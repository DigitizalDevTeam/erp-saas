<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KitchenCostAlert extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_ACKNOWLEDGED,
    ];

    // A completed order whose ACTUAL food-cost band is bad.
    public const TYPE_BAND_BAD = 'band_bad';
    // A completed order whose ACTUAL food-cost band is very bad.
    public const TYPE_BAND_VERY_BAD = 'band_very_bad';
    // A completed order whose actual band is worse than its planned band.
    public const TYPE_WORSE_THAN_PLANNED = 'worse_than_planned';
    // A dish whose CURRENT expected (recipe) band is bad — raised by
    // the manager's refresh, since a recipe cost moves when ingredient
    // prices move and there is no single event that fires it.
    public const TYPE_DISH_BAND_BAD = 'dish_band_bad';
    // A dish whose CURRENT expected (recipe) band is very bad.
    public const TYPE_DISH_BAND_VERY_BAD = 'dish_band_very_bad';

    public const TYPES = [
        self::TYPE_BAND_BAD,
        self::TYPE_BAND_VERY_BAD,
        self::TYPE_WORSE_THAN_PLANNED,
        self::TYPE_DISH_BAND_BAD,
        self::TYPE_DISH_BAND_VERY_BAD,
    ];

    protected $fillable = [
        'alert_type',
        'product_id',
        'kitchen_recipe_id',
        'kitchen_production_order_id',
        'food_cost_percent',
        'band',
        'planned_food_cost_percent',
        'planned_food_cost_band',
        'status',
        'acknowledged_by',
        'acknowledged_at',
        'note',
        'created_by',
    ];

    protected $casts = [
        'food_cost_percent' => 'decimal:2',
        'planned_food_cost_percent' => 'decimal:2',
        'acknowledged_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(ProductService::class, 'product_id', 'id');
    }

    public function recipe()
    {
        return $this->belongsTo(KitchenRecipe::class, 'kitchen_recipe_id', 'id');
    }

    public function order()
    {
        return $this->belongsTo(KitchenProductionOrder::class, 'kitchen_production_order_id', 'id');
    }

    public function acknowledgedBy()
    {
        return $this->belongsTo(User::class, 'acknowledged_by', 'id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isAcknowledged(): bool
    {
        return $this->status === self::STATUS_ACKNOWLEDGED;
    }
}
