<?php

namespace App\Models;

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
        'company_name',
        'is_vip',
        'created_by',
    ];

    public function reservations()
    {
        return $this->hasMany(HotelReservation::class);
    }
}
