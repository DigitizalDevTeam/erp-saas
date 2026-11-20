<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerCreditLedger extends Model
{
    /**
     * Credit Notes Phase 3 — one row per credit event for a customer.
     *
     * An `added` row is a LOT: the credit a credit note added, usable
     * oldest-first until `expires_at` (created_at + 14 days). `used`
     * rows record credit applied to an invoice, `expired` rows record
     * a lot that ran out of time, `reversed` rows record a lot that
     * was cancelled when its note was deleted. Every row carries the
     * lot's `remaining` balance after the event, so the ledger reads
     * as the customer's checking account.
     */
    public const TYPE_ADDED = 'added';
    public const TYPE_USED = 'used';
    public const TYPE_EXPIRED = 'expired';
    public const TYPE_REVERSED = 'reversed';

    public const TYPE_LABELS = [
        self::TYPE_ADDED => 'Added',
        self::TYPE_USED => 'Used',
        self::TYPE_EXPIRED => 'Expired',
        self::TYPE_REVERSED => 'Reversed',
    ];

    protected $table = 'customer_credit_ledger';

    protected $fillable = [
        'customer_id',
        'credit_note_id',
        'invoice_id',
        'payment_id',
        'type',
        'amount',
        'remaining',
        'expires_at',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'remaining' => 'decimal:2',
        'expires_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function creditNote()
    {
        return $this->belongsTo(CreditNote::class, 'credit_note_id');
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
