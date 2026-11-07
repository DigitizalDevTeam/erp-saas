<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Contract Management Phase 1 — billing contract master.
 *
 * A customer contract with a billing schedule. Lifecycle:
 * draft -> pending_approval -> active -> suspended -> ended /
 * cancelled. Draft is editable; pending approval has no billing;
 * active bills on schedule (Phase 2 engine); suspended bills
 * nothing until resumed; ended/cancelled need an effective date
 * and cancel their unissued periods.
 *
 * Never confuse this with the generic project/client Contract
 * model (Contract.php) — different table, different routes,
 * different purpose.
 */
class BillingContract extends Model
{
    public const STATE_DRAFT = 'draft';
    public const STATE_PENDING_APPROVAL = 'pending_approval';
    public const STATE_ACTIVE = 'active';
    public const STATE_SUSPENDED = 'suspended';
    public const STATE_ENDED = 'ended';
    public const STATE_CANCELLED = 'cancelled';

    public const STATES = [
        self::STATE_DRAFT,
        self::STATE_PENDING_APPROVAL,
        self::STATE_ACTIVE,
        self::STATE_SUSPENDED,
        self::STATE_ENDED,
        self::STATE_CANCELLED,
    ];

    public const FREQUENCY_MONTHLY = 'monthly';
    public const FREQUENCY_QUARTERLY = 'quarterly';
    public const FREQUENCY_SEMI_ANNUAL = 'semi-annual';
    public const FREQUENCY_ANNUAL = 'annual';
    public const FREQUENCY_CUSTOM = 'custom';

    public const FREQUENCIES = [
        self::FREQUENCY_MONTHLY,
        self::FREQUENCY_QUARTERLY,
        self::FREQUENCY_SEMI_ANNUAL,
        self::FREQUENCY_ANNUAL,
        self::FREQUENCY_CUSTOM,
    ];

    public const CUSTOM_UNITS = ['days', 'weeks', 'months', 'years'];

    public const BILLING_MODE_ADVANCE = 'advance';
    public const BILLING_MODE_ARREARS = 'arrears';

    public const BILLING_MODES = [
        self::BILLING_MODE_ADVANCE,
        self::BILLING_MODE_ARREARS,
    ];

    public const APPROVAL_MODE_APPROVAL = 'approval';
    public const APPROVAL_MODE_AUTOMATIC = 'automatic';

    public const APPROVAL_MODES = [
        self::APPROVAL_MODE_APPROVAL,
        self::APPROVAL_MODE_AUTOMATIC,
    ];

    /** Partial first/final period rule (D3): full | prorate | skip. */
    public const PARTIAL_FULL = 'full';
    public const PARTIAL_PRORATE = 'prorate';
    public const PARTIAL_SKIP = 'skip';

    public const PARTIAL_RULES = [
        self::PARTIAL_FULL,
        self::PARTIAL_PRORATE,
        self::PARTIAL_SKIP,
    ];

    protected $fillable = [
        'created_by', 'branch_id', 'customer_id', 'reference', 'title',
        'description', 'start_date', 'end_date', 'currency',
        'exchange_rate', 'billing_frequency', 'custom_interval',
        'custom_unit', 'anchor_day', 'billing_mode',
        'payment_terms_days', 'approval_mode', 'proration_enabled',
        'partial_period_rule', 'state', 'approved_by', 'approved_at',
        'rejected_reason', 'suspended_reason', 'suspended_at',
        'ended_at', 'cancelled_at', 'renewal_notice_days', 'auto_renew',
        'delivery_auto_email', 'delivery_to', 'delivery_cc', 'notes',
        'current_revision_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'approved_at' => 'datetime',
        'suspended_at' => 'datetime',
        'ended_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'proration_enabled' => 'boolean',
        'auto_renew' => 'boolean',
        'delivery_auto_email' => 'boolean',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function lines()
    {
        return $this->hasMany(BillingContractLine::class, 'contract_id');
    }

    public function occurrences()
    {
        return $this->hasMany(BillingContractOccurrence::class, 'contract_id');
    }

    public function revisions()
    {
        return $this->hasMany(BillingContractRevision::class, 'contract_id')
            ->orderBy('revision_number');
    }

    public function auditEvents()
    {
        return $this->hasMany(BillingContractAuditEvent::class, 'contract_id')
            ->orderByDesc('id');
    }

    public function currentRevision()
    {
        return $this->belongsTo(BillingContractRevision::class, 'current_revision_id');
    }

    public function isEditable(): bool
    {
        return $this->state === self::STATE_DRAFT;
    }

    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }
}
