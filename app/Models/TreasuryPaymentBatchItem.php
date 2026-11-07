<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 3 — batch items (plan §5, §6, CA07).
 *
 * One row per instruction in a batch: the payment request, the
 * amount to execute for it, the outcome of its own execution, the
 * attempt that settled it, and the failure reason when it failed.
 */
class TreasuryPaymentBatchItem extends Model
{
    public const STATE_PENDING = 'pending';
    public const STATE_PAID = 'paid';
    public const STATE_FAILED = 'failed';
    public const STATE_SKIPPED = 'skipped';

    public const STATES = [
        self::STATE_PENDING,
        self::STATE_PAID,
        self::STATE_FAILED,
        self::STATE_SKIPPED,
    ];

    protected $fillable = [
        'batch_id',
        'payment_request_id',
        'amount_original',
        'amount_base',
        'currency',
        'state',
        'attempt_id',
        'failure_reason',
        'created_by',
    ];

    protected $casts = [
        'amount_original' => 'decimal:4',
        'amount_base' => 'decimal:4',
    ];

    public function batch()
    {
        return $this->belongsTo(TreasuryPaymentBatch::class, 'batch_id');
    }

    public function paymentRequest()
    {
        return $this->belongsTo(TreasuryPaymentRequest::class, 'payment_request_id');
    }

    public function attempt()
    {
        return $this->belongsTo(TreasuryPaymentAttempt::class, 'attempt_id');
    }

    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }

    public function isPaid(): bool
    {
        return $this->state === self::STATE_PAID;
    }

    public function isFailed(): bool
    {
        return $this->state === self::STATE_FAILED;
    }
}
