<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelFloor extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'level_number',
        'sort_order',
        'is_active',
        'created_by',
    ];

    public function rooms()
    {
        return $this->hasMany(HotelRoom::class);
    }
}
