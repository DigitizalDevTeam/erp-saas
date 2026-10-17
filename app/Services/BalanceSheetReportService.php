<?php

namespace App\Services;

use App\Services\Accounting\AccountingReportFilterService;
use App\Services\Accounting\LedgerAccountBalanceService;
use App\Support\ChartOfAccountHierarchy;

class BalanceSheetReportService
{
    /**
     * PGC MOZ-style balance sheet lines. Prefixes match account codes and descendants.
     * An account is assigned to the single line with the longest matching prefix,
     * so overlapping prefixes (44 vs 4437) never double count.
     *
     * @var list<array{section: string, label: string, code_label: string, prefixes: list<string>}>
     */
    private const LINE_MAPPINGS = [
        // Assets
        ['section' => 'Assets', 'label' => 'Cash', 'code_label' => '11', 'prefixes' => ['11']],
        ['section' => 'Assets', 'label' => 'Bank deposits', 'code_label' => '12', 'prefixes' => ['12']],
        ['section' => 'Assets', 'label' => 'Other cash equivalents', 'code_label' => '13', 'prefixes' => ['13']],
        ['section' => 'Assets', 'label' => 'Purchases / Inventory', 'code_label' => '21', 'prefixes' => ['21']],
        ['section' => 'Assets', 'label' => 'Goods for resale', 'code_label' => '22', 'prefixes' => ['22']],
        ['section' => 'Assets', 'label' => 'Raw materials & consumables', 'code_label' => '23', 'prefixes' => ['23']],
        ['section' => 'Assets', 'label' => 'Finished & intermediate products', 'code_label' => '24', 'prefixes' => ['24']],
        ['section' => 'Assets', 'label' => 'Other inventories', 'code_label' => '25,26,27,28,29', 'prefixes' => ['25', '26', '27', '28', '29']],
        ['section' => 'Assets', 'label' => 'Tangible fixed assets', 'code_label' => '32', 'prefixes' => ['32']],
        ['section' => 'Assets', 'label' => 'Intangible assets', 'code_label' => '33', 'prefixes' => ['33']],
        ['section' => 'Assets', 'label' => 'Financial investments', 'code_label' => '31,34,35', 'prefixes' => ['31', '34', '35']],
        ['section' => 'Assets', 'label' => 'Clients / Trade receivables', 'code_label' => '41', 'prefixes' => ['41']],
        ['section' => 'Assets', 'label' => 'Other debtors', 'code_label' => '45', 'prefixes' => ['45']],
        ['section' => 'Assets', 'label' => 'VAT recoverable & related', 'code_label' => '4432,4438', 'prefixes' => ['4432', '4438', '4431', '4434', '4439']],
        ['section' => 'Assets', 'label' => 'Deferred tax assets', 'code_label' => '446', 'prefixes' => ['446']],

        // Liabilities
        ['section' => 'Liabilities', 'label' => 'Suppliers', 'code_label' => '42', 'prefixes' => ['42']],
        ['section' => 'Liabilities', 'label' => 'State / Tax authorities', 'code_label' => '44', 'prefixes' => ['44']],
        ['section' => 'Liabilities', 'label' => 'VAT payable', 'code_label' => '4437', 'prefixes' => ['4437']],
        ['section' => 'Liabilities', 'label' => 'Income tax payable', 'code_label' => '441', 'prefixes' => ['441']],
        ['section' => 'Liabilities', 'label' => 'Other creditors', 'code_label' => '46', 'prefixes' => ['46']],
        ['section' => 'Liabilities', 'label' => 'Borrowings & financing', 'code_label' => '25,26', 'prefixes' => ['251', '252', '261', '262']],

        // Equity
        ['section' => 'Equity', 'label' => 'Share capital', 'code_label' => '51', 'prefixes' => ['51']],
        ['section' => 'Equity', 'label' => 'Share premiums & reserves', 'code_label' => '52,55', 'prefixes' => ['52', '55']],
        ['section' => 'Equity', 'label' => 'Revaluation reserves', 'code_label' => '56', 'prefixes' => ['56']],
        ['section' => 'Equity', 'label' => 'Retained earnings / other equity', 'code_label' => '59', 'prefixes' => ['59']],
        // Face of the balanço reports period result under Net results (88). Closing
        // accounts 81/82/83 hold the same result until transferred to 88 — map them
        // here so interim balances are not shown on a separate line.
        ['section' => 'Equity', 'label' => 'Net results', 'code_label' => '88', 'prefixes' => ['81', '82', '83', '88']],
    ];

    /** Lines kept on the face of the report even when the balance is zero. */
    private const ALWAYS_SHOWN_CODES = ['21', '44', '11', '12', '41', '51', '88'];

    private const SECTIONS = ['Assets', 'Liabilities', 'Equity'];

    /**
     * Resolve BS filter. Date-only bookmarks still use full FY 0→15 (opening + close),
     * unlike Trial Balance which treats explicit dates as operational-only.
     *
     * @param  array<string, mixed>  $input
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
    public static function resolveFilter(array $input): array
    {
        return AccountingReportFilterService::resolveWithPreviousYear($input);
    }

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     totals: array<string, array<string, float>>,
     *     filter: array<string, mixed>,
     * }
     */
    public function build(
        int $creatorId,
        string $start,
        string $end,
        ?int $accountingPeriod = null,
        ?int $fiscalYear = null,
        ?int $accountingPeriodFrom = null,
        ?int $accountingPeriodTo = null
    ): array {
        ChartOfAccountHierarchy::backfillLegacyParentLinks($creatorId);

        $fiscalYear = $fiscalYear ?? (int) date('Y', strtotime($end));

        if ($accountingPeriodFrom === null && $accountingPeriodTo === null) {
            if ($accountingPeriod !== null) {
                $accountingPeriodFrom = $accountingPeriod;
                $accountingPeriodTo = $accountingPeriod;
            } else {
                $accountingPeriodFrom = 0;
                $accountingPeriodTo = 15;
            }
        }

        $previousFiscalYear = $fiscalYear - 1;
        $previousRange = TrialBalanceReportService::dateRangeForPeriodSpan(
            $previousFiscalYear,
            $accountingPeriodFrom,
            $accountingPeriodTo
        );

        $ledger = app(LedgerAccountBalanceService::class);
        $current = $this->computeSnapshot($ledger->balancesForSnapshot(
            $creatorId,
            $start,
            $end,
            $accountingPeriodFrom,
            $accountingPeriodTo,
            $fiscalYear
        ));
        $previous = $this->computeSnapshot($ledger->balancesForSnapshot(
            $creatorId,
            $previousRange['start'],
            $previousRange['end'],
            $accountingPeriodFrom,
            $accountingPeriodTo,
            $previousFiscalYear
        ));

        $rows = $this->buildRows($current, $previous);

        $totals = [
            'assets' => [
                'current' => $current['totals']['Assets'],
                'previous' => $previous['totals']['Assets'],
            ],
            'liabilities' => [
                'current' => $current['totals']['Liabilities'],
                'previous' => $previous['totals']['Liabilities'],
            ],
            'equity' => [
                'current' => $current['totals']['Equity'],
                'previous' => $previous['totals']['Equity'],
            ],
            'equity_liabilities' => [
                'current' => round($current['totals']['Liabilities'] + $current['totals']['Equity'], 2),
                'previous' => round($previous['totals']['Liabilities'] + $previous['totals']['Equity'], 2),
            ],
            'difference' => [
                'current' => $current['difference'],
                'previous' => $previous['difference'],
            ],
        ];

        return [
            'rows' => $rows,
            'totals' => $totals,
            'filter' => [
                'startDateRange' => $start,
                'endDateRange' => $end,
                'previousStartDateRange' => $previousRange['start'],
                'previousEndDateRange' => $previousRange['end'],
                'currentYear' => (string) $fiscalYear,
                'previousYear' => (string) $previousFiscalYear,
                'fiscalYear' => $fiscalYear,
                'accountingPeriod' => ($accountingPeriodFrom === $accountingPeriodTo)
                    ? $accountingPeriodFrom
                    : null,
                'accountingPeriodFrom' => $accountingPeriodFrom,
                'accountingPeriodTo' => $accountingPeriodTo,
            ],
        ];
    }

    /**
     * Turn per-account balances into report lines and section totals.
     *
     * Every account lands in exactly one bucket, so section totals always equal the
     * sum of the lines shown underneath them, and Assets = Liabilities + Equity
     * holds whenever the underlying journals balance.
     *
     * @param  list<array{code: string, type: string, debit: float, credit: float}>  $accounts
     * @return array{
     *     lines: array<int, float>,
     *     other: array<string, float>,
     *     unclassified: float,
     *     unclassified_liabilities: float,
     *     net_income: float,
     *     totals: array<string, float>,
     *     difference: float,
     * }
     */
    public function computeSnapshot(array $accounts): array
    {
        $lines = array_fill(0, count(self::LINE_MAPPINGS), 0.0);
        $other = ['Assets' => 0.0, 'Liabilities' => 0.0, 'Equity' => 0.0];
        $unclassified = 0.0;
        $unclassifiedLiabilities = 0.0;
        $income = 0.0;
        $expenses = 0.0;
        $resultsInEquity = 0.0;

        foreach ($accounts as $account) {
            $debit = (float) ($account['debit'] ?? 0);
            $credit = (float) ($account['credit'] ?? 0);
            $code = (string) ($account['code'] ?? '');
            $section = $this->sectionForType((string) ($account['type'] ?? ''));

            switch ($section) {
                case 'Assets':
                    $this->addToSection($lines, $other, 'Assets', $code, $debit - $credit);
                    break;
                case 'Liabilities':
                    $this->addToSection($lines, $other, 'Liabilities', $code, $credit - $debit);
                    break;
                case 'Equity':
                    $equityAmount = $credit - $debit;
                    $this->addToSection($lines, $other, 'Equity', $code, $equityAmount);
                    if ($this->isResultsEquityCode($code)) {
                        $resultsInEquity += $equityAmount;
                    }
                    break;
                case 'Income':
                    $income += $credit - $debit;
                    break;
                case 'Expenses':
                    $expenses += $debit - $credit;
                    break;
                default:
                    $this->routeOrphanBalance($code, $debit, $credit, $other, $unclassified, $unclassifiedLiabilities);
            }
        }

        $netIncome = round($income - $expenses, 2);
        // Avoid double-counting when closings already sit in 81/82/83/88 and the same
        // amount is still visible in Income/Expense. Mid-year prior results + current
        // P&L (different amounts) still add the operational result.
        $addNetIncome = $netIncome;
        if (
            abs($netIncome) > 0.005
            && abs($resultsInEquity) > 0.005
            && abs($netIncome - $resultsInEquity) < 0.02
        ) {
            $addNetIncome = 0.0;
        }

        $sectionSums = [];
        foreach (self::SECTIONS as $section) {
            $sum = $other[$section];
            foreach (self::LINE_MAPPINGS as $index => $mapping) {
                if ($mapping['section'] === $section) {
                    $sum += $lines[$index];
                }
            }
            $sectionSums[$section] = round($sum, 2);
        }

        $sectionSums['Assets'] = round($sectionSums['Assets'] + $unclassified, 2);
        $sectionSums['Liabilities'] = round($sectionSums['Liabilities'] + $unclassifiedLiabilities, 2);
        $sectionSums['Equity'] = round($sectionSums['Equity'] + $addNetIncome, 2);

        return [
            'lines' => $lines,
            'other' => $other,
            'unclassified' => round($unclassified, 2),
            'unclassified_liabilities' => round($unclassifiedLiabilities, 2),
            'net_income' => $addNetIncome,
            'totals' => $sectionSums,
            'difference' => round($sectionSums['Assets'] - ($sectionSums['Liabilities'] + $sectionSums['Equity']), 2),
        ];
    }

    /**
     * @param  array{lines: array<int, float>, other: array<string, float>, unclassified: float, net_income: float}  $current
     * @param  array{lines: array<int, float>, other: array<string, float>, unclassified: float, net_income: float}  $previous
     * @return list<array<string, mixed>>
     */
    private function buildRows(array $current, array $previous): array
    {
        $rows = [];

        foreach (self::LINE_MAPPINGS as $index => $mapping) {
            $currentValue = round($current['lines'][$index], 2);
            $previousValue = round($previous['lines'][$index], 2);

            $alwaysShow = in_array($mapping['code_label'], self::ALWAYS_SHOWN_CODES, true);
            if (! $alwaysShow && ! $this->hasBalance($currentValue) && ! $this->hasBalance($previousValue)) {
                continue;
            }

            $rows[] = [
                'section' => $mapping['section'],
                'label' => $mapping['label'],
                'code_label' => $mapping['code_label'],
                'current_year' => $currentValue,
                'previous_year' => $previousValue,
            ];
        }

        $extra = [
            ['section' => 'Assets', 'label' => 'Other assets', 'code_label' => '', 'key' => 'other.Assets'],
            ['section' => 'Assets', 'label' => 'Unclassified / deleted accounts', 'code_label' => '', 'key' => 'unclassified'],
            ['section' => 'Liabilities', 'label' => 'Other liabilities', 'code_label' => '', 'key' => 'other.Liabilities'],
            ['section' => 'Liabilities', 'label' => 'Unclassified / deleted accounts', 'code_label' => '', 'key' => 'unclassified_liabilities'],
            ['section' => 'Equity', 'label' => 'Other equity', 'code_label' => '', 'key' => 'other.Equity'],
            ['section' => 'Equity', 'label' => 'Current Period Result', 'code_label' => '', 'key' => 'net_income'],
        ];

        foreach ($extra as $definition) {
            $currentValue = round($this->snapshotValue($current, $definition['key']), 2);
            $previousValue = round($this->snapshotValue($previous, $definition['key']), 2);

            if (! $this->hasBalance($currentValue) && ! $this->hasBalance($previousValue)) {
                continue;
            }

            $rows[] = [
                'section' => $definition['section'],
                'label' => $definition['label'],
                'code_label' => $definition['code_label'],
                'current_year' => $currentValue,
                'previous_year' => $previousValue,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function snapshotValue(array $snapshot, string $key): float
    {
        if (str_starts_with($key, 'other.')) {
            return (float) ($snapshot['other'][substr($key, 6)] ?? 0);
        }

        return (float) ($snapshot[$key] ?? 0);
    }

    /**
     * Add a balance to the best-matching line of a section, or to that section's
     * catch-all bucket when no mapping claims the code.
     *
     * @param  array<int, float>  $lines
     * @param  array<string, float>  $other
     */
    private function addToSection(array &$lines, array &$other, string $section, string $code, float $amount): void
    {
        $bestIndex = null;
        $bestLength = -1;

        foreach (self::LINE_MAPPINGS as $index => $mapping) {
            if ($mapping['section'] !== $section) {
                continue;
            }

            foreach ($mapping['prefixes'] as $prefix) {
                if ($code !== $prefix && ! str_starts_with($code, $prefix)) {
                    continue;
                }
                if (strlen($prefix) > $bestLength) {
                    $bestLength = strlen($prefix);
                    $bestIndex = $index;
                }
            }
        }

        if ($bestIndex === null) {
            $other[$section] += $amount;

            return;
        }

        $lines[$bestIndex] += $amount;
    }

    /**
     * Map a chart-of-account type name onto a balance sheet section.
     * Result accounts (81–88) belong to equity once the year is closed.
     */
    private function sectionForType(string $typeName): string
    {
        return match (strtolower(trim($typeName))) {
            'assets', 'asset' => 'Assets',
            'liabilities', 'liability' => 'Liabilities',
            'equity', 'capital', 'equity and liabilities', 'results', 'result' => 'Equity',
            'income', 'revenue' => 'Income',
            'expenses', 'expense', 'costs of goods sold', 'cost of goods sold', 'cogs' => 'Expenses',
            default => 'Unclassified',
        };
    }

    /** Liability code prefixes for orphan routing when type metadata is missing. */
    private const LIABILITY_CODE_PREFIXES = ['42', '44', '46', '251', '252', '261', '262'];

    /** Equity code prefixes for orphan routing when type metadata is missing. */
    private const EQUITY_CODE_PREFIXES = ['51', '52', '55', '56', '59', '81', '82', '83', '88'];

    /**
     * Route deleted/untyped balances to the correct BS section.
     *
     * @param  array<string, float>  $other
     */
    private function routeOrphanBalance(
        string $code,
        float $debit,
        float $credit,
        array &$other,
        float &$unclassified,
        float &$unclassifiedLiabilities
    ): void {
        $net = $debit - $credit;

        if ($this->codeStartsWithAny($code, self::LIABILITY_CODE_PREFIXES)) {
            $other['Liabilities'] += $credit - $debit;

            return;
        }

        if ($this->codeStartsWithAny($code, self::EQUITY_CODE_PREFIXES)) {
            $other['Equity'] += $credit - $debit;

            return;
        }

        if ($credit > $debit) {
            $unclassifiedLiabilities += $credit - $debit;

            return;
        }

        $unclassified += $net;
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function codeStartsWithAny(string $code, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($code === $prefix || str_starts_with($code, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function hasBalance(float $amount): bool
    {
        return abs($amount) > 0.005;
    }

    /** Result / closing codes that already hold period result once the year is closed. */
    private function isResultsEquityCode(string $code): bool
    {
        foreach (['81', '82', '83', '88'] as $prefix) {
            if ($code === $prefix || str_starts_with($code, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
