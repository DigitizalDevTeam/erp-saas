<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockIntroduction extends Model
{
    protected $fillable = [
        'intro_no',
        'warehouse_id',
        'intro_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'intro_date' => 'date',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(StockIntroductionLine::class, 'stock_introduction_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(warehouse::class, 'warehouse_id');
    }
}
