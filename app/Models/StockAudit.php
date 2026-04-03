<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockAudit extends Model
{
    protected $fillable = [
        'created_by',
        'period_type',
        'period_start',
        'period_end',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(StockAuditLine::class, 'stock_audit_id');
    }
}
