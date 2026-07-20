<?php

namespace UptimeKuma\Providers;

use App\Events\Server\Installed;
use App\Models\Server;
use App\Models\Egg;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use UptimeKuma\Listeners\ServerInstalledListener;
use UptimeKuma\Observers\ServerObserver;
use UptimeKuma\Observers\EggObserver;

class UptimeKumaPluginProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Event::listen(Installed::class, ServerInstalledListener::class);
        Server::observe(ServerObserver::class);
        Egg::observe(EggObserver::class);
    }
}
