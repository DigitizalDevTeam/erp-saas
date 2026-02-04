<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosTableAssignment extends Model
{
    protected $fillable = [
        'shift_id',
        'table_id',
        'user_id',
        'assigned_at',
        'unassigned_at',
        'created_by',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'unassigned_at' => 'datetime',
    ];

    public function shift()
    {
        return $this->belongsTo(PosShift::class, 'shift_id');
    }

    public function table()
    {
        return $this->belongsTo(PosTable::class, 'table_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
