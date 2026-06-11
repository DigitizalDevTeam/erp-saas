<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelRoomMinibarRestock extends Model
{
    use HasFactory;

    const STATUS_PENDING = 'pending';

    const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'hotel_room_id',
        'hotel_stay_id',
        'status',
        'completed_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function room()
    {
        return $this->belongsTo(HotelRoom::class, 'hotel_room_id');
    }

    public function stay()
    {
        return $this->belongsTo(HotelStay::class, 'hotel_stay_id');
    }

    public function lines()
    {
        return $this->hasMany(HotelRoomMinibarRestockLine::class, 'hotel_room_minibar_restock_id');
    }
}
