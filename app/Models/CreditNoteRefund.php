<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Credit Notes Phase 2 — one Treasury refund (Reembolso) per credit
 * note. A refund always pays the FULL note amount, once: the plan's
 * rule is "cannot exceed the note amount, cannot be refunded twice".
 * The row links the note to the confirmed payment operation + journal
 * it posted, the source the money left from, the till session when
 * the refund was recorded at the till, and both receipt files.
 */
class CreditNoteRefund extends Model
{
    public const METHOD_TRANSFER = 'transfer';
    public const METHOD_CASH = 'cash';

    public const METHODS = [
        self::METHOD_TRANSFER,
        self::METHOD_CASH,
    ];

    protected $fillable = [
        'credit_note_id',
        'invoice_id',
        'customer_id',
        'treasury_operation_id',
        'journal_id',
        'method',
        'financial_account_id',
        'petty_cash_fund_id',
        'cash_session_id',
        'amount',
        'currency',
        'transfer_receipt',
        'signed_receipt',
        'reference',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function note()
    {
        return $this->belongsTo(CreditNote::class, 'credit_note_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function operation()
    {
        return $this->belongsTo(TreasuryOperation::class, 'treasury_operation_id');
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_id');
    }

    public function financialAccount()
    {
        return $this->belongsTo(TreasuryFinancialAccount::class, 'financial_account_id');
    }

    public function pettyCashFund()
    {
        return $this->belongsTo(TreasuryPettyCashFund::class, 'petty_cash_fund_id');
    }

    public function cashSession()
    {
        return $this->belongsTo(TreasuryCashSession::class, 'cash_session_id');
    }
}
