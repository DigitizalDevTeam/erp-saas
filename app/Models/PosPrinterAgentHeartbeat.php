<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class PosPrinterAgentHeartbeat extends Model
{
    /** Seconds without heartbeat before status is Offline (UTC comparison). */
    public const ONLINE_THRESHOLD_SECONDS = 30;

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

        // Compare in UTC so company timezone (XSS middleware) cannot make a live heartbeat look stale.
        $lastUtc = $this->last_heartbeat_at->copy()->utc();
        $cutoffUtc = Carbon::now('UTC')->subSeconds(self::ONLINE_THRESHOLD_SECONDS);

        return $lastUtc->gte($cutoffUtc);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
