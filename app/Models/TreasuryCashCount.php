<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 5 — one denomination row of a session count
 * (plan §5, CA09).
 *
 * One row per denomination in the count; amount = denomination ×
 * count. The count and the variance it produced are preserved
 * forever — count rows are immutable once the session is closed.
 */
class TreasuryCashCount extends Model
{
    protected $fillable = [
        'cash_session_id',
        'created_by',
        'denomination',
        'count',
        'amount',
    ];

    protected $casts = [
        'denomination' => 'decimal:4',
        'amount' => 'decimal:4',
    ];

    public function session()
    {
        return $this->belongsTo(TreasuryCashSession::class, 'cash_session_id');
    }
}
