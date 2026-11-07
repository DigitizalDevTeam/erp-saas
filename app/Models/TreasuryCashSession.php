<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 5 — one cash register session (plan §5, §6).
 *
 * States: open → closed → locked. A closed session can be reopened
 * (authorisation + reason + audit event); a locked session is
 * terminal. The count and the variance are preserved forever: a
 * re-count while open supersedes the draft count, a re-close posts
 * only the delta against the variance already posted (CA09).
 *
 * Expected physical cash = opening float + cash inflows − cash
 * outflows (handovers included). Card and mobile totals are tracked
 * separately and never enter the physical count. Variance = counted
 * − expected: negative is a shortage, positive a surplus.
 *
 * Money is DECIMAL(20,4); rates are DECIMAL(20,10) (RF04). Decimal
 * casts return strings so bcmath stays exact — never float.
 */
class TreasuryCashSession extends Model
{
    public const STATE_OPEN = 'open';
    public const STATE_CLOSED = 'closed';
    public const STATE_LOCKED = 'locked';

    public const STATES = [
        self::STATE_OPEN,
        self::STATE_CLOSED,
        self::STATE_LOCKED,
    ];

    /** Standard MZN denominations offered on the count form. */
    public const DENOMINATIONS = [
        '1000.0000',
        '500.0000',
        '200.0000',
        '100.0000',
        '50.0000',
        '20.0000',
        '10.0000',
        '5.0000',
        '2.0000',
        '1.0000',
        '0.5000',
    ];

    protected $fillable = [
        'created_by',
        'branch_id',
        'register_id',
        'operator_user_id',
        'shift_id',
        'currency',
        'exchange_rate',
        'rate_source',
        'rate_date',
        'opened_at',
        'closed_at',
        'opening_float',
        'opening_float_difference',
        'expected_balance',
        'counted_balance',
        'variance_amount',
        'variance_posted_amount',
        'variance_reason',
        'variance_approved_by',
        'variance_approved_at',
        'variance_operation_id',
        'variance_journal_id',
        'card_total',
        'mobile_total',
        'shift_cash_total',
        'shift_card_total',
        'shift_credit_total',
        'shift_difference',
        'shift_closing_balance',
        'operation_id',
        'reopen_reason',
        'reopened_by',
        'reopened_at',
        'lock_reason',
        'locked_by',
        'locked_at',
        'state',
    ];

    protected $casts = [
        'opening_float' => 'decimal:4',
        'opening_float_difference' => 'decimal:4',
        'expected_balance' => 'decimal:4',
        'counted_balance' => 'decimal:4',
        'variance_amount' => 'decimal:4',
        'variance_posted_amount' => 'decimal:4',
        'card_total' => 'decimal:4',
        'mobile_total' => 'decimal:4',
        'shift_cash_total' => 'decimal:4',
        'shift_card_total' => 'decimal:4',
        'shift_credit_total' => 'decimal:4',
        'shift_difference' => 'decimal:4',
        'shift_closing_balance' => 'decimal:4',
        'exchange_rate' => 'decimal:10',
        'rate_date' => 'date',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'variance_approved_at' => 'datetime',
        'reopened_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    public function register()
    {
        return $this->belongsTo(TreasuryFinancialAccount::class, 'register_id');
    }

    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_user_id');
    }

    public function shift()
    {
        return $this->belongsTo(PosShift::class, 'shift_id');
    }

    public function operation()
    {
        return $this->belongsTo(TreasuryOperation::class, 'operation_id');
    }

    public function varianceOperation()
    {
        return $this->belongsTo(TreasuryOperation::class, 'variance_operation_id');
    }

    public function varianceApprover()
    {
        return $this->belongsTo(User::class, 'variance_approved_by');
    }

    public function counts()
    {
        return $this->hasMany(TreasuryCashCount::class, 'cash_session_id');
    }

    public function movements()
    {
        return $this->hasMany(TreasuryCashMovement::class, 'cash_session_id');
    }

    public function isOpen(): bool
    {
        return $this->state === self::STATE_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->state === self::STATE_CLOSED;
    }

    public function isLocked(): bool
    {
        return $this->state === self::STATE_LOCKED;
    }

    /** True once a denomination count has been recorded. */
    public function hasCount(): bool
    {
        return $this->counted_balance !== null;
    }

    /** Signed variance (counted − expected), or null before a count. */
    public function variance(): ?string
    {
        if ($this->counted_balance === null) {
            return null;
        }

        return bcsub((string) $this->counted_balance, (string) $this->expected_balance, 4);
    }
}
