<?php

namespace App\Services;

use App\Exceptions\CostCentreException;
use App\Models\Bill;
use App\Models\CostCentreCommitment;
use App\Models\ProductService;
use App\Models\Project;
use App\Models\Purchase;
use App\Models\Utility;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Cost Centre Phase 7 — purchasing.
 *
 * A purchase order is the commitment stage: creating (or editing) a
 * PO with a cost centre reserves a budget commitment per GL account
 * group, reusing the Phase 5 commitments table and
 * BudgetControlService. Converting the PO to a vendor bill converts
 * the commitment exactly once — the actual then rises through the
 * dimensioned bill lines, so the money is never counted as both
 * committed and actual.
 *
 * Line amount mirrors the app's own purchase math
 * (Purchase::getTotalTax): price × qty − discount + tax, tax on the
 * gross line amount.
 */
class PurchaseCommitmentService
{
    public const SOURCE = CostCentreCommitment::SOURCE_PURCHASE;

    public function __construct(
        protected BudgetControlService $budgets = new BudgetControlService(),
    ) {
    }

    /**
     * Group a purchase's line amounts by the product's expense GL
     * account (null when the product has none — the centre-only
     * budget scope). Returns rows of
     * [gl_account_id, project_id, amount, lines].
     *
     * @return array<int, array{gl_account_id: ?int, project_id: ?int, amount: string, lines: array<int>}>
     */
    public function groupsForPurchase(Purchase $purchase): array
    {
        $taxData = Utility::getTaxData();
        $groups = [];

        $purchase->loadMissing('items.product');

        foreach ($purchase->items as $item) {
            $gross = bcmul((string) ($item->price ?? 0), (string) ($item->quantity ?? 0), 4);
            $taxRate = 0.0;
            foreach (explode(',', (string) ($item->tax ?? '')) as $taxId) {
                $taxRate += ! empty($taxData[$taxId]['rate']) ? (float) $taxData[$taxId]['rate'] : 0.0;
            }
            $tax = bcmul($gross, bcdiv((string) $taxRate, '100', 10), 4);
            $amount = bcsub(bcadd($gross, $tax, 4), (string) ($item->discount ?? 0), 4);

            $product = $item->product instanceof ProductService ? $item->product : null;
            $glAccountId = $product !== null && $product->expense_chartaccount_id !== null
                ? (int) $product->expense_chartaccount_id
                : null;

            $key = $glAccountId === null ? 'none' : (string) $glAccountId;
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'gl_account_id' => $glAccountId,
                    'project_id' => $purchase->project_id !== null ? (int) $purchase->project_id : null,
                    'amount' => '0.00',
                    'lines' => [],
                ];
            }
            $groups[$key]['amount'] = bcadd($groups[$key]['amount'], $amount, 2);
            $groups[$key]['lines'][] = (int) $item->id;
        }

        return array_values($groups);
    }

    /**
     * Run the budget check for every group without reserving
     * anything. Overall decision: 'blocked' if any group is blocked,
     * else 'needs_approval' if any group needs approval, else
     * 'allowed'. Warnings (warn policy / approval routing) are
     * collected per group.
     */
    public function checkForPurchase(Purchase $purchase, int $requesterId): array
    {
        $creatorId = (int) $purchase->created_by;

        if ($purchase->cost_centre_id === null) {
            return ['decision' => 'allowed', 'reason' => 'no_centre', 'warnings' => [], 'groups' => []];
        }

        $this->assertProjectForCompany($purchase, $creatorId);

        $date = $purchase->purchase_date !== null
            ? Carbon::parse($purchase->purchase_date)
            : Carbon::today();

        $decision = 'allowed';
        $warnings = [];
        $checks = [];

        foreach ($this->groupsForPurchase($purchase) as $group) {
            if (bccomp($group['amount'], '0', 2) <= 0) {
                continue;
            }

            $check = $this->budgets->check(
                $creatorId,
                (int) $purchase->cost_centre_id,
                $group['amount'],
                $requesterId,
                $date,
                $group['gl_account_id'],
                $group['project_id'],
                'Purchase order #'.($purchase->purchase_number ?? $purchase->id),
            );
            $check['gl_account_id'] = $group['gl_account_id'];
            $checks[] = $check;

            if ($check['decision'] === 'blocked') {
                $decision = 'blocked';
            } elseif ($check['decision'] === 'needs_approval' && $decision !== 'blocked') {
                $decision = 'needs_approval';
            }

            if (! empty($check['warning'])) {
                $warnings[] = $check['warning'];
            }
        }

        return ['decision' => $decision, 'warnings' => $warnings, 'groups' => $checks];
    }

    /**
     * Reserve one commitment per account group. Throws
     * CostCentreException when any group is blocked (policy=block) —
     * nothing is reserved in that case. Idempotent per
     * source+centre+scope (the Phase 5 reserve is). No-op when the
     * purchase has no cost centre.
     *
     * @return CostCentreCommitment[]
     */
    public function reserveForPurchase(Purchase $purchase, int $requesterId): array
    {
        if ($purchase->cost_centre_id === null) {
            return [];
        }

        $this->assertProjectForCompany($purchase, (int) $purchase->created_by);

        $date = $purchase->purchase_date !== null
            ? Carbon::parse($purchase->purchase_date)
            : Carbon::today();

        $reserved = [];
        foreach ($this->groupsForPurchase($purchase) as $group) {
            if (bccomp($group['amount'], '0', 2) <= 0) {
                continue;
            }

            $reserved[] = $this->budgets->reserveCommitment(
                (int) $purchase->created_by,
                (int) $purchase->cost_centre_id,
                $group['amount'],
                self::SOURCE,
                (int) $purchase->id,
                $requesterId,
                $date,
                $group['gl_account_id'],
                $group['project_id'],
                'Purchase order #'.($purchase->purchase_number ?? $purchase->id),
            );
        }

        return $reserved;
    }

    /**
     * Re-sync a purchase's commitments after an edit: check first
     * (a block throws and the old commitments stay untouched), then
     * cancel the live commitments and re-reserve from the new lines.
     * A purchase that lost its centre ends with no commitments.
     *
     * @return CostCentreCommitment[]
     */
    public function syncForPurchase(Purchase $purchase, int $requesterId): array
    {
        $creatorId = (int) $purchase->created_by;

        if (! $this->commitmentsTableExists()) {
            return [];
        }

        $check = $this->checkForPurchase($purchase, $requesterId);

        if ($check['decision'] === 'blocked') {
            throw new CostCentreException($check['warnings'][0] ?? __('Over budget.'));
        }

        $this->cancelLiveCommitments($purchase, $creatorId, $requesterId);

        return $this->reserveForPurchase($purchase, $requesterId);
    }

    /**
     * Convert the PO's commitments to their vendor bill. Exactly
     * once: only active commitments convert, so a second call (or a
     * double submit) converts zero rows. No-op when the commitments
     * table does not exist yet.
     */
    public function convertForPurchase(Purchase $purchase, Bill $bill, int $userId): int
    {
        if (! $this->commitmentsTableExists()) {
            return 0;
        }

        return $this->budgets->convertCommitment(
            (int) $purchase->created_by,
            self::SOURCE,
            (int) $purchase->id,
            (int) $bill->id,
            $userId,
        );
    }

    /**
     * Cancel every live commitment for a purchase (e.g. the PO was
     * deleted). Converted/released commitments are untouched.
     */
    public function cancelForPurchase(Purchase $purchase, int $userId): int
    {
        if (! $this->commitmentsTableExists()) {
            return 0;
        }

        $cancelled = 0;
        foreach ($this->commitmentsForPurchase($purchase) as $commitment) {
            $this->budgets->cancelCommitment($commitment, (int) $purchase->created_by, $userId);
            $cancelled++;
        }

        return $cancelled;
    }

    /**
     * Live (active / pending approval) commitments for a purchase.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CostCentreCommitment>
     */
    public function commitmentsForPurchase(Purchase $purchase)
    {
        if (! $this->commitmentsTableExists()) {
            return collect();
        }

        return CostCentreCommitment::forCompany((int) $purchase->created_by)
            ->where('source_type', self::SOURCE)
            ->where('source_id', (int) $purchase->id)
            ->whereIn('status', [CostCentreCommitment::STATUS_ACTIVE, CostCentreCommitment::STATUS_PENDING_APPROVAL])
            ->get();
    }

    protected function cancelLiveCommitments(Purchase $purchase, int $creatorId, int $userId): void
    {
        foreach ($this->commitmentsForPurchase($purchase) as $commitment) {
            $this->budgets->cancelCommitment($commitment, $creatorId, $userId);
        }
    }

    protected function assertProjectForCompany(Purchase $purchase, int $creatorId): void
    {
        if ($purchase->project_id === null) {
            return;
        }

        $exists = Project::where('id', (int) $purchase->project_id)
            ->where('created_by', $creatorId)
            ->exists();

        if (! $exists) {
            throw new CostCentreException(__('Project not found.'));
        }
    }

    protected function commitmentsTableExists(): bool
    {
        // Live check on every call, never memoized (Phase 0–5 schema
        // safety: a minimal install has no commitments table).
        return Schema::hasTable('cost_centre_commitments');
    }
}
