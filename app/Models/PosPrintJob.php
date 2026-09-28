<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosPrintJob extends Model
{
    public const ZONE_KITCHEN = 'kitchen';
    public const ZONE_BAR = 'bar';
    public const ZONE_RECEIPT = 'receipt';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PRINTING = 'printing';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'branch_id',
        'created_by',
        'pos_id',
        'zone',
        'system_printer_name',
        'title',
        'payload',
        'status',
        'error_message',
        'claimed_at',
        'printed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'claimed_at' => 'datetime',
        'printed_at' => 'datetime',
    ];

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function pos()
    {
        return $this->belongsTo(Pos::class, 'pos_id');
    }
}
