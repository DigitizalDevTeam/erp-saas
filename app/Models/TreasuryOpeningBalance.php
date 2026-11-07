<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 0 — opening balance (plan §5).
 *
 * Draft → approved. A confirmed (approved) row is corrected only by an
 * authorised reversal — a new row linked via `reversal_of_id` with
 * negated amounts — never by editing the confirmed row (RF03).
 *
 * Posted balance counts every non-draft row: an approved row counts
 * its amount, a reversed row still counts its amount, and its reversal
 * row counts the negated amount — so a reversed balance nets to zero.
 * See TreasuryAccountService::postedBalance().
 */
class TreasuryOpeningBalance extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REVERSED = 'reversed';

    public const RATE_SOURCE_BANK = 'bank_rate';
    public const RATE_SOURCE_CENTRAL_BANK = 'central_bank';
    public const RATE_SOURCE_MANUAL = 'manual';

    public const RATE_SOURCES = [
        self::RATE_SOURCE_BANK,
        self::RATE_SOURCE_CENTRAL_BANK,
        self::RATE_SOURCE_MANUAL,
    ];

    public const RATE_SOURCE_LABELS = [
        self::RATE_SOURCE_BANK => 'Bank rate',
        self::RATE_SOURCE_CENTRAL_BANK => 'Central bank rate',
        self::RATE_SOURCE_MANUAL => 'Manual entry',
    ];

    protected $fillable = [
        'financial_account_id',
        'created_by',
        'currency',
        'amount',
        'amount_base',
        'exchange_rate',
        'rate_source',
        'rate_date',
        'cut_off_date',
        'batch_reference',
        'operator_user_id',
        'attachments',
        'status',
        'includes_liabilities',
        'includes_receivables',
        'reversal_of_id',
        'reversal_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'amount_base' => 'decimal:4',
        'exchange_rate' => 'decimal:10',
        'cut_off_date' => 'date',
        'rate_date' => 'date',
        'includes_liabilities' => 'boolean',
        'includes_receivables' => 'boolean',
    ];

    public function financialAccount()
    {
        return $this->belongsTo(TreasuryFinancialAccount::class, 'financial_account_id');
    }

    public function operator()
    {
        return $this->belongsTo(User::class, 'operator_user_id');
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function reversedBalance()
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    /** Confirmed rows (approved + reversed) count toward the balance. */
    public function scopeConfirmed($query)
    {
        return $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_REVERSED]);
    }
}
