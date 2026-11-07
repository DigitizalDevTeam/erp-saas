<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 0 — financial account (plan §5).
 *
 * Extends the existing `bank_accounts` row (holder name, bank /
 * institution name, account number, chart account, contact, address
 * live there and are reused, never duplicated here). One treasury row
 * per bank account carries the Treasury-specific fields.
 *
 * Money is DECIMAL(20,4); rates are DECIMAL(20,10) (RF04). Decimal
 * casts return strings so bcmath stays exact — never float.
 */
class TreasuryFinancialAccount extends Model
{
    public const TYPE_BANK = 'bank';
    public const TYPE_CASH_REGISTER = 'cash_register';
    public const TYPE_MOBILE_WALLET = 'mobile_wallet';
    public const TYPE_FUNDS_IN_TRANSIT = 'funds_in_transit';

    public const TYPES = [
        self::TYPE_BANK,
        self::TYPE_CASH_REGISTER,
        self::TYPE_MOBILE_WALLET,
        self::TYPE_FUNDS_IN_TRANSIT,
    ];

    public const TYPE_LABELS = [
        self::TYPE_BANK => 'Bank account',
        self::TYPE_CASH_REGISTER => 'Cash register',
        self::TYPE_MOBILE_WALLET => 'Mobile wallet',
        self::TYPE_FUNDS_IN_TRANSIT => 'Funds in transit',
    ];

    public const POLICY_BLOCK = 'block';
    public const POLICY_ALLOW = 'allow';
    public const POLICY_APPROVAL = 'approval';

    public const NEGATIVE_BALANCE_POLICIES = [
        self::POLICY_BLOCK,
        self::POLICY_ALLOW,
        self::POLICY_APPROVAL,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $fillable = [
        'bank_account_id',
        'created_by',
        'code',
        'type',
        'currency',
        'currency_locked',
        'branch_id',
        'nib_or_iban',
        'owner_user_id',
        'cash_limit',
        'minimum_balance',
        'negative_balance_policy',
        'negative_balance_limit',
        'negative_balance_approver_user_id',
        'status',
    ];

    protected $casts = [
        'currency_locked' => 'boolean',
        'cash_limit' => 'decimal:4',
        'minimum_balance' => 'decimal:4',
        'negative_balance_limit' => 'decimal:4',
    ];

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function openingBalances()
    {
        return $this->hasMany(TreasuryOpeningBalance::class, 'financial_account_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    /** Display name: code + holder/institution from the bank account. */
    public function displayName(): string
    {
        $bank = $this->bankAccount;

        if (! $bank) {
            return $this->code;
        }

        return $this->code . ' — ' . $bank->holder_name . ' (' . $bank->bank_name . ')';
    }
}
