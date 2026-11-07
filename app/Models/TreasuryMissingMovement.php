<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Treasury Phase 7 — items found on the bank statement but not
 * in the books (bank fees, interest, other charges).
 *
 * Lifecycle: proposed → confirmed (posts exactly one journal
 * through the JournalEntryPoster, linked to the statement line so
 * the same item can never be created twice) or rejected. A
 * confirmed movement is immutable — reversal only, as a new
 * operation with negated amounts linked via reversal_of_id (RF03).
 */
class TreasuryMissingMovement extends Model
{
    public const CATEGORY_FEE = 'fee';
    public const CATEGORY_INTEREST = 'interest';
    public const CATEGORY_OTHER = 'other';

    public const CATEGORIES = [
        self::CATEGORY_FEE,
        self::CATEGORY_INTEREST,
        self::CATEGORY_OTHER,
    ];

    public const STATE_PROPOSED = 'proposed';
    public const STATE_CONFIRMED = 'confirmed';
    public const STATE_REJECTED = 'rejected';
    public const STATE_REVERSED = 'reversed';

    public const STATES = [
        self::STATE_PROPOSED,
        self::STATE_CONFIRMED,
        self::STATE_REJECTED,
        self::STATE_REVERSED,
    ];

    protected $fillable = [
        'session_id',
        'statement_line_id',
        'category',
        'offset_account_id',
        'currency',
        'amount_original',
        'exchange_rate',
        'amount_base',
        'state',
        'operation_id',
        'journal_id',
        'reversal_of_id',
        'reversal_operation_id',
        'reason',
        'created_by',
        'confirmed_by',
        'confirmed_at',
    ];

    protected $casts = [
        'amount_original' => 'decimal:4',
        'exchange_rate' => 'decimal:10',
        'amount_base' => 'decimal:4',
        'confirmed_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(BankReconciliationSession::class, 'session_id');
    }

    public function statementLine(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'statement_line_id');
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(TreasuryOperation::class, 'operation_id');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_id');
    }

    public function isProposed(): bool
    {
        return $this->state === self::STATE_PROPOSED;
    }

    public function isConfirmed(): bool
    {
        return $this->state === self::STATE_CONFIRMED;
    }
}
