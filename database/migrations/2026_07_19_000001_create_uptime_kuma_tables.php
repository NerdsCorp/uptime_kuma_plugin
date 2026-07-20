<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('uptime_kuma_settings', function (Blueprint $table) {
            $table->id();
            $table->string('base_url')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('status_page_slug')->default('game-servers');
            $table->string('status_page_title')->default('Game Server Status');
            $table->string('group_name')->default('Game Servers');
            $table->unsignedInteger('interval')->default(60);
            $table->boolean('verify_tls')->default(true);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('uptime_kuma_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('server_id')->unique()->constrained('servers')->cascadeOnDelete();
            $table->unsignedBigInteger('monitor_id')->nullable()->index();
            $table->string('monitor_type')->default('port');
            $table->string('endpoint')->nullable();
            $table->string('sync_status')->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('uptime_kuma_monitors');
        Schema::dropIfExists('uptime_kuma_settings');
    }
};
