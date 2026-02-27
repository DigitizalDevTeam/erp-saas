<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosDayClosed extends Model
{
    protected $table = 'pos_day_closed';

    protected $fillable = ['closed_date', 'closed_by', 'closed_at', 'created_by'];

    protected $casts = [
        'closed_date' => 'date',
        'closed_at' => 'datetime',
    ];

    public function closedByUser()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
