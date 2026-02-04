<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;


class PosBalance extends Model
{
    protected $fillable = [
        'id',
        'user_id',
        'branch_id',
        'opening_balance',
        'closing_balance',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function user()
    {
        return $this->hasMany('App\Models\User', 'id', 'user_id');
    }


}



