<?php

namespace App\Services;

use App\Models\User;
use App\Models\Utility;

/**
 * Money formatting for the management reports ONLY.
 *
 * The file (PnL2026_Baseline.xlsx) shows 2 decimals (213.40); the
 * app's global priceFormat() uses the site's `decimal_number`
 * setting (0 on a fresh install — "213"). The global format is NOT
 * changed: this mirrors priceFormat() exactly (same separators,
 * currency symbol and position) with the decimal count forced to 2.
 */
class ManagementMoney
{
    public static function format(User $user, float $value): string
    {
        $number = explode('.', (string) $value);
        $length = strlen(trim($number[0]));
        $float_number = Utility::getValByName('float_number') == 'dot' ? '.' : ',';

        if ($length > 3) {
            $decimal_separator = Utility::getValByName('decimal_separator') == 'dot' ? ',' : ',';
            $thousand_separator = Utility::getValByName('thousand_separator') == 'dot' ? '.' : ',';
        } else {
            $decimal_separator = Utility::getValByName('decimal_separator') == 'dot' ? '.' : ',';
            $thousand_separator = Utility::getValByName('thousand_separator') == 'dot' ? '.' : ',';
        }

        $currency = Utility::getValByName('currency_symbol') == 'withcurrencysymbol' ? Utility::getValByName('site_currency_symbol') : Utility::getValByName('site_currency');
        $settings = Utility::settings();
        $currency_space = Utility::getValByName('currency_space');
        $price = number_format($value, 2, $decimal_separator, $thousand_separator);

        if ($float_number == 'dot') {
            $price = preg_replace('/' . preg_quote($thousand_separator, '/') . '([^' . preg_quote($thousand_separator, '/') . ']*)$/', $float_number . '$1', $price);
        } else {
            $price = preg_replace('/' . preg_quote($decimal_separator, '/') . '([^' . preg_quote($decimal_separator, '/') . ']*)$/', $float_number . '$1', $price);
        }

        return (($settings['site_currency_symbol_position'] == 'pre') ? $currency : '') . ($currency_space == 'withspace' ? ' ' : '') . $price . ($currency_space == 'withspace' ? ' ' : '') . (($settings['site_currency_symbol_position'] == 'post') ? $currency : '');
    }
}
