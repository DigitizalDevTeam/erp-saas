<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductExpiryLot extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'purchase_id',
        'purchase_product_id',
        'source',
        'expiry_date',
        'quantity_in',
        'quantity_remaining',
        'created_by',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'quantity_in' => 'decimal:4',
        'quantity_remaining' => 'decimal:4',
    ];
}
