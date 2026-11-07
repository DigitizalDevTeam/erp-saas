<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Cost centre company settings, stored in the existing `settings` table
 * per created_by (plan §5) — no new settings table.
 *
 * D2 (plan §12) is recorded here as the company setting
 * `cost_centre_parents_summary_only` = 1: parents are summary-only,
 * only leaf children take direct postings. The posting enforcement
 * itself lands in Phase 1; Phase 0 already enforces it in
 * CostCentreService::assertCanAcceptTransactions() so later phases
 * inherit the guard.
 *
 * The same keys (with the same defaults) are listed in
 * Utility::settings() so the standard settings array carries them too.
 */
class CostCentreSettings
{
    public const KEY_REQUIRE_FOR_EXPENSES = 'require_cost_centre_for_expenses';
    public const KEY_ALLOW_SPLITS = 'allow_splits';
    public const KEY_BUDGET_POLICY = 'budget_policy';
    public const KEY_DIMENSION_ON_CASH = 'dimension_on_cash_transactions';
    public const KEY_PERIOD_LOCK_POLICY = 'period_lock_policy';
    public const KEY_ROUNDING_ACCOUNT = 'rounding_account';
    public const KEY_DEFAULT_ALLOCATION_BASIS = 'default_allocation_basis';
    public const KEY_PARENTS_SUMMARY_ONLY = 'cost_centre_parents_summary_only';

    public const BUDGET_POLICIES = ['off', 'warn', 'block', 'approval'];
    public const DIMENSION_ON_CASH_VALUES = ['optional', 'required'];
    public const PERIOD_LOCK_POLICIES = ['block', 'warn', 'off'];
    public const ALLOCATION_BASES = ['percentage', 'fixed_amount'];

    public const DEFAULTS = [
        self::KEY_REQUIRE_FOR_EXPENSES => '0',
        self::KEY_ALLOW_SPLITS => '1',
        self::KEY_BUDGET_POLICY => 'off',
        self::KEY_DIMENSION_ON_CASH => 'optional',
        self::KEY_PERIOD_LOCK_POLICY => 'block',
        self::KEY_ROUNDING_ACCOUNT => '',
        self::KEY_DEFAULT_ALLOCATION_BASIS => 'percentage',
        // D2: parents are summary-only (children only take direct postings).
        self::KEY_PARENTS_SUMMARY_ONLY => '1',
    ];

    public static function forCompany(int $creatorId): array
    {
        $settings = self::DEFAULTS;

        $rows = DB::table('settings')
            ->where('created_by', $creatorId)
            ->whereIn('name', array_keys(self::DEFAULTS))
            ->pluck('value', 'name');

        foreach ($rows as $name => $value) {
            $settings[$name] = $value;
        }

        return $settings;
    }

    public static function get(int $creatorId, string $key): ?string
    {
        $settings = self::forCompany($creatorId);

        return $settings[$key] ?? null;
    }

    public static function parentsSummaryOnly(int $creatorId): bool
    {
        return self::get($creatorId, self::KEY_PARENTS_SUMMARY_ONLY) !== '0';
    }

    /**
     * Normalise + persist one row per key. Unknown keys are ignored;
     * invalid enum values fall back to their default.
     */
    public static function save(int $creatorId, array $input): array
    {
        $values = self::DEFAULTS;

        $values[self::KEY_REQUIRE_FOR_EXPENSES] = ! empty($input[self::KEY_REQUIRE_FOR_EXPENSES]) ? '1' : '0';
        $values[self::KEY_ALLOW_SPLITS] = ! empty($input[self::KEY_ALLOW_SPLITS]) ? '1' : '0';
        $values[self::KEY_PARENTS_SUMMARY_ONLY] = ! empty($input[self::KEY_PARENTS_SUMMARY_ONLY]) ? '1' : '0';

        $budgetPolicy = (string) ($input[self::KEY_BUDGET_POLICY] ?? '');
        $values[self::KEY_BUDGET_POLICY] = in_array($budgetPolicy, self::BUDGET_POLICIES, true)
            ? $budgetPolicy : self::DEFAULTS[self::KEY_BUDGET_POLICY];

        $dimensionOnCash = (string) ($input[self::KEY_DIMENSION_ON_CASH] ?? '');
        $values[self::KEY_DIMENSION_ON_CASH] = in_array($dimensionOnCash, self::DIMENSION_ON_CASH_VALUES, true)
            ? $dimensionOnCash : self::DEFAULTS[self::KEY_DIMENSION_ON_CASH];

        $periodLock = (string) ($input[self::KEY_PERIOD_LOCK_POLICY] ?? '');
        $values[self::KEY_PERIOD_LOCK_POLICY] = in_array($periodLock, self::PERIOD_LOCK_POLICIES, true)
            ? $periodLock : self::DEFAULTS[self::KEY_PERIOD_LOCK_POLICY];

        $basis = (string) ($input[self::KEY_DEFAULT_ALLOCATION_BASIS] ?? '');
        $values[self::KEY_DEFAULT_ALLOCATION_BASIS] = in_array($basis, self::ALLOCATION_BASES, true)
            ? $basis : self::DEFAULTS[self::KEY_DEFAULT_ALLOCATION_BASIS];

        // Rounding account: a GL account code (free text, optional).
        $values[self::KEY_ROUNDING_ACCOUNT] = substr(trim((string) ($input[self::KEY_ROUNDING_ACCOUNT] ?? '')), 0, 50);

        foreach ($values as $name => $value) {
            DB::table('settings')->updateOrInsert(
                ['name' => $name, 'created_by' => $creatorId],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return $values;
    }
}
