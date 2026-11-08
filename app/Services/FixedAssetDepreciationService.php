<?php

namespace App\Services;

use App\Exceptions\CostCentreException;
use App\Exceptions\FixedAssetException;
use App\Models\ChartOfAccount;
use App\Models\CostCentre;
use App\Models\FixedAsset;
use App\Models\FixedAssetAccountingEntry;
use App\Models\FixedAssetApproval;
use App\Models\FixedAssetAssignment;
use App\Models\FixedAssetDepreciationEntry;
use App\Models\FixedAssetDepreciationRun;
use App\Models\FixedAssetDepreciationSchedule;
use App\Models\FixedAssetTransfer;
use App\Models\JournalItem;
use App\Models\JournalLineDimension;
use App\Services\Accounting\ChartAccountResolver;
use App\Services\Accounting\JournalEntryPoster;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed Assets Phase 3 — straight-line depreciation (plan §5,
 * §10; spec §13, §14).
 *
 * Owns the monthly run: preview (pure calculation, nothing
 * persisted) → create → review → approval (one
 * FixedAssetApproval per asset, no self-approval) → post.
 * Posting writes ONE journal per run through JournalEntryPoster
 * only: Dr depreciation expense (class 63) / Cr accumulated
 * depreciation (class 32), one Dr line per asset and one Cr
 * line per accumulated-depreciation account.
 *
 * Amounts: the Phase 1 schedule shell is the source of truth
 * and is never regenerated here. A period posts the schedule
 * row's amount rounded to 2dp; the asset's FINAL period posts
 * (depreciable base − everything posted so far), so the posted
 * total is exact and the final book value is exactly the
 * residual value. No pro-rata in v1 (plan D6: monthly from the
 * depreciation start date, no pro-rata unless the accountant
 * says otherwise) — the shell charges a full first month and
 * this engine posts the shell amounts verbatim.
 *
 * Guards (service layer, not only UI): a period posts only
 * once (unique company+asset+period key + run check), a closed
 * fiscal year blocks posting (same FY-CLOSE rule as
 * capitalization), a fully-depreciated asset posts nothing
 * more, disposed / written-off assets never depreciate.
 *
 * Cost centres (plan §12c): the depreciation amount follows
 * the asset's effective cost-centre assignment for the period.
 * A mid-period assignment acceptance or transfer receipt
 * splits the period by days (receipt/acceptance date belongs
 * to the new centre). The split is written to the existing
 * journal_line_dimensions table via DimensionPostingService —
 * no new dimension table. An asset with no cost centre posts
 * without dimensions; the journal still balances.
 *
 * All writes are company-scoped via creatorId(): every method
 * asserts the row belongs to the caller's company before it
 * touches it (AC11 pattern from Contract Management).
 */
class FixedAssetDepreciationService
{
    public function __construct(
        protected FixedAssetService $assets,
        protected FixedAssetAcquisitionService $acquisitions,
        protected JournalEntryPoster $poster,
        protected ChartAccountResolver $resolver,
        protected DimensionPostingService $dimensions = new DimensionPostingService(),
    ) {
    }

    // ------------------------------------------------------------------
    // Period helpers
    // ------------------------------------------------------------------

    /** 'YYYY-MM' → first day of that month. Throws on a bad value. */
    public function periodDate(string $periodYm): Carbon
    {
        $period = Carbon::createFromFormat('Y-m-d', $periodYm.'-01');
        if (! $period || $period->format('Y-m') !== $periodYm) {
            throw new FixedAssetException('Invalid depreciation period. Use YYYY-MM.');
        }

        return $period->startOfDay();
    }

    /** Asset statuses that never depreciate (closure). */
    protected function terminalStatuses(): array
    {
        return [FixedAsset::STATUS_DISPOSED, FixedAsset::STATUS_WRITTEN_OFF];
    }

    /** Depreciable base in base currency (4dp): cost − residual − opening accumulated depreciation. */
    public function depreciableBase(FixedAsset $asset): string
    {
        $residualBase = bcmul((string) ($asset->residual_value ?? '0'), (string) ($asset->exchange_rate ?? '1'), 4);
        $openingAccum = (string) ($asset->opening_accumulated_depreciation ?? '0');

        return bcsub(bcsub((string) $asset->amount_base, $residualBase, 4), $openingAccum, 4);
    }

    /** Sum of everything already posted for one asset (2dp strings). */
    public function postedTotalForAsset(int $assetId, int $creatorId): string
    {
        $total = FixedAssetDepreciationEntry::where('created_by', $creatorId)
            ->where('asset_id', $assetId)
            ->where('status', FixedAssetDepreciationEntry::STATUS_POSTED)
            ->sum('posted_amount');

        return bcadd((string) $total, '0', 2);
    }

    // ------------------------------------------------------------------
    // Preview — pure calculation, nothing persisted
    // ------------------------------------------------------------------

    /**
     * Compute the period's lines from the Phase 1 schedule shell.
     *
     * Each line: asset, schedule row, planned amount (4dp), amount
     * to post (2dp), whether it is the asset's final period, the
     * cost-centre split for the period, and the two GL accounts.
     * Nothing is written — the create step calls this same method
     * and persists its result.
     *
     * @return array<int, array{asset: FixedAsset, schedule: FixedAssetDepreciationSchedule, planned: string, amount: string, is_last: bool, split: array<int, array{cost_centre_id: int, percentage: string, cost_centre_code: string|null}>, expense_account_id: int, accum_account_id: int}>
     */
    public function computeLines(int $creatorId, Carbon $period): array
    {
        $periodStart = $period->copy()->startOfMonth();
        $periodEnd = $period->copy()->endOfMonth();

        $rows = FixedAssetDepreciationSchedule::where('created_by', $creatorId)
            ->whereDate('period', $periodStart->format('Y-m-d'))
            ->where('status', FixedAssetDepreciationSchedule::STATUS_PLANNED)
            ->orderBy('asset_id')
            ->get();

        $lines = [];
        foreach ($rows as $row) {
            $asset = $row->asset;
            if (! $asset) {
                continue;
            }
            if (in_array($asset->status, $this->terminalStatuses(), true)) {
                continue;
            }
            // A POSTED entry for this asset + period means the
            // period is already done — never compute it twice.
            // (A merely calculated entry from this run itself does
            // not exclude the asset — posting recomputes the same
            // lines it created.)
            if (FixedAssetDepreciationEntry::where('created_by', $creatorId)
                ->where('asset_id', $asset->id)
                ->whereDate('period', $periodStart->format('Y-m-d'))
                ->where('status', FixedAssetDepreciationEntry::STATUS_POSTED)
                ->exists()) {
                continue;
            }

            $amount = $this->amountToPost($asset, $row, $creatorId);
            if (bccomp($amount, '0', 2) <= 0) {
                continue;
            }

            $lines[] = [
                'asset' => $asset,
                'schedule' => $row,
                'planned' => (string) $row->period_depreciation,
                'amount' => $amount,
                'is_last' => $this->isLastPlannedRow($row, $creatorId),
                'split' => $this->costCentreSplitForPeriod($asset, $periodStart, $periodEnd, $creatorId),
                'expense_account_id' => $this->resolveExpenseAccount($asset, $creatorId),
                'accum_account_id' => $this->resolveAccumAccount($asset, $creatorId),
            ];
        }

        return $lines;
    }

    /**
     * Amount to post for one schedule row (2dp). Normally the
     * row's amount rounded to 2dp; the asset's FINAL period posts
     * (depreciable base − everything posted so far) so the posted
     * total is exact and the final book value is exactly the
     * residual value.
     */
    protected function amountToPost(FixedAsset $asset, FixedAssetDepreciationSchedule $row, int $creatorId): string
    {
        if ($this->isLastPlannedRow($row, $creatorId)) {
            // The remainder (base − everything posted so far) is
            // only the final row's amount when every earlier row
            // is already posted. If an earlier month is still
            // unposted, the remainder still contains those months'
            // amounts — posting it now would over-depreciate the
            // asset (e.g. posting Jan before Nov/Dec would post the
            // whole 50,000 at once). Until the earlier rows post,
            // the final row posts its own rounded monthly amount.
            $earlierUnposted = FixedAssetDepreciationSchedule::where('created_by', $creatorId)
                ->where('asset_id', $asset->id)
                ->whereDate('period', '<', $row->period->format('Y-m-d'))
                ->where('status', '!=', FixedAssetDepreciationSchedule::STATUS_POSTED)
                ->exists();
            if ($earlierUnposted) {
                return number_format((float) $row->period_depreciation, 2, '.', '');
            }

            $remaining = bcsub($this->depreciableBase($asset), $this->postedTotalForAsset((int) $asset->id, $creatorId), 4);

            return number_format((float) $remaining, 2, '.', '');
        }

        return number_format((float) $row->period_depreciation, 2, '.', '');
    }

    protected function isLastPlannedRow(FixedAssetDepreciationSchedule $row, int $creatorId): bool
    {
        return ! FixedAssetDepreciationSchedule::where('created_by', $creatorId)
            ->where('asset_id', $row->asset_id)
            ->where('status', FixedAssetDepreciationSchedule::STATUS_PLANNED)
            ->where('period_number', '>', (int) $row->period_number)
            ->exists();
    }

    // ------------------------------------------------------------------
    // Cost-centre split for a period (plan §12c)
    // ------------------------------------------------------------------

    /**
     * Day-weighted cost-centre split for one asset in one period.
     *
     * The centre effective at the period start is the first
     * in-period event's "from" centre (or the asset's current
     * centre when nothing changed in the period). An assignment
     * acceptance or a transfer receipt inside the period starts a
     * new segment on its date — the event date belongs to the new
     * centre. Percentages are 6dp and sum to exactly 100 (the
     * last segment absorbs the rounding).
     *
     * Returns [] when the asset has no cost centre at all (the
     * journal still posts — dimensions are analytical, never the
     * money), or when any segment has no centre.
     *
     * @return array<int, array{cost_centre_id: int, percentage: string}>
     */
    public function costCentreSplitForPeriod(FixedAsset $asset, Carbon $periodStart, Carbon $periodEnd, int $creatorId): array
    {
        $events = [];

        if (Schema::hasTable('fixed_asset_assignments')) {
            $accepted = FixedAssetAssignment::where('created_by', $creatorId)
                ->where('asset_id', $asset->id)
                ->whereNotNull('accepted_at')
                ->whereDate('accepted_at', '>=', $periodStart->format('Y-m-d'))
                ->whereDate('accepted_at', '<=', $periodEnd->format('Y-m-d'))
                ->orderBy('accepted_at')
                ->get();
            foreach ($accepted as $assignment) {
                $events[] = [
                    'date' => Carbon::parse($assignment->accepted_at)->startOfDay(),
                    'from' => $assignment->previous_cost_centre_id ? (int) $assignment->previous_cost_centre_id : null,
                    'to' => $assignment->cost_centre_id ? (int) $assignment->cost_centre_id : null,
                ];
            }
        }

        if (Schema::hasTable('fixed_asset_transfers')) {
            $received = FixedAssetTransfer::where('created_by', $creatorId)
                ->where('asset_id', $asset->id)
                ->where('status', FixedAssetTransfer::STATUS_RECEIVED)
                ->whereNotNull('received_at')
                ->whereDate('received_at', '>=', $periodStart->format('Y-m-d'))
                ->whereDate('received_at', '<=', $periodEnd->format('Y-m-d'))
                ->orderBy('received_at')
                ->get();
            foreach ($received as $transfer) {
                $events[] = [
                    'date' => Carbon::parse($transfer->received_at)->startOfDay(),
                    'from' => $transfer->from_cost_centre_id ? (int) $transfer->from_cost_centre_id : null,
                    'to' => $transfer->to_cost_centre_id ? (int) $transfer->to_cost_centre_id : null,
                ];
            }
        }

        usort($events, fn ($a, $b) => $a['date']->timestamp <=> $b['date']->timestamp);

        // Carbon 3 diffInDays() returns a FLOAT (Nov 1 → Nov 30
        // 23:59:59 is 30.999999999988, not 30). Normalise every
        // boundary to midnight first, then the difference is an
        // exact whole number of days; cast it to int before any
        // arithmetic, or the split percentages drift (15/31
        // instead of 15/30) and the allocated amounts with them.
        $periodStart = $periodStart->copy()->startOfDay();
        $periodEnd = $periodEnd->copy()->startOfDay();
        $totalDays = (int) $periodStart->diffInDays($periodEnd) + 1;

        // No change inside the period: the whole month belongs to
        // the asset's current centre.
        if ($events === []) {
            if (! $asset->cost_centre_id) {
                return [];
            }
            $code = CostCentre::where('created_by', $creatorId)
                ->where('id', (int) $asset->cost_centre_id)
                ->value('code');

            return [[
                'cost_centre_id' => (int) $asset->cost_centre_id,
                'percentage' => '100.000000',
                'cost_centre_code' => $code,
            ]];
        }

        // Build day segments: [period start, event 1) → centre 0,
        // [event 1, event 2) → centre 1, … [last event, period end]
        // → final centre. The event date belongs to the new centre.
        $segments = [];
        $cursor = $periodStart->copy();
        $current = $events[0]['from'] ?? (int) $asset->cost_centre_id;
        foreach ($events as $event) {
            if ($event['date']->gt($cursor)) {
                $segments[] = ['centre' => $current, 'days' => (int) $cursor->diffInDays($event['date'])];
                $cursor = $event['date']->copy();
            }
            $current = $event['to'] ?? $current;
        }
        if ($cursor->lte($periodEnd)) {
            $segments[] = ['centre' => $current, 'days' => (int) $cursor->diffInDays($periodEnd) + 1];
        }

        // Merge neighbours with the same centre (an event that did
        // not change the centre) and drop zero-day segments.
        $merged = [];
        foreach ($segments as $segment) {
            if ($segment['days'] <= 0) {
                continue;
            }
            if ($segment['centre'] === null) {
                return [];
            }
            $last = count($merged) - 1;
            if ($last >= 0 && $merged[$last]['centre'] === $segment['centre']) {
                $merged[$last]['days'] += $segment['days'];
            } else {
                $merged[] = $segment;
            }
        }

        if ($merged === []) {
            return [];
        }

        $split = [];
        $used = '0';
        foreach ($merged as $index => $segment) {
            if ($index === count($merged) - 1) {
                $percentage = bcsub('100', $used, 6);
            } else {
                $percentage = bcdiv(bcmul((string) $segment['days'], '100', 10), (string) $totalDays, 6);
                $used = bcadd($used, $percentage, 6);
            }
            $split[] = ['cost_centre_id' => (int) $segment['centre'], 'percentage' => $percentage];
        }

        $codes = CostCentre::where('created_by', $creatorId)
            ->whereIn('id', array_column($split, 'cost_centre_id'))
            ->pluck('code', 'id');
        foreach ($split as $index => $segment) {
            $split[$index]['cost_centre_code'] = $codes[$segment['cost_centre_id']] ?? null;
        }

        return $split;
    }

    // ------------------------------------------------------------------
    // Run workflow: create → review → approve → post
    // ------------------------------------------------------------------

    public function assertRunCompany(FixedAssetDepreciationRun $run, int $creatorId): void
    {
        $this->assets->assertSameCompany($run, $creatorId);
    }

    public function createRun(int $creatorId, int $userId, string $periodYm): FixedAssetDepreciationRun
    {
        $period = $this->periodDate($periodYm);

        $existing = FixedAssetDepreciationRun::where('created_by', $creatorId)
            ->whereDate('period', $period->format('Y-m-d'))
            ->where('status', '!=', FixedAssetDepreciationRun::STATUS_CANCELLED)
            ->first();
        if ($existing) {
            throw new FixedAssetException('A depreciation run already exists for '.$period->format('Y-m').' (status: '.$existing->status.').');
        }

        $lines = $this->computeLines($creatorId, $period);
        if ($lines === []) {
            throw new FixedAssetException('No assets have depreciation due for '.$period->format('Y-m').'.');
        }

        $total = '0';
        foreach ($lines as $line) {
            $total = bcadd($total, $line['amount'], 2);
        }

        // Race backstop: two creates for the same period at once
        // both pass the check above; the entries' unique key stops
        // the second one — turn its raw QueryException into the
        // same clean refusal.
        try {
            return DB::transaction(function () use ($creatorId, $userId, $period, $lines, $total) {
            $run = FixedAssetDepreciationRun::create([
                'created_by' => $creatorId,
                'period' => $period->format('Y-m-d'),
                'status' => FixedAssetDepreciationRun::STATUS_CALCULATED,
                'total_amount' => $total,
                'asset_count' => count($lines),
                'requested_by' => $userId,
            ]);

            foreach ($lines as $line) {
                FixedAssetDepreciationEntry::create([
                    'created_by' => $creatorId,
                    'run_id' => $run->id,
                    'asset_id' => $line['asset']->id,
                    'schedule_id' => $line['schedule']->id,
                    'period' => $period->format('Y-m-d'),
                    'planned_amount' => $line['planned'],
                    'status' => FixedAssetDepreciationEntry::STATUS_CALCULATED,
                ]);
            }

            $this->assets->logAudit($creatorId, null, 'fixed_asset_depreciation_run', $run->id, 'calculated', $userId, null, $run->fresh()->toArray());

            return $run->fresh();
            });
        } catch (QueryException $e) {
            throw new FixedAssetException('A depreciation run already exists for '.$period->format('Y-m').'.');
        }
    }

    public function reviewRun(FixedAssetDepreciationRun $run, int $creatorId, int $userId): FixedAssetDepreciationRun
    {
        $this->assets->assertSameCompany($run, $creatorId);

        if (! $run->isCalculated()) {
            throw new FixedAssetException('Only a calculated run can be reviewed.');
        }

        return DB::transaction(function () use ($run, $userId) {
            $run->update([
                'status' => FixedAssetDepreciationRun::STATUS_REVIEWED,
                'reviewed_by' => $userId,
                'reviewed_at' => now(),
            ]);
            $this->assets->logAudit((int) $run->created_by, null, 'fixed_asset_depreciation_run', $run->id, 'reviewed', $userId, null, $run->fresh()->toArray());

            return $run->fresh();
        });
    }

    /**
     * Submit the run for approval: one FixedAssetApproval per
     * asset entry (type depreciation), all requested by the same
     * user. The run stays REVIEWED until every approval is
     * decided — approveRun() moves it to APPROVED.
     */
    public function submitForApproval(FixedAssetDepreciationRun $run, int $creatorId, int $userId): FixedAssetDepreciationRun
    {
        $this->assets->assertSameCompany($run, $creatorId);

        if (! $run->isReviewed()) {
            throw new FixedAssetException('Only a reviewed run can be submitted for approval.');
        }

        $entries = $run->entries()->with('asset')->get();
        if ($entries->isEmpty()) {
            throw new FixedAssetException('This run has no entries to approve.');
        }

        $pending = FixedAssetApproval::where('created_by', $creatorId)
            ->where('transaction_type', FixedAssetApproval::TYPE_DEPRECIATION)
            ->whereIn('transaction_id', $entries->pluck('id')->all())
            ->where('decision', FixedAssetApproval::DECISION_PENDING)
            ->exists();
        if ($pending) {
            throw new FixedAssetException('This run is already waiting for approval.');
        }

        return DB::transaction(function () use ($run, $userId, $entries) {
            foreach ($entries as $entry) {
                $this->assets->requestApproval(
                    $entry->asset,
                    FixedAssetApproval::TYPE_DEPRECIATION,
                    (int) $entry->id,
                    $userId,
                    (string) $entry->planned_amount
                );
            }
            $this->assets->logAudit((int) $run->created_by, null, 'fixed_asset_depreciation_run', $run->id, 'submitted_for_approval', $userId, null, $run->fresh()->toArray());

            return $run->fresh();
        });
    }

    /**
     * Approve the run: decide every pending approval. The
     * no-self-approval rule in decideApproval() throws when the
     * approver requested the run — the run stays REVIEWED.
     */
    public function approveRun(FixedAssetDepreciationRun $run, int $creatorId, int $approverId, ?string $comment = null): FixedAssetDepreciationRun
    {
        $this->assets->assertSameCompany($run, $creatorId);

        if (! $run->isReviewed()) {
            throw new FixedAssetException('Only a reviewed run can be approved.');
        }

        $entries = $run->entries()->get();
        $approvals = FixedAssetApproval::where('created_by', $creatorId)
            ->where('transaction_type', FixedAssetApproval::TYPE_DEPRECIATION)
            ->whereIn('transaction_id', $entries->pluck('id')->all())
            ->orderBy('id')
            ->get();
        if ($approvals->isEmpty()) {
            throw new FixedAssetException('This run has not been submitted for approval yet.');
        }

        return DB::transaction(function () use ($run, $approverId, $comment, $approvals) {
            foreach ($approvals as $approval) {
                $this->assets->decideApproval($approval, $approverId, FixedAssetApproval::DECISION_APPROVED, $comment);
            }

            $run->update([
                'status' => FixedAssetDepreciationRun::STATUS_APPROVED,
                'approved_by' => $approverId,
                'approved_at' => now(),
            ]);
            $this->assets->logAudit((int) $run->created_by, null, 'fixed_asset_depreciation_run', $run->id, 'approved', $approverId, null, $run->fresh()->toArray());

            return $run->fresh();
        });
    }

    /**
     * Cancel a calculated or reviewed run. Pending approvals are
     * marked rejected directly — cancelling your own run is
     * self-denial, not self-approval, so the no-self-approval
     * rule does not apply here.
     */
    public function cancelRun(FixedAssetDepreciationRun $run, int $creatorId, int $userId, ?string $reason = null): FixedAssetDepreciationRun
    {
        $this->assets->assertSameCompany($run, $creatorId);

        if (! in_array($run->status, [FixedAssetDepreciationRun::STATUS_CALCULATED, FixedAssetDepreciationRun::STATUS_REVIEWED], true)) {
            throw new FixedAssetException('Only a calculated or reviewed run can be cancelled.');
        }

        return DB::transaction(function () use ($run, $userId, $reason) {
            $entries = $run->entries()->get();
            FixedAssetApproval::where('created_by', (int) $run->created_by)
                ->where('transaction_type', FixedAssetApproval::TYPE_DEPRECIATION)
                ->whereIn('transaction_id', $entries->pluck('id')->all())
                ->where('decision', FixedAssetApproval::DECISION_PENDING)
                ->update([
                    'approver_id' => $userId,
                    'decision' => FixedAssetApproval::DECISION_REJECTED,
                    'comment' => $reason ?: 'Run cancelled',
                    'decided_at' => now(),
                ]);

            // The run never posted, so its entries are only a plan
            // snapshot: delete them, otherwise the entries' unique
            // (company + asset + period) key would block a
            // replacement run for the same period. The run row
            // itself stays (with its audit events) as the record
            // that this period's run was cancelled.
            FixedAssetDepreciationEntry::where('run_id', $run->id)->delete();

            $run->update([
                'status' => FixedAssetDepreciationRun::STATUS_CANCELLED,
                'notes' => $reason,
            ]);
            $this->assets->logAudit((int) $run->created_by, null, 'fixed_asset_depreciation_run', $run->id, 'cancelled', $userId, null, $run->fresh()->toArray());

            return $run->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Posting — ONE journal per run, JournalEntryPoster only
    // ------------------------------------------------------------------

    public function postRun(FixedAssetDepreciationRun $run, int $creatorId, int $userId): FixedAssetDepreciationRun
    {
        $this->assets->assertSameCompany($run, $creatorId);

        if (! $run->isApproved()) {
            throw new FixedAssetException('Only an approved run can be posted.');
        }

        $period = Carbon::parse($run->period)->startOfMonth();
        $year = (int) $period->format('Y');
        if ($this->acquisitions->isFiscalYearClosed($creatorId, $year)) {
            throw new FixedAssetException('Fiscal year '.$year.' is already closed. Depreciation posting is blocked for closed periods.');
        }

        $entries = $run->entries()->with(['asset', 'schedule'])->orderBy('asset_id')->get();
        if ($entries->isEmpty()) {
            throw new FixedAssetException('This run has no entries to post.');
        }

        // Recompute the amounts at posting time (the schedule is
        // the source of truth; the entries only carry the plan).
        $lines = $this->computeLines($creatorId, $period);
        $byAsset = [];
        foreach ($lines as $line) {
            $byAsset[(int) $line['asset']->id] = $line;
        }

        $journalLines = [];
        $postAmounts = [];
        foreach ($entries as $entry) {
            if ($entry->isPosted()) {
                throw new FixedAssetException('Entry for asset '.$entry->asset->code.' is already posted.');
            }
            $line = $byAsset[(int) $entry->asset_id] ?? null;
            if ($line === null) {
                throw new FixedAssetException('Asset '.$entry->asset->code.' has no depreciation due for '.$period->format('Y-m').' any more. Cancel the run and create a fresh one.');
            }
            $postAmounts[(int) $entry->id] = $line['amount'];
            $journalLines[] = [
                'account' => $line['expense_account_id'],
                'debit' => (float) $line['amount'],
                'description' => 'Depreciation '.$entry->asset->code.' '.$period->format('Y-m'),
            ];
        }

        // One credit line per accumulated-depreciation account.
        $credits = [];
        foreach ($entries as $entry) {
            $line = $byAsset[(int) $entry->asset_id];
            $account = $line['accum_account_id'];
            $credits[$account] = bcadd($credits[$account] ?? '0', $line['amount'], 2);
        }
        foreach ($credits as $account => $amount) {
            $journalLines[] = [
                'account' => (int) $account,
                'credit' => (float) $amount,
                'description' => 'Accumulated depreciation '.$period->format('Y-m'),
            ];
        }

        $total = '0';
        foreach ($postAmounts as $amount) {
            $total = bcadd($total, $amount, 2);
        }

        return DB::transaction(function () use ($run, $creatorId, $userId, $period, $entries, $byAsset, $postAmounts, $journalLines, $total) {
            $journal = $this->poster->post(
                $creatorId,
                $period->copy()->endOfMonth()->format('Y-m-d'),
                'FA-DEP-'.$period->format('Y-m'),
                'Monthly depreciation '.$period->format('Y-m'),
                $journalLines
            );

            // The poster keeps line order: the first N items are
            // the per-asset debit lines, in the same order.
            $items = JournalItem::where('journal', $journal->id)->orderBy('id')->get();
            $debitItems = $items->filter(fn ($item) => bccomp((string) $item->debit, '0', 2) > 0)->values();
            if ($debitItems->count() !== count($entries)) {
                throw new FixedAssetException('Depreciation journal lines do not match the run entries.');
            }

            foreach ($entries as $index => $entry) {
                $line = $byAsset[(int) $entry->asset_id];
                $amount = $postAmounts[(int) $entry->id];
                $asset = $entry->asset->fresh();
                $scheduleRow = $line['schedule'];

                // Cost-centre dimensions on the expense line (plan
                // §12c). A tagging failure rolls the whole posting
                // back — better to refuse than post untagged.
                $split = $line['split'];
                if ($split !== []) {
                    $this->tagDimensions($debitItems[$index], $split, $creatorId, $userId, (int) $asset->id);
                }

                $entry->update([
                    'status' => FixedAssetDepreciationEntry::STATUS_POSTED,
                    'posted_amount' => $amount,
                    'cost_centre_id' => $this->periodEndCentre($split),
                    'journal_id' => $journal->id,
                ]);

                $scheduleRow->update(['status' => FixedAssetDepreciationSchedule::STATUS_POSTED]);

                FixedAssetAccountingEntry::create([
                    'created_by' => $creatorId,
                    'asset_id' => $asset->id,
                    'transaction_type' => FixedAssetAccountingEntry::TYPE_DEPRECIATION,
                    'transaction_id' => $entry->id,
                    'journal_id' => $journal->id,
                ]);

                // Book value: the posted amount off the old value.
                // The asset closes exactly on its residual only
                // when NO schedule row is left unposted — posting
                // the final row early (before earlier months) must
                // NOT snap the book value to the residual or flip
                // the asset to fully depreciated while months are
                // still outstanding. (The row above was already
                // marked posted, so "none left" means the schedule
                // is complete.)
                $residualBase = bcmul((string) ($asset->residual_value ?? '0'), (string) ($asset->exchange_rate ?? '1'), 4);
                $newBook = bcsub((string) $asset->current_book_value, $amount, 4);
                $scheduleComplete = ! FixedAssetDepreciationSchedule::where('created_by', $creatorId)
                    ->where('asset_id', $asset->id)
                    ->where('status', '!=', FixedAssetDepreciationSchedule::STATUS_POSTED)
                    ->exists();
                if ($scheduleComplete) {
                    $newBook = $residualBase;
                }

                $newStatus = $asset->status;
                if ($scheduleComplete && bccomp((string) $asset->amount_base, '0', 4) > 0 && bccomp($newBook, $residualBase, 4) <= 0) {
                    $newStatus = FixedAsset::STATUS_FULLY_DEPRECIATED;
                }

                $oldStatus = $asset->status;
                $asset->update([
                    'current_book_value' => $newBook,
                    'status' => $newStatus,
                ]);
                if ($newStatus !== $oldStatus) {
                    $this->assets->recordStatusChange($asset->fresh(), $oldStatus, $newStatus, $userId, 'Fully depreciated ('.$period->format('Y-m').')');
                }

                $this->assets->logAudit($creatorId, (int) $asset->id, 'fixed_asset_depreciation', (int) $entry->id, 'posted', $userId, null, $entry->fresh()->toArray());
            }

            $run->update([
                'status' => FixedAssetDepreciationRun::STATUS_POSTED,
                'total_amount' => $total,
                'journal_id' => $journal->id,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);
            $this->assets->logAudit($creatorId, null, 'fixed_asset_depreciation_run', $run->id, 'posted', $userId, null, $run->fresh()->toArray());

            return $run->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Disposal catch-up (Phase 5) — post every unposted period up
    // to the disposal date, under the disposal's own approval
    // ------------------------------------------------------------------

    /**
     * Post all unposted schedule periods up to $upToDate for one
     * asset (Phase 5 disposal catch-up, plan §7/§16).
     *
     * A disposal must never leave unposted depreciation behind,
     * so the execution drives this before it posts the disposal
     * journal. The catch-up is authorised by the APPROVED
     * disposal itself — it does not go through the monthly run
     * approval chain (the disposal approver is recorded on the
     * run row as the approver, the executor as the poster).
     *
     * One catch-up run row + one journal (Dr 631 per period /
     * Cr 328), one POSTED entry per period, same final-row
     * remainder rule as postRun (periods are computed one at a
     * time, in order, so the final period closes exactly on the
     * residual). Returns null when nothing is unposted.
     *
     * @return array{run_id: int, journal_id: int, total: string, periods: array<int, string>, book_value: string}|null
     */
    public function postCatchUpForAsset(FixedAsset $asset, Carbon $upToDate, int $creatorId, int $userId, int $approverId): ?array
    {
        $this->assets->assertSameCompany($asset, $creatorId);

        $rows = FixedAssetDepreciationSchedule::where('created_by', $creatorId)
            ->where('asset_id', $asset->id)
            ->where('status', FixedAssetDepreciationSchedule::STATUS_PLANNED)
            ->whereDate('period', '<=', $upToDate->copy()->startOfMonth()->format('Y-m-d'))
            ->orderBy('period')
            ->get();
        if ($rows->isEmpty()) {
            return null;
        }

        // A closed fiscal year blocks posting for that year's
        // periods — same FY-CLOSE rule as postRun, named per year.
        foreach ($rows->pluck('period')->map(fn ($p) => (int) Carbon::parse($p)->format('Y'))->unique()->all() as $year) {
            if ($this->acquisitions->isFiscalYearClosed($creatorId, $year)) {
                throw new FixedAssetException('Fiscal year '.$year.' is already closed. Depreciation catch-up is blocked for closed periods.');
            }
        }

        return DB::transaction(function () use ($asset, $upToDate, $creatorId, $userId, $approverId, $rows) {
            $lastPeriod = Carbon::parse($rows->last()->period)->startOfMonth();

            $run = FixedAssetDepreciationRun::create([
                'created_by' => $creatorId,
                'period' => $lastPeriod->format('Y-m-d'),
                'status' => FixedAssetDepreciationRun::STATUS_POSTED,
                'total_amount' => '0.0000',
                'asset_count' => 1,
                'requested_by' => $userId,
                'reviewed_by' => $approverId,
                'approved_by' => $approverId,
                'posted_by' => $userId,
                'reviewed_at' => now(),
                'approved_at' => now(),
                'posted_at' => now(),
                'notes' => 'Depreciation catch-up on disposal of '.$asset->code,
            ]);

            $collected = [];
            foreach ($rows as $row) {
                $period = Carbon::parse($row->period)->startOfMonth();
                $lines = $this->computeLines($creatorId, $period);
                $line = null;
                foreach ($lines as $candidate) {
                    if ((int) $candidate['asset']->id === (int) $asset->id) {
                        $line = $candidate;
                        break;
                    }
                }
                if ($line === null) {
                    // A zero-amount planned row (zero-cost asset)
                    // posts nothing — skip it, it stays planned
                    // and can never post once the asset is gone.
                    if (bccomp((string) $row->period_depreciation, '0', 4) <= 0) {
                        continue;
                    }
                    throw new FixedAssetException('No depreciation due for asset '.$asset->code.' in '.$period->format('Y-m').' any more. Cancel the disposal and check the depreciation schedule.');
                }

                $entry = FixedAssetDepreciationEntry::create([
                    'created_by' => $creatorId,
                    'run_id' => $run->id,
                    'asset_id' => $asset->id,
                    'schedule_id' => $row->id,
                    'period' => $period->format('Y-m-d'),
                    'planned_amount' => $line['planned'],
                    'posted_amount' => $line['amount'],
                    'cost_centre_id' => $this->periodEndCentre($line['split']),
                    'status' => FixedAssetDepreciationEntry::STATUS_POSTED,
                    'journal_id' => null,
                ]);
                $row->update(['status' => FixedAssetDepreciationSchedule::STATUS_POSTED]);

                $collected[] = ['entry' => $entry, 'line' => $line, 'period' => $period];
            }

            if ($collected === []) {
                // Nothing actually due (all planned rows were
                // zero-amount): drop the empty run and report no
                // catch-up instead of posting an empty journal.
                $run->delete();

                return null;
            }

            $journalLines = [];
            $credits = [];
            $total = '0';
            foreach ($collected as $item) {
                $amount = $item['line']['amount'];
                $total = bcadd($total, $amount, 2);
                $journalLines[] = [
                    'account' => $item['line']['expense_account_id'],
                    'debit' => (float) $amount,
                    'description' => 'Depreciation '.$asset->code.' '.$item['period']->format('Y-m'),
                ];
                $account = $item['line']['accum_account_id'];
                $credits[$account] = bcadd($credits[$account] ?? '0', $amount, 2);
            }
            foreach ($credits as $account => $amount) {
                $journalLines[] = [
                    'account' => (int) $account,
                    'credit' => (float) $amount,
                    'description' => 'Accumulated depreciation to disposal '.$upToDate->format('Y-m-d'),
                ];
            }

            $journal = $this->poster->post(
                $creatorId,
                $upToDate->format('Y-m-d'),
                'FA-DEP-CATCHUP-'.$asset->code,
                'Depreciation catch-up to disposal date for '.$asset->code,
                $journalLines
            );

            $items = JournalItem::where('journal', $journal->id)->orderBy('id')->get();
            $debitItems = $items->filter(fn ($item) => bccomp((string) $item->debit, '0', 2) > 0)->values();
            if ($debitItems->count() !== count($collected)) {
                throw new FixedAssetException('Depreciation catch-up journal lines do not match the caught-up periods.');
            }

            foreach ($collected as $index => $item) {
                $split = $item['line']['split'];
                if ($split !== []) {
                    $this->tagDimensions($debitItems[$index], $split, $creatorId, $userId, (int) $asset->id);
                }
                $item['entry']->update(['journal_id' => $journal->id]);
                FixedAssetAccountingEntry::create([
                    'created_by' => $creatorId,
                    'asset_id' => $asset->id,
                    'transaction_type' => FixedAssetAccountingEntry::TYPE_DEPRECIATION,
                    'transaction_id' => $item['entry']->id,
                    'journal_id' => $journal->id,
                ]);
                $this->assets->logAudit($creatorId, (int) $asset->id, 'fixed_asset_depreciation', (int) $item['entry']->id, 'posted', $userId, null, $item['entry']->fresh()->toArray());
            }

            // Book value after the catch-up; snap to the residual
            // only when no schedule row is left unposted (same
            // rule as postRun).
            $fresh = $asset->fresh();
            $residualBase = bcmul((string) ($fresh->residual_value ?? '0'), (string) ($fresh->exchange_rate ?? '1'), 4);
            $newBook = bcsub((string) $fresh->current_book_value, $total, 4);
            $scheduleComplete = ! FixedAssetDepreciationSchedule::where('created_by', $creatorId)
                ->where('asset_id', $asset->id)
                ->where('status', '!=', FixedAssetDepreciationSchedule::STATUS_POSTED)
                ->exists();
            if ($scheduleComplete) {
                $newBook = $residualBase;
            }
            $newStatus = $fresh->status;
            if ($scheduleComplete && bccomp((string) $fresh->amount_base, '0', 4) > 0 && bccomp($newBook, $residualBase, 4) <= 0) {
                $newStatus = FixedAsset::STATUS_FULLY_DEPRECIATED;
            }
            $oldStatus = $fresh->status;
            $fresh->update(['current_book_value' => $newBook, 'status' => $newStatus]);
            if ($newStatus !== $oldStatus) {
                $this->assets->recordStatusChange($fresh->fresh(), $oldStatus, $newStatus, $userId, 'Fully depreciated (catch-up to disposal)');
            }

            $run->update(['journal_id' => $journal->id, 'total_amount' => $total]);
            $this->assets->logAudit($creatorId, null, 'fixed_asset_depreciation_run', $run->id, 'posted', $userId, null, $run->fresh()->toArray());

            return [
                'run_id' => $run->id,
                'journal_id' => $journal->id,
                'total' => $total,
                'periods' => array_map(fn ($item) => $item['period']->format('Y-m'), $collected),
                'book_value' => $newBook,
            ];
        });
    }

    /**
     * Tag one posted expense line with its cost-centre split and
     * stamp the asset on the dimension rows (the
     * journal_line_dimensions table carries asset_id for exactly
     * this Fixed Assets integration).
     */
    protected function tagDimensions(JournalItem $item, array $split, int $creatorId, int $userId, int $assetId): void
    {
        $lines = [];
        foreach ($split as $index => $segment) {
            $lines[] = [
                'cost_centre_id' => $segment['cost_centre_id'],
                'percentage' => $segment['percentage'],
                'fixed_amount' => null,
                'sort_order' => $index + 1,
            ];
        }

        $this->dimensions->attachToLine(
            JournalLineDimension::LINE_JOURNAL_ITEM,
            (int) $item->id,
            $lines,
            'percentage',
            $creatorId,
            $userId
        );

        JournalLineDimension::forCompany($creatorId)
            ->forLine(JournalLineDimension::LINE_JOURNAL_ITEM, (int) $item->id)
            ->active()
            ->update(['asset_id' => $assetId]);
    }

    /** The cost centre effective at the period end (last split segment), if any. */
    protected function periodEndCentre(array $split): ?int
    {
        if ($split === []) {
            return null;
        }

        return (int) $split[count($split) - 1]['cost_centre_id'];
    }

    // ------------------------------------------------------------------
    // Accounts (asset override → category default → class fallback)
    // ------------------------------------------------------------------

    /** Depreciation expense: asset override, else category default, else class 63. */
    public function resolveExpenseAccount(FixedAsset $asset, int $creatorId): int
    {
        $id = (int) ($asset->depreciation_expense_account_id ?? 0);
        if ($id > 0 && ChartOfAccount::where('created_by', $creatorId)->where('id', $id)->exists()) {
            return $id;
        }

        $category = $asset->category;
        $id = $category ? (int) ($category->depreciation_expense_account_id ?? 0) : 0;
        if ($id > 0 && ChartOfAccount::where('created_by', $creatorId)->where('id', $id)->exists()) {
            return $id;
        }

        $id = $this->resolver->resolveByCodeOrPrefix($creatorId, '63');
        if ($id) {
            return (int) $id;
        }

        throw new FixedAssetException('No depreciation expense account is configured for this category and no class 63 account exists. Set the depreciation expense account on the category first.');
    }

    /** Accumulated depreciation: asset override, else category default, else class 32. */
    public function resolveAccumAccount(FixedAsset $asset, int $creatorId): int
    {
        $id = (int) ($asset->accum_depreciation_account_id ?? 0);
        if ($id > 0 && ChartOfAccount::where('created_by', $creatorId)->where('id', $id)->exists()) {
            return $id;
        }

        $category = $asset->category;
        $id = $category ? (int) ($category->accum_depreciation_account_id ?? 0) : 0;
        if ($id > 0 && ChartOfAccount::where('created_by', $creatorId)->where('id', $id)->exists()) {
            return $id;
        }

        $id = $this->resolver->resolveByCodeOrPrefix($creatorId, '32');
        if ($id) {
            return (int) $id;
        }

        throw new FixedAssetException('No accumulated depreciation account is configured for this category and no class 32 account exists. Set the accumulated depreciation account on the category first.');
    }

    // ------------------------------------------------------------------
    // Monthly totals report (plan §9)
    // ------------------------------------------------------------------

    /**
     * This period (posted, or planned when nothing is posted
     * yet), year-to-date posted, and remaining planned for one
     * company + month. Also returns the per-asset breakdown for
     * the month.
     *
     * @return array{period: string, period_total: string, period_posted: bool, ytd_posted: string, remaining_planned: string, lines: array}
     */
    public function monthlyTotals(int $creatorId, string $periodYm): array
    {
        $period = $this->periodDate($periodYm);
        $periodStart = $period->format('Y-m-d');
        $yearStart = $period->copy()->startOfYear()->format('Y-m-d');
        $yearEnd = $period->copy()->endOfYear()->format('Y-m-d');

        $postedRun = FixedAssetDepreciationRun::where('created_by', $creatorId)
            ->whereDate('period', $periodStart)
            ->where('status', FixedAssetDepreciationRun::STATUS_POSTED)
            ->first();

        if ($postedRun) {
            $lines = $postedRun->entries()->with(['asset', 'costCentre'])->orderBy('asset_id')->get()->map(function ($entry) {
                return [
                    'asset' => $entry->asset,
                    'planned' => (string) $entry->planned_amount,
                    'amount' => (string) ($entry->posted_amount ?? '0'),
                    'posted' => true,
                    'cost_centre_id' => $entry->cost_centre_id ? (int) $entry->cost_centre_id : null,
                    'cost_centre_code' => $entry->costCentre?->code,
                ];
            })->all();
            $periodTotal = (string) $postedRun->total_amount;
            $periodPosted = true;
        } else {
            $computed = $this->computeLines($creatorId, $period);
            $lines = [];
            $periodTotal = '0';
            foreach ($computed as $line) {
                $lines[] = [
                    'asset' => $line['asset'],
                    'planned' => $line['planned'],
                    'amount' => $line['amount'],
                    'posted' => false,
                    'cost_centre_id' => $this->periodEndCentre($line['split']),
                    'cost_centre_code' => null,
                ];
                $periodTotal = bcadd($periodTotal, $line['amount'], 2);
            }
            $periodPosted = false;
        }

        $ytd = (string) FixedAssetDepreciationEntry::where('created_by', $creatorId)
            ->where('status', FixedAssetDepreciationEntry::STATUS_POSTED)
            ->whereDate('period', '>=', $yearStart)
            ->whereDate('period', '<=', $yearEnd)
            ->sum('posted_amount');

        $remaining = (string) FixedAssetDepreciationSchedule::where('created_by', $creatorId)
            ->where('status', FixedAssetDepreciationSchedule::STATUS_PLANNED)
            ->whereDate('period', '>', $period->copy()->endOfMonth()->format('Y-m-d'))
            ->sum('period_depreciation');

        return [
            'period' => $period->format('Y-m'),
            'period_total' => bcadd($periodTotal, '0', 2),
            'period_posted' => $periodPosted,
            'ytd_posted' => bcadd($ytd, '0', 2),
            'remaining_planned' => bcadd($remaining, '0', 4),
            'lines' => $lines,
        ];
    }
}
