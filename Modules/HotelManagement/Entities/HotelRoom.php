<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelRoom extends Model
{
    use HasFactory;

    const STATUS_AVAILABLE = 'available';
    const STATUS_OCCUPIED = 'occupied';
    const STATUS_CLEANING = 'cleaning';
    const STATUS_MAINTENANCE = 'maintenance';

    protected $fillable = [
        'hotel_room_type_id',
        'branch_id',
        'room_no',
        'override_price',
        'status',
        'notes',
        'created_by',
    ];

    public function roomType()
    {
        return $this->belongsTo(HotelRoomType::class, 'hotel_room_type_id');
    }
}
