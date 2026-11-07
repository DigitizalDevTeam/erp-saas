<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KitchenRequisition extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_ISSUED = 'issued';
    public const STATUS_PARTIALLY_ISSUED = 'partially_issued';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_REQUESTED,
        self::STATUS_APPROVED,
        self::STATUS_ISSUED,
        self::STATUS_PARTIALLY_ISSUED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'requisition_number',
        'kitchen_production_plan_id',
        'kitchen_production_order_id',
        'source_warehouse_id',
        'kitchen_warehouse_id',
        'branch_id',
        'status',
        'requested_by',
        'approved_by',
        'approved_at',
        'issued_by',
        'issued_at',
        'rejected_by',
        'rejected_at',
        'rejection_note',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'issued_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(KitchenRequisitionLine::class, 'kitchen_requisition_id', 'id')->orderBy('id');
    }

    public function plan()
    {
        return $this->belongsTo(KitchenProductionPlan::class, 'kitchen_production_plan_id', 'id');
    }

    public function order()
    {
        return $this->belongsTo(KitchenProductionOrder::class, 'kitchen_production_order_id', 'id');
    }

    public function sourceWarehouse()
    {
        return $this->belongsTo(warehouse::class, 'source_warehouse_id', 'id');
    }

    public function kitchenWarehouse()
    {
        return $this->belongsTo(warehouse::class, 'kitchen_warehouse_id', 'id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'id');
    }

    public function isRequested(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isIssued(): bool
    {
        return $this->status === self::STATUS_ISSUED;
    }

    public function isPartiallyIssued(): bool
    {
        return $this->status === self::STATUS_PARTIALLY_ISSUED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /** Quantity still to issue across all lines (shortage not yet moved). */
    public function remainingQuantity(): float
    {
        $remaining = 0.0;
        foreach ($this->lines as $line) {
            $remaining += max(0, (float) $line->shortage_quantity - (float) $line->issued_quantity);
        }

        return round($remaining, 4);
    }
}
