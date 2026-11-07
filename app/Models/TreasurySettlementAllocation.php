<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 1 — settlement allocations (plan §5).
 *
 * One receipt settles many invoices; many receipts settle one
 * invoice. Total applied can never exceed the outstanding debt or
 * the available (unapplied) credit. A reversal marks the allocation
 * 'reversed' and reopens the debt (CA15).
 */
class TreasurySettlementAllocation extends Model
{
    public const TARGET_INVOICE = 'invoice';
    public const TARGET_BILL = 'bill';

    public const STATE_ACTIVE = 'active';
    public const STATE_REVERSED = 'reversed';

    public const SOURCE_RECEIPT = 'receipt';
    public const SOURCE_UNAPPLIED_CREDIT = 'unapplied_credit';

    protected $fillable = [
        'receipt_id',
        'payment_id',
        'target_type',
        'target_id',
        'allocated_amount',
        'allocated_amount_base',
        'currency',
        'exchange_rate',
        'rate_source',
        'rate_date',
        'state',
        'source',
        'approved_by',
        'invoice_payment_id',
        'journal_id',
        'created_by',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:4',
        'allocated_amount_base' => 'decimal:4',
        'exchange_rate' => 'decimal:10',
        'rate_date' => 'date',
    ];

    public function receipt()
    {
        return $this->belongsTo(TreasuryReceipt::class, 'receipt_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'target_id');
    }

    public function invoicePayment()
    {
        return $this->belongsTo(InvoicePayment::class, 'invoice_payment_id');
    }

    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }

    public function scopeActive($query)
    {
        return $query->where('state', self::STATE_ACTIVE);
    }
}
