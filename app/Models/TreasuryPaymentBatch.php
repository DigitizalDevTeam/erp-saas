<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 3 — payment batches (plan §5, §6, CA07).
 *
 * A batch groups several approved payment requests under one
 * reference, one state and one outcome per instruction. A batch is
 * never all-or-nothing on the money: each instruction settles or
 * fails on its own, and a failed item stays in the batch for review
 * while the successful items stay settled.
 *
 * Money is DECIMAL(20,4) — decimal casts return strings so bcmath
 * stays exact, never float (RF04).
 */
class TreasuryPaymentBatch extends Model
{
    public const STATE_DRAFT = 'draft';
    public const STATE_PROCESSING = 'processing';
    public const STATE_PARTIALLY_PAID = 'partially_paid';
    public const STATE_PAID = 'paid';
    public const STATE_FAILED = 'failed';
    public const STATE_CANCELLED = 'cancelled';

    public const STATES = [
        self::STATE_DRAFT,
        self::STATE_PROCESSING,
        self::STATE_PARTIALLY_PAID,
        self::STATE_PAID,
        self::STATE_FAILED,
        self::STATE_CANCELLED,
    ];

    /** States in which the batch is still open (blocks re-use of its requests). */
    public const OPEN_STATES = [
        self::STATE_DRAFT,
        self::STATE_PROCESSING,
        self::STATE_PARTIALLY_PAID,
    ];

    protected $fillable = [
        'created_by',
        'branch_id',
        'reference',
        'provider',
        'state',
        'note',
        'executed_by',
        'executed_at',
    ];

    protected $casts = [
        'executed_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(TreasuryPaymentBatchItem::class, 'batch_id')->orderBy('id');
    }

    public function isDraft(): bool
    {
        return $this->state === self::STATE_DRAFT;
    }

    public function isOpen(): bool
    {
        return in_array($this->state, self::OPEN_STATES, true);
    }

    public function isPaid(): bool
    {
        return $this->state === self::STATE_PAID;
    }

    public function isPartiallyPaid(): bool
    {
        return $this->state === self::STATE_PARTIALLY_PAID;
    }
}
