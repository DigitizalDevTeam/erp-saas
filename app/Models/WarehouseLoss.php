<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseLoss extends Model
{
    protected $fillable = [
        'warehouse_id',
        'product_id',
        'quantity',
        'date',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function warehouse()
    {
        return $this->belongsTo(warehouse::class, 'warehouse_id');
    }

    public function product()
    {
        return $this->belongsTo(ProductService::class, 'product_id');
    }
}

