<?php

namespace App\Services;

use App\Exceptions\CostCentreException;
use App\Models\Bill;
use App\Models\BillAccount;
use App\Models\BillProduct;
use App\Models\CostCentre;
use App\Models\CostCentreAllocation;
use App\Models\CostCentreAllocationTemplate;
use App\Models\DimensionAuditEvent;
use App\Models\Utility;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cost Centre allocation workflow (Phase 1, plan §5/§6).
 *
 * State machine (service-enforced, not only UI):
 *   Draft → Validate → Posted → Reversed only.
 * A posted split is NEVER edited: reverse it with a reason, then post a
 * new allocation. Every allocation, reversal and approval writes a
 * dimension_audit_events row (append-only).
 *
 * Dimensions sit BESIDE the money: this service never writes journal
 * lines and never changes a debit/credit amount. The only writers of
 * money remain JournalEntryPoster / Utility::addTransactionLines().
 *
 * Lifecycle (plan §11 risk 2): bills and expenses delete and re-post
 * their transaction lines on edit, so allocations follow the same
 * lifecycle — see afterBillUpdated(), reverseForLine() and
 * reverseForSource(). An allocation whose source line is gone is
 * reversed, never left orphaned.
 */
class CostCentreAllocationService
{
    public function __construct(
        protected AllocationEngine $engine = new AllocationEngine(),
        protected CostCentreService $centres = new CostCentreService(),
    ) {}

    // ------------------------------------------------------------------
    // Validation / preview (no writes)
    // ------------------------------------------------------------------

    /**
     * Full validation of a split: arithmetic (engine) + centre status +
     * period + budget policy. Returns the computed split and any
     * non-blocking warnings. Throws CostCentreException on any failure.
     */
    public function preview(
        string $amount,
        array $lines,
        string $basis,
        int $creatorId,
        ?Carbon $date = null,
        bool $isReversal = false
    ): array {
        $computed = $this->engine->compute($amount, $lines, $basis, $isReversal);
        $date = $date ?? Carbon::today();

        foreach ($computed['lines'] as $row) {
            $centre = CostCentre::find($row['cost_centre_id']);
            if (! $centre) {
                throw new CostCentreException(__('The selected cost centre does not exist.'));
            }
            if ((int) $centre->created_by !== $creatorId) {
                throw new CostCentreException(__('Cost centre :code belongs to another company.', ['code' => $centre->code]));
            }
            $this->centres->assertCanAcceptTransactions($centre, $date);
        }

        $this->assertPeriodOpen($creatorId, $date);
        $warnings = $this->budgetWarnings($creatorId);

        return array_merge($computed, ['warnings' => $warnings]);
    }

    /** Budget policy check (plan §9). Phase 1 has no budgets table, so
     *  there is nothing to block against: 'off' passes silently, every
     *  other policy passes with a warning that no budget data exists
     *  yet. Budgets (Phase 5) will turn this into a real check. */
    public function budgetWarnings(int $creatorId): array
    {
        $policy = Schema::hasTable('settings')
            ? (CostCentreSettings::get($creatorId, CostCentreSettings::KEY_BUDGET_POLICY) ?? 'off')
            : 'off';

        if ($policy === 'off') {
            return [];
        }

        return [__('Budget policy is ":policy" but no budgets exist yet in Phase 1, so the check passes. Budgets arrive in Phase 5.', ['policy' => $policy])];
    }

    /**
     * Period check: when period_lock_policy is 'block', a fiscal year
     * that already has a year-end closing journal (FY-{year}-CLOSE-*)
     * is closed — no new allocation may be posted into it. A reversal
     * in a closed period needs an authorised reopen (not built in
     * Phase 1), so it is blocked the same way.
     */
    public function assertPeriodOpen(int $creatorId, Carbon $date): void
    {
        $policy = Schema::hasTable('settings')
            ? (CostCentreSettings::get($creatorId, CostCentreSettings::KEY_PERIOD_LOCK_POLICY) ?? 'block')
            : 'block';

        if ($policy !== 'block') {
            return;
        }

        if (! Schema::hasTable('journal_entries')) {
            return;
        }

        $closed = DB::table('journal_entries')
            ->where('created_by', $creatorId)
            ->where('reference', 'like', 'FY-'.$date->year.'-CLOSE%')
            ->exists();

        if ($closed) {
            throw new CostCentreException(
                __('Fiscal year :year is closed. Allocations into a closed period are blocked.', ['year' => $date->year])
            );
        }
    }

    // ------------------------------------------------------------------
    // Create / post / reverse / approve
    // ------------------------------------------------------------------

    /**
     * Create a POSTED allocation for one source line. The source amount
     * is always computed server-side from the bill line — never trusted
     * from the client.
     */
    public function createPosted(
        string $sourceType,
        int $sourceId,
        string $lineType,
        int $lineId,
        string $basis,
        array $lines,
        int $creatorId,
        int $userId,
        ?int $templateId = null,
        ?int $replacesAllocationId = null
    ): CostCentreAllocation {
        return $this->create($sourceType, $sourceId, $lineType, $lineId, $basis, $lines, $creatorId, $userId, CostCentreAllocation::STATUS_POSTED, $templateId, $replacesAllocationId);
    }

    public function createDraft(
        string $sourceType,
        int $sourceId,
        string $lineType,
        int $lineId,
        string $basis,
        array $lines,
        int $creatorId,
        int $userId,
        ?int $templateId = null
    ): CostCentreAllocation {
        return $this->create($sourceType, $sourceId, $lineType, $lineId, $basis, $lines, $creatorId, $userId, CostCentreAllocation::STATUS_DRAFT, $templateId);
    }

    protected function create(
        string $sourceType,
        int $sourceId,
        string $lineType,
        int $lineId,
        string $basis,
        array $lines,
        int $creatorId,
        int $userId,
        string $status,
        ?int $templateId = null,
        ?int $replacesAllocationId = null
    ): CostCentreAllocation {
        if (! in_array($sourceType, CostCentreAllocation::SOURCES, true)) {
            throw new CostCentreException(__('Invalid allocation source.'));
        }
        if (! in_array($lineType, CostCentreAllocation::LINE_TYPES, true)) {
            throw new CostCentreException(__('Invalid allocation source line.'));
        }
        if (! in_array($basis, CostCentreAllocation::BASES, true)) {
            throw new CostCentreException(__('Invalid allocation basis.'));
        }

        $bill = $this->resolveSourceBill($sourceType, $sourceId, $creatorId);
        $amount = $this->lineAmount($bill, $lineType, $lineId);
        $date = $bill->bill_date ? Carbon::parse($bill->bill_date) : Carbon::today();

        $computed = $this->preview($amount, $lines, $basis, $creatorId, $date);

        return DB::transaction(function () use (
            $sourceType, $sourceId, $lineType, $lineId, $basis, $amount,
            $computed, $creatorId, $userId, $status, $templateId, $replacesAllocationId
        ) {
            $allocation = CostCentreAllocation::create([
                'company_id' => $creatorId,
                'created_by' => $creatorId,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'source_line_type' => $lineType,
                'source_line_id' => $lineId,
                'basis' => $basis,
                'source_amount' => $amount,
                'status' => $status,
                'template_id' => $templateId,
                'replaces_allocation_id' => $replacesAllocationId,
                // Snapshot of the rule that produced the posted amounts.
                'rule_snapshot' => [
                    'engine_version' => AllocationEngine::VERSION,
                    'basis' => $basis,
                    'source_amount' => $amount,
                    'sum_tolerance' => AllocationEngine::SUM_TOLERANCE,
                    'input_lines' => array_map(fn ($l) => [
                        'cost_centre_id' => $l['cost_centre_id'],
                        'percentage' => $l['percentage'],
                        'fixed_amount' => $l['fixed_amount'],
                    ], $computed['lines']),
                    'residual_line_cost_centre_id' => $computed['lines'][count($computed['lines']) - 1]['cost_centre_id'],
                    'computed_at' => now()->toIso8601String(),
                ],
            ]);

            foreach ($computed['lines'] as $row) {
                $allocation->lines()->create([
                    'cost_centre_id' => $row['cost_centre_id'],
                    'percentage' => $row['percentage'],
                    'fixed_amount' => $row['fixed_amount'],
                    'allocated_amount' => $row['allocated_amount'],
                    'sort_order' => $row['sort_order'],
                ]);
            }

            $this->audit($creatorId, $allocation, $status === CostCentreAllocation::STATUS_POSTED ? 'posted' : 'created', null, $userId);

            return $allocation->fresh(['lines']);
        });
    }

    /** Post a draft (re-validates everything first). */
    public function post(CostCentreAllocation $allocation, int $creatorId, int $userId): CostCentreAllocation
    {
        $this->assertSameCompany($allocation, $creatorId);
        if (! $allocation->isDraft()) {
            throw new CostCentreException(__('Only a draft allocation can be posted.'));
        }

        $bill = $this->resolveSourceBill($allocation->source_type, $allocation->source_id, $creatorId);
        $amount = $this->lineAmount($bill, $allocation->source_line_type, $allocation->source_line_id);
        $date = $bill->bill_date ? Carbon::parse($bill->bill_date) : Carbon::today();

        $input = $allocation->lines->map(fn ($l) => [
            'cost_centre_id' => $l->cost_centre_id,
            'percentage' => $l->percentage,
            'fixed_amount' => $l->fixed_amount,
            'sort_order' => $l->sort_order,
        ])->all();

        // The amount may have moved since the draft was saved: recompute
        // against the current line amount and persist the new amounts.
        $computed = $this->preview($amount, $input, $allocation->basis, $creatorId, $date);

        return DB::transaction(function () use ($allocation, $amount, $computed, $creatorId, $userId) {
            $before = $this->summary($allocation->fresh(['lines']));

            foreach ($computed['lines'] as $row) {
                $allocation->lines()->where('cost_centre_id', $row['cost_centre_id'])
                    ->update(['allocated_amount' => $row['allocated_amount']]);
            }
            $allocation->source_amount = $amount;
            $allocation->status = CostCentreAllocation::STATUS_POSTED;
            $allocation->save();

            $this->audit($creatorId, $allocation->fresh(['lines']), 'posted', $before, $userId);

            return $allocation->fresh(['lines']);
        });
    }

    /**
     * Reverse a posted allocation — the ONLY way a posted split changes.
     * The original row stays (status reversed) with the reason and the
     * actor; a repost is a NEW allocation linked via
     * replaces_allocation_id.
     */
    public function reverse(CostCentreAllocation $allocation, int $creatorId, int $userId, string $reason): CostCentreAllocation
    {
        $this->assertSameCompany($allocation, $creatorId);
        if (! $allocation->isPosted()) {
            throw new CostCentreException(__('Only a posted allocation can be reversed.'));
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new CostCentreException(__('A reason is required to reverse an allocation.'));
        }

        return DB::transaction(function () use ($allocation, $creatorId, $userId, $reason) {
            $before = $this->summary($allocation->fresh(['lines']));

            $allocation->status = CostCentreAllocation::STATUS_REVERSED;
            $allocation->reversal_reason = $reason;
            $allocation->reversed_by = $userId;
            $allocation->reversed_at = now();
            $allocation->save();

            $this->audit($creatorId, $allocation->fresh(['lines']), 'reversed', $before, $userId);

            return $allocation->fresh(['lines']);
        });
    }

    /**
     * Approve a posted allocation. No self-approval: the approver must
     * differ from the user who created the allocation.
     */
    public function approve(CostCentreAllocation $allocation, int $creatorId, int $userId): CostCentreAllocation
    {
        $this->assertSameCompany($allocation, $creatorId);
        if (! $allocation->isPosted()) {
            throw new CostCentreException(__('Only a posted allocation can be approved.'));
        }
        // created_by on the header is the company id (creatorId scope),
        // not the posting user — so self-approval is judged against the
        // audit trail: the user who posted it is the last 'posted' event
        // actor.
        $poster = DimensionAuditEvent::where('company_id', $creatorId)
            ->where('entity_type', 'cost_centre_allocation')
            ->where('entity_id', $allocation->id)
            ->where('action', 'posted')
            ->orderByDesc('id')->value('actor_id');
        if ($poster !== null && (int) $poster === $userId) {
            throw new CostCentreException(__('You cannot approve an allocation you posted.'));
        }

        return DB::transaction(function () use ($allocation, $creatorId, $userId) {
            $before = $this->summary($allocation->fresh(['lines']));

            $allocation->approved_by = $userId;
            $allocation->approved_at = now();
            $allocation->save();

            $this->audit($creatorId, $allocation->fresh(['lines']), 'approved', $before, $userId);

            return $allocation->fresh(['lines']);
        });
    }

    // ------------------------------------------------------------------
    // Lookups
    // ------------------------------------------------------------------

    public function postedForLine(string $sourceType, int $sourceId, string $lineType, int $lineId, int $creatorId): ?CostCentreAllocation
    {
        return CostCentreAllocation::forCompany($creatorId)->posted()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('source_line_type', $lineType)
            ->where('source_line_id', $lineId)
            ->with('lines.costCentre')
            ->first();
    }

    public function postedForSource(string $sourceType, int $sourceId, int $creatorId)
    {
        return CostCentreAllocation::forCompany($creatorId)->posted()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->with('lines.costCentre')
            ->get();
    }

    // ------------------------------------------------------------------
    // Lifecycle — follows the bill/expense line lifecycle (plan §11 risk 2)
    // ------------------------------------------------------------------

    /**
     * Reverse (posted) or delete (draft) every allocation on one source
     * line. Called when the line is deleted, and before a line is
     * re-posted on edit.
     */
    public function reverseForLine(
        string $sourceType,
        int $sourceId,
        string $lineType,
        int $lineId,
        int $creatorId,
        int $userId,
        string $reason
    ): void {
        $allocations = CostCentreAllocation::forCompany($creatorId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('source_line_type', $lineType)
            ->where('source_line_id', $lineId)
            ->whereIn('status', [CostCentreAllocation::STATUS_POSTED, CostCentreAllocation::STATUS_DRAFT])
            ->get();

        foreach ($allocations as $allocation) {
            if ($allocation->isPosted()) {
                $this->reverse($allocation, $creatorId, $userId, $reason);
            } else {
                $before = $this->summary($allocation->fresh(['lines']));
                // Delete the lines explicitly too: SQLite in tests does
                // not enforce the FK cascade that MySQL applies.
                $allocation->lines()->delete();
                $allocation->delete();
                DimensionAuditEvent::record($creatorId, 'cost_centre_allocation', (int) $allocation->id, 'deleted', $before, null, $userId);
            }
        }
    }

    /** Reverse/delete every allocation on a whole bill/expense. */
    public function reverseForSource(string $sourceType, int $sourceId, int $creatorId, int $userId, string $reason): void
    {
        $allocations = CostCentreAllocation::forCompany($creatorId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->whereIn('status', [CostCentreAllocation::STATUS_POSTED, CostCentreAllocation::STATUS_DRAFT])
            ->get();

        foreach ($allocations as $allocation) {
            if ($allocation->isPosted()) {
                $this->reverse($allocation, $creatorId, $userId, $reason);
            } else {
                $before = $this->summary($allocation->fresh(['lines']));
                // Delete the lines explicitly too: SQLite in tests does
                // not enforce the FK cascade that MySQL applies.
                $allocation->lines()->delete();
                $allocation->delete();
                DimensionAuditEvent::record($creatorId, 'cost_centre_allocation', (int) $allocation->id, 'deleted', $before, null, $userId);
            }
        }
    }

    /**
     * Called from BillController::update / ExpenseController::update
     * AFTER the lines have been saved and the transaction lines
     * re-posted. Two cases:
     *  - the request carries an `allocations` payload: the old splits
     *    for the listed lines are reversed and the new splits posted;
     *  - no payload: each surviving posted allocation is re-based —
     *    if its line amount changed, the old split is reversed and a
     *    new one is posted with the SAME percentages recomputed against
     *    the new amount. A fixed-amount split cannot be re-based (its
     *    fixed amounts no longer add up), so it stays reversed and the
     *    user re-allocates it from the modal.
     */
    public function afterBillUpdated(Bill $bill, ?array $allocationsPayload, int $creatorId, int $userId): void
    {
        $sourceType = $this->sourceTypeForBill($bill);

        if ($allocationsPayload !== null) {
            $this->syncFromPayload($bill, $sourceType, $allocationsPayload, $creatorId, $userId);

            return;
        }

        $this->rebaseForBill($bill, $sourceType, $creatorId, $userId);
    }

    /** Payload: list of {source_line_type, source_line_id, basis, lines}. */
    public function syncFromPayload(Bill $bill, string $sourceType, array $payload, int $creatorId, int $userId): void
    {
        foreach ($payload as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $lineType = (string) ($entry['source_line_type'] ?? '');
            $lineId = (int) ($entry['source_line_id'] ?? 0);
            $basis = (string) ($entry['basis'] ?? CostCentreAllocation::BASIS_PERCENTAGE);
            $lines = $entry['lines'] ?? [];

            if ($lineId <= 0 || ! in_array($lineType, CostCentreAllocation::LINE_TYPES, true)) {
                throw new CostCentreException(__('Invalid allocation line in the bill payload.'));
            }
            // The line must belong to this bill — never trust the payload.
            $amount = $this->lineAmount($bill, $lineType, $lineId);
            $date = $bill->bill_date ? Carbon::parse($bill->bill_date) : Carbon::today();

            if ($lines === [] || $lines === null) {
                // Empty split for this line: just remove the old one.
                $this->reverseForLine($sourceType, $bill->id, $lineType, $lineId, $creatorId, $userId, __('Bill edited — lines re-posted, allocation replaced.'));
                continue;
            }

            // Validate the NEW split before touching the old one, so a
            // bad payload cannot leave the line with no allocation.
            $this->preview($amount, $lines, $basis, $creatorId, $date);

            $existing = $this->postedForLine($sourceType, $bill->id, $lineType, $lineId, $creatorId);
            $replacesId = $existing ? (int) $existing->id : null;

            $this->reverseForLine($sourceType, $bill->id, $lineType, $lineId, $creatorId, $userId, __('Bill edited — lines re-posted, allocation replaced.'));

            $this->createPosted($sourceType, $bill->id, $lineType, $lineId, $basis, $lines, $creatorId, $userId, null, $replacesId);
        }
    }

    protected function rebaseForBill(Bill $bill, string $sourceType, int $creatorId, int $userId): void
    {
        $allocations = $this->postedForSource($sourceType, $bill->id, $creatorId);

        foreach ($allocations as $allocation) {
            try {
                $currentAmount = $this->lineAmount($bill, $allocation->source_line_type, $allocation->source_line_id);
            } catch (CostCentreException) {
                // The line itself is gone: reverse, never orphan.
                $this->reverse($allocation, $creatorId, $userId, __('Source line deleted — allocation reversed.'));
                continue;
            }

            if (bccomp($currentAmount, (string) $allocation->source_amount, 2) === 0) {
                continue;
            }

            $oldId = (int) $allocation->id;
            $this->reverse($allocation, $creatorId, $userId, __('Source line amount changed on edit — allocation re-based.'));

            if ($allocation->basis !== CostCentreAllocation::BASIS_PERCENTAGE) {
                // Fixed amounts cannot be re-based: they no longer add up
                // to the new amount. Leave it reversed; the user
                // re-allocates from the modal.
                continue;
            }

            $lines = $allocation->lines->map(fn ($l) => [
                'cost_centre_id' => $l->cost_centre_id,
                'percentage' => $l->percentage,
                'fixed_amount' => null,
                'sort_order' => $l->sort_order,
            ])->all();

            $this->createPosted($sourceType, $bill->id, $allocation->source_line_type, $allocation->source_line_id, $allocation->basis, $lines, $creatorId, $userId, null, $oldId);
        }
    }

    // ------------------------------------------------------------------
    // Source resolution + line amounts (server-side, never client-trusted)
    // ------------------------------------------------------------------

    public function sourceTypeForBill(Bill $bill): string
    {
        return strtolower((string) $bill->type) === 'expense'
            ? CostCentreAllocation::SOURCE_EXPENSE
            : CostCentreAllocation::SOURCE_BILL;
    }

    public function resolveSourceBill(string $sourceType, int $sourceId, int $creatorId): Bill
    {
        $bill = Bill::find($sourceId);
        if (! $bill) {
            throw new CostCentreException(__('The source document does not exist.'));
        }
        if ((int) $bill->created_by !== $creatorId) {
            throw new CostCentreException(__('The source document belongs to another company.'));
        }
        if ($this->sourceTypeForBill($bill) !== $sourceType) {
            throw new CostCentreException(__('The source document type does not match the allocation source.'));
        }

        return $bill;
    }

    /**
     * The amount a bill/expense line posts to the ledger — the same
     * formula the controllers use when they call addTransactionLines():
     * product lines are price × qty − discount + tax; account lines are
     * the account price.
     */
    public function lineAmount(Bill $bill, string $lineType, int $lineId): string
    {
        if ($lineType === CostCentreAllocation::LINE_BILL_PRODUCT) {
            $product = BillProduct::where('id', $lineId)->where('bill_id', $bill->id)->first();
            if (! $product) {
                throw new CostCentreException(__('The bill line does not exist.'));
            }

            $totalTaxPrice = 0.0;
            if ($product->tax !== null && $product->tax !== '') {
                $taxes = Utility::tax($product->tax);
                foreach ($taxes as $tax) {
                    $totalTaxPrice += (float) Utility::taxRate($tax->rate, $product->price, $product->quantity, $product->discount);
                }
            }

            $amount = ((float) $product->price * (float) $product->quantity)
                - (float) $product->discount + $totalTaxPrice;

            return $this->engine->round2((string) $amount);
        }

        $account = BillAccount::where('id', $lineId)->where('ref_id', $bill->id)->first();
        if (! $account) {
            throw new CostCentreException(__('The bill account line does not exist.'));
        }

        return $this->engine->round2((string) $account->price);
    }

    // ------------------------------------------------------------------
    // Templates
    // ------------------------------------------------------------------

    public function createTemplate(string $name, string $basis, array $lines, int $creatorId, int $userId): CostCentreAllocationTemplate
    {
        $name = trim($name);
        if ($name === '') {
            throw new CostCentreException(__('The template name field is required.'));
        }
        if (! in_array($basis, CostCentreAllocation::BASES, true)) {
            throw new CostCentreException(__('Invalid allocation basis.'));
        }
        if ($lines === []) {
            throw new CostCentreException(__('A template needs at least one split line.'));
        }

        $seen = [];
        $clean = [];
        $percentSum = '0';
        foreach ($lines as $index => $line) {
            $centreId = (int) ($line['cost_centre_id'] ?? 0);
            if ($centreId <= 0) {
                throw new CostCentreException(__('Each template line must select a cost centre.'));
            }
            if (isset($seen[$centreId])) {
                throw new CostCentreException(__('A cost centre may only appear once in a template.'));
            }
            $seen[$centreId] = true;

            $centre = CostCentre::find($centreId);
            if (! $centre || (int) $centre->created_by !== $creatorId) {
                throw new CostCentreException(__('The selected cost centre does not exist for this company.'));
            }

            if ($basis === 'percentage') {
                $pct = (string) ($line['percentage'] ?? '');
                if ($pct === '' || ! is_numeric($pct) || bccomp($pct, '0', 6) <= 0) {
                    throw new CostCentreException(__('Each template line needs a percentage greater than zero.'));
                }
                $percentSum = bcadd($percentSum, $pct, 6);
                $clean[] = ['cost_centre_id' => $centreId, 'percentage' => bcadd($pct, '0', 6), 'fixed_amount' => null, 'sort_order' => (int) ($line['sort_order'] ?? $index)];
            } else {
                $fixed = (string) ($line['fixed_amount'] ?? '');
                if ($fixed === '' || ! is_numeric($fixed) || bccomp($fixed, '0', 2) <= 0) {
                    throw new CostCentreException(__('Each template line needs a fixed amount greater than zero.'));
                }
                $clean[] = ['cost_centre_id' => $centreId, 'percentage' => null, 'fixed_amount' => bcadd($fixed, '0', 2), 'sort_order' => (int) ($line['sort_order'] ?? $index)];
            }
        }

        if ($basis === 'percentage') {
            $diff = bcsub($percentSum, '100', 6);
            $abs = str_starts_with($diff, '-') ? substr($diff, 1) : $diff;
            if (bccomp($abs, AllocationEngine::SUM_TOLERANCE, 6) > 0) {
                throw new CostCentreException(__('Template percentages must add up to 100%.'));
            }
        }

        $exists = CostCentreAllocationTemplate::forCompany($creatorId)->where('name', $name)->exists();
        if ($exists) {
            throw new CostCentreException(__('A template named ":name" already exists for this company.', ['name' => $name]));
        }

        return CostCentreAllocationTemplate::create([
            'company_id' => $creatorId,
            'created_by' => $creatorId,
            'name' => $name,
            'basis' => $basis,
            'lines' => $clean,
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public function assertSameCompany(CostCentreAllocation $allocation, int $creatorId): void
    {
        if ((int) $allocation->created_by !== $creatorId) {
            abort(403, __('This allocation belongs to another company.'));
        }
    }

    protected function audit(int $creatorId, CostCentreAllocation $allocation, string $action, ?array $before, ?int $actorId): void
    {
        DimensionAuditEvent::record(
            $creatorId,
            'cost_centre_allocation',
            (int) $allocation->id,
            $action,
            $before,
            $this->summary($allocation),
            $actorId
        );
    }

    /** Small JSON snapshot of an allocation for the audit trail. */
    public function summary(CostCentreAllocation $allocation): array
    {
        return [
            'id' => (int) $allocation->id,
            'status' => $allocation->status,
            'source_type' => $allocation->source_type,
            'source_id' => (int) $allocation->source_id,
            'source_line_type' => $allocation->source_line_type,
            'source_line_id' => (int) $allocation->source_line_id,
            'basis' => $allocation->basis,
            'source_amount' => (string) $allocation->source_amount,
            'reversal_reason' => $allocation->reversal_reason,
            'lines' => $allocation->lines->map(fn ($l) => [
                'cost_centre_id' => (int) $l->cost_centre_id,
                'percentage' => $l->percentage !== null ? (string) $l->percentage : null,
                'fixed_amount' => $l->fixed_amount !== null ? (string) $l->fixed_amount : null,
                'allocated_amount' => (string) $l->allocated_amount,
            ])->all(),
        ];
    }
}
