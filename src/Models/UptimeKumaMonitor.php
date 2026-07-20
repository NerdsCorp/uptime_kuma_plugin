<?php

namespace UptimeKuma\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UptimeKumaMonitor extends Model
{
    protected $table = 'uptime_kuma_monitors';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime', 'monitor_id' => 'integer'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
