<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosPrinterAgentHeartbeat extends Model
{
    /** Seconds without heartbeat before status is Offline. */
    public const ONLINE_THRESHOLD_SECONDS = 12;

    protected $fillable = [
        'branch_id',
        'created_by',
        'last_heartbeat_at',
        'machine_name',
    ];

    protected $casts = [
        'last_heartbeat_at' => 'datetime',
    ];

    public function isOnline(): bool
    {
        if (! $this->last_heartbeat_at) {
            return false;
        }

        return $this->last_heartbeat_at->gte(now()->subSeconds(self::ONLINE_THRESHOLD_SECONDS));
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
