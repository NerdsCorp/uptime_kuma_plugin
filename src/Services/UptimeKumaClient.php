<?php

namespace UptimeKuma\Services;

use ElephantIO\Client;
use ElephantIO\Engine\Argument;
use ElephantIO\Engine\Packet;
use Illuminate\Support\Facades\Http;
use Throwable;
use UptimeKuma\Exceptions\UptimeKumaException;
use UptimeKuma\Models\UptimeKumaSetting;

class UptimeKumaClient
{
    private ?Client $client = null;
    private ?string $lastEvent = null;

    public function __construct(private readonly UptimeKumaSetting $settings) {}

    public function connect(): void
    {
        if (!class_exists(Client::class)) {
            throw new UptimeKumaException(
                'The required elephantio/elephant.io package is not installed. Run "php artisan p:plugin:composer" from the Pelican directory.'
            );
        }

        if (!$this->settings->base_url || !$this->settings->username || !$this->settings->password) {
            throw new UptimeKumaException('Uptime Kuma URL, username and password are required.');
        }

        $this->client = Client::create(rtrim($this->settings->base_url, '/'), [
            'timeout' => 10,
            'context' => ['ssl' => [
                'verify_peer' => $this->settings->verify_tls,
                'verify_peer_name' => $this->settings->verify_tls,
            ]],
        ]);
        $this->client->connect();
        $this->assertOk($this->emit('login', [[
            'username' => $this->settings->username,
            'password' => $this->settings->password,
        ]]));
    }

    public function close(): void
    {
        $this->client?->disconnect();
        $this->client = null;
    }

    public function addMonitor(array $monitor): int
    {
        $result = $this->assertOk($this->emit('add', [$monitor]));
        return (int) ($result['monitorID'] ?? 0);
    }

    public function updateMonitor(array $monitor): void
    {
        $this->assertOk($this->emit('editMonitor', [$monitor]));
    }

    public function deleteMonitor(int $monitorId): void
    {
        $this->assertOk($this->emit('deleteMonitor', [$monitorId, false]));
    }

    public function getMonitor(int $monitorId): array
    {
        $result = $this->assertOk($this->emit('getMonitor', [$monitorId]));
        return (array) ($result['monitor'] ?? []);
    }

    /** @param string[] $tags */
    public function resolveGameFromTags(array $tags): ?string
    {
        $result = $this->assertOk($this->emit('getGameList', []));
        $tags = array_map(fn (string $tag) => strtolower(trim($tag)), $tags);
        $explicit = collect($tags)
            ->filter(fn (string $tag) => str_starts_with($tag, 'gamedig:'))
            ->map(fn (string $tag) => substr($tag, strlen('gamedig:')))
            ->filter()
            ->values()
            ->all();
        $candidates = array_values(array_unique([...$explicit, ...$tags]));

        $supported = [];
        foreach ((array) ($result['gameList'] ?? []) as $game) {
            foreach ((array) ($game['keys'] ?? []) as $key) {
                $supported[strtolower((string) $key)] = (string) $key;
            }
        }

        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $supported)) {
                return $supported[$candidate];
            }
        }

        return null;
    }

    public function ensureStatusPage(string $slug, string $title): bool
    {
        $slug = $this->normalizeStatusPageSlug($slug);
        $title = trim($title);
        $packet = $this->emit('getStatusPage', [$slug]);
        $result = $this->packetData($packet);
        if (($result['ok'] ?? false) === true) {
            return false;
        }

        try {
            $this->assertOk($this->emit('addStatusPage', [$title, $slug]));
            return true;
        } catch (UptimeKumaException $exception) {
            // Another worker may have created the page after our initial check.
            // Re-read it before treating a duplicate-slug response as a failure.
            $retry = $this->packetData($this->emit('getStatusPage', [$slug]));
            if (($retry['ok'] ?? false) === true) {
                return false;
            }

            // Kuma may return a duplicate-key database message when its first
            // lookup used stale state. The unique slug constraint proves that
            // the requested page exists, so continue with reconciliation.
            $message = strtolower($exception->getMessage());
            if (str_contains($message, 'duplicate') && str_contains($message, 'status_page')) {
                return false;
            }

            throw $exception;
        }
    }

    public function putStatusPageMonitor(string $slug, string $groupName, int $monitorId, bool $newPage = false): void
    {
        $slug = $this->normalizeStatusPageSlug($slug);
        $groupName = trim($groupName);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $result = $this->assertOk($this->emit('getStatusPage', [$slug]));
            $config = (array) ($result['config'] ?? []);
            // Kuma 1.x does not clear its public API cache in addStatusPage.
            // Avoid reading a cached 404 for a page we just created. Saving
            // the initial group below clears Kuma's cache.
            if ($newPage && $attempt === 1) {
                $groups = [];
            } else {
                try {
                    $groups = $this->normalizePublicGroups(
                        (array) ($this->getPublicStatusPage($slug)['publicGroupList'] ?? [])
                    );
                } catch (Throwable) {
                    // This page is owned by the plugin. A stale public cache
                    // must not prevent it from receiving its first group.
                    $groups = [];
                }
            }
            $found = false;

            foreach ($groups as &$group) {
                if (($group['name'] ?? null) !== $groupName) {
                    continue;
                }
                $group['monitorList'] = (array) ($group['monitorList'] ?? []);
                if (!$this->groupsContainMonitor([$group], $monitorId)) {
                    $group['monitorList'][] = ['id' => $monitorId];
                }
                $found = true;
            }
            unset($group);

            if (!$found) {
                $groups[] = ['name' => $groupName, 'monitorList' => [['id' => $monitorId]]];
            }

            $defaults = [
                'slug' => $slug, 'title' => $this->settings->status_page_title, 'description' => '', 'logo' => '',
                'autoRefreshInterval' => 300, 'theme' => 'auto', 'showTags' => false, 'footerText' => '',
                'customCSS' => '', 'showPoweredBy' => true, 'rssTitle' => '', 'showOnlyLastHeartbeat' => false,
                'showCertificateExpiry' => false, 'analyticsId' => null, 'analyticsScriptUrl' => null,
                'analyticsType' => null, 'domainNameList' => [],
            ];
            $saved = $this->assertOk($this->emit('saveStatusPage', [$slug, array_replace($defaults, $config), '', $groups]));

            if ($this->groupsContainMonitor((array) ($saved['publicGroupList'] ?? []), $monitorId)) {
                return;
            }

            $verified = (array) ($this->getPublicStatusPage($slug)['publicGroupList'] ?? []);
            if ($this->groupsContainMonitor($verified, $monitorId)) {
                return;
            }
        }

        throw new UptimeKumaException(
            "Uptime Kuma accepted the status-page update but monitor {$monitorId} was not present on page '{$slug}' after verification."
        );
    }

    private function getPublicStatusPage(string $slug): array
    {
        $slug = $this->normalizeStatusPageSlug($slug);

        return (array) Http::timeout(10)
            ->withOptions(['verify' => $this->settings->verify_tls])
            ->get(
                rtrim($this->settings->base_url, '/') . '/api/status-page/' . rawurlencode($slug),
                ['_plugin_check' => bin2hex(random_bytes(6))],
            )
            ->throw()
            ->json();
    }

    private function normalizeStatusPageSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        if ($slug === '' || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new UptimeKumaException('The status-page slug must contain only letters, numbers, and single hyphens.');
        }

        return $slug;
    }

    private function groupsContainMonitor(array $groups, int $monitorId): bool
    {
        foreach ($groups as $group) {
            foreach ((array) ($group['monitorList'] ?? []) as $monitor) {
                if (is_array($monitor) && is_numeric($monitor['id'] ?? null) && (int) $monitor['id'] === $monitorId) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizePublicGroups(array $groups): array
    {
        $normalized = [];

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $clean = [
                'name' => (string) ($group['name'] ?? $this->settings->group_name),
                'monitorList' => [],
            ];

            if (is_numeric($group['id'] ?? null)) {
                $clean['id'] = (int) $group['id'];
            }

            foreach ((array) ($group['monitorList'] ?? []) as $monitor) {
                if (!is_array($monitor) || !is_numeric($monitor['id'] ?? null)) {
                    continue;
                }

                $entry = ['id' => (int) $monitor['id']];
                if (array_key_exists('sendUrl', $monitor)) {
                    $entry['sendUrl'] = (bool) $monitor['sendUrl'];
                }
                if (isset($monitor['url']) && is_string($monitor['url'])) {
                    $entry['url'] = $monitor['url'];
                }
                $clean['monitorList'][] = $entry;
            }

            $normalized[] = $clean;
        }

        return $normalized;
    }

    private function emit(string $event, array $arguments): Packet
    {
        if (!$this->client) {
            throw new UptimeKumaException('Not connected to Uptime Kuma.');
        }
        $this->lastEvent = $event;
        // Always construct Argument explicitly. ElephantIO's automatic
        // conversion casts a lone scalar to an array and then wraps it again,
        // causing Kuma to receive [monitorId] instead of monitorId.
        $payload = new Argument(...$arguments);
        $packet = $this->client->emit($event, $payload, true);
        if (!$packet instanceof Packet) {
            throw new UptimeKumaException("Uptime Kuma did not acknowledge {$event}.");
        }
        return $packet;
    }

    private function assertOk(Packet $packet): array
    {
        $result = $this->packetData($packet);
        if (($result['ok'] ?? false) !== true) {
            $operation = $this->lastEvent ? "[{$this->lastEvent}] " : '';
            throw new UptimeKumaException($operation . (string) ($result['msg'] ?? 'Uptime Kuma request failed.'));
        }
        return $result;
    }

    private function packetData(Packet $packet): array
    {
        $args = $packet->args;
        return is_array($args) ? (array) ($args[0] ?? $args) : [];
    }
}
