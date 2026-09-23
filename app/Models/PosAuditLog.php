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
    const ACTION_CASH_IN = 'cash_in';
    const ACTION_CASH_OUT_EXPENSE = 'cash_out_expense';
    const ACTION_CLOSE_SHIFT = 'close_shift';
    const ACTION_REOPEN_TABLE = 'reopen_table';
    const ACTION_REFUND = 'refund';
    const ACTION_VOID_LINE = 'void_line';
    const ACTION_DELETE_LINE = 'delete_line';
    const ACTION_SWITCH_USER = 'switch_user';

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
