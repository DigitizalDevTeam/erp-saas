<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Services\Accounting\LedgerAccountBalanceService;
use App\Support\ChartOfAccountHierarchy;
use Illuminate\Support\Collection;

class TrialBalanceReportService
{
    /**
     * @return array<int|string, string>
     */
    public static function accountingPeriodOptions(): array
    {
        return [
            '' => __('All operational (Jan–Dec)'),
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

    /**
     * Month / period options for range selectors (0–15 only, no “all”).
     *
     * @return array<int, string>
     */
    public static function accountingPeriodRangeOptions(): array
    {
        $options = self::accountingPeriodOptions();
        unset($options['']);

        return $options;
    }

    /**
     * Default TB calendar window for a fiscal year (1 Jan–31 Dec).
     * Period filter defaults to 0–15 (opening + operational + year-end closing).
     *
     * @return array{start: string, end: string}
     */
    public static function defaultDateRange(?int $year = null): array
    {
        $year = $year ?? (int) date('Y');

        return [
            'start' => sprintf('%04d-01-01', $year),
            'end' => sprintf('%04d-12-31', $year),
        ];
    }

    /**
     * Year-to-date through today (excludes future-dated closing entries).
     *
     * @return array{start: string, end: string}
     */
    public static function yearToDateRange(?int $year = null): array
    {
        $year = $year ?? (int) date('Y');
        $today = date('Y-m-d');
        $end = str_starts_with($today, (string) $year) ? $today : sprintf('%04d-12-31', $year);

        return [
            'start' => sprintf('%04d-01-01', $year),
            'end' => $end,
        ];
    }

    /**
     * @return array{start: string, end: string}
     */
    public static function dateRangeForMonth(int $year, int $month): array
    {
        $month = max(1, min(12, $month));
        $start = sprintf('%04d-%02d-01', $year, $month);

        return [
            'start' => $start,
            'end' => date('Y-m-t', strtotime($start)),
        ];
    }

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
    public static function resolveFilter(array $input): array
    {
        $fiscalYear = isset($input['fiscal_year']) && $input['fiscal_year'] !== ''
            ? (int) $input['fiscal_year']
            : (int) date('Y');

        if (! empty($input['start_date'])) {
            $fiscalYear = (int) date('Y', strtotime((string) $input['start_date']));
        }

        [$periodFrom, $periodTo] = self::normalizePeriodRange($input);

        // Fiscal year alone (no month range): include opening (0) + months 1–12 + closing (13–15),
        // so year-end accounts 81/82/83 appear the same as an explicit 0→15 filter.
        // YTD and explicit date ranges keep period null (= operational journals only).
        if ($periodFrom === null && $periodTo === null
            && ($input['range'] ?? '') !== 'ytd'
            && empty($input['start_date'])
            && empty($input['end_date'])
        ) {
            $periodFrom = 0;
            $periodTo = 15;
        }

        if ($periodFrom !== null && $periodTo !== null) {
            $range = self::dateRangeForPeriodSpan($fiscalYear, $periodFrom, $periodTo);
            $start = $range['start'];
            $end = $range['end'];
        } elseif (! empty($input['start_date']) && ! empty($input['end_date'])) {
            $start = (string) $input['start_date'];
            $end = (string) $input['end_date'];
        } elseif (($input['range'] ?? '') === 'ytd') {
            $range = self::yearToDateRange($fiscalYear);
            $start = $range['start'];
            $end = $range['end'];
        } else {
            $range = self::defaultDateRange($fiscalYear);
            $start = $range['start'];
            $end = $range['end'];
        }

        return [
            'start' => $start,
            'end' => $end,
            'fiscalYear' => $fiscalYear,
            'accountingPeriod' => ($periodFrom !== null && $periodFrom === $periodTo) ? $periodFrom : null,
            'accountingPeriodFrom' => $periodFrom,
            'accountingPeriodTo' => $periodTo,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: int|null, 1: int|null}
     */
    public static function normalizePeriodRange(array $input): array
    {
        $fromRaw = $input['accounting_period_from'] ?? null;
        $toRaw = $input['accounting_period_to'] ?? null;
        $legacy = $input['accounting_period'] ?? null;

        $from = ($fromRaw !== '' && $fromRaw !== null) ? (int) $fromRaw : null;
        $to = ($toRaw !== '' && $toRaw !== null) ? (int) $toRaw : null;

        if ($from === null && $to === null && $legacy !== '' && $legacy !== null) {
            $from = $to = (int) $legacy;
        }

        if ($from === null && $to !== null) {
            $from = $to;
        }
        if ($to === null && $from !== null) {
            $to = $from;
        }

        if ($from === null || $to === null) {
            return [null, null];
        }

        $from = max(0, min(15, $from));
        $to = max(0, min(15, $to));

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    /**
     * @return array{start: string, end: string}
     */
    public static function dateRangeForPeriodSpan(int $year, int $periodFrom, int $periodTo): array
    {
        $ops = [];
        for ($p = $periodFrom; $p <= $periodTo; $p++) {
            if ($p >= 1 && $p <= 12) {
                $ops[] = $p;
            }
        }

        if ($ops !== []) {
            $first = min($ops);
            $last = max($ops);
            $startRange = self::dateRangeForMonth($year, $first);
            $endRange = self::dateRangeForMonth($year, $last);

            return [
                'start' => $startRange['start'],
                'end' => $endRange['end'],
            ];
        }

        return [
            'start' => sprintf('%04d-01-01', $year),
            'end' => sprintf('%04d-12-31', $year),
        ];
    }

    /**
     * Build trial balance rows grouped by account type.
     *
     * @return array{
     *     totalAccounts: array<string, list<array<string, mixed>>>,
     *     grandTotalDebit: float,
     *     grandTotalCredit: float,
     *     filter: array{
     *         startDateRange: string,
     *         endDateRange: string,
     *         fiscalYear: int,
     *         accountingPeriod: int|null,
     *         accountingPeriodFrom: int|null,
     *         accountingPeriodTo: int|null
     *     }
     * }
     */
    public function build(
        int $creatorId,
        string $start,
        string $end,
        string $view = 'expand',
        ?int $accountingPeriod = null,
        ?int $fiscalYear = null,
        ?int $accountingPeriodFrom = null,
        ?int $accountingPeriodTo = null
    ): array {
        ChartOfAccountHierarchy::backfillLegacyParentLinks($creatorId);

        $fiscalYear = $fiscalYear ?? (int) date('Y', strtotime($start));

        if ($accountingPeriodFrom === null && $accountingPeriodTo === null && $accountingPeriod !== null) {
            $accountingPeriodFrom = $accountingPeriod;
            $accountingPeriodTo = $accountingPeriod;
        }

        $ledger = app(LedgerAccountBalanceService::class);
        $balances = $ledger->balancesByAccountId($creatorId, $start, $end, $accountingPeriodFrom, $accountingPeriodTo, $fiscalYear);
        $grandTotals = $ledger->grandTotals($creatorId, $start, $end, $accountingPeriodFrom, $accountingPeriodTo, $fiscalYear);

        // Same ordering as Chart of Accounts (PGC / CSV code order), not grouped by account type.
        $tree = ChartOfAccountHierarchy::buildMasterTree($creatorId);
        $rows = [];
        $seenAccountIds = [];
        foreach ($tree as $root) {
            $result = $this->rowsForNode($root, $balances, $view, 0, $seenAccountIds);
            $rows = array_merge($rows, $result['rows']);
        }

        $orphanRows = $this->orphanBalanceRows($balances, $seenAccountIds, $creatorId);
        if ($orphanRows !== []) {
            $rows = array_merge($rows, $orphanRows);
        }

        $sections = $rows !== [] ? ['' => $rows] : [];

        return [
            'totalAccounts' => $sections,
            'grandTotalDebit' => $grandTotals['totalDebit'],
            'grandTotalCredit' => $grandTotals['totalCredit'],
            'filter' => [
                'startDateRange' => $start,
                'endDateRange' => $end,
                'fiscalYear' => $fiscalYear,
                'accountingPeriod' => ($accountingPeriodFrom !== null && $accountingPeriodFrom === $accountingPeriodTo)
                    ? $accountingPeriodFrom
                    : null,
                'accountingPeriodFrom' => $accountingPeriodFrom,
                'accountingPeriodTo' => $accountingPeriodTo,
            ],
        ];
    }

    /**
     * @param  array<int, array{totalDebit: float, totalCredit: float}>  $balances
     * @param  array<int, true>  $seenAccountIds
     * @return array{rows: list<array<string, mixed>>, subtreeDebit: float, subtreeCredit: float}
     */
    private function rowsForNode(
        ChartOfAccount $node,
        array $balances,
        string $view,
        int $depth,
        array &$seenAccountIds
    ): array {
        $accountId = (int) $node->id;
        $seenAccountIds[$accountId] = true;
        $direct = $balances[$accountId] ?? ['totalDebit' => 0.0, 'totalCredit' => 0.0];

        /** @var Collection<int, ChartOfAccount> $children */
        $children = $node->tree_children ?? collect();

        $childRows = [];
        $childDebit = 0.0;
        $childCredit = 0.0;

        foreach ($children as $child) {
            $childResult = $this->rowsForNode($child, $balances, $view, $depth + 1, $seenAccountIds);
            if ($childResult['rows'] === []) {
                continue;
            }
            $childRows = array_merge($childRows, $childResult['rows']);
            $childDebit += $childResult['subtreeDebit'];
            $childCredit += $childResult['subtreeCredit'];
        }

        $subtreeDebit = $direct['totalDebit'] + $childDebit;
        $subtreeCredit = $direct['totalCredit'] + $childCredit;

        if (! $this->hasActivity($subtreeDebit, $subtreeCredit)) {
            return ['rows' => [], 'subtreeDebit' => 0.0, 'subtreeCredit' => 0.0];
        }

        $rows = [];
        $hasActiveChildren = $childRows !== [];
        $hasDirect = $this->hasActivity($direct['totalDebit'], $direct['totalCredit']);

        if ($hasActiveChildren) {
            if ($view !== 'collapse' || $depth === 0) {
                $rows[] = $this->makeRow($node, 'parent', $subtreeDebit, $subtreeCredit);
            }

            if ($view === 'expand') {
                if ($hasDirect) {
                    $own = $this->makeRow($node, 'subAccount', $direct['totalDebit'], $direct['totalCredit']);
                    $own['account_name'] = $node->labelForLocale() . ' (' . __('own movements') . ')';
                    $rows[] = $own;
                }
                $rows = array_merge($rows, $childRows);
            }
        } elseif ($hasDirect) {
            $role = $depth === 0 ? '' : 'subAccount';
            $rows[] = $this->makeRow($node, $role, $direct['totalDebit'], $direct['totalCredit']);
        }

        return [
            'rows' => $rows,
            'subtreeDebit' => $subtreeDebit,
            'subtreeCredit' => $subtreeCredit,
        ];
    }

    /**
     * Balances whose account id is not in this company's chart tree (deleted or
     * unmapped foreign ids after remap). Surface them so TB matches BS.
     *
     * @param  array<int, array{totalDebit: float, totalCredit: float}>  $balances
     * @param  array<int, true>  $seenAccountIds
     * @return list<array<string, mixed>>
     */
    private function orphanBalanceRows(array $balances, array $seenAccountIds, int $creatorId): array
    {
        $orphanIds = [];
        foreach ($balances as $accountId => $amounts) {
            $accountId = (int) $accountId;
            if (isset($seenAccountIds[$accountId])) {
                continue;
            }
            if (! $this->hasActivity($amounts['totalDebit'], $amounts['totalCredit'])) {
                continue;
            }
            $orphanIds[] = $accountId;
        }

        if ($orphanIds === []) {
            return [];
        }

        $coaById = ChartOfAccount::query()
            ->where('created_by', $creatorId)
            ->whereIn('id', $orphanIds)
            ->get()
            ->keyBy('id');

        $rows = [];
        foreach ($orphanIds as $accountId) {
            $amounts = $balances[$accountId];
            $coa = $coaById->get($accountId);
            if ($coa) {
                $rows[] = $this->makeRow($coa, 'orphan', $amounts['totalDebit'], $amounts['totalCredit']);
                continue;
            }

            $normalized = $this->normalizeAmounts($amounts['totalDebit'], $amounts['totalCredit']);
            $rows[] = [
                'account_id' => $accountId,
                'account_code' => '',
                'account_name' => __('Unclassified / deleted accounts') . ' #' . $accountId,
                'account' => 'orphan',
                'totalDebit' => $normalized['totalDebit'],
                'totalCredit' => $normalized['totalCredit'],
                'balanceDebit' => $normalized['totalDebit'] > $normalized['totalCredit']
                    ? $normalized['totalDebit'] - $normalized['totalCredit']
                    : 0.0,
                'balanceCredit' => $normalized['totalCredit'] > $normalized['totalDebit']
                    ? $normalized['totalCredit'] - $normalized['totalDebit']
                    : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function makeRow(ChartOfAccount $account, string $role, float $totalDebit, float $totalCredit): array
    {
        $amounts = $this->normalizeAmounts($totalDebit, $totalCredit);

        return [
            'account_id' => (int) $account->id,
            'account_code' => (string) ($account->code ?? ''),
            'account_name' => $account->labelForLocale(),
            'account' => $role,
            'totalDebit' => $amounts['totalDebit'],
            'totalCredit' => $amounts['totalCredit'],
            'balanceDebit' => $amounts['totalDebit'] > $amounts['totalCredit']
                ? $amounts['totalDebit'] - $amounts['totalCredit']
                : 0.0,
            'balanceCredit' => $amounts['totalCredit'] > $amounts['totalDebit']
                ? $amounts['totalCredit'] - $amounts['totalDebit']
                : 0.0,
        ];
    }

    /**
     * @return array{totalDebit: float, totalCredit: float}
     */
    private function normalizeAmounts(float $totalDebit, float $totalCredit): array
    {
        if ($totalDebit < 0) {
            $totalCredit += abs($totalDebit);
            $totalDebit = 0.0;
        }

        if ($totalCredit < 0) {
            $totalDebit += abs($totalCredit);
            $totalCredit = 0.0;
        }

        return [
            'totalDebit' => round($totalDebit, 2),
            'totalCredit' => round($totalCredit, 2),
        ];
    }

    /**
     * True when the account has any gross movement (even if debit == credit).
     * Clearing accounts 81/82/83 must remain visible after year-end close.
     */
    private function hasActivity(float $totalDebit, float $totalCredit): bool
    {
        return abs($totalDebit) > 0.00001 || abs($totalCredit) > 0.00001;
    }
}
