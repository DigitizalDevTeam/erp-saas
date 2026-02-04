<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosArea extends Model
{
    protected $fillable = [
        'branch_id',
        'name',
        'sort_order',
        'created_by',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function tables()
    {
        return $this->hasMany(PosTable::class, 'area_id');
    }
}
