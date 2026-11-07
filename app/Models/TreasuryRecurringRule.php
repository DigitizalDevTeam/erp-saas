<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Treasury Phase 8 — a recurring commitment rule (plan §5, §6,
 * §10, CA14).
 *
 * The rule holds the schedule; the occurrences hold the dated
 * amounts. Changes apply to future unconfirmed occurrences
 * only; pausing or ending a rule keeps earlier occurrences.
 * Automatic execution is disabled by default — an occurrence
 * never creates a payment by itself.
 */
class TreasuryRecurringRule extends Model
{
    public const FREQUENCY_MONTHLY = 'monthly';
    public const FREQUENCY_QUARTERLY = 'quarterly';
    public const FREQUENCY_SEMI_ANNUAL = 'semi-annual';
    public const FREQUENCY_ANNUAL = 'annual';
    public const FREQUENCY_CUSTOM = 'custom';

    public const FREQUENCIES = [
        self::FREQUENCY_MONTHLY,
        self::FREQUENCY_QUARTERLY,
        self::FREQUENCY_SEMI_ANNUAL,
        self::FREQUENCY_ANNUAL,
        self::FREQUENCY_CUSTOM,
    ];

    public const CUSTOM_UNITS = ['days', 'weeks', 'months', 'years'];

    public const STATE_ACTIVE = 'active';
    public const STATE_PAUSED = 'paused';
    public const STATE_ENDED = 'ended';

    public const STATES = [
        self::STATE_ACTIVE,
        self::STATE_PAUSED,
        self::STATE_ENDED,
    ];

    protected $fillable = [
        'company_id',
        'created_by',
        'beneficiary',
        'description',
        'category',
        'direction',
        'currency',
        'amount',
        'amount_base',
        'amount_type',
        'start_date',
        'end_date',
        'frequency',
        'custom_interval',
        'custom_unit',
        'due_day',
        'planned_account_id',
        'approval_required',
        'state',
        'version',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'approval_required' => 'boolean',
    ];

    public function occurrences(): HasMany
    {
        return $this->hasMany(TreasuryRecurringOccurrence::class, 'rule_id');
    }

    public function plannedAccount()
    {
        return $this->belongsTo(TreasuryFinancialAccount::class, 'planned_account_id');
    }

    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }
}
