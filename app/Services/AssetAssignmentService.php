<?php

namespace App\Services;

use App\Exceptions\CostCentreException;
use App\Models\AssetCostCentreAssignment;
use App\Models\CostCentre;
use App\Models\DimensionAuditEvent;
use App\Models\FixedAsset;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asset cost-centre assignments (Cost Centre Phase 2, plan §5/§6).
 *
 * Effective-dated splits of a fixed asset across cost centres.
 * One assign() call creates one generation of rows that always
 * sums to exactly 100.000000%; a new generation supersedes the
 * open one (old rows keep their valid_to, history preserved) and
 * endAssignment() closes the open generation without replacement.
 * Rows are never edited in place.
 *
 * The depreciation engine reads the assignments in force for a
 * period through effectiveAssignments() / activeSplitsOn(): the
 * split follows the asset's effective assignment for the period
 * (plan §7, D4: split by days where an assignment starts or ends
 * mid-period). Historical depreciation stays with the old centre
 * when an asset moves; future depreciation uses the new one.
 *
 * Every assign / supersede / end writes a dimension_audit_events
 * row (the Phase 1 audit table — reused, no new audit table).
 *
 * All writes are company-scoped via creatorId(): every method
 * asserts the asset belongs to the caller's company before it
 * touches it (AC11 pattern from Contract Management).
 */
class AssetAssignmentService
{
    public function __construct(
        protected CostCentreService $centres = new CostCentreService(),
        protected FixedAssetService $assets = new FixedAssetService(),
    ) {}

    // ------------------------------------------------------------------
    // Assign — create a generation, superseding the open one
    // ------------------------------------------------------------------

    /**
     * Assign an asset to one or more cost centres from $validFrom.
     *
     * @param  array<int, array{cost_centre_id: int, percentage: string}>  $splits
     *
     * @return AssetCostCentreAssignment[] the created rows
     *
     * @throws CostCentreException on any validation failure.
     */
    public function assign(FixedAsset $asset, array $splits, Carbon $validFrom, int $creatorId, int $userId): array
    {
        $this->assets->assertSameCompany($asset, $creatorId);

        if ($asset->isDisposed()) {
            throw new CostCentreException(__('A disposed or written-off asset cannot take a new cost centre assignment.'));
        }

        $validFrom = $validFrom->copy()->startOfDay();
        $clean = $this->normaliseSplits($splits, $creatorId, $validFrom);

        if (! Schema::hasTable('asset_cost_centre_assignments')) {
            throw new CostCentreException(__('The asset cost centre assignments table does not exist yet. Run the migrations first.'));
        }

        return DB::transaction(function () use ($asset, $clean, $validFrom, $creatorId, $userId) {
            // Overlap rule (plan §5): overlapping assignments for the
            // same asset must never exceed 100% for the same period.
            // An OPEN generation is superseded when the new one starts
            // strictly after it; anything else that intersects the new
            // window is refused — history is immutable, so a closed
            // generation is never truncated to make room.
            $existing = AssetCostCentreAssignment::forCompany($creatorId)
                ->forAsset((int) $asset->id)
                ->orderBy('valid_from')
                ->orderBy('id')
                ->get();
            $toSupersede = [];
            foreach ($existing as $row) {
                $rowFrom = Carbon::parse($row->valid_from)->startOfDay();
                $rowTo = $row->valid_to ? Carbon::parse($row->valid_to)->startOfDay() : null;
                if ($rowTo !== null && $rowTo->lt($validFrom)) {
                    continue; // Entirely before the new assignment.
                }
                if ($rowTo === null) {
                    if ($validFrom->lte($rowFrom)) {
                        throw new CostCentreException(
                            __('A cost centre assignment starting :date already exists for this asset. End it first, or choose a later start date.', ['date' => $rowFrom->format('Y-m-d')])
                        );
                    }
                    $toSupersede[] = $row;
                    continue;
                }
                $centre = $row->costCentre;
                throw new CostCentreException(
                    __('The new assignment overlaps the existing assignment (:centre, :from to :to). History is never rewritten — end the existing assignment first, or choose a start date after :to.', [
                        'centre' => $centre?->code ?? '#'.(int) $row->cost_centre_id,
                        'from' => $rowFrom->format('Y-m-d'),
                        'to' => $rowTo->format('Y-m-d'),
                    ])
                );
            }

            $supersedeTo = $validFrom->copy()->subDay()->format('Y-m-d');
            foreach ($toSupersede as $row) {
                $before = $row->fresh()->toArray();
                $row->update(['valid_to' => $supersedeTo]);
                $this->audit($creatorId, (int) $row->id, 'superseded', $before, $row->fresh()->toArray(), $userId);
            }

            $created = [];
            foreach ($clean as $split) {
                $row = AssetCostCentreAssignment::create([
                    'asset_id' => (int) $asset->id,
                    'company_id' => $creatorId,
                    'cost_centre_id' => $split['cost_centre_id'],
                    'percentage' => $split['percentage'],
                    'valid_from' => $validFrom->format('Y-m-d'),
                    'valid_to' => null,
                    'created_by' => $creatorId,
                ]);
                $this->audit($creatorId, (int) $row->id, 'assigned', null, $row->fresh()->toArray(), $userId);
                $created[] = $row->fresh();
            }

            return $created;
        });
    }

    /**
     * End the asset's open assignment generation on $validTo (no
     * replacement). Days after $validTo fall back to the asset's
     * own cost_centre_id for depreciation. Returns how many rows
     * were closed.
     */
    public function endAssignment(FixedAsset $asset, Carbon $validTo, int $creatorId, int $userId): int
    {
        $this->assets->assertSameCompany($asset, $creatorId);

        $validTo = $validTo->copy()->startOfDay();

        if (! Schema::hasTable('asset_cost_centre_assignments')) {
            throw new CostCentreException(__('The asset cost centre assignments table does not exist yet. Run the migrations first.'));
        }

        return DB::transaction(function () use ($asset, $validTo, $creatorId, $userId) {
            $open = AssetCostCentreAssignment::forCompany($creatorId)
                ->forAsset((int) $asset->id)
                ->whereNull('valid_to')
                ->orderBy('valid_from')
                ->get();

            if ($open->isEmpty()) {
                throw new CostCentreException(__('This asset has no open cost centre assignment to end.'));
            }

            $latestFrom = Carbon::parse($open->max('valid_from'))->startOfDay();
            if ($validTo->lt($latestFrom)) {
                throw new CostCentreException(__('The assignment cannot end before it starts (:date).', ['date' => $latestFrom->format('Y-m-d')]));
            }

            $closed = 0;
            foreach ($open as $row) {
                $before = $row->fresh()->toArray();
                $row->update(['valid_to' => $validTo->format('Y-m-d')]);
                $this->audit($creatorId, (int) $row->id, 'ended', $before, $row->fresh()->toArray(), $userId);
                $closed++;
            }

            return $closed;
        });
    }

    // ------------------------------------------------------------------
    // Reads — what is in force, when
    // ------------------------------------------------------------------

    /**
     * The split in force on one date: [['cost_centre_id' => int,
     * 'percentage' => string], ...] ordered by centre id. [] when
     * no assignment covers the date (the caller falls back to the
     * asset's own cost_centre_id). [] when the table is missing.
     */
    public function activeSplitsOn(int $assetId, int $creatorId, Carbon $date): array
    {
        if (! Schema::hasTable('asset_cost_centre_assignments')) {
            return [];
        }

        return AssetCostCentreAssignment::forCompany($creatorId)
            ->forAsset($assetId)
            ->activeOn($date)
            ->orderBy('cost_centre_id')
            ->get()
            ->map(fn ($row) => [
                'cost_centre_id' => (int) $row->cost_centre_id,
                'percentage' => (string) $row->percentage,
            ])
            ->all();
    }

    /**
     * The split(s) in force for a period, as day-segments.
     *
     * Each segment covers the days where one generation of rows is
     * active: ['from' => Carbon, 'to' => Carbon (inclusive),
     * 'days' => int, 'splits' => [['cost_centre_id' => int,
     * 'percentage' => string], ...]]. Days with no assignment are
     * NOT in the list — the caller falls back to the asset's own
     * cost centre for those days. [] when the table is missing or
     * no assignment overlaps the period.
     *
     * A segment whose rows do not sum to exactly 100.000000% is
     * corrupt data (the service never writes one) — refuse loudly
     * instead of posting a wrong split.
     */
    public function effectiveAssignments(int $assetId, int $creatorId, Carbon $periodStart, Carbon $periodEnd): array
    {
        if (! Schema::hasTable('asset_cost_centre_assignments')) {
            return [];
        }

        $periodStart = $periodStart->copy()->startOfDay();
        $periodEnd = $periodEnd->copy()->startOfDay();

        $rows = AssetCostCentreAssignment::forCompany($creatorId)
            ->forAsset($assetId)
            ->overlapping($periodStart, $periodEnd)
            ->orderBy('valid_from')
            ->orderBy('cost_centre_id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Boundaries: period edges + every row's start / end+1.
        $boundaries = [
            $periodStart->format('Y-m-d'),
            $periodEnd->copy()->addDay()->format('Y-m-d'),
        ];
        foreach ($rows as $row) {
            $from = Carbon::parse($row->valid_from)->startOfDay();
            if ($from->gt($periodStart) && $from->lte($periodEnd)) {
                $boundaries[] = $from->format('Y-m-d');
            }
            if ($row->valid_to) {
                $after = Carbon::parse($row->valid_to)->startOfDay()->addDay();
                if ($after->gt($periodStart) && $after->lte($periodEnd)) {
                    $boundaries[] = $after->format('Y-m-d');
                }
            }
        }
        $boundaries = array_values(array_unique($boundaries));
        sort($boundaries);

        $segments = [];
        for ($i = 0; $i < count($boundaries) - 1; $i++) {
            $start = Carbon::parse($boundaries[$i])->startOfDay();
            $end = Carbon::parse($boundaries[$i + 1])->startOfDay()->subDay();
            if ($end->lt($start)) {
                continue;
            }
            $active = $this->activeSplitsOn($assetId, $creatorId, $start);
            if ($active === []) {
                continue;
            }
            $sum = '0';
            foreach ($active as $split) {
                $sum = bcadd($sum, $split['percentage'], 6);
            }
            if (bccomp($sum, '100.000000', 6) !== 0) {
                throw new CostCentreException(
                    __('The cost centre assignment for this asset sums to :sum% on :date instead of 100%. Correct the assignment before posting depreciation.', [
                        'sum' => rtrim(rtrim($sum, '0'), '.'),
                        'date' => $start->format('Y-m-d'),
                    ])
                );
            }
            $segments[] = [
                'from' => $start,
                'to' => $end,
                'days' => (int) $start->diffInDays($end) + 1,
                'splits' => $active,
            ];
        }

        return $segments;
    }

    /**
     * The allocation for one date, with the asset's own centre as
     * the fallback: split rows when they exist, else a single
     * 100% line for the asset's cost_centre_id, else []. Used by
     * reports that attribute a cost to a centre on its date.
     *
     * @return array<int, array{cost_centre_id: int, percentage: string}>
     */
    public function allocationForDate(FixedAsset $asset, Carbon $date, int $creatorId): array
    {
        $splits = $this->activeSplitsOn((int) $asset->id, $creatorId, $date);
        if ($splits !== []) {
            return $splits;
        }

        if (! $asset->cost_centre_id) {
            return [];
        }

        return [[
            'cost_centre_id' => (int) $asset->cost_centre_id,
            'percentage' => '100.000000',
        ]];
    }

    /** Full assignment history for one asset, newest first. */
    public function history(FixedAsset $asset, int $creatorId)
    {
        $this->assets->assertSameCompany($asset, $creatorId);

        if (! Schema::hasTable('asset_cost_centre_assignments')) {
            return collect();
        }

        return AssetCostCentreAssignment::forCompany($creatorId)
            ->forAsset((int) $asset->id)
            ->with('costCentre')
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->get();
    }

    /** The split in force today (or on the given date), [] when none. */
    public function currentSplit(FixedAsset $asset, int $creatorId, ?Carbon $date = null): array
    {
        return $this->activeSplitsOn((int) $asset->id, $creatorId, $date ?? Carbon::today());
    }

    // ------------------------------------------------------------------
    // Validation + audit
    // ------------------------------------------------------------------

    /**
     * Normalise + validate a split set: non-empty, one row per
     * centre, every percentage > 0, the set sums to exactly
     * 100.000000%, every centre belongs to this company and can
     * take a new transaction on the start date (active, inside its
     * effective window, not a summary-only parent).
     *
     * @return array<int, array{cost_centre_id: int, percentage: string}>
     */
    protected function normaliseSplits(array $splits, int $creatorId, Carbon $validFrom): array
    {
        if ($splits === []) {
            throw new CostCentreException(__('At least one cost centre split line is required.'));
        }

        $seen = [];
        $clean = [];
        $sum = '0';
        foreach ($splits as $split) {
            $centreId = (int) ($split['cost_centre_id'] ?? 0);
            if ($centreId <= 0) {
                throw new CostCentreException(__('Each split line must select a cost centre.'));
            }
            if (isset($seen[$centreId])) {
                throw new CostCentreException(__('A cost centre may only appear once in a split.'));
            }
            $seen[$centreId] = true;

            $percentage = $split['percentage'] ?? null;
            if ($percentage === null || ! is_numeric($percentage)) {
                throw new CostCentreException(__('Each split line needs a percentage.'));
            }
            $percentage = bcadd((string) $percentage, '0', 6);
            if (bccomp($percentage, '0', 6) <= 0) {
                throw new CostCentreException(__('Split percentages must be greater than zero.'));
            }

            $sum = bcadd($sum, $percentage, 6);
            $clean[] = ['cost_centre_id' => $centreId, 'percentage' => $percentage];
        }

        if (bccomp($sum, '100.000000', 6) !== 0) {
            throw new CostCentreException(
                __('Split percentages must add up to 100% (currently :sum%).', ['sum' => rtrim(rtrim($sum, '0'), '.')])
            );
        }

        foreach ($clean as $split) {
            $centre = CostCentre::find($split['cost_centre_id']);
            if (! $centre) {
                throw new CostCentreException(__('The selected cost centre does not exist.'));
            }
            if ((int) $centre->created_by !== $creatorId) {
                throw new CostCentreException(__('Cost centre :code belongs to another company.', ['code' => $centre->code]));
            }
            $this->centres->assertCanAcceptTransactions($centre, $validFrom);
        }

        usort($clean, static fn ($a, $b) => $a['cost_centre_id'] <=> $b['cost_centre_id']);

        return $clean;
    }

    protected function audit(int $creatorId, int $entityId, string $action, ?array $before, ?array $after, ?int $actorId): void
    {
        DimensionAuditEvent::record(
            $creatorId,
            'asset_cost_centre_assignment',
            $entityId,
            $action,
            $before,
            $after,
            $actorId
        );
    }
}
