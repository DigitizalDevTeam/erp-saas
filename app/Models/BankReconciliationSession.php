<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankReconciliationSession extends Model
{
    protected $fillable = [
        'bank_account_id',
        'preset_key',
        'status',
        'original_filename',
        'stored_path',
        'period_from',
        'period_to',
        'date_tolerance_days',
        'column_mapping',
        'created_by',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'column_mapping' => 'array',
            'period_from' => 'date',
            'period_to' => 'date',
            'date_tolerance_days' => 'integer',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function statementLines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class, 'session_id');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(BankReconciliationMatch::class, 'session_id');
    }
}
