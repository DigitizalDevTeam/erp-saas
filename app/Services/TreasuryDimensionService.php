<?php

namespace App\Services;

use App\Exceptions\CostCentreException;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\CostCentre;
use App\Models\DimensionAuditEvent;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\TreasuryLineDimension;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury payment-line dimensions (Cost Centre Phase 3, plan §5/§7).
 *
 * A payment that settles a bill INHERITS the bill's dimensions by
 * default — the expense is NOT recognised a second time at payment
 * (accrual once at the bill, cash once at the payment). The user can
 * override the inherited split per payment. A single payment can
 * split across many cost centres. The bank movement is recorded
 * exactly once, by the payment flow itself — this service NEVER calls
 * bankAccountBalance(), postBillPayment() or addTransaction(), and
 * never writes a journal line.
 *
 * Receipts (customer InvoicePayment rows) are tagged the same way,
 * for cash-flow analysis only: tagging a receipt never creates or
 * changes an expense or a debt.
 *
 * Warnings, never blocks, in Phase 3: a dimension that differs from
 * the bill, or a missing dimension where the company requires one,
 * is shown as a warning. The company setting decides warn vs block
 * only from Phase 5 (budget policy) onwards; here, warn.
 */
class TreasuryDimensionService
{
    public function __construct(
        protected AllocationEngine $engine = new AllocationEngine(),
        protected CostCentreAllocationService $allocations = new CostCentreAllocationService(),
        protected CostCentreService $centres = new CostCentreService(),
    ) {}

    // ------------------------------------------------------------------
    // Lookups
    // ------------------------------------------------------------------

    public function dimensionsForPayment(string $paymentType, int $paymentId, int $creatorId)
    {
        $this->assertPaymentType($paymentType);

        return TreasuryLineDimension::forCompany($creatorId)
            ->forPayment($paymentType, $paymentId)
            ->active()
            ->with('costCentre')
            ->orderBy('id')
            ->get();
    }

    public function activeTotalForPayment(string $paymentType, int $paymentId, int $creatorId): string
    {
        $this->assertPaymentType($paymentType);

        $total = TreasuryLineDimension::forCompany($creatorId)
            ->forPayment($paymentType, $paymentId)
            ->active()
            ->sum('allocated_amount');

        return $this->engine->round2((string) $total);
    }

    /** One payment, many centres: the active split as a summary. */
    public function summaryForPayment(string $paymentType, int $paymentId, int $creatorId): array
    {
        $rows = $this->dimensionsForPayment($paymentType, $paymentId, $creatorId);

        return [
            'payment_type' => $paymentType,
            'payment_id' => $paymentId,
            'total' => $this->activeTotalForPayment($paymentType, $paymentId, $creatorId),
            'rows' => $rows->map(fn ($r) => [
                'cost_centre_id' => (int) $r->cost_centre_id,
                'cost_centre_code' => $r->costCentre?->code,
                'project_id' => $r->project_id !== null ? (int) $r->project_id : null,
                'allocated_amount' => (string) $r->allocated_amount,
                'percentage' => $r->percentage !== null ? (string) $r->percentage : null,
                'source' => $r->source,
            ])->all(),
        ];
    }

    // ------------------------------------------------------------------
    // Inheritance — a payment settles a bill (default, no user input)
    // ------------------------------------------------------------------

    /**
     * What inheritance WOULD produce for a bill payment, without
     * writing anything. Used by the edit form preview, by the
     * differs-from-bill warning, and by inheritForBillPayment().
     *
     * Math: the payment settles a fraction of the bill
     * (payment amount / bill total, capped at 1), so each posted bill
     * line split is scaled by that fraction — a payment that settles
     * half the bill inherits half of every line's split. Amounts are
     * rounded with the engine's round2 and the residual lands on the
     * deterministic final centre (highest cost_centre_id), so two runs
     * produce the same amounts.
     *
     * This does NOT go through AllocationEngine::compute(): a
     * partially-allocated bill legitimately yields inherited rows
     * summing to LESS than the payment amount (the unallocated part
     * of the bill has no dimensions), and compute() would reject a
     * split that does not sum to 100%. The posted bill splits were
     * already validated when they were posted; inheritance is a
     * pro-rata scale of validated splits, not a new split.
     */
    public function inheritedPreviewForBillPayment(BillPayment $payment, int $creatorId): array
    {
        $bill = $this->resolveBillForPayment($payment, $creatorId);
        $paymentAmount = $this->engine->round2((string) ($payment->amount ?? 0));
        $warnings = [];

        if (bccomp($paymentAmount, '0', 2) <= 0) {
            return $this->previewResult([], $paymentAmount, array_merge($warnings, [__('This payment has no amount, so no dimensions can be inherited.')]));
        }

        $billTotal = $this->engine->round2((string) $bill->getTotal());
        if (bccomp($billTotal, '0', 2) <= 0) {
            return $this->previewResult([], $paymentAmount, array_merge($warnings, [__('The bill total is zero, so no dimensions can be inherited.')]));
        }

        // Fraction of the bill this payment settles, capped at 1 (an
        // overpayment inherits at most the bill's full split).
        $fraction = bcdiv($paymentAmount, $billTotal, AllocationEngine::SCALE);
        if (bccomp($fraction, '1', AllocationEngine::SCALE) > 0) {
            $fraction = '1';
            $warnings[] = __('This payment is more than the bill total, so the inherited dimensions are capped at the bill’s split.');
        }

        $sourceType = $this->allocations->sourceTypeForBill($bill);
        $posted = $this->allocations->postedForSource($sourceType, (int) $bill->id, $creatorId);

        if ($posted->isEmpty()) {
            return $this->previewResult([], $paymentAmount, array_merge($warnings, [__('The bill has no posted cost centre allocations, so there is nothing to inherit.')]));
        }

        // Aggregate each centre's scaled amount across all bill lines.
        $perCentre = [];
        foreach ($posted as $allocation) {
            foreach ($allocation->lines as $line) {
                $scaled = bcmul((string) $line->allocated_amount, $fraction, AllocationEngine::SCALE);
                $centreId = (int) $line->cost_centre_id;
                $perCentre[$centreId] = bcadd($perCentre[$centreId] ?? '0', $scaled, AllocationEngine::SCALE);
            }
        }

        $date = $payment->date ? Carbon::parse($payment->date) : Carbon::today();
        $rows = [];
        $keptTotal = '0';
        $centreIds = array_keys($perCentre);
        sort($centreIds); // Deterministic order; residual lands on the last.
        foreach ($centreIds as $centreId) {
            $centre = CostCentre::find($centreId);
            if (! $centre || (int) $centre->created_by !== $creatorId) {
                $warnings[] = __('Cost centre #:id on the bill no longer belongs to this company, so its share was skipped.', ['id' => $centreId]);
                continue;
            }
            if (! $this->centres->canAcceptTransactions($centre, $date)) {
                $warnings[] = __('Cost centre :code cannot take new transactions, so its share was skipped.', ['code' => $centre->code]);
                continue;
            }
            $keptTotal = bcadd($keptTotal, $perCentre[$centreId], AllocationEngine::SCALE);
            $rows[] = [
                'cost_centre_id' => $centreId,
                'percentage' => null, // Filled below, as a share of the payment.
                'allocated_amount' => $this->engine->round2($perCentre[$centreId]),
                'sort_order' => count($rows),
            ];
        }

        if ($rows === []) {
            return $this->previewResult([], $paymentAmount, $warnings);
        }

        // Residual: the rounded rows must sum to exactly the rounded
        // inherited total (fraction × the KEPT centres' allocated total
        // — a skipped centre's share is not inherited at all).
        $inheritedTotal = $this->engine->round2($keptTotal);
        $sum = '0.00';
        foreach ($rows as $row) {
            $sum = bcadd($sum, $row['allocated_amount'], 2);
        }
        $residual = bcsub($inheritedTotal, $sum, 2);
        if (bccomp($residual, '0', 2) !== 0) {
            $last = count($rows) - 1;
            $rows[$last]['allocated_amount'] = bcadd($rows[$last]['allocated_amount'], $residual, 2);
        }

        // Percentages are shares of the PAYMENT (for the stored rows).
        foreach ($rows as $i => $row) {
            $rows[$i]['percentage'] = $this->percentageOf($row['allocated_amount'], $paymentAmount);
        }

        return $this->previewResult($rows, $paymentAmount, $warnings, $inheritedTotal);
    }

    protected function previewResult(array $rows, string $paymentAmount, array $warnings, ?string $inheritedTotal = null): array
    {
        return [
            'rows' => $rows,
            'payment_amount' => $paymentAmount,
            'inherited_total' => $inheritedTotal ?? $this->sumRows($rows),
            'warnings' => $warnings,
        ];
    }

    protected function sumRows(array $rows): string
    {
        $sum = '0.00';
        foreach ($rows as $row) {
            $sum = bcadd($sum, (string) $row['allocated_amount'], 2);
        }

        return $sum;
    }

    /** Share of $part in $whole, as a 6dp percentage string. */
    protected function percentageOf(string $part, string $whole): string
    {
        if (bccomp($whole, '0', 2) === 0) {
            return '0.000000';
        }

        return bcdiv(bcmul($part, '100', AllocationEngine::SCALE), $whole, 6);
    }

    /**
     * Write the inherited dimensions for a bill payment. Idempotent:
     * if the payment already has active dimensions, they are returned
     * unchanged (a payment is tagged once; changes go through
     * applyDimensions()). A closed period blocks inheritance — the
     * payment itself already happened, so this is a warning, not an
     * exception: the dimensions screen can fix it after a reopen.
     */
    public function inheritForBillPayment(BillPayment $payment, int $creatorId, int $userId): array
    {
        $existing = $this->dimensionsForPayment(TreasuryLineDimension::TYPE_BILL_PAYMENT, (int) $payment->id, $creatorId);
        if ($existing->isNotEmpty()) {
            return [
                'rows' => $this->summaryForPayment(TreasuryLineDimension::TYPE_BILL_PAYMENT, (int) $payment->id, $creatorId)['rows'],
                'payment_amount' => $this->engine->round2((string) ($payment->amount ?? 0)),
                'inherited_total' => $this->activeTotalForPayment(TreasuryLineDimension::TYPE_BILL_PAYMENT, (int) $payment->id, $creatorId),
                'warnings' => [],
                'already_tagged' => true,
            ];
        }

        $date = $payment->date ? Carbon::parse($payment->date) : Carbon::today();
        try {
            $this->allocations->assertPeriodOpen($creatorId, $date);
        } catch (CostCentreException $e) {
            return [
                'rows' => [],
                'payment_amount' => $this->engine->round2((string) ($payment->amount ?? 0)),
                'inherited_total' => '0.00',
                'warnings' => [$e->getMessage()],
                'already_tagged' => false,
            ];
        }

        $preview = $this->inheritedPreviewForBillPayment($payment, $creatorId);

        if ($preview['rows'] === []) {
            return array_merge($preview, ['already_tagged' => false]);
        }

        $bill = $this->resolveBillForPayment($payment, $creatorId);

        DB::transaction(function () use ($preview, $payment, $bill, $creatorId, $userId) {
            foreach ($preview['rows'] as $row) {
                TreasuryLineDimension::create([
                    'company_id' => $creatorId,
                    'created_by' => $creatorId,
                    'payment_type' => TreasuryLineDimension::TYPE_BILL_PAYMENT,
                    'payment_id' => (int) $payment->id,
                    'bill_id' => (int) $bill->id,
                    'cost_centre_id' => $row['cost_centre_id'],
                    'project_id' => null,
                    'asset_id' => null,
                    'allocated_amount' => $row['allocated_amount'],
                    'percentage' => $row['percentage'],
                    'source' => TreasuryLineDimension::SOURCE_INHERITED,
                    'status' => TreasuryLineDimension::STATUS_ACTIVE,
                ]);
            }

            $this->audit($creatorId, TreasuryLineDimension::TYPE_BILL_PAYMENT, (int) $payment->id, 'inherited', null, $this->summaryForPayment(TreasuryLineDimension::TYPE_BILL_PAYMENT, (int) $payment->id, $creatorId), $userId);
        });

        return array_merge($preview, ['already_tagged' => false]);
    }

    // ------------------------------------------------------------------
    // Override / manual tagging — one payment, many centres
    // ------------------------------------------------------------------

    /**
     * Apply a manual split to any payment (bill payment override, or
     * receipt tagging). The split is validated by the AllocationEngine
     * (% sums to 100% or fixed amounts equal the payment amount, with
     * the deterministic rounding residual) — the split math is never
     * rebuilt here. The old active rows are superseded, never edited
     * in place; the audit event keeps the before/after.
     *
     * This writes DIMENSION rows only. It never touches the bank
     * balance, the journal, or the bill/invoice debt.
     */
    public function applyDimensions(
        string $paymentType,
        int $paymentId,
        array $lines,
        string $basis,
        int $creatorId,
        int $userId,
        ?int $projectId = null
    ): array {
        $this->assertPaymentType($paymentType);

        $payment = $this->resolvePayment($paymentType, $paymentId, $creatorId);
        $amount = $this->engine->round2((string) ($payment->amount ?? 0));
        $date = $payment->date ? Carbon::parse($payment->date) : Carbon::today();

        if (bccomp($amount, '0', 2) <= 0) {
            throw new CostCentreException(__('This payment has no amount to split.'));
        }

        $computed = $this->engine->compute($amount, $lines, $basis);

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

        $this->allocations->assertPeriodOpen($creatorId, $date);

        if ($projectId !== null) {
            $this->assertProjectBelongsToCompany($projectId, $creatorId);
        }

        $billId = $paymentType === TreasuryLineDimension::TYPE_BILL_PAYMENT
            ? (int) $payment->bill_id
            : null;

        return DB::transaction(function () use ($paymentType, $paymentId, $computed, $creatorId, $userId, $projectId, $billId, $basis) {
            $before = $this->summaryForPayment($paymentType, $paymentId, $creatorId);

            TreasuryLineDimension::forCompany($creatorId)
                ->forPayment($paymentType, $paymentId)
                ->active()
                ->update(['status' => TreasuryLineDimension::STATUS_SUPERSEDED]);

            foreach ($computed['lines'] as $row) {
                TreasuryLineDimension::create([
                    'company_id' => $creatorId,
                    'created_by' => $creatorId,
                    'payment_type' => $paymentType,
                    'payment_id' => $paymentId,
                    'bill_id' => $billId,
                    'cost_centre_id' => $row['cost_centre_id'],
                    'project_id' => $projectId,
                    'asset_id' => null,
                    'allocated_amount' => $row['allocated_amount'],
                    'percentage' => $row['percentage'],
                    'source' => TreasuryLineDimension::SOURCE_MANUAL,
                    'status' => TreasuryLineDimension::STATUS_ACTIVE,
                ]);
            }

            $after = $this->summaryForPayment($paymentType, $paymentId, $creatorId);
            $this->audit($creatorId, $paymentType, $paymentId, 'overridden', $before, $after, $userId);

            return array_merge($after, [
                'basis' => $basis,
                'warnings' => $this->warningsForPayment($paymentType, $paymentId, $creatorId),
            ]);
        });
    }

    /**
     * Tag a receipt (customer InvoicePayment) with dimensions, for
     * cash-flow analysis only. A receipt is money IN: tagging it never
     * creates or changes an expense or a debt — this method writes
     * dimension rows and nothing else.
     */
    public function tagReceipt(
        InvoicePayment $receipt,
        array $lines,
        string $basis,
        int $creatorId,
        int $userId,
        ?int $projectId = null
    ): array {
        $this->resolveReceipt((int) $receipt->id, $creatorId);

        $result = $this->applyDimensions(
            TreasuryLineDimension::TYPE_RECEIPT,
            (int) $receipt->id,
            $lines,
            $basis,
            $creatorId,
            $userId,
            $projectId
        );

        // The audit action for a receipt is 'tagged', not 'overridden':
        // rewrite the last event's action so the trail reads correctly.
        DimensionAuditEvent::where('company_id', $creatorId)
            ->where('entity_type', 'treasury_line_dimension')
            ->where('entity_id', (int) $receipt->id)
            ->where('action', 'overridden')
            ->orderByDesc('id')
            ->limit(1)
            ->update(['action' => 'tagged']);

        return $result;
    }

    // ------------------------------------------------------------------
    // Warnings (Phase 3: warn, never block)
    // ------------------------------------------------------------------

    /**
     * Warnings for a payment's dimensions. Phase 3 warns only:
     *  - a bill payment with no dimensions where the company requires
     *    dimensions on cash transactions (setting
     *    dimension_on_cash_transactions = 'required');
     *  - dimensions that cover less than the payment amount (part of
     *    the bill was never allocated);
     *  - a manual split that differs from what the bill would inherit
     *    (the expense stays recognised at the bill; only the cash view
     *    changes).
     * Receipts never warn: tagging is optional analysis.
     */
    public function warningsForPayment(string $paymentType, int $paymentId, int $creatorId): array
    {
        $this->assertPaymentType($paymentType);
        $warnings = [];

        if ($paymentType !== TreasuryLineDimension::TYPE_BILL_PAYMENT) {
            return $warnings;
        }

        $payment = $this->resolveBillPayment($paymentId, $creatorId);
        $amount = $this->engine->round2((string) ($payment->amount ?? 0));
        $rows = $this->dimensionsForPayment($paymentType, $paymentId, $creatorId);

        if ($rows->isEmpty()) {
            if ($this->dimensionsRequiredOnCash($creatorId)) {
                $warnings[] = __('This payment has no cost centre dimensions, but the company requires dimensions on cash transactions.');
            }

            return $warnings;
        }

        $total = $this->activeTotalForPayment($paymentType, $paymentId, $creatorId);
        if (bccomp($total, $amount, 2) < 0) {
            $warnings[] = __('Dimensions cover :covered of :amount — :rest has no cost centre.', [
                'covered' => $total,
                'amount' => $amount,
                'rest' => bcsub($amount, $total, 2),
            ]);
        }

        if ($rows->contains(fn ($r) => $r->source === TreasuryLineDimension::SOURCE_MANUAL)) {
            $preview = $this->inheritedPreviewForBillPayment($payment, $creatorId);
            if ($this->splitDiffers($rows, $preview['rows'])) {
                $warnings[] = __('These dimensions differ from the bill’s dimensions. The expense stays recognised at the bill; only the cash view changes.');
            }
        }

        return $warnings;
    }

    protected function dimensionsRequiredOnCash(int $creatorId): bool
    {
        return Schema::hasTable('settings')
            && CostCentreSettings::get($creatorId, CostCentreSettings::KEY_DIMENSION_ON_CASH) === 'required';
    }

    /** Do the active rows differ from the inherited preview, per centre? */
    protected function splitDiffers($rows, array $previewRows): bool
    {
        $active = [];
        foreach ($rows as $row) {
            $active[(int) $row->cost_centre_id] = (string) $row->allocated_amount;
        }

        $preview = [];
        foreach ($previewRows as $row) {
            $preview[(int) $row['cost_centre_id']] = (string) $row['allocated_amount'];
        }

        if (count($active) !== count($preview)) {
            return true;
        }
        foreach ($active as $centreId => $amount) {
            if (! isset($preview[$centreId])) {
                return true;
            }
            if (bccomp($amount, $preview[$centreId], 2) !== 0) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Removal — follows the payment lifecycle (never orphaned)
    // ------------------------------------------------------------------

    /** Supersede every active dimension row on a payment (it was deleted). */
    /**
     * Supersede every active dimension row on a payment. A closed
     * period blocks the removal (Phase 6 hardening) — the one exception
     * is a payment DELETE: the payment itself is gone, so its
     * dimensions must follow it or they would be orphaned. That caller
     * (BillController's destroy hook) passes $allowClosedPeriod = true.
     */
    public function removeForPayment(string $paymentType, int $paymentId, int $creatorId, int $userId, string $reason, bool $allowClosedPeriod = false): void
    {
        $this->assertPaymentType($paymentType);

        $before = $this->summaryForPayment($paymentType, $paymentId, $creatorId);
        if ($before['rows'] === []) {
            return;
        }

        if (! $allowClosedPeriod) {
            $date = $this->paymentDate($paymentType, $paymentId, $creatorId);
            if ($date !== null) {
                $this->allocations->assertPeriodOpen($creatorId, $date);
            }
        }

        DB::transaction(function () use ($paymentType, $paymentId, $creatorId, $userId, $before, $reason) {
            TreasuryLineDimension::forCompany($creatorId)
                ->forPayment($paymentType, $paymentId)
                ->active()
                ->update(['status' => TreasuryLineDimension::STATUS_SUPERSEDED]);

            $after = $this->summaryForPayment($paymentType, $paymentId, $creatorId);
            $after['removal_reason'] = $reason;
            $this->audit($creatorId, $paymentType, $paymentId, 'removed', $before, $after, $userId);
        });
    }

    // ------------------------------------------------------------------
    // Resolution + guards
    // ------------------------------------------------------------------

    /** The payment's date, for the closed-period check. Resolving the
     *  payment also asserts it belongs to this company. Treasury
     *  payments (future module) have no dated row yet, so they return
     *  null and skip the check. */
    protected function paymentDate(string $paymentType, int $paymentId, int $creatorId): ?Carbon
    {
        if ($paymentType === TreasuryLineDimension::TYPE_BILL_PAYMENT) {
            $payment = $this->resolveBillPayment($paymentId, $creatorId);

            return $payment->date ? Carbon::parse($payment->date) : null;
        }

        if ($paymentType === TreasuryLineDimension::TYPE_RECEIPT) {
            $receipt = $this->resolveReceipt($paymentId, $creatorId);

            return $receipt->date ? Carbon::parse($receipt->date) : null;
        }

        return null;
    }

    protected function assertPaymentType(string $paymentType): void
    {
        if (! in_array($paymentType, TreasuryLineDimension::TYPES, true)) {
            throw new CostCentreException(__('Invalid payment type for treasury dimensions.'));
        }
    }

    public function resolveBillPayment(int $paymentId, int $creatorId): BillPayment
    {
        $payment = BillPayment::find($paymentId);
        if (! $payment) {
            throw new CostCentreException(__('The bill payment does not exist.'));
        }
        $this->resolveBillForPayment($payment, $creatorId);

        return $payment;
    }

    public function resolveBillForPayment(BillPayment $payment, int $creatorId): Bill
    {
        $bill = Bill::find((int) $payment->bill_id);
        if (! $bill) {
            throw new CostCentreException(__('The bill this payment settles does not exist.'));
        }
        if ((int) $bill->created_by !== $creatorId) {
            throw new CostCentreException(__('This payment belongs to another company.'));
        }

        return $bill;
    }

    public function resolveReceipt(int $receiptId, int $creatorId): InvoicePayment
    {
        $receipt = InvoicePayment::find($receiptId);
        if (! $receipt) {
            throw new CostCentreException(__('The receipt does not exist.'));
        }
        $invoice = Invoice::find((int) $receipt->invoice_id);
        if (! $invoice) {
            throw new CostCentreException(__('The invoice this receipt settles does not exist.'));
        }
        if ((int) $invoice->created_by !== $creatorId) {
            throw new CostCentreException(__('This receipt belongs to another company.'));
        }

        return $receipt;
    }

    protected function resolvePayment(string $paymentType, int $paymentId, int $creatorId): BillPayment|InvoicePayment
    {
        return match ($paymentType) {
            TreasuryLineDimension::TYPE_BILL_PAYMENT => $this->resolveBillPayment($paymentId, $creatorId),
            TreasuryLineDimension::TYPE_RECEIPT => $this->resolveReceipt($paymentId, $creatorId),
            // The Treasury module's own payments do not exist yet.
            default => throw new CostCentreException(__('Treasury payments do not exist yet — tag bill payments and receipts only.')),
        };
    }

    protected function assertProjectBelongsToCompany(int $projectId, int $creatorId): void
    {
        if (! Schema::hasTable('projects')) {
            throw new CostCentreException(__('Projects do not exist in this app.'));
        }
        $project = DB::table('projects')->where('id', $projectId)->first();
        if (! $project) {
            throw new CostCentreException(__('The selected project does not exist.'));
        }
        if ((int) ($project->created_by ?? 0) !== $creatorId) {
            throw new CostCentreException(__('The selected project belongs to another company.'));
        }
    }

    protected function audit(int $creatorId, string $paymentType, int $paymentId, string $action, ?array $before, ?array $after, ?int $actorId): void
    {
        DimensionAuditEvent::record(
            $creatorId,
            'treasury_line_dimension',
            $paymentId,
            $action,
            $before,
            $after,
            $actorId
        );
    }
}
