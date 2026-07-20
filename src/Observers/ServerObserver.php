<?php

namespace UptimeKuma\Observers;

use App\Models\Server;
use UptimeKuma\Jobs\DeleteServerMonitor;
use UptimeKuma\Jobs\SyncServerMonitor;
use UptimeKuma\Models\UptimeKumaMonitor;

class ServerObserver
{
    public function updated(Server $server): void
    {
        if ($server->wasChanged(['name', 'allocation_id', 'egg_id']) && $server->installed_at) {
            SyncServerMonitor::dispatch($server->id)->afterCommit();
        }
    }

    public function deleting(Server $server): void
    {
        $monitorId = UptimeKumaMonitor::query()->where('server_id', $server->id)->value('monitor_id');
        DeleteServerMonitor::dispatch($server->id, $monitorId)->afterCommit();
    }
}
