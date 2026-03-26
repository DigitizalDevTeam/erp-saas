<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductKitItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'kit_product_id',
        'component_product_id',
        'quantity',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
    ];

    public function kit()
    {
        return $this->belongsTo(ProductService::class, 'kit_product_id', 'id');
    }

    public function component()
    {
        return $this->belongsTo(ProductService::class, 'component_product_id', 'id');
    }
}
