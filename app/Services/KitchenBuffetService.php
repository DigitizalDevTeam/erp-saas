<?php

namespace App\Services;

use App\Models\KitchenBuffetEvent;
use App\Models\KitchenBuffetEventLine;
use App\Models\KitchenLoss;
use App\Models\KitchenProductionPlan;
use App\Models\KitchenRecipe;
use App\Models\ProductRecipe;
use App\Models\ProductService;
use App\Models\StockReport;
use App\Models\User;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Kitchen Production — Buffet events (Phase 4, plan §3/§5, decision D2).
 *
 * The client model (Danilo, voice note 2026-11-09): a buffet is charged
 * PER PERSON (covers), never per dish — but stock is still controlled.
 * The hotel knows the day before how many persons come tomorrow, so
 * the chef prepares everything the day before for that headcount.
 * After service the team confirms what was used, what is waste and
 * what is still good and goes back to stock for the next day.
 *
 * Flow: DRAFT -> PREPARED -> COMPLETED (or CANCELLED from draft).
 * - Create: date / meal service, expected covers from hotel occupancy
 *   (read-only, via KitchenOccupancyService) or a manual headcount,
 *   and one line per preparation / item with a planned quantity (per
 *   cover x covers, or entered directly).
 * - Prepare: the food is made / issued. A recipe line deducts its
 *   ingredients (scaled to the planned quantity, FEFO when lot-tracked)
 *   and receives the preparation into kitchen stock; a direct line
 *   (leaves, roast beef — served as-is) is issued out of warehouse
 *   stock to the buffet. Blocked on a shortage, naming the item.
 * - Close (after service): the chef records per line what was used,
 *   what is leftover and what is waste. The three must add up to what
 *   was prepared (prepared = used + leftover + waste). Used and waste
 *   leave the preparation stock; leftovers stay in stock (recipe
 *   lines) or return to it (direct lines). Waste is written off
 *   through a kitchen loss (posted, stock already deducted); leftovers
 *   are recorded as a buffet-leftover loss with the reuse flag and a
 *   food-safety note — recorded, never posted, because nothing was
 *   written off.
 *
 * Buffet consumption NEVER touches the POS: no per-dish deduction
 * happens for a buffet (the charge is per cover, not per dish).
 *
 * Stock movements mirror KitchenProductionOrderService (same three
 * views: global quantity, warehouse quantity, stock_reports ledger)
 * with the company id explicit, so the service stays unit-testable.
 * No new journal postings beyond what KitchenLossService already
 * does for a posted waste loss.
 */
class KitchenBuffetService
{
    public function __construct(
        protected KitchenUnitConversionService $conversions,
        protected KitchenProductionOrderService $orders,
        protected KitchenOccupancyService $occupancy,
        protected KitchenLossService $losses,
        protected KitchenPosSaleService $posSale,
    ) {
    }

    // -----------------------------------------------------------------
    // Create / edit (draft only)
    // -----------------------------------------------------------------

    /**
     * @param array{
     *   event_date: string, meal_service?: string|null, name?: string|null,
     *   event_link?: string|null, branch_id?: int|null, warehouse_id: int,
     *   covers_source?: string, expected_covers?: int|null,
     *   price_per_person?: float|null, notes?: string|null,
     *   lines: array<int, array{product_id: int, per_cover_quantity?: float|null, planned_quantity?: float|null, unit_id?: int|null, kitchen_recipe_id?: int|null, notes?: string|null}>
     * } $data
     */
    public function create(array $data, User $user): KitchenBuffetEvent
    {
        $creatorId = (int) $user->creatorId();
        $validated = $this->validateEventData($data, $creatorId, $user);

        return DB::transaction(function () use ($validated, $data, $user, $creatorId) {
            $event = KitchenBuffetEvent::create([
                'event_number' => $this->nextEventNumber($creatorId),
                'event_date' => $validated['event_date'],
                'meal_service' => $validated['meal_service'],
                'name' => $validated['name'],
                'event_link' => $validated['event_link'],
                'branch_id' => $validated['branch_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'covers_source' => $validated['covers_source'],
                'expected_covers' => $validated['expected_covers'],
                'occupancy_snapshot' => $validated['occupancy_snapshot'],
                'price_per_person' => $validated['price_per_person'],
                'status' => KitchenBuffetEvent::STATUS_DRAFT,
                'notes' => $data['notes'] ?? null,
                'created_by' => $creatorId,
            ]);

            $this->syncLines($event, $validated['lines'], $creatorId);

            return $event->fresh();
        });
    }

    public function updateDraft(KitchenBuffetEvent $event, array $data, User $user): KitchenBuffetEvent
    {
        $this->assertSameCompany($event, $user);

        if (! $event->isDraft()) {
            throw new InvalidArgumentException(__('Only a draft buffet event can be edited.'));
        }

        $creatorId = (int) $event->created_by;
        $validated = $this->validateEventData($data, $creatorId, $user);

        return DB::transaction(function () use ($event, $validated, $data) {
            $event->update([
                'event_date' => $validated['event_date'],
                'meal_service' => $validated['meal_service'],
                'name' => $validated['name'],
                'event_link' => $validated['event_link'],
                'branch_id' => $validated['branch_id'],
                'warehouse_id' => $validated['warehouse_id'],
                'covers_source' => $validated['covers_source'],
                'expected_covers' => $validated['expected_covers'],
                'occupancy_snapshot' => $validated['occupancy_snapshot'],
                'price_per_person' => $validated['price_per_person'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->syncLines($event, $validated['lines'], (int) $event->created_by);

            return $event->fresh();
        });
    }

    /**
     * Shared validation for create() and updateDraft(). Resolves the
     * warehouse, the covers (occupancy snapshot or manual headcount)
     * and every line's product, recipe and planned quantity (per-cover
     * x covers, or entered directly).
     *
     * @return array{event_date: string, meal_service: string|null, name: string|null, event_link: string|null, branch_id: int|null, warehouse_id: int, covers_source: string, expected_covers: int, occupancy_snapshot: array|null, price_per_person: float|null, lines: array<int, array{product_id: int, kitchen_recipe_id: int|null, unit_id: int|null, per_cover_quantity: float|null, planned_quantity: float, notes: string|null}>}
     */
    protected function validateEventData(array $data, int $creatorId, User $user): array
    {
        $eventDate = (string) ($data['event_date'] ?? '');
        if (strtotime($eventDate) === false) {
            throw new InvalidArgumentException(__('Select a valid event date.'));
        }
        $eventDate = date('Y-m-d', strtotime($eventDate));

        $mealService = $data['meal_service'] ?? null;
        if ($mealService !== null && $mealService !== '' && ! in_array($mealService, KitchenProductionPlan::MEAL_SERVICES, true)) {
            throw new InvalidArgumentException(__('Select a valid meal service.'));
        }
        $mealService = $mealService !== '' ? $mealService : null;

        $warehouse = warehouse::where('id', (int) ($data['warehouse_id'] ?? 0))
            ->where('created_by', $creatorId)
            ->first();
        if (! $warehouse) {
            throw new InvalidArgumentException(__('The warehouse is required.'));
        }

        $branchId = ! empty($data['branch_id']) ? (int) $data['branch_id'] : null;

        $coversSource = (string) ($data['covers_source'] ?? KitchenBuffetEvent::COVERS_MANUAL);
        if (! in_array($coversSource, KitchenBuffetEvent::COVERS_SOURCES, true)) {
            throw new InvalidArgumentException(__('Select where the expected covers come from.'));
        }

        $occupancySnapshot = null;
        if ($coversSource === KitchenBuffetEvent::COVERS_OCCUPANCY) {
            $occupancySnapshot = $this->occupancy->snapshot($eventDate, $creatorId, $branchId);
            $expectedCovers = $mealService !== null
                ? $this->occupancy->coversForMeal($eventDate, $mealService, $creatorId, $branchId)
                : (int) ($occupancySnapshot['total_guests'] ?? 0);
            if ($expectedCovers <= 0) {
                throw new InvalidArgumentException(__('No guests with a meal plan are in-house on that date, so occupancy gives zero covers. Enter the headcount manually.'));
            }
        } else {
            $expectedCovers = (int) ($data['expected_covers'] ?? 0);
            if ($expectedCovers <= 0) {
                throw new InvalidArgumentException(__('Expected covers must be greater than zero.'));
            }
        }

        $pricePerPerson = null;
        if (isset($data['price_per_person']) && $data['price_per_person'] !== '' && $data['price_per_person'] !== null) {
            $pricePerPerson = max(0, (float) $data['price_per_person']);
        }

        $rawLines = $data['lines'] ?? [];
        if (! is_array($rawLines) || $rawLines === []) {
            throw new InvalidArgumentException(__('A buffet event needs at least one preparation or item.'));
        }

        $lines = [];
        $seenProducts = [];
        foreach ($rawLines as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $product = ProductService::where('id', (int) ($raw['product_id'] ?? 0))
                ->where('created_by', $creatorId)
                ->first();
            if (! $product) {
                throw new InvalidArgumentException(__('A buffet line product does not exist.'));
            }
            if ((string) ($product->type ?? '') === 'kit') {
                throw new InvalidArgumentException(__('A buffet line cannot be a kit.'));
            }
            if (in_array((int) $product->id, $seenProducts, true)) {
                throw new InvalidArgumentException(__('Each product can appear once per buffet event.'));
            }
            $seenProducts[] = (int) $product->id;

            $perCover = isset($raw['per_cover_quantity']) && $raw['per_cover_quantity'] !== '' && $raw['per_cover_quantity'] !== null
                ? (float) $raw['per_cover_quantity']
                : null;
            $planned = null;
            if ($perCover !== null && $perCover > 0) {
                $planned = round($perCover * $expectedCovers, 4);
            } elseif (isset($raw['planned_quantity']) && (float) $raw['planned_quantity'] > 0) {
                $planned = round((float) $raw['planned_quantity'], 4);
            }
            if ($planned === null || $planned <= 0) {
                throw new InvalidArgumentException(__('Each buffet line needs a planned quantity (per cover, or entered directly).'));
            }

            $recipeId = null;
            if (! empty($raw['kitchen_recipe_id'])) {
                $recipe = KitchenRecipe::where('id', (int) $raw['kitchen_recipe_id'])
                    ->where('created_by', $creatorId)
                    ->first();
                if (! $recipe || (int) $recipe->product_id !== (int) $product->id || ! $recipe->isApproved()) {
                    throw new InvalidArgumentException(__('The recipe for :product must be an approved recipe for that product.', ['product' => (string) $product->name]));
                }
                $recipeId = (int) $recipe->id;
            }

            $unitId = ! empty($raw['unit_id']) ? (int) $raw['unit_id'] : ((int) ($product->unit_id ?? 0) ?: null);

            $lines[] = [
                'product_id' => (int) $product->id,
                'kitchen_recipe_id' => $recipeId,
                'unit_id' => $unitId,
                'per_cover_quantity' => $perCover !== null && $perCover > 0 ? round($perCover, 4) : null,
                'planned_quantity' => $planned,
                'notes' => $raw['notes'] ?? null,
            ];
        }

        if ($lines === []) {
            throw new InvalidArgumentException(__('A buffet event needs at least one preparation or item.'));
        }

        return [
            'event_date' => $eventDate,
            'meal_service' => $mealService,
            'name' => $data['name'] ?? null,
            'event_link' => $data['event_link'] ?? null,
            'branch_id' => $branchId,
            'warehouse_id' => (int) $warehouse->id,
            'covers_source' => $coversSource,
            'expected_covers' => $expectedCovers,
            'occupancy_snapshot' => $occupancySnapshot,
            'price_per_person' => $pricePerPerson,
            'lines' => $lines,
        ];
    }

    protected function syncLines(KitchenBuffetEvent $event, array $lines, int $creatorId): void
    {
        KitchenBuffetEventLine::where('kitchen_buffet_event_id', (int) $event->id)->delete();

        foreach ($lines as $line) {
            KitchenBuffetEventLine::create(array_merge($line, [
                'kitchen_buffet_event_id' => (int) $event->id,
                'created_by' => $creatorId,
            ]));
        }
    }

    // -----------------------------------------------------------------
    // Prepare (draft -> prepared): make / issue the food
    // -----------------------------------------------------------------

    /**
     * Prepare the event: for every line, make the preparation or issue
     * the item. Blocked on a shortage, naming the item — nothing moves
     * until every line is covered.
     */
    public function prepare(KitchenBuffetEvent $event, User $user): KitchenBuffetEvent
    {
        $this->assertSameCompany($event, $user);

        if (! $event->isDraft()) {
            throw new InvalidArgumentException(__('Only a draft buffet event can be prepared.'));
        }

        $creatorId = (int) $event->created_by;
        $warehouseId = (int) $event->warehouse_id;

        $shortages = $this->checkAvailability($event, $creatorId);
        if ($shortages !== []) {
            $first = $shortages[0];
            throw new InvalidArgumentException(__('Not enough :item in the kitchen warehouse: need :need, have :have. Move stock first or lower the planned quantity.', [
                'item' => $first['item_name'],
                'need' => $first['needed'],
                'have' => $first['available'],
            ]));
        }

        return DB::transaction(function () use ($event, $user, $creatorId, $warehouseId) {
            $locked = KitchenBuffetEvent::where('id', $event->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isDraft()) {
                throw new InvalidArgumentException(__('Only a draft buffet event can be prepared.'));
            }

            foreach ($locked->lines()->with('product')->get() as $line) {
                $product = $line->product;
                if (! $product instanceof ProductService) {
                    continue;
                }

                $planned = (float) $line->planned_quantity;
                $plannedUnitId = $line->unit_id ? (int) $line->unit_id : null;

                $ingredients = $this->resolveIngredients($line, $product, $planned, $plannedUnitId, $creatorId);

                if ($ingredients !== null) {
                    // Recipe line: the raw ingredients leave stock and
                    // the preparation enters it (same as a production
                    // order completion, minus the order + batch rows —
                    // a buffet preparation has no production order).
                    $totalCost = 0.0;
                    foreach ($ingredients as $ingredient) {
                        $this->deductStock(
                            (int) $ingredient['ingredient_product_id'],
                            (float) $ingredient['quantity'],
                            $warehouseId,
                            $creatorId,
                            'kitchen_buffet',
                            __('Buffet :event — ingredients for :product', ['event' => $locked->event_number, 'product' => (string) $product->name]),
                            (int) $locked->id
                        );
                        $totalCost += (float) $ingredient['cost'];
                    }
                    $totalCost = round($totalCost, 2);
                    $this->addStock(
                        (int) $product->id,
                        $planned,
                        $warehouseId,
                        $creatorId,
                        'kitchen_buffet',
                        __('Buffet :event — prepared :product', ['event' => $locked->event_number, 'product' => (string) $product->name]),
                        (int) $locked->id
                    );
                    $line->unit_cost = $planned > 0 ? round($totalCost / $planned, 4) : null;
                    $line->total_cost = $totalCost;
                } else {
                    // Direct line (served as-is): the item leaves the
                    // warehouse for the buffet table.
                    $this->deductStock(
                        (int) $product->id,
                        $planned,
                        $warehouseId,
                        $creatorId,
                        'kitchen_buffet',
                        __('Buffet :event — issued :product to the buffet', ['event' => $locked->event_number, 'product' => (string) $product->name]),
                        (int) $locked->id
                    );
                    $unitCost = (float) ($product->purchase_price ?? 0);
                    $line->unit_cost = $unitCost;
                    $line->total_cost = round($planned * $unitCost, 2);
                }

                $line->prepared_quantity = round($planned, 4);
                $line->save();
            }

            $locked->status = KitchenBuffetEvent::STATUS_PREPARED;
            $locked->prepared_by = (int) $user->id;
            $locked->prepared_at = now();
            $locked->save();

            return $locked->fresh();
        });
    }

    /**
     * Shortages for the prepare step: which ingredients / items the
     * kitchen warehouse cannot cover. Empty array = everything covered.
     *
     * @return array<int, array{line_id: int, item_name: string, needed: float, available: float}>
     */
    public function checkAvailability(KitchenBuffetEvent $event, int $creatorId): array
    {
        $warehouseId = (int) $event->warehouse_id;
        $shortages = [];

        foreach ($event->lines()->with('product')->get() as $line) {
            $product = $line->product;
            if (! $product instanceof ProductService) {
                continue;
            }

            $planned = (float) $line->planned_quantity;
            $plannedUnitId = $line->unit_id ? (int) $line->unit_id : null;

            $ingredients = $this->resolveIngredients($line, $product, $planned, $plannedUnitId, $creatorId);

            if ($ingredients !== null) {
                foreach ($ingredients as $ingredient) {
                    $ingredientProduct = ProductService::where('id', (int) $ingredient['ingredient_product_id'])
                        ->where('created_by', $creatorId)
                        ->first();
                    if (! $ingredientProduct instanceof ProductService) {
                        continue;
                    }
                    if ($ingredientProduct->allowsNegativeStock()) {
                        continue;
                    }
                    $needed = (float) $ingredient['quantity'];
                    $available = $this->availableQty($ingredientProduct, $warehouseId, $creatorId);
                    if ($needed > $available + 1e-9) {
                        $shortages[] = [
                            'line_id' => (int) $line->id,
                            'item_name' => (string) $ingredientProduct->name,
                            'needed' => round($needed, 4),
                            'available' => round($available, 4),
                        ];
                    }
                }
            } else {
                if ($product->allowsNegativeStock()) {
                    continue;
                }
                $available = $this->availableQty($product, $warehouseId, $creatorId);
                if ($planned > $available + 1e-9) {
                    $shortages[] = [
                        'line_id' => (int) $line->id,
                        'item_name' => (string) $product->name,
                        'needed' => round($planned, 4),
                        'available' => round($available, 4),
                    ];
                }
            }
        }

        return $shortages;
    }

    /**
     * Ingredients for one line, scaled to the planned quantity, in
     * stock units — or null when the line is a direct stock item with
     * no recipe. An approved recipe (explicit on the line, or the
     * product's current approved recipe) scales through the production
     * order planner (yield / portion / loss %); plain recipe lines
     * scale per unit of the dish.
     *
     * @return array<int, array{ingredient_product_id: int, quantity: float, cost: float}>|null
     */
    protected function resolveIngredients(
        KitchenBuffetEventLine $line,
        ProductService $product,
        float $plannedQuantity,
        ?int $plannedUnitId,
        int $creatorId,
    ): ?array {
        $recipe = null;
        if ($line->kitchen_recipe_id) {
            $recipe = KitchenRecipe::where('id', (int) $line->kitchen_recipe_id)
                ->where('created_by', $creatorId)
                ->first();
        }
        if (! $recipe) {
            $recipe = $this->posSale->approvedRecipeForProduct((int) $product->id, $creatorId);
        }

        if ($recipe) {
            $planned = $this->orders->planLines($recipe, $plannedQuantity, $plannedUnitId);

            return array_map(fn (array $row) => [
                'ingredient_product_id' => (int) $row['ingredient_product_id'],
                'quantity' => (float) $row['planned_quantity'],
                'cost' => (float) $row['planned_cost'],
            ], $planned['lines']);
        }

        $rows = ProductRecipe::where('product_id', (int) $product->id)
            ->where('created_by', $creatorId)
            ->with('ingredientProduct')
            ->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $ingredients = [];
        foreach ($rows as $row) {
            $ingredient = $row->ingredientProduct;
            if (! $ingredient instanceof ProductService) {
                continue;
            }
            $quantity = (float) $row->quantity * $plannedQuantity;
            $lossPercent = max(0, min(99.99, (float) ($row->loss_percent ?? 0)));
            if ($lossPercent > 0) {
                $quantity = $quantity / (1 - ($lossPercent / 100));
            }
            $quantityInStockUnit = $this->conversions->convertToStockUnit(
                $quantity,
                $row->unit_id ? (int) $row->unit_id : null,
                $ingredient,
                $creatorId
            );
            $ingredients[] = [
                'ingredient_product_id' => (int) $ingredient->id,
                'quantity' => round($quantityInStockUnit, 4),
                'cost' => round($quantityInStockUnit * (float) ($ingredient->purchase_price ?? 0), 2),
            ];
        }

        return $ingredients === [] ? null : $ingredients;
    }

    // -----------------------------------------------------------------
    // Close (prepared -> completed): reconcile after service
    // -----------------------------------------------------------------

    /**
     * Close the event after service. Every line must reconcile:
     * used + leftover + waste = prepared. Used and waste leave the
     * preparation stock; leftovers stay in stock (recipe lines) or
     * return to it (direct lines). Waste is written off through a
     * kitchen loss (posted); leftovers are recorded as a
     * buffet-leftover loss with the reuse flag — recorded, never
     * posted, because nothing was written off.
     *
     * @param array<int, array{used_quantity: float, leftover_quantity: float, waste_quantity: float}> $lines line id => quantities
     */
    public function close(KitchenBuffetEvent $event, User $user, array $lines, ?string $foodSafetyNote = null): KitchenBuffetEvent
    {
        $this->assertSameCompany($event, $user);

        if (! $event->isPrepared()) {
            throw new InvalidArgumentException(__('Only a prepared buffet event can be closed.'));
        }

        $creatorId = (int) $event->created_by;
        $warehouseId = (int) $event->warehouse_id;

        $eventLines = $event->lines()->with('product')->get();
        $validated = [];
        $needsFoodSafetyNote = false;
        foreach ($eventLines as $line) {
            $row = $lines[(int) $line->id] ?? null;
            if (! is_array($row)) {
                throw new InvalidArgumentException(__('Every buffet line must be reconciled.'));
            }
            $used = max(0, (float) ($row['used_quantity'] ?? 0));
            $leftover = max(0, (float) ($row['leftover_quantity'] ?? 0));
            $waste = max(0, (float) ($row['waste_quantity'] ?? 0));
            $prepared = (float) $line->prepared_quantity;
            $difference = round($used + $leftover + $waste - $prepared, 4);
            if (abs($difference) >= 0.0001) {
                throw new InvalidArgumentException(__('Line :product does not reconcile: used + leftover + waste must equal the prepared :prepared.', [
                    'product' => (string) ($line->product->name ?? '#'.$line->id),
                    'prepared' => $prepared,
                ]));
            }
            if ($leftover > 0) {
                $needsFoodSafetyNote = true;
            }
            $validated[(int) $line->id] = ['used' => $used, 'leftover' => $leftover, 'waste' => $waste];
        }

        if ($needsFoodSafetyNote && ($foodSafetyNote === null || trim($foodSafetyNote) === '')) {
            throw new InvalidArgumentException(__('Leftovers kept for the next day need a food-safety note.'));
        }

        return DB::transaction(function () use ($event, $user, $creatorId, $warehouseId, $eventLines, $validated, $foodSafetyNote) {
            $locked = KitchenBuffetEvent::where('id', $event->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPrepared()) {
                throw new InvalidArgumentException(__('Only a prepared buffet event can be closed.'));
            }

            foreach ($eventLines as $line) {
                $row = $validated[(int) $line->id];
                $product = $line->product;
                $hasRecipe = $line->kitchen_recipe_id !== null
                    || ($product instanceof ProductService && $this->posSale->approvedRecipeForProduct((int) $product->id, $creatorId) !== null)
                    || ($product instanceof ProductService && ProductRecipe::where('product_id', (int) $product->id)->where('created_by', $creatorId)->exists());

                // Used + waste leave the preparation stock (recipe
                // lines). Direct lines were already issued at prepare,
                // so their used + waste are already out of stock.
                if ($hasRecipe && $row['used'] + $row['waste'] > 0) {
                    $this->deductStock(
                        (int) $line->product_id,
                        $row['used'] + $row['waste'],
                        $warehouseId,
                        $creatorId,
                        'kitchen_buffet',
                        __('Buffet :event — used + waste of :product', ['event' => $locked->event_number, 'product' => (string) ($product->name ?? $line->product_id)]),
                        (int) $locked->id
                    );
                }

                // Leftovers of a direct line return to warehouse stock.
                // Recipe-line leftovers never left it.
                if (! $hasRecipe && $row['leftover'] > 0) {
                    $this->addStock(
                        (int) $line->product_id,
                        $row['leftover'],
                        $warehouseId,
                        $creatorId,
                        'kitchen_buffet',
                        __('Buffet :event — leftover :product back to stock', ['event' => $locked->event_number, 'product' => (string) ($product->name ?? $line->product_id)]),
                        (int) $locked->id
                    );
                }

                $line->used_quantity = round($row['used'], 4);
                $line->leftover_quantity = round($row['leftover'], 4);
                $line->waste_quantity = round($row['waste'], 4);
                $line->save();

                // Waste is written off through a kitchen loss (posted;
                // the stock already left, so only the log + journal).
                if ($row['waste'] > 0) {
                    $loss = $this->losses->record([
                        'category' => KitchenLoss::CATEGORY_BURNT_SPOILED,
                        'product_id' => (int) $line->product_id,
                        'quantity' => $row['waste'],
                        'unit_id' => $line->unit_id,
                        'unit_cost' => $line->unit_cost,
                        'warehouse_id' => $warehouseId,
                        'reason' => __('Buffet waste — :event', ['event' => $locked->event_number]),
                        'stock_already_deducted' => true,
                    ], $user);
                    $loss->update([
                        'kitchen_buffet_event_id' => (int) $locked->id,
                        'kitchen_buffet_event_line_id' => (int) $line->id,
                    ]);
                    $this->losses->post($loss->fresh(), $user);
                }

                // Leftovers are recorded as a buffet-leftover loss
                // with the reuse flag — recorded, never posted,
                // because nothing was written off.
                if ($row['leftover'] > 0) {
                    $loss = $this->losses->record([
                        'category' => KitchenLoss::CATEGORY_BUFFET_LEFTOVER,
                        'product_id' => (int) $line->product_id,
                        'quantity' => $row['leftover'],
                        'unit_id' => $line->unit_id,
                        'unit_cost' => $line->unit_cost,
                        'warehouse_id' => $warehouseId,
                        'reason' => __('Buffet leftover — :event (kept for the next day)', ['event' => $locked->event_number]),
                        'reuse_flag' => true,
                        'food_safety_note' => $foodSafetyNote,
                        'reuse_destination' => KitchenLoss::REUSE_RE_PREPPED,
                        'stock_already_deducted' => ! $hasRecipe,
                    ], $user);
                    $loss->update([
                        'kitchen_buffet_event_id' => (int) $locked->id,
                        'kitchen_buffet_event_line_id' => (int) $line->id,
                    ]);
                }
            }

            $locked->status = KitchenBuffetEvent::STATUS_COMPLETED;
            $locked->closed_by = (int) $user->id;
            $locked->closed_at = now();
            $locked->save();

            return $locked->fresh();
        });
    }

    /** Only a draft event can be cancelled — a prepared event must be closed (reconciled) instead. */
    public function cancel(KitchenBuffetEvent $event, User $user): KitchenBuffetEvent
    {
        $this->assertSameCompany($event, $user);

        if (! $event->isDraft()) {
            throw new InvalidArgumentException(__('Only a draft buffet event can be cancelled. Close a prepared event instead, so its stock reconciles.'));
        }

        $event->status = KitchenBuffetEvent::STATUS_CANCELLED;
        $event->save();

        return $event->fresh();
    }

    public function nextEventNumber(int $creatorId): string
    {
        $year = date('Y');
        $prefix = 'BF-'.$year.'-';

        $count = KitchenBuffetEvent::where('created_by', $creatorId)
            ->where('event_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->count();

        return $prefix.str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }

    // -----------------------------------------------------------------
    // Stock primitives (mirror KitchenProductionOrderService)
    // -----------------------------------------------------------------

    protected function availableQty(ProductService $product, int $warehouseId, int $creatorId): float
    {
        if (($product->batch_tracking ?? false) || BatchLotDeductionService::hasLots((int) $product->id, $warehouseId, $creatorId)) {
            return BatchLotDeductionService::availableQty((int) $product->id, $warehouseId, $creatorId);
        }

        return $this->orders->warehouseQty((int) $product->id, $warehouseId, $creatorId);
    }

    /**
     * Take stock out: FEFO lots first when lot-tracked (throws when
     * the lots fall short), then the global + warehouse quantities and
     * the stock ledger. Same composition as the production order
     * completion, with the company id explicit.
     */
    protected function deductStock(
        int $productId,
        float $quantity,
        int $warehouseId,
        int $creatorId,
        string $reportType,
        string $description,
        int $typeId,
    ): void {
        if ($quantity <= 0) {
            return;
        }

        $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->firstOrFail();

        if (($product->batch_tracking ?? false) || BatchLotDeductionService::hasLots($productId, $warehouseId, $creatorId)) {
            $remaining = BatchLotDeductionService::deductLots($productId, $quantity, $creatorId, $warehouseId);
            if ($remaining > 0) {
                throw new InvalidArgumentException(__('Not enough :product in the kitchen warehouse lots: short by :short.', [
                    'product' => (string) $product->name,
                    'short' => round($remaining, 4),
                ]));
            }
        }

        $original = (float) ($product->quantity ?? 0);
        $newQty = $product->allowsNegativeStock() ? $original - $quantity : max(0, $original - $quantity);
        ProductService::where('id', $productId)->update(['quantity' => $newQty]);

        $this->adjustWarehouseStock($productId, $warehouseId, $creatorId, -$quantity, $product->allowsNegativeStock());
        $this->recordStockReport($productId, $quantity, $reportType, $description, $typeId, $creatorId);
    }

    /** Put stock back: global + warehouse quantities + stock ledger. */
    protected function addStock(
        int $productId,
        float $quantity,
        int $warehouseId,
        int $creatorId,
        string $reportType,
        string $description,
        int $typeId,
    ): void {
        if ($quantity <= 0) {
            return;
        }

        $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->firstOrFail();

        ProductService::where('id', $productId)->update(['quantity' => (float) ($product->quantity ?? 0) + $quantity]);
        $this->adjustWarehouseStock($productId, $warehouseId, $creatorId, $quantity, true);
        $this->recordStockReport($productId, $quantity, $reportType, $description, $typeId, $creatorId);
    }

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

    protected function recordStockReport(int $productId, float $quantity, string $type, string $description, int $typeId, int $creatorId): void
    {
        // created_by is NOT in StockReport::$fillable — assign it as a
        // property, like Utility::addProductStock does.
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

    protected function assertSameCompany($record, User $user): void
    {
        if ((int) $record->created_by !== (int) $user->creatorId()) {
            throw new InvalidArgumentException(__('This buffet event belongs to another company.'));
        }
    }
}
