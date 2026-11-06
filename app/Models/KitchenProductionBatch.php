<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class KitchenProductionBatch extends Model
{
    protected $fillable = [
        'batch_number',
        'kitchen_production_order_id',
        'product_id',
        'quantity_produced',
        'quantity_remaining',
        'unit_id',
        'warehouse_id',
        'expiry_date',
        'unit_cost',
        'total_cost',
        'created_by',
    ];

    protected $casts = [
        'quantity_produced' => 'decimal:4',
        'quantity_remaining' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:2',
        'expiry_date' => 'date',
    ];

    public function order()
    {
        return $this->belongsTo(KitchenProductionOrder::class, 'kitchen_production_order_id', 'id');
    }

    public function product()
    {
        return $this->belongsTo(ProductService::class, 'product_id', 'id');
    }

    public function warehouse()
    {
        return $this->belongsTo(warehouse::class, 'warehouse_id', 'id');
    }

    public function unit()
    {
        return $this->belongsTo(ProductServiceUnit::class, 'unit_id', 'id');
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    /**
     * Consume part of this batch (Phase 2: a dish sale deducts the
     * prepared batch only). Throws when the batch does not hold enough.
     */
    public function consume(float $quantity): static
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException(__('Quantity to consume must be greater than zero.'));
        }

        if ((float) $this->quantity_remaining < $quantity) {
            throw new InvalidArgumentException(__('Batch :batch holds only :remaining, cannot consume :quantity.', [
                'batch' => $this->batch_number,
                'remaining' => (float) $this->quantity_remaining,
                'quantity' => $quantity,
            ]));
        }

        $this->quantity_remaining = round((float) $this->quantity_remaining - $quantity, 4);
        $this->save();

        return $this->fresh();
    }
}
