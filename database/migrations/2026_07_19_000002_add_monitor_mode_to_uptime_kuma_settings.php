<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('uptime_kuma_settings', function (Blueprint $table) {
            $table->string('monitor_mode')->default('port')->after('group_name');
        });
    }

    public function down(): void
    {
        Schema::table('uptime_kuma_settings', function (Blueprint $table) {
            $table->dropColumn('monitor_mode');
        });
    }
};
