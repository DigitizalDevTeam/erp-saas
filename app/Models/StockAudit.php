<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockAudit extends Model
{
    protected $fillable = [
        'created_by',
        'warehouse_id',
        'check_date',
        'period_type',
        'period_start',
        'period_end',
        'notes',
    ];

    protected $casts = [
        'check_date' => 'date',
        'period_start' => 'date',
        'period_end' => 'date',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(StockAuditLine::class, 'stock_audit_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(warehouse::class, 'warehouse_id');
    }
}
