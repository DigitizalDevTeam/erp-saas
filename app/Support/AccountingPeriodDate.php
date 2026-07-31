<?php

namespace App\Support;

class AccountingPeriodDate
{
    /**
     * @return array<int, string>
     */
    public static function monthOptions(): array
    {
        return [
            0 => __('0 — Opening balances'),
            1 => __('1 — January'),
            2 => __('2 — February'),
            3 => __('3 — March'),
            4 => __('4 — April'),
            5 => __('5 — May'),
            6 => __('6 — June'),
            7 => __('7 — July'),
            8 => __('8 — August'),
            9 => __('9 — September'),
            10 => __('10 — October'),
            11 => __('11 — November'),
            12 => __('12 — December'),
            13 => __('13 — Year-end adjustments'),
            14 => __('14 — Post-audit'),
            15 => __('15 — Final closing'),
        ];
    }

    public static function isSpecialPeriod(int $period): bool
    {
        return $period === 0 || ($period >= 13 && $period <= 15);
    }

    /**
     * Days allowed for the selected accounting month (0–15).
     * Special periods 0, 13, 14, 15 always allow 1–31.
     */
    public static function daysInPeriod(int $year, int $period): int
    {
        if (self::isSpecialPeriod($period)) {
            return 31;
        }

        $period = max(1, min(12, $period));

        return (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $period)));
    }

    /**
     * Build a valid calendar date for storage + set accounting_period separately.
     * Special periods use Jan (0) or Dec (13–15) so the fiscal year stays correct.
     */
    public static function composeDate(int $year, int $period, int $day): string
    {
        $year = max(2000, min(2100, $year));
        $period = max(0, min(15, $period));
        $maxDay = self::daysInPeriod($year, $period);
        $day = max(1, min($maxDay, $day));

        if ($period >= 1 && $period <= 12) {
            return sprintf('%04d-%02d-%02d', $year, $period, $day);
        }

        $calendarMonth = $period === 0 ? 1 : 12;

        return sprintf('%04d-%02d-%02d', $year, $calendarMonth, $day);
    }

    /**
     * @return array{year: int, month: int, day: int}
     */
    public static function parse(?string $date, $accountingPeriod = null): array
    {
        $timestamp = $date ? strtotime($date) : false;
        $year = $timestamp ? (int) date('Y', $timestamp) : (int) date('Y');
        $day = $timestamp ? (int) date('j', $timestamp) : (int) date('j');

        if ($accountingPeriod !== null && $accountingPeriod !== '') {
            $month = (int) $accountingPeriod;
        } elseif ($timestamp) {
            $month = (int) date('n', $timestamp);
        } else {
            $month = (int) date('n');
        }

        $month = max(0, min(15, $month));
        $day = max(1, min(self::daysInPeriod($year, $month), $day));

        return [
            'year' => $year,
            'month' => $month,
            'day' => $day,
        ];
    }

    /**
     * Resolve request fields into date + accounting_period.
     *
     * @param  array<string, mixed>  $input
     * @return array{date: string, accounting_period: int}
     */
    public static function fromRequest(array $input): array
    {
        $year = isset($input['journal_year']) && $input['journal_year'] !== ''
            ? (int) $input['journal_year']
            : (int) date('Y');
        $month = isset($input['journal_month']) && $input['journal_month'] !== ''
            ? (int) $input['journal_month']
            : (isset($input['accounting_period']) && $input['accounting_period'] !== ''
                ? (int) $input['accounting_period']
                : (int) date('n'));
        $day = isset($input['journal_day']) && $input['journal_day'] !== ''
            ? (int) $input['journal_day']
            : (int) date('j');

        $month = max(0, min(15, $month));

        return [
            'date' => self::composeDate($year, $month, $day),
            'accounting_period' => $month,
        ];
    }
}
