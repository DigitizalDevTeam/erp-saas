<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductOptionItem extends Model
{
    protected $fillable = [
        'parent_product_id',
        'option_product_id',
        'sort_order',
        'created_by',
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
