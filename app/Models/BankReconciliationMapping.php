<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankReconciliationMapping extends Model
{
    protected $fillable = [
        'bank_account_id',
        'created_by',
        'preset_key',
        'mapping',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    public static function forAccount(int $bankAccountId, int $createdBy): ?self
    {
        return static::where('bank_account_id', $bankAccountId)
            ->where('created_by', $createdBy)
            ->first();
    }
}
