<?php

namespace App\Services\Accounting\BankReconciliation;

class AmountNormalizer
{
    /**
     * Parse amounts like "-1.000,00 MZN", "1,234.56", "(100.00)".
     */
    public function parse(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }

        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }

        $negative = false;
        if (preg_match('/^\((.*)\)$/', $s, $m)) {
            $negative = true;
            $s = $m[1];
        }

        $s = preg_replace('/\b(MZN|USD|EUR|ZAR|MZN)\b/iu', '', $s);
        $s = str_replace('+', '', $s);
        $s = trim($s);

        if ($s === '' || $s === '-') {
            return null;
        }

        if (str_starts_with($s, '-')) {
            $negative = ! $negative;
            $s = ltrim(substr($s, 1));
        }

        $s = preg_replace('/[^\d,.\-]/', '', $s);
        $s = str_replace('-', '', $s);

        if ($s === '') {
            return null;
        }

        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($lastComma !== false && $lastDot === false) {
            $parts = explode(',', $s);
            if (count($parts) === 2 && strlen($parts[1]) <= 2) {
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } else {
            $s = str_replace(',', '', $s);
        }

        if (! is_numeric($s)) {
            return null;
        }

        $v = (float) $s;

        return $negative ? -$v : $v;
    }
}
