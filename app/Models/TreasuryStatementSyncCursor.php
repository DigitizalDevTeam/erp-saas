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
        // Phase 8 — a failed API sync records its error here; the
        // next successful import clears it. The alert service raises
        // a sync_failure alert while a recent failure stands.
        'last_error',
        'last_error_at',
        'created_by',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }
}
