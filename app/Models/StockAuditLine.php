<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAuditLine extends Model
{
    protected $fillable = [
        'stock_audit_id',
        'product_id',
        'product_name',
        'sku',
        'category_id',
        'row_no',
        'shelf_no',
        'system_quantity',
        'physical_quantity',
        'difference',
    ];

    protected $casts = [
        'system_quantity' => 'decimal:4',
        'physical_quantity' => 'decimal:4',
        'difference' => 'decimal:4',
    ];

    public function audit(): BelongsTo
    {
        return $this->belongsTo(StockAudit::class, 'stock_audit_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductService::class, 'product_id');
    }
}
