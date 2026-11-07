<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostCentreAllocation extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_POSTED = 'posted';
    public const STATUS_REVERSED = 'reversed';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_POSTED,
        self::STATUS_REVERSED,
    ];

    public const SOURCE_BILL = 'bill';
    public const SOURCE_EXPENSE = 'expense';

    public const SOURCES = [
        self::SOURCE_BILL,
        self::SOURCE_EXPENSE,
    ];

    public const LINE_BILL_PRODUCT = 'bill_product';
    public const LINE_BILL_ACCOUNT = 'bill_account';

    public const LINE_TYPES = [
        self::LINE_BILL_PRODUCT,
        self::LINE_BILL_ACCOUNT,
    ];

    public const BASIS_PERCENTAGE = 'percentage';
    public const BASIS_FIXED = 'fixed_amount';

    public const BASES = [
        self::BASIS_PERCENTAGE,
        self::BASIS_FIXED,
    ];

    protected $fillable = [
        'company_id',
        'created_by',
        'source_type',
        'source_id',
        'source_line_type',
        'source_line_id',
        'basis',
        'source_amount',
        'currency_code',
        'status',
        'rule_snapshot',
        'template_id',
        'replaces_allocation_id',
        'approved_by',
        'approved_at',
        'reversal_reason',
        'reversed_by',
        'reversed_at',
    ];

    protected $casts = [
        'source_amount' => 'decimal:2',
        'rule_snapshot' => 'array',
        'approved_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(CostCentreAllocationLine::class, 'allocation_id')->orderBy('sort_order');
    }

    public function template()
    {
        return $this->belongsTo(CostCentreAllocationTemplate::class, 'template_id');
    }

    public function replaces()
    {
        return $this->belongsTo(self::class, 'replaces_allocation_id');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }

    public function scopePosted($query)
    {
        return $query->where('status', self::STATUS_POSTED);
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }

    /** Sum of the persisted allocated amounts (never recomputed). */
    public function allocatedTotal(): string
    {
        return (string) $this->lines()->sum('allocated_amount');
    }
}
