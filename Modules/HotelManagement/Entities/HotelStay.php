<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelStay extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotel_reservation_id',
        'hotel_guest_id',
        'hotel_room_id',
        'check_in_at',
        'expected_check_out_at',
        'checked_out_at',
        'status',
        'extra_charge',
        'notes',
        'minibar_verified_at',
        'created_by',
    ];

    protected $casts = [
        'minibar_verified_at' => 'datetime',
    ];

    public function reservation()
    {
        return $this->belongsTo(HotelReservation::class, 'hotel_reservation_id');
    }

    public function folio()
    {
        return $this->hasOne(HotelFolio::class);
    }
}
