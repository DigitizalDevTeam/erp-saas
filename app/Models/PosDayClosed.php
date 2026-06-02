<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosDayClosed extends Model
{
    protected $table = 'pos_day_closed';

    protected $fillable = ['closed_date', 'closed_by', 'closed_at', 'created_by', 'locked_by_night_audit'];

    protected $casts = [
        'closed_date' => 'date',
        'closed_at' => 'datetime',
        'locked_by_night_audit' => 'boolean',
    ];

    public function closedByUser()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
