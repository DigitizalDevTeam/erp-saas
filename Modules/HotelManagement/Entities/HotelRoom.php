<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelRoom extends Model
{
    use HasFactory;

    const STATUS_AVAILABLE = 'available';
    const STATUS_OCCUPIED = 'occupied';
    const STATUS_DIRTY = 'dirty';
    const STATUS_CLEANING = 'cleaning';
    const STATUS_MAINTENANCE = 'maintenance';
    const STATUS_BLOCKED = 'blocked';

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_AVAILABLE => __('Available'),
            self::STATUS_OCCUPIED => __('Occupied'),
            self::STATUS_DIRTY => __('Dirty'),
            self::STATUS_CLEANING => __('Cleaning'),
            self::STATUS_MAINTENANCE => __('Maintenance'),
            self::STATUS_BLOCKED => __('Blocked'),
        ];
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }

    protected $fillable = [
        'hotel_room_type_id',
        'hotel_floor_id',
        'branch_id',
        'room_no',
        'override_price',
        'monthly_override_price',
        'status',
        'notes',
        'created_by',
    ];

    public function roomType()
    {
        return $this->belongsTo(HotelRoomType::class, 'hotel_room_type_id');
    }

    public function floor()
    {
        return $this->belongsTo(HotelFloor::class, 'hotel_floor_id');
    }
}
