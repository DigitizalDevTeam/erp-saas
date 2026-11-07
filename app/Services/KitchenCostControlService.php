<?php

namespace App\Services;

use App\Models\KitchenCostAlert;
use App\Models\KitchenCostSnapshot;
use App\Models\KitchenProductionOrder;
use App\Models\KitchenRecipe;
use App\Models\ProductService;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Kitchen Production — Cost control (Phase 6, plan §7).
 *
 * What lives here:
 * - Food-cost bands (Danilo, decided 2026-11-06): below 25% and 25–28%
 *   excellent, 29–32% good, 33–36% good-, 37–41% bad, over 41% very
 *   bad. The band function itself stays in KitchenRecipeCostService;
 *   this service adds the band RANK (for worse-than-planned) and the
 *   alert rule (bad / very bad).
 * - Cost per dish: expected cost from the latest approved recipe
 *   version vs actual cost from the latest completed order, per period.
 * - Variance vs plan: a completed order's actual cost vs its planned
 *   cost (snapshotted at creation), with a flag when the actual band
 *   is worse than the planned band.
 * - Cost history: snapshots written at approval time (recipe) and at
 *   completion time (order), so a later price change never rewrites
 *   the trend — the chef sees WHY a dish moved bands.
 * - Alerts: a record the manager sees and acknowledges (no email in
 *   this phase). Order alerts fire at completion; dish alerts are
 *   raised by the manager's refresh, because a recipe cost moves when
 *   ingredient prices move and there is no single event that fires it.
 *
 * Guard note: the alerts / snapshots tables may not exist in a test
 * that builds only the Phase 0–5 schema (or on a database the Phase 6
 * migration has not run on yet). Every method that touches those
 * tables checks Schema::hasTable() on each call — never memoized, the
 * same pattern Phase 2 uses for the kitchen_losses table.
 */
class KitchenCostControlService
{
    /** Band rank: the higher the worse. Used for worse-than-planned. */
    public const BAND_RANKS = [
        KitchenRecipeCostService::BAND_EXCELLENT => 0,
        KitchenRecipeCostService::BAND_GOOD => 1,
        KitchenRecipeCostService::BAND_GOOD_MINUS => 2,
        KitchenRecipeCostService::BAND_BAD => 3,
        KitchenRecipeCostService::BAND_VERY_BAD => 4,
    ];

    public function __construct(
        protected KitchenUnitConversionService $conversions,
        protected KitchenRecipeCostService $costs,
    ) {
    }

    public static function bandRank(?string $band): ?int
    {
        if ($band === null) {
            return null;
        }

        return self::BAND_RANKS[$band] ?? null;
    }

    /** A band that fires an alert: bad or very bad. */
    public static function bandIsAlert(?string $band): bool
    {
        return $band === KitchenRecipeCostService::BAND_BAD
            || $band === KitchenRecipeCostService::BAND_VERY_BAD;
    }

    /**
     * Planned food cost % / band for a production order, from the
     * planLines() result: planned portions = portions per batch x
     * scale, cost per portion = planned cost / planned portions.
     *
     * @param array{portions: float|null, scale: float, planned_cost: float} $planned
     * @return array{percent: float|null, band: string|null}
     */
    public function plannedFoodCost(KitchenRecipe $recipe, array $planned): array
    {
        $portions = ($planned['portions'] ?? null) !== null && ($planned['scale'] ?? 0) > 0
            ? (float) $planned['portions'] * (float) $planned['scale']
            : null;

        if ($portions === null || $portions <= 0) {
            return ['percent' => null, 'band' => null];
        }

        $costPerPortion = round((float) $planned['planned_cost'] / $portions, 2);
        $dish = $recipe->product;
        $sellingPriceExVat = $dish instanceof ProductService
            ? (float) $dish->exclusiveUnitPrice((float) ($dish->sale_price ?? 0))
            : 0.0;

        if ($sellingPriceExVat <= 0) {
            return ['percent' => null, 'band' => null];
        }

        $percent = round(($costPerPortion / $sellingPriceExVat) * 100, 2);

        return [
            'percent' => $percent,
            'band' => KitchenRecipeCostService::bandForFoodCostPercent($percent),
        ];
    }

    /**
     * One row per dish (product with a latest approved recipe):
     * expected cost from the recipe, actual cost from the latest
     * completed order, both food-cost %s and bands.
     *
     * @param array{from?: string|null, to?: string|null, branch_id?: int|null, search?: string|null} $filters
     * @return array<int, array{product: ProductService, recipe: KitchenRecipe, expected: array, latest_order: KitchenProductionOrder|null, actual_cost_per_portion: float|null, actual_food_cost_percent: float|null, actual_band: string|null, completed_orders_in_period: int, average_food_cost_percent: float|null, alert: bool}>
     */
    public function dishCosts(int $creatorId, array $filters = []): array
    {
        $recipes = KitchenRecipe::where('created_by', $creatorId)
            ->where('status', KitchenRecipe::STATUS_APPROVED)
            ->with('product')
            ->orderBy('product_id')
            ->orderByDesc('version')
            ->get();

        // Latest approved version per dish.
        $latest = [];
        foreach ($recipes as $recipe) {
            $productId = (int) $recipe->product_id;
            if (! isset($latest[$productId])) {
                $latest[$productId] = $recipe;
            }
        }

        $rows = [];
        foreach ($latest as $productId => $recipe) {
            $dish = $recipe->product;
            if (! $dish instanceof ProductService) {
                continue;
            }

            if (! empty($filters['branch_id']) && (int) ($recipe->branch_id ?? 0) !== (int) $filters['branch_id']) {
                continue;
            }

            if (! empty($filters['search']) && stripos((string) $dish->name, (string) $filters['search']) === false) {
                continue;
            }

            try {
                $expected = $this->costs->calculate($recipe);
            } catch (\Throwable) {
                // A dish whose recipe cannot be costed (missing
                // conversion) is shown without an expected cost rather
                // than breaking the whole report.
                $expected = [
                    'recipe_cost' => null,
                    'portions' => null,
                    'cost_per_portion' => null,
                    'selling_price_ex_vat' => (float) $dish->exclusiveUnitPrice((float) ($dish->sale_price ?? 0)),
                    'food_cost_percent' => null,
                    'band' => null,
                ];
            }

            $ordersQuery = KitchenProductionOrder::where('created_by', $creatorId)
                ->where('kitchen_recipe_id', (int) $recipe->id)
                ->whereIn('status', [KitchenProductionOrder::STATUS_COMPLETED, KitchenProductionOrder::STATUS_VALIDATED]);

            if (! empty($filters['branch_id'])) {
                $ordersQuery->where('branch_id', (int) $filters['branch_id']);
            }

            $periodOrders = (clone $ordersQuery)
                ->when(! empty($filters['from']), fn ($q) => $q->whereDate('completed_at', '>=', $filters['from']))
                ->when(! empty($filters['to']), fn ($q) => $q->whereDate('completed_at', '<=', $filters['to']))
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->get();

            $latestOrder = $periodOrders->first();
            $percents = $periodOrders
                ->map(fn ($o) => $o->food_cost_percent !== null ? (float) $o->food_cost_percent : null)
                ->filter(fn ($p) => $p !== null);

            $rows[] = [
                'product' => $dish,
                'recipe' => $recipe,
                'expected' => $expected,
                'latest_order' => $latestOrder,
                'actual_cost_per_portion' => $latestOrder && $latestOrder->cost_per_portion !== null
                    ? (float) $latestOrder->cost_per_portion
                    : null,
                'actual_food_cost_percent' => $latestOrder && $latestOrder->food_cost_percent !== null
                    ? (float) $latestOrder->food_cost_percent
                    : null,
                'actual_band' => $latestOrder ? $latestOrder->food_cost_band : null,
                'completed_orders_in_period' => $periodOrders->count(),
                'average_food_cost_percent' => $percents->isNotEmpty()
                    ? round($percents->avg(), 2)
                    : null,
                'alert' => self::bandIsAlert($expected['band'] ?? null)
                    || self::bandIsAlert($latestOrder ? $latestOrder->food_cost_band : null),
            ];
        }

        return $rows;
    }

    /**
     * Variance of one completed order vs its plan.
     *
     * @return array{planned_cost: float, actual_cost: float|null, variance: float|null, variance_percent: float|null, planned_food_cost_percent: float|null, planned_band: string|null, actual_food_cost_percent: float|null, actual_band: string|null, worse_than_planned: bool, alert: bool}
     */
    public function orderVariance(KitchenProductionOrder $order): array
    {
        $plannedCost = (float) ($order->planned_cost ?? 0);
        $actualCost = $order->actual_cost !== null ? (float) $order->actual_cost : null;
        $variance = $actualCost !== null ? round($actualCost - $plannedCost, 2) : null;
        $variancePercent = $variance !== null && $plannedCost > 0
            ? round(($variance / $plannedCost) * 100, 2)
            : null;

        $plannedBand = $order->planned_food_cost_band;
        $actualBand = $order->food_cost_band;
        $worse = $plannedBand !== null && $actualBand !== null
            && self::bandRank($actualBand) !== null
            && self::bandRank($plannedBand) !== null
            && self::bandRank($actualBand) > self::bandRank($plannedBand);

        return [
            'planned_cost' => $plannedCost,
            'actual_cost' => $actualCost,
            'variance' => $variance,
            'variance_percent' => $variancePercent,
            'planned_food_cost_percent' => $order->planned_food_cost_percent !== null ? (float) $order->planned_food_cost_percent : null,
            'planned_band' => $plannedBand,
            'actual_food_cost_percent' => $order->food_cost_percent !== null ? (float) $order->food_cost_percent : null,
            'actual_band' => $actualBand,
            'worse_than_planned' => $worse,
            'alert' => self::bandIsAlert($actualBand) || $worse,
        ];
    }

    /**
     * Completed orders grouped by week or month: totals, variance and
     * how many dishes landed in a bad / very bad band.
     *
     * @return array<int, array{label: string, orders: int, planned_cost: float, actual_cost: float, variance: float, average_food_cost_percent: float|null, bad_dishes: int}>
     */
    public function periodSummary(int $creatorId, string $from, string $to, string $group = 'month'): array
    {
        $orders = KitchenProductionOrder::where('created_by', $creatorId)
            ->whereIn('status', [KitchenProductionOrder::STATUS_COMPLETED, KitchenProductionOrder::STATUS_VALIDATED])
            ->whereDate('completed_at', '>=', $from)
            ->whereDate('completed_at', '<=', $to)
            ->orderBy('completed_at')
            ->get();

        $groups = [];
        foreach ($orders as $order) {
            $completedAt = $order->completed_at;
            if ($completedAt === null) {
                continue;
            }

            if ($group === 'week') {
                $key = $completedAt->copy()->startOfWeek()->format('Y-m-d');
                $label = __('Week of :date', ['date' => $completedAt->copy()->startOfWeek()->format('d M Y')]);
            } else {
                $key = $completedAt->format('Y-m');
                $label = $completedAt->format('F Y');
            }

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'label' => $label,
                    'orders' => 0,
                    'planned_cost' => 0.0,
                    'actual_cost' => 0.0,
                    'percents' => [],
                    'bad_dishes' => 0,
                ];
            }

            $groups[$key]['orders']++;
            $groups[$key]['planned_cost'] += (float) ($order->planned_cost ?? 0);
            $groups[$key]['actual_cost'] += (float) ($order->actual_cost ?? 0);
            if ($order->food_cost_percent !== null) {
                $groups[$key]['percents'][] = (float) $order->food_cost_percent;
            }
            if (self::bandIsAlert($order->food_cost_band)) {
                $groups[$key]['bad_dishes']++;
            }
        }

        ksort($groups);

        return array_values(array_map(function (array $g) {
            return [
                'label' => $g['label'],
                'orders' => $g['orders'],
                'planned_cost' => round($g['planned_cost'], 2),
                'actual_cost' => round($g['actual_cost'], 2),
                'variance' => round($g['actual_cost'] - $g['planned_cost'], 2),
                'average_food_cost_percent' => $g['percents'] !== []
                    ? round(array_sum($g['percents']) / count($g['percents']), 2)
                    : null,
                'bad_dishes' => $g['bad_dishes'],
            ];
        }, $groups));
    }

    /**
     * Fire alerts for a completed order: bad / very bad actual band,
     * and actual band worse than the planned band. One open alert per
     * (order, type) — calling this twice for the same order creates
     * nothing new.
     *
     * @return KitchenCostAlert[] the alerts created by this call
     */
    public function checkOrder(KitchenProductionOrder $order): array
    {
        if (! Schema::hasTable('kitchen_cost_alerts')) {
            return [];
        }

        $created = [];
        $variance = $this->orderVariance($order);
        $actualBand = $order->food_cost_band;

        $types = [];
        if ($actualBand === KitchenRecipeCostService::BAND_BAD) {
            $types[] = KitchenCostAlert::TYPE_BAND_BAD;
        } elseif ($actualBand === KitchenRecipeCostService::BAND_VERY_BAD) {
            $types[] = KitchenCostAlert::TYPE_BAND_VERY_BAD;
        }
        if ($variance['worse_than_planned']) {
            $types[] = KitchenCostAlert::TYPE_WORSE_THAN_PLANNED;
        }

        foreach ($types as $type) {
            $existing = KitchenCostAlert::where('created_by', (int) $order->created_by)
                ->where('kitchen_production_order_id', (int) $order->id)
                ->where('alert_type', $type)
                ->where('status', KitchenCostAlert::STATUS_OPEN)
                ->first();
            if ($existing) {
                continue;
            }

            $created[] = KitchenCostAlert::create([
                'alert_type' => $type,
                'product_id' => (int) $order->recipe->product_id,
                'kitchen_recipe_id' => (int) $order->kitchen_recipe_id,
                'kitchen_production_order_id' => (int) $order->id,
                'food_cost_percent' => $order->food_cost_percent,
                'band' => $actualBand,
                'planned_food_cost_percent' => $order->planned_food_cost_percent,
                'planned_food_cost_band' => $order->planned_food_cost_band,
                'status' => KitchenCostAlert::STATUS_OPEN,
                'created_by' => (int) $order->created_by,
            ]);
        }

        return $created;
    }

    /**
     * Raise dish-level alerts for dishes whose CURRENT expected band
     * is bad / very bad (recipe cost at today's prices). One open
     * alert per (product, type).
     *
     * @return KitchenCostAlert[] the alerts created by this call
     */
    public function refreshDishAlerts(int $creatorId): array
    {
        if (! Schema::hasTable('kitchen_cost_alerts')) {
            return [];
        }

        $created = [];
        foreach ($this->dishCosts($creatorId) as $row) {
            $band = $row['expected']['band'] ?? null;
            if ($band === KitchenRecipeCostService::BAND_BAD) {
                $type = KitchenCostAlert::TYPE_DISH_BAND_BAD;
            } elseif ($band === KitchenRecipeCostService::BAND_VERY_BAD) {
                $type = KitchenCostAlert::TYPE_DISH_BAND_VERY_BAD;
            } else {
                continue;
            }

            $existing = KitchenCostAlert::where('created_by', $creatorId)
                ->where('product_id', (int) $row['product']->id)
                ->where('alert_type', $type)
                ->where('status', KitchenCostAlert::STATUS_OPEN)
                ->first();
            if ($existing) {
                continue;
            }

            $created[] = KitchenCostAlert::create([
                'alert_type' => $type,
                'product_id' => (int) $row['product']->id,
                'kitchen_recipe_id' => (int) $row['recipe']->id,
                'kitchen_production_order_id' => null,
                'food_cost_percent' => $row['expected']['food_cost_percent'] ?? null,
                'band' => $band,
                'planned_food_cost_percent' => null,
                'planned_food_cost_band' => null,
                'status' => KitchenCostAlert::STATUS_OPEN,
                'created_by' => $creatorId,
            ]);
        }

        return $created;
    }

    /**
     * Snapshot a recipe version's expected cost at approval time.
     * Idempotent per (recipe, version, source).
     */
    public function recordRecipeSnapshot(KitchenRecipe $recipe): ?KitchenCostSnapshot
    {
        if (! Schema::hasTable('kitchen_cost_snapshots')) {
            return null;
        }

        $existing = KitchenCostSnapshot::where('created_by', (int) $recipe->created_by)
            ->where('kitchen_recipe_id', (int) $recipe->id)
            ->where('version', (int) $recipe->version)
            ->where('source', KitchenCostSnapshot::SOURCE_RECIPE_APPROVED)
            ->first();
        if ($existing) {
            return $existing;
        }

        $calculated = $this->costs->calculate($recipe);

        return KitchenCostSnapshot::create([
            'kitchen_recipe_id' => (int) $recipe->id,
            'version' => (int) $recipe->version,
            'kitchen_production_order_id' => null,
            'source' => KitchenCostSnapshot::SOURCE_RECIPE_APPROVED,
            'recipe_cost' => $calculated['recipe_cost'],
            'cost_per_portion' => $calculated['cost_per_portion'],
            'selling_price_ex_vat' => $calculated['selling_price_ex_vat'],
            'food_cost_percent' => $calculated['food_cost_percent'],
            'band' => $calculated['band'],
            'snapshot_date' => date('Y-m-d'),
            'created_by' => (int) $recipe->created_by,
        ]);
    }

    /**
     * Snapshot a completed order's actual cost. Idempotent per order.
     */
    public function recordOrderSnapshot(KitchenProductionOrder $order): ?KitchenCostSnapshot
    {
        if (! Schema::hasTable('kitchen_cost_snapshots')) {
            return null;
        }

        $existing = KitchenCostSnapshot::where('created_by', (int) $order->created_by)
            ->where('kitchen_production_order_id', (int) $order->id)
            ->where('source', KitchenCostSnapshot::SOURCE_ORDER_COMPLETED)
            ->first();
        if ($existing) {
            return $existing;
        }

        $dish = $order->recipe ? $order->recipe->product : null;
        $sellingPriceExVat = $dish instanceof ProductService
            ? (float) $dish->exclusiveUnitPrice((float) ($dish->sale_price ?? 0))
            : null;

        return KitchenCostSnapshot::create([
            'kitchen_recipe_id' => (int) $order->kitchen_recipe_id,
            'version' => (int) $order->recipe_version,
            'kitchen_production_order_id' => (int) $order->id,
            'source' => KitchenCostSnapshot::SOURCE_ORDER_COMPLETED,
            'recipe_cost' => $order->actual_cost,
            'cost_per_portion' => $order->cost_per_portion,
            'selling_price_ex_vat' => $sellingPriceExVat,
            'food_cost_percent' => $order->food_cost_percent,
            'band' => $order->food_cost_band,
            'snapshot_date' => $order->completed_at ? $order->completed_at->format('Y-m-d') : date('Y-m-d'),
            'created_by' => (int) $order->created_by,
        ]);
    }

    /** Acknowledge an open alert (the manager has seen it). */
    public function acknowledgeAlert(KitchenCostAlert $alert, User $user): KitchenCostAlert
    {
        if ((int) $alert->created_by !== (int) $user->creatorId()) {
            throw new InvalidArgumentException(__('This alert belongs to another company.'));
        }

        if (! $alert->isOpen()) {
            throw new InvalidArgumentException(__('Only an open alert can be acknowledged.'));
        }

        $alert->status = KitchenCostAlert::STATUS_ACKNOWLEDGED;
        $alert->acknowledged_by = (int) $user->id;
        $alert->acknowledged_at = now();
        $alert->save();

        return $alert->fresh();
    }

    /**
     * Cost history for one dish: recipe-approval snapshots and
     * completed-order snapshots, newest first — the trend that shows
     * WHY a dish moved bands (a price change moves the next snapshot,
     * never the ones already written).
     *
     * @return array<int, array{date: string, source: string, label: string, cost_per_portion: float|null, food_cost_percent: float|null, band: string|null}>
     */
    public function costHistory(int $creatorId, int $productId): array
    {
        if (! Schema::hasTable('kitchen_cost_snapshots')) {
            return [];
        }

        $recipeIds = KitchenRecipe::where('created_by', $creatorId)
            ->where('product_id', $productId)
            ->pluck('id')
            ->all();

        if ($recipeIds === []) {
            return [];
        }

        $snapshots = KitchenCostSnapshot::where('created_by', $creatorId)
            ->whereIn('kitchen_recipe_id', $recipeIds)
            ->with('order')
            ->orderByDesc('snapshot_date')
            ->orderByDesc('id')
            ->get();

        $history = [];
        foreach ($snapshots as $snapshot) {
            $history[] = [
                'date' => $snapshot->snapshot_date ? $snapshot->snapshot_date->format('Y-m-d') : '',
                'source' => $snapshot->source,
                'label' => $snapshot->source === KitchenCostSnapshot::SOURCE_RECIPE_APPROVED
                    ? __('Recipe v:version approved', ['version' => $snapshot->version])
                    : __('Order :number completed', ['number' => $snapshot->order ? ($snapshot->order->order_number ?? ('#'.$snapshot->order->id)) : ('#'.$snapshot->kitchen_production_order_id)]),
                'cost_per_portion' => $snapshot->cost_per_portion !== null ? (float) $snapshot->cost_per_portion : null,
                'food_cost_percent' => $snapshot->food_cost_percent !== null ? (float) $snapshot->food_cost_percent : null,
                'band' => $snapshot->band,
            ];
        }

        return $history;
    }

    public function openAlerts(int $creatorId)
    {
        if (! Schema::hasTable('kitchen_cost_alerts')) {
            return collect();
        }

        return KitchenCostAlert::where('created_by', $creatorId)
            ->where('status', KitchenCostAlert::STATUS_OPEN)
            ->with(['product', 'order'])
            ->orderByDesc('id')
            ->get();
    }
}
