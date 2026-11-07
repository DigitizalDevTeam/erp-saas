<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliationMatch extends Model
{
    protected $fillable = [
        'session_id',
        'statement_line_id',
        'transaction_id',
        'treasury_operation_id',
        'matched_amount',
        'match_type',
        'match_note',
    ];

    protected $casts = [
        'matched_amount' => 'decimal:4',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(BankReconciliationSession::class, 'session_id');
    }

    public function statementLine(): BelongsTo
    {
        return $this->belongsTo(BankStatementLine::class, 'statement_line_id');
    }

    public function erpTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function treasuryOperation(): BelongsTo
    {
        return $this->belongsTo(TreasuryOperation::class, 'treasury_operation_id');
    }

    public function isTreasuryMatch(): bool
    {
        return $this->treasury_operation_id !== null;
    }
}
