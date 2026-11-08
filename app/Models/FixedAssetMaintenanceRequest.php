<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixed Assets Phase 4 — maintenance request (plan §5; spec §15).
 *
 * Workflow: requested → approved → in_progress → completed →
 * verified; rejected / cancelled are terminal. Approval reuses
 * the Phase 0 FixedAssetApproval engine (type "maintenance");
 * the approver must differ from the requester — no
 * self-approval, at any value.
 *
 * On approval the asset moves to under_maintenance; on
 * completion (or cancel after approval) it returns to
 * previous_status. Assignment, cost centre and depreciation
 * schedule are untouched by maintenance.
 */
class FixedAssetMaintenanceRequest extends Model
{
    public const TYPE_CORRECTIVE = 'corrective';
    public const TYPE_PREVENTIVE = 'preventive';
    public const TYPE_INSPECTION = 'inspection';

    public const TYPES = [
        self::TYPE_CORRECTIVE,
        self::TYPE_PREVENTIVE,
        self::TYPE_INSPECTION,
    ];

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_MEDIUM,
        self::PRIORITY_HIGH,
        self::PRIORITY_URGENT,
    ];

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_VERIFIED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /** A request in one of these states still owns the asset. */
    public const ACTIVE_STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
    ];

    protected $fillable = [
        'created_by',
        'asset_id',
        'type',
        'priority',
        'description',
        'expected_cost',
        'status',
        'previous_status',
        'requested_by',
        'approved_by',
        'started_by',
        'completed_by',
        'verified_by',
        'requested_at',
        'approved_at',
        'started_at',
        'completed_at',
        'verified_at',
        'completion_notes',
        'rejection_reason',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function costs()
    {
        return $this->hasMany(FixedAssetMaintenanceCost::class, 'maintenance_request_id')->orderBy('recorded_at');
    }

    public function approvals()
    {
        return $this->hasMany(FixedAssetApproval::class, 'transaction_id')
            ->where('transaction_type', FixedAssetApproval::TYPE_MAINTENANCE)
            ->orderBy('created_at');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /** Total actual cost recorded so far (base currency). */
    public function totalCost(): string
    {
        $total = '0';
        foreach ($this->costs as $cost) {
            $total = bcadd($total, (string) $cost->amount, 4);
        }

        return $total;
    }
}
