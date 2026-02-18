<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosProduct extends Model
{
    const STATUS_PENDING = 'pending';
    const STATUS_IN_PREPARATION = 'in_preparation';
    const STATUS_READY = 'ready';
    const STATUS_SERVED = 'served';
    const STATUS_CANCELLED = 'cancelled';

    const PRINT_ZONE_KITCHEN = 'kitchen';
    const PRINT_ZONE_BAR = 'bar';
    const PRINT_ZONE_BOTH = 'both';

    protected $fillable = [
        'product_id',
        'pos_id',
        'quantity',
        'tax',
        'discount',
        'total',
        'price',
        'description',
        'status',
        'sent_at',
        'ready_at',
        'print_zone',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'ready_at' => 'datetime',
    ];

    public function pos()
    {
        return $this->belongsTo(Pos::class, 'pos_id', 'id');
    }

    public function product()
    {
        return $this->belongsTo(ProductService::class, 'product_id', 'id');
    }

    public function getProductNameAttribute(): string
    {
        $p = $this->product;
        return $p ? $p->name : '';
    }

    public function scopeForProductionZone($query, string $zone)
    {
        if ($zone === self::PRINT_ZONE_KITCHEN) {
            return $query->whereIn('print_zone', [self::PRINT_ZONE_KITCHEN, self::PRINT_ZONE_BOTH]);
        }
        if ($zone === self::PRINT_ZONE_BAR) {
            return $query->whereIn('print_zone', [self::PRINT_ZONE_BAR, self::PRINT_ZONE_BOTH]);
        }
        return $query;
    }

    public function scopePendingOrInProgress($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_IN_PREPARATION, self::STATUS_READY]);
    }
}
