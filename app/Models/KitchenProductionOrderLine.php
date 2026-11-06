<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KitchenProductionOrderLine extends Model
{
    protected $fillable = [
        'kitchen_production_order_id',
        'ingredient_product_id',
        'planned_quantity',
        'planned_unit_id',
        'planned_unit_cost',
        'planned_cost',
        'actual_quantity',
        'actual_unit_cost',
        'actual_cost',
        'loss_percent',
        'created_by',
    ];

    protected $casts = [
        'planned_quantity' => 'decimal:4',
        'planned_unit_cost' => 'decimal:4',
        'planned_cost' => 'decimal:2',
        'actual_quantity' => 'decimal:4',
        'actual_unit_cost' => 'decimal:4',
        'actual_cost' => 'decimal:2',
        'loss_percent' => 'decimal:2',
    ];

    public function order()
    {
        return $this->belongsTo(KitchenProductionOrder::class, 'kitchen_production_order_id', 'id');
    }

    public function ingredient()
    {
        return $this->belongsTo(ProductService::class, 'ingredient_product_id', 'id');
    }

    public function plannedUnit()
    {
        return $this->belongsTo(ProductServiceUnit::class, 'planned_unit_id', 'id');
    }

    public function lotUsages()
    {
        return $this->hasMany(KitchenProductionOrderLotUsage::class, 'kitchen_production_order_line_id', 'id');
    }

    /** Actual minus planned, in stock units (null until completion). */
    public function difference(): ?float
    {
        if ($this->actual_quantity === null) {
            return null;
        }

        return round((float) $this->actual_quantity - (float) $this->planned_quantity, 4);
    }
}
