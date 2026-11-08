<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Fixed Assets company settings, stored in the existing `settings`
 * table per created_by (plan §5) — no new settings table. Follows
 * the CostCentreSettings / TreasurySettings pattern exactly.
 *
 * D2 (plan §12b): base currency is MZN per company; every asset
 * stores its own currency + exchange rate + base value.
 * D5 (plan §12b): approval is by LEVELS and the levels differ per
 * company. No client example was given, so the default is one
 * approval level for every acquisition and a second, independent
 * level at/above the configured threshold (default 100,000 base)
 * — configurable per company through the settings screen.
 * D7 (plan §12b): the asset clearing account code is named by
 * the accountant; empty means "not configured" and Phase 1
 * refuses a capitalization that needs it.
 * Phase 4: the maintenance expense account code (SNC 6226
 * "Conservation and repair" by default) is named the same way;
 * a maintenance cost posts Dr that account / Cr accounts
 * payable 421. The code is configurable per company because
 * the chart differs per company.
 * D8 (plan §12b): label size is configurable (small / standard /
 * large); the printer itself is still to be confirmed.
 *
 * The same keys (with the same defaults) are listed in
 * Utility::settings() so the standard settings array carries them
 * too.
 */
class FixedAssetSettings
{
    public const KEY_BASE_CURRENCY = 'fixed_asset_base_currency';
    public const KEY_REFERENCE_PREFIX = 'fixed_asset_reference_prefix';
    public const KEY_CLEARING_ACCOUNT_CODE = 'fixed_asset_clearing_account_code';
    public const KEY_MAINTENANCE_EXPENSE_CODE = 'fixed_asset_maintenance_expense_code';
    public const KEY_LABEL_SIZE = 'fixed_asset_label_size';
    public const KEY_APPROVAL_THRESHOLD = 'fixed_asset_approval_threshold_base';

    public const LABEL_SIZES = ['small', 'standard', 'large'];

    // Label dimensions in millimetres (width × height) per size.
    public const LABEL_DIMENSIONS = [
        'small' => ['width' => 50, 'height' => 30],
        'standard' => ['width' => 80, 'height' => 50],
        'large' => ['width' => 100, 'height' => 60],
    ];

    public const DEFAULTS = [
        self::KEY_BASE_CURRENCY => 'MZN',
        self::KEY_REFERENCE_PREFIX => '',
        self::KEY_CLEARING_ACCOUNT_CODE => '',
        // Phase 4: SNC 6226 "Conservation and repair" — the
        // maintenance expense account. The resolver falls back
        // to the class 62 prefix when no exact 6226 exists.
        self::KEY_MAINTENANCE_EXPENSE_CODE => '6226',
        self::KEY_LABEL_SIZE => 'standard',
        // D5: at/above this base amount a second, independent
        // approval level is required. Default 100,000 — every
        // acquisition at/above it needs both levels until the
        // company sets its own threshold.
        self::KEY_APPROVAL_THRESHOLD => '100000',
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

    public static function baseCurrency(int $creatorId): string
    {
        return (string) (self::get($creatorId, self::KEY_BASE_CURRENCY) ?: 'MZN');
    }

    public static function labelSize(int $creatorId): string
    {
        $size = (string) self::get($creatorId, self::KEY_LABEL_SIZE);

        return in_array($size, self::LABEL_SIZES, true) ? $size : 'standard';
    }

    /** Maintenance expense account code (default SNC 6226). */
    public static function maintenanceExpenseCode(int $creatorId): string
    {
        $code = trim((string) self::get($creatorId, self::KEY_MAINTENANCE_EXPENSE_CODE));

        return $code !== '' ? $code : '6226';
    }

    public static function labelDimensions(int $creatorId): array
    {
        return self::LABEL_DIMENSIONS[self::labelSize($creatorId)];
    }

    public static function approvalThresholdBase(int $creatorId): string
    {
        $threshold = (string) self::get($creatorId, self::KEY_APPROVAL_THRESHOLD);

        return is_numeric($threshold) && (float) $threshold >= 0 ? $threshold : '100000';
    }

    /**
     * Approval levels for an acquisition of $amountBase (base
     * currency). Level 1 always; level 2 when the amount reaches
     * the configured threshold. The permission is the same for
     * both levels — the second level must be a DIFFERENT user
     * (enforced by the service at decision time).
     */
    public static function approvalLevels(int $creatorId, string $amountBase): array
    {
        $levels = [
            ['level' => 1, 'min_amount_base' => '0', 'permission' => 'approve fixed asset acquisitions', 'required_votes' => 1],
        ];

        if (bccomp($amountBase, self::approvalThresholdBase($creatorId), 4) >= 0) {
            $levels[] = [
                'level' => 2,
                'min_amount_base' => self::approvalThresholdBase($creatorId),
                'permission' => 'approve fixed asset acquisitions',
                'required_votes' => 1,
            ];
        }

        return $levels;
    }

    /**
     * Normalise + persist one row per key. Unknown keys are
     * ignored; invalid enum values fall back to their default.
     */
    public static function save(int $creatorId, array $input): array
    {
        $values = self::DEFAULTS;

        $currency = strtoupper(trim((string) ($input[self::KEY_BASE_CURRENCY] ?? '')));
        $values[self::KEY_BASE_CURRENCY] = preg_match('/^[A-Z]{3}$/', $currency) === 1
            ? $currency : self::DEFAULTS[self::KEY_BASE_CURRENCY];

        $values[self::KEY_REFERENCE_PREFIX] = substr(trim((string) ($input[self::KEY_REFERENCE_PREFIX] ?? '')), 0, 10);

        // Clearing account: a GL account code (free text, optional).
        $values[self::KEY_CLEARING_ACCOUNT_CODE] = substr(trim((string) ($input[self::KEY_CLEARING_ACCOUNT_CODE] ?? '')), 0, 50);

        // Maintenance expense account: a GL account code (free
        // text). Empty falls back to the default 6226.
        $maintenanceCode = trim((string) ($input[self::KEY_MAINTENANCE_EXPENSE_CODE] ?? ''));
        $values[self::KEY_MAINTENANCE_EXPENSE_CODE] = $maintenanceCode !== ''
            ? substr($maintenanceCode, 0, 50)
            : self::DEFAULTS[self::KEY_MAINTENANCE_EXPENSE_CODE];

        $labelSize = (string) ($input[self::KEY_LABEL_SIZE] ?? '');
        $values[self::KEY_LABEL_SIZE] = in_array($labelSize, self::LABEL_SIZES, true)
            ? $labelSize : self::DEFAULTS[self::KEY_LABEL_SIZE];

        // Approval threshold (D5): a non-negative amount, 2 decimals.
        $threshold = $input[self::KEY_APPROVAL_THRESHOLD] ?? '';
        $values[self::KEY_APPROVAL_THRESHOLD] = is_numeric($threshold) && (float) $threshold >= 0
            ? number_format((float) $threshold, 2, '.', '')
            : self::DEFAULTS[self::KEY_APPROVAL_THRESHOLD];

        foreach ($values as $name => $value) {
            DB::table('settings')->updateOrInsert(
                ['name' => $name, 'created_by' => $creatorId],
                ['value' => $value, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return $values;
    }
}
