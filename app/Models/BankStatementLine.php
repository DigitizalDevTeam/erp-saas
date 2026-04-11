<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BankStatementLine extends Model
{
    protected $fillable = [
        'session_id',
        'row_index',
        'txn_date',
        'description',
        'amount',
        'balance',
        'parse_error',
        'raw_row',
    ];

    protected function casts(): array
    {
        return [
            'txn_date' => 'date',
            'amount' => 'decimal:4',
            'balance' => 'decimal:4',
            'raw_row' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(BankReconciliationSession::class, 'session_id');
    }

    public function reconciliationMatch(): HasOne
    {
        return $this->hasOne(BankReconciliationMatch::class, 'statement_line_id');
    }
}
