<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 1 — the ONE unified operation table (plan §5).
 *
 * One row per confirmed operation (RF01). The reference is unique
 * per company and never reused; the sequence is allocated only when
 * the operation leaves Draft. Money is DECIMAL(20,4), rates
 * DECIMAL(20,10) — decimal casts return strings so bcmath stays
 * exact, never float (RF04).
 */
class TreasuryOperation extends Model
{
    public const TYPE_RECEIPT = 'receipt';
    public const TYPE_PAYMENT = 'payment';
    public const TYPE_TRANSFER = 'transfer';
    public const TYPE_ADVANCE = 'advance';
    public const TYPE_CASH_MOVEMENT = 'cash_movement';
    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPES = [
        self::TYPE_RECEIPT,
        self::TYPE_PAYMENT,
        self::TYPE_TRANSFER,
        self::TYPE_ADVANCE,
        self::TYPE_CASH_MOVEMENT,
        self::TYPE_ADJUSTMENT,
    ];

    public const TYPE_CODES = [
        self::TYPE_RECEIPT => 'RCT',
        self::TYPE_PAYMENT => 'PAY',
        self::TYPE_TRANSFER => 'TRF',
        self::TYPE_ADVANCE => 'ADV',
        self::TYPE_CASH_MOVEMENT => 'CSH',
        self::TYPE_ADJUSTMENT => 'ADJ',
    ];

    public const STATE_DRAFT = 'draft';
    public const STATE_PENDING = 'pending';
    public const STATE_CONFIRMED = 'confirmed';
    public const STATE_REJECTED = 'rejected';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_REVERSED = 'reversed';

    public const STATES = [
        self::STATE_DRAFT,
        self::STATE_PENDING,
        self::STATE_CONFIRMED,
        self::STATE_REJECTED,
        self::STATE_CANCELLED,
        self::STATE_REVERSED,
    ];

    protected $fillable = [
        'reference',
        'type',
        'company_id',
        'branch_id',
        'financial_account_id',
        'counterparty_type',
        'counterparty_id',
        'currency',
        'amount_original',
        'exchange_rate',
        'rate_source',
        'rate_date',
        'amount_base',
        'posting_date',
        'effective_date',
        'value_date',
        'state',
        'source_type',
        'source_id',
        'reversal_of_id',
        'reversal_reason',
        'created_by',
    ];

    protected $casts = [
        'amount_original' => 'decimal:4',
        'exchange_rate' => 'decimal:10',
        'amount_base' => 'decimal:4',
        'posting_date' => 'date',
        'effective_date' => 'date',
        'value_date' => 'date',
        'rate_date' => 'date',
    ];

    public function financialAccount()
    {
        return $this->belongsTo(TreasuryFinancialAccount::class, 'financial_account_id');
    }

    public function entries()
    {
        return $this->hasMany(TreasuryEntry::class, 'operation_id');
    }

    public function receipt()
    {
        return $this->hasOne(TreasuryReceipt::class, 'operation_id');
    }

    public function isDraft(): bool
    {
        return $this->state === self::STATE_DRAFT;
    }

    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }

    public function isConfirmed(): bool
    {
        return $this->state === self::STATE_CONFIRMED;
    }

    public function scopeConfirmed($query)
    {
        return $query->where('state', self::STATE_CONFIRMED);
    }
}
