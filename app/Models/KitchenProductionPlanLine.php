<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KitchenProductionPlanLine extends Model
{
    protected $fillable = [
        'kitchen_production_plan_id',
        'kitchen_recipe_id',
        'recipe_version',
        'planned_quantity',
        'planned_unit_id',
        'source',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'planned_quantity' => 'decimal:4',
    ];

    public function plan()
    {
        return $this->belongsTo(KitchenProductionPlan::class, 'kitchen_production_plan_id', 'id');
    }

    public function recipe()
    {
        return $this->belongsTo(KitchenRecipe::class, 'kitchen_recipe_id', 'id');
    }

    public function plannedUnit()
    {
        return $this->belongsTo(ProductServiceUnit::class, 'planned_unit_id', 'id');
    }
}
