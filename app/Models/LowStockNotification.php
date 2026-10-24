<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LowStockNotification extends Model
{
    protected $fillable = [
        'company_id',
        'product_id',
        'warehouse_id',
        'quantity',
        'low_stock_threshold',
        'resolved_at',
    ];

    protected $casts = [
        'quantity' => 'float',
        'resolved_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(ProductService::class, 'product_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(warehouse::class, 'warehouse_id');
    }

    public function reads()
    {
        return $this->hasMany(LowStockNotificationRead::class, 'notification_id');
    }

    public function isReadBy(int $userId): bool
    {
        return $this->reads()->where('user_id', $userId)->exists();
    }
}
