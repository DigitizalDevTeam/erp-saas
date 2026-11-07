<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 1 — receipts (plan §5).
 *
 * Workflow: DRAFT -> PENDING -> CONFIRMED; PENDING can be REJECTED
 * or CANCELLED. A receipt number is issued only after confirmation
 * (== the operation reference). Confirmed means immutable (RF03):
 * corrected only by an authorised reversal.
 *
 * Amount model (plan §7): `amount_original` is the gross amount the
 * customer paid, before bank fees and withholding. Cash credited to
 * the bank is gross - fees - withholding. The settlement value
 * (what is applied to invoices) is gross + discount.
 */
class TreasuryReceipt extends Model
{
    public const METHOD_CASH = 'cash';
    public const METHOD_TRANSFER = 'transfer';
    public const METHOD_DEPOSIT = 'deposit';
    public const METHOD_CHEQUE = 'cheque';
    public const METHOD_CARD = 'card';
    public const METHOD_MOBILE_WALLET = 'mobile_wallet';

    public const METHODS = [
        self::METHOD_CASH,
        self::METHOD_TRANSFER,
        self::METHOD_DEPOSIT,
        self::METHOD_CHEQUE,
        self::METHOD_CARD,
        self::METHOD_MOBILE_WALLET,
    ];

    public const METHOD_LABELS = [
        self::METHOD_CASH => 'Cash',
        self::METHOD_TRANSFER => 'Bank transfer',
        self::METHOD_DEPOSIT => 'Deposit',
        self::METHOD_CHEQUE => 'Cheque',
        self::METHOD_CARD => 'Card',
        self::METHOD_MOBILE_WALLET => 'Mobile wallet (M-Pesa / e-Mola / mKesh)',
    ];

    public const STATE_DRAFT = 'draft';
    public const STATE_PENDING = 'pending';
    public const STATE_CONFIRMED = 'confirmed';
    public const STATE_REJECTED = 'rejected';
    public const STATE_CANCELLED = 'cancelled';
    public const STATE_REVERSED = 'reversed';

    public const STATES = [
        self::STATE_DRAFT,
        self::STATE_PENDING,
        self::STATE_CONFIRMED,
        self::STATE_REJECTED,
        self::STATE_CANCELLED,
        self::STATE_REVERSED,
    ];

    protected $fillable = [
        'operation_id',
        'customer_id',
        'method',
        'external_transaction_id',
        'value_date',
        'cheque_bank',
        'cheque_number',
        'fees',
        'withholding',
        'discount',
        'state',
        'confirmed_by',
        'confirmed_at',
        'receipt_number',
        'classification',
        'classification_reason',
        'evidence',
        'reversal_of_id',
        'reversal_reason',
        'created_by',
    ];

    protected $casts = [
        'fees' => 'decimal:4',
        'withholding' => 'decimal:4',
        'discount' => 'decimal:4',
        'value_date' => 'date',
        'confirmed_at' => 'datetime',
    ];

    public function operation()
    {
        return $this->belongsTo(TreasuryOperation::class, 'operation_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function allocations()
    {
        return $this->hasMany(TreasurySettlementAllocation::class, 'receipt_id');
    }

    public function isDraft(): bool
    {
        return $this->state === self::STATE_DRAFT;
    }

    public function isPending(): bool
    {
        return $this->state === self::STATE_PENDING;
    }

    public function isConfirmed(): bool
    {
        return $this->state === self::STATE_CONFIRMED;
    }

    public function methodLabel(): string
    {
        return self::METHOD_LABELS[$this->method] ?? $this->method;
    }

    /** Gross amount paid by the customer (operation amount). */
    public function grossAmount(): string
    {
        return (string) ($this->operation ? $this->operation->amount_original : '0.0000');
    }

    /** Cash actually credited to the bank: gross - fees - withholding. */
    public function netCashAmount(): string
    {
        return bcsub(bcsub($this->grossAmount(), (string) $this->fees, 4), (string) $this->withholding, 4);
    }

    /** Settlement value: what can be applied to invoices (gross + discount). */
    public function settlementValue(): string
    {
        return bcadd($this->grossAmount(), (string) $this->discount, 4);
    }

    /** Settlement value in the company base currency. */
    public function settlementValueBase(): string
    {
        $operation = $this->operation;

        if (! $operation) {
            return '0.0000';
        }

        return bcadd((string) $operation->amount_base, bcmul((string) $this->discount, (string) ($operation->exchange_rate ?? '1'), 4), 4);
    }
}
