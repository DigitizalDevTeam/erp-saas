<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelMinibarItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'unit_price',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'unit_price' => 'float',
        'is_active' => 'boolean',
    ];
}
