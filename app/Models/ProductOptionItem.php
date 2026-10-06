<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductOptionItem extends Model
{
    protected $fillable = [
        'parent_product_id',
        'option_product_id',
        'option_group',
        'is_required',
        'quantity',
        'sort_order',
        'created_by',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'quantity' => 'float',
    ];

    public function parent()
    {
        return $this->belongsTo(ProductService::class, 'parent_product_id', 'id');
    }

    public function optionProduct()
    {
        return $this->belongsTo(ProductService::class, 'option_product_id', 'id');
    }
}
