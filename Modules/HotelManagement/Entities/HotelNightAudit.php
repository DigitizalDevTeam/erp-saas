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
        'pos_sales_total',
        'pos_payments_total',
        'summary_json',
        'status',
        'created_by',
        'closed_by',
    ];

    protected $casts = [
        'summary_json' => 'array',
        'audit_date' => 'date',
    ];
}
