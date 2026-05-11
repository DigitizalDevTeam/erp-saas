<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelRoomType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'unit_category',
        'base_price',
        'nightly_rate',
        'monthly_rate',
        'default_billing_mode',
        'max_occupancy',
        'is_active',
        'created_by',
    ];

    public function rooms()
    {
        return $this->hasMany(HotelRoom::class);
    }

    public function ratePlans()
    {
        return $this->hasMany(HotelRatePlan::class);
    }
}
