<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KitchenBuffetEventLine extends Model
{
    protected $fillable = [
        'kitchen_buffet_event_id',
        'product_id',
        'kitchen_recipe_id',
        'unit_id',
        'per_cover_quantity',
        'planned_quantity',
        'prepared_quantity',
        'used_quantity',
        'leftover_quantity',
        'waste_quantity',
        'unit_cost',
        'total_cost',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'per_cover_quantity' => 'decimal:4',
        'planned_quantity' => 'decimal:4',
        'prepared_quantity' => 'decimal:4',
        'used_quantity' => 'decimal:4',
        'leftover_quantity' => 'decimal:4',
        'waste_quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:2',
    ];

    public function event()
    {
        return $this->belongsTo(KitchenBuffetEvent::class, 'kitchen_buffet_event_id', 'id');
    }

    public function product()
    {
        return $this->belongsTo(ProductService::class, 'product_id', 'id');
    }

    public function recipe()
    {
        return $this->belongsTo(KitchenRecipe::class, 'kitchen_recipe_id', 'id');
    }

    public function unit()
    {
        return $this->belongsTo(ProductServiceUnit::class, 'unit_id', 'id');
    }

    public function losses()
    {
        return $this->hasMany(KitchenLoss::class, 'kitchen_buffet_event_line_id', 'id')->orderBy('id');
    }

    /** True when this line's quantities reconcile (plan §9). */
    public function isBalanced(): bool
    {
        $difference = (float) $this->prepared_quantity
            - (float) $this->used_quantity
            - (float) $this->leftover_quantity
            - (float) $this->waste_quantity;

        return abs(round($difference, 4)) < 0.0001;
    }
}
