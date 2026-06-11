<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelRoomMinibarRestockLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_room_minibar_restock_id',
        'hotel_minibar_item_id',
        'expected_qty',
        'created_by',
    ];

    public function restock()
    {
        return $this->belongsTo(HotelRoomMinibarRestock::class, 'hotel_room_minibar_restock_id');
    }

    public function minibarItem()
    {
        return $this->belongsTo(HotelMinibarItem::class, 'hotel_minibar_item_id');
    }
}
