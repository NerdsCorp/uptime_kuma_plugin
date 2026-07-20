<?php

namespace UptimeKuma\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use UptimeKuma\Models\UptimeKumaMonitor;
use UptimeKuma\Models\UptimeKumaSetting;
use UptimeKuma\Services\ServerMonitorSynchronizer;

class SyncManualMonitorStates implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue(config('uptime-kuma.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'uptime-kuma-manual-monitor-states';
    }

    public function handle(ServerMonitorSynchronizer $synchronizer): void
    {
        $settings = UptimeKumaSetting::current();
        if (!$settings->enabled) {
            return;
        }

        try {
            $synchronizer->beginBatch();
            UptimeKumaMonitor::query()
                ->where('monitor_type', 'manual')
                ->with('server')
                ->each(function (UptimeKumaMonitor $mapping) use ($synchronizer): void {
                    if (!$mapping->server) {
                        return;
                    }

                    try {
                        $synchronizer->sync($mapping->server);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                });
        } catch (Throwable $exception) {
            report($exception);
        } finally {
            $synchronizer->endBatch();
        }

        if (UptimeKumaMonitor::query()->where('monitor_type', 'manual')->exists()) {
            self::dispatch()->delay(now()->addSeconds(max(20, $settings->interval)));
        }
    }
}
