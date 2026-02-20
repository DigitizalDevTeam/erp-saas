<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosAuditLog extends Model
{
    const ACTION_OPEN_TABLE = 'open_table';
    const ACTION_CLOSE_TABLE = 'close_table';
    const ACTION_SEND_ORDER = 'send_order';
    const ACTION_CANCEL_ORDER = 'cancel_order';
    const ACTION_DISCOUNT = 'discount';
    const ACTION_CASH_DROP = 'cash_drop';
    const ACTION_CLOSE_SHIFT = 'close_shift';
    const ACTION_REOPEN_TABLE = 'reopen_table';
    const ACTION_REFUND = 'refund';
    const ACTION_VOID_LINE = 'void_line';

    protected $fillable = [
        'created_by',
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'description',
        'meta',
        'ip',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
