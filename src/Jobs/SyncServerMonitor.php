<?php

namespace UptimeKuma\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use UptimeKuma\Services\ServerMonitorSynchronizer;

class SyncServerMonitor implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [30, 120, 300, 900];
    public int $uniqueFor = 600;

    public function __construct(public readonly int $serverId)
    {
        $this->onQueue(config('uptime-kuma.queue', 'default'));
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("uptime-kuma-server-{$this->serverId}"))->expireAfter(120)];
    }

    public function uniqueId(): string
    {
        return "uptime-kuma-server-{$this->serverId}";
    }

    public function handle(ServerMonitorSynchronizer $synchronizer): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server) {
            $synchronizer->sync($server);
        }
    }
}
