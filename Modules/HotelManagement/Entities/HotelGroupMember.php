<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelGroupMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_group_id',
        'hotel_guest_id',
        'role',
        'sort_order',
    ];

    public function group()
    {
        return $this->belongsTo(HotelGroup::class, 'hotel_group_id');
    }

    public function guest()
    {
        return $this->belongsTo(HotelGuest::class, 'hotel_guest_id');
    }
}
