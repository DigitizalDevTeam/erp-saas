<?php

namespace App\Services;

use App\Models\ChartOfAccount;
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

        $balances = $this->accountBalances($creatorId, $start, $end, $accountingPeriodFrom, $accountingPeriodTo, $fiscalYear);
        $grandTotals = $this->grandTotals($creatorId, $start, $end, $accountingPeriodFrom, $accountingPeriodTo, $fiscalYear);

        // Same ordering as Chart of Accounts (PGC / CSV code order), not grouped by account type.
        $tree = ChartOfAccountHierarchy::buildMasterTree($creatorId);
        $rows = [];
        foreach ($tree as $root) {
            $result = $this->rowsForNode($root, $balances, $view, 0);
            $rows = array_merge($rows, $result['rows']);
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
     * @return array<int, array{totalDebit: float, totalCredit: float}>
     */
    private function accountBalances(
        int $creatorId,
        string $start,
        string $end,
        ?int $periodFrom,
        ?int $periodTo,
        int $fiscalYear
    ): array {
        $closingIdsForYear = $this->fiscalYearClosingJournalIds($creatorId, $fiscalYear, $periodFrom, $periodTo);

        $query = TransactionLines::query()
            ->select(
                'account_id',
                DB::raw('SUM(debit) as totalDebit'),
                DB::raw('SUM(credit) as totalCredit')
            )
            ->where('created_by', $creatorId)
            ->where(function ($w) use ($start, $end, $closingIdsForYear) {
                // Normal activity in the fiscal calendar window…
                $w->where(function ($dated) use ($start, $end) {
                    $dated->whereDate('date', '>=', $start)
                        ->whereDate('date', '<=', $end);
                });
                // …plus this year's FY-CLOSE journals even if their calendar date drifted.
                if ($closingIdsForYear !== []) {
                    $w->orWhere(function ($closing) use ($closingIdsForYear) {
                        $closing->where('reference', 'Journal')
                            ->whereIn('reference_id', $closingIdsForYear);
                    });
                }
            });

        $this->applyTransactionLinePeriodFilter($query, $creatorId, $start, $end, $periodFrom, $periodTo, $fiscalYear);

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
            ->where(function ($w) use ($start, $end, $closingIdsForYear) {
                $w->where(function ($dated) use ($start, $end) {
                    $dated->whereDate('je.date', '>=', $start)
                        ->whereDate('je.date', '<=', $end);
                });
                if ($closingIdsForYear !== []) {
                    $w->orWhereIn('je.id', $closingIdsForYear);
                }
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('transaction_lines as tl')
                    ->whereColumn('tl.reference_id', 'je.id')
                    ->where('tl.reference', 'Journal');
            });

        $this->applyJournalEntryPeriodFilter($journalOnly, $periodFrom, $periodTo, $fiscalYear);

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
        ?int $periodFrom,
        ?int $periodTo,
        int $fiscalYear
    ): array {
        $closingIdsForYear = $this->fiscalYearClosingJournalIds($creatorId, $fiscalYear, $periodFrom, $periodTo);

        $tlQuery = TransactionLines::query()
            ->select(
                DB::raw('COALESCE(SUM(debit), 0) as totalDebit'),
                DB::raw('COALESCE(SUM(credit), 0) as totalCredit')
            )
            ->where('created_by', $creatorId)
            ->where(function ($w) use ($start, $end, $closingIdsForYear) {
                $w->where(function ($dated) use ($start, $end) {
                    $dated->whereDate('date', '>=', $start)
                        ->whereDate('date', '<=', $end);
                });
                if ($closingIdsForYear !== []) {
                    $w->orWhere(function ($closing) use ($closingIdsForYear) {
                        $closing->where('reference', 'Journal')
                            ->whereIn('reference_id', $closingIdsForYear);
                    });
                }
            });

        $this->applyTransactionLinePeriodFilter($tlQuery, $creatorId, $start, $end, $periodFrom, $periodTo, $fiscalYear);
        $tl = $tlQuery->first();

        $jiQuery = DB::table('journal_items as ji')
            ->join('journal_entries as je', 'ji.journal', '=', 'je.id')
            ->where('je.created_by', $creatorId)
            ->where(function ($w) use ($start, $end, $closingIdsForYear) {
                $w->where(function ($dated) use ($start, $end) {
                    $dated->whereDate('je.date', '>=', $start)
                        ->whereDate('je.date', '<=', $end);
                });
                if ($closingIdsForYear !== []) {
                    $w->orWhereIn('je.id', $closingIdsForYear);
                }
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('transaction_lines as tl')
                    ->whereColumn('tl.reference_id', 'je.id')
                    ->where('tl.reference', 'Journal');
            });

        $this->applyJournalEntryPeriodFilter($jiQuery, $periodFrom, $periodTo, $fiscalYear);

        $ji = $jiQuery
            ->selectRaw('COALESCE(SUM(ji.debit),0) as totalDebit, COALESCE(SUM(ji.credit),0) as totalCredit')
            ->first();

        return $this->normalizeAmounts(
            (float) ($tl->totalDebit ?? 0) + (float) ($ji->totalDebit ?? 0),
            (float) ($tl->totalCredit ?? 0) + (float) ($ji->totalCredit ?? 0)
        );
    }

    /**
     * @return list<int>
     */
    private function periodsInRange(?int $periodFrom, ?int $periodTo): array
    {
        if ($periodFrom === null || $periodTo === null) {
            return [];
        }

        return range($periodFrom, $periodTo);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder|Builder  $query
     */
    private function applyTransactionLinePeriodFilter(
        $query,
        int $creatorId,
        string $start,
        string $end,
        ?int $periodFrom,
        ?int $periodTo,
        int $fiscalYear
    ): void {
        $periods = $this->periodsInRange($periodFrom, $periodTo);

        if ($periods === []) {
            $excludedIds = $this->allClosingJournalIdsToExclude($creatorId, $fiscalYear, $start, $end);
            $openingIds = $this->openingJournalIdsQuery($creatorId, $fiscalYear, $start, $end);
            $excludedIds = array_values(array_unique(array_merge($excludedIds, $openingIds)));

            $query->where(function ($w) use ($excludedIds) {
                $w->where('reference', '!=', 'Journal')
                    ->orWhereNull('reference_id')
                    ->orWhere(function ($journal) use ($excludedIds) {
                        $journal->where('reference', 'Journal');
                        if ($excludedIds !== []) {
                            $journal->whereNotIn('reference_id', $excludedIds);
                        }
                    });
            });

            return;
        }

        $hasOpening = in_array(0, $periods, true);
        $ops = array_values(array_filter($periods, static fn ($p) => $p >= 1 && $p <= 12));
        $closing = array_values(array_filter($periods, static fn ($p) => $p >= 13));

        $query->where(function ($outer) use ($creatorId, $fiscalYear, $hasOpening, $ops, $closing, $start, $end) {
            if ($hasOpening) {
                $openingIds = $this->openingJournalIdsQuery($creatorId, $fiscalYear);
                $outer->orWhere(function ($w) use ($openingIds) {
                    $w->where('reference', 'Journal');
                    if ($openingIds !== []) {
                        $w->whereIn('reference_id', $openingIds);
                    } else {
                        $w->whereRaw('1 = 0');
                    }
                });
            }

            if ($closing !== []) {
                $closingIds = $this->closingJournalIdsQuery($creatorId, null, $fiscalYear)
                    ->whereIn('accounting_period', $closing)
                    ->pluck('id')
                    ->map(static fn ($id) => (int) $id)
                    ->all();
                $outer->orWhere(function ($w) use ($closingIds) {
                    $w->where('reference', 'Journal');
                    if ($closingIds !== []) {
                        $w->whereIn('reference_id', $closingIds);
                    } else {
                        $w->whereRaw('1 = 0');
                    }
                });
            }

            if ($ops !== []) {
                // Exclude every FY-*-CLOSE* (any year) and period 13–15 in the window so foreign
                // closes dated in this calendar year cannot leak into operational totals.
                $excludedClosing = $this->allClosingJournalIdsToExclude($creatorId, $fiscalYear, $start, $end);
                $excludedOpening = $this->openingJournalIdsQuery($creatorId, $fiscalYear, $start, $end);
                $excludedIds = array_values(array_unique(array_merge($excludedClosing, $excludedOpening)));

                $outer->orWhere(function ($w) use ($excludedIds, $ops) {
                    $w->where(function ($nonJournal) {
                        $nonJournal->where('reference', '!=', 'Journal')
                            ->orWhereNull('reference_id');
                    })->orWhere(function ($journal) use ($excludedIds, $ops) {
                        $journal->where('reference', 'Journal');

                        if ($excludedIds !== []) {
                            $journal->whereNotIn('reference_id', $excludedIds);
                        }

                        $journal->whereIn('reference_id', function ($sub) use ($ops) {
                            $sub->select('id')
                                ->from('journal_entries')
                                ->where(function ($periods) use ($ops) {
                                    $periods->whereNull('accounting_period')
                                        ->orWhereIn('accounting_period', $ops);
                                });
                        });
                    });
                });
            }
        });
    }

    /**
     * @param  Builder  $query
     */
    private function applyJournalEntryPeriodFilter($query, ?int $periodFrom, ?int $periodTo, int $fiscalYear): void
    {
        $periods = $this->periodsInRange($periodFrom, $periodTo);

        if ($periods === []) {
            $query->where(function ($w) {
                $w->whereNull('je.accounting_period')
                    ->orWhereBetween('je.accounting_period', [1, 12]);
            });

            return;
        }

        $hasOpening = in_array(0, $periods, true);
        $ops = array_values(array_filter($periods, static fn ($p) => $p >= 1 && $p <= 12));
        $closing = array_values(array_filter($periods, static fn ($p) => $p >= 13));

        $query->where(function ($outer) use ($hasOpening, $ops, $closing, $fiscalYear) {
            if ($hasOpening) {
                $outer->orWhere(function ($w) use ($fiscalYear) {
                    $w->where('je.accounting_period', 0)
                        ->whereYear('je.date', $fiscalYear);
                });
            }

            if ($closing !== []) {
                $outer->orWhere(function ($w) use ($closing, $fiscalYear) {
                    $w->whereIn('je.accounting_period', $closing)
                        ->where('je.reference', 'like', 'FY-'.$fiscalYear.'-CLOSE%');
                });
            }

            if ($ops !== []) {
                $outer->orWhere(function ($w) use ($ops) {
                    $w->where(function ($periods) use ($ops) {
                        $periods->whereNull('je.accounting_period')
                            ->orWhereIn('je.accounting_period', $ops);
                    })->where(function ($ref) {
                        $ref->whereNull('je.reference')
                            ->orWhere('je.reference', 'not like', 'FY-%-CLOSE%');
                    });
                });
            }
        });
    }

    /**
     * FY-{year}-CLOSE* journal ids for the selected fiscal year (reference year is authoritative).
     *
     * @return list<int>
     */
    private function fiscalYearClosingJournalIds(
        int $creatorId,
        int $fiscalYear,
        ?int $periodFrom,
        ?int $periodTo
    ): array {
        $periods = $this->periodsInRange($periodFrom, $periodTo);
        if ($periods === []) {
            return [];
        }
        $closing = array_values(array_filter($periods, static fn ($p) => $p >= 13));
        if ($closing === []) {
            return [];
        }

        return $this->closingJournalIdsQuery($creatorId, null, $fiscalYear)
            ->whereIn('accounting_period', $closing)
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function openingJournalIdsQuery(int $creatorId, int $fiscalYear, ?string $start = null, ?string $end = null): array
    {
        $query = DB::table('journal_entries')
            ->select('id')
            ->where('created_by', $creatorId)
            ->where('accounting_period', 0)
            ->whereYear('date', $fiscalYear);

        if ($start !== null && $end !== null) {
            $query->whereDate('date', '>=', $start)
                ->whereDate('date', '<=', $end);
        }

        return $query->pluck('id')->map(static fn ($id) => (int) $id)->all();
    }

    /**
     * Official closing journals for one fiscal year (by FY-{year}-CLOSE* reference).
     * Does not use calendar year of the journal date — avoids FY-2025 closes dated in 2026
     * polluting / doubling the 2026 trial balance.
     *
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
            ->where('reference', 'like', 'FY-'.$fiscalYear.'-CLOSE%');

        if ($onlyPeriod !== null) {
            $query->where('accounting_period', $onlyPeriod);
        } else {
            $query->whereIn('accounting_period', [13, 14, 15]);
        }

        return $query;
    }

    /**
     * All closing-related journal ids to keep out of the operational branch.
     *
     * @return list<int>
     */
    private function allClosingJournalIdsToExclude(
        int $creatorId,
        int $fiscalYear,
        ?string $start,
        ?string $end
    ): array {
        $query = DB::table('journal_entries')
            ->select('id')
            ->where('created_by', $creatorId)
            ->where(function ($w) use ($fiscalYear, $start, $end) {
                $w->where('reference', 'like', 'FY-%-CLOSE%')
                    ->orWhere(function ($p) use ($fiscalYear, $start, $end) {
                        $p->whereIn('accounting_period', [13, 14, 15])
                            ->whereYear('date', $fiscalYear);
                        if ($start !== null && $end !== null) {
                            $p->whereDate('date', '>=', $start)
                                ->whereDate('date', '<=', $end);
                        }
                    });
            });

        return $query->pluck('id')->map(static fn ($id) => (int) $id)->all();
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

    /**
     * True when the account has any gross movement (even if debit == credit).
     * Clearing accounts 81/82/83 must remain visible after year-end close.
     */
    private function hasActivity(float $totalDebit, float $totalCredit): bool
    {
        return abs($totalDebit) > 0.00001 || abs($totalCredit) > 0.00001;
    }
}
