<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosPrinter extends Model
{
    const TYPE_KITCHEN = 'kitchen';
    const TYPE_BAR = 'bar';
    const TYPE_RESTAURANT = 'restaurant';
    const CONNECTION_BROWSER = 'browser';

    protected $fillable = [
        'branch_id',
        'name',
        'type',
        'connection',
        'sort_order',
        'created_by',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
