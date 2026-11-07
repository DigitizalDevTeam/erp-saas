<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 6 — one expense claimed against an advance
 * (plan §5, §6).
 *
 * Each line carries its own evidence and is reviewed on its own:
 * pending → approved (posts Dr expense / Cr advance asset, capped
 * at the advance balance still unsubstantiated — the excess becomes
 * the advance's reimbursement, never a second expense posting) →
 * rejected (no posting) or returned (the recipient edits the line
 * and resubmits it for review).
 *
 * Money is DECIMAL(20,4); rates are DECIMAL(20,10) (RF04).
 */
class TreasuryAdvanceExpenseLine extends Model
{
    public const STATE_PENDING = 'pending';
    public const STATE_APPROVED = 'approved';
    public const STATE_REJECTED = 'rejected';
    public const STATE_RETURNED = 'returned';

    public const STATES = [
        self::STATE_PENDING,
        self::STATE_APPROVED,
        self::STATE_REJECTED,
        self::STATE_RETURNED,
    ];

    protected $fillable = [
        'advance_id',
        'expense_date',
        'counterparty',
        'category',
        'document',
        'amount',
        'amount_base',
        'chart_account_id',
        'cost_centre_id',
        'evidence',
        'state',
        'submitted_by',
        'reviewed_by',
        'reviewed_at',
        'review_reason',
        'journal_id',
        'operation_id',
        'reimbursement_amount',
        'reimbursement_amount_base',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:4',
        'amount_base' => 'decimal:4',
        'reviewed_at' => 'datetime',
        'reimbursement_amount' => 'decimal:4',
        'reimbursement_amount_base' => 'decimal:4',
    ];

    public function advance()
    {
        return $this->belongsTo(TreasuryAdvance::class, 'advance_id');
    }

    public function costCentre()
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function chartAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'chart_account_id');
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_id');
    }

    public function operation()
    {
        return $this->belongsTo(TreasuryOperation::class, 'operation_id');
    }

    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->state === self::STATE_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->state === self::STATE_REJECTED;
    }

    public function isReturned(): bool
    {
        return $this->state === self::STATE_RETURNED;
    }

    /** The part of this line actually substantiated from the advance. */
    public function substantiatedAmount(): string
    {
        return bcsub((string) $this->amount, (string) $this->reimbursement_amount, 4);
    }

    public function substantiatedAmountBase(): string
    {
        return bcsub((string) $this->amount_base, (string) $this->reimbursement_amount_base, 4);
    }
}
