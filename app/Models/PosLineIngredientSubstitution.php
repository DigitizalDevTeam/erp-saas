<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosLineIngredientSubstitution extends Model
{
    protected $fillable = [
        'pos_product_id',
        'product_recipe_id',
        'main_ingredient_id',
        'alternative_id',
        'quantity',
        'warehouse_id',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
    ];

    public function line()
    {
        return $this->belongsTo(PosProduct::class, 'pos_product_id', 'id');
    }

    public function recipe()
    {
        return $this->belongsTo(ProductRecipe::class, 'product_recipe_id', 'id');
    }

    public function mainProduct()
    {
        return $this->belongsTo(ProductService::class, 'main_ingredient_id', 'id');
    }

    public function alternativeProduct()
    {
        return $this->belongsTo(ProductService::class, 'alternative_id', 'id');
    }
}
