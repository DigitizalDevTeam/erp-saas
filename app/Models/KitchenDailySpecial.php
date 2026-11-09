<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KitchenDailySpecial extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
    ];

    protected $fillable = [
        'special_date',
        'product_id',
        'kitchen_recipe_id',
        'source_product_id',
        'quantity_planned',
        'selling_price',
        'status',
        'is_on_the_spot',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'special_date' => 'date',
        'quantity_planned' => 'decimal:4',
        'selling_price' => 'decimal:4',
        'is_on_the_spot' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(ProductService::class, 'product_id', 'id');
    }

    public function recipe()
    {
        return $this->belongsTo(KitchenRecipe::class, 'kitchen_recipe_id', 'id');
    }

    public function sourceProduct()
    {
        return $this->belongsTo(ProductService::class, 'source_product_id', 'id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /** A special is sellable only on its own date, while active. */
    public function isForDate(string $date): bool
    {
        return $this->isActive()
            && $this->special_date !== null
            && $this->special_date->format('Y-m-d') === $date;
    }
}
