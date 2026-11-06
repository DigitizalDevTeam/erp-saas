<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KitchenUnitConversion extends Model
{
    protected $fillable = [
        'from_unit_id',
        'to_unit_id',
        'factor',
        'product_id',
        'created_by',
    ];

    protected $casts = [
        'factor' => 'decimal:6',
    ];

    public function fromUnit()
    {
        return $this->belongsTo(ProductServiceUnit::class, 'from_unit_id', 'id');
    }

    public function toUnit()
    {
        return $this->belongsTo(ProductServiceUnit::class, 'to_unit_id', 'id');
    }

    public function product()
    {
        return $this->belongsTo(ProductService::class, 'product_id', 'id');
    }
}
