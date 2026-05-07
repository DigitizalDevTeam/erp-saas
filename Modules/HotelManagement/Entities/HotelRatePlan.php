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
        'valid_from',
        'valid_to',
        'is_active',
        'created_by',
    ];
}
