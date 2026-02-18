<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosCashDrop extends Model
{
    protected $fillable = [
        'shift_id',
        'amount',
        'reason',
        'user_id',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function shift()
    {
        return $this->belongsTo(PosShift::class, 'shift_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
