<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 2 — payment attempts (plan §5, CA06, CA20).
 *
 * One row per execution attempt. A retry is a NEW attempt row, never
 * a re-send of the old one. A timeout sets the outcome to unknown —
 * the provider is queried before any retry.
 */
class TreasuryPaymentAttempt extends Model
{
    public const STATE_PROCESSING = 'processing';
    public const STATE_OUTCOME_UNKNOWN = 'outcome_unknown';
    public const STATE_PAID = 'paid';
    public const STATE_FAILED = 'failed';

    public const STATES = [
        self::STATE_PROCESSING,
        self::STATE_OUTCOME_UNKNOWN,
        self::STATE_PAID,
        self::STATE_FAILED,
    ];

    protected $fillable = [
        'payment_request_id',
        'attempt_number',
        'idempotency_key',
        'provider',
        'provider_reference',
        'state',
        'result',
        'payload_hash',
        'amount_original',
        'amount_base',
        'currency',
        'journal_id',
        'entry_id',
        'created_by',
    ];

    protected $casts = [
        'amount_original' => 'decimal:4',
        'amount_base' => 'decimal:4',
    ];

    public function request()
    {
        return $this->belongsTo(TreasuryPaymentRequest::class, 'payment_request_id');
    }

    public function isProcessing(): bool
    {
        return $this->state === self::STATE_PROCESSING;
    }

    public function isOutcomeUnknown(): bool
    {
        return $this->state === self::STATE_OUTCOME_UNKNOWN;
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
