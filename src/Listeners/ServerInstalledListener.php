<?php

namespace UptimeKuma\Listeners;

use App\Events\Server\Installed;
use UptimeKuma\Jobs\SyncServerMonitor;

class ServerInstalledListener
{
    public function handle(Installed $event): void
    {
        if ($event->successful && $event->initialInstall) {
            SyncServerMonitor::dispatch($event->server->id)->afterCommit();
        }
    }
}
