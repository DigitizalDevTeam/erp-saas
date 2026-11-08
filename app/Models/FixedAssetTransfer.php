<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixed Assets Phase 2 — transfer event (plan §5, spec §12).
 *
 * One row per transfer: request → manager approval → handover
 * → destination receipt. The previous location / custodian /
 * cost centre are stored on the row, and the asset master is
 * updated only on receipt — a transfer in flight never moves
 * the asset. The depreciation schedule is untouched by a
 * transfer (a transfer is not a disposal and never restarts
 * depreciation) and no journal is posted (plan §7 has no
 * transfer posting).
 */
class FixedAssetTransfer extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_HANDED_OVER = 'handed_over';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_HANDED_OVER,
        self::STATUS_RECEIVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    /** Statuses that still hold the asset (block a new one). */
    public const ACTIVE_STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_HANDED_OVER,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_RECEIVED,
        self::STATUS_REJECTED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'created_by',
        'asset_id',
        'from_location_id',
        'to_location_id',
        'from_custodian_id',
        'to_custodian_id',
        'from_cost_centre_id',
        'to_cost_centre_id',
        'status',
        'previous_status',
        'requested_by',
        'approved_by',
        'handed_over_by',
        'received_by',
        'requested_at',
        'approved_at',
        'handed_over_at',
        'received_at',
        'effective_date',
        'reason',
        'notes',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'handed_over_at' => 'datetime',
        'received_at' => 'datetime',
        'effective_date' => 'date',
    ];

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function fromLocation()
    {
        return $this->belongsTo(FixedAssetLocation::class, 'from_location_id');
    }

    public function toLocation()
    {
        return $this->belongsTo(FixedAssetLocation::class, 'to_location_id');
    }

    public function fromCustodian()
    {
        return $this->belongsTo(Employee::class, 'from_custodian_id');
    }

    public function toCustodian()
    {
        return $this->belongsTo(Employee::class, 'to_custodian_id');
    }

    public function fromCostCentre()
    {
        return $this->belongsTo(CostCentre::class, 'from_cost_centre_id');
    }

    public function toCostCentre()
    {
        return $this->belongsTo(CostCentre::class, 'to_cost_centre_id');
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopeForCompany($query, int $creatorId)
    {
        return $query->where('created_by', $creatorId);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }
}
