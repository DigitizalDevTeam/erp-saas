<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fixed Assets Phase 2 — assignment event (plan §5, spec §11).
 *
 * One row per assignment, append-only history: a new
 * assignment is a new row, the old row keeps its full
 * lifecycle (requested → approved → handed over → accepted /
 * refused / returned / cancelled). The asset is "assigned"
 * only when the custodian accepts; a refusal returns it to
 * available, recorded on this row.
 *
 * Effective-dated cost-centre assignment (plan §12c):
 * effective_from is the acceptance date, effective_to the
 * return date. One active assignment per asset — enforced in
 * the service layer.
 */
class FixedAssetAssignment extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_HANDED_OVER = 'handed_over';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_HANDED_OVER,
        self::STATUS_ACCEPTED,
        self::STATUS_REFUSED,
        self::STATUS_RETURNED,
        self::STATUS_CANCELLED,
    ];

    /** Statuses that still hold the asset (block a new one). */
    public const ACTIVE_STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_HANDED_OVER,
        self::STATUS_ACCEPTED,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_REFUSED,
        self::STATUS_RETURNED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'created_by',
        'asset_id',
        'custodian_id',
        'location_id',
        'cost_centre_id',
        'previous_custodian_id',
        'previous_location_id',
        'previous_cost_centre_id',
        'status',
        'condition',
        'accessories',
        'requested_by',
        'approved_by',
        'handed_over_by',
        'accepted_by',
        'requested_at',
        'approved_at',
        'handed_over_at',
        'accepted_at',
        'refused_at',
        'returned_at',
        'effective_from',
        'effective_to',
        'refusal_reason',
        'return_reason',
        'notes',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'handed_over_at' => 'datetime',
        'accepted_at' => 'datetime',
        'refused_at' => 'datetime',
        'returned_at' => 'datetime',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'asset_id');
    }

    public function custodian()
    {
        return $this->belongsTo(Employee::class, 'custodian_id');
    }

    public function previousCustodian()
    {
        return $this->belongsTo(Employee::class, 'previous_custodian_id');
    }

    public function location()
    {
        return $this->belongsTo(FixedAssetLocation::class, 'location_id');
    }

    public function previousLocation()
    {
        return $this->belongsTo(FixedAssetLocation::class, 'previous_location_id');
    }

    public function costCentre()
    {
        return $this->belongsTo(CostCentre::class, 'cost_centre_id');
    }

    public function previousCostCentre()
    {
        return $this->belongsTo(CostCentre::class, 'previous_cost_centre_id');
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
