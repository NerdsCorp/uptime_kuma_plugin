<?php

namespace UptimeKuma\Models;

use Illuminate\Database\Eloquent\Model;

class UptimeKumaSetting extends Model
{
    protected $table = 'uptime_kuma_settings';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'enabled' => 'boolean', 'verify_tls' => 'boolean', 'interval' => 'integer'];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'status_page_slug' => 'game-servers',
            'status_page_title' => 'Game Server Status',
            'group_name' => 'Game Servers',
            'monitor_mode' => 'auto',
            'interval' => 60,
            'verify_tls' => true,
            'enabled' => false,
        ]);
    }
}
