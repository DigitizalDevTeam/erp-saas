<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'company_name',
        'contact_name',
        'notes',
        'branch_id',
        'created_by',
    ];

    public function members()
    {
        return $this->hasMany(HotelGroupMember::class, 'hotel_group_id');
    }

    public function reservations()
    {
        return $this->hasMany(HotelReservation::class, 'hotel_group_id');
    }
}
