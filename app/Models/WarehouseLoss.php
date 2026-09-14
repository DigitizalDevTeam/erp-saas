<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseLoss extends Model
{
    protected $fillable = [
        'write_off_no',
        'warehouse_id',
        'product_id',
        'quantity',
        'unit_cost',
        'cost_of_goods',
        'date',
        'reason',
        'issuer_name',
        'authorized_by',
        'witness_name',
        'delivery_note_required',
        'delivery_destination',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'quantity' => 'float',
        'unit_cost' => 'float',
        'cost_of_goods' => 'float',
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

