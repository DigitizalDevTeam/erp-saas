<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelRoomType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'base_price',
        'max_occupancy',
        'is_active',
        'created_by',
    ];

    public function rooms()
    {
        return $this->hasMany(HotelRoom::class);
    }
}
