<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 4 — internal transfer with FX (plan §5, §6).
 *
 * A transfer moves money between two financial accounts of the
 * SAME company (intercompany needs the intercompany workflow,
 * which does not exist yet — Phase 4 does not build it).
 *
 * States (plan §6): DRAFT → CONFIRMED (immediate: both legs
 * atomically) or DRAFT → IN TRANSIT (delayed: outflow confirmed
 * to the transit account, inflow clears it on receipt) → RETURNED
 * (destination rejected: the money stays in transit until a
 * return is confirmed, then the transit balance is cleared).
 * A draft can be cancelled; a confirmed transfer is never edited
 * (RF03 — reverse, never edit).
 *
 * Money is DECIMAL(20,4); rates are DECIMAL(20,10) (RF04).
 * Decimal casts return strings so bcmath stays exact.
 */
class TreasuryTransfer extends Model
{
    public const TIMING_IMMEDIATE = 'immediate';
    public const TIMING_DELAYED = 'delayed';

    public const TIMINGS = [
        self::TIMING_IMMEDIATE,
        self::TIMING_DELAYED,
    ];

    public const STATE_DRAFT = 'draft';
    public const STATE_IN_TRANSIT = 'in_transit';
    public const STATE_CONFIRMED = 'confirmed';
    public const STATE_RETURNED = 'returned';
    public const STATE_CANCELLED = 'cancelled';

    public const STATES = [
        self::STATE_DRAFT,
        self::STATE_IN_TRANSIT,
        self::STATE_CONFIRMED,
        self::STATE_RETURNED,
        self::STATE_CANCELLED,
    ];

    protected $fillable = [
        'operation_id',
        'created_by',
        'branch_id',
        'source_account_id',
        'destination_account_id',
        'transit_account_id',
        'source_currency',
        'destination_currency',
        'timing',
        'amount_debited',
        'exchange_rate',
        'rate_source',
        'rate_date',
        'base_exchange_rate',
        'amount_credited',
        'amount_debited_base',
        'amount_credited_base',
        'fee_amount',
        'fee_base',
        'fee_account_id',
        'exchange_difference_base',
        'exchange_difference_account_id',
        'state',
        'evidence',
        'confirmed_at',
        'cleared_at',
        'returned_at',
        'return_reason',
    ];

    protected $casts = [
        'amount_debited' => 'decimal:4',
        'exchange_rate' => 'decimal:10',
        'rate_date' => 'date',
        'base_exchange_rate' => 'decimal:10',
        'amount_credited' => 'decimal:4',
        'amount_debited_base' => 'decimal:4',
        'amount_credited_base' => 'decimal:4',
        'fee_amount' => 'decimal:4',
        'fee_base' => 'decimal:4',
        'exchange_difference_base' => 'decimal:4',
        'confirmed_at' => 'datetime',
        'cleared_at' => 'datetime',
        'returned_at' => 'datetime',
    ];

    public function operation()
    {
        return $this->belongsTo(TreasuryOperation::class, 'operation_id');
    }

    public function sourceAccount()
    {
        return $this->belongsTo(TreasuryFinancialAccount::class, 'source_account_id');
    }

    public function destinationAccount()
    {
        return $this->belongsTo(TreasuryFinancialAccount::class, 'destination_account_id');
    }

    public function transitAccount()
    {
        return $this->belongsTo(TreasuryFinancialAccount::class, 'transit_account_id');
    }

    public function isDraft(): bool
    {
        return $this->state === self::STATE_DRAFT;
    }

    public function isInTransit(): bool
    {
        return $this->state === self::STATE_IN_TRANSIT;
    }

    public function isConfirmed(): bool
    {
        return $this->state === self::STATE_CONFIRMED;
    }

    public function isReturned(): bool
    {
        return $this->state === self::STATE_RETURNED;
    }

    public function isCancelled(): bool
    {
        return $this->state === self::STATE_CANCELLED;
    }

    public function isDelayed(): bool
    {
        return $this->timing === self::TIMING_DELAYED;
    }

    /** Reference of the linked operation (null while a draft). */
    public function reference(): ?string
    {
        return $this->operation ? (string) $this->operation->reference : null;
    }
}
