<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\KitchenCostAlert;
use App\Models\KitchenLoss;
use App\Models\KitchenPosBatchConsumption;
use App\Models\KitchenProductionBatch;
use App\Models\KitchenProductionOrder;
use App\Models\KitchenProductionPlan;
use App\Models\KitchenRequisition;
use App\Models\ProductService;
use App\Models\ProductServiceUnit;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kitchen Production Phase 7 — reports (plan §9).
 *
 * One canonical representation per report: headings + rows of
 * pre-formatted scalar values, so the HTML screen, the CSV export,
 * the Excel export and the PDF export all render exactly the same
 * data. Every query is scoped by creatorId (one company's data can
 * never leak into another company's report) and relations are eager
 * loaded — no N+1 on the report screens.
 *
 * Reconciliation rule (§9): produced - sold - lost = batch
 * remaining, per batch. The traceability report runs that check for
 * every batch (via KitchenLossService::reconciliation) and flags any
 * batch that does not balance.
 *
 * Stock valuation rule: a completed order writes BOTH a batch row
 * and a WarehouseProduct row for the produced dish, so the raw
 * ingredient section excludes any product that is a batch output —
 * otherwise the prepared dish would be counted twice.
 */
class KitchenReportService
{
    public const REPORT_PRODUCTION = 'production';
    public const REPORT_USAGE = 'usage';
    public const REPORT_LOSSES = 'losses';
    public const REPORT_FOOD_COST = 'food-cost';
    public const REPORT_STOCK = 'stock';
    public const REPORT_WASTE = 'waste';
    public const REPORT_REQUISITIONS = 'requisitions';
    public const REPORT_TRACEABILITY = 'traceability';

    public const REPORTS = [
        self::REPORT_PRODUCTION => 'Production summary',
        self::REPORT_USAGE => 'Ingredient usage',
        self::REPORT_LOSSES => 'Losses',
        self::REPORT_FOOD_COST => 'Food cost',
        self::REPORT_STOCK => 'Stock valuation',
        self::REPORT_WASTE => 'Waste',
        self::REPORT_REQUISITIONS => 'Requisitions',
        self::REPORT_TRACEABILITY => 'Traceability',
    ];

    /** Filter fields each report offers on its screen. */
    public const FILTER_FIELDS = [
        self::REPORT_PRODUCTION => ['from', 'to', 'branch_id', 'section', 'status'],
        self::REPORT_USAGE => ['from', 'to', 'branch_id'],
        self::REPORT_LOSSES => ['from', 'to', 'category', 'status'],
        self::REPORT_FOOD_COST => ['from', 'to', 'branch_id', 'search'],
        self::REPORT_STOCK => ['warehouse_id', 'search'],
        self::REPORT_WASTE => ['from', 'to'],
        self::REPORT_REQUISITIONS => ['from', 'to', 'status'],
        self::REPORT_TRACEABILITY => ['search', 'warehouse_id'],
    ];

    public function __construct(
        protected KitchenCostControlService $costs,
        protected KitchenLossService $losses,
    ) {
    }

    /**
     * Build one report.
     *
     * @return array{key: string, title: string, description: string, headings: string[], rows: array<int, string[]>, totals: array<string, string>, summary: array, sections: array}
     */
    public function build(string $key, int $creatorId, array $filters = []): array
    {
        return match ($key) {
            self::REPORT_PRODUCTION => $this->productionSummary($creatorId, $filters),
            self::REPORT_USAGE => $this->ingredientUsage($creatorId, $filters),
            self::REPORT_LOSSES => $this->lossesReport($creatorId, $filters),
            self::REPORT_FOOD_COST => $this->foodCostReport($creatorId, $filters),
            self::REPORT_STOCK => $this->stockValuation($creatorId, $filters),
            self::REPORT_WASTE => $this->wasteReport($creatorId, $filters),
            self::REPORT_REQUISITIONS => $this->requisitionsReport($creatorId, $filters),
            self::REPORT_TRACEABILITY => $this->traceabilityReport($creatorId, $filters),
            default => throw new \InvalidArgumentException(__('Unknown kitchen report.')),
        };
    }

    /** Dashboard data for the kitchen (plan §8): today at a glance. */
    public function dashboard(int $creatorId): array
    {
        $today = date('Y-m-d');

        $todayOrders = KitchenProductionOrder::where('created_by', $creatorId)
            ->whereDate('production_date', $today)
            ->with(['recipe.product', 'branch'])
            ->orderBy('production_date')
            ->orderByDesc('id')
            ->get();

        $pendingPlans = KitchenProductionPlan::where('created_by', $creatorId)
            ->where('status', KitchenProductionPlan::STATUS_PENDING_APPROVAL)
            ->orderBy('plan_date')
            ->get();

        $nearExpiry = KitchenProductionBatch::where('created_by', $creatorId)
            ->where('quantity_remaining', '>', 0)
            ->whereDate('expiry_date', '>=', $today)
            ->whereDate('expiry_date', '<=', date('Y-m-d', strtotime('+7 days')))
            ->with(['product', 'warehouse'])
            ->orderBy('expiry_date')
            ->get();

        $expired = KitchenProductionBatch::where('created_by', $creatorId)
            ->where('quantity_remaining', '>', 0)
            ->whereDate('expiry_date', '<', $today)
            ->count();

        $lossesToday = KitchenLoss::where('created_by', $creatorId)
            ->whereDate('created_at', $today)
            ->whereNotIn('status', [KitchenLoss::STATUS_REJECTED])
            ->get();

        $openRequisitions = KitchenRequisition::where('created_by', $creatorId)
            ->whereIn('status', [KitchenRequisition::STATUS_REQUESTED, KitchenRequisition::STATUS_APPROVED])
            ->count();

        $openAlerts = KitchenCostAlert::where('created_by', $creatorId)
            ->where('status', KitchenCostAlert::STATUS_OPEN)
            ->count();

        $dishRows = $this->costs->dishCosts($creatorId);
        $flaggedDishes = collect($dishRows)->filter(fn ($row) => ! empty($row['alert']))->values();

        return [
            'today' => $today,
            'todayOrders' => $todayOrders,
            'todayOrdersCount' => $todayOrders->count(),
            'todayCompletedCount' => $todayOrders->whereIn('status', [KitchenProductionOrder::STATUS_COMPLETED, KitchenProductionOrder::STATUS_VALIDATED])->count(),
            'pendingPlans' => $pendingPlans,
            'nearExpiry' => $nearExpiry,
            'expiredCount' => $expired,
            'lossesTodayCount' => $lossesToday->count(),
            'lossesTodayCost' => round($lossesToday->sum(fn ($loss) => (float) ($loss->total_cost ?? 0)), 2),
            'openRequisitions' => $openRequisitions,
            'openAlerts' => $openAlerts,
            'flaggedDishes' => $flaggedDishes,
        ];
    }

    // -----------------------------------------------------------------
    // Reports
    // -----------------------------------------------------------------

    /**
     * Daily production by recipe, section and responsible person (§9):
     * planned vs actual quantity, yield %, planned vs actual cost,
     * cost per portion, food cost % and band, status.
     */
    public function productionSummary(int $creatorId, array $filters = []): array
    {
        $query = KitchenProductionOrder::where('created_by', $creatorId)
            ->with(['recipe.product', 'branch'])
            ->orderByDesc('production_date')
            ->orderByDesc('id');

        $this->applyDateFilter($query, $filters, 'production_date');

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }

        if (! empty($filters['section'])) {
            $query->where('section', $filters['section']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $orders = $query->get();

        $rows = [];
        $plannedCost = 0.0;
        $actualCost = 0.0;

        foreach ($orders as $order) {
            $plannedQty = (float) ($order->planned_quantity ?? 0);
            $actualQty = $order->actual_yield_quantity !== null ? (float) $order->actual_yield_quantity : null;
            $yieldPercent = $actualQty !== null && $plannedQty > 0
                ? round(($actualQty / $plannedQty) * 100, 2)
                : null;

            $plannedCost += (float) ($order->planned_cost ?? 0);
            $actualCost += (float) ($order->actual_cost ?? 0);

            $rows[] = [
                (string) ($order->order_number ?? ('#'.$order->id)),
                $order->production_date ? $order->production_date->format('Y-m-d') : '-',
                $order->recipe && $order->recipe->product ? (string) $order->recipe->product->name : '-',
                $order->recipe ? $order->recipe->code.' v'.$order->recipe->version : '-',
                $this->sectionLabel($order->section),
                $order->branch ? (string) $order->branch->name : '-',
                $this->qty($plannedQty),
                $actualQty !== null ? $this->qty($actualQty) : '-',
                $yieldPercent !== null ? $this->qty($yieldPercent).'%' : '-',
                $this->money((float) ($order->planned_cost ?? 0)),
                $order->actual_cost !== null ? $this->money((float) $order->actual_cost) : '-',
                $order->cost_per_portion !== null ? $this->money((float) $order->cost_per_portion) : '-',
                $order->food_cost_percent !== null
                    ? $this->qty((float) $order->food_cost_percent).'%'.($order->food_cost_band ? ' ('.KitchenRecipeCostService::bandLabel($order->food_cost_band).')' : '')
                    : '-',
                $this->statusLabel($order->status),
            ];
        }

        return [
            'key' => self::REPORT_PRODUCTION,
            'title' => __('Production summary'),
            'description' => __('Production orders in the period: planned vs actual quantity and cost, yield, food cost % and band.'),
            'headings' => [__('Order'), __('Date'), __('Dish'), __('Recipe'), __('Section'), __('Branch'), __('Planned qty'), __('Actual yield'), __('Yield %'), __('Planned cost'), __('Actual cost'), __('Cost / portion'), __('Food cost %'), __('Status')],
            'rows' => $rows,
            'totals' => [
                __('Orders') => (string) count($rows),
                __('Planned cost') => $this->money($plannedCost),
                __('Actual cost') => $this->money($actualCost),
            ],
            'summary' => [],
            'sections' => [],
        ];
    }

    /**
     * Expected vs actual ingredient consumption (§9): planned vs
     * actual quantity and cost per ingredient, across the completed
     * orders in the period.
     */
    public function ingredientUsage(int $creatorId, array $filters = []): array
    {
        $ordersQuery = KitchenProductionOrder::where('created_by', $creatorId)
            ->whereIn('status', [KitchenProductionOrder::STATUS_COMPLETED, KitchenProductionOrder::STATUS_VALIDATED])
            ->with(['lines.ingredient', 'lines.plannedUnit']);

        $this->applyDateFilter($ordersQuery, $filters, 'completed_at');

        if (! empty($filters['branch_id'])) {
            $ordersQuery->where('branch_id', (int) $filters['branch_id']);
        }

        $aggregated = [];

        foreach ($ordersQuery->get() as $order) {
            foreach ($order->lines as $line) {
                $ingredientId = (int) $line->ingredient_product_id;

                if (! isset($aggregated[$ingredientId])) {
                    $aggregated[$ingredientId] = [
                        'name' => $line->ingredient ? (string) $line->ingredient->name : '#'.$ingredientId,
                        'unit' => $line->plannedUnit ? (string) $line->plannedUnit->name : ($line->ingredient && $line->ingredient->unit ? (string) $line->ingredient->unit->name : '-'),
                        'planned_qty' => 0.0,
                        'actual_qty' => 0.0,
                        'planned_cost' => 0.0,
                        'actual_cost' => 0.0,
                    ];
                }

                $aggregated[$ingredientId]['planned_qty'] += (float) ($line->planned_quantity ?? 0);
                $aggregated[$ingredientId]['actual_qty'] += (float) ($line->actual_quantity ?? 0);
                $aggregated[$ingredientId]['planned_cost'] += (float) ($line->planned_cost ?? 0);
                $aggregated[$ingredientId]['actual_cost'] += (float) ($line->actual_cost ?? 0);
            }
        }

        ksort($aggregated);

        $rows = [];
        $plannedCost = 0.0;
        $actualCost = 0.0;

        foreach ($aggregated as $item) {
            $variance = round($item['actual_qty'] - $item['planned_qty'], 4);
            $variancePercent = $item['planned_qty'] > 0
                ? round(($variance / $item['planned_qty']) * 100, 2)
                : null;

            $plannedCost += $item['planned_cost'];
            $actualCost += $item['actual_cost'];

            $rows[] = [
                $item['name'],
                $item['unit'],
                $this->qty($item['planned_qty']),
                $this->qty($item['actual_qty']),
                $this->qty($variance),
                $variancePercent !== null ? $this->qty($variancePercent).'%' : '-',
                $this->money($item['planned_cost']),
                $this->money($item['actual_cost']),
            ];
        }

        return [
            'key' => self::REPORT_USAGE,
            'title' => __('Ingredient usage'),
            'description' => __('Expected vs actual ingredient consumption across the completed orders in the period.'),
            'headings' => [__('Ingredient'), __('Unit'), __('Planned qty'), __('Actual qty'), __('Variance'), __('Variance %'), __('Planned cost'), __('Actual cost')],
            'rows' => $rows,
            'totals' => [
                __('Ingredients') => (string) count($rows),
                __('Planned cost') => $this->money($plannedCost),
                __('Actual cost') => $this->money($actualCost),
            ],
            'summary' => [],
            'sections' => [],
        ];
    }

    /**
     * Losses and waste by reason (§9): every loss in the period, with
     * a per-category summary (quantity + cost).
     */
    public function lossesReport(int $creatorId, array $filters = []): array
    {
        $query = KitchenLoss::where('created_by', $creatorId)
            ->with(['product', 'unit', 'order', 'batch'])
            ->orderByDesc('created_at');

        $this->applyDateFilter($query, $filters, 'created_at');

        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $losses = $query->get();

        $rows = [];
        $totalCost = 0.0;
        $byCategory = [];

        foreach ($losses as $loss) {
            $cost = (float) ($loss->total_cost ?? 0);
            $totalCost += $cost;

            $category = (string) $loss->category;
            if (! isset($byCategory[$category])) {
                $byCategory[$category] = ['quantity' => 0.0, 'cost' => 0.0, 'count' => 0];
            }
            $byCategory[$category]['quantity'] += (float) ($loss->quantity ?? 0);
            $byCategory[$category]['cost'] += $cost;
            $byCategory[$category]['count']++;

            $rows[] = [
                (string) $loss->loss_number,
                $loss->created_at ? $loss->created_at->format('Y-m-d') : '-',
                self::categoryLabel($category),
                $loss->product ? (string) $loss->product->name : '-',
                $this->qty((float) ($loss->quantity ?? 0)),
                $loss->unit ? (string) $loss->unit->name : '-',
                $this->money((float) ($loss->unit_cost ?? 0)),
                $this->money($cost),
                $this->statusLabel($loss->status),
                $loss->order ? (string) ($loss->order->order_number ?? ('#'.$loss->order->id)) : '-',
                $loss->batch ? (string) $loss->batch->batch_number : '-',
            ];
        }

        $summary = [];
        foreach ($byCategory as $category => $item) {
            $summary[] = [
                'label' => self::categoryLabel($category),
                'count' => $item['count'],
                'quantity' => $this->qty($item['quantity']),
                'cost' => $this->money($item['cost']),
            ];
        }

        return [
            'key' => self::REPORT_LOSSES,
            'title' => __('Losses'),
            'description' => __('Losses by category, dish and cost in the period. Rejected losses are excluded from the totals below.'),
            'headings' => [__('Loss'), __('Date'), __('Category'), __('Product'), __('Quantity'), __('Unit'), __('Unit cost'), __('Total cost'), __('Status'), __('Order'), __('Batch')],
            'rows' => $rows,
            'totals' => [
                __('Losses') => (string) count($rows),
                __('Total cost') => $this->money($totalCost),
            ],
            'summary' => $summary,
            'sections' => [],
        ];
    }

    /**
     * Cost and margin by dish (§9, plan §7): expected cost from the
     * approved recipe at today's prices vs the actual cost of the
     * latest completed order, with Danilo's food-cost band.
     */
    public function foodCostReport(int $creatorId, array $filters = []): array
    {
        $dishRows = $this->costs->dishCosts($creatorId, $filters);
        $periodSummary = $this->costs->periodSummary(
            $creatorId,
            $filters['from'] ?? date('Y-m-01'),
            $filters['to'] ?? date('Y-m-t'),
            $filters['group'] ?? 'month'
        );

        $rows = [];
        foreach ($dishRows as $row) {
            $rows[] = [
                (string) $row['product']->name,
                $row['recipe']->code.' v'.$row['recipe']->version,
                $row['expected']['cost_per_portion'] !== null ? $this->money((float) $row['expected']['cost_per_portion']) : '-',
                $row['expected']['food_cost_percent'] !== null
                    ? $this->qty((float) $row['expected']['food_cost_percent']).'%'.($row['expected']['band'] ? ' ('.KitchenRecipeCostService::bandLabel($row['expected']['band']).')' : '')
                    : '-',
                $row['actual_cost_per_portion'] !== null ? $this->money((float) $row['actual_cost_per_portion']) : '-',
                $row['actual_food_cost_percent'] !== null
                    ? $this->qty((float) $row['actual_food_cost_percent']).'%'.($row['actual_band'] ? ' ('.KitchenRecipeCostService::bandLabel($row['actual_band']).')' : '')
                    : '-',
                (string) $row['completed_orders_in_period'],
                $row['average_food_cost_percent'] !== null ? $this->qty((float) $row['average_food_cost_percent']).'%' : '-',
            ];
        }

        $summary = [];
        foreach ($periodSummary as $period) {
            $summary[] = [
                'label' => $period['label'],
                'count' => $period['orders'],
                'quantity' => $this->money($period['actual_cost']),
                'cost' => $period['average_food_cost_percent'] !== null
                    ? $this->qty((float) $period['average_food_cost_percent']).'%'
                    : '-',
            ];
        }

        return [
            'key' => self::REPORT_FOOD_COST,
            'title' => __('Food cost'),
            'description' => __('Cost and margin by dish: expected cost from the approved recipe vs the actual cost of the latest completed order, with the food-cost band.'),
            'headings' => [__('Dish'), __('Recipe'), __('Expected cost / portion'), __('Expected food cost %'), __('Actual cost / portion'), __('Actual food cost %'), __('Orders in period'), __('Avg food cost %')],
            'rows' => $rows,
            'totals' => [
                __('Dishes') => (string) count($rows),
                __('Flagged') => (string) collect($dishRows)->filter(fn ($row) => ! empty($row['alert']))->count(),
            ],
            'summary' => $summary,
            'sections' => [],
        ];
    }

    /**
     * Stock valuation (§9): prepared batches at their actual
     * production cost, plus raw ingredients held in the kitchen
     * warehouses at purchase price. Batch outputs are excluded from
     * the raw section — a completed order writes both a batch row
     * and a warehouse row for the produced dish, so counting both
     * would value the dish twice.
     */
    public function stockValuation(int $creatorId, array $filters = []): array
    {
        $batchQuery = KitchenProductionBatch::where('created_by', $creatorId)
            ->where('quantity_remaining', '>', 0)
            ->with(['product', 'warehouse'])
            ->orderBy('expiry_date');

        if (! empty($filters['warehouse_id'])) {
            $batchQuery->where('warehouse_id', (int) $filters['warehouse_id']);
        }

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $batchQuery->where(function ($q) use ($search) {
                $q->where('batch_number', 'like', $search)
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $search));
            });
        }

        $batches = $batchQuery->get();
        $today = date('Y-m-d');
        $nearDate = date('Y-m-d', strtotime('+7 days'));

        $batchRows = [];
        $batchValue = 0.0;
        foreach ($batches as $batch) {
            $remaining = (float) ($batch->quantity_remaining ?? 0);
            $value = round($remaining * (float) ($batch->unit_cost ?? 0), 2);
            $batchValue += $value;

            $expiry = $batch->expiry_date ? $batch->expiry_date->format('Y-m-d') : '-';
            $near = $batch->expiry_date && $batch->expiry_date->format('Y-m-d') <= $nearDate
                ? ($batch->expiry_date->format('Y-m-d') < $today ? __('Expired') : __('Yes'))
                : __('No');

            $batchRows[] = [
                (string) $batch->batch_number,
                $batch->product ? (string) $batch->product->name : '-',
                $batch->warehouse ? (string) $batch->warehouse->name : '-',
                $this->qty((float) ($batch->quantity_produced ?? 0)),
                $this->qty($remaining),
                $batch->unit ? (string) $batch->unit->name : '-',
                $this->money((float) ($batch->unit_cost ?? 0)),
                $this->money($value),
                $expiry,
                $near,
            ];
        }

        $kitchenWarehouseIds = $this->kitchenWarehouseIds($creatorId);

        $rawRows = [];
        $rawValue = 0.0;
        if ($kitchenWarehouseIds !== []) {
            $batchProductIds = KitchenProductionBatch::where('created_by', $creatorId)
                ->distinct()
                ->pluck('product_id')
                ->all();

            $stockRows = WarehouseProduct::whereIn('warehouse_id', $kitchenWarehouseIds)
                ->where('quantity', '>', 0)
                ->whereNotIn('product_id', $batchProductIds)
                ->with(['product', 'warehouse'])
                ->orderBy('warehouse_id')
                ->orderBy('product_id')
                ->get();

            foreach ($stockRows as $row) {
                if (! $row->product instanceof ProductService) {
                    continue;
                }

                if (! empty($filters['warehouse_id']) && (int) $row->warehouse_id !== (int) $filters['warehouse_id']) {
                    continue;
                }

                if (! empty($filters['search']) && stripos((string) $row->product->name, (string) $filters['search']) === false) {
                    continue;
                }

                $quantity = (float) ($row->quantity ?? 0);
                $value = round($quantity * (float) ($row->product->purchase_price ?? 0), 2);
                $rawValue += $value;

                $rawRows[] = [
                    (string) $row->product->name,
                    $row->warehouse ? (string) $row->warehouse->name : '-',
                    $this->qty($quantity),
                    $row->product->unit ? (string) $row->product->unit->name : '-',
                    $this->money((float) ($row->product->purchase_price ?? 0)),
                    $this->money($value),
                ];
            }
        }

        return [
            'key' => self::REPORT_STOCK,
            'title' => __('Stock valuation'),
            'description' => __('Kitchen warehouse stock at cost, as of today. Prepared batches are valued at their actual production cost; raw ingredients at purchase price. A dish that is also a batch output is valued once, through its batches.'),
            'headings' => [__('Batch'), __('Dish'), __('Warehouse'), __('Produced'), __('Remaining'), __('Unit'), __('Unit cost'), __('Value'), __('Expiry'), __('Near expiry')],
            'rows' => $batchRows,
            'totals' => [
                __('Prepared batches value') => $this->money($batchValue),
                __('Raw ingredients value') => $this->money($rawValue),
                __('Total stock value') => $this->money($batchValue + $rawValue),
            ],
            'summary' => [],
            'sections' => [
                [
                    'title' => __('Raw ingredients in kitchen warehouses'),
                    'headings' => [__('Ingredient'), __('Warehouse'), __('Quantity'), __('Unit'), __('Purchase price'), __('Value')],
                    'rows' => $rawRows,
                ],
            ],
        ];
    }

    /**
     * Waste (§9): losses as a % of production, per dish, in the
     * period. Produced comes from the batches created in the period,
     * sold from the POS consumptions of those batches, lost from the
     * posted losses of the dish.
     */
    public function wasteReport(int $creatorId, array $filters = []): array
    {
        $from = $filters['from'] ?? date('Y-m-01');
        $to = $filters['to'] ?? date('Y-m-t');

        $batches = KitchenProductionBatch::where('created_by', $creatorId)
            ->whereDate('created_at', '>=', $from)
            ->whereDate('created_at', '<=', $to)
            ->with('product')
            ->get();

        $aggregated = [];
        foreach ($batches as $batch) {
            $productId = (int) $batch->product_id;
            if (! isset($aggregated[$productId])) {
                $aggregated[$productId] = [
                    'name' => $batch->product ? (string) $batch->product->name : '#'.$productId,
                    'produced_qty' => 0.0,
                    'produced_cost' => 0.0,
                    'batch_ids' => [],
                ];
            }
            $aggregated[$productId]['produced_qty'] += (float) ($batch->quantity_produced ?? 0);
            $aggregated[$productId]['produced_cost'] += (float) ($batch->total_cost ?? 0);
            $aggregated[$productId]['batch_ids'][] = (int) $batch->id;
        }

        $rows = [];
        $totalProducedCost = 0.0;
        $totalLostCost = 0.0;

        foreach ($aggregated as $productId => $item) {
            $sold = 0.0;
            if (Schema::hasTable('kitchen_pos_batch_consumptions') && $item['batch_ids'] !== []) {
                $sold = round((float) KitchenPosBatchConsumption::where('created_by', $creatorId)
                    ->whereIn('kitchen_production_batch_id', $item['batch_ids'])
                    ->whereDate('created_at', '>=', $from)
                    ->whereDate('created_at', '<=', $to)
                    ->sum(DB::raw('quantity - quantity_restored')), 4);
            }

            $lostRows = KitchenLoss::where('created_by', $creatorId)
                ->where('product_id', $productId)
                ->where('status', KitchenLoss::STATUS_POSTED)
                ->where('stock_already_deducted', false)
                ->whereDate('posted_at', '>=', $from)
                ->whereDate('posted_at', '<=', $to)
                ->get();

            $lostQty = round($lostRows->sum(fn ($loss) => (float) ($loss->quantity ?? 0)), 4);
            $lostCost = round($lostRows->sum(fn ($loss) => (float) ($loss->total_cost ?? 0)), 2);

            $wastePercent = $item['produced_qty'] > 0
                ? round(($lostQty / $item['produced_qty']) * 100, 2)
                : null;

            $totalProducedCost += $item['produced_cost'];
            $totalLostCost += $lostCost;

            $rows[] = [
                $item['name'],
                $this->qty($item['produced_qty']),
                $this->money($item['produced_cost']),
                $this->qty($sold),
                $this->qty($lostQty),
                $this->money($lostCost),
                $wastePercent !== null ? $this->qty($wastePercent).'%' : '-',
            ];
        }

        return [
            'key' => self::REPORT_WASTE,
            'title' => __('Waste'),
            'description' => __('Losses as a % of production, per dish, in the period. Lost counts only posted losses.'),
            'headings' => [__('Dish'), __('Produced qty'), __('Produced cost'), __('Sold qty'), __('Lost qty'), __('Lost cost'), __('Waste %')],
            'rows' => $rows,
            'totals' => [
                __('Dishes') => (string) count($rows),
                __('Produced cost') => $this->money($totalProducedCost),
                __('Lost cost') => $this->money($totalLostCost),
            ],
            'summary' => [],
            'sections' => [],
        ];
    }

    /**
     * Requisitions (§3): requested vs issued, per line, with the
     * remaining quantity still to issue.
     */
    public function requisitionsReport(int $creatorId, array $filters = []): array
    {
        $query = KitchenRequisition::where('created_by', $creatorId)
            ->with(['lines.ingredient', 'lines.unit', 'sourceWarehouse', 'kitchenWarehouse'])
            ->orderByDesc('created_at');

        $this->applyDateFilter($query, $filters, 'created_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $rows = [];
        $requiredTotal = 0.0;
        $issuedTotal = 0.0;

        foreach ($query->get() as $requisition) {
            foreach ($requisition->lines as $line) {
                $requiredTotal += (float) ($line->required_quantity ?? 0);
                $issuedTotal += (float) ($line->issued_quantity ?? 0);

                $rows[] = [
                    (string) $requisition->requisition_number,
                    $requisition->created_at ? $requisition->created_at->format('Y-m-d') : '-',
                    $line->ingredient ? (string) $line->ingredient->name : '-',
                    $this->qty((float) ($line->required_quantity ?? 0)),
                    $this->qty((float) ($line->available_quantity ?? 0)),
                    $this->qty((float) ($line->shortage_quantity ?? 0)),
                    $this->qty((float) ($line->issued_quantity ?? 0)),
                    $this->qty($line->remainingQuantity()),
                    $line->unit ? (string) $line->unit->name : '-',
                    $this->statusLabel($requisition->status),
                    $requisition->sourceWarehouse ? (string) $requisition->sourceWarehouse->name : '-',
                    $requisition->kitchenWarehouse ? (string) $requisition->kitchenWarehouse->name : '-',
                ];
            }
        }

        return [
            'key' => self::REPORT_REQUISITIONS,
            'title' => __('Requisitions'),
            'description' => __('Warehouse requests from plans and orders: required vs issued, and what is still to issue.'),
            'headings' => [__('Requisition'), __('Date'), __('Ingredient'), __('Required'), __('Available'), __('Shortage'), __('Issued'), __('Remaining'), __('Unit'), __('Status'), __('Source warehouse'), __('Kitchen warehouse')],
            'rows' => $rows,
            'totals' => [
                __('Lines') => (string) count($rows),
                __('Required total') => $this->qty($requiredTotal),
                __('Issued total') => $this->qty($issuedTotal),
            ],
            'summary' => [],
            'sections' => [],
        ];
    }

    /**
     * Traceability (§9): from ingredient batches to preparations to
     * dishes sold, with the per-batch reconciliation check
     * produced - sold - lost = remaining. A batch that does not
     * balance is flagged — that is a bug, not a rounding difference.
     */
    public function traceabilityReport(int $creatorId, array $filters = []): array
    {
        $query = KitchenProductionBatch::where('created_by', $creatorId)
            ->with(['product', 'warehouse', 'order.recipe'])
            ->orderByDesc('created_at');

        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', (int) $filters['warehouse_id']);
        }

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($search) {
                $q->where('batch_number', 'like', $search)
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $search));
            });
        }

        $rows = [];
        $unbalanced = 0;

        foreach ($query->get() as $batch) {
            $check = $this->losses->reconciliation($batch, $creatorId);
            if (! $check['balanced']) {
                $unbalanced++;
            }

            $rows[] = [
                (string) $batch->batch_number,
                $batch->product ? (string) $batch->product->name : '-',
                $batch->order ? (string) ($batch->order->order_number ?? ('#'.$batch->order->id)) : '-',
                $batch->warehouse ? (string) $batch->warehouse->name : '-',
                $this->qty($check['produced']),
                $this->qty($check['sold']),
                $this->qty($check['lost']),
                $this->qty($check['remaining']),
                $check['balanced'] ? __('Yes') : __('No'),
                $check['balanced'] ? '-' : $this->qty($check['difference']),
            ];
        }

        return [
            'key' => self::REPORT_TRACEABILITY,
            'title' => __('Traceability'),
            'description' => __('From ingredient batches to preparations to dishes sold. Every batch must reconcile: produced - sold - lost = remaining.'),
            'headings' => [__('Batch'), __('Dish'), __('Order'), __('Warehouse'), __('Produced'), __('Sold'), __('Lost'), __('Remaining'), __('Balanced'), __('Difference')],
            'rows' => $rows,
            'totals' => [
                __('Batches') => (string) count($rows),
                __('Unbalanced') => (string) $unbalanced,
            ],
            'summary' => [],
            'sections' => [],
        ];
    }

    /**
     * Traceability detail for one batch: the ingredient lots the
     * production order consumed, and the POS lines that consumed the
     * batch. Both sides are scoped by the batch's own company.
     */
    public function traceabilityDetail(KitchenProductionBatch $batch, int $creatorId): array
    {
        $lotRows = [];
        if ($batch->order) {
            $lines = $batch->order->lines()->with(['ingredient', 'lotUsages.lot'])->get();
            foreach ($lines as $line) {
                foreach ($line->lotUsages as $usage) {
                    $lot = $usage->lot;
                    $lotRows[] = [
                        $line->ingredient ? (string) $line->ingredient->name : '-',
                        $lot && $lot->batch_no ? (string) $lot->batch_no : '-',
                        $lot && $lot->expiry_date ? $lot->expiry_date->format('Y-m-d') : '-',
                        $this->qty((float) ($usage->quantity ?? 0)),
                    ];
                }
            }
        }

        $consumptionRows = [];
        if (Schema::hasTable('kitchen_pos_batch_consumptions')) {
            $consumptions = KitchenPosBatchConsumption::where('kitchen_production_batch_id', (int) $batch->id)
                ->where('created_by', $creatorId)
                ->orderBy('created_at')
                ->get();

            foreach ($consumptions as $consumption) {
                $consumptionRows[] = [
                    '#'.(int) $consumption->pos_product_id,
                    $this->qty((float) ($consumption->quantity ?? 0)),
                    $this->qty((float) ($consumption->quantity_restored ?? 0)),
                    $this->qty($consumption->outstanding()),
                    $consumption->created_at ? $consumption->created_at->format('Y-m-d H:i') : '-',
                ];
            }
        }

        return [
            'lotRows' => $lotRows,
            'consumptionRows' => $consumptionRows,
            'reconciliation' => $this->losses->reconciliation($batch, $creatorId),
        ];
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    public static function categoryLabel(string $category): string
    {
        return match ($category) {
            KitchenLoss::CATEGORY_PREPARATION => __('Preparation loss'),
            KitchenLoss::CATEGORY_BURNT_SPOILED => __('Burnt / spoiled'),
            KitchenLoss::CATEGORY_BUFFET_LEFTOVER => __('Buffet leftover'),
            KitchenLoss::CATEGORY_CUSTOMER_RETURN => __('Customer return'),
            KitchenLoss::CATEGORY_STAFF_MEAL => __('Staff meal'),
            KitchenLoss::CATEGORY_COMPLIMENTARY => __('Complimentary'),
            KitchenLoss::CATEGORY_TASTING => __('Tasting'),
            KitchenLoss::CATEGORY_TRANSFER => __('Transfer'),
            default => $category,
        };
    }

    protected function sectionLabel(?string $section): string
    {
        return match ($section) {
            KitchenProductionOrder::SECTION_HOT_KITCHEN => __('Hot kitchen'),
            KitchenProductionOrder::SECTION_PASTRY => __('Pastry'),
            KitchenProductionOrder::SECTION_COLD_KITCHEN => __('Cold kitchen'),
            default => $section ?? '-',
        };
    }

    protected function statusLabel(?string $status): string
    {
        return $status !== null && $status !== '' ? ucfirst(str_replace('_', ' ', $status)) : '-';
    }

    protected function applyDateFilter($query, array $filters, string $column): void
    {
        if (! empty($filters['from'])) {
            $query->whereDate($column, '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate($column, '<=', $filters['to']);
        }
    }

    /** Warehouses the kitchen actually uses (orders + requisitions). */
    protected function kitchenWarehouseIds(int $creatorId): array
    {
        $orderWarehouses = KitchenProductionOrder::where('created_by', $creatorId)
            ->distinct()
            ->pluck('warehouse_id')
            ->all();

        $requisitionWarehouses = KitchenRequisition::where('created_by', $creatorId)
            ->distinct()
            ->pluck('kitchen_warehouse_id')
            ->all();

        return array_values(array_unique(array_filter(array_merge($orderWarehouses, $requisitionWarehouses))));
    }

    protected function qty(float $value): string
    {
        return number_format($value, 2);
    }

    protected function money(float $value): string
    {
        return number_format($value, 2);
    }
}
