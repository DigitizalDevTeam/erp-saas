<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductRecipe extends Model
{
    protected $fillable = [
        'product_id',
        'ingredient_product_id',
        'quantity',
        'unit',
        'unit_id',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
    ];

    public function product()
    {
        return $this->belongsTo(ProductService::class, 'product_id', 'id');
    }

    public function ingredientProduct()
    {
        return $this->belongsTo(ProductService::class, 'ingredient_product_id', 'id');
    }

    public function unit()
    {
        return $this->belongsTo(ProductServiceUnit::class, 'unit_id', 'id');
    }
}
