<?php

namespace Modules\HotelManagement\Entities;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotelNightAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_id',
        'audit_date',
        'room_revenue',
        'service_revenue',
        'total_revenue',
        'status',
        'created_by',
    ];
}
