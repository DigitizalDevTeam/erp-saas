<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only diagnostic: shows exactly how the year-end closing journals
 * are stored, so a Trial Balance doubling can be traced to its source.
 */
class DiagnoseClosingDuplicates extends Command
{
    protected $signature = 'accounting:diagnose-closing
                            {--company= : Company id to inspect}
                            {--year= : Fiscal year to inspect}';

    protected $description = 'Show closing journals with item/line counts and duplicate-pair analysis (read-only)';

    public function handle(): int
    {
        $companyId = (int) ($this->option('company') ?? 0);
        $year = (int) ($this->option('year') ?? date('Y'));

        if ($companyId <= 0) {
            $this->error('Pass --company=<id>.');
            return self::FAILURE;
        }

        $journals = DB::table('journal_entries')
            ->where('created_by', $companyId)
            ->where(function ($q) use ($year) {
                $q->where('reference', 'like', 'FY-%-CLOSE%')
                    ->orWhere(function ($q2) use ($year) {
                        $q2->where('reference', 'like', 'FY-'.$year.'%')
                            ->orWhereBetween('date', [sprintf('%04d-12-01', $year), sprintf('%04d-01-31', $year + 1)]);
                    });
            })
            ->orderBy('id')
            ->get(['id', 'reference', 'date', 'status']);

        $this->info("Company {$companyId}, FY {$year}: ".$journals->count().' candidate journals');
        $this->line('');

        foreach ($journals as $j) {
            $items = DB::table('journal_items')->where('journal', $j->id)->get();
            $lines = DB::table('transaction_lines')
                ->where('reference', 'Journal')
                ->where('reference_id', $j->id)
                ->get();

            $itemPairs = $this->pairAnalysis($items->map(fn ($r) => [(int) $r->account, round((float) $r->debit, 2), round((float) $r->credit, 2)]));
            $linePairs = $this->pairAnalysis($lines->map(fn ($r) => [(int) $r->account_id, round((float) $r->debit, 2), round((float) $r->credit, 2)]));

            // Lines whose sub_id does not match any item id of this journal.
            $itemIds = $items->pluck('id')->map(fn ($id) => (int) $id)->all();
            $orphanSubIds = $lines->pluck('reference_sub_id')->map(fn ($id) => (int) $id)
                ->unique()->reject(fn ($id) => in_array($id, $itemIds, true))->values()->all();

            // Lines whose created_by differs from the journal's company.
            $foreignLines = $lines->reject(fn ($r) => (int) $r->created_by === $companyId)->count();

            $this->line("#{$j->id} [{$j->reference}] date={$j->date} status={$j->status} items={$items->count()} lines={$lines->count()} ".
                "itemPairs={$itemPairs} linePairs={$linePairs} ".
                'orphanSubIds='.($orphanSubIds === [] ? 'none' : implode(',', $orphanSubIds)).
                " foreignLines={$foreignLines}");
        }

        // Orphaned closing lines: reference_id points at a deleted journal.
        $orphans = DB::table('transaction_lines as tl')
            ->where('tl.created_by', $companyId)
            ->where('tl.reference', 'Journal')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('journal_entries as je')
                    ->whereColumn('je.id', 'tl.reference_id');
            })
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(tl.debit),0) as d, COALESCE(SUM(tl.credit),0) as c')
            ->first();
        $this->line('');
        $this->line("Orphaned 'Journal' lines (journal deleted): n={$orphans->n} debit={$orphans->d} credit={$orphans->c}");

        // Class 6/7 totals from transaction_lines for the year.
        $totals = DB::table('transaction_lines as tl')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'tl.account_id')
            ->where('tl.created_by', $companyId)
            ->whereDate('tl.date', '>=', sprintf('%04d-01-01', $year))
            ->whereDate('tl.date', '<=', sprintf('%04d-12-31', $year))
            ->whereRaw("LEFT(coa.code, 1) IN ('6','7')")
            ->selectRaw('COALESCE(SUM(tl.debit),0) as d, COALESCE(SUM(tl.credit),0) as c')
            ->first();
        $this->line("Class 6/7 transaction_lines in {$year}: debit={$totals->d} credit={$totals->c}");

        return self::SUCCESS;
    }

    /**
     * Describe duplication shape: 'none', 'all-paired' (every distinct
     * amount appears exactly twice), or 'partial'.
     */
    private function pairAnalysis($rows): string
    {
        if ($rows->isEmpty()) {
            return 'empty';
        }
        $groups = $rows->map(fn ($r) => implode(':', $r))->countBy();
        if ($groups->every(fn ($c) => $c === 2)) {
            return 'all-paired('.$groups->count().'x2)';
        }
        if ($groups->every(fn ($c) => $c === 1)) {
            return 'none';
        }
        $doubled = $groups->filter(fn ($c) => $c === 2)->count();
        return "partial({$doubled}/{$groups->count()} doubled)";
    }
}
