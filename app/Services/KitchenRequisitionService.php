<?php

namespace App\Services;

use App\Models\KitchenProductionOrder;
use App\Models\KitchenProductionPlan;
use App\Models\KitchenRequisition;
use App\Models\KitchenRequisitionLine;
use App\Models\ProductService;
use App\Models\ProductServiceUnit;
use App\Models\StockReport;
use App\Models\User;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use App\Models\WarehouseTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Kitchen Production — requisitions (Phase 3, plan §3, §5–§6).
 *
 * A requisition moves stock that is ALREADY in a warehouse into the
 * kitchen warehouse. It is NEVER a purchase order: it asks for what is
 * short, the warehouse approves, and the issue physically moves the
 * stock (a warehouse transfer, not a purchase).
 *
 * Flow: REQUESTED -> APPROVED -> ISSUED / PARTIALLY_ISSUED, with
 * REJECTED returning the request to the kitchen. Issuing is the only
 * step that touches stock, and it is idempotent per line: a line can
 * never be issued past its shortage, and the source warehouse can
 * never be taken below zero.
 *
 * Stock movement mirrors the existing transfer path
 * (WarehouseTransferController::store + Utility::warehouse_transfer_qty
 * + Utility::addProductStock), with the company id explicit instead of
 * read from Auth, so the service stays unit-testable.
 */
class KitchenRequisitionService
{
    public function __construct(
        protected KitchenProductionPlanService $plans,
        protected KitchenProductionOrderService $orders,
    ) {
    }

    // -----------------------------------------------------------------
    // Creation
    // -----------------------------------------------------------------

    /**
     * @param array{kitchen_production_plan_id?: int|null, kitchen_production_order_id?: int|null, source_warehouse_id: int, kitchen_warehouse_id: int, branch_id?: int|null, notes?: string|null} $data
     * @param array<int, array{ingredient_product_id: int, required_quantity: float|int, unit_id?: int|null, unit_cost?: float|int|null}> $lines
     */
    public function create(array $data, array $lines, User $user): KitchenRequisition
    {
        $creatorId = (int) $user->creatorId();

        $this->validateWarehouses($data, $creatorId);
        $validatedLines = $this->validateLines($lines, (int) $data['kitchen_warehouse_id'], $creatorId);

        return DB::transaction(function () use ($data, $validatedLines, $user, $creatorId) {
            $requisition = KitchenRequisition::create([
                'requisition_number' => $this->nextRequisitionNumber($creatorId),
                'kitchen_production_plan_id' => ! empty($data['kitchen_production_plan_id']) ? (int) $data['kitchen_production_plan_id'] : null,
                'kitchen_production_order_id' => ! empty($data['kitchen_production_order_id']) ? (int) $data['kitchen_production_order_id'] : null,
                'source_warehouse_id' => (int) $data['source_warehouse_id'],
                'kitchen_warehouse_id' => (int) $data['kitchen_warehouse_id'],
                'branch_id' => ! empty($data['branch_id']) ? (int) $data['branch_id'] : null,
                'notes' => $data['notes'] ?? null,
                'status' => KitchenRequisition::STATUS_REQUESTED,
                'requested_by' => (int) $user->id,
                'created_by' => $creatorId,
            ]);

            foreach ($validatedLines as $line) {
                KitchenRequisitionLine::create(array_merge($line, [
                    'kitchen_requisition_id' => (int) $requisition->id,
                    'issued_quantity' => 0,
                    'created_by' => $creatorId,
                ]));
            }

            return $requisition->fresh('lines');
        });
    }

    /**
     * Edit a requested requisition: the header and the lines are replaced,
     * and availability / shortage are re-computed against the kitchen
     * warehouse at edit time.
     */
    public function updateDraft(KitchenRequisition $requisition, array $data, array $lines, User $user): KitchenRequisition
    {
        if (! $requisition->isRequested()) {
            throw new InvalidArgumentException(__('Only a requested requisition can be edited.'));
        }

        $creatorId = (int) $requisition->created_by;

        $this->validateWarehouses($data, $creatorId);
        $validatedLines = $this->validateLines($lines, (int) $data['kitchen_warehouse_id'], $creatorId);

        return DB::transaction(function () use ($requisition, $data, $validatedLines, $creatorId) {
            $requisition->update([
                'kitchen_production_plan_id' => ! empty($data['kitchen_production_plan_id']) ? (int) $data['kitchen_production_plan_id'] : null,
                'kitchen_production_order_id' => ! empty($data['kitchen_production_order_id']) ? (int) $data['kitchen_production_order_id'] : null,
                'source_warehouse_id' => (int) $data['source_warehouse_id'],
                'kitchen_warehouse_id' => (int) $data['kitchen_warehouse_id'],
                'branch_id' => ! empty($data['branch_id']) ? (int) $data['branch_id'] : null,
                'notes' => $data['notes'] ?? null,
            ]);

            $requisition->lines()->delete();
            foreach ($validatedLines as $line) {
                KitchenRequisitionLine::create(array_merge($line, [
                    'kitchen_requisition_id' => (int) $requisition->id,
                    'issued_quantity' => 0,
                    'created_by' => $creatorId,
                ]));
            }

            return $requisition->fresh('lines');
        });
    }

    /**
     * Build a requisition from a plan's shortages: one line per
     * ingredient that is short in the kitchen warehouse, required =
     * the shortage. If nothing is short, there is nothing to ask for.
     */
    public function createFromPlan(KitchenProductionPlan $plan, int $sourceWarehouseId, User $user, ?string $notes = null): KitchenRequisition
    {
        $this->assertSameCompany($plan, $user);

        $requirements = $this->plans->requirements($plan);
        $lines = [];
        foreach ($requirements['lines'] as $row) {
            if ($row['shortage'] <= 0) {
                continue;
            }
            // required = the total the plan needs (validateLines() then
            // re-computes available / shortage against the kitchen
            // warehouse, which lands on this same shortage).
            $lines[] = [
                'ingredient_product_id' => (int) $row['ingredient_id'],
                'required_quantity' => (float) $row['needed'],
                'unit_id' => $row['unit_id'],
                'unit_cost' => (float) $row['unit_cost'],
            ];
        }

        if ($lines === []) {
            throw new InvalidArgumentException(__('This plan has no shortages in the kitchen warehouse — there is nothing to requisition.'));
        }

        return $this->create([
            'kitchen_production_plan_id' => (int) $plan->id,
            'source_warehouse_id' => $sourceWarehouseId,
            'kitchen_warehouse_id' => (int) $plan->warehouse_id,
            'branch_id' => $plan->branch_id,
            'notes' => $notes,
        ], $lines, $user);
    }

    /**
     * Build a requisition from an order's planned lines: one line per
     * ingredient short in the kitchen warehouse, required = the
     * shortage against the order's planned quantities.
     */
    public function createFromOrder(KitchenProductionOrder $order, int $sourceWarehouseId, User $user, ?string $notes = null): KitchenRequisition
    {
        $this->assertSameCompany($order, $user);

        $creatorId = (int) $order->created_by;
        $lines = [];
        foreach ($order->lines as $orderLine) {
            $ingredientId = (int) $orderLine->ingredient_product_id;
            $product = ProductService::where('id', $ingredientId)
                ->where('created_by', $creatorId)
                ->first();
            if (! $product) {
                continue;
            }

            $required = (float) $orderLine->planned_quantity;
            $available = $this->orders->warehouseQty($ingredientId, (int) $order->warehouse_id, $creatorId);
            $shortage = $product->allowsNegativeStock()
                ? 0.0
                : max(0.0, round($required - $available, 4));

            if ($shortage <= 0) {
                continue;
            }

            // required = the total the order needs (validateLines() then
            // re-computes available / shortage against the kitchen
            // warehouse, which lands on this same shortage).
            $lines[] = [
                'ingredient_product_id' => $ingredientId,
                'required_quantity' => $required,
                'unit_id' => $orderLine->planned_unit_id,
                'unit_cost' => (float) ($orderLine->planned_unit_cost ?? 0),
            ];
        }

        if ($lines === []) {
            throw new InvalidArgumentException(__('This order has no shortages in the kitchen warehouse — there is nothing to requisition.'));
        }

        return $this->create([
            'kitchen_production_order_id' => (int) $order->id,
            'source_warehouse_id' => $sourceWarehouseId,
            'kitchen_warehouse_id' => (int) $order->warehouse_id,
            'branch_id' => $order->branch_id,
            'notes' => $notes,
        ], $lines, $user);
    }

    // -----------------------------------------------------------------
    // Approval / rejection
    // -----------------------------------------------------------------

    public function approve(KitchenRequisition $requisition, User $user): KitchenRequisition
    {
        if (! $requisition->isRequested()) {
            throw new InvalidArgumentException(__('Only a requested requisition can be approved.'));
        }

        if ((int) $user->id === (int) $requisition->requested_by) {
            throw new InvalidArgumentException(__('You cannot approve a requisition you requested yourself.'));
        }

        $requisition->update([
            'status' => KitchenRequisition::STATUS_APPROVED,
            'approved_by' => (int) $user->id,
            'approved_at' => now(),
        ]);

        return $requisition->fresh();
    }

    public function reject(KitchenRequisition $requisition, User $user, ?string $note = null): KitchenRequisition
    {
        if (! $requisition->isRequested()) {
            throw new InvalidArgumentException(__('Only a requested requisition can be rejected.'));
        }

        if ((int) $user->id === (int) $requisition->requested_by) {
            throw new InvalidArgumentException(__('You cannot reject a requisition you requested yourself.'));
        }

        $requisition->update([
            'status' => KitchenRequisition::STATUS_REJECTED,
            'rejected_by' => (int) $user->id,
            'rejected_at' => now(),
            'rejection_note' => $note,
        ]);

        return $requisition->fresh();
    }

    // -----------------------------------------------------------------
    // Issue (the only step that moves stock)
    // -----------------------------------------------------------------

    /**
     * Issue stock for an approved (or partially issued) requisition.
     * $issuedQuantities is [requisition_line_id => quantity].
     *
     * Each line is issued at most its remaining shortage, and at most
     * what the source warehouse actually holds. The total issued must
     * be greater than zero. A line may be issued across several
     * partial issues; the requisition becomes ISSUED only when every
     * line is fully issued.
     */
    public function issue(KitchenRequisition $requisition, User $user, array $issuedQuantities): KitchenRequisition
    {
        if (! $requisition->isApproved() && ! $requisition->isPartiallyIssued()) {
            throw new InvalidArgumentException(__('Only an approved requisition can be issued.'));
        }

        $creatorId = (int) $requisition->created_by;
        $batchId = $requisition->lines()->count() > 1 ? (string) Str::uuid() : null;

        return DB::transaction(function () use ($requisition, $user, $issuedQuantities, $creatorId, $batchId) {
            $totalIssued = 0.0;

            foreach ($requisition->lines as $line) {
                $quantity = (float) ($issuedQuantities[$line->id] ?? 0);
                if ($quantity <= 0) {
                    continue;
                }

                $remaining = $line->remainingQuantity();
                if ($quantity > $remaining) {
                    throw new InvalidArgumentException(__(
                        'Cannot issue :qty of :ingredient: only :remaining is still requested.',
                        ['qty' => $quantity, 'ingredient' => $line->ingredient->name ?? '#'.$line->ingredient_product_id, 'remaining' => $remaining],
                    ));
                }

                $sourceAvailable = $this->orders->warehouseQty(
                    (int) $line->ingredient_product_id,
                    (int) $requisition->source_warehouse_id,
                    $creatorId,
                );
                if ($quantity > $sourceAvailable) {
                    throw new InvalidArgumentException(__(
                        'Cannot issue :qty of :ingredient: the source warehouse only holds :available.',
                        ['qty' => $quantity, 'ingredient' => $line->ingredient->name ?? '#'.$line->ingredient_product_id, 'available' => $sourceAvailable],
                    ));
                }

                $this->transferLine($requisition, $line, $quantity, $creatorId, $batchId);
                $line->issued_quantity = round((float) $line->issued_quantity + $quantity, 4);
                $line->save();
                $totalIssued += $quantity;
            }

            if ($totalIssued <= 0) {
                throw new InvalidArgumentException(__('Issue at least one quantity before confirming.'));
            }

            $fullyIssued = $requisition->lines()->get()->every(
                fn ($line) => $line->remainingQuantity() <= 0
            );

            $requisition->update([
                'status' => $fullyIssued
                    ? KitchenRequisition::STATUS_ISSUED
                    : KitchenRequisition::STATUS_PARTIALLY_ISSUED,
                'issued_by' => (int) $user->id,
                'issued_at' => now(),
            ]);

            return $requisition->fresh('lines');
        });
    }

    /** Move one line's quantity from the source warehouse to the kitchen warehouse. */
    protected function transferLine(KitchenRequisition $requisition, KitchenRequisitionLine $line, float $quantity, int $creatorId, ?string $batchId): void
    {
        $productId = (int) $line->ingredient_product_id;
        $fromId = (int) $requisition->source_warehouse_id;
        $toId = (int) $requisition->kitchen_warehouse_id;

        $transfer = WarehouseTransfer::create([
            'transfer_batch_id' => $batchId,
            'from_warehouse' => $fromId,
            'to_warehouse' => $toId,
            'product_id' => $productId,
            'quantity' => $quantity,
            'date' => now()->format('Y-m-d'),
            'created_by' => $creatorId,
        ]);

        // Mirror Utility::warehouse_transfer_qty, with the company id
        // explicit: destination gains the quantity (row created when
        // missing), source loses it (row deleted at zero).
        $toRow = WarehouseProduct::where('warehouse_id', $toId)
            ->where('product_id', $productId)
            ->first();
        if (! $toRow) {
            $toRow = new WarehouseProduct();
            $toRow->warehouse_id = $toId;
            $toRow->product_id = $productId;
            $toRow->quantity = 0;
            $toRow->created_by = $creatorId;
        }
        $toRow->quantity = (float) $toRow->quantity + $quantity;
        $toRow->save();

        $fromRow = WarehouseProduct::where('warehouse_id', $fromId)
            ->where('product_id', $productId)
            ->first();
        if ($fromRow) {
            $fromRow->quantity = (float) $fromRow->quantity - $quantity;
            if ($fromRow->quantity <= 0) {
                $fromRow->delete();
            } else {
                $fromRow->save();
            }
        }

        LowStockNotificationService::checkProductWarehouse($productId, $fromId, $creatorId);
        LowStockNotificationService::checkProductWarehouse($productId, $toId, $creatorId);

        $description = 'Kitchen requisition '.$requisition->requisition_number;

        $this->recordStockReport($productId, $quantity, 'transfer_out', $description, (int) $transfer->id, $creatorId);
        $this->recordStockReport($productId, $quantity, 'transfer_in', $description, (int) $transfer->id, $creatorId);
    }

    /** Same row shape as Utility::addProductStock, company id explicit. */
    protected function recordStockReport(int $productId, float $quantity, string $type, string $description, int $typeId, int $creatorId): void
    {
        // created_by is NOT in StockReport::$fillable, so it must be
        // assigned as a property — passing it to create() would
        // silently drop it to 0.
        $report = new StockReport();
        $report->product_id = $productId;
        $report->quantity = $quantity;
        $report->type = $type;
        $report->type_id = $typeId;
        $report->warehouse_loss_id = null;
        $report->description = $description;
        $report->created_by = $creatorId;
        $report->save();
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    protected function validateWarehouses(array $data, int $creatorId): void
    {
        $sourceId = (int) ($data['source_warehouse_id'] ?? 0);
        $kitchenId = (int) ($data['kitchen_warehouse_id'] ?? 0);

        if ($sourceId <= 0 || ! warehouse::where('id', $sourceId)->where('created_by', $creatorId)->exists()) {
            throw new InvalidArgumentException(__('The source warehouse is required.'));
        }

        if ($kitchenId <= 0 || ! warehouse::where('id', $kitchenId)->where('created_by', $creatorId)->exists()) {
            throw new InvalidArgumentException(__('The kitchen warehouse is required.'));
        }

        if ($sourceId === $kitchenId) {
            throw new InvalidArgumentException(__('The source warehouse and the kitchen warehouse must be different.'));
        }
    }

    /** @return array<int, array{ingredient_product_id: int, required_quantity: float, available_quantity: float, shortage_quantity: float, unit_id: int|null, unit_cost: float}> */
    protected function validateLines(array $lines, int $kitchenWarehouseId, int $creatorId): array
    {
        if ($lines === []) {
            throw new InvalidArgumentException(__('A requisition needs at least one ingredient.'));
        }

        $validated = [];
        foreach ($lines as $line) {
            $ingredientId = (int) ($line['ingredient_product_id'] ?? 0);
            $product = ProductService::where('id', $ingredientId)
                ->where('created_by', $creatorId)
                ->first();

            if (! $product) {
                throw new InvalidArgumentException(__('One of the selected ingredients does not exist.'));
            }

            $required = (float) ($line['required_quantity'] ?? 0);
            if ($required <= 0) {
                throw new InvalidArgumentException(__('Requested quantities must be greater than zero.'));
            }

            $unitId = isset($line['unit_id']) && $line['unit_id'] !== ''
                ? (int) $line['unit_id']
                : $product->unit_id;
            if ($unitId !== null && ! ProductServiceUnit::where('id', $unitId)
                ->where('created_by', $creatorId)->exists()) {
                throw new InvalidArgumentException(__('One of the selected units does not exist.'));
            }

            $available = $this->orders->warehouseQty($ingredientId, $kitchenWarehouseId, $creatorId);

            $validated[] = [
                'ingredient_product_id' => $ingredientId,
                'required_quantity' => $required,
                'available_quantity' => $available,
                'shortage_quantity' => $product->allowsNegativeStock()
                    ? 0.0
                    : max(0.0, round($required - $available, 4)),
                'unit_id' => $unitId,
                'unit_cost' => isset($line['unit_cost']) ? (float) $line['unit_cost'] : (float) ($product->purchase_price ?? 0),
            ];
        }

        return $validated;
    }

    protected function assertSameCompany($record, User $user): void
    {
        if ((int) $record->created_by !== (int) $user->creatorId()) {
            throw new InvalidArgumentException(__('This record belongs to another company.'));
        }
    }

    public function nextRequisitionNumber(int $creatorId): string
    {
        $year = now()->format('Y');
        $prefix = 'RQ-'.$year.'-';

        $count = KitchenRequisition::where('created_by', $creatorId)
            ->where('requisition_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->count();

        return $prefix.str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }
}
