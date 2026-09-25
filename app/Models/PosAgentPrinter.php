<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosAgentPrinter extends Model
{
    protected $fillable = [
        'branch_id',
        'created_by',
        'system_name',
        'display_name',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
