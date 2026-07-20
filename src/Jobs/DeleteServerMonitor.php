<?php

namespace UptimeKuma\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use UptimeKuma\Services\ServerMonitorSynchronizer;

class DeleteServerMonitor implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [30, 120, 300, 900];

    public function __construct(public readonly int $serverId, public readonly ?int $monitorId) {}

    public function handle(ServerMonitorSynchronizer $synchronizer): void
    {
        $synchronizer->delete($this->serverId, $this->monitorId);
    }
}
