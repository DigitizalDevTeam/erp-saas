<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductRecipeAlternative extends Model
{
    protected $fillable = [
        'product_recipe_id',
        'alternative_product_id',
        'sort_order',
        'created_by',
    ];

    public function recipe()
    {
        return $this->belongsTo(ProductRecipe::class, 'product_recipe_id', 'id');
    }

    public function alternativeProduct()
    {
        return $this->belongsTo(ProductService::class, 'alternative_product_id', 'id');
    }
}
