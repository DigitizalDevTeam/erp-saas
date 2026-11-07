<?php

namespace App\Services;

use App\Exceptions\KitchenConversionMissingException;
use App\Models\KitchenPosBatchConsumption;
use App\Models\KitchenProductionBatch;
use App\Models\KitchenRecipe;
use App\Models\Pos;
use App\Models\PosProduct;
use App\Models\ProductRecipe;
use App\Models\ProductService;
use App\Models\User;
use App\Models\Utility;
use App\Models\WarehouseLoss;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Kitchen Production Phase 2 — POS integration (plan §5).
 *
 * The one rule this service exists to enforce: a dish sale deducts the
 * PREPARED batch only. The raw ingredients were already deducted once,
 * at production (Phase 1); they must never be deducted a second time
 * at sale. Two cases:
 *
 * - Direct sale: the dish itself is a prepared product (it has an
 *   approved kitchen recipe and batches). Selling it consumes the
 *   batches FEFO plus the prepared product's own warehouse stock.
 *   Its recipe's raw ingredients are NOT touched.
 * - Prepared ingredient in a dish: the dish's recipe lists a prepared
 *   product (e.g. coconut rice in grilled chicken). The existing
 *   ladder still deducts that ingredient's warehouse stock; this
 *   service also consumes its batches FEFO by the same amount, so the
 *   batch record and the warehouse stock stay reconciled
 *   (produced - sold - lost = remaining).
 *
 * A shortage is shown BEFORE anything is sent to production
 * (checkShortages, wired into PosController::sendToProduction): when
 * no prepared batch covers a dish, the send is refused with the dish
 * and the batch named, instead of silently deducting raw ingredients.
 *
 * Options change the composition and the cost through the existing
 * option ladder: a selected option that consumes an alternative of a
 * recipe line SWAPS that line (the main ingredient is not deducted —
 * the alternative already came through the option), and the effective
 * recipe cost is recomputed with the alternative's price.
 *
 * Cancellation (before vs after preparation, plan §6):
 * - Before preparation (line still PENDING): everything goes back —
 *   the existing restore ladder plus the batches this line consumed.
 * - After preparation (IN_PREPARATION / READY / SERVED): nothing is
 *   restored. The prepared food is recorded as a kitchen loss
 *   (a WarehouseLoss row) with its destination — staff meal,
 *   complimentary, disposal, tasting — or, when the destination is
 *   "returned to stock" and the line sold a prepared product itself,
 *   the prepared food goes back into its batch.
 *
 * The loss row is a waste log, not a second stock movement: the stock
 * was already deducted when the line was sent to production, so the
 * row must not move stock again.
 *
 * Everything here is scoped by creatorId() (one company) and works
 * globally for all companies.
 */
class KitchenPosSaleService
{
    public const DESTINATION_STAFF_MEAL = 'staff_meal';
    public const DESTINATION_COMPLIMENTARY = 'complimentary';
    public const DESTINATION_DISPOSAL = 'disposal';
    public const DESTINATION_TASTING = 'tasting';
    public const DESTINATION_RETURNED_TO_STOCK = 'returned_to_stock';

    public const DESTINATIONS = [
        self::DESTINATION_STAFF_MEAL,
        self::DESTINATION_COMPLIMENTARY,
        self::DESTINATION_DISPOSAL,
        self::DESTINATION_TASTING,
        self::DESTINATION_RETURNED_TO_STOCK,
    ];

    public function __construct(
        protected KitchenUnitConversionService $conversions,
        protected KitchenRecipeCostService $costs,
    ) {
    }

    // -----------------------------------------------------------------
    // Prepared products and their batches
    // -----------------------------------------------------------------

    /**
     * The APPROVED kitchen recipe that produces this product, if any.
     * When the kitchen tables do not exist (a test that builds only
     * the POS schema, or an install before the kitchen migrations),
     * there is no prepared product — the legacy ladder runs untouched.
     * The check runs on every call (never memoized: PHPUnit reuses one
     * process across tests with different in-memory schemas).
     */
    public function approvedRecipeForProduct(int $productId, int $creatorId): ?KitchenRecipe
    {
        if (! Schema::hasTable('kitchen_recipes')) {
            return null;
        }

        return KitchenRecipe::where('product_id', $productId)
            ->where('created_by', $creatorId)
            ->where('status', KitchenRecipe::STATUS_APPROVED)
            ->orderByDesc('version')
            ->first();
    }

    /** A product is "prepared" when an approved kitchen recipe makes it. */
    public function isPreparedProduct(int $productId, int $creatorId): bool
    {
        return $this->approvedRecipeForProduct($productId, $creatorId) !== null;
    }

    /** Sellable prepared quantity of a product in a warehouse (non-expired batches only). */
    public function batchRemaining(int $productId, int $warehouseId, int $creatorId): float
    {
        if ($warehouseId <= 0 || $productId <= 0) {
            return 0.0;
        }

        return round((float) $this->availableBatchesQuery($productId, $warehouseId, $creatorId)->sum('quantity_remaining'), 4);
    }

    /** The unit the product's batches are held in (the dish unit at production). */
    public function batchUnitId(int $productId, int $creatorId): ?int
    {
        $unitId = KitchenProductionBatch::where('product_id', $productId)
            ->where('created_by', $creatorId)
            ->whereNotNull('unit_id')
            ->orderByDesc('id')
            ->value('unit_id');

        return $unitId !== null ? (int) $unitId : null;
    }

    /**
     * Consume prepared batches FEFO (earliest expiry first, no expiry
     * last; expired batches are never sold). Returns the quantity
     * actually consumed.
     *
     * With $allOrNothing (the default) the call is atomic: when the
     * batches do not cover the full quantity, nothing is consumed and
     * 0 is returned, so the caller can fall back to the legacy raw
     * deduction instead of mixing a partial batch with raw stock.
     * With $allOrNothing = false the call consumes whatever is
     * available (best effort; the send-time shortage check is the
     * enforcement point for that path).
     */
    public function consumeBatches(
        int $productId,
        int $warehouseId,
        int $creatorId,
        float $quantity,
        ?int $posProductId = null,
        bool $allOrNothing = true,
    ): float {
        if ($quantity <= 0 || $warehouseId <= 0 || $productId <= 0) {
            return 0.0;
        }

        return DB::transaction(function () use ($productId, $warehouseId, $creatorId, $quantity, $posProductId, $allOrNothing) {
            $batches = $this->availableBatchesQuery($productId, $warehouseId, $creatorId)
                ->orderByRaw('expiry_date IS NULL, expiry_date ASC, id ASC')
                ->lockForUpdate()
                ->get();

            $total = 0.0;
            foreach ($batches as $batch) {
                $total += (float) $batch->quantity_remaining;
            }

            if ($allOrNothing && $total < $quantity) {
                return 0.0;
            }

            $remaining = $quantity;
            $consumed = 0.0;
            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }
                $take = min((float) $batch->quantity_remaining, $remaining);
                if ($take <= 0) {
                    continue;
                }
                $batch->consume($take);
                $consumed += $take;
                $remaining -= $take;

                if ($posProductId !== null && $posProductId > 0) {
                    KitchenPosBatchConsumption::create([
                        'pos_product_id' => $posProductId,
                        'kitchen_production_batch_id' => (int) $batch->id,
                        'product_id' => $productId,
                        'quantity' => round($take, 4),
                        'quantity_restored' => 0,
                        'warehouse_id' => $warehouseId,
                        'created_by' => $creatorId,
                    ]);
                }
            }

            return round($consumed, 4);
        });
    }

    /**
     * Put a line's consumed batches back (cancellation before
     * preparation). Only the batch record is touched here — the
     * warehouse/global stock is returned by the existing restore
     * ladder, which runs alongside this call. Idempotent: a row
     * already restored is never added back twice. When the
     * consumption table does not exist, there is nothing to restore.
     */
    public function restoreBatchesOnly(PosProduct $line, int $creatorId): float
    {
        if (! Schema::hasTable('kitchen_pos_batch_consumptions')) {
            return 0.0;
        }

        $restored = 0.0;

        foreach (KitchenPosBatchConsumption::where('pos_product_id', (int) $line->id)->where('created_by', $creatorId)->get() as $row) {
            $outstanding = $row->outstanding();
            if ($outstanding <= 0) {
                continue;
            }

            $batch = KitchenProductionBatch::where('id', (int) $row->kitchen_production_batch_id)
                ->where('created_by', $creatorId)
                ->first();
            if (! $batch) {
                continue;
            }

            $batch->quantity_remaining = round((float) $batch->quantity_remaining + $outstanding, 4);
            $batch->save();

            $row->quantity_restored = round((float) $row->quantity_restored + $outstanding, 4);
            $row->save();

            $restored += $outstanding;
        }

        return round($restored, 4);
    }

    /** Total batch quantity a line consumed for one prepared product. */
    public function consumedQuantityForProduct(PosProduct $line, int $productId, int $creatorId): float
    {
        return round((float) KitchenPosBatchConsumption::where('pos_product_id', (int) $line->id)
            ->where('product_id', $productId)
            ->where('created_by', $creatorId)
            ->sum('quantity'), 4);
    }

    protected function availableBatchesQuery(int $productId, int $warehouseId, int $creatorId)
    {
        $today = date('Y-m-d');

        return KitchenProductionBatch::where('created_by', $creatorId)
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('quantity_remaining', '>', 0)
            ->where(function ($q) use ($today) {
                $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today);
            });
    }

    // -----------------------------------------------------------------
    // Deduction: batch-only sale of a prepared product
    // -----------------------------------------------------------------

    /**
     * Sell a prepared product from its batches only. Returns true when
     * the batch path handled the whole line (raw ingredients untouched);
     * false when the caller must fall back to the legacy raw deduction
     * (no approved recipe, no batches, batches short, or a
     * manager-authorized negative-stock line — the legacy ladder owns
     * those, it allows negative stock and batches never go negative).
     */
    public function deductPreparedSale(
        int $productId,
        float $lineQty,
        int $warehouseId,
        int $creatorId,
        string $posNo,
        int $posId,
        ?PosProduct $line = null,
        bool $allowNegative = false,
    ): bool {
        $recipe = $this->approvedRecipeForProduct($productId, $creatorId);
        if (! $recipe || $allowNegative) {
            return false;
        }

        $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
        if (! $product) {
            return false;
        }

        // The line quantity is in the dish's selling unit; the batches
        // are in the dish's unit (set at production). Convert when they
        // differ; fall back to the quantity as-is when no conversion
        // exists (same unit in the normal case).
        $need = $this->saleNeedForPreparedProduct($product, $lineQty, $creatorId);
        if ($need <= 0) {
            return false;
        }

        // Atomic gate: the batch must cover the whole need, or the
        // caller falls back to the legacy raw deduction. A partial
        // batch consumption followed by a raw deduction would
        // double-deduct the sale.
        if ($this->batchRemaining($productId, $warehouseId, $creatorId) + 1e-9 < $need) {
            return false;
        }

        $consumed = $this->consumeBatches(
            $productId,
            $warehouseId,
            $creatorId,
            $need,
            $line !== null ? (int) $line->id : null,
            true
        );
        if ($consumed < $need) {
            return false;
        }

        // The prepared product's own stock falls by the same amount
        // (global + warehouse + ledger) — the batch and the warehouse
        // stock are two views of the same prepared stock.
        $this->deductProductStock($productId, $need, $warehouseId, $creatorId, $posNo, $posId, $allowNegative);

        return true;
    }

    /**
     * A recipe line whose ingredient is itself a prepared product: the
     * batch it came from must fall by the same amount the ladder just
     * deducted from warehouse stock. Returns the quantity consumed
     * from batches (0 when the ingredient is not a prepared product).
     * Tolerant: consumes whatever batches hold; the send-time
     * shortage check is what blocks a short sale.
     */
    public function consumePreparedIngredient(
        ProductRecipe $line,
        float $ingredientQty,
        int $warehouseId,
        int $creatorId,
        ?PosProduct $posLine = null,
    ): float {
        $ingredient = $line->ingredientProduct;
        if (! $ingredient instanceof ProductService) {
            return 0.0;
        }
        if (($ingredient->type ?? '') !== 'ingredient') {
            return 0.0;
        }
        if (! ($ingredient->affect_stock ?? true)) {
            return 0.0;
        }
        if (! $this->isPreparedProduct((int) $ingredient->id, $creatorId)) {
            return 0.0;
        }

        // The line quantity is in the line's unit; the batch is in the
        // ingredient's stock unit. Convert when they differ.
        $need = $ingredientQty;
        try {
            $need = $this->conversions->convertToStockUnit(
                $ingredientQty,
                $line->unit_id ? (int) $line->unit_id : null,
                $ingredient,
                $creatorId
            );
        } catch (KitchenConversionMissingException) {
            // No conversion defined: fall back to the ladder's quantity.
        }
        if ($need <= 0) {
            return 0.0;
        }

        // Best effort (allOrNothing = false): the warehouse stock for
        // this ingredient was already deducted by the caller in the
        // same loop, so a short batch must not crash the sale — the
        // batch just falls by what it has. The send-time shortage
        // check is the enforcement point.
        return $this->consumeBatches(
            (int) $ingredient->id,
            $warehouseId,
            $creatorId,
            $need,
            $posLine !== null ? (int) $posLine->id : null,
            false
        );
    }

    /** Batch quantity a direct sale of a prepared product needs. */
    public function saleNeedForPreparedProduct(ProductService $product, float $lineQty, int $creatorId): float
    {
        $batchUnitId = $this->batchUnitId((int) $product->id, $creatorId);
        $fromUnitId = (int) ($product->unit_id ?? 0);

        if ($fromUnitId <= 0 || $batchUnitId === null || $batchUnitId <= 0 || $fromUnitId === $batchUnitId) {
            return round($lineQty, 4);
        }

        try {
            return round($this->conversions->convert($lineQty, $fromUnitId, $batchUnitId, $creatorId, (int) $product->id), 4);
        } catch (KitchenConversionMissingException) {
            return round($lineQty, 4);
        }
    }

    /** Mirror of PosRecipeDeductionService::deductProductQuantity, public so the batch path can use it. */
    public function deductProductStock(int $productId, float $quantity, int $warehouseId, int $creatorId, string $posNo, int $posId, bool $allowNegative = false): void
    {
        $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
        if (! $product) {
            return;
        }

        $original = (float) ($product->quantity ?? 0);
        $newQty = ($allowNegative || $product->allowsNegativeStock())
            ? $original - $quantity
            : max(0, $original - $quantity);
        ProductService::where('id', $productId)->update(['quantity' => $newQty]);

        Utility::warehouse_quantity('minus', $quantity, $productId, $warehouseId, null, $allowNegative);
        Utility::addProductStock($productId, $quantity, 'pos', $quantity . ' ' . __('quantity sold in pos') . ' ' . $posNo, $posId);
    }

    // -----------------------------------------------------------------
    // Shortage check before sending to production
    // -----------------------------------------------------------------

    /**
     * Pre-flight check for sendToProduction: for every item, every
     * prepared component it needs (the dish itself when it is a
     * prepared product, a prepared ingredient in its recipe, a prepared
     * option or a prepared ingredient in an option's recipe) must be
     * covered by non-expired batches in the item's warehouse.
     *
     * @param array<int, array{ id?: int, quantity?: float, selected_options?: mixed, selected_options_json?: mixed, warehouse_id?: int, negative_stock_authorized_by?: mixed }> $items
     * @return array<int, array{dish_id: int, dish_name: string, prepared_product_id: int, prepared_product_name: string, needed: float, available: float}>
     */
    public function checkShortages(array $items, int $warehouseId, int $creatorId): array
    {
        $shortages = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $productId = (int) ($item['id'] ?? 0);
            $qty = (float) ($item['quantity'] ?? 0);
            if ($productId <= 0 || $qty <= 0) {
                continue;
            }
            // A manager-authorized line may go negative: never block it.
            if (! empty($item['negative_stock_authorized_by'])) {
                continue;
            }

            $itemWarehouse = ! empty($item['warehouse_id']) ? (int) $item['warehouse_id'] : $warehouseId;
            $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
            if (! $product) {
                continue;
            }

            $selected = ProductOptionService::normalizeSelectedOptions(
                $item['selected_options'] ?? $item['selected_options_json'] ?? null
            );

            // The dish itself is a prepared product: it comes from its
            // batches only, and its recipe's raw inputs were already
            // deducted at production — they are not checked again.
            // (Its options are still checked below.)
            $dishIsPrepared = $this->isPreparedProduct($productId, $creatorId);
            if ($dishIsPrepared) {
                $need = $this->saleNeedForPreparedProduct($product, $qty, $creatorId);
                $have = $this->batchRemaining($productId, $itemWarehouse, $creatorId);
                if ($need > $have) {
                    $shortages[] = $this->shortageRow($product, $product, $need, $have);
                }
            }

            // Prepared ingredients in the dish's recipe (a prepared
            // dish's own recipe lines are production inputs, not sale
            // inputs, so they are not checked).
            if (! $dishIsPrepared) {
            $recipes = ProductRecipe::where('product_id', $productId)
                ->where('created_by', $creatorId)
                ->with(['ingredientProduct', 'alternatives'])
                ->get();
            foreach ($recipes as $line) {
                $ingredient = $line->ingredientProduct;
                if (! $ingredient instanceof ProductService) {
                    continue;
                }
                if (($ingredient->type ?? '') !== 'ingredient' || ! ($ingredient->affect_stock ?? true)) {
                    continue;
                }
                if (! $this->isPreparedProduct((int) $ingredient->id, $creatorId)) {
                    continue;
                }
                if ($this->isLineSwapped($line, $selected, $qty, $creatorId)) {
                    continue;
                }
                $need = $this->lineNeedInStockUnit($line, $ingredient, $qty, $creatorId);
                $have = $this->batchRemaining((int) $ingredient->id, $itemWarehouse, $creatorId);
                if ($need > $have) {
                    $shortages[] = $this->shortageRow($product, $ingredient, $need, $have);
                }
            }
            }

            // Prepared options and prepared ingredients in an option's recipe.
            foreach ($selected as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $optId = (int) ($row['product_id'] ?? 0);
                $optQty = (float) ($row['quantity'] ?? 1);
                if ($optId <= 0 || $optQty <= 0) {
                    continue;
                }
                $optNeed = $optQty * $qty;

                if ($this->isPreparedProduct($optId, $creatorId)) {
                    $have = $this->batchRemaining($optId, $itemWarehouse, $creatorId);
                    if ($optNeed > $have) {
                        $opt = ProductService::where('id', $optId)->where('created_by', $creatorId)->first();
                        $shortages[] = $this->shortageRow($product, $opt ?? $product, $optNeed, $have);
                    }
                }

                foreach (ProductOptionService::optionRecipeMovements([$row], $qty, $creatorId) as $move) {
                    $moveProduct = ProductService::where('id', (int) $move['product_id'])->where('created_by', $creatorId)->first();
                    if (! $moveProduct instanceof ProductService) {
                        continue;
                    }
                    if (! $this->isPreparedProduct((int) $moveProduct->id, $creatorId)) {
                        continue;
                    }
                    $have = $this->batchRemaining((int) $moveProduct->id, $itemWarehouse, $creatorId);
                    if ((float) $move['quantity'] > $have) {
                        $shortages[] = $this->shortageRow($product, $moveProduct, (float) $move['quantity'], $have);
                    }
                }
            }
        }

        return $shortages;
    }

    protected function shortageRow(ProductService $dish, ProductService $prepared, float $needed, float $available): array
    {
        return [
            'dish_id' => (int) $dish->id,
            'dish_name' => (string) ($dish->name ?? ''),
            'prepared_product_id' => (int) $prepared->id,
            'prepared_product_name' => (string) ($prepared->name ?? ''),
            'needed' => round($needed, 4),
            'available' => round($available, 4),
        ];
    }

    protected function lineNeedInStockUnit(ProductRecipe $line, ProductService $ingredient, float $dishQty, int $creatorId): float
    {
        $qty = (float) $line->quantity * $dishQty;
        try {
            return round($this->conversions->convertToStockUnit(
                $qty,
                $line->unit_id ? (int) $line->unit_id : null,
                $ingredient,
                $creatorId
            ), 4);
        } catch (KitchenConversionMissingException) {
            return round($qty, 4);
        }
    }

    // -----------------------------------------------------------------
    // Options: ingredient swap and effective cost
    // -----------------------------------------------------------------

    /**
     * Products the selected options consume: the option products
     * themselves plus the ingredients of the options' recipes
     * (optionRecipeMovements, honoring order-time picks).
     *
     * @return array<int, float> product_id => quantity (per line unit x line qty)
     */
    public function optionConsumedProductIds(array $selectedOptions, float $lineQty, int $creatorId): array
    {
        $consumed = [];
        $multiplier = $lineQty > 0 ? $lineQty : 1;

        foreach ($selectedOptions as $row) {
            if (! is_array($row)) {
                continue;
            }
            $optId = (int) ($row['product_id'] ?? 0);
            $optQty = (float) ($row['quantity'] ?? 1);
            if ($optId <= 0 || $optQty <= 0) {
                continue;
            }
            $consumed[$optId] = ($consumed[$optId] ?? 0) + $optQty * $multiplier;
        }

        foreach (ProductOptionService::optionRecipeMovements($selectedOptions, $lineQty, $creatorId) as $move) {
            $consumed[(int) $move['product_id']] = ($consumed[(int) $move['product_id']] ?? 0) + (float) $move['quantity'];
        }

        return $consumed;
    }

    /**
     * Recipe lines whose main ingredient was swapped by a selected
     * option: the option consumes a defined alternative of the line
     * (ProductRecipeAlternative), so the main ingredient is not
     * deducted — the alternative already came through the option.
     *
     * @return array<int, int> product_recipe_id => alternative_product_id
     */
    public function swappedRecipeLineIds(int $productId, array $selectedOptions, float $lineQty, int $creatorId): array
    {
        if ($selectedOptions === []) {
            return [];
        }

        $consumed = $this->optionConsumedProductIds($selectedOptions, $lineQty, $creatorId);
        if ($consumed === []) {
            return [];
        }

        $swapped = [];
        $lines = ProductRecipe::where('product_id', $productId)
            ->where('created_by', $creatorId)
            ->with('alternatives')
            ->get();
        foreach ($lines as $line) {
            foreach ($line->alternatives as $alt) {
                if (isset($consumed[(int) $alt->alternative_product_id])) {
                    $swapped[(int) $line->id] = (int) $alt->alternative_product_id;
                    break;
                }
            }
        }

        return $swapped;
    }

    protected function isLineSwapped(ProductRecipe $line, array $selectedOptions, float $lineQty, int $creatorId): bool
    {
        if ($selectedOptions === []) {
            return false;
        }

        $consumed = $this->optionConsumedProductIds($selectedOptions, $lineQty, $creatorId);
        foreach ($line->alternatives as $alt) {
            if (isset($consumed[(int) $alt->alternative_product_id])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The dish's food cost with the selected options applied: a swapped
     * line costs the alternative (at its effective quantity) instead of
     * the main ingredient. Same shape as
     * KitchenRecipeCostService::calculate, plus a `swapped_to` per line.
     *
     * @return array{recipe_cost: float, portions: float|null, cost_per_portion: float|null, lines: array<int, array{ingredient_id: int, quantity_stock_unit: float, unit_cost: float, line_cost: float, swapped_to: int|null, recipe_line_id: int}>}
     */
    public function effectiveRecipeCost(KitchenRecipe $recipe, array $selectedOptions, int $creatorId): array
    {
        $swapped = $this->swappedRecipeLineIds((int) $recipe->product_id, $selectedOptions, 1, $creatorId);
        $base = 0.0;
        $lines = [];

        foreach ($recipe->lines()->with(['ingredientProduct', 'alternatives'])->get() as $line) {
            $ingredient = $line->ingredientProduct;
            if (! $ingredient instanceof ProductService) {
                continue;
            }

            $effective = $ingredient;
            $qty = (float) $line->quantity;
            $swappedTo = null;
            if (isset($swapped[(int) $line->id])) {
                $alt = ProductService::where('id', $swapped[(int) $line->id])->where('created_by', $creatorId)->first();
                $altRow = $line->alternatives->firstWhere('alternative_product_id', $swapped[(int) $line->id]);
                if ($alt instanceof ProductService && $altRow) {
                    $effective = $alt;
                    $qty = $altRow->effectiveQuantity((float) $line->quantity);
                    $swappedTo = (int) $alt->id;
                }
            }

            $qtyInStockUnit = $this->conversions->convertToStockUnit(
                $qty,
                $line->unit_id ? (int) $line->unit_id : null,
                $effective,
                $creatorId
            );
            $unitCost = (float) ($effective->purchase_price ?? 0);
            $lineCost = round($qtyInStockUnit * $unitCost, 4);
            $base += $lineCost;

            $lines[] = [
                'ingredient_id' => (int) $effective->id,
                'quantity_stock_unit' => $qtyInStockUnit,
                'unit_cost' => $unitCost,
                'line_cost' => $lineCost,
                'swapped_to' => $swappedTo,
                'recipe_line_id' => (int) $line->id,
            ];
        }

        $lossPercent = (float) ($recipe->loss_percent ?? 0);
        $recipeCost = $lossPercent >= 100
            ? round($base, 2)
            : round($base / (1 - ($lossPercent / 100)), 2);

        $portions = $this->costs->portions($recipe);
        $costPerPortion = $portions !== null && $portions > 0
            ? round($recipeCost / $portions, 2)
            : null;

        return [
            'recipe_cost' => $recipeCost,
            'portions' => $portions,
            'cost_per_portion' => $costPerPortion,
            'lines' => $lines,
        ];
    }

    /**
     * Food cost of one POS line (the whole line, not per unit): the
     * dish's recipe cost scaled to the line quantity, with option swaps
     * applied; plain product_recipes lines when the dish has no
     * kitchen recipe. Used only for the waste cost of a cancelled
     * line, so it is best-effort by design.
     */
    public function dishLineFoodCost(int $productId, float $lineQty, array $selectedOptions, int $creatorId): float
    {
        $recipe = $this->approvedRecipeForProduct($productId, $creatorId);
        if ($recipe instanceof KitchenRecipe) {
            try {
                $cost = $this->effectiveRecipeCost($recipe, $selectedOptions, $creatorId);
            } catch (KitchenConversionMissingException) {
                $cost = null;
            }

            if ($cost !== null && ($cost['cost_per_portion'] ?? null) !== null) {
                $portionsOrdered = $this->portionsOrdered($recipe, $lineQty, $creatorId);

                return round($portionsOrdered * (float) $cost['cost_per_portion'], 2);
            }
        }

        // No kitchen recipe (or no costable portion): sum the plain
        // recipe lines per dish unit, with swaps applied.
        $total = 0.0;
        $swapped = $this->swappedRecipeLineIds($productId, $selectedOptions, $lineQty, $creatorId);
        foreach (ProductRecipe::where('product_id', $productId)->where('created_by', $creatorId)->with(['ingredientProduct', 'alternatives'])->get() as $line) {
            if (isset($swapped[(int) $line->id])) {
                $alt = ProductService::where('id', $swapped[(int) $line->id])->where('created_by', $creatorId)->first();
                $altRow = $line->alternatives->firstWhere('alternative_product_id', $swapped[(int) $line->id]);
                if ($alt instanceof ProductService && $altRow) {
                    $total += $altRow->effectiveQuantity((float) $line->quantity) * (float) ($alt->purchase_price ?? 0) * $lineQty;
                    continue;
                }
            }

            $ingredient = $line->ingredientProduct;
            if (! $ingredient instanceof ProductService) {
                continue;
            }
            $total += (float) $line->quantity * (float) ($ingredient->purchase_price ?? 0) * $lineQty;
        }

        return round($total, 2);
    }

    /** How many recipe portions a line quantity is (for the waste cost). */
    protected function portionsOrdered(KitchenRecipe $recipe, float $lineQty, int $creatorId): float
    {
        $portionQty = (float) ($recipe->standard_portion_quantity ?? 0);
        $portionUnitId = (int) ($recipe->standard_portion_unit_id ?? 0);
        if ($portionQty <= 0 || $portionUnitId <= 0) {
            return $lineQty;
        }

        $dish = $recipe->product;
        $dishUnitId = $dish instanceof ProductService ? (int) ($dish->unit_id ?? 0) : 0;

        $qtyInPortionUnit = $lineQty;
        if ($dishUnitId > 0 && $dishUnitId !== $portionUnitId) {
            try {
                $qtyInPortionUnit = $this->conversions->convert($lineQty, $dishUnitId, $portionUnitId, $creatorId, (int) $recipe->product_id);
            } catch (KitchenConversionMissingException) {
                $qtyInPortionUnit = $lineQty;
            }
        }

        return $qtyInPortionUnit / $portionQty;
    }

    // -----------------------------------------------------------------
    // Cancellation: before vs after preparation
    // -----------------------------------------------------------------

    /** True once the kitchen has started (or finished) a sent line. */
    public function isAfterPreparation(PosProduct $line): bool
    {
        return in_array($line->status, [
            PosProduct::STATUS_IN_PREPARATION,
            PosProduct::STATUS_READY,
            PosProduct::STATUS_SERVED,
        ], true);
    }

    /**
     * Cancel one sent POS line. Returns what happened:
     * - 'restored': before preparation, stock went back (existing
     *   restore ladder + the batches this line consumed).
     * - 'loss': after preparation, nothing was restored; the prepared
     *   food was recorded as a kitchen loss with its destination.
     * - 'returned': after preparation with destination "returned to
     *   stock" and the line sold a prepared product itself: the
     *   prepared food went back into its batch (raw ingredients stay
     *   deducted — they were cooked).
     * - 'nothing': the line was never deducted or was already restored.
     */
    public function cancelLine(Pos $pos, PosProduct $line, int $creatorId, string $posNo, ?string $destination, ?User $user): string
    {
        $destination = in_array($destination, self::DESTINATIONS, true)
            ? $destination
            : self::DESTINATION_DISPOSAL;

        if (! $line->stock_deducted_at || $line->stock_restored_at) {
            return 'nothing';
        }

        if (! $this->isAfterPreparation($line)) {
            $restored = PosLineStockRestoreService::restoreLine($pos, $line, $creatorId, $posNo);

            return $restored ? 'restored' : 'nothing';
        }

        if ($destination === self::DESTINATION_RETURNED_TO_STOCK
            && $this->isPreparedProduct((int) $line->product_id, $creatorId)
            && $this->consumedQuantityForProduct($line, (int) $line->product_id, $creatorId) > 0
        ) {
            $this->restorePreparedSale($pos, $line, $creatorId, $posNo);

            return 'returned';
        }

        $this->recordKitchenLoss($pos, $line, $creatorId, $destination, $user);

        return 'loss';
    }

    /**
     * Return a prepared product itself to its batch (destination
     * "returned to stock"): the batch and the product's own stock go
     * back up by what the line consumed. Raw ingredients stay deducted.
     */
    protected function restorePreparedSale(Pos $pos, PosProduct $line, int $creatorId, string $posNo): void
    {
        $consumed = $this->consumedQuantityForProduct($line, (int) $line->product_id, $creatorId);
        if ($consumed <= 0) {
            return;
        }

        $this->restoreBatchesOnly($line, $creatorId);

        $warehouseId = (int) ($line->warehouse_id ?? 0) > 0
            ? (int) $line->warehouse_id
            : (int) ($pos->warehouse_id ?? 0);

        $product = ProductService::where('id', (int) $line->product_id)->where('created_by', $creatorId)->first();
        if ($product instanceof ProductService) {
            ProductService::where('id', (int) $line->product_id)->update(['quantity' => (float) ($product->quantity ?? 0) + $consumed]);
            if ($warehouseId > 0) {
                Utility::warehouse_quantity('plus', $consumed, (int) $line->product_id, $warehouseId);
            }
            Utility::addProductStock((int) $line->product_id, -$consumed, 'pos', $consumed . ' ' . __('quantity returned in pos') . ' ' . $posNo, (int) $pos->id);
        }

        $line->stock_restored_at = now();
        $line->save();
    }

    /**
     * Record the waste of a line cancelled after preparation. The stock
     * was already deducted when the line was sent to production, so
     * this row is a waste log only — it must NOT move stock again.
     * The wasted item is the dish itself, costed at its food cost.
     */
    protected function recordKitchenLoss(Pos $pos, PosProduct $line, int $creatorId, string $destination, ?User $user): WarehouseLoss
    {
        $product = ProductService::where('id', (int) $line->product_id)->where('created_by', $creatorId)->first();
        $selected = ProductOptionService::normalizeSelectedOptions($line->selected_options_json ?? null);
        $lineQty = (float) $line->quantity;
        $totalCost = $this->dishLineFoodCost((int) $line->product_id, $lineQty, $selected, $creatorId);
        $unitCost = $lineQty > 0 ? round($totalCost / $lineQty, 4) : 0.0;

        $warehouseId = (int) ($line->warehouse_id ?? 0) > 0
            ? (int) $line->warehouse_id
            : (int) ($pos->warehouse_id ?? 0);

        return WarehouseLoss::create([
            'write_off_no' => $this->nextWriteOffNumber($creatorId),
            'warehouse_id' => $warehouseId,
            'product_id' => (int) $line->product_id,
            'quantity' => $lineQty,
            'unit_cost' => $unitCost,
            'cost_of_goods' => $totalCost,
            'date' => date('Y-m-d'),
            'reason' => __('Kitchen loss after preparation — POS :pos line :line, destination: :destination', [
                'pos' => $pos->pos_id ?? $pos->id,
                'line' => $line->id,
                'destination' => $destination,
            ]),
            'issuer_name' => $user ? (string) ($user->name ?? '') : '',
            'authorized_by' => $user ? (string) ($user->name ?? '') : '',
            'witness_name' => null,
            'delivery_note_required' => false,
            'delivery_destination' => $destination,
            'created_by' => $creatorId,
        ]);
    }

    protected function nextWriteOffNumber(int $creatorId): string
    {
        $maxId = (int) (WarehouseLoss::where('created_by', $creatorId)->max('id') ?? 0);

        return 'WO-' . str_pad((string) ($maxId + 1), 6, '0', STR_PAD_LEFT);
    }
}
