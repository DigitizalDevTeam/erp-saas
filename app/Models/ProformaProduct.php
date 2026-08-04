<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProformaProduct extends Model
{
    protected $fillable = [
        'proforma_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'tax',
        'discount',
        'price',
        'description',
    ];

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function product()
    {
        return $this->hasOne(ProductService::class, 'id', 'product_id')->first();
    }
}
