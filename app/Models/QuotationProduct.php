<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuotationProduct extends Model
{
    protected $fillable = [
        'purchase_id',
        'product_id',
        'quantity',
        'tax',
        'discount',
        'price',
        'selected_options_json',
    ];

    public function product(){
        return $this->hasOne('App\Models\ProductService', 'id', 'product_id')->first();
    }
}
