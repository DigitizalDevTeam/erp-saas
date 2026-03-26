<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductIdentifier extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'purchase_id',
        'purchase_product_id',
        'pos_id',
        'pos_product_id',
        'identifier_type',
        'identifier_value',
        'status',
        'customer_id',
        'sold_at',
        'warranty_end_date',
        'created_by',
    ];

    protected $casts = [
        'sold_at' => 'datetime',
        'warranty_end_date' => 'date',
    ];

    public function product()
    {
        return $this->belongsTo(ProductService::class, 'product_id', 'id');
    }
}
