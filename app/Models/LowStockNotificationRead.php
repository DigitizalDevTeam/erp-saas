<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LowStockNotificationRead extends Model
{
    protected $fillable = [
        'notification_id',
        'user_id',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];
}
