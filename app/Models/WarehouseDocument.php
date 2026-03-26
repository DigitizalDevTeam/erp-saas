<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WarehouseDocument extends Model
{
    protected $fillable = [
        'warehouse_id',
        'title',
        'file_path',
        'created_by',
    ];

    public function warehouse()
    {
        return $this->belongsTo(warehouse::class, 'warehouse_id');
    }
}

