<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Treasury company settings, stored in the existing `settings` table
 * per created_by (plan §5) — no new settings table. Follows the
 * CostCentreSettings pattern exactly.
 *
 * D2 (plan §12): base currency is MZN per company; every Treasury
 * operation stores original amount + base amount + rate + rate source
 * + rate date from Phase 0. If the rate for a date is missing,
 * confirmation is blocked — never guess a rate.
 *
 * D3 (plan §12): negative-balance policy is block by default; allow
 * only on named accounts with a named limit and an approver. Limits
 * are set per account in Phase 0 (TreasuryFinancialAccount).
 *
 * The same keys (with the same defaults) are listed in
 * Utility::settings() so the standard settings array carries them too.
 */
class TreasurySettings
{
    public const KEY_BASE_CURRENCY = 'treasury_base_currency';
    public const KEY_NEGATIVE_BALANCE_POLICY = 'treasury_negative_balance_policy';
    public const KEY_RESERVATION_POLICY = 'treasury_reservation_policy';
    public const KEY_REFERENCE_PREFIX = 'treasury_reference_prefix';
    public const KEY_RETENTION_MONTHS = 'treasury_retention_months';
    public const KEY_NOTIFICATION_CHANNELS = 'treasury_notification_channels';
    public const KEY_APPROVAL_DEFAULT = 'treasury_approval_default';
    public const KEY_FORECAST_HORIZONS = 'treasury_forecast_horizons';
    public const KEY_STATEMENT_DATE_TOLERANCE_DAYS = 'treasury_statement_date_tolerance_days';
    public const KEY_STATEMENT_AUTO_MATCH = 'treasury_statement_auto_match';
    // Phase 1 — chart account codes (exact code or prefix) used by the
    // receipt journals. Empty means "use the documented default prefix";
    // when neither resolves, confirmation is refused (never guess).
    public const KEY_FEE_ACCOUNT_CODE = 'treasury_fee_account_code';
    public const KEY_WITHHOLDING_ACCOUNT_CODE = 'treasury_withholding_account_code';
    public const KEY_DISCOUNT_ACCOUNT_CODE = 'treasury_discount_account_code';
    public const KEY_CUSTOMER_CREDIT_ACCOUNT_CODE = 'treasury_customer_credit_account_code';
    // Phase 4 — realised exchange-difference accounts (transfer
    // FX). Empty means "use the documented default prefix" (loss
    // 694 "Diferenças de câmbio desfavoráveis", gain 784
    // "Diferenças de câmbio favoráveis" on the Hoya chart); when
    // neither resolves, a transfer that needs the line is refused.
    public const KEY_EXCHANGE_LOSS_ACCOUNT_CODE = 'treasury_exchange_loss_account_code';
    public const KEY_EXCHANGE_GAIN_ACCOUNT_CODE = 'treasury_exchange_gain_account_code';
    // Phase 2 — approval rules (JSON list of levels) + reservation
    // threshold (base-currency amount at/above which approval reserves
    // funds). D4/D5 (plan §12): no client example was given, so the
    // default is one approval level for every request and a second,
    // independent level at/above 100,000 base — configurable per
    // company through the same settings screen.
    public const KEY_APPROVAL_RULES = 'treasury_approval_rules';
    public const KEY_RESERVATION_THRESHOLD = 'treasury_reservation_threshold_base';

    public const DEFAULT_APPROVAL_RULES = [
        ['level' => 1, 'min_amount_base' => '0', 'permission' => 'approve treasury payments', 'required_votes' => 1],
        ['level' => 2, 'min_amount_base' => '100000', 'permission' => 'approve treasury payments', 'required_votes' => 1],
    ];

    public const NEGATIVE_BALANCE_POLICIES = ['block', 'allow', 'approval'];
    public const RESERVATION_POLICIES = ['off', 'on'];
    public const NOTIFICATION_CHANNELS = ['email', 'none'];
    public const APPROVAL_DEFAULTS = ['none', 'manager', 'finance_controller'];

    public const DEFAULTS = [
        self::KEY_BASE_CURRENCY => 'MZN',
        self::KEY_NEGATIVE_BALANCE_POLICY => 'block',
        self::KEY_RESERVATION_POLICY => 'off',
        self::KEY_REFERENCE_PREFIX => '',
        self::KEY_RETENTION_MONTHS => '60',
        self::KEY_NOTIFICATION_CHANNELS => 'email',
        self::KEY_APPROVAL_DEFAULT => 'manager',
        self::KEY_FORECAST_HORIZONS => '7,15,30,90',
        self::KEY_STATEMENT_DATE_TOLERANCE_DAYS => '3',
        self::KEY_STATEMENT_AUTO_MATCH => '0',
        self::KEY_FEE_ACCOUNT_CODE => '',
        self::KEY_WITHHOLDING_ACCOUNT_CODE => '',
        self::KEY_DISCOUNT_ACCOUNT_CODE => '',
        self::KEY_CUSTOMER_CREDIT_ACCOUNT_CODE => '',
        self::KEY_EXCHANGE_LOSS_ACCOUNT_CODE => '',
        self::KEY_EXCHANGE_GAIN_ACCOUNT_CODE => '',
        self::KEY_APPROVAL_RULES => '',
        self::KEY_RESERVATION_THRESHOLD => '0',
    ];

    /**
     * Approval rules for a company, normalised to a list of levels
     * [{level, min_amount_base, permission, required_votes}]. An
     * invalid or empty stored value falls back to the default rules.
     */
    public static function approvalRules(int $creatorId): array
    {
        $raw = trim((string) (self::get($creatorId, self::KEY_APPROVAL_RULES) ?? ''));

        $rules = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $rule) {
                    if (! is_array($rule)) {
                        continue;
                    }
                    $level = (int) ($rule['level'] ?? 0);
                    $permission = trim((string) ($rule['permission'] ?? ''));
                    $votes = (int) ($rule['required_votes'] ?? 1);
                    $min = isset($rule['min_amount_base']) && is_numeric($rule['min_amount_base'])
                        ? number_format((float) $rule['min_amount_base'], 4, '.', '')
                        : '0.0000';
                    if ($level > 0 && $permission !== '' && $votes > 0) {
                        $rules[] = [
                            'level' => $level,
                            'min_amount_base' => $min,
                            'permission' => $permission,
                            'required_votes' => $votes,
                        ];
                    }
                }
            }
        }

        if ($rules === []) {
            $rules = self::DEFAULT_APPROVAL_RULES;
        }

        usort($rules, fn ($a, $b) => $a['level'] <=> $b['level']);

        return array_values($rules);
    }

    /** Reservation threshold in base currency (D4). */
    public static function reservationThreshold(int $creatorId): string
    {
        $raw = trim((string) (self::get($creatorId, self::KEY_RESERVATION_THRESHOLD) ?? ''));

        return is_numeric($raw) && (float) $raw >= 0
            ? number_format((float) $raw, 4, '.', '')
            : '0.0000';
    }

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

    public static function baseCurrency(int $creatorId): string
    {
        $currency = strtoupper(trim((string) (self::get($creatorId, self::KEY_BASE_CURRENCY) ?? '')));

        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'MZN';
    }

    /**
     * Normalise + persist one row per key. Unknown keys are ignored;
     * invalid enum values fall back to their default.
     */
    public static function save(int $creatorId, array $input): array
    {
        $values = self::DEFAULTS;

        $currency = strtoupper(trim((string) ($input[self::KEY_BASE_CURRENCY] ?? '')));
        $values[self::KEY_BASE_CURRENCY] = preg_match('/^[A-Z]{3}$/', $currency)
            ? $currency : self::DEFAULTS[self::KEY_BASE_CURRENCY];

        $policy = (string) ($input[self::KEY_NEGATIVE_BALANCE_POLICY] ?? '');
        $values[self::KEY_NEGATIVE_BALANCE_POLICY] = in_array($policy, self::NEGATIVE_BALANCE_POLICIES, true)
            ? $policy : self::DEFAULTS[self::KEY_NEGATIVE_BALANCE_POLICY];

        $reservation = (string) ($input[self::KEY_RESERVATION_POLICY] ?? '');
        $values[self::KEY_RESERVATION_POLICY] = in_array($reservation, self::RESERVATION_POLICIES, true)
            ? $reservation : self::DEFAULTS[self::KEY_RESERVATION_POLICY];

        $values[self::KEY_REFERENCE_PREFIX] = substr(trim((string) ($input[self::KEY_REFERENCE_PREFIX] ?? '')), 0, 20);

        $retention = trim((string) ($input[self::KEY_RETENTION_MONTHS] ?? ''));
        $values[self::KEY_RETENTION_MONTHS] = ctype_digit($retention) && (int) $retention > 0
            ? (string) (int) $retention : self::DEFAULTS[self::KEY_RETENTION_MONTHS];

        $channels = (string) ($input[self::KEY_NOTIFICATION_CHANNELS] ?? '');
        $values[self::KEY_NOTIFICATION_CHANNELS] = in_array($channels, self::NOTIFICATION_CHANNELS, true)
            ? $channels : self::DEFAULTS[self::KEY_NOTIFICATION_CHANNELS];

        $approval = (string) ($input[self::KEY_APPROVAL_DEFAULT] ?? '');
        $values[self::KEY_APPROVAL_DEFAULT] = in_array($approval, self::APPROVAL_DEFAULTS, true)
            ? $approval : self::DEFAULTS[self::KEY_APPROVAL_DEFAULT];

        // Forecast horizons: comma-separated positive day counts.
        $horizons = array_values(array_filter(array_map(
            fn ($part) => ctype_digit(trim($part)) && (int) trim($part) > 0 ? (string) (int) trim($part) : null,
            explode(',', (string) ($input[self::KEY_FORECAST_HORIZONS] ?? ''))
        )));
        $values[self::KEY_FORECAST_HORIZONS] = $horizons !== []
            ? implode(',', $horizons) : self::DEFAULTS[self::KEY_FORECAST_HORIZONS];

        $tolerance = trim((string) ($input[self::KEY_STATEMENT_DATE_TOLERANCE_DAYS] ?? ''));
        $values[self::KEY_STATEMENT_DATE_TOLERANCE_DAYS] = ctype_digit($tolerance)
            ? (string) (int) $tolerance : self::DEFAULTS[self::KEY_STATEMENT_DATE_TOLERANCE_DAYS];

        $values[self::KEY_STATEMENT_AUTO_MATCH] = ! empty($input[self::KEY_STATEMENT_AUTO_MATCH]) ? '1' : '0';

        // Phase 1 posting accounts: a chart code or prefix (digits,
        // up to 20 chars). Empty keeps the default (no account set).
        foreach ([
            self::KEY_FEE_ACCOUNT_CODE,
            self::KEY_WITHHOLDING_ACCOUNT_CODE,
            self::KEY_DISCOUNT_ACCOUNT_CODE,
            self::KEY_CUSTOMER_CREDIT_ACCOUNT_CODE,
            self::KEY_EXCHANGE_LOSS_ACCOUNT_CODE,
            self::KEY_EXCHANGE_GAIN_ACCOUNT_CODE,
        ] as $key) {
            $values[$key] = substr(trim((string) ($input[$key] ?? '')), 0, 20);
        }

        // Phase 2 approval rules: a JSON list of levels. Invalid JSON
        // or a level list with no usable level keeps the default.
        $rulesRaw = trim((string) ($input[self::KEY_APPROVAL_RULES] ?? ''));
        if ($rulesRaw !== '') {
            $decoded = json_decode($rulesRaw, true);
            $usable = is_array($decoded) && array_values(array_filter($decoded, fn ($r) => is_array($r) && (int) ($r['level'] ?? 0) > 0 && trim((string) ($r['permission'] ?? '')) !== '')) !== [];
            $values[self::KEY_APPROVAL_RULES] = $usable ? $rulesRaw : '';
        } else {
            $values[self::KEY_APPROVAL_RULES] = '';
        }

        $threshold = trim((string) ($input[self::KEY_RESERVATION_THRESHOLD] ?? ''));
        $values[self::KEY_RESERVATION_THRESHOLD] = is_numeric($threshold) && (float) $threshold >= 0
            ? number_format((float) $threshold, 4, '.', '')
            : self::DEFAULTS[self::KEY_RESERVATION_THRESHOLD];

        foreach ($values as $name => $value) {
            DB::table('settings')->updateOrInsert(
                ['name' => $name, 'created_by' => $creatorId],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return $values;
    }
}
