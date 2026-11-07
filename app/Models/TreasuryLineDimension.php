<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury payment-line dimensions (Cost Centre Phase 3, plan §5/§7).
 *
 * One row per (payment, cost centre): which cost centres a payment's
 * cash belongs to, and how much each gets. Dimensions sit BESIDE the
 * money — these rows never create, change or delete a journal line, a
 * bank movement or a balance. The expense is recognised once, at the
 * bill; the payment only moves cash, once.
 *
 * Rows are never edited in place: an override supersedes the old set
 * (status 'superseded') and inserts a new set; the audit event keeps
 * the before/after. Receipts (customer InvoicePayment rows) are tagged
 * the same way, for cash-flow analysis only.
 */
class TreasuryLineDimension extends Model
{
    public const TYPE_BILL_PAYMENT = 'bill_payment';
    public const TYPE_RECEIPT = 'receipt';
    public const TYPE_TREASURY_PAYMENT = 'treasury_payment';
    // Phase 6 — an approved advance expense line, tagged with the
    // cost centre on the line (payment_id = the line id).
    public const TYPE_TREASURY_ADVANCE = 'treasury_advance';

    public const TYPES = [
        self::TYPE_BILL_PAYMENT,
        self::TYPE_RECEIPT,
        self::TYPE_TREASURY_PAYMENT,
        self::TYPE_TREASURY_ADVANCE,
    ];

    public const SOURCE_INHERITED = 'inherited';
    public const SOURCE_MANUAL = 'manual';

    public const SOURCES = [
        self::SOURCE_INHERITED,
        self::SOURCE_MANUAL,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_SUPERSEDED,
    ];

    protected $fillable = [
        'company_id',
        'created_by',
        'payment_type',
        'payment_id',
        'bill_id',
        'cost_centre_id',
        'project_id',
        'asset_id',
        'allocated_amount',
        'percentage',
        'source',
        'status',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'percentage' => 'decimal:6',
    ];

    public function costCentre()
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function bill()
    {
        return $this->belongsTo(Bill::class, 'bill_id');
    }

    public function billPayment()
    {
        return $this->belongsTo(BillPayment::class, 'payment_id');
    }

    public function receipt()
    {
        return $this->belongsTo(InvoicePayment::class, 'payment_id');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForPayment($query, string $paymentType, int $paymentId)
    {
        return $query->where('payment_type', $paymentType)->where('payment_id', $paymentId);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isInherited(): bool
    {
        return $this->source === self::SOURCE_INHERITED;
    }
}
