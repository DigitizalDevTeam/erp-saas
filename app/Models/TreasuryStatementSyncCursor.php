<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Treasury Phase 7 — API import sync cursors.
 *
 * One row per (bank account, source). A re-run resumes after the
 * stored cursor and never re-imports a line already seen. The
 * cursor is the greatest external_id imported so far (external
 * ids are monotonic per source); a provider that hands out its
 * own opaque cursor stores it here instead.
 */
class TreasuryStatementSyncCursor extends Model
{
    protected $fillable = [
        'bank_account_id',
        'source_key',
        'cursor_value',
        'last_synced_at',
        'created_by',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }
}
