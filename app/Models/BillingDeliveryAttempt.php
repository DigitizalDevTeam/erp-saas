<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Contract Management Phase 2 — billing delivery attempt.
 *
 * One row per issued contract invoice: the email delivery history
 * for that invoice. Statuses: queued | sent | failed | skipped.
 * 'skipped' = email delivery is disabled for the contract, so no
 * send was attempted. A failed send keeps next_retry_at until the
 * backoff is exhausted (4 sends max), then it stays 'failed' with
 * next_retry_at null — a manual resend can still retry it.
 */
class BillingDeliveryAttempt extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_SENT,
        self::STATUS_FAILED,
        self::STATUS_SKIPPED,
    ];

    protected $fillable = [
        'created_by', 'contract_id', 'occurrence_id', 'invoice_id',
        'channel', 'recipients_to', 'recipients_cc', 'status',
        'attempt_count', 'next_retry_at', 'last_error', 'pdf_path',
        'sent_at',
    ];

    protected $casts = [
        'next_retry_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function contract()
    {
        return $this->belongsTo(BillingContract::class, 'contract_id');
    }

    public function occurrence()
    {
        return $this->belongsTo(BillingContractOccurrence::class, 'occurrence_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
