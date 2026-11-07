<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Contract Management Phase 3 — billing reminder.
 *
 * One row per overdue contract invoice: the reminder schedule
 * for that invoice. Statuses: scheduled (waiting for
 * next_reminder_at), sent (last send succeeded, more may be
 * scheduled), failed (last send failed, retry at next_retry_at),
 * stopped (invoice settled or cancelled, or the max number of
 * reminders was reached). sent_count = reminders actually sent;
 * attempt_count = send attempts for the current reminder
 * (backoff 5 min → 30 min → 2 h, 4 max — same ladder as the
 * invoice delivery attempts in Phase 2).
 */
class BillingReminder extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_STOPPED = 'stopped';

    public const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_SENT,
        self::STATUS_FAILED,
        self::STATUS_STOPPED,
    ];

    protected $fillable = [
        'created_by', 'contract_id', 'occurrence_id', 'invoice_id',
        'status', 'sent_count', 'attempt_count', 'next_reminder_at',
        'next_retry_at', 'last_sent_at', 'last_error',
        'recipients_to', 'recipients_cc',
    ];

    protected $casts = [
        'next_reminder_at' => 'date',
        'next_retry_at' => 'datetime',
        'last_sent_at' => 'datetime',
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

    public function isStopped(): bool
    {
        return $this->status === self::STATUS_STOPPED;
    }
}
