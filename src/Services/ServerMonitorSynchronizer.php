<?php

namespace UptimeKuma\Services;

use App\Models\Allocation;
use App\Models\Server;
use App\Enums\ContainerStatus;
use Throwable;
use UptimeKuma\Exceptions\UptimeKumaException;
use UptimeKuma\Jobs\SyncManualMonitorStates;
use UptimeKuma\Models\UptimeKumaMonitor;
use UptimeKuma\Models\UptimeKumaSetting;

class ServerMonitorSynchronizer
{
    private ?UptimeKumaClient $batchClient = null;

    public function sync(Server $server): void
    {
        $this->withSyncLock(fn () => $this->syncLocked($server));
    }

    public function beginBatch(): void
    {
        if ($this->batchClient) {
            return;
        }

        $this->batchClient = new UptimeKumaClient(UptimeKumaSetting::current());
        try {
            $this->batchClient->connect();
        } catch (Throwable $exception) {
            $this->batchClient->close();
            $this->batchClient = null;
            throw $exception;
        }
    }

    public function endBatch(): void
    {
        $this->batchClient?->close();
        $this->batchClient = null;
    }

    private function syncLocked(Server $server): void
    {
        $settings = UptimeKumaSetting::current();
        if (!$settings->enabled) {
            return;
        }

        $server->loadMissing(['allocation', 'allocations', 'egg']);
        if (!$server->allocation) {
            return;
        }

        $mapping = UptimeKumaMonitor::query()->firstOrCreate(['server_id' => $server->id]);
        $client = $this->batchClient ?? new UptimeKumaClient($settings);
        $ownsClient = $this->batchClient === null;

        try {
            if ($ownsClient) {
                $client->connect();
            }
            $statusPageCreated = $client->ensureStatusPage($settings->status_page_slug, $settings->status_page_title);
            $payload = $this->payload($server, $settings, $client);
            $endpoint = $payload['hostname'] . ':' . $payload['port'];
            if ($mapping->monitor_id) {
                try {
                    $existing = $client->getMonitor($mapping->monitor_id);
                    $client->updateMonitor(array_replace($existing, $payload, ['id' => $mapping->monitor_id]));
                } catch (UptimeKumaException $exception) {
                    if (!$this->monitorIsMissing($exception)) {
                        throw $exception;
                    }

                    $mapping->monitor_id = $client->addMonitor($payload);
                }
            } else {
                $mapping->monitor_id = $client->addMonitor($payload);
            }
            $client->putStatusPageMonitor(
                $settings->status_page_slug,
                $settings->group_name,
                $mapping->monitor_id,
                $statusPageCreated,
            );
            $mapping->fill([
                'monitor_type' => $payload['type'],
                'endpoint' => $endpoint, 'sync_status' => 'synced',
                'last_error' => null, 'last_synced_at' => now(),
            ])->save();

            if ($payload['type'] === 'manual') {
                SyncManualMonitorStates::dispatch()
                    ->delay(now()->addSeconds(max(20, $settings->interval)));
            }
        } catch (Throwable $exception) {
            $mapping->fill(['sync_status' => 'failed', 'last_error' => $exception->getMessage()])->save();
            throw $exception;
        } finally {
            if ($ownsClient) {
                $client->close();
            }
        }
    }

    public function delete(int $serverId, ?int $monitorId = null): void
    {
        $settings = UptimeKumaSetting::current();
        $mapping = UptimeKumaMonitor::query()->where('server_id', $serverId)->first();
        $monitorId ??= $mapping?->monitor_id;
        if (!$settings->enabled || !$monitorId) {
            $mapping?->delete();
            return;
        }

        $client = new UptimeKumaClient($settings);
        try {
            $client->connect();
            $client->deleteMonitor($monitorId);
            $mapping?->delete();
        } finally {
            $client->close();
        }
    }

    private function payload(Server $server, UptimeKumaSetting $settings, UptimeKumaClient $client): array
    {
        $tags = array_map(fn (string $tag) => strtolower(trim($tag)), (array) $server->egg->tags);
        $manual = in_array('manual', $tags, true);
        $game = $manual ? null : $client->resolveGameFromTags($tags);

        // Select the most game-aware check available for each egg. GameDig is
        // preferred because it verifies the game protocol, unless the egg
        // explicitly requests container-state monitoring with a manual tag.
        $type = match (true) {
            $manual => 'manual',
            $game !== null => 'gamedig',
            (bool) array_intersect(['steam', 'steamcmd'], $tags) => 'steam',
            default => 'manual',
        };
        $allocation = $this->monitorAllocation($server, $type);

        return [
            'name' => $server->name,
            'description' => match ($type) {
                'gamedig' => "GameDig ({$game}) for Pelican server " . $server->uuid_short,
                'steam' => 'Steam server-list check for Pelican server ' . $server->uuid_short,
                default => 'Pelican Wings container state for server ' . $server->uuid_short,
            },
            'type' => $type, 'subtype' => null, 'parent' => null,
            'hostname' => $allocation->alias,
            'port' => $allocation->port,
            'game' => $game,
            'manual_status' => $type === 'manual'
                ? ($server->condition === ContainerStatus::Running ? 1 : 0)
                : null,
            'gamedigGivenPortOnly' => false,
            'gamedigToken' => '',
            'packetSize' => 56,
            'maxredirects' => 10,
            'ignoreTls' => false,
            'interval' => $settings->interval, 'retryInterval' => $settings->interval,
            'resendInterval' => 0, 'maxretries' => 2, 'timeout' => 10,
            'active' => true, 'notificationIDList' => [], 'accepted_statuscodes' => ['200-299'],
            'kafkaProducerBrokers' => [], 'kafkaProducerSaslOptions' => ['mechanism' => 'None'],
            'rabbitmqNodes' => [], 'conditions' => [],
        ];
    }

    private function monitorAllocation(Server $server, string $type): Allocation
    {
        $priorities = $type === 'steam'
            ? ['steam query port', 'query port']
            : ['query port', 'steam query port'];

        foreach ($priorities as $label) {
            $allocation = $server->allocations->first(
                fn (Allocation $allocation): bool => strtolower(trim((string) $allocation->notes)) === $label
            );

            if ($allocation) {
                return $allocation;
            }
        }

        return $server->allocation;
    }

    private function monitorIsMissing(UptimeKumaException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, '[getmonitor]') && (
            str_contains($message, "cannot read properties of null")
            || str_contains($message, 'not found')
            || str_contains($message, 'does not exist')
        );
    }

    private function withSyncLock(callable $callback): void
    {
        $directory = storage_path('framework/cache/uptime-kuma');
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException("Unable to create Uptime Kuma lock directory: {$directory}");
        }

        $handle = fopen("{$directory}/synchronization.lock", 'c');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open the Uptime Kuma synchronization lock.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Unable to acquire the Uptime Kuma synchronization lock.');
            }

            $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
