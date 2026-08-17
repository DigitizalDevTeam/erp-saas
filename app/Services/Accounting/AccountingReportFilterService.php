<?php

namespace App\Services\Accounting;

use App\Services\TrialBalanceReportService;

/**
 * Shared fiscal-year / accounting-period filter for Trial Balance and Balance Sheet.
 */
class AccountingReportFilterService
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     start: string,
     *     end: string,
     *     fiscalYear: int,
     *     accountingPeriod: int|null,
     *     accountingPeriodFrom: int|null,
     *     accountingPeriodTo: int|null
     * }
     */
    public static function resolve(array $input): array
    {
        $resolved = TrialBalanceReportService::resolveFilter($input);
        [$periodFrom, $periodTo] = TrialBalanceReportService::normalizePeriodRange($input);

        if ($periodFrom === null || $periodTo === null) {
            $periodFrom = 0;
            $periodTo = 15;
            $range = TrialBalanceReportService::dateRangeForPeriodSpan($resolved['fiscalYear'], $periodFrom, $periodTo);
            $resolved['start'] = $range['start'];
            $resolved['end'] = $range['end'];
            $resolved['accountingPeriodFrom'] = $periodFrom;
            $resolved['accountingPeriodTo'] = $periodTo;
            $resolved['accountingPeriod'] = null;
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array{
     *     start: string,
     *     end: string,
     *     fiscalYear: int,
     *     accountingPeriod: int|null,
     *     accountingPeriodFrom: int,
     *     accountingPeriodTo: int,
     *     previousStart: string,
     *     previousEnd: string,
     *     previousFiscalYear: int
     * }
     */
    public static function resolveWithPreviousYear(array $base): array
    {
        $resolved = self::resolve($base);
        $fiscalYear = $resolved['fiscalYear'];
        $periodFrom = $resolved['accountingPeriodFrom'] ?? 0;
        $periodTo = $resolved['accountingPeriodTo'] ?? 15;
        $previousFiscalYear = $fiscalYear - 1;
        $previousRange = TrialBalanceReportService::dateRangeForPeriodSpan($previousFiscalYear, $periodFrom, $periodTo);

        return [
            'start' => $resolved['start'],
            'end' => $resolved['end'],
            'fiscalYear' => $fiscalYear,
            'accountingPeriod' => $resolved['accountingPeriod'],
            'accountingPeriodFrom' => $periodFrom,
            'accountingPeriodTo' => $periodTo,
            'previousStart' => $previousRange['start'],
            'previousEnd' => $previousRange['end'],
            'previousFiscalYear' => $previousFiscalYear,
        ];
    }
}
