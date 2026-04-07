<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $fillable = [
        'name',
        'address',
        'timezone',
        'is_active',
        'pos_type',
        'created_by',
    ];

    const POS_TYPE_RETAIL = 'retail';
    const POS_TYPE_RESTAURANT = 'restaurant';

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function warehouses()
    {
        return $this->hasMany(\App\Models\warehouse::class, 'branch_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'branch_user')->withTimestamps();
    }

    public function posSales()
    {
        return $this->hasMany(\App\Models\Pos::class, 'branch_id');
    }

    public function posAreas()
    {
        return $this->hasMany(\App\Models\PosArea::class, 'branch_id');
    }

    public function posPrinters()
    {
        return $this->hasMany(\App\Models\PosPrinter::class, 'branch_id');
    }

    public function posTables()
    {
        return $this->hasMany(\App\Models\PosTable::class, 'branch_id');
    }

    public function posShifts()
    {
        return $this->hasMany(\App\Models\PosShift::class, 'branch_id');
    }

    public function isRestaurant(): bool
    {
        return $this->pos_type === self::POS_TYPE_RESTAURANT;
    }
}
