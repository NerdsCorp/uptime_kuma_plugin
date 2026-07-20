<?php

namespace UptimeKuma\Observers;

use App\Models\Egg;
use UptimeKuma\Jobs\SyncServerMonitor;

class EggObserver
{
    public function updated(Egg $egg): void
    {
        if (!$egg->wasChanged('tags')) {
            return;
        }

        $egg->servers()
            ->whereNotNull('installed_at')
            ->pluck('id')
            ->each(fn (int $id) => SyncServerMonitor::dispatch($id)->afterCommit());
    }
}
