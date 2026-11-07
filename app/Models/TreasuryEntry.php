<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 1 — confirmed money movements (plan §5).
 *
 * One entry per confirmed operation leg (the bank movement). A
 * reversal is a NEW entry with negated amounts linked via
 * `reversal_of_id` — never an edit of the old one (RF03).
 */
class TreasuryEntry extends Model
{
    public const DIRECTION_INFLOW = 'inflow';
    public const DIRECTION_OUTFLOW = 'outflow';

    protected $fillable = [
        'operation_id',
        'financial_account_id',
        'direction',
        'amount_original',
        'amount_base',
        'exchange_rate',
        'confirmed_at',
        'reversal_of_id',
        'journal_id',
        'created_by',
    ];

    protected $casts = [
        'amount_original' => 'decimal:4',
        'amount_base' => 'decimal:4',
        'exchange_rate' => 'decimal:10',
        'confirmed_at' => 'datetime',
    ];

    public function operation()
    {
        return $this->belongsTo(TreasuryOperation::class, 'operation_id');
    }

    public function financialAccount()
    {
        return $this->belongsTo(TreasuryFinancialAccount::class, 'financial_account_id');
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_id');
    }
}
