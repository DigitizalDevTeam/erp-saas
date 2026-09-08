<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelRatePlan extends Model
{
    use HasFactory;

    public const TYPE_PERIOD = 'period';

    public const TYPE_PROMOTIONAL = 'promotional';

    protected $fillable = [
        'hotel_room_type_id',
        'name',
        'plan_type',
        'rate',
        'weekend_rate',
        'monthly_rate',
        'billing_mode',
        'priority',
        'valid_from',
        'valid_to',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'rate' => 'float',
        'weekend_rate' => 'float',
        'monthly_rate' => 'float',
        'is_active' => 'boolean',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
    ];

    public function roomType()
    {
        return $this->belongsTo(HotelRoomType::class, 'hotel_room_type_id');
    }

    public function isPromotional(): bool
    {
        return ($this->plan_type ?? self::TYPE_PERIOD) === self::TYPE_PROMOTIONAL;
    }

    public function planTypeLabel(): string
    {
        return $this->isPromotional() ? __('Promotional') : __('Period');
    }
}
