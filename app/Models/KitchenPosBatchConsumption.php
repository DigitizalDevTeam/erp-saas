<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Kitchen Production Phase 2: one row per prepared batch a POS line
 * consumed at send time. Used to put the prepared food back on a
 * cancellation before preparation, and for the per-batch reconciliation
 * (produced - sold - lost = remaining).
 */
class KitchenPosBatchConsumption extends Model
{
    protected $fillable = [
        'pos_product_id',
        'kitchen_production_batch_id',
        'product_id',
        'quantity',
        'quantity_restored',
        'warehouse_id',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'quantity_restored' => 'decimal:4',
    ];

    public function line()
    {
        return $this->belongsTo(PosProduct::class, 'pos_product_id', 'id');
    }

    public function batch()
    {
        return $this->belongsTo(KitchenProductionBatch::class, 'kitchen_production_batch_id', 'id');
    }

    public function product()
    {
        return $this->belongsTo(ProductService::class, 'product_id', 'id');
    }

    /** Quantity of this row not yet put back into the batch. */
    public function outstanding(): float
    {
        return max(0, round((float) ($this->quantity ?? 0) - (float) ($this->quantity_restored ?? 0), 4));
    }
}
