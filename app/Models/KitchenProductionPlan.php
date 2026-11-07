<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KitchenProductionPlan extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_IN_PRODUCTION = 'in_production';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING_APPROVAL,
        self::STATUS_APPROVED,
        self::STATUS_IN_PRODUCTION,
        self::STATUS_COMPLETED,
        self::STATUS_REJECTED,
    ];

    /** Approval chain steps (plan §6, decision D5). */
    public const STEP_HEAD_CHEF = 'head_chef';
    public const STEP_FB_MANAGER = 'fb_manager';
    public const STEP_GENERAL_MANAGER = 'general_manager';

    public const APPROVAL_STEPS = [
        self::STEP_HEAD_CHEF,
        self::STEP_FB_MANAGER,
        self::STEP_GENERAL_MANAGER,
    ];

    public const MEAL_BREAKFAST = 'breakfast';
    public const MEAL_LUNCH = 'lunch';
    public const MEAL_DINNER = 'dinner';

    public const MEAL_SERVICES = [
        self::MEAL_BREAKFAST,
        self::MEAL_LUNCH,
        self::MEAL_DINNER,
    ];

    /** Line sources (plan §5): what a planned quantity is based on. */
    public const SOURCE_OCCUPANCY = 'occupancy';
    public const SOURCE_SALES_HISTORY = 'sales_history';
    public const SOURCE_EVENT = 'event';
    public const SOURCE_MINIMUM_STOCK = 'minimum_stock';
    public const SOURCE_SPECIAL_ORDER = 'special_order';
    public const SOURCE_MANUAL = 'manual';

    public const SOURCES = [
        self::SOURCE_OCCUPANCY,
        self::SOURCE_SALES_HISTORY,
        self::SOURCE_EVENT,
        self::SOURCE_MINIMUM_STOCK,
        self::SOURCE_SPECIAL_ORDER,
        self::SOURCE_MANUAL,
    ];

    protected $fillable = [
        'plan_number',
        'plan_date',
        'meal_service',
        'branch_id',
        'warehouse_id',
        'section',
        'status',
        'approval_step',
        'occupancy_snapshot',
        'notes',
        'submitted_by',
        'head_chef_approved_by',
        'head_chef_approved_at',
        'fb_manager_approved_by',
        'fb_manager_approved_at',
        'gm_approved_by',
        'gm_approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_note',
        'created_by',
    ];

    protected $casts = [
        'plan_date' => 'date',
        'occupancy_snapshot' => 'array',
        'head_chef_approved_at' => 'datetime',
        'fb_manager_approved_at' => 'datetime',
        'gm_approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(KitchenProductionPlanLine::class, 'kitchen_production_plan_id', 'id')->orderBy('id');
    }

    public function orders()
    {
        return $this->hasMany(KitchenProductionOrder::class, 'kitchen_production_plan_id', 'id')->orderBy('id');
    }

    public function warehouse()
    {
        return $this->belongsTo(warehouse::class, 'warehouse_id', 'id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'id');
    }

    public function requisitions()
    {
        return $this->hasMany(KitchenRequisition::class, 'kitchen_production_plan_id', 'id')->orderBy('id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPendingApproval(): bool
    {
        return $this->status === self::STATUS_PENDING_APPROVAL;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isInProduction(): bool
    {
        return $this->status === self::STATUS_IN_PRODUCTION;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /** Covers for this plan's meal service, from the stored snapshot. */
    public function occupancyCovers(): ?int
    {
        $snapshot = $this->occupancy_snapshot;
        if (! is_array($snapshot)) {
            return null;
        }

        return match ($this->meal_service) {
            self::MEAL_BREAKFAST => $snapshot['breakfast_covers'] ?? null,
            self::MEAL_LUNCH => $snapshot['lunch_covers'] ?? null,
            self::MEAL_DINNER => $snapshot['dinner_covers'] ?? null,
            default => $snapshot['total_guests'] ?? null,
        };
    }
}
