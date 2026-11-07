<?php

namespace App\Services;

use App\Models\CostCentre;
use App\Models\CostCentreAllocation;
use App\Models\JournalLineDimension;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cost centre dimension reports (Cost Centre Phase 4, plan §8/§9/§13).
 *
 * Two reports, never one mixed number (plan §7):
 *  - Expense by cost centre — ACCRUAL basis (invoice/journal date).
 *    An expense is recognised once, at the bill or journal.
 *  - Cash outflow by cost centre — CASH basis (payment date), from
 *    the Phase 3 treasury dimensions. A payment settles a bill; the
 *    expense is NOT recognised a second time at payment.
 *
 * Plus the reconciliation check and the unassigned/invalid report.
 *
 * One counting rule everywhere, so reports can never disagree with
 * the GL: a manual journal's line exists as BOTH a JournalItem and a
 * TransactionLines row with reference 'Journal' (JournalEntryPoster
 * writes both). Every query counts the line ONCE — via the JournalItem
 * for manual journals, and via the TransactionLines row for documents
 * (reference != 'Journal').
 *
 * "Eligible" lines are expense-account lines (SNC class 6, code LIKE
 * '6%') unless the caller asks for all accounts. A dimension report
 * must add up to the GL, never to a different total.
 */
class DimensionReportService
{
    public function __construct(
        protected DimensionPostingService $posting = new DimensionPostingService(),
    ) {}

    // ------------------------------------------------------------------
    // Expense by centre — accrual basis
    // ------------------------------------------------------------------

    /**
     * Expense by cost centre (accrual basis, line date). Returns rows
     * grouped by centre with an account breakdown, plus the period
     * total and the basis label. Amounts are signed (debit +, credit −).
     */
    public function expenseByCentre(
        int $creatorId,
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?int $centreId = null,
        ?int $projectId = null
    ): array {
        $grouped = $this->dimensionTotalsByCentre($creatorId, $from, $to, 'expense', $centreId, $projectId);
        $rows = $this->rowsWithCentreNames($grouped, $creatorId);

        return [
            'basis' => 'accrual',
            'basis_label' => __('Accrual basis — expense recognised at the bill or journal date, not at payment.'),
            'currency_code' => $this->posting->baseCurrency($creatorId),
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'rows' => $rows,
            'total' => $this->sumTotals($rows, 'total'),
        ];
    }

    // ------------------------------------------------------------------
    // Cash outflow by centre — cash basis (Phase 3 treasury dimensions)
    // ------------------------------------------------------------------

    /**
     * Cash outflow by cost centre (cash basis, payment date). Reads the
     * Phase 3 treasury_line_dimensions — a bill payment inherits the
     * bill's split, so the cash is shown once, at the payment. Receipts
     * (cash IN) are excluded: this is outflow only.
     */
    public function cashOutflowByCentre(
        int $creatorId,
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?int $centreId = null,
        ?int $projectId = null
    ): array {
        $query = DB::table('treasury_line_dimensions as d')
            ->join('bill_payments as bp', 'bp.id', '=', 'd.payment_id')
            ->where('d.created_by', $creatorId)
            ->where('d.status', 'active')
            ->where('d.payment_type', 'bill_payment');

        $this->applyCentreProjectFilters($query, $centreId, $projectId);
        $this->applyDateFilter($query, 'bp.date', $from, $to);

        $grouped = $query
            ->select(
                'd.cost_centre_id',
                'd.project_id',
                DB::raw('SUM(d.allocated_amount) as total'),
                DB::raw('COUNT(*) as rows')
            )
            ->groupBy('d.cost_centre_id', 'd.project_id')
            ->orderBy('d.cost_centre_id')
            ->get();

        $rows = $this->rowsWithCentreNames($grouped->all(), $creatorId);

        return [
            'basis' => 'cash',
            'basis_label' => __('Cash basis — cash paid at the payment date. The expense was recognised once, at the bill; it is not recognised again here.'),
            'currency_code' => $this->posting->baseCurrency($creatorId),
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'rows' => $rows,
            'total' => $this->sumTotals($rows, 'total'),
        ];
    }

    // ------------------------------------------------------------------
    // Reconciliation check — dimension totals = GL amounts
    // ------------------------------------------------------------------

    /**
     * The reconciliation check (plan §13): for every eligible posted
     * line in the period, the active dimension rows must sum to the
     * line's net GL amount. Returns the GL total, the dimensioned
     * total, the difference, and the detail: lines whose dimensions do
     * NOT sum to the line (true mismatches) and lines with no
     * dimensions at all (unassigned — also listed on the unassigned
     * report). A report that cannot reconcile is not shipped.
     *
     * @param  string  $scope  'expense' (class 6 accounts) or 'all'
     */
    public function reconciliation(
        int $creatorId,
        ?Carbon $from = null,
        ?Carbon $to = null,
        string $scope = 'expense'
    ): array {
        $lines = $this->eligibleLines($creatorId, $from, $to, $scope);

        $glTotal = '0.00';
        $dimTotal = '0.00';
        $mismatches = [];
        $unassigned = [];

        foreach ($lines as $line) {
            $glTotal = bcadd($glTotal, $line['net'], 2);
            $dimTotal = bcadd($dimTotal, $line['dimensioned'], 2);

            if (bccomp($line['dimensioned'], '0', 2) === 0) {
                $unassigned[] = $line;
            } elseif (bccomp($line['dimensioned'], $line['net'], 2) !== 0) {
                $mismatches[] = $line;
            }
        }

        return [
            'scope' => $scope,
            'currency_code' => $this->posting->baseCurrency($creatorId),
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'gl_total' => $glTotal,
            'dimensioned_total' => $dimTotal,
            'difference' => bcsub($glTotal, $dimTotal, 2),
            'balanced' => bccomp(bcsub($glTotal, $dimTotal, 2), '0', 2) === 0,
            'lines_checked' => count($lines),
            'mismatches' => $mismatches,
            'unassigned' => $unassigned,
        ];
    }

    // ------------------------------------------------------------------
    // Unassigned / invalid allocations report
    // ------------------------------------------------------------------

    /**
     * Lines that should carry a dimension but don't, and dimensions
     * that point at a centre that can no longer take them:
     *  A. expense lines with no active dimension rows (with the reason
     *     'Allocation exists — sync to ledger' when a posted Phase 1
     *     allocation exists for the line but was never synced);
     *  B. active dimension rows whose centre is inactive, outside its
     *     effective dates for the line date, or from another company;
     *  C. posted Phase 1 allocations whose centre is now inactive.
     */
    public function unassigned(
        int $creatorId,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): array {
        $lines = $this->eligibleLines($creatorId, $from, $to, 'expense');
        $sectionA = [];
        foreach ($lines as $line) {
            if (bccomp($line['dimensioned'], '0', 2) !== 0) {
                continue;
            }
            $line['reason'] = $this->unassignedReason($line, $creatorId);
            $sectionA[] = $line;
        }

        $sectionB = $this->invalidDimensionRows($creatorId, $from, $to);
        $sectionC = $this->invalidAllocations($creatorId);

        return [
            'currency_code' => $this->posting->baseCurrency($creatorId),
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'missing_dimensions' => $sectionA,
            'invalid_dimensions' => $sectionB,
            'invalid_allocations' => $sectionC,
        ];
    }

    // ------------------------------------------------------------------
    // Eligible lines — each economic line counted exactly once
    // ------------------------------------------------------------------

    /**
     * Eligible posted lines in the period, each with its net GL amount
     * and its dimensioned total. Manual journal lines come from
     * JournalItem; document lines come from TransactionLines with
     * reference != 'Journal' (the 'Journal' rows only mirror the
     * JournalItems and must never be counted twice).
     */
    public function eligibleLines(
        int $creatorId,
        ?Carbon $from = null,
        ?Carbon $to = null,
        string $scope = 'expense'
    ): array {
        $lines = [];
        // Dimension totals, bulk-loaded once per line type — the report
        // must not run one query per line.
        $sums = $this->dimensionSumsByLine($creatorId);

        // Manual journals — JournalItem lines, dated by the journal.
        $ji = DB::table('journal_items as ji')
            ->join('journal_entries as je', 'je.id', '=', 'ji.journal')
            ->leftJoin('chart_of_accounts as coa', 'coa.id', '=', 'ji.account')
            ->where('je.created_by', $creatorId)
            ->select('ji.id as line_id', 'ji.account as account_id', 'coa.code as account_code', 'ji.debit', 'ji.credit', 'je.date as line_date', 'je.reference as journal_reference', 'ji.description as description');
        $this->applyDateFilter($ji, 'je.date', $from, $to);
        if ($scope === 'expense') {
            $ji->where('coa.code', 'like', '6%');
        }
        foreach ($ji->orderBy('je.date')->orderBy('ji.id')->get() as $row) {
            $net = bcsub((string) $row->debit, (string) $row->credit, 2);
            $lines[] = $this->lineRow('journal_item', (int) $row->line_id, (int) $row->account_id, $row->account_code, $net, $row->line_date, $row->journal_reference, $row->description, $sums['journal_item'][(int) $row->line_id] ?? '0.00');
        }

        // Documents posted directly — TransactionLines, except the
        // 'Journal' mirrors of JournalItems.
        $tl = DB::table('transaction_lines as tl')
            ->leftJoin('chart_of_accounts as coa', 'coa.id', '=', 'tl.account_id')
            ->where('tl.created_by', $creatorId)
            ->where('tl.reference', '!=', 'Journal')
            ->select('tl.id as line_id', 'tl.account_id', 'coa.code as account_code', 'tl.debit', 'tl.credit', 'tl.date as line_date', 'tl.reference', 'tl.reference_id', 'tl.reference_sub_id');
        $this->applyDateFilter($tl, 'tl.date', $from, $to);
        if ($scope === 'expense') {
            $tl->where('coa.code', 'like', '6%');
        }
        foreach ($tl->orderBy('tl.date')->orderBy('tl.id')->get() as $row) {
            $net = bcsub((string) $row->debit, (string) $row->credit, 2);
            $lines[] = $this->lineRow('transaction_line', (int) $row->line_id, (int) $row->account_id, $row->account_code, $net, $row->line_date, (string) $row->reference.' #'.(int) $row->reference_id, null, $sums['transaction_line'][(int) $row->line_id] ?? '0.00', (string) $row->reference, (int) $row->reference_id, (int) $row->reference_sub_id);
        }

        return $lines;
    }

    /** Active dimension totals keyed by [line_type][line_id]. */
    protected function dimensionSumsByLine(int $creatorId): array
    {
        $sums = ['journal_item' => [], 'transaction_line' => []];
        $rows = DB::table('journal_line_dimensions')
            ->where('created_by', $creatorId)
            ->where('status', 'active')
            ->select('line_type', 'line_id', DB::raw('SUM(allocated_amount) as total'))
            ->groupBy('line_type', 'line_id')
            ->get();
        foreach ($rows as $row) {
            $sums[(string) $row->line_type][(int) $row->line_id] = (string) $row->total;
        }

        return $sums;
    }

    protected function lineRow(
        string $lineType,
        int $lineId,
        int $accountId,
        ?string $accountCode,
        string $net,
        $date,
        ?string $reference,
        ?string $description,
        string $dimensioned,
        ?string $tlReference = null,
        ?int $tlReferenceId = null,
        ?int $tlReferenceSubId = null
    ): array {
        return [
            'line_type' => $lineType,
            'line_id' => $lineId,
            'account_id' => $accountId,
            'account_code' => $accountCode,
            'net' => $net,
            'dimensioned' => $this->round2($dimensioned),
            'date' => $date ? Carbon::parse($date)->toDateString() : null,
            'reference' => $reference,
            'description' => $description,
            'tl_reference' => $tlReference,
            'tl_reference_id' => $tlReferenceId,
            'tl_reference_sub_id' => $tlReferenceSubId,
        ];
    }

    /** Dimension totals grouped by (centre, project) for the expense report. */
    protected function dimensionTotalsByCentre(
        int $creatorId,
        ?Carbon $from,
        ?Carbon $to,
        string $scope,
        ?int $centreId,
        ?int $projectId
    ) {
        $merged = [];

        // Manual journal lines.
        $ji = DB::table('journal_line_dimensions as d')
            ->join('journal_items as ji', 'ji.id', '=', 'd.line_id')
            ->join('journal_entries as je', 'je.id', '=', 'ji.journal')
            ->leftJoin('chart_of_accounts as coa', 'coa.id', '=', 'ji.account')
            ->where('d.created_by', $creatorId)
            ->where('d.status', 'active')
            ->where('d.line_type', 'journal_item');
        $this->applyCentreProjectFilters($ji, $centreId, $projectId, 'd');
        $this->applyDateFilter($ji, 'je.date', $from, $to);
        if ($scope === 'expense') {
            $ji->where('coa.code', 'like', '6%');
        }
        $jiRows = $ji->select('d.cost_centre_id', 'd.project_id', 'ji.account as account_id', 'coa.code as account_code', DB::raw('SUM(d.allocated_amount) as total'), DB::raw('COUNT(*) as rows'))
            ->groupBy('d.cost_centre_id', 'd.project_id', 'ji.account', 'coa.code')
            ->get();

        // Document lines (reference != 'Journal' mirrors excluded).
        $tl = DB::table('journal_line_dimensions as d')
            ->join('transaction_lines as tl', 'tl.id', '=', 'd.line_id')
            ->leftJoin('chart_of_accounts as coa', 'coa.id', '=', 'tl.account_id')
            ->where('d.created_by', $creatorId)
            ->where('d.status', 'active')
            ->where('d.line_type', 'transaction_line')
            ->where('tl.reference', '!=', 'Journal');
        $this->applyCentreProjectFilters($tl, $centreId, $projectId, 'd');
        $this->applyDateFilter($tl, 'tl.date', $from, $to);
        if ($scope === 'expense') {
            $tl->where('coa.code', 'like', '6%');
        }
        $tlRows = $tl->select('d.cost_centre_id', 'd.project_id', 'tl.account_id as account_id', 'coa.code as account_code', DB::raw('SUM(d.allocated_amount) as total'), DB::raw('COUNT(*) as rows'))
            ->groupBy('d.cost_centre_id', 'd.project_id', 'tl.account_id', 'coa.code')
            ->get();

        foreach ([$jiRows, $tlRows] as $set) {
            foreach ($set as $row) {
                $key = (int) $row->cost_centre_id.'|'.($row->project_id !== null ? (int) $row->project_id : '');
                if (! isset($merged[$key])) {
                    $merged[$key] = [
                        'cost_centre_id' => (int) $row->cost_centre_id,
                        'project_id' => $row->project_id !== null ? (int) $row->project_id : null,
                        'total' => '0.00',
                        'rows' => 0,
                        'accounts' => [],
                    ];
                }
                $merged[$key]['total'] = bcadd($merged[$key]['total'], (string) $row->total, 2);
                $merged[$key]['rows'] += (int) $row->rows;
                $accountKey = (int) $row->account_id.'|'.(string) $row->account_code;
                if (! isset($merged[$key]['accounts'][$accountKey])) {
                    $merged[$key]['accounts'][$accountKey] = [
                        'account_id' => (int) $row->account_id,
                        'account_code' => $row->account_code,
                        'total' => '0.00',
                    ];
                }
                $merged[$key]['accounts'][$accountKey]['total'] = bcadd($merged[$key]['accounts'][$accountKey]['total'], (string) $row->total, 2);
            }
        }

        foreach ($merged as $key => $row) {
            $merged[$key]['accounts'] = array_values($row['accounts']);
        }

        return array_values($merged);
    }

    /** Attach centre code/name and the including-children total. */
    protected function rowsWithCentreNames(array $grouped, int $creatorId): array
    {
        $centres = CostCentre::forCompany($creatorId)->get()->keyBy('id');
        $childrenOf = [];
        foreach ($centres as $centre) {
            if ($centre->parent_id) {
                $childrenOf[(int) $centre->parent_id][] = (int) $centre->id;
            }
        }

        $byCentre = [];
        foreach ($grouped as $row) {
            // Cash rows arrive as stdClass from the query builder; expense
            // rows arrive as plain arrays. Normalise here so both callers
            // get the same shape out.
            $row = (array) $row;
            $centre = $centres->get((int) $row['cost_centre_id']);
            $row['cost_centre_code'] = $centre?->code ?? '#'.(int) $row['cost_centre_id'];
            $row['cost_centre_name'] = $centre?->name;
            $row['total'] = $this->round2((string) $row['total']);
            $byCentre[(int) $row['cost_centre_id']] = $row;
        }

        // Total including descendants: a parent row shows its own total
        // plus everything posted to its children.
        foreach ($byCentre as $id => $row) {
            $total = (string) $row['total'];
            foreach ($this->descendantIds($id, $childrenOf) as $descendantId) {
                if (isset($byCentre[$descendantId])) {
                    $total = bcadd($total, (string) $byCentre[$descendantId]['total'], 2);
                }
            }
            $byCentre[$id]['total_including_children'] = $total;
        }

        return array_values($byCentre);
    }

    protected function descendantIds(int $centreId, array $childrenOf): array
    {
        $ids = [];
        foreach ($childrenOf[$centreId] ?? [] as $childId) {
            $ids[] = $childId;
            $ids = array_merge($ids, $this->descendantIds($childId, $childrenOf));
        }

        return $ids;
    }

    protected function sumTotals(array $rows, string $key): string
    {
        $total = '0.00';
        foreach ($rows as $row) {
            $total = bcadd($total, (string) ($row[$key] ?? '0'), 2);
        }

        return $total;
    }

    /** Why does this line have no dimensions? */
    protected function unassignedReason(array $line, int $creatorId): string
    {
        if ($line['line_type'] !== 'transaction_line') {
            return __('No dimensions');
        }
        $reference = $line['tl_reference'];
        if (! in_array($reference, ['Bill', 'Bill Account'], true)) {
            return __('No dimensions');
        }

        $sourceLineType = $reference === 'Bill' ? CostCentreAllocation::LINE_BILL_PRODUCT : CostCentreAllocation::LINE_BILL_ACCOUNT;
        $sourceType = CostCentreAllocation::forCompany($creatorId)
            ->posted()
            ->where('source_id', (int) $line['tl_reference_id'])
            ->where('source_line_type', $sourceLineType)
            ->where('source_line_id', (int) $line['tl_reference_sub_id'])
            ->exists();

        return $sourceType
            ? __('Allocation exists — sync to ledger')
            : __('No dimensions');
    }

    /** Active dimension rows whose centre is invalid for the line date. */
    protected function invalidDimensionRows(int $creatorId, ?Carbon $from, ?Carbon $to): array
    {
        $rows = JournalLineDimension::forCompany($creatorId)
            ->active()
            ->with('costCentre')
            ->orderBy('id')
            ->get();

        $invalid = [];
        foreach ($rows as $row) {
            $centre = $row->costCentre;
            $reason = null;
            if (! $centre) {
                $reason = __('Cost centre no longer exists');
            } elseif ((int) $centre->created_by !== $creatorId) {
                $reason = __('Cost centre belongs to another company');
            } elseif (! $centre->isActive()) {
                $reason = __('Cost centre is deactivated');
            } else {
                // Effective dates are judged against the line date.
                $lineDate = $this->lineDateForRow($row);
                if ($lineDate && ! $centre->isEffectiveOn($lineDate)) {
                    $reason = __('Cost centre was not effective on the line date');
                }
            }

            if ($reason === null) {
                continue;
            }
            // Date filter applies to the line date when it is known.
            $lineDate = $this->lineDateForRow($row);
            if ($lineDate && $from && $lineDate->lt($from->startOfDay())) {
                continue;
            }
            if ($lineDate && $to && $lineDate->gt($to->endOfDay())) {
                continue;
            }

            $invalid[] = [
                'dimension_id' => (int) $row->id,
                'line_type' => $row->line_type,
                'line_id' => (int) $row->line_id,
                'cost_centre_id' => (int) $row->cost_centre_id,
                'cost_centre_code' => $centre?->code,
                'allocated_amount' => (string) $row->allocated_amount,
                'source' => $row->source,
                'reason' => $reason,
            ];
        }

        return $invalid;
    }

    protected function lineDateForRow(JournalLineDimension $row): ?Carbon
    {
        if ($row->line_type === JournalLineDimension::LINE_JOURNAL_ITEM) {
            $item = DB::table('journal_items')->where('id', $row->line_id)->first();
            if (! $item) {
                return null;
            }
            $journal = DB::table('journal_entries')->where('id', $item->journal)->first();

            return $journal && $journal->date ? Carbon::parse($journal->date) : null;
        }

        $line = DB::table('transaction_lines')->where('id', $row->line_id)->first();

        return $line && $line->date ? Carbon::parse($line->date) : null;
    }

    /** Posted Phase 1 allocations whose centre is now inactive. */
    protected function invalidAllocations(int $creatorId): array
    {
        $allocations = CostCentreAllocation::forCompany($creatorId)
            ->posted()
            ->with(['lines.costCentre'])
            ->orderBy('id')
            ->get();

        $invalid = [];
        foreach ($allocations as $allocation) {
            foreach ($allocation->lines as $line) {
                $centre = $line->costCentre;
                if ($centre && $centre->isActive()) {
                    continue;
                }
                $invalid[] = [
                    'allocation_id' => (int) $allocation->id,
                    'source_type' => $allocation->source_type,
                    'source_id' => (int) $allocation->source_id,
                    'cost_centre_id' => (int) $line->cost_centre_id,
                    'cost_centre_code' => $centre?->code,
                    'allocated_amount' => (string) $line->allocated_amount,
                    'reason' => $centre ? __('Cost centre is deactivated') : __('Cost centre no longer exists'),
                ];
            }
        }

        return $invalid;
    }

    // ------------------------------------------------------------------
    // Query helpers
    // ------------------------------------------------------------------

    protected function applyDateFilter($query, string $column, ?Carbon $from, ?Carbon $to): void
    {
        if ($from) {
            $query->whereDate($column, '>=', $from->toDateString());
        }
        if ($to) {
            $query->whereDate($column, '<=', $to->toDateString());
        }
    }

    protected function applyCentreProjectFilters($query, ?int $centreId, ?int $projectId, string $alias = 'd'): void
    {
        if ($centreId !== null) {
            $query->where($alias.'.cost_centre_id', $centreId);
        }
        if ($projectId !== null) {
            $query->where($alias.'.project_id', $projectId);
        }
    }

    protected function round2(string $value): string
    {
        $negative = str_starts_with(ltrim($value), '-');
        $abs = $negative ? substr(ltrim($value), 1) : ltrim($value);
        $shifted = bcadd($abs, '0.005', 10);
        $rounded = bcdiv($shifted, '1', 2);

        return $negative ? '-'.$rounded : $rounded;
    }
}
