<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Modelo A IVA — ledger aggregation aligned with Balancete (remap, journal-only rows)
 * and settlement-aware VAT box calculations.
 */
class VatModeloAReportService
{
    /** @var array<int, string> */
    private const DEDUCTIBLE_VAT_PREFIXES = ['44321', '44322', '44323'];

    /** @var array<int, string> */
    private const CLEARING_PREFIXES = ['4435', '4437', '4438'];

    /**
     * Journal entry IDs that clear VAT (4435 / FY-VAT / paired clearing patterns).
     *
     * @return array<int, int>
     */
    public function settlementJournalIds(int $creatorId): array
    {
        $ids = collect();

        $ids = $ids->merge($this->journalIdsTouchingAccountPrefix($creatorId, self::CLEARING_PREFIXES));

        $ids = $ids->merge(
            DB::table('journal_entries')
                ->where('created_by', $creatorId)
                ->where('reference', 'like', 'FY-%-VAT%')
                ->pluck('id')
        );

        $ids = $ids->merge($this->journalIdsWithSettlementDescription($creatorId));
        $ids = $ids->merge($this->journalIdsByClearingPattern($creatorId));

        return $ids
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Per-account-code debit/credit totals for a calendar month.
     *
     * @return Collection<int, object{code: string, debit: float, credit: float}>
     */
    public function ledgerTotalsByCode(int $creatorId, string $start, string $end, bool $excludeSettlement): Collection
    {
        $excludeIds = $excludeSettlement ? $this->settlementJournalIds($creatorId) : [];
        $excludeSet = array_fill_keys($excludeIds, true);

        $byAccount = $this->rawTotalsByAccountId($creatorId, $start, $end, $excludeSet);
        if ($byAccount === []) {
            return collect();
        }

        $remapped = app(LedgerAccountBalanceService::class)
            ->remapToCompanyAccountIds($creatorId, $byAccount);

        $codes = ChartOfAccount::query()
            ->where('created_by', $creatorId)
            ->whereIn('id', array_keys($remapped))
            ->pluck('code', 'id');

        $missingIds = array_diff(array_keys($remapped), $codes->keys()->all());
        if ($missingIds !== []) {
            $fallback = ChartOfAccount::query()
                ->whereIn('id', $missingIds)
                ->pluck('code', 'id');
            foreach ($fallback as $id => $code) {
                $codes[(int) $id] = $code;
            }
        }

        /** @var array<string, array{debit: float, credit: float}> $byCode */
        $byCode = [];
        foreach ($remapped as $accountId => $amounts) {
            $code = trim((string) ($codes[(int) $accountId] ?? ''));
            if ($code === '') {
                continue;
            }
            if (! isset($byCode[$code])) {
                $byCode[$code] = ['debit' => 0.0, 'credit' => 0.0];
            }
            $byCode[$code]['debit'] += (float) $amounts['totalDebit'];
            $byCode[$code]['credit'] += (float) $amounts['totalCredit'];
        }

        return collect($byCode)
            ->map(fn (array $amounts, string $code): object => (object) [
                'code' => $code,
                'debit' => round($amounts['debit'], 2),
                'credit' => round($amounts['credit'], 2),
            ])
            ->values();
    }

    /**
     * @param  array<int, true>  $excludeSet
     * @return array<int, array{totalDebit: float, totalCredit: float}>
     */
    private function rawTotalsByAccountId(int $creatorId, string $start, string $end, array $excludeSet): array
    {
        $balances = [];

        $tlQuery = DB::table('transaction_lines as tl')
            ->where('tl.created_by', $creatorId)
            ->whereDate('tl.date', '>=', $start)
            ->whereDate('tl.date', '<=', $end);

        $this->applySettlementExclusion($tlQuery, 'tl', $excludeSet);

        foreach ($tlQuery->select('tl.account_id', 'tl.debit', 'tl.credit')->get() as $row) {
            $accountId = (int) $row->account_id;
            if (! isset($balances[$accountId])) {
                $balances[$accountId] = ['totalDebit' => 0.0, 'totalCredit' => 0.0];
            }
            $balances[$accountId]['totalDebit'] += (float) $row->debit;
            $balances[$accountId]['totalCredit'] += (float) $row->credit;
        }

        $journalOnly = DB::table('journal_items as ji')
            ->join('journal_entries as je', 'ji.journal', '=', 'je.id')
            ->where('je.created_by', $creatorId)
            ->whereDate('je.date', '>=', $start)
            ->whereDate('je.date', '<=', $end)
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('transaction_lines as tl')
                    ->whereColumn('tl.reference_id', 'je.id')
                    ->where('tl.reference', 'Journal');
            });

        if ($excludeSet !== []) {
            $journalOnly->whereNotIn('je.id', array_keys($excludeSet));
        }

        foreach ($journalOnly->select('ji.account as account_id', 'ji.debit', 'ji.credit')->get() as $row) {
            $accountId = (int) $row->account_id;
            if (! isset($balances[$accountId])) {
                $balances[$accountId] = ['totalDebit' => 0.0, 'totalCredit' => 0.0];
            }
            $balances[$accountId]['totalDebit'] += (float) $row->debit;
            $balances[$accountId]['totalCredit'] += (float) $row->credit;
        }

        return $balances;
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array<int, true>  $excludeSet
     */
    private function applySettlementExclusion($query, string $alias, array $excludeSet): void
    {
        if ($excludeSet === []) {
            return;
        }

        $excludeIds = array_keys($excludeSet);
        $query->where(function ($q) use ($alias, $excludeIds): void {
            $q->where("{$alias}.reference", '!=', 'Journal')
                ->orWhereNotIn("{$alias}.reference_id", $excludeIds);
        });
    }

    /**
     * @param  array<int, string>  $prefixes
     * @return Collection<int, int|string>
     */
    private function journalIdsTouchingAccountPrefix(int $creatorId, array $prefixes): Collection
    {
        $query = DB::table('transaction_lines as tl')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'tl.account_id')
            ->where('tl.created_by', $creatorId)
            ->where('tl.reference', 'Journal');

        $query->where(function ($w) use ($prefixes): void {
            foreach ($prefixes as $i => $prefix) {
                $method = $i === 0 ? 'whereRaw' : 'orWhereRaw';
                $w->{$method}('CAST(coa.code AS CHAR) LIKE ?', [$prefix.'%']);
            }
        });

        return $query->distinct()->pluck('tl.reference_id');
    }

    /**
     * @return Collection<int, int|string>
     */
    private function journalIdsWithSettlementDescription(int $creatorId): Collection
    {
        return DB::table('journal_entries')
            ->where('created_by', $creatorId)
            ->where(function ($q): void {
                $q->where('description', 'like', '%VAT — deductible%')
                    ->orWhere('description', 'like', '%VAT — collected%')
                    ->orWhere('description', 'like', '%VAT — payable%')
                    ->orWhere('description', 'like', '%VAT — recoverable%')
                    ->orWhere('description', 'like', '%deductible to 4435%')
                    ->orWhere('description', 'like', '%collected to settlement%')
                    ->orWhere('description', 'like', '%ICMS collected%');
            })
            ->pluck('id');
    }

    /**
     * Journals that pair deductible/collected VAT lines with clearing accounts on the same entry.
     *
     * @return Collection<int, int|string>
     */
    private function journalIdsByClearingPattern(int $creatorId): Collection
    {
        $ids = collect();

        $ids = $ids->merge($this->journalIdsMatchingSidePattern(
            $creatorId,
            self::DEDUCTIBLE_VAT_PREFIXES,
            'credit',
            self::CLEARING_PREFIXES,
            'debit'
        ));

        $ids = $ids->merge($this->journalIdsMatchingSidePattern(
            $creatorId,
            ['44331'],
            'debit',
            self::CLEARING_PREFIXES,
            'credit'
        ));

        $ids = $ids->merge($this->journalIdsMatchingSidePattern(
            $creatorId,
            self::CLEARING_PREFIXES,
            'debit',
            ['4437'],
            'credit'
        ));

        $ids = $ids->merge($this->journalIdsMatchingSidePattern(
            $creatorId,
            ['4438'],
            'debit',
            self::CLEARING_PREFIXES,
            'credit'
        ));

        return $ids;
    }

    /**
     * @param  array<int, string>  $leftPrefixes
     * @param  array<int, string>  $rightPrefixes
     * @return Collection<int, int|string>
     */
    private function journalIdsMatchingSidePattern(
        int $creatorId,
        array $leftPrefixes,
        string $leftSide,
        array $rightPrefixes,
        string $rightSide
    ): Collection {
        $leftColumn = $leftSide === 'credit' ? 'tl1.credit' : 'tl1.debit';
        $rightColumn = $rightSide === 'credit' ? 'tl2.credit' : 'tl2.debit';

        $query = DB::table('transaction_lines as tl1')
            ->join('chart_of_accounts as coa1', 'coa1.id', '=', 'tl1.account_id')
            ->join('transaction_lines as tl2', function ($join): void {
                $join->on('tl2.reference_id', '=', 'tl1.reference_id')
                    ->where('tl2.reference', '=', 'Journal')
                    ->whereColumn('tl2.id', '!=', 'tl1.id');
            })
            ->join('chart_of_accounts as coa2', 'coa2.id', '=', 'tl2.account_id')
            ->where('tl1.created_by', $creatorId)
            ->where('tl1.reference', 'Journal')
            ->where($leftColumn, '>', 0)
            ->where($rightColumn, '>', 0);

        $query->where(function ($w) use ($leftPrefixes): void {
            foreach ($leftPrefixes as $i => $prefix) {
                $method = $i === 0 ? 'whereRaw' : 'orWhereRaw';
                $w->{$method}('CAST(coa1.code AS CHAR) LIKE ?', [$prefix.'%']);
            }
        });

        $query->where(function ($w) use ($rightPrefixes): void {
            foreach ($rightPrefixes as $i => $prefix) {
                $method = $i === 0 ? 'whereRaw' : 'orWhereRaw';
                $w->{$method}('CAST(coa2.code AS CHAR) LIKE ?', [$prefix.'%']);
            }
        });

        return $query->distinct()->pluck('tl1.reference_id');
    }
}
