<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KitchenProductionOrderLotUsage extends Model
{
    protected $fillable = [
        'kitchen_production_order_line_id',
        'product_expiry_lot_id',
        'quantity',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
    ];

    public function line()
    {
        return $this->belongsTo(KitchenProductionOrderLine::class, 'kitchen_production_order_line_id', 'id');
    }

    public function lot()
    {
        return $this->belongsTo(ProductExpiryLot::class, 'product_expiry_lot_id', 'id');
    }
}
