<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 8 — a cash-flow alert record (plan §8, §10).
 *
 * Recomputed by the forecast-refresh job and the cash-flow
 * screen: one active alert per (company, type, source). The
 * refresh upserts by that key, so a job run twice never
 * duplicates an alert, and resolves the alert when its
 * condition clears. No email is sent in this phase; the records
 * + screen are the deliverable.
 */
class TreasuryCashFlowAlert extends Model
{
    public const TYPE_UPCOMING_OCCURRENCE = 'upcoming_occurrence';
    public const TYPE_OVERDUE_OCCURRENCE = 'overdue_occurrence';
    public const TYPE_INSUFFICIENT_FUNDS = 'insufficient_funds';
    public const TYPE_PENDING_APPROVAL = 'pending_approval';
    public const TYPE_DELAYED_TRANSFER = 'delayed_transfer';
    public const TYPE_OVERDUE_ADVANCE = 'overdue_advance';
    public const TYPE_SYNC_FAILURE = 'sync_failure';

    public const TYPES = [
        self::TYPE_UPCOMING_OCCURRENCE,
        self::TYPE_OVERDUE_OCCURRENCE,
        self::TYPE_INSUFFICIENT_FUNDS,
        self::TYPE_PENDING_APPROVAL,
        self::TYPE_DELAYED_TRANSFER,
        self::TYPE_OVERDUE_ADVANCE,
        self::TYPE_SYNC_FAILURE,
    ];

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    public const STATE_ACTIVE = 'active';
    public const STATE_ACKNOWLEDGED = 'acknowledged';
    public const STATE_RESOLVED = 'resolved';

    public const STATES = [
        self::STATE_ACTIVE,
        self::STATE_ACKNOWLEDGED,
        self::STATE_RESOLVED,
    ];

    protected $fillable = [
        'company_id',
        'created_by',
        'type',
        'severity',
        'title',
        'detail',
        'source_type',
        'source_id',
        'state',
        'occurred_at',
        'acknowledged_by',
        'acknowledged_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }
}
