<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 2 — payment requests (plan §5, §6).
 *
 * Workflow: draft → under_review → awaiting_approval → approved →
 * processing → paid / failed / rejected; outcome_unknown; cancelled
 * or reversed. Draft edits change nothing; approval reserves funds
 * if configured; processing blocks concurrent resubmission.
 *
 * Money is DECIMAL(20,4), rates DECIMAL(20,10) — decimal casts
 * return strings so bcmath stays exact, never float (RF04).
 */
class TreasuryPaymentRequest extends Model
{
    public const BENEFICIARY_VENDOR = 'vendor';
    public const BENEFICIARY_EMPLOYEE = 'employee';
    public const BENEFICIARY_CUSTOMER = 'customer';
    public const BENEFICIARY_OTHER = 'other';

    public const BENEFICIARY_TYPES = [
        self::BENEFICIARY_VENDOR,
        self::BENEFICIARY_EMPLOYEE,
        self::BENEFICIARY_CUSTOMER,
        self::BENEFICIARY_OTHER,
    ];

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_NORMAL,
        self::PRIORITY_HIGH,
        self::PRIORITY_URGENT,
    ];

    public const STATE_DRAFT = 'draft';
    public const STATE_UNDER_REVIEW = 'under_review';
    public const STATE_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATE_APPROVED = 'approved';
    public const STATE_PROCESSING = 'processing';
    public const STATE_PAID = 'paid';
    public const STATE_FAILED = 'failed';
    public const STATE_REJECTED = 'rejected';
    public const STATE_OUTCOME_UNKNOWN = 'outcome_unknown';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_REVERSED = 'reversed';

    public const STATES = [
        self::STATE_DRAFT,
        self::STATE_UNDER_REVIEW,
        self::STATE_AWAITING_APPROVAL,
        self::STATE_APPROVED,
        self::STATE_PROCESSING,
        self::STATE_PAID,
        self::STATE_FAILED,
        self::STATE_REJECTED,
        self::STATE_OUTCOME_UNKNOWN,
        self::STATE_CANCELLED,
        self::STATE_REVERSED,
    ];

    /** States in which the request is still "open" (blocks duplicates). */
    public const OPEN_STATES = [
        self::STATE_DRAFT,
        self::STATE_UNDER_REVIEW,
        self::STATE_AWAITING_APPROVAL,
        self::STATE_APPROVED,
        self::STATE_PROCESSING,
        self::STATE_OUTCOME_UNKNOWN,
    ];

    protected $fillable = [
        'operation_id',
        'created_by',
        'branch_id',
        'source_account_id',
        'beneficiary_type',
        'beneficiary_id',
        'beneficiary_name',
        'beneficiary_account',
        'amount_original',
        'currency',
        'exchange_rate',
        'rate_source',
        'rate_date',
        'amount_base',
        'due_date',
        'planned_date',
        'priority',
        'reason',
        'cost_centre_id',
        'project_id',
        'source_documents',
        'client_reference',
        'similar_flags',
        'state',
        'requested_by',
        'executor_user_id',
        'approved_snapshot',
        'approved_rule_version',
        'approved_at',
        'rejection_reason',
        'return_reason',
    ];

    protected $casts = [
        'amount_original' => 'decimal:4',
        'exchange_rate' => 'decimal:10',
        'amount_base' => 'decimal:4',
        'due_date' => 'date',
        'planned_date' => 'date',
        'rate_date' => 'date',
        'source_documents' => 'array',
        'similar_flags' => 'array',
        'approved_snapshot' => 'array',
        'approved_at' => 'datetime',
    ];

    public function operation()
    {
        return $this->belongsTo(TreasuryOperation::class, 'operation_id');
    }

    public function sourceAccount()
    {
        return $this->belongsTo(TreasuryFinancialAccount::class, 'source_account_id');
    }

    public function steps()
    {
        return $this->hasMany(TreasuryApprovalStep::class, 'payment_request_id')->orderBy('level');
    }

    public function decisions()
    {
        return $this->hasMany(TreasuryApprovalDecision::class, 'payment_request_id')->orderBy('decided_at');
    }

    public function reservations()
    {
        return $this->hasMany(TreasuryFundReservation::class, 'payment_request_id')->orderByDesc('id');
    }

    public function attempts()
    {
        return $this->hasMany(TreasuryPaymentAttempt::class, 'payment_request_id')->orderBy('attempt_number');
    }

    public function activeReservation()
    {
        return $this->hasOne(TreasuryFundReservation::class, 'payment_request_id')
            ->where('state', TreasuryFundReservation::STATE_ACTIVE)
            ->orderByDesc('id');
    }

    public function isDraft(): bool
    {
        return $this->state === self::STATE_DRAFT;
    }

    public function isUnderReview(): bool
    {
        return $this->state === self::STATE_UNDER_REVIEW;
    }

    public function isAwaitingApproval(): bool
    {
        return $this->state === self::STATE_AWAITING_APPROVAL;
    }

    public function isApproved(): bool
    {
        return $this->state === self::STATE_APPROVED;
    }

    public function isOpen(): bool
    {
        return in_array($this->state, self::OPEN_STATES, true);
    }

    /** Source documents as a list of ['type' => ..., 'id' => ...]. */
    public function sourceDocuments(): array
    {
        $docs = $this->source_documents;
        if (! is_array($docs)) {
            return [];
        }

        return array_values(array_filter($docs, fn ($doc) => is_array($doc) && isset($doc['type'], $doc['id'])));
    }

    public function beneficiaryLabel(): string
    {
        if ($this->beneficiary_name !== null && $this->beneficiary_name !== '') {
            return (string) $this->beneficiary_name;
        }

        return ucfirst((string) $this->beneficiary_type) . ' #' . (string) $this->beneficiary_id;
    }
}
