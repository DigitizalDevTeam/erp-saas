<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CostCentreCommitment extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_RELEASED = 'released';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_RELEASED,
        self::STATUS_CONVERTED,
        self::STATUS_CANCELLED,
    ];

    // Only active commitments reserve budget.
    public const COUNTING_STATUSES = [
        self::STATUS_ACTIVE,
    ];

    public const SOURCE_PURCHASE = 'purchase';
    public const SOURCE_BILL = 'bill';
    public const SOURCE_EXPENSE = 'expense';
    public const SOURCE_MANUAL = 'manual';

    public const SOURCES = [
        self::SOURCE_PURCHASE,
        self::SOURCE_BILL,
        self::SOURCE_EXPENSE,
        self::SOURCE_MANUAL,
    ];

    protected $fillable = [
        'company_id',
        'created_by',
        'fiscal_year',
        'period',
        'cost_centre_id',
        'gl_account_id',
        'project_id',
        'scope_key',
        'source_type',
        'source_id',
        'amount',
        'currency_code',
        'status',
        'converted_bill_id',
        'approval_id',
        'requested_by',
        'released_by',
        'released_at',
        'reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'released_at' => 'datetime',
    ];

    public function centre()
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function approval()
    {
        return $this->belongsTo(CostCentreBudgetApproval::class, 'approval_id');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }

    public function isCounting(): bool
    {
        return in_array($this->status, self::COUNTING_STATUSES, true);
    }
}
