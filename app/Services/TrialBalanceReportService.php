<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountType;
use App\Models\TransactionLines;
use App\Support\ChartOfAccountHierarchy;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TrialBalanceReportService
{
    /**
     * @return array<int|string, string>
     */
    public static function accountingPeriodOptions(): array
    {
        return [
            '' => __('All operational (Jan–Dec)'),
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
     * Default TB window: operational months only (excludes periods 13–15).
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
     *     accountingPeriod: int|null
     * }
     */
    public static function resolveFilter(array $input): array
    {
        $fiscalYear = isset($input['fiscal_year']) && $input['fiscal_year'] !== ''
            ? (int) $input['fiscal_year']
            : (int) date('Y');

        $accountingPeriod = null;
        if (isset($input['accounting_period']) && $input['accounting_period'] !== '' && $input['accounting_period'] !== null) {
            $accountingPeriod = (int) $input['accounting_period'];
        }

        if (! empty($input['start_date'])) {
            $fiscalYear = (int) date('Y', strtotime((string) $input['start_date']));
        }

        if ($accountingPeriod !== null && $accountingPeriod >= 1 && $accountingPeriod <= 12) {
            $range = self::dateRangeForMonth($fiscalYear, $accountingPeriod);
            $start = $range['start'];
            $end = $range['end'];
        } elseif ($accountingPeriod !== null && $accountingPeriod >= 13) {
            $start = sprintf('%04d-01-01', $fiscalYear);
            $end = sprintf('%04d-12-31', $fiscalYear);
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
            'accountingPeriod' => $accountingPeriod,
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
     *         accountingPeriod: int|null
     *     }
     * }
     */
    public function build(
        int $creatorId,
        string $start,
        string $end,
        string $view = 'expand',
        ?int $accountingPeriod = null,
        ?int $fiscalYear = null
    ): array {
        ChartOfAccountHierarchy::backfillLegacyParentLinks($creatorId);

        $fiscalYear = $fiscalYear ?? (int) date('Y', strtotime($start));
        $balances = $this->accountBalances($creatorId, $start, $end, $accountingPeriod, $fiscalYear);
        $grandTotals = $this->grandTotals($creatorId, $start, $end, $accountingPeriod, $fiscalYear);

        $sections = [];
        $types = ChartOfAccountType::where('created_by', $creatorId)->orderBy('id')->get();

        foreach ($types as $type) {
            $accounts = ChartOfAccountHierarchy::accountsForCreator($creatorId, (int) $type->id);
            if ($accounts->isEmpty()) {
                continue;
            }

            $tree = ChartOfAccountHierarchy::buildTree($accounts);
            $rows = [];

            foreach ($tree as $root) {
                $result = $this->rowsForNode($root, $balances, $view, 0);
                $rows = array_merge($rows, $result['rows']);
            }

            if ($rows !== []) {
                $sections[$type->name] = $rows;
            }
        }

        return [
            'totalAccounts' => $sections,
            'grandTotalDebit' => $grandTotals['totalDebit'],
            'grandTotalCredit' => $grandTotals['totalCredit'],
            'filter' => [
                'startDateRange' => $start,
                'endDateRange' => $end,
                'fiscalYear' => $fiscalYear,
                'accountingPeriod' => $accountingPeriod,
            ],
        ];
    }

    /**
     * @return array<int, array{totalDebit: float, totalCredit: float}>
     */
    private function accountBalances(
        int $creatorId,
        string $start,
        string $end,
        ?int $accountingPeriod,
        int $fiscalYear
    ): array {
        $query = TransactionLines::query()
            ->select(
                'account_id',
                DB::raw('SUM(debit) as totalDebit'),
                DB::raw('SUM(credit) as totalCredit')
            )
            ->where('created_by', $creatorId)
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end);

        $this->applyTransactionLinePeriodFilter($query, $creatorId, $start, $end, $accountingPeriod, $fiscalYear);

        $rows = $query->groupBy('account_id')->get();

        $balances = [];
        foreach ($rows as $row) {
            $normalized = $this->normalizeAmounts(
                (float) $row->totalDebit,
                (float) $row->totalCredit
            );
            if ($this->hasActivity($normalized['totalDebit'], $normalized['totalCredit'])) {
                $balances[(int) $row->account_id] = $normalized;
            }
        }

        $journalOnly = DB::table('journal_items as ji')
            ->join('journal_entries as je', 'ji.journal', '=', 'je.id')
            ->where('je.created_by', $creatorId)
            ->whereDate('je.date', '>=', $start)
            ->whereDate('je.date', '<=', $end)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('transaction_lines as tl')
                    ->whereColumn('tl.reference_id', 'je.id')
                    ->where('tl.reference', 'Journal');
            });

        $this->applyJournalEntryPeriodFilter($journalOnly, $accountingPeriod, $fiscalYear);

        $journalOnlyRows = $journalOnly
            ->groupBy('ji.account')
            ->select(
                'ji.account as account_id',
                DB::raw('SUM(ji.debit) as totalDebit'),
                DB::raw('SUM(ji.credit) as totalCredit')
            )
            ->get();

        foreach ($journalOnlyRows as $row) {
            $accountId = (int) $row->account_id;
            $normalized = $this->normalizeAmounts(
                (float) $row->totalDebit,
                (float) $row->totalCredit
            );
            if (! $this->hasActivity($normalized['totalDebit'], $normalized['totalCredit'])) {
                continue;
            }
            if (! isset($balances[$accountId])) {
                $balances[$accountId] = $normalized;
            } else {
                $balances[$accountId] = $this->normalizeAmounts(
                    $balances[$accountId]['totalDebit'] + $normalized['totalDebit'],
                    $balances[$accountId]['totalCredit'] + $normalized['totalCredit']
                );
            }
        }

        return $balances;
    }

    /**
     * @return array{totalDebit: float, totalCredit: float}
     */
    private function grandTotals(
        int $creatorId,
        string $start,
        string $end,
        ?int $accountingPeriod,
        int $fiscalYear
    ): array {
        $tlQuery = TransactionLines::query()
            ->select(
                DB::raw('COALESCE(SUM(debit), 0) as totalDebit'),
                DB::raw('COALESCE(SUM(credit), 0) as totalCredit')
            )
            ->where('created_by', $creatorId)
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end);

        $this->applyTransactionLinePeriodFilter($tlQuery, $creatorId, $start, $end, $accountingPeriod, $fiscalYear);
        $tl = $tlQuery->first();

        $jiQuery = DB::table('journal_items as ji')
            ->join('journal_entries as je', 'ji.journal', '=', 'je.id')
            ->where('je.created_by', $creatorId)
            ->whereDate('je.date', '>=', $start)
            ->whereDate('je.date', '<=', $end)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('transaction_lines as tl')
                    ->whereColumn('tl.reference_id', 'je.id')
                    ->where('tl.reference', 'Journal');
            });

        $this->applyJournalEntryPeriodFilter($jiQuery, $accountingPeriod, $fiscalYear);

        $ji = $jiQuery
            ->selectRaw('COALESCE(SUM(ji.debit),0) as totalDebit, COALESCE(SUM(ji.credit),0) as totalCredit')
            ->first();

        return $this->normalizeAmounts(
            (float) ($tl->totalDebit ?? 0) + (float) ($ji->totalDebit ?? 0),
            (float) ($tl->totalCredit ?? 0) + (float) ($ji->totalCredit ?? 0)
        );
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder|Builder  $query
     */
    private function applyTransactionLinePeriodFilter(
        $query,
        int $creatorId,
        string $start,
        string $end,
        ?int $accountingPeriod,
        int $fiscalYear
    ): void {
        if ($accountingPeriod !== null && $accountingPeriod >= 13) {
            $query->where('reference', 'Journal')
                ->whereIn('reference_id', $this->closingJournalIdsQuery($creatorId, $accountingPeriod, $fiscalYear));

            return;
        }

        $closingIds = $this->closingJournalIdsQuery($creatorId, null, $fiscalYear, $start, $end);

        $query->where(function ($w) use ($closingIds, $accountingPeriod) {
            $w->where('reference', '!=', 'Journal')
                ->orWhereNull('reference_id')
                ->orWhere(function ($journal) use ($closingIds, $accountingPeriod) {
                    $journal->where('reference', 'Journal')
                        ->whereNotIn('reference_id', $closingIds);

                    if ($accountingPeriod !== null && $accountingPeriod >= 1 && $accountingPeriod <= 12) {
                        $journal->whereIn('reference_id', function ($sub) use ($accountingPeriod) {
                            $sub->select('id')
                                ->from('journal_entries')
                                ->where(function ($periods) use ($accountingPeriod) {
                                    $periods->whereNull('accounting_period')
                                        ->orWhere('accounting_period', 0)
                                        ->orWhere('accounting_period', $accountingPeriod);
                                });
                        });
                    }
                });
        });
    }

    /**
     * @param  Builder  $query
     */
    private function applyJournalEntryPeriodFilter($query, ?int $accountingPeriod, int $fiscalYear): void
    {
        if ($accountingPeriod !== null && $accountingPeriod >= 13) {
            $query->where('je.accounting_period', $accountingPeriod)
                ->whereYear('je.date', $fiscalYear);

            return;
        }

        if ($accountingPeriod !== null && $accountingPeriod >= 1 && $accountingPeriod <= 12) {
            $query->where(function ($w) use ($accountingPeriod) {
                $w->whereNull('je.accounting_period')
                    ->orWhere('je.accounting_period', 0)
                    ->orWhere('je.accounting_period', $accountingPeriod);
            });

            return;
        }

        $query->where(function ($w) {
            $w->whereNull('je.accounting_period')
                ->orWhere('je.accounting_period', 0)
                ->orWhere('je.accounting_period', '<', 13);
        });
    }

    /**
     * @return Builder
     */
    private function closingJournalIdsQuery(
        int $creatorId,
        ?int $onlyPeriod,
        int $fiscalYear,
        ?string $start = null,
        ?string $end = null
    ) {
        $query = DB::table('journal_entries')
            ->select('id')
            ->where('created_by', $creatorId)
            ->whereYear('date', $fiscalYear);

        if ($onlyPeriod !== null) {
            $query->where('accounting_period', $onlyPeriod);
        } else {
            $query->whereIn('accounting_period', [13, 14, 15]);
            if ($start !== null && $end !== null) {
                $query->whereDate('date', '>=', $start)
                    ->whereDate('date', '<=', $end);
            }
        }

        return $query;
    }

    /**
     * @param  array<int, array{totalDebit: float, totalCredit: float}>  $balances
     * @return array{rows: list<array<string, mixed>>, subtreeDebit: float, subtreeCredit: float}
     */
    private function rowsForNode(ChartOfAccount $node, array $balances, string $view, int $depth): array
    {
        $direct = $balances[(int) $node->id] ?? ['totalDebit' => 0.0, 'totalCredit' => 0.0];

        /** @var Collection<int, ChartOfAccount> $children */
        $children = $node->tree_children ?? collect();

        $childRows = [];
        $childDebit = 0.0;
        $childCredit = 0.0;

        foreach ($children as $child) {
            $childResult = $this->rowsForNode($child, $balances, $view, $depth + 1);
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

    private function hasActivity(float $totalDebit, float $totalCredit): bool
    {
        return abs($totalDebit - $totalCredit) > 0.00001;
    }
}
