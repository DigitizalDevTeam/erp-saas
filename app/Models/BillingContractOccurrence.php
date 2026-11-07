<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Contract Management Phase 1 — billing occurrence.
 *
 * One row per scheduled billing period. Coverage is stored
 * start-inclusive / end-exclusive; the invoice shows the
 * inclusive final day (period_end - 1 day).
 *
 * States: pending | generated | invoiced | skipped | cancelled.
 * 'pending' = generated at activation, awaiting its billing date.
 * 'generated' = picked up by the recurring engine (Phase 2).
 * 'invoiced' = a sales invoice exists (invoice_id). 'skipped' =
 * a partial period skipped by explicit choice, or a suspended
 * period. 'cancelled' = the contract ended/cancelled before it.
 */
class BillingContractOccurrence extends Model
{
    public const STATE_PENDING = 'pending';
    public const STATE_GENERATED = 'generated';
    public const STATE_INVOICED = 'invoiced';
    public const STATE_SKIPPED = 'skipped';
    public const STATE_CANCELLED = 'cancelled';

    public const STATES = [
        self::STATE_PENDING,
        self::STATE_GENERATED,
        self::STATE_INVOICED,
        self::STATE_SKIPPED,
        self::STATE_CANCELLED,
    ];

    protected $fillable = [
        'contract_id', 'created_by', 'occurrence_key', 'period_key',
        'period_start', 'period_end', 'billing_date', 'due_date',
        'amount', 'currency', 'state', 'purpose', 'invoice_id',
        'generated_at', 'skip_reason',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'billing_date' => 'date',
        'due_date' => 'date',
        'generated_at' => 'datetime',
    ];

    public function contract()
    {
        return $this->belongsTo(BillingContract::class, 'contract_id');
    }

    /** Inclusive final day of the coverage period (for display). */
    public function coverageEndInclusive(): string
    {
        return $this->period_end->copy()->subDay()->format('Y-m-d');
    }
}
