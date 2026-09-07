<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelRatePlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_room_type_id',
        'name',
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
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function roomType()
    {
        return $this->belongsTo(HotelRoomType::class, 'hotel_room_type_id');
    }
}
