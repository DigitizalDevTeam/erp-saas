<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\TransactionLines;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class LedgerAccountBalanceService
{
    /**
     * Per-account debit/credit totals for Trial Balance (sign-normalized, activity only).
     *
     * @return array<int, array{totalDebit: float, totalCredit: float}>
     */
    public function balancesByAccountId(
        int $creatorId,
        string $start,
        string $end,
        ?int $periodFrom,
        ?int $periodTo,
        int $fiscalYear
    ): array {
        $raw = $this->rawBalancesByAccountId($creatorId, $start, $end, $periodFrom, $periodTo, $fiscalYear);

        $balances = [];
        foreach ($raw as $accountId => $amounts) {
            $normalized = $this->normalizeAmounts($amounts['totalDebit'], $amounts['totalCredit']);
            if (! $this->hasActivity($normalized['totalDebit'], $normalized['totalCredit'])) {
                continue;
            }
            $balances[$accountId] = $normalized;
        }

        return $balances;
    }

    /**
     * Per-account totals with chart code + type for balance-sheet snapshots.
     * Includes deleted/untyped accounts (empty code/type) so the equation still holds.
     *
     * @return list<array{code: string, type: string, debit: float, credit: float}>
     */
    public function balancesForSnapshot(
        int $creatorId,
        string $start,
        string $end,
        ?int $periodFrom,
        ?int $periodTo,
        int $fiscalYear
    ): array {
        $raw = $this->rawBalancesByAccountId($creatorId, $start, $end, $periodFrom, $periodTo, $fiscalYear);
        if ($raw === []) {
            return [];
        }

        $meta = ChartOfAccount::query()
            ->leftJoin('chart_of_account_types', 'chart_of_accounts.type', '=', 'chart_of_account_types.id')
            ->where('chart_of_accounts.created_by', $creatorId)
            ->whereIn('chart_of_accounts.id', array_keys($raw))
            ->select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_account_types.name as type_name')
            ->get()
            ->keyBy('id');

        $missingIds = array_values(array_diff(array_keys($raw), $meta->keys()->all()));
        if ($missingIds !== []) {
            $fallback = ChartOfAccount::query()
                ->leftJoin('chart_of_account_types', 'chart_of_accounts.type', '=', 'chart_of_account_types.id')
                ->whereIn('chart_of_accounts.id', $missingIds)
                ->select('chart_of_accounts.id', 'chart_of_accounts.code', 'chart_of_account_types.name as type_name')
                ->get()
                ->keyBy('id');
            foreach ($fallback as $id => $row) {
                $meta->put($id, $row);
            }
        }

        $accounts = [];
        foreach ($raw as $accountId => $amounts) {
            $coa = $meta->get($accountId);
            $accounts[] = [
                // Missing / foreign ids (after remap) stay empty so BS shows Unclassified
                // instead of borrowing another company's chart labels.
                'code' => (string) ($coa->code ?? ''),
                'type' => (string) ($coa->type_name ?? ''),
                'debit' => (float) $amounts['totalDebit'],
                'credit' => (float) $amounts['totalCredit'],
            ];
        }

        return $accounts;
    }

    /**
     * @return array{totalDebit: float, totalCredit: float}
     */
    public function grandTotals(
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
     * @return array<int, array{totalDebit: float, totalCredit: float}>
     */
    private function rawBalancesByAccountId(
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

        $this->applyTransactionLinePeriodFilter($query, $creatorId, $start, $end, $periodFrom, $periodTo, $fiscalYear);

        $rows = $query->groupBy('account_id')->get();

        $balances = [];
        foreach ($rows as $row) {
            $balances[(int) $row->account_id] = [
                'totalDebit' => (float) $row->totalDebit,
                'totalCredit' => (float) $row->totalCredit,
            ];
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
            $debit = (float) $row->totalDebit;
            $credit = (float) $row->totalCredit;
            if (! isset($balances[$accountId])) {
                $balances[$accountId] = [
                    'totalDebit' => $debit,
                    'totalCredit' => $credit,
                ];
            } else {
                $balances[$accountId]['totalDebit'] += $debit;
                $balances[$accountId]['totalCredit'] += $credit;
            }
        }

        // Historical bills/docs sometimes stored another company's chart account id.
        // Remap by account code onto this company's CoA so TB and BS stay aligned.
        return $this->remapToCompanyAccountIds($creatorId, $balances);
    }

    /**
     * Re-attribute balances posted against foreign/missing chart rows onto this
     * company's account with the same code (when one exists).
     *
     * @param  array<int, array{totalDebit: float, totalCredit: float}>  $balances
     * @return array<int, array{totalDebit: float, totalCredit: float}>
     */
    public function remapToCompanyAccountIds(int $creatorId, array $balances): array
    {
        if ($balances === []) {
            return [];
        }

        $accountIds = array_keys($balances);
        $coaRows = ChartOfAccount::query()
            ->whereIn('id', $accountIds)
            ->get(['id', 'code', 'created_by'])
            ->keyBy('id');

        $meta = [];
        foreach ($accountIds as $accountId) {
            $coa = $coaRows->get($accountId);
            $meta[(int) $accountId] = $coa === null
                ? null
                : [
                    'code' => trim((string) ($coa->code ?? '')),
                    'created_by' => (int) $coa->created_by,
                ];
        }

        $foreignCodes = [];
        foreach ($meta as $info) {
            if ($info === null || $info['created_by'] === $creatorId || $info['code'] === '') {
                continue;
            }
            $foreignCodes[$info['code']] = true;
        }

        $companyByCode = [];
        if ($foreignCodes !== []) {
            $companyByCode = ChartOfAccount::query()
                ->where('created_by', $creatorId)
                ->whereIn('code', array_keys($foreignCodes))
                ->get(['id', 'code'])
                ->keyBy(static fn (ChartOfAccount $row) => trim((string) $row->code))
                ->map(static fn (ChartOfAccount $row) => (int) $row->id)
                ->all();
        }

        return self::rekeyBalancesToCompanyAccounts($balances, $meta, $companyByCode, $creatorId);
    }

    /**
     * Pure remapping helper (unit-testable without Eloquent).
     *
     * @param  array<int, array{totalDebit: float, totalCredit: float}>  $balances
     * @param  array<int, array{code: string, created_by: int}|null>  $meta
     * @param  array<string, int>  $companyByCode
     * @return array<int, array{totalDebit: float, totalCredit: float}>
     */
    public static function rekeyBalancesToCompanyAccounts(
        array $balances,
        array $meta,
        array $companyByCode,
        int $creatorId
    ): array {
        $remapped = [];
        foreach ($balances as $accountId => $amounts) {
            $targetId = (int) $accountId;
            $info = $meta[$accountId] ?? null;
            if ($info !== null && $info['created_by'] !== $creatorId && $info['code'] !== '') {
                if (isset($companyByCode[$info['code']])) {
                    $targetId = $companyByCode[$info['code']];
                }
            }

            if (! isset($remapped[$targetId])) {
                $remapped[$targetId] = [
                    'totalDebit' => 0.0,
                    'totalCredit' => 0.0,
                ];
            }
            $remapped[$targetId]['totalDebit'] += (float) $amounts['totalDebit'];
            $remapped[$targetId]['totalCredit'] += (float) $amounts['totalCredit'];
        }

        return $remapped;
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
     * @return array{totalDebit: float, totalCredit: float}
     */
    public function normalizeAmounts(float $totalDebit, float $totalCredit): array
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

    public function hasActivity(float $totalDebit, float $totalCredit): bool
    {
        return abs($totalDebit) > 0.00001 || abs($totalCredit) > 0.00001;
    }
}
