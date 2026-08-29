<?php

namespace App\Support;

use App\Models\Utility;

class MoneyAmount
{
    /**
     * Parse locale-aware monetary input and round to 2 decimal places.
     */
    public static function parse(mixed $value, ?array $settings = null): float
    {
        if (is_int($value) || is_float($value)) {
            return round(max(0, (float) $value), 2);
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return 0.0;
        }

        $normalized = preg_replace('/[^\d.,\-+]/', '', $normalized) ?? '';
        if ($normalized === '' || $normalized === '-' || $normalized === '+') {
            return 0.0;
        }

        $hasComma = str_contains($normalized, ',');
        $hasDot = str_contains($normalized, '.');

        if ($hasComma && ! $hasDot) {
            $normalized = str_replace(',', '.', $normalized);
        } elseif ($hasComma && $hasDot) {
            $settings = $settings ?? Utility::settings();
            $decimalSep = ($settings['decimal_separator'] ?? 'dot') === 'dot' ? '.' : ',';
            $thousandSep = ($settings['thousand_separator'] ?? 'dot') === 'dot' ? '.' : ',';

            if ($decimalSep === ',') {
                $normalized = str_replace($thousandSep, '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } else {
                $normalized = str_replace($thousandSep, '', $normalized);
            }
        }

        return round(max(0, (float) $normalized), 2);
    }

    public static function equals(float $a, float $b): bool
    {
        return abs($a - $b) < 0.01;
    }
}
