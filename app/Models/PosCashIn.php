<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosCashIn extends Model
{
    protected $fillable = [
        'shift_id',
        'amount',
        'source_account_id',
        'target_account_id',
        'reason',
        'user_id',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function shift()
    {
        return $this->belongsTo(PosShift::class, 'shift_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function sourceAccount()
    {
        return $this->belongsTo(BankAccount::class, 'source_account_id', 'id');
    }

    public function targetAccount()
    {
        return $this->belongsTo(BankAccount::class, 'target_account_id', 'id');
    }
}

