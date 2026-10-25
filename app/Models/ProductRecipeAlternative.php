<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductRecipeAlternative extends Model
{
    protected $fillable = [
        'product_recipe_id',
        'alternative_product_id',
        'quantity',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
    ];

    /**
     * Quantity of the alternative actually used per unit: its own quantity
     * when set, otherwise the main ingredient's recipe quantity.
     */
    public function effectiveQuantity(float $recipeQuantity): float
    {
        return $this->quantity !== null ? (float) $this->quantity : $recipeQuantity;
    }

    public function recipe()
    {
        return $this->belongsTo(ProductRecipe::class, 'product_recipe_id', 'id');
    }

    public function alternativeProduct()
    {
        return $this->belongsTo(ProductService::class, 'alternative_product_id', 'id');
    }
}
