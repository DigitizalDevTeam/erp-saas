<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosTable extends Model
{
    const STATUS_AVAILABLE = 'available';
    const STATUS_OCCUPIED = 'occupied';
    const STATUS_RESERVED = 'reserved';

    protected $fillable = [
        'area_id',
        'branch_id',
        'name',
        'capacity',
        'current_guests',
        'status',
        'created_by',
    ];

    public function area()
    {
        return $this->belongsTo(PosArea::class, 'area_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function posSales()
    {
        return $this->hasMany(Pos::class, 'table_id');
    }

    public function currentAssignment()
    {
        return $this->hasOne(PosTableAssignment::class, 'table_id')
            ->whereNull('unassigned_at');
    }
}
