<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 6 — employee / supplier advances (plan §5, §6).
 *
 * Lifecycle (plan §6): REQUEST → APPROVAL → DISBURSEMENT (cash
 * outflow created) → EXPENSE SUBSTANTIATION (per-line evidence,
 * approve / reject / return for correction) → REGULARISATION
 * (return of unspent money, or a separate reimbursement when
 * approved expenses exceed the advance) → CLOSURE (only when the
 * balance is regularised and all lines are resolved).
 *
 * States: draft → pending_approval → approved → disbursed, then
 * partially_substantiated / under_review while lines are worked,
 * regularised once the balance is cleared, closed on explicit
 * close. Rejected and cancelled are terminal.
 *
 * Money is DECIMAL(20,4); rates are DECIMAL(20,10) (RF04).
 * Decimal casts return strings so bcmath stays exact.
 */
class TreasuryAdvance extends Model
{
    public const RECIPIENT_EMPLOYEE = 'employee';
    public const RECIPIENT_SUPPLIER = 'supplier';

    public const RECIPIENT_TYPES = [
        self::RECIPIENT_EMPLOYEE,
        self::RECIPIENT_SUPPLIER,
    ];

    public const STATE_DRAFT = 'draft';
    public const STATE_PENDING_APPROVAL = 'pending_approval';
    public const STATE_APPROVED = 'approved';
    public const STATE_REJECTED = 'rejected';
    public const STATE_DISBURSED = 'disbursed';
    public const STATE_PARTIALLY_SUBSTANTIATED = 'partially_substantiated';
    public const STATE_UNDER_REVIEW = 'under_review';
    public const STATE_REGULARISED = 'regularised';
    public const STATE_CLOSED = 'closed';
    public const STATE_CANCELLED = 'cancelled';

    public const STATES = [
        self::STATE_DRAFT,
        self::STATE_PENDING_APPROVAL,
        self::STATE_APPROVED,
        self::STATE_REJECTED,
        self::STATE_DISBURSED,
        self::STATE_PARTIALLY_SUBSTANTIATED,
        self::STATE_UNDER_REVIEW,
        self::STATE_REGULARISED,
        self::STATE_CLOSED,
        self::STATE_CANCELLED,
    ];

    /** States in which money is out and lines can still be worked. */
    public const DISBURSED_STATES = [
        self::STATE_DISBURSED,
        self::STATE_PARTIALLY_SUBSTANTIATED,
        self::STATE_UNDER_REVIEW,
    ];

    public const REIMBURSEMENT_NONE = 'none';
    public const REIMBURSEMENT_PENDING = 'pending';
    public const REIMBURSEMENT_APPROVED = 'approved';
    public const REIMBURSEMENT_REJECTED = 'rejected';
    public const REIMBURSEMENT_PAID = 'paid';

    public const REIMBURSEMENT_STATES = [
        self::REIMBURSEMENT_NONE,
        self::REIMBURSEMENT_PENDING,
        self::REIMBURSEMENT_APPROVED,
        self::REIMBURSEMENT_REJECTED,
        self::REIMBURSEMENT_PAID,
    ];

    protected $fillable = [
        'created_by',
        'branch_id',
        'operation_id',
        'source_account_id',
        'recipient_type',
        'recipient_id',
        'recipient_name',
        'purpose',
        'currency',
        'exchange_rate',
        'rate_source',
        'rate_date',
        'amount_requested',
        'amount_requested_base',
        'amount_approved',
        'amount_approved_base',
        'amount_paid',
        'amount_paid_base',
        'amount_substantiated',
        'amount_substantiated_base',
        'amount_returned',
        'amount_returned_base',
        'amount_outstanding',
        'amount_outstanding_base',
        'reimbursement_amount',
        'reimbursement_amount_base',
        'reimbursement_state',
        'reimbursement_approved_by',
        'reimbursement_approved_at',
        'reimbursement_paid_at',
        'reimbursement_journal_id',
        'reimbursement_operation_id',
        'reimbursement_reason',
        'due_date',
        'state',
        'requested_by',
        'approved_by',
        'approved_at',
        'rejection_reason',
        'disbursed_at',
        'disbursement_journal_id',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'exchange_rate' => 'decimal:10',
        'rate_date' => 'date',
        'amount_requested' => 'decimal:4',
        'amount_requested_base' => 'decimal:4',
        'amount_approved' => 'decimal:4',
        'amount_approved_base' => 'decimal:4',
        'amount_paid' => 'decimal:4',
        'amount_paid_base' => 'decimal:4',
        'amount_substantiated' => 'decimal:4',
        'amount_substantiated_base' => 'decimal:4',
        'amount_returned' => 'decimal:4',
        'amount_returned_base' => 'decimal:4',
        'amount_outstanding' => 'decimal:4',
        'amount_outstanding_base' => 'decimal:4',
        'reimbursement_amount' => 'decimal:4',
        'reimbursement_amount_base' => 'decimal:4',
        'reimbursement_approved_at' => 'datetime',
        'reimbursement_paid_at' => 'datetime',
        'due_date' => 'date',
        'approved_at' => 'datetime',
        'disbursed_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function operation()
    {
        return $this->belongsTo(TreasuryOperation::class, 'operation_id');
    }

    public function sourceAccount()
    {
        return $this->belongsTo(TreasuryFinancialAccount::class, 'source_account_id');
    }

    public function lines()
    {
        return $this->hasMany(TreasuryAdvanceExpenseLine::class, 'advance_id');
    }

    public function disbursementJournal()
    {
        return $this->belongsTo(JournalEntry::class, 'disbursement_journal_id');
    }

    public function reimbursementJournal()
    {
        return $this->belongsTo(JournalEntry::class, 'reimbursement_journal_id');
    }

    public function reimbursementOperation()
    {
        return $this->belongsTo(TreasuryOperation::class, 'reimbursement_operation_id');
    }

    public function isDraft(): bool
    {
        return $this->state === self::STATE_DRAFT;
    }

    public function isPendingApproval(): bool
    {
        return $this->state === self::STATE_PENDING_APPROVAL;
    }

    public function isApproved(): bool
    {
        return $this->state === self::STATE_APPROVED;
    }

    public function isDisbursed(): bool
    {
        return in_array($this->state, self::DISBURSED_STATES, true);
    }

    public function isRegularised(): bool
    {
        return $this->state === self::STATE_REGULARISED;
    }

    public function isClosed(): bool
    {
        return $this->state === self::STATE_CLOSED;
    }

    public function isCancelled(): bool
    {
        return $this->state === self::STATE_CANCELLED;
    }

    public function isRejected(): bool
    {
        return $this->state === self::STATE_REJECTED;
    }

    /** Reference of the linked operation (null while a draft). */
    public function reference(): ?string
    {
        return $this->operation ? (string) $this->operation->reference : null;
    }

    /** Overdue: due date passed with money still outstanding. */
    public function isOverdue(): bool
    {
        if (! $this->isDisbursed() || ! $this->due_date) {
            return false;
        }

        return $this->due_date->lt(Carbon::today())
            && bccomp((string) $this->amount_outstanding, '0', 4) > 0;
    }

    /** The reimbursement still needs a decision or payment. */
    public function hasOpenReimbursement(): bool
    {
        return in_array($this->reimbursement_state, [self::REIMBURSEMENT_PENDING, self::REIMBURSEMENT_APPROVED], true);
    }

    public function recipientLabel(): string
    {
        $type = ucfirst((string) $this->recipient_type);

        return trim((string) $this->recipient_name) !== ''
            ? (string) $this->recipient_name . ' (' . $type . ')'
            : $type;
    }
}
