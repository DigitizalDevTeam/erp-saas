<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KitchenCostSnapshot extends Model
{
    // Written when a recipe version is approved: the expected cost at
    // approval time, with the ingredient prices of that moment.
    public const SOURCE_RECIPE_APPROVED = 'recipe_approved';
    // Written when a production order completes: the actual cost of
    // that order, snapshotted so later price changes never rewrite it.
    public const SOURCE_ORDER_COMPLETED = 'order_completed';

    public const SOURCES = [
        self::SOURCE_RECIPE_APPROVED,
        self::SOURCE_ORDER_COMPLETED,
    ];

    protected $fillable = [
        'kitchen_recipe_id',
        'version',
        'kitchen_production_order_id',
        'source',
        'recipe_cost',
        'cost_per_portion',
        'selling_price_ex_vat',
        'food_cost_percent',
        'band',
        'snapshot_date',
        'created_by',
    ];

    protected $casts = [
        'recipe_cost' => 'decimal:2',
        'cost_per_portion' => 'decimal:2',
        'selling_price_ex_vat' => 'decimal:2',
        'food_cost_percent' => 'decimal:2',
        'snapshot_date' => 'date',
    ];

    public function recipe()
    {
        return $this->belongsTo(KitchenRecipe::class, 'kitchen_recipe_id', 'id');
    }

    public function order()
    {
        return $this->belongsTo(KitchenProductionOrder::class, 'kitchen_production_order_id', 'id');
    }
}
