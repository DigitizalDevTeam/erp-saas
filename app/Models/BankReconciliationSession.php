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
        'file_hash',
        'period_from',
        'period_to',
        'date_tolerance_days',
        'column_mapping',
        'statement_balance',
        'book_balance',
        'outstanding_items',
        'explained_differences',
        'closed_at',
        'closed_by',
        'reopened_at',
        'reopen_reason',
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
            'statement_balance' => 'decimal:4',
            'book_balance' => 'decimal:4',
            'outstanding_items' => 'decimal:4',
            'explained_differences' => 'decimal:4',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    public const STATUS_DRAFT = 'draft';
    public const STATUS_CLOSED = 'closed';

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
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

    public function missingMovements(): HasMany
    {
        return $this->hasMany(TreasuryMissingMovement::class, 'session_id');
    }
}
