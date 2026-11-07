<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KitchenRequisitionLine extends Model
{
    protected $fillable = [
        'kitchen_requisition_id',
        'ingredient_product_id',
        'required_quantity',
        'available_quantity',
        'shortage_quantity',
        'issued_quantity',
        'unit_id',
        'unit_cost',
        'created_by',
    ];

    protected $casts = [
        'required_quantity' => 'decimal:4',
        'available_quantity' => 'decimal:4',
        'shortage_quantity' => 'decimal:4',
        'issued_quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
    ];

    public function requisition()
    {
        return $this->belongsTo(KitchenRequisition::class, 'kitchen_requisition_id', 'id');
    }

    public function ingredient()
    {
        return $this->belongsTo(ProductService::class, 'ingredient_product_id', 'id');
    }

    public function unit()
    {
        return $this->belongsTo(ProductServiceUnit::class, 'unit_id', 'id');
    }

    /** Still to issue on this line (shortage minus what already moved). */
    public function remainingQuantity(): float
    {
        return max(0, round((float) $this->shortage_quantity - (float) $this->issued_quantity, 4));
    }
}
