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
        'product_variant_id',
        'pos_id',
        'warehouse_id',
        'quantity',
        'tax',
        'discount',
        'total',
        'price',
        'unit_subtotal',
        'description',
        'identifiers',
        'measurement_json',
        'selected_options_json',
        'status',
        'sent_at',
        'stock_deducted_at',
        'stock_restored_at',
        'negative_stock_authorized_by',
        'prep_minutes',
        'ready_at',
        'print_zone',
        'pos_printer_id',
        'voided_at',
        'void_reason',
        'voided_by',
        'removed_from_cart_at',
        'removed_from_cart_by',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'stock_deducted_at' => 'datetime',
        'stock_restored_at' => 'datetime',
        'ready_at' => 'datetime',
        'voided_at' => 'datetime',
        'removed_from_cart_at' => 'datetime',
        'prep_minutes' => 'integer',
        'negative_stock_authorized_by' => 'integer',
    ];

    public function dueAt(): ?\Carbon\Carbon
    {
        if (! $this->sent_at || ! $this->prep_minutes || (int) $this->prep_minutes <= 0) {
            return null;
        }

        return $this->sent_at->copy()->addMinutes((int) $this->prep_minutes);
    }

    public function isPrepOverdue(): bool
    {
        if ($this->ready_at || in_array($this->status, [self::STATUS_READY, self::STATUS_SERVED, self::STATUS_CANCELLED], true)) {
            return false;
        }
        $due = $this->dueAt();

        return $due ? now()->greaterThan($due) : false;
    }

    public function prepElapsedMinutes(): ?int
    {
        if (! $this->sent_at) {
            return null;
        }
        $end = $this->ready_at ?? now();

        return (int) $this->sent_at->diffInMinutes($end);
    }

    public function prepRemainingMinutes(): ?int
    {
        $due = $this->dueAt();
        if (! $due || $this->ready_at) {
            return null;
        }

        return (int) now()->diffInMinutes($due, false);
    }

    public function pos()
    {
        return $this->belongsTo(Pos::class, 'pos_id', 'id');
    }

    public function product()
    {
        return $this->belongsTo(ProductService::class, 'product_id', 'id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function posPrinter()
    {
        return $this->belongsTo(\App\Models\PosPrinter::class, 'pos_printer_id', 'id');
    }

    public function voidedByUser()
    {
        return $this->belongsTo(User::class, 'voided_by', 'id');
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
