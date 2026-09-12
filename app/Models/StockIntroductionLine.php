<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockIntroductionLine extends Model
{
    protected $fillable = [
        'stock_introduction_id',
        'product_id',
        'quantity',
        'row_no',
        'shelf_no',
        'batch_no',
        'expiry_date',
        'mfg_date',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'mfg_date' => 'date',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductService::class, 'product_id');
    }

    public function introduction(): BelongsTo
    {
        return $this->belongsTo(StockIntroduction::class, 'stock_introduction_id');
    }
}
