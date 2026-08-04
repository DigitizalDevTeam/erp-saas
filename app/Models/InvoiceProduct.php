<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceProduct extends Model
{
    protected $fillable = [
        'product_id',
        'product_variant_id',
        'invoice_id',
        'quantity',
        'tax',
        'discount',
        'price',
        'description',
        'identifiers',
        'measurement_json',
        'selected_options_json',
    ];

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function product(){
        return $this->hasOne('App\Models\ProductService', 'id', 'product_id');
    }    

  
}
