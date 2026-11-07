<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 5 — a petty-cash fund (plan §5).
 *
 * A small cash float held by a custodian on one financial account
 * (a cash register is the usual choice). The limit is the target
 * float: replenishment due = limit − posted balance, floored at
 * zero. One fund per account — two funds on the same account would
 * make the balance meaningless.
 *
 * Expenses post through the existing Expense model (PENDING →
 * APPROVED → EXECUTED) with the fund and the cost centre on the
 * line; replenishment uses approved expenses and returned amounts.
 */
class TreasuryPettyCashFund extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
    ];

    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCY_MONTHLY = 'monthly';
    public const FREQUENCY_ON_DEMAND = 'on_demand';

    public const FREQUENCIES = [
        self::FREQUENCY_WEEKLY,
        self::FREQUENCY_MONTHLY,
        self::FREQUENCY_ON_DEMAND,
    ];

    public const FREQUENCY_LABELS = [
        self::FREQUENCY_WEEKLY => 'Weekly',
        self::FREQUENCY_MONTHLY => 'Monthly',
        self::FREQUENCY_ON_DEMAND => 'On demand',
    ];

    protected $fillable = [
        'created_by',
        'custodian_user_id',
        'financial_account_id',
        'branch_id',
        'limit_amount',
        'replenishment_frequency',
        'status',
    ];

    protected $casts = [
        'limit_amount' => 'decimal:4',
    ];

    public function custodian()
    {
        return $this->belongsTo(User::class, 'custodian_user_id');
    }

    public function financialAccount()
    {
        return $this->belongsTo(TreasuryFinancialAccount::class, 'financial_account_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class, 'treasury_petty_cash_fund_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
