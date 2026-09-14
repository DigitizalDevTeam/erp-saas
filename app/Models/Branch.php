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
        'default_warehouse_id',
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

    public function defaultWarehouse()
    {
        return $this->belongsTo(\App\Models\warehouse::class, 'default_warehouse_id');
    }

    /**
     * Warehouse to preselect for this POS/branch (configured default, else first linked warehouse).
     */
    public function resolveDefaultWarehouseId(): ?int
    {
        if ($this->default_warehouse_id) {
            $ok = \App\Models\warehouse::query()
                ->where('id', $this->default_warehouse_id)
                ->where(function ($q) {
                    $q->where('branch_id', $this->id)->orWhereNull('branch_id');
                })
                ->exists();
            if ($ok) {
                return (int) $this->default_warehouse_id;
            }
        }

        $first = \App\Models\warehouse::query()
            ->where('branch_id', $this->id)
            ->when($this->created_by, fn ($q) => $q->where('created_by', $this->created_by))
            ->orderBy('id')
            ->value('id');

        return $first ? (int) $first : null;
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
