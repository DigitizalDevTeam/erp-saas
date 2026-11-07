<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Treasury Phase 8 — one expected movement in the cash-flow
 * forecast (plan §5, §8, §10, CA13).
 *
 * System items (balance / invoice / payroll) are regenerated on
 * every forecast refresh. Manual and contract items persist
 * across refreshes; a contract item is excluded from the totals
 * once its invoice is issued (replacement_reference set).
 */
class TreasuryForecastItem extends Model
{
    public const SOURCE_BALANCE = 'balance';
    public const SOURCE_INVOICE = 'invoice';
    public const SOURCE_CONTRACT = 'contract';
    public const SOURCE_PAYROLL = 'payroll';
    public const SOURCE_EXPENSE = 'expense';
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_TYPES = [
        self::SOURCE_BALANCE,
        self::SOURCE_INVOICE,
        self::SOURCE_CONTRACT,
        self::SOURCE_PAYROLL,
        self::SOURCE_EXPENSE,
        self::SOURCE_MANUAL,
    ];

    /** Sources regenerated from live data on every refresh. */
    public const SYSTEM_SOURCE_TYPES = [
        self::SOURCE_BALANCE,
        self::SOURCE_INVOICE,
        self::SOURCE_PAYROLL,
    ];

    public const DIRECTION_INFLOW = 'inflow';
    public const DIRECTION_OUTFLOW = 'outflow';

    public const SCENARIO_BASELINE = 'baseline';
    public const SCENARIO_LATE_RECEIPTS = 'late_receipts';
    public const SCENARIO_HIGHER_EXPENSES = 'higher_expenses';
    public const SCENARIO_EARLIER_PAYMENTS = 'earlier_payments';

    public const SCENARIOS = [
        self::SCENARIO_BASELINE,
        self::SCENARIO_LATE_RECEIPTS,
        self::SCENARIO_HIGHER_EXPENSES,
        self::SCENARIO_EARLIER_PAYMENTS,
    ];

    public const STATE_FORECAST = 'forecast';
    public const STATE_ACTUAL = 'actual';

    protected $fillable = [
        'company_id',
        'created_by',
        'source_type',
        'source_id',
        'direction',
        'amount',
        'amount_base',
        'currency',
        'exchange_rate',
        'expected_date',
        'scenario',
        'probability',
        'document',
        'replacement_reference',
        'description',
        'state',
    ];

    protected $casts = [
        'expected_date' => 'date',
    ];

    /** A replaced contract projection no longer counts in the forecast. */
    public function isReplaced(): bool
    {
        return $this->replacement_reference !== null && trim((string) $this->replacement_reference) !== '';
    }
}
