<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KitchenProductionOrder extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_IN_PREPARATION = 'in_preparation';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_VALIDATED = 'validated';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_APPROVED,
        self::STATUS_IN_PREPARATION,
        self::STATUS_COMPLETED,
        self::STATUS_VALIDATED,
    ];

    public const SECTION_HOT_KITCHEN = 'hot_kitchen';
    public const SECTION_PASTRY = 'pastry';
    public const SECTION_COLD_KITCHEN = 'cold_kitchen';

    public const SECTIONS = [
        self::SECTION_HOT_KITCHEN,
        self::SECTION_PASTRY,
        self::SECTION_COLD_KITCHEN,
    ];

    protected $fillable = [
        'order_number',
        'kitchen_recipe_id',
        'recipe_version',
        'production_date',
        'section',
        'branch_id',
        'warehouse_id',
        'planned_quantity',
        'planned_unit_id',
        'actual_yield_quantity',
        'actual_yield_unit_id',
        'status',
        'responsible_user_id',
        'notes',
        'planned_cost',
        'actual_cost',
        'cost_per_portion',
        'food_cost_percent',
        'food_cost_band',
        'batch_expiry_date',
        'submitted_by',
        'approved_by',
        'approved_at',
        'started_by',
        'started_at',
        'completed_by',
        'completed_at',
        'validated_by',
        'validated_at',
        'stock_deducted_at',
        'stock_received_at',
        'cost_centre_id',
        'created_by',
    ];

    protected $casts = [
        'production_date' => 'date',
        'planned_quantity' => 'decimal:4',
        'actual_yield_quantity' => 'decimal:4',
        'planned_cost' => 'decimal:2',
        'actual_cost' => 'decimal:2',
        'cost_per_portion' => 'decimal:2',
        'food_cost_percent' => 'decimal:2',
        'batch_expiry_date' => 'date',
        'approved_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'validated_at' => 'datetime',
        'stock_deducted_at' => 'datetime',
        'stock_received_at' => 'datetime',
    ];

    public function recipe()
    {
        return $this->belongsTo(KitchenRecipe::class, 'kitchen_recipe_id', 'id');
    }

    public function lines()
    {
        return $this->hasMany(KitchenProductionOrderLine::class, 'kitchen_production_order_id', 'id')->orderBy('id');
    }

    public function batch()
    {
        return $this->hasOne(KitchenProductionBatch::class, 'kitchen_production_order_id', 'id');
    }

    public function warehouse()
    {
        return $this->belongsTo(warehouse::class, 'warehouse_id', 'id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id', 'id');
    }

    public function plannedUnit()
    {
        return $this->belongsTo(ProductServiceUnit::class, 'planned_unit_id', 'id');
    }

    public function actualYieldUnit()
    {
        return $this->belongsTo(ProductServiceUnit::class, 'actual_yield_unit_id', 'id');
    }

    public function responsibleUser()
    {
        return $this->belongsTo(User::class, 'responsible_user_id', 'id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isInPreparation(): bool
    {
        return $this->status === self::STATUS_IN_PREPARATION;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isValidated(): bool
    {
        return $this->status === self::STATUS_VALIDATED;
    }
}
