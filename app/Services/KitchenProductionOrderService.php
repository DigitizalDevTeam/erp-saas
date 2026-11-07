<?php

namespace App\Services;

use App\Exceptions\KitchenConversionMissingException;
use App\Models\KitchenProductionBatch;
use App\Models\KitchenProductionOrder;
use App\Models\KitchenProductionOrderLine;
use App\Models\KitchenProductionOrderLotUsage;
use App\Models\KitchenRecipe;
use App\Models\ProductExpiryLot;
use App\Models\ProductService;
use App\Models\StockReport;
use App\Models\User;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Kitchen Production — Production Orders (Phase 1, plan §4–§6).
 *
 *   DRAFT -> APPROVED -> IN PREPARATION -> COMPLETED -> VALIDATED
 *
 * Rules (enforced here, not only in the blade):
 * - Only an APPROVED recipe version can be used in an order, and the
 *   order locks the recipe version it started with.
 * - The order number (KP-{YEAR}-{SEQ}, per company, sequential, never
 *   reused) is allocated on approval; drafts hold no number.
 * - No self-approval: the user who created the order cannot approve it.
 * - No self-validation: the user who completed the order cannot validate it.
 * - On completion the ACTUAL ingredients are deducted ONCE (in stock
 *   units, FEFO when the ingredient is lot-tracked), the preparation is
 *   received into kitchen stock as ONE batch with an expiry date, and
 *   the actual cost is snapshotted — later price changes never rewrite
 *   a completed order. A completed order cannot be edited or completed
 *   twice (stock_deducted_at is the idempotency flag).
 * - Completion is BLOCKED when the kitchen warehouse does not hold
 *   enough of an ingredient (shortage named in the error) — stock is
 *   never silently clamped to zero on a production order.
 *
 * Stock movements mirror the POS deduction path (PosRecipeDeductionService
 * / BatchLotDeductionService): the product's global quantity, the
 * warehouse quantity and the stock_reports ledger are all updated, and
 * the low-stock bell is re-evaluated. The only difference from Utility::
 * warehouse_quantity / addProductStock is that this service takes the
 * company id explicitly instead of reading Auth, so it stays unit-testable.
 */
class KitchenProductionOrderService
{
    public function __construct(
        protected KitchenUnitConversionService $conversions,
        protected KitchenRecipeCostService $costs,
    ) {
    }

    /**
     * Scale a recipe to a planned quantity and build the planned lines.
     *
     * @return array{scale: float, portions: float|null, lines: array<int, array{ingredient_product_id: int, planned_quantity: float, planned_unit_id: int|null, planned_unit_cost: float, planned_cost: float, loss_percent: float}>, planned_cost: float}
     *
     * @throws InvalidArgumentException|KitchenConversionMissingException
     */
    public function planLines(KitchenRecipe $recipe, float $plannedQuantity, ?int $plannedUnitId): array
    {
        if (! $recipe->isApproved()) {
            throw new InvalidArgumentException(__('Only an approved recipe can be used in a production order.'));
        }

        if ($plannedQuantity <= 0) {
            throw new InvalidArgumentException(__('Planned quantity must be greater than zero.'));
        }

        $scale = $this->scaleFactor($recipe, $plannedQuantity, $plannedUnitId);
        $creatorId = (int) $recipe->created_by;
        $plannedCost = 0.0;
        $lines = [];

        foreach ($recipe->lines()->with('ingredientProduct')->get() as $line) {
            $ingredient = $line->ingredientProduct;
            if (! $ingredient instanceof ProductService) {
                continue;
            }

            // Line quantity is for one recipe batch; scale it to the
            // planned quantity, then gross up by the line's cleaning /
            // preparation loss % (what is lost in preparation must be
            // taken from stock as well).
            $quantity = (float) $line->quantity * $scale;
            $lossPercent = max(0, min(99.99, (float) ($line->loss_percent ?? 0)));
            if ($lossPercent > 0) {
                $quantity = $quantity / (1 - ($lossPercent / 100));
            }

            $quantityInStockUnit = $this->conversions->convertToStockUnit(
                $quantity,
                $line->unit_id ? (int) $line->unit_id : null,
                $ingredient,
                $creatorId
            );

            $unitCost = (float) ($ingredient->purchase_price ?? 0);
            $lineCost = round($quantityInStockUnit * $unitCost, 2);
            $plannedCost += $lineCost;

            $lines[] = [
                'ingredient_product_id' => (int) $ingredient->id,
                'planned_quantity' => round($quantityInStockUnit, 4),
                'planned_unit_id' => $ingredient->unit_id ? (int) $ingredient->unit_id : null,
                'planned_unit_cost' => $unitCost,
                'planned_cost' => $lineCost,
                'loss_percent' => $lossPercent,
            ];
        }

        if ($lines === []) {
            throw new InvalidArgumentException(__('The recipe has no ingredients to plan from.'));
        }

        return [
            'scale' => round($scale, 4),
            'portions' => $this->costs->portions($recipe),
            'lines' => $lines,
            'planned_cost' => round($plannedCost, 2),
        ];
    }

    /**
     * Create a DRAFT order with its planned lines (no number yet).
     *
     * @param array{kitchen_recipe_id: int, production_date: string, section: string|null, branch_id: int|null, warehouse_id: int, planned_quantity: float, planned_unit_id: int|null, responsible_user_id: int|null, notes: string|null, cost_centre_id: int|null} $data
     */
    public function create(array $data, User $user): KitchenProductionOrder
    {
        $creatorId = (int) $user->creatorId();
        $recipe = KitchenRecipe::where('id', (int) $data['kitchen_recipe_id'])
            ->where('created_by', $creatorId)
            ->first();
        if (! $recipe) {
            throw new InvalidArgumentException(__('Recipe not found for this company.'));
        }
        $this->assertSameCompany($recipe, $user);

        $warehouse = warehouse::where('id', (int) $data['warehouse_id'])
            ->where('created_by', $creatorId)
            ->first();
        if (! $warehouse) {
            throw new InvalidArgumentException(__('Warehouse not found for this company.'));
        }

        $planned = $this->planLines($recipe, (float) $data['planned_quantity'], isset($data['planned_unit_id']) ? (int) $data['planned_unit_id'] : null);

        return DB::transaction(function () use ($data, $user, $creatorId, $recipe, $planned) {
            $order = KitchenProductionOrder::create([
                'kitchen_recipe_id' => (int) $recipe->id,
                // Phase 3: the plan this order was generated from (null
                // for orders created directly from the Orders screen).
                'kitchen_production_plan_id' => ! empty($data['kitchen_production_plan_id']) ? (int) $data['kitchen_production_plan_id'] : null,
                'recipe_version' => (int) $recipe->version,
                'production_date' => $data['production_date'],
                'section' => $data['section'] ?? null,
                'branch_id' => ! empty($data['branch_id']) ? (int) $data['branch_id'] : null,
                'warehouse_id' => (int) $data['warehouse_id'],
                'planned_quantity' => (float) $data['planned_quantity'],
                'planned_unit_id' => isset($data['planned_unit_id']) ? (int) $data['planned_unit_id'] : null,
                'status' => KitchenProductionOrder::STATUS_DRAFT,
                'responsible_user_id' => ! empty($data['responsible_user_id']) ? (int) $data['responsible_user_id'] : null,
                'notes' => $data['notes'] ?? null,
                'planned_cost' => $planned['planned_cost'],
                'cost_centre_id' => ! empty($data['cost_centre_id']) ? (int) $data['cost_centre_id'] : null,
                // The creating user is the "submitter": they cannot
                // approve their own order (same rule as recipes).
                'submitted_by' => (int) $user->id,
                'created_by' => $creatorId,
            ]);

            $this->syncPlannedLines($order, $planned['lines'], $creatorId);

            return $order->fresh();
        });
    }

    /**
     * Update a DRAFT order. Changing the recipe or the planned quantity
     * re-plans the lines from the (new) recipe; anything else only
     * updates the header.
     */
    public function updateDraft(KitchenProductionOrder $order, array $data, User $user): KitchenProductionOrder
    {
        $this->assertSameCompany($order, $user);

        if (! $order->isDraft()) {
            throw new InvalidArgumentException(__('Only a draft production order can be edited.'));
        }

        $creatorId = (int) $user->creatorId();
        $recipeId = (int) ($data['kitchen_recipe_id'] ?? $order->kitchen_recipe_id);
        $recipe = KitchenRecipe::where('id', $recipeId)
            ->where('created_by', $creatorId)
            ->first();
        if (! $recipe) {
            throw new InvalidArgumentException(__('Recipe not found for this company.'));
        }
        $this->assertSameCompany($recipe, $user);

        $warehouse = warehouse::where('id', (int) ($data['warehouse_id'] ?? $order->warehouse_id))
            ->where('created_by', $creatorId)
            ->first();
        if (! $warehouse) {
            throw new InvalidArgumentException(__('Warehouse not found for this company.'));
        }

        $plannedQuantity = (float) ($data['planned_quantity'] ?? $order->planned_quantity);
        $plannedUnitId = isset($data['planned_unit_id']) ? (int) $data['planned_unit_id'] : ($order->planned_unit_id ? (int) $order->planned_unit_id : null);
        $replan = $recipeId !== (int) $order->kitchen_recipe_id
            || $plannedQuantity !== (float) $order->planned_quantity
            || $plannedUnitId !== ($order->planned_unit_id ? (int) $order->planned_unit_id : null);

        return DB::transaction(function () use ($order, $data, $creatorId, $recipe, $plannedQuantity, $plannedUnitId, $replan) {
            $order->fill([
                'kitchen_recipe_id' => (int) $recipe->id,
                'recipe_version' => (int) $recipe->version,
                'production_date' => $data['production_date'] ?? $order->production_date,
                'section' => $data['section'] ?? $order->section,
                'branch_id' => array_key_exists('branch_id', $data) ? ($data['branch_id'] ? (int) $data['branch_id'] : null) : $order->branch_id,
                'warehouse_id' => (int) ($data['warehouse_id'] ?? $order->warehouse_id),
                'planned_quantity' => $plannedQuantity,
                'planned_unit_id' => $plannedUnitId,
                'responsible_user_id' => array_key_exists('responsible_user_id', $data) ? ($data['responsible_user_id'] ? (int) $data['responsible_user_id'] : null) : $order->responsible_user_id,
                'notes' => $data['notes'] ?? $order->notes,
                'cost_centre_id' => array_key_exists('cost_centre_id', $data) ? ($data['cost_centre_id'] ? (int) $data['cost_centre_id'] : null) : $order->cost_centre_id,
            ]);

            if ($replan) {
                $planned = $this->planLines($recipe, $plannedQuantity, $plannedUnitId);
                $order->planned_cost = $planned['planned_cost'];
                $order->save();
                $this->syncPlannedLines($order, $planned['lines'], $creatorId);
            } else {
                $order->save();
            }

            return $order->fresh();
        });
    }

    /** Approve a DRAFT order and allocate its number (KP-{YEAR}-{SEQ}). */
    public function approve(KitchenProductionOrder $order, User $user): KitchenProductionOrder
    {
        $this->assertSameCompany($order, $user);

        if (! $order->isDraft()) {
            throw new InvalidArgumentException(__('Only a draft production order can be approved.'));
        }

        if ((int) $order->submitted_by === (int) $user->id) {
            throw new InvalidArgumentException(__('You cannot approve a production order you created yourself.'));
        }

        return DB::transaction(function () use ($order, $user) {
            $order->order_number = $this->nextOrderNumber((int) $order->created_by);
            $order->status = KitchenProductionOrder::STATUS_APPROVED;
            $order->approved_by = (int) $user->id;
            $order->approved_at = now();
            $order->save();

            return $order->fresh();
        });
    }

    /** Move an APPROVED order into preparation (the cook starts cooking). */
    public function start(KitchenProductionOrder $order, User $user): KitchenProductionOrder
    {
        $this->assertSameCompany($order, $user);

        if (! $order->isApproved()) {
            throw new InvalidArgumentException(__('Only an approved production order can be started.'));
        }

        $order->status = KitchenProductionOrder::STATUS_IN_PREPARATION;
        $order->started_by = (int) $user->id;
        $order->started_at = now();
        $order->save();

        return $order->fresh();
    }

    /**
     * Complete an IN PREPARATION order: deduct the actual ingredients
     * once, receive the preparation as one batch with an expiry date,
     * and snapshot the actual cost.
     *
     * @param array<int, float> $actuals order-line id => actual quantity (stock units); lines not given default to planned
     */
    public function complete(
        KitchenProductionOrder $order,
        User $user,
        array $actuals,
        float $actualYieldQuantity,
        ?int $actualYieldUnitId,
        string $expiryDate,
        ?string $notes = null,
    ): KitchenProductionOrder {
        $this->assertSameCompany($order, $user);

        if (! $order->isInPreparation()) {
            throw new InvalidArgumentException(__('Only a production order in preparation can be completed.'));
        }

        if ($order->stock_deducted_at !== null) {
            throw new InvalidArgumentException(__('This production order was already completed. Ingredients were deducted once and cannot be deducted again.'));
        }

        if ($actualYieldQuantity <= 0) {
            throw new InvalidArgumentException(__('Actual yield must be greater than zero.'));
        }

        if (strtotime($expiryDate) === false || $expiryDate < date('Y-m-d')) {
            throw new InvalidArgumentException(__('Batch expiry date must be today or later.'));
        }

        $shortages = $this->checkAvailability($order, $actuals);
        if ($shortages !== []) {
            $first = $shortages[0];
            throw new InvalidArgumentException(__('Not enough :ingredient in the kitchen warehouse: need :need, have :have. Move stock first or lower the actual quantity.', [
                'ingredient' => $first['ingredient_name'],
                'need' => $first['needed'],
                'have' => $first['available'],
            ]));
        }

        return DB::transaction(function () use ($order, $user, $actuals, $actualYieldQuantity, $actualYieldUnitId, $expiryDate, $notes) {
            // Re-lock and re-check inside the transaction: a second
            // completion racing this one must fail, not deduct twice.
            $locked = KitchenProductionOrder::where('id', $order->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isInPreparation() || $locked->stock_deducted_at !== null) {
                throw new InvalidArgumentException(__('This production order was already completed. Ingredients were deducted once and cannot be deducted again.'));
            }

            $creatorId = (int) $order->created_by;
            $warehouseId = (int) $order->warehouse_id;
            $recipe = $order->recipe;
            $actualCost = 0.0;

            foreach ($order->lines()->with('ingredient')->get() as $line) {
                $ingredient = $line->ingredient;
                if (! $ingredient instanceof ProductService) {
                    continue;
                }

                $actualQty = isset($actuals[(int) $line->id]) ? (float) $actuals[(int) $line->id] : (float) $line->planned_quantity;
                if ($actualQty < 0) {
                    throw new InvalidArgumentException(__('Actual quantity cannot be negative.'));
                }

                $actualUnitCost = (float) ($ingredient->purchase_price ?? 0);
                $lineActualCost = round($actualQty * $actualUnitCost, 2);
                $actualCost += $lineActualCost;

                if ($actualQty > 0) {
                    $this->deductIngredient($line, $ingredient, $actualQty, $order, $creatorId, $warehouseId);
                }

                $line->actual_quantity = round($actualQty, 4);
                $line->actual_unit_cost = $actualUnitCost;
                $line->actual_cost = $lineActualCost;
                $line->save();
            }

            $actualCost = round($actualCost, 2);

            // Receive the preparation into kitchen stock as one batch.
            $dish = $recipe ? $recipe->product : null;
            $producedQty = $this->producedQuantityInDishUnit($order, $recipe, $actualYieldQuantity, $actualYieldUnitId, $creatorId);
            $dishUnitId = null;
            if ($dish && $dish->unit_id) {
                $dishUnitId = (int) $dish->unit_id;
            } elseif ($actualYieldUnitId) {
                $dishUnitId = (int) $actualYieldUnitId;
            } elseif ($order->planned_unit_id) {
                $dishUnitId = (int) $order->planned_unit_id;
            }

            $batch = KitchenProductionBatch::create([
                'batch_number' => $order->order_number.'-B1',
                'kitchen_production_order_id' => (int) $order->id,
                'product_id' => (int) $order->recipe->product_id,
                'quantity_produced' => round($producedQty, 4),
                'quantity_remaining' => round($producedQty, 4),
                'unit_id' => $dishUnitId,
                'warehouse_id' => $warehouseId,
                'expiry_date' => $expiryDate,
                'unit_cost' => $producedQty > 0 ? round($actualCost / $producedQty, 4) : null,
                'total_cost' => $actualCost,
                'created_by' => $creatorId,
            ]);

            $this->receiveProduced((int) $order->recipe->product_id, $producedQty, $order, $creatorId, $warehouseId);

            if ($dish && ($dish->batch_tracking ?? false)) {
                ProductExpiryLot::create([
                    'product_id' => (int) $dish->id,
                    'warehouse_id' => $warehouseId,
                    'purchase_id' => null,
                    'purchase_product_id' => null,
                    'source' => 'kitchen_production',
                    'batch_no' => $batch->batch_number,
                    'mfg_date' => date('Y-m-d'),
                    'expiry_date' => $expiryDate,
                    'quantity_in' => round($producedQty, 4),
                    'quantity_remaining' => round($producedQty, 4),
                    'created_by' => $creatorId,
                ]);
            }

            // Cost snapshot: cost per portion + food cost % + band.
            $costPerPortion = null;
            $foodCostPercent = null;
            $band = null;
            $portions = $this->actualPortions($order, $recipe, $actualYieldQuantity, $actualYieldUnitId, $creatorId);
            if ($portions !== null && $portions > 0) {
                $costPerPortion = round($actualCost / $portions, 2);
                $sellingPriceExVat = $dish instanceof ProductService
                    ? (float) $dish->exclusiveUnitPrice((float) ($dish->sale_price ?? 0))
                    : 0.0;
                if ($sellingPriceExVat > 0) {
                    $foodCostPercent = round(($costPerPortion / $sellingPriceExVat) * 100, 2);
                    $band = KitchenRecipeCostService::bandForFoodCostPercent($foodCostPercent);
                }
            }

            $order->actual_yield_quantity = round($actualYieldQuantity, 4);
            $order->actual_yield_unit_id = $actualYieldUnitId ?? $order->planned_unit_id;
            $order->batch_expiry_date = $expiryDate;
            if ($notes !== null) {
                $order->notes = $notes;
            }
            $order->actual_cost = $actualCost;
            $order->cost_per_portion = $costPerPortion;
            $order->food_cost_percent = $foodCostPercent;
            $order->food_cost_band = $band;
            $order->status = KitchenProductionOrder::STATUS_COMPLETED;
            $order->stock_deducted_at = now();
            $order->stock_received_at = now();
            $order->completed_by = (int) $user->id;
            $order->completed_at = now();
            $order->save();

            return $order->fresh();
        });
    }

    /** Validate a COMPLETED order (final step; not the completer). */
    public function validateOrder(KitchenProductionOrder $order, User $user): KitchenProductionOrder
    {
        $this->assertSameCompany($order, $user);

        if (! $order->isCompleted()) {
            throw new InvalidArgumentException(__('Only a completed production order can be validated.'));
        }

        if ((int) $order->completed_by === (int) $user->id) {
            throw new InvalidArgumentException(__('You cannot validate a production order you completed yourself.'));
        }

        $order->status = KitchenProductionOrder::STATUS_VALIDATED;
        $order->validated_by = (int) $user->id;
        $order->validated_at = now();
        $order->save();

        return $order->fresh();
    }

    /**
     * Shortages for the completion form: which actual quantities the
     * kitchen warehouse cannot cover. Empty array = everything is covered.
     *
     * @param array<int, float> $actuals order-line id => actual quantity
     * @return array<int, array{line_id: int, ingredient_id: int, ingredient_name: string, needed: float, available: float}>
     */
    public function checkAvailability(KitchenProductionOrder $order, array $actuals): array
    {
        $creatorId = (int) $order->created_by;
        $warehouseId = (int) $order->warehouse_id;
        $shortages = [];

        foreach ($order->lines()->with('ingredient')->get() as $line) {
            $ingredient = $line->ingredient;
            if (! $ingredient instanceof ProductService) {
                continue;
            }

            $needed = isset($actuals[(int) $line->id]) ? (float) $actuals[(int) $line->id] : (float) $line->planned_quantity;
            if ($needed <= 0) {
                continue;
            }

            // Products allowed to go negative are never blocked.
            if ($ingredient->allowsNegativeStock()) {
                continue;
            }

            $available = ($ingredient->batch_tracking ?? false) || BatchLotDeductionService::hasLots((int) $ingredient->id, $warehouseId, $creatorId)
                ? BatchLotDeductionService::availableQty((int) $ingredient->id, $warehouseId, $creatorId)
                : $this->warehouseQty((int) $ingredient->id, $warehouseId, $creatorId);

            if ($needed > $available) {
                $shortages[] = [
                    'line_id' => (int) $line->id,
                    'ingredient_id' => (int) $ingredient->id,
                    'ingredient_name' => (string) $ingredient->name,
                    'needed' => round($needed, 4),
                    'available' => round($available, 4),
                ];
            }
        }

        return $shortages;
    }

    /** Next per-company order number: KP-{YEAR}-{SEQ}, never reused. */
    public function nextOrderNumber(int $creatorId): string
    {
        $year = date('Y');
        $count = KitchenProductionOrder::where('created_by', $creatorId)
            ->whereNotNull('order_number')
            ->lockForUpdate()
            ->count();

        return sprintf('KP-%s-%04d', $year, $count + 1);
    }

    public function warehouseQty(int $productId, int $warehouseId, int $creatorId): float
    {
        return (float) WarehouseProduct::where('created_by', $creatorId)
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->sum('quantity');
    }

    /**
     * Scale factor: how many recipe batches the planned quantity is.
     * Planned in portions: planned / portions per batch. Planned in any
     * other unit: planned converted to the yield unit / expected yield.
     */
    protected function scaleFactor(KitchenRecipe $recipe, float $plannedQuantity, ?int $plannedUnitId): float
    {
        $yield = (float) ($recipe->expected_yield_quantity ?? 0);
        $yieldUnitId = (int) ($recipe->expected_yield_unit_id ?? 0);
        $portion = (float) ($recipe->standard_portion_quantity ?? 0);
        $portionUnitId = (int) ($recipe->standard_portion_unit_id ?? 0);

        if ($yield <= 0 || $yieldUnitId <= 0 || $portion <= 0 || $portionUnitId <= 0) {
            throw new InvalidArgumentException(__('The recipe needs an expected yield and a standard portion before a production order can be planned.'));
        }

        $portionsPerBatch = $this->costs->portions($recipe);

        if ($plannedUnitId !== null && $plannedUnitId === $portionUnitId && $portionsPerBatch !== null && $portionsPerBatch > 0) {
            return $plannedQuantity / $portionsPerBatch;
        }

        $plannedInYieldUnit = $plannedUnitId !== null && $plannedUnitId !== $yieldUnitId
            ? $this->conversions->convert($plannedQuantity, $plannedUnitId, $yieldUnitId, (int) $recipe->created_by)
            : $plannedQuantity;

        return $plannedInYieldUnit / $yield;
    }

    protected function syncPlannedLines(KitchenProductionOrder $order, array $lines, int $creatorId): void
    {
        KitchenProductionOrderLine::where('kitchen_production_order_id', (int) $order->id)->delete();

        foreach ($lines as $line) {
            KitchenProductionOrderLine::create(array_merge($line, [
                'kitchen_production_order_id' => (int) $order->id,
                'created_by' => $creatorId,
            ]));
        }
    }

    /**
     * Deduct one ingredient for a completed order: FEFO lots first when
     * the ingredient is lot-tracked (usage recorded per lot for
     * traceability), then the aggregate quantities + stock ledger —
     * the same composition as BatchLotDeductionService::deductForSale.
     */
    protected function deductIngredient(
        KitchenProductionOrderLine $line,
        ProductService $ingredient,
        float $quantity,
        KitchenProductionOrder $order,
        int $creatorId,
        int $warehouseId,
    ): void {
        $ingredientId = (int) $ingredient->id;

        if (($ingredient->batch_tracking ?? false) || BatchLotDeductionService::hasLots($ingredientId, $warehouseId, $creatorId)) {
            $remaining = $this->deductLotsFefo($line, $ingredient, $quantity, $creatorId, $warehouseId);
            if ($remaining > 0) {
                throw new InvalidArgumentException(__('Not enough :ingredient in the kitchen warehouse lots: short by :short.', [
                    'ingredient' => (string) $ingredient->name,
                    'short' => round($remaining, 4),
                ]));
            }
        }

        // Global product quantity (clamped at zero unless the product
        // allows negative stock — same rule as the POS deduction).
        $original = (float) ($ingredient->quantity ?? 0);
        $newQty = $ingredient->allowsNegativeStock()
            ? $original - $quantity
            : max(0, $original - $quantity);
        ProductService::where('id', $ingredientId)->update(['quantity' => $newQty]);

        $this->adjustWarehouseStock($ingredientId, $warehouseId, $creatorId, -$quantity, $ingredient->allowsNegativeStock());

        $this->recordStockReport(
            $ingredientId,
            $quantity,
            'kitchen_production',
            $quantity.' '.__('used in kitchen production').' '.$order->order_number,
            (int) $order->id,
            $creatorId
        );
    }

    /**
     * Receive the produced preparation into kitchen stock: global
     * quantity, warehouse quantity and the stock ledger.
     */
    protected function receiveProduced(int $productId, float $quantity, KitchenProductionOrder $order, int $creatorId, int $warehouseId): void
    {
        $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
        if (! $product) {
            return;
        }

        $original = (float) ($product->quantity ?? 0);
        ProductService::where('id', $productId)->update(['quantity' => $original + $quantity]);

        $this->adjustWarehouseStock($productId, $warehouseId, $creatorId, $quantity, true);

        $this->recordStockReport(
            $productId,
            $quantity,
            'kitchen_production',
            $quantity.' '.__('produced in kitchen production').' '.$order->order_number,
            (int) $order->id,
            $creatorId
        );
    }

    /**
     * FEFO lot walk (same ordering as BatchLotDeductionService):
     * earliest expiry first, lots without expiry last. Each lot taken
     * is recorded for traceability. Returns the quantity NOT covered
     * by lots.
     */
    protected function deductLotsFefo(
        KitchenProductionOrderLine $line,
        ProductService $ingredient,
        float $quantity,
        int $creatorId,
        int $warehouseId,
    ): float {
        $method = BatchLotDeductionService::methodForProduct($ingredient);
        $today = date('Y-m-d');

        $query = DB::table('product_expiry_lots')
            ->where('created_by', $creatorId)
            ->where('product_id', (int) $ingredient->id)
            ->where('quantity_remaining', '>', 0)
            ->where(function ($q) use ($today) {
                $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today);
            });

        if ($warehouseId > 0) {
            $query->where(function ($q) use ($warehouseId) {
                $q->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId);
            });
        }

        if ($method === BatchLotDeductionService::METHOD_FEFO) {
            $lots = (clone $query)->whereNotNull('expiry_date')->orderBy('expiry_date')->orderBy('id')->lockForUpdate()->get()
                ->concat((clone $query)->whereNull('expiry_date')->orderBy('id')->lockForUpdate()->get());
        } elseif ($method === BatchLotDeductionService::METHOD_FIFO) {
            $lots = $query->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
        } else {
            $lots = $query->orderByDesc('created_at')->orderByDesc('id')->lockForUpdate()->get();
        }

        $remaining = $quantity;
        foreach ($lots as $lot) {
            if ($remaining <= 0) {
                break;
            }
            $take = min((float) $lot->quantity_remaining, $remaining);
            DB::table('product_expiry_lots')->where('id', $lot->id)->update([
                'quantity_remaining' => max(0, (float) $lot->quantity_remaining - $take),
                'updated_at' => now(),
            ]);
            KitchenProductionOrderLotUsage::create([
                'kitchen_production_order_line_id' => (int) $line->id,
                'product_expiry_lot_id' => (int) $lot->id,
                'quantity' => round($take, 4),
                'created_by' => $creatorId,
            ]);
            $remaining -= $take;
        }

        return max(0, round($remaining, 4));
    }

    /**
     * Adjust one warehouse row by a delta (mirror of
     * Utility::warehouse_quantity, with the company id explicit):
     * creates the row when missing, clamps at zero unless negative
     * stock is allowed, and re-evaluates the low-stock bell.
     */
    protected function adjustWarehouseStock(int $productId, int $warehouseId, int $creatorId, float $delta, bool $allowNegative): void
    {
        $row = WarehouseProduct::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->whereNull('product_variant_id')
            ->first();

        if (! $row) {
            $row = new WarehouseProduct();
            $row->warehouse_id = $warehouseId;
            $row->product_id = $productId;
            $row->quantity = 0;
            $row->created_by = $creatorId;
        }

        $newQty = (float) ($row->quantity ?? 0) + $delta;
        $row->quantity = $allowNegative ? $newQty : max(0, $newQty);
        $row->save();

        LowStockNotificationService::checkProductWarehouse($productId, $warehouseId, $creatorId);
    }

    /** Same row shape as Utility::addProductStock, company id explicit. */
    protected function recordStockReport(int $productId, float $quantity, string $type, string $description, int $typeId, int $creatorId): void
    {
        // created_by is NOT in StockReport::$fillable, so it must be
        // assigned as a property (like Utility::addProductStock does) —
        // passing it to create() would silently drop it to 0.
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

    /**
     * Actual yield converted to the dish's stock unit (the unit the
     * batch is received in). Falls back to the yield unit as-is when
     * the dish has no unit.
     */
    protected function producedQuantityInDishUnit(
        KitchenProductionOrder $order,
        ?KitchenRecipe $recipe,
        float $actualYieldQuantity,
        ?int $actualYieldUnitId,
        int $creatorId,
    ): float {
        $dish = $recipe ? $recipe->product : null;
        $dishUnitId = $dish && $dish->unit_id ? (int) $dish->unit_id : null;
        $fromUnitId = $actualYieldUnitId;
        if ($fromUnitId === null && $order->planned_unit_id) {
            $fromUnitId = (int) $order->planned_unit_id;
        }

        if ($dishUnitId === null || $dishUnitId <= 0 || $fromUnitId === null || $fromUnitId <= 0 || $fromUnitId === $dishUnitId) {
            return round($actualYieldQuantity, 4);
        }

        return $this->conversions->convert($actualYieldQuantity, $fromUnitId, $dishUnitId, $creatorId);
    }

    /** Actual portions produced: actual yield / standard portion. */
    protected function actualPortions(
        KitchenProductionOrder $order,
        ?KitchenRecipe $recipe,
        float $actualYieldQuantity,
        ?int $actualYieldUnitId,
        int $creatorId,
    ): ?float {
        if (! $recipe) {
            return null;
        }

        $portion = (float) ($recipe->standard_portion_quantity ?? 0);
        $portionUnitId = (int) ($recipe->standard_portion_unit_id ?? 0);
        if ($portion <= 0 || $portionUnitId <= 0) {
            return null;
        }

        $fromUnitId = $actualYieldUnitId;
        if ($fromUnitId === null && $order->planned_unit_id) {
            $fromUnitId = (int) $order->planned_unit_id;
        }
        $yield = $actualYieldQuantity;
        if ($fromUnitId !== null && $fromUnitId > 0 && $fromUnitId !== $portionUnitId) {
            $yield = $this->conversions->convert($actualYieldQuantity, $fromUnitId, $portionUnitId, $creatorId);
        }

        return $yield > 0 ? round($yield / $portion, 2) : null;
    }

    protected function assertSameCompany($record, User $user): void
    {
        if ((int) $record->created_by !== (int) $user->creatorId()) {
            throw new InvalidArgumentException(__('This production order belongs to another company.'));
        }
    }
}
