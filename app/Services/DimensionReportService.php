<?php

namespace App\Services;

use App\Models\CostCentre;
use App\Models\CostCentreAllocation;
use App\Models\FixedAsset;
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
        protected ?AssetAssignmentService $assignments = null,
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
    // Phase 2 — Depreciation by asset and centre
    // ------------------------------------------------------------------

    /**
     * Depreciation by asset and centre (plan §9/§13, Phase 2).
     *
     * Reads the SAME journal_line_dimensions rows the depreciation
     * engine wrote (via DimensionPostingService), so the report can
     * never disagree with the posted dimensions. The report is
     * month-aligned: from/to are normalised to the first/last day
     * of their months, because a depreciation run posts one month
     * at a time (journal date = period end).
     *
     * Reconciliation: `dimension_total` is the sum of the engine's
     * dimension rows for the period and `posted_total` is the sum
     * of the posted depreciation entries for the same period; both
     * ignore the cost-centre filter (a posted entry carries only
     * its period-end centre) and both respect the asset filters.
     * `matches` is true when the two agree — the engine's
     * dimensions reconcile to the money it posted.
     */
    public function depreciationByAssetAndCentre(
        int $creatorId,
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?int $centreId = null,
        ?int $assetId = null,
        ?string $search = null,
        ?int $categoryId = null,
        ?int $locationId = null,
        ?string $status = null
    ): array {
        if (! Schema::hasTable('journal_line_dimensions') || ! Schema::hasTable('fixed_asset_depreciation_entries')) {
            return $this->emptyAssetReport($creatorId, $from, $to, 'depreciation');
        }

        $from = $from?->copy()->startOfMonth();
        $to = $to?->copy()->endOfMonth();

        $query = DB::table('journal_line_dimensions as d')
            ->join('journal_items as ji', 'ji.id', '=', 'd.line_id')
            ->join('journal_entries as je', 'je.id', '=', 'ji.journal')
            ->join('fixed_assets as fa', 'fa.id', '=', 'd.asset_id')
            ->leftJoin('cost_centres as cc', 'cc.id', '=', 'd.cost_centre_id')
            ->where('d.created_by', $creatorId)
            ->where('d.status', JournalLineDimension::STATUS_ACTIVE)
            ->where('d.line_type', JournalLineDimension::LINE_JOURNAL_ITEM)
            ->whereNotNull('d.asset_id');
        $this->applyDateFilter($query, 'je.date', $from, $to);
        $this->applyAssetFilters($query, 'fa', $assetId, $search, $categoryId, $locationId, $status);
        if ($centreId !== null) {
            $query->where('d.cost_centre_id', $centreId);
        }

        $grouped = $query
            ->select(
                'd.asset_id',
                'fa.code as asset_code',
                'fa.name as asset_name',
                'fa.status as asset_status',
                'd.cost_centre_id',
                'cc.code as cost_centre_code',
                'cc.name as cost_centre_name',
                DB::raw('SUM(d.allocated_amount) as total'),
                DB::raw('COUNT(*) as rows')
            )
            ->groupBy('d.asset_id', 'fa.code', 'fa.name', 'fa.status', 'd.cost_centre_id', 'cc.code', 'cc.name')
            ->orderBy('fa.code')
            ->orderBy('d.cost_centre_id')
            ->get();

        $rows = $grouped->map(fn ($row) => [
            'asset_id' => (int) $row->asset_id,
            'asset_code' => $row->asset_code,
            'asset_name' => $row->asset_name,
            'asset_status' => $row->asset_status,
            'cost_centre_id' => $row->cost_centre_id ? (int) $row->cost_centre_id : null,
            'cost_centre_code' => $row->cost_centre_code,
            'cost_centre_name' => $row->cost_centre_name,
            'total' => $this->round2((string) $row->total),
            'rows' => (int) $row->rows,
        ])->all();

        // Engine reconciliation for the same period (asset filters
        // apply; the centre filter does not — see docblock).
        $dimensionTotal = $this->depreciationDimensionTotal($creatorId, $from, $to, $assetId, $search, $categoryId, $locationId, $status);
        $postedTotal = $this->depreciationPostedTotal($creatorId, $from, $to, $assetId, $search, $categoryId, $locationId, $status);

        return [
            'basis' => 'depreciation',
            'basis_label' => __('Depreciation expense by asset and cost centre, from the dimension rows the depreciation engine posted. Month-aligned: a run posts one month at a time.'),
            'currency_code' => $this->posting->baseCurrency($creatorId),
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'rows' => $rows,
            'total' => $this->sumTotals($rows, 'total'),
            'dimension_total' => $dimensionTotal,
            'posted_total' => $postedTotal,
            'matches' => bccomp($dimensionTotal, $postedTotal, 2) === 0,
        ];
    }

    /** Sum of the engine's active dimension rows for the period (asset filters apply). */
    protected function depreciationDimensionTotal(
        int $creatorId,
        ?Carbon $from,
        ?Carbon $to,
        ?int $assetId,
        ?string $search,
        ?int $categoryId,
        ?int $locationId,
        ?string $status
    ): string {
        $query = DB::table('journal_line_dimensions as d')
            ->join('journal_items as ji', 'ji.id', '=', 'd.line_id')
            ->join('journal_entries as je', 'je.id', '=', 'ji.journal')
            ->join('fixed_assets as fa', 'fa.id', '=', 'd.asset_id')
            ->where('d.created_by', $creatorId)
            ->where('d.status', JournalLineDimension::STATUS_ACTIVE)
            ->where('d.line_type', JournalLineDimension::LINE_JOURNAL_ITEM)
            ->whereNotNull('d.asset_id');
        $this->applyDateFilter($query, 'je.date', $from, $to);
        $this->applyAssetFilters($query, 'fa', $assetId, $search, $categoryId, $locationId, $status);

        return $this->round2((string) ($query->sum('d.allocated_amount') ?? '0'));
    }

    /** Sum of posted depreciation entries for the period (asset filters apply). */
    protected function depreciationPostedTotal(
        int $creatorId,
        ?Carbon $from,
        ?Carbon $to,
        ?int $assetId,
        ?string $search,
        ?int $categoryId,
        ?int $locationId,
        ?string $status
    ): string {
        $query = DB::table('fixed_asset_depreciation_entries as e')
            ->join('fixed_assets as fa', 'fa.id', '=', 'e.asset_id')
            ->where('e.created_by', $creatorId)
            ->where('e.status', 'posted');
        if ($from) {
            $query->whereDate('e.period', '>=', $from->copy()->startOfMonth()->toDateString());
        }
        if ($to) {
            $query->whereDate('e.period', '<=', $to->copy()->startOfMonth()->toDateString());
        }
        $this->applyAssetFilters($query, 'fa', $assetId, $search, $categoryId, $locationId, $status);

        return $this->round2((string) ($query->sum('e.posted_amount') ?? '0'));
    }

    // ------------------------------------------------------------------
    // Phase 2 — Maintenance by asset and centre
    // ------------------------------------------------------------------

    /**
     * Maintenance by asset and centre (plan §9/§13, Phase 2).
     *
     * Reads the Phase 4 maintenance costs (each cost posted Dr
     * maintenance expense 6226 / Cr AP 421 at recording time) and
     * attributes each cost to the centre(s) in force on the cost
     * date — the split assignment for that date when one exists,
     * else the asset's own cost centre. The maintenance posting
     * path itself is untouched (no dimensions were written at
     * posting time); the attribution is a report-time read of the
     * same assignment history the depreciation engine uses, so
     * the two reports agree on which centre owned the asset when.
     *
     * Costs with no centre at all land on an "Unassigned"
     * pseudo-row so the report total always reconciles:
     * `journal_total` is the sum of the Dr class-62 lines of the
     * same costs' journals, and `matches` is true when the two
     * agree. Month-aligned like the depreciation report.
     */
    public function maintenanceByAssetAndCentre(
        int $creatorId,
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?int $centreId = null,
        ?int $assetId = null,
        ?string $search = null,
        ?int $categoryId = null,
        ?int $locationId = null,
        ?string $status = null
    ): array {
        if (! Schema::hasTable('fixed_asset_maintenance_costs') || ! Schema::hasTable('fixed_assets')) {
            return $this->emptyAssetReport($creatorId, $from, $to, 'maintenance');
        }

        $from = $from?->copy()->startOfMonth();
        $to = $to?->copy()->endOfMonth();

        $query = DB::table('fixed_asset_maintenance_costs as c')
            ->join('fixed_assets as fa', 'fa.id', '=', 'c.asset_id')
            ->where('c.created_by', $creatorId);
        $this->applyDateFilter($query, 'c.recorded_at', $from, $to);
        $this->applyAssetFilters($query, 'fa', $assetId, $search, $categoryId, $locationId, $status);
        if ($assetId !== null) {
            $query->where('c.asset_id', $assetId);
        }

        $costs = $query
            ->select('c.id', 'c.asset_id', 'c.amount', 'c.recorded_at', 'c.journal_id', 'fa.code as asset_code', 'fa.name as asset_name', 'fa.status as asset_status')
            ->orderBy('fa.code')
            ->orderBy('c.recorded_at')
            ->get();

        $assignments = $this->assignments ?? new AssetAssignmentService();
        $centres = CostCentre::where('created_by', $creatorId)->get()->keyBy('id');
        $grouped = [];
        $journalIds = [];
        foreach ($costs as $cost) {
            $asset = FixedAsset::find((int) $cost->asset_id);
            $allocation = $asset
                ? $assignments->allocationForDate($asset, Carbon::parse($cost->recorded_at), $creatorId)
                : [];
            $amount = $this->round2((string) $cost->amount);
            if ($cost->journal_id) {
                $journalIds[] = (int) $cost->journal_id;
            }

            if ($allocation === []) {
                $this->addAssetReportRow($grouped, (int) $cost->asset_id, $cost->asset_code, $cost->asset_name, $cost->asset_status, null, null, null, $amount);
                continue;
            }

            // Split the cost by the allocation, 2dp, residual on
            // the last centre (Phase 1 pattern).
            $allocated = '0';
            $lastIndex = count($allocation) - 1;
            foreach ($allocation as $index => $split) {
                $splitCentreId = (int) $split['cost_centre_id'];
                $share = $index === $lastIndex
                    ? bcsub($amount, $allocated, 2)
                    : bcdiv(bcmul($amount, (string) $split['percentage'], 10), '100', 2);
                $allocated = bcadd($allocated, $share, 2);
                $centre = $centres->get($splitCentreId);
                $this->addAssetReportRow($grouped, (int) $cost->asset_id, $cost->asset_code, $cost->asset_name, $cost->asset_status, $splitCentreId, $centre?->code, $centre?->name, $share);
            }
        }

        $rows = array_values($grouped);
        usort($rows, static fn ($a, $b) => [$a['asset_code'], (string) $a['cost_centre_code']] <=> [$b['asset_code'], (string) $b['cost_centre_code']]);
        // The reconciliation compares the UNFILTERED report total
        // with the journal total: a maintenance journal carries no
        // centre, so a centre filter can never apply to it.
        $reportTotal = $this->sumTotals($rows, 'total');
        if ($centreId !== null) {
            $rows = array_values(array_filter($rows, static fn ($row) => $row['cost_centre_id'] === $centreId));
        }

        // Journal reconciliation: the Dr class-62 lines of the
        // same costs' journals (asset filters apply; centre does
        // not — a maintenance journal carries no centre).
        $journalTotal = $this->maintenanceJournalTotal($creatorId, $from, $to, $assetId, $search, $categoryId, $locationId, $status);

        return [
            'basis' => 'maintenance',
            'basis_label' => __('Maintenance cost by asset and cost centre, attributed to the centre in force on the cost date. Month-aligned like the depreciation report.'),
            'currency_code' => $this->posting->baseCurrency($creatorId),
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'rows' => $rows,
            'total' => $this->sumTotals($rows, 'total'),
            'report_total' => $reportTotal,
            'journal_total' => $journalTotal,
            'matches' => bccomp($reportTotal, $journalTotal, 2) === 0,
        ];
    }

    /** Sum of the Dr class-62 lines of the maintenance journals for the period. */
    protected function maintenanceJournalTotal(
        int $creatorId,
        ?Carbon $from,
        ?Carbon $to,
        ?int $assetId,
        ?string $search,
        ?int $categoryId,
        ?int $locationId,
        ?string $status
    ): string {
        if (! Schema::hasTable('fixed_asset_maintenance_costs')) {
            return '0.00';
        }

        $costs = DB::table('fixed_asset_maintenance_costs as c')
            ->join('fixed_assets as fa', 'fa.id', '=', 'c.asset_id')
            ->where('c.created_by', $creatorId)
            ->whereNotNull('c.journal_id');
        $this->applyDateFilter($costs, 'c.recorded_at', $from, $to);
        $this->applyAssetFilters($costs, 'fa', $assetId, $search, $categoryId, $locationId, $status);
        if ($assetId !== null) {
            $costs->where('c.asset_id', $assetId);
        }
        $journalIds = $costs->pluck('c.journal_id')->unique()->all();
        if ($journalIds === []) {
            return '0.00';
        }

        $total = DB::table('journal_items as ji')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'ji.account')
            ->whereIn('ji.journal', $journalIds)
            ->where('coa.code', 'like', '62%')
            ->where('ji.debit', '>', 0)
            ->sum('ji.debit');

        return $this->round2((string) ($total ?? '0'));
    }

    /** Shared asset filters for the Phase 2 reports (alias 'fa' = fixed_assets). */
    protected function applyAssetFilters($query, string $alias, ?int $assetId, ?string $search, ?int $categoryId, ?int $locationId, ?string $status): void
    {
        if ($assetId !== null) {
            $query->where($alias.'.id', $assetId);
        }
        if ($categoryId !== null) {
            $query->where($alias.'.category_id', $categoryId);
        }
        if ($locationId !== null) {
            $query->where($alias.'.location_id', $locationId);
        }
        if ($status !== null && $status !== '') {
            $query->where($alias.'.status', $status);
        }
        if ($search !== null && $search !== '') {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            $query->where(function ($q) use ($alias, $term) {
                $q->where($alias.'.code', 'like', $term)
                    ->orWhere($alias.'.name', 'like', $term);
            });
        }
    }

    protected function addAssetReportRow(array &$grouped, int $assetId, ?string $assetCode, ?string $assetName, ?string $assetStatus, ?int $centreId, ?string $centreCode, ?string $centreName, string $amount): void
    {
        $key = $assetId.'|'.($centreId ?? 'none');
        if (! isset($grouped[$key])) {
            $grouped[$key] = [
                'asset_id' => $assetId,
                'asset_code' => $assetCode,
                'asset_name' => $assetName,
                'asset_status' => $assetStatus,
                'cost_centre_id' => $centreId,
                'cost_centre_code' => $centreCode,
                'cost_centre_name' => $centreName,
                'total' => '0.00',
                'rows' => 0,
            ];
        }
        $grouped[$key]['total'] = bcadd($grouped[$key]['total'], $amount, 2);
        $grouped[$key]['rows']++;
    }

    protected function emptyAssetReport(int $creatorId, ?Carbon $from, ?Carbon $to, string $basis): array
    {
        return [
            'basis' => $basis,
            'basis_label' => __('No fixed asset data is available yet.'),
            'currency_code' => $this->posting->baseCurrency($creatorId),
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'rows' => [],
            'total' => '0.00',
            'report_total' => '0.00',
            'dimension_total' => '0.00',
            'posted_total' => '0.00',
            'journal_total' => '0.00',
            'matches' => true,
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
        // One bulk lookup for "a posted allocation exists for this
        // line" — the old code ran one query per unassigned line here
        // (Phase 6 hardening).
        $allocatedLineKeys = $this->postedAllocationLineKeys($creatorId);
        $sectionA = [];
        foreach ($lines as $line) {
            if (bccomp($line['dimensioned'], '0', 2) !== 0) {
                continue;
            }
            $line['reason'] = $this->unassignedReason($line, $allocatedLineKeys);
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
    protected function unassignedReason(array $line, array $allocatedLineKeys): string
    {
        if ($line['line_type'] !== 'transaction_line') {
            return __('No dimensions');
        }
        $reference = $line['tl_reference'];
        if (! in_array($reference, ['Bill', 'Bill Account'], true)) {
            return __('No dimensions');
        }

        $key = $reference.'|'.(int) $line['tl_reference_id'].'|'.(int) $line['tl_reference_sub_id'];

        return isset($allocatedLineKeys[$key])
            ? __('Allocation exists — sync to ledger')
            : __('No dimensions');
    }

    /** Posted bill allocations, keyed by "reference|bill id|line id" —
     *  one query for the whole unassigned report. */
    protected function postedAllocationLineKeys(int $creatorId): array
    {
        $keys = [];
        $rows = CostCentreAllocation::forCompany($creatorId)
            ->posted()
            ->whereIn('source_line_type', [
                CostCentreAllocation::LINE_BILL_PRODUCT,
                CostCentreAllocation::LINE_BILL_ACCOUNT,
            ])
            ->get(['source_id', 'source_line_type', 'source_line_id']);
        foreach ($rows as $row) {
            $reference = $row->source_line_type === CostCentreAllocation::LINE_BILL_PRODUCT ? 'Bill' : 'Bill Account';
            $keys[$reference.'|'.(int) $row->source_id.'|'.(int) $row->source_line_id] = true;
        }

        return $keys;
    }

    /** Active dimension rows whose centre is invalid for the line date. */
    protected function invalidDimensionRows(int $creatorId, ?Carbon $from, ?Carbon $to): array
    {
        $rows = JournalLineDimension::forCompany($creatorId)
            ->active()
            ->with('costCentre')
            ->orderBy('id')
            ->get();

        // Line dates, bulk-loaded once per line type — the old code ran
        // one query per dimension row here (Phase 6 hardening).
        $dates = $this->lineDatesForRows($rows);

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
                $lineDate = $dates[$row->line_type.'|'.(int) $row->line_id] ?? null;
                if ($lineDate && ! $centre->isEffectiveOn($lineDate)) {
                    $reason = __('Cost centre was not effective on the line date');
                }
            }

            if ($reason === null) {
                continue;
            }
            // Date filter applies to the line date when it is known.
            $lineDate = $dates[$row->line_type.'|'.(int) $row->line_id] ?? null;
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

    /** Line dates for a set of dimension rows, keyed by
     *  "line_type|line_id" — two queries at most, never one per row. */
    protected function lineDatesForRows($rows): array
    {
        $dates = [];
        $tlIds = [];
        $jiIds = [];
        foreach ($rows as $row) {
            if ($row->line_type === JournalLineDimension::LINE_JOURNAL_ITEM) {
                $jiIds[] = (int) $row->line_id;
            } else {
                $tlIds[] = (int) $row->line_id;
            }
        }

        if ($tlIds !== []) {
            foreach (DB::table('transaction_lines')->whereIn('id', array_unique($tlIds))->get(['id', 'date']) as $line) {
                if ($line->date) {
                    $dates['transaction_line|'.(int) $line->id] = Carbon::parse($line->date);
                }
            }
        }

        if ($jiIds !== []) {
            $items = DB::table('journal_items')->whereIn('id', array_unique($jiIds))->get(['id', 'journal']);
            $journalIds = [];
            foreach ($items as $item) {
                $journalIds[(int) $item->journal] = true;
            }
            $journalDates = DB::table('journal_entries')->whereIn('id', array_keys($journalIds))->pluck('date', 'id');
            foreach ($items as $item) {
                $date = $journalDates[(int) $item->journal] ?? null;
                if ($date) {
                    $dates['journal_item|'.(int) $item->id] = Carbon::parse($date);
                }
            }
        }

        return $dates;
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
