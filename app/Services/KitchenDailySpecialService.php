<?php

namespace App\Services;

use App\Http\Controllers\ProductServiceController;
use App\Models\KitchenDailySpecial;
use App\Models\KitchenRecipe;
use App\Models\ProductRecipe;
use App\Models\ProductService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Kitchen Production — Daily Specials / plate of the day (Phase 4,
 * plan §3/§5, decision D3).
 *
 * The client model (Danilo, voice note 2026-11-09): the plate of the
 * day depends on what the kitchen has in stock that day. The chef
 * plans per WEEK (and per month): Monday–Friday have a plate of the
 * day, weekends do not; 2–3 plates per day, about 20 portions each
 * (it varies per client — some have 2–3, some 5 or more). And when a
 * guest walks in and asks for something NOT on the menu (the client's
 * example: boiled fish — fish, water and salt), the chef must be able
 * to make that dish up on the spot, entering its ingredients at
 * creation, so it is sellable right away.
 *
 * How a special is a normal POS dish for its date only: every special
 * gets its OWN product row. Its sale_price IS the special price, and
 * it is hidden from the POS (show_on_pos = 0) — the POS listing and
 * the add-to-cart guard both let it through only while an active
 * special exists for that product TODAY (see isSellableToday() and
 * ProductService::scopeSellableOnPos). Tomorrow the product is
 * invisible again; nothing on the menu product is ever mutated.
 * Its ingredients live in the normal ProductRecipe lines, so a sale
 * deducts them through the existing POS ladder (Phase 2) — the same
 * path as any other dish, FEFO when lot-tracked.
 *
 * Statuses: active -> expired (the date passed) or cancelled. Old
 * actives are marked expired lazily when the list is opened — no
 * scheduler is needed, because sellability is always decided by
 * (active AND date = today).
 */
class KitchenDailySpecialService
{
    public function __construct(
        protected KitchenUnitConversionService $conversions,
        protected KitchenPosSaleService $posSale,
    ) {
    }

    // -----------------------------------------------------------------
    // Create
    // -----------------------------------------------------------------

    /**
     * Create one special from an existing menu dish: a dedicated
     * product row is made for the date (name + date, the special
     * price), the dish's recipe lines are copied onto it, and the
     * special is active for that date.
     *
     * @param array{special_date: string, source_product_id: int, quantity_planned: float, selling_price?: float|null, notes?: string|null} $data
     */
    public function createFromDish(array $data, User $user): KitchenDailySpecial
    {
        $creatorId = (int) $user->creatorId();
        $date = $this->validateDate($data['special_date'] ?? '');
        $quantity = $this->validateQuantity($data['quantity_planned'] ?? 0);

        $dish = ProductService::where('id', (int) ($data['source_product_id'] ?? 0))
            ->where('created_by', $creatorId)
            ->first();
        if (! $dish) {
            throw new InvalidArgumentException(__('The dish does not exist.'));
        }
        if ((string) ($dish->type ?? '') !== 'product') {
            throw new InvalidArgumentException(__('A daily special must be a dish (product), not an ingredient or a kit.'));
        }

        $price = isset($data['selling_price']) && $data['selling_price'] !== '' && $data['selling_price'] !== null
            ? (float) $data['selling_price']
            : (float) ($dish->sale_price ?? 0);
        if ($price <= 0) {
            throw new InvalidArgumentException(__('The special needs a selling price.'));
        }

        return DB::transaction(function () use ($data, $user, $creatorId, $date, $quantity, $dish, $price) {
            $this->assertNoDuplicate(null, (int) $dish->id, $date, $creatorId);

            $product = $this->createSpecialProduct(
                $dish->name.' — '.$date->format('d M Y'),
                $price,
                $dish,
                $creatorId
            );

            // Copy the dish's recipe lines onto the special product,
            // so the POS ladder deducts the same ingredients.
            $recipeCost = $this->copyRecipeLines((int) $dish->id, (int) $product->id, $creatorId);
            if ($recipeCost > 0) {
                $product->update(['purchase_price' => $recipeCost]);
            }

            $special = KitchenDailySpecial::create([
                'special_date' => $date->format('Y-m-d'),
                'product_id' => (int) $product->id,
                'kitchen_recipe_id' => $this->approvedRecipeIdFor((int) $dish->id, $creatorId),
                'source_product_id' => (int) $dish->id,
                'quantity_planned' => $quantity,
                'selling_price' => $price,
                'status' => KitchenDailySpecial::STATUS_ACTIVE,
                'is_on_the_spot' => false,
                'notes' => $data['notes'] ?? null,
                'created_by' => $creatorId,
            ]);

            $this->bumpPosCache($creatorId);

            return $special->fresh();
        });
    }

    /**
     * Create an on-the-spot special: a dish made up for one guest,
     * not on the menu. The chef enters the name, the price and the
     * ingredients at creation; the dish is sellable immediately.
     *
     * @param array{special_date: string, name: string, quantity_planned: float, selling_price: float, unit_id?: int|null, notes?: string|null, ingredients: array<int, array{ingredient_product_id: int, quantity: float, unit_id?: int|null}>} $data
     */
    public function createOnTheSpot(array $data, User $user): KitchenDailySpecial
    {
        $creatorId = (int) $user->creatorId();
        $date = $this->validateDate($data['special_date'] ?? '');
        $quantity = $this->validateQuantity($data['quantity_planned'] ?? 0);

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException(__('Give the special dish a name.'));
        }

        $price = (float) ($data['selling_price'] ?? 0);
        if ($price <= 0) {
            throw new InvalidArgumentException(__('The special needs a selling price.'));
        }

        $ingredients = $this->validateIngredients($data['ingredients'] ?? [], $creatorId);

        return DB::transaction(function () use ($data, $user, $creatorId, $date, $quantity, $name, $price, $ingredients) {
            $product = $this->createSpecialProduct(
                $name.' — '.$date->format('d M Y'),
                $price,
                null,
                $creatorId,
                ! empty($data['unit_id']) ? (int) $data['unit_id'] : null
            );

            foreach ($ingredients as $ingredient) {
                ProductRecipe::create([
                    'product_id' => (int) $product->id,
                    'kitchen_recipe_id' => null,
                    'version' => 1,
                    'ingredient_product_id' => (int) $ingredient['ingredient_product_id'],
                    'quantity' => $ingredient['quantity'],
                    'loss_percent' => 0,
                    'unit' => null,
                    'unit_id' => $ingredient['unit_id'],
                    'created_by' => $creatorId,
                ]);
            }

            $special = KitchenDailySpecial::create([
                'special_date' => $date->format('Y-m-d'),
                'product_id' => (int) $product->id,
                'kitchen_recipe_id' => null,
                'source_product_id' => null,
                'quantity_planned' => $quantity,
                'selling_price' => $price,
                'status' => KitchenDailySpecial::STATUS_ACTIVE,
                'is_on_the_spot' => true,
                'notes' => $data['notes'] ?? null,
                'created_by' => $creatorId,
            ]);

            $this->bumpPosCache($creatorId);

            return $special->fresh();
        });
    }

    /**
     * Plan a whole week at once: the chef picks a week (Monday) and,
     * for each day, the specials for that day (dish or on-the-spot).
     * Weekends are allowed — the form simply leaves them empty by
     * default. Any week of any month can be planned.
     *
     * @param array{week_start: string, days: array<string, array<int, array{source_product_id?: int|null, name?: string|null, quantity_planned: float, selling_price?: float|null, unit_id?: int|null, ingredients?: array<int, array{ingredient_product_id: int, quantity: float, unit_id?: int|null}>}>>} $data
     * @return array<int, KitchenDailySpecial>
     */
    public function planWeek(array $data, User $user): array
    {
        $weekStart = $this->validateDate($data['week_start'] ?? '');
        if ((int) $weekStart->format('N') !== 1) {
            throw new InvalidArgumentException(__('The week must start on a Monday.'));
        }

        $days = $data['days'] ?? [];
        if (! is_array($days) || $days === []) {
            throw new InvalidArgumentException(__('Plan at least one special for the week.'));
        }

        $created = [];
        foreach ($days as $dateString => $specials) {
            $date = $this->validateDate((string) $dateString);
            $weekEnd = (clone $weekStart)->modify('+6 days');
            if ($date < $weekStart || $date > $weekEnd) {
                throw new InvalidArgumentException(__('Every special must fall inside the planned week.'));
            }
            if (! is_array($specials)) {
                continue;
            }
            foreach ($specials as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $row['special_date'] = $date->format('Y-m-d');
                if (! empty($row['source_product_id'])) {
                    $created[] = $this->createFromDish($row, $user);
                } elseif (! empty($row['name'])) {
                    $created[] = $this->createOnTheSpot($row, $user);
                }
            }
        }

        if ($created === []) {
            throw new InvalidArgumentException(__('Plan at least one special for the week.'));
        }

        return $created;
    }

    // -----------------------------------------------------------------
    // Cancel / expiry / sellability
    // -----------------------------------------------------------------

    public function cancel(KitchenDailySpecial $special, User $user): KitchenDailySpecial
    {
        $this->assertSameCompany($special, $user);

        if (! $special->isActive()) {
            throw new InvalidArgumentException(__('Only an active special can be cancelled.'));
        }

        $special->status = KitchenDailySpecial::STATUS_CANCELLED;
        $special->save();

        $this->bumpPosCache((int) $special->created_by);

        return $special->fresh();
    }

    /** Mark past-date actives as expired (lazy: no scheduler needed). */
    public function markExpired(int $creatorId, ?string $today = null): int
    {
        $today = $today ?? date('Y-m-d');

        return KitchenDailySpecial::where('created_by', $creatorId)
            ->where('status', KitchenDailySpecial::STATUS_ACTIVE)
            ->whereDate('special_date', '<', $today)
            ->update(['status' => KitchenDailySpecial::STATUS_EXPIRED]);
    }

    /**
     * Is this product sellable on the POS on a date (today by
     * default)? Only while an active special exists for it on that
     * date. False when the specials table does not exist yet (an
     * install before the Phase 4 migration) — never fatal.
     */
    public function isSellableToday(int $productId, int $creatorId, ?string $date = null): bool
    {
        if ($productId <= 0 || $creatorId <= 0) {
            return false;
        }
        if (! Schema::hasTable('kitchen_daily_specials')) {
            return false;
        }

        $date = $date ?? date('Y-m-d');

        return KitchenDailySpecial::where('created_by', $creatorId)
            ->where('product_id', $productId)
            ->where('status', KitchenDailySpecial::STATUS_ACTIVE)
            ->whereDate('special_date', $date)
            ->exists();
    }

    /** Today's specials for the POS grid / the kitchen screen. */
    public function todaysSpecials(int $creatorId, ?string $date = null)
    {
        $date = $date ?? date('Y-m-d');

        return KitchenDailySpecial::where('created_by', $creatorId)
            ->where('status', KitchenDailySpecial::STATUS_ACTIVE)
            ->whereDate('special_date', $date)
            ->with('product')
            ->orderBy('id')
            ->get();
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    protected function validateDate(string $value): \DateTimeImmutable
    {
        if ($value === '' || strtotime($value) === false) {
            throw new InvalidArgumentException(__('Select a valid date.'));
        }

        return new \DateTimeImmutable(date('Y-m-d', strtotime($value)));
    }

    protected function validateQuantity(mixed $value): float
    {
        $quantity = (float) $value;
        if ($quantity <= 0) {
            throw new InvalidArgumentException(__('The planned quantity must be greater than zero.'));
        }

        return round($quantity, 4);
    }

    /**
     * Ingredients entered for an on-the-spot dish: each must exist,
     * have a positive quantity, and is converted to the ingredient's
     * stock unit (the POS ladder reads lines in stock units).
     *
     * @return array<int, array{ingredient_product_id: int, quantity: float, unit_id: int|null}>
     */
    protected function validateIngredients(array $ingredients, int $creatorId): array
    {
        if ($ingredients === []) {
            throw new InvalidArgumentException(__('An on-the-spot dish needs at least one ingredient.'));
        }

        $validated = [];
        $seen = [];
        foreach ($ingredients as $row) {
            if (! is_array($row)) {
                continue;
            }
            $ingredient = ProductService::where('id', (int) ($row['ingredient_product_id'] ?? 0))
                ->where('created_by', $creatorId)
                ->first();
            if (! $ingredient) {
                throw new InvalidArgumentException(__('An ingredient does not exist.'));
            }
            if (in_array((int) $ingredient->id, $seen, true)) {
                throw new InvalidArgumentException(__('Each ingredient can appear once per dish.'));
            }
            $seen[] = (int) $ingredient->id;

            $quantity = (float) ($row['quantity'] ?? 0);
            if ($quantity <= 0) {
                throw new InvalidArgumentException(__('Each ingredient needs a quantity greater than zero.'));
            }

            $stockUnitId = (int) ($ingredient->unit_id ?? 0);
            $enteredUnitId = ! empty($row['unit_id']) ? (int) $row['unit_id'] : $stockUnitId;
            if ($stockUnitId > 0 && $enteredUnitId > 0 && $enteredUnitId !== $stockUnitId) {
                $quantity = $this->conversions->convert($quantity, $enteredUnitId, $stockUnitId, $creatorId, (int) $ingredient->id);
            }
            $quantity = round($quantity, 4);
            if ($quantity <= 0) {
                throw new InvalidArgumentException(__('Each ingredient needs a quantity greater than zero.'));
            }

            $validated[] = [
                'ingredient_product_id' => (int) $ingredient->id,
                'quantity' => $quantity,
                'unit_id' => $stockUnitId > 0 ? $stockUnitId : null,
            ];
        }

        if ($validated === []) {
            throw new InvalidArgumentException(__('An on-the-spot dish needs at least one ingredient.'));
        }

        return $validated;
    }

    protected function assertNoDuplicate(?int $specialId, int $productId, \DateTimeImmutable $date, int $creatorId): void
    {
        $query = KitchenDailySpecial::where('created_by', $creatorId)
            ->where('product_id', $productId)
            ->whereDate('special_date', $date->format('Y-m-d'))
            ->where('status', KitchenDailySpecial::STATUS_ACTIVE);
        if ($specialId !== null) {
            $query->where('id', '!=', $specialId);
        }
        if ($query->exists()) {
            throw new InvalidArgumentException(__('That dish already has a special on this date.'));
        }
    }

    /**
     * The dedicated product row for a special: a normal sellable dish
     * (type product) with the special price, hidden from the POS
     * except on its date. The purchase price is the recipe cost when
     * it can be computed from the copied lines (set by the caller via
     * updateRecipeCost()).
     */
    protected function createSpecialProduct(
        string $name,
        float $price,
        ?ProductService $template,
        int $creatorId,
        ?int $unitId = null,
    ): ProductService {
        return ProductService::create([
            'name' => $name,
            'sku' => 'SP-'.date('YmdHis').'-'.random_int(100, 999),
            'sale_price' => $price,
            'sale_price_type' => 'exclusive',
            'purchase_price' => 0,
            'tax_id' => $template ? $template->tax_id : null,
            'category_id' => $template ? $template->category_id : null,
            'unit_id' => $unitId ?? ($template ? $template->unit_id : null),
            'type' => 'product',
            'show_on_pos' => 0,
            'affect_stock' => true,
            'batch_tracking' => false,
            'created_by' => $creatorId,
        ]);
    }

    protected function copyRecipeLines(int $sourceProductId, int $targetProductId, int $creatorId): float
    {
        $cost = 0.0;
        $rows = ProductRecipe::where('product_id', $sourceProductId)
            ->where('created_by', $creatorId)
            ->get();
        foreach ($rows as $row) {
            ProductRecipe::create([
                'product_id' => $targetProductId,
                'kitchen_recipe_id' => null,
                'version' => 1,
                'ingredient_product_id' => (int) $row->ingredient_product_id,
                'quantity' => (float) $row->quantity,
                'loss_percent' => (float) ($row->loss_percent ?? 0),
                'unit' => $row->unit,
                'unit_id' => $row->unit_id,
                'created_by' => $creatorId,
            ]);
            $ingredient = ProductService::where('id', (int) $row->ingredient_product_id)
                ->where('created_by', $creatorId)
                ->first();
            if ($ingredient) {
                $cost += (float) $row->quantity * (float) ($ingredient->purchase_price ?? 0);
            }
        }

        return round($cost, 2);
    }

    protected function approvedRecipeIdFor(int $productId, int $creatorId): ?int
    {
        $recipe = $this->posSale->approvedRecipeForProduct($productId, $creatorId);

        return $recipe ? (int) $recipe->id : null;
    }

    /** Bump the POS product-grid cache version so a new / cancelled special shows at once. */
    protected function bumpPosCache(int $creatorId): void
    {
        ProductServiceController::bumpPosProductsCache($creatorId);
    }

    protected function assertSameCompany($record, User $user): void
    {
        if ((int) $record->created_by !== (int) $user->creatorId()) {
            throw new InvalidArgumentException(__('This special belongs to another company.'));
        }
    }
}
