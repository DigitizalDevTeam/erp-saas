<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelGuest extends Model
{
    use HasFactory;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'birth_date',
        'id_type',
        'id_number',
        'nationality',
        'preferred_room_type_id',
        'preferred_floor',
        'preferred_payment_method',
        'credit_limit',
        'notes',
        'company_name',
        'is_vip',
        'created_by',
    ];

    public function preferredRoomType()
    {
        return $this->belongsTo(HotelRoomType::class, 'preferred_room_type_id');
    }

    public function reservations()
    {
        return $this->hasMany(HotelReservation::class);
    }
}
