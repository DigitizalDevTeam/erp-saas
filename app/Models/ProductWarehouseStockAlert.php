<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductWarehouseStockAlert extends Model
{
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'low_stock_threshold',
        'max_stock_threshold',
        'created_by',
    ];

    protected $casts = [
        'low_stock_threshold' => 'integer',
        'max_stock_threshold' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(ProductService::class, 'product_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(warehouse::class, 'warehouse_id');
    }

    /**
     * Effective low threshold for one product in one warehouse:
     * per-warehouse value wins, then the product's own value, then null
     * (caller applies the system default).
     */
    public static function effectiveLow(?int $alertLow, ?int $productLow): ?int
    {
        return $alertLow ?? $productLow;
    }

    public static function effectiveMax(?int $alertMax, ?int $productMax): ?int
    {
        return $alertMax ?? $productMax;
    }
}
