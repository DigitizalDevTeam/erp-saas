<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelReservation extends Model
{
    use HasFactory;

    const STATUS_PENDING = 'pending';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_CHECKED_IN = 'checked_in';
    const STATUS_CHECKED_OUT = 'checked_out';
    const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'reservation_no',
        'hotel_guest_id',
        'hotel_group_id',
        'hotel_room_type_id',
        'hotel_room_id',
        'hotel_rate_plan_id',
        'branch_id',
        'check_in_at',
        'check_out_at',
        'estimated_arrival_time',
        'parking_requested',
        'check_in_date',
        'check_out_date',
        'adults',
        'children',
        'source',
        'status',
        'billing_mode',
        'rate_per_night',
        'discount_amount',
        'discount_reason',
        'guest_email_sent_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'parking_requested' => 'boolean',
        'guest_email_sent_at' => 'datetime',
    ];

    public function guest()
    {
        return $this->belongsTo(HotelGuest::class, 'hotel_guest_id');
    }

    public function group()
    {
        return $this->belongsTo(HotelGroup::class, 'hotel_group_id');
    }

    public function roomType()
    {
        return $this->belongsTo(HotelRoomType::class, 'hotel_room_type_id');
    }

    public function room()
    {
        return $this->belongsTo(HotelRoom::class, 'hotel_room_id');
    }

    public function ratePlan()
    {
        return $this->belongsTo(HotelRatePlan::class, 'hotel_rate_plan_id');
    }

    public function stay()
    {
        return $this->hasOne(HotelStay::class);
    }
}
