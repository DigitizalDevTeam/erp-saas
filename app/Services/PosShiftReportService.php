<?php

namespace App\Services;

use App\Http\Controllers\PosController;
use App\Models\Expense;
use App\Models\InvoicePayment;
use App\Models\Pos;
use App\Models\PosCashDrop;
use App\Models\PosCashIn;
use App\Models\PosPayment;
use App\Models\PosProduct;
use App\Models\PosShift;
use App\Models\PosTable;
use App\Models\User;
use App\Models\Utility;
use App\Models\WarehouseProduct;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PosShiftReportService
{
    public const MAX_DATE_RANGE_DAYS = 90;

    public function normalizeFilters(array $input): array
    {
        $end = $input['end_date'] ?? date('Y-m-d');
        $start = $input['start_date'] ?? date('Y-m-d', strtotime('-7 days'));
        if (strtotime($start) > strtotime($end)) {
            [$start, $end] = [$end, $start];
        }
        $days = (int) Carbon::parse($start)->diffInDays(Carbon::parse($end));
        if ($days > self::MAX_DATE_RANGE_DAYS) {
            $start = Carbon::parse($end)->subDays(self::MAX_DATE_RANGE_DAYS)->format('Y-m-d');
        }

        return [
            'start_date' => $start,
            'end_date' => $end,
            'branch_id' => (int) ($input['branch_id'] ?? 0),
            'warehouse_id' => (int) ($input['warehouse_id'] ?? 0),
            'shift_user_id' => (int) ($input['shift_user_id'] ?? 0),
            'pos_user_id' => (int) ($input['pos_user_id'] ?? 0),
            'product_id' => (int) ($input['product_id'] ?? 0),
            'shift_status' => $input['shift_status'] ?? '',
            'order_type' => $input['order_type'] ?? '',
            'payment_method' => $input['payment_method'] ?? '',
        ];
    }

    public function queryShifts(int $creatorId, array $filters): Builder
    {
        $start = $filters['start_date'] . ' 00:00:00';
        $end = $filters['end_date'] . ' 23:59:59';

        $query = PosShift::query()
            ->where('created_by', $creatorId)
            ->whereBetween('opened_at', [$start, $end])
            ->with(['branch', 'user'])
            ->orderByDesc('opened_at');

        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }
        if (! empty($filters['shift_status']) && in_array($filters['shift_status'], [PosShift::STATUS_OPEN, PosShift::STATUS_CLOSED], true)) {
            $query->where('status', $filters['shift_status']);
        }
        if (! empty($filters['shift_user_id'])) {
            $query->where('user_id', $filters['shift_user_id']);
        }
        if (! empty($filters['pos_user_id'])) {
            $query->whereHas('posSales', function ($q) use ($filters) {
                $q->where('pos_user_id', $filters['pos_user_id']);
            });
        }
        if (! empty($filters['warehouse_id'])) {
            $query->whereHas('posSales', function ($q) use ($filters) {
                $q->where('warehouse_id', $filters['warehouse_id']);
            });
        }
        if (! empty($filters['product_id'])) {
            $query->whereHas('posSales.items', function ($q) use ($filters) {
                $q->where('product_id', $filters['product_id']);
            });
        }
        if (! empty($filters['order_type'])) {
            $query->whereHas('posSales', function ($q) use ($filters) {
                $q->where('order_type', $filters['order_type']);
            });
        }
        if (! empty($filters['payment_method'])) {
            $method = $filters['payment_method'];
            $query->whereHas('posSales.posPayments', function ($q) use ($method) {
                if ($method === PosPayment::METHOD_CASH) {
                    $q->where(function ($q2) {
                        $q2->where('payment_method', PosPayment::METHOD_CASH)->orWhereNull('payment_method');
                    });
                } else {
                    $q->where('payment_method', $method);
                }
            });
        }

        return $query;
    }

    public function getShiftIdsForFilters(int $creatorId, array $filters): Collection
    {
        return $this->queryShifts($creatorId, $filters)->pluck('id');
    }

    /**
     * All POS record ids that belong to this shift (linked + paid orphans during shift window).
     *
     * @return Collection<int, int>
     */
    public function resolveShiftPosIds(PosShift $shift, int $creatorId): Collection
    {
        $shift->loadMissing('branch');

        $directIds = Pos::where('shift_id', $shift->id)
            ->where('created_by', $creatorId)
            ->pluck('id');

        $orphanIds = $this->findOrphanPaidPosIds($shift, $creatorId, $directIds);

        if ($orphanIds->isNotEmpty()) {
            Pos::whereIn('id', $orphanIds)
                ->whereNull('shift_id')
                ->update(['shift_id' => $shift->id]);
        }

        return $directIds->merge($orphanIds)->unique()->values();
    }

    /**
     * Paid POS transactions for shift report Sales (matches Pay Receipts for this shift).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Pos>
     */
    public function getShiftPaidSales(PosShift $shift, int $creatorId)
    {
        $posIds = $this->resolveShiftPosIds($shift, $creatorId);

        if ($posIds->isEmpty()) {
            return collect();
        }

        return Pos::whereIn('id', $posIds)
            ->whereHas('posPayments')
            ->whereNull('cancelled_at')
            ->with(['items', 'posPayments'])
            ->orderBy('pos_id')
            ->get();
    }

    /**
     * Paid POS on the same branch/waiter during the shift but missing shift_id.
     *
     * @return Collection<int, int>
     */
    private function findOrphanPaidPosIds(PosShift $shift, int $creatorId, Collection $excludeIds): Collection
    {
        if (! $shift->branch_id) {
            return collect();
        }

        $windowStart = $shift->opened_at ?? Carbon::parse($shift->created_at ?? now());
        $windowEnd = $shift->closed_at ?? now();

        $assignedTableIds = $shift->tableAssignments()
            ->pluck('table_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return Pos::query()
            ->where('created_by', $creatorId)
            ->where('branch_id', $shift->branch_id)
            ->whereNull('cancelled_at')
            ->where(function ($q) {
                $q->whereNull('shift_id')->orWhere('shift_id', 0);
            })
            ->when($excludeIds->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $excludeIds))
            ->where(function ($q) use ($shift, $assignedTableIds) {
                $q->where('pos_user_id', $shift->user_id);
                if (! empty($assignedTableIds)) {
                    $q->orWhereIn('table_id', $assignedTableIds);
                }
            })
            ->whereHas('posPayments', function ($q) use ($windowStart, $windowEnd) {
                $q->whereBetween('created_at', [$windowStart, $windowEnd]);
            })
            ->pluck('id');
    }

    /**
     * @return array<string, mixed>
     */
    public function buildShiftMetrics(PosShift $shift, int $creatorId): array
    {
        $posIds = $this->resolveShiftPosIds($shift, $creatorId);
        $sales = $posIds->isEmpty()
            ? collect()
            : Pos::whereIn('id', $posIds)
                ->whereHas('posPayments')
                ->whereNull('cancelled_at')
                ->with(['items', 'posPayments'])
                ->orderBy('pos_id')
                ->get();
        $paymentsByMethod = PosPayment::whereIn('pos_id', $posIds)
            ->selectRaw('payment_method, sum(amount) as total, count(*) as cnt')
            ->groupBy('payment_method')
            ->get()
            ->keyBy('payment_method');
        $cashTotal = (float) PosPayment::whereIn('pos_id', $posIds)->where(function ($q) {
            $q->where('payment_method', PosPayment::METHOD_CASH)->orWhereNull('payment_method');
        })->sum('amount');
        $balanceCash = (float) InvoicePayment::where('pos_shift_id', $shift->id)->where('payment_method', 0)->sum('amount');
        $cashTotal += $balanceCash;
        $cardTotal = (float) PosPayment::whereIn('pos_id', $posIds)->whereIn('payment_method', ['card', 'visa', 'mpesa', 'bank_transfer', 'cheque'])->sum('amount');
        $creditTotal = (float) PosPayment::whereIn('pos_id', $posIds)->where('payment_method', PosPayment::METHOD_CREDIT)->sum('amount');
        $salesTotal = (float) PosPayment::whereIn('pos_id', $posIds)->sum('amount');
        $cashDropsTotal = (float) PosCashDrop::where('shift_id', $shift->id)->sum('amount');
        $cashInsTotal = (float) PosCashIn::where('shift_id', $shift->id)->sum('amount');
        $cashOutExpensesTotal = (float) Expense::where('pos_shift_id', $shift->id)
            ->where('created_by', $creatorId)
            ->cashOutPosted()
            ->sum('amount');
        $expectedCash = (float) ($shift->opening_balance ?? 0) + $cashTotal + $cashInsTotal - $cashDropsTotal - $cashOutExpensesTotal;
        $voidedLines = PosProduct::whereIn('pos_id', $posIds)
            ->whereNotNull('voided_at')
            ->with(['product', 'pos', 'voidedByUser'])
            ->orderBy('voided_at')
            ->get();

        $taxData = Utility::getTaxData() ?? [];
        $posMeta = $posIds->isEmpty()
            ? collect()
            : Pos::whereIn('id', $posIds)->get(['id', 'document_type', 'table_id', 'pos_user_id'])->keyBy('id');
        $soldLines = $posIds->isEmpty()
            ? collect()
            : PosProduct::query()
                ->with('product')
                ->whereIn('pos_id', $posIds)
                ->whereNull('voided_at')
                ->whereNull('removed_from_cart_at')
                ->get();

        $productQtySummary = $this->buildProductQtySummary($soldLines, $posMeta, $taxData);
        [$sales, $internalConsumption] = $this->splitSalesWithInvoiceAmounts($sales, $taxData);

        return [
            'shift' => $shift,
            'sales' => $sales,
            'internalConsumption' => $internalConsumption,
            'paymentsByMethod' => $paymentsByMethod,
            'cashTotal' => $cashTotal,
            'cardTotal' => $cardTotal,
            'creditTotal' => $creditTotal,
            'salesTotal' => $salesTotal,
            'cashDropsTotal' => $cashDropsTotal,
            'cashInsTotal' => $cashInsTotal,
            'cashOutExpensesTotal' => $cashOutExpensesTotal,
            'voidedLines' => $voidedLines,
            'balanceCash' => $balanceCash,
            'expectedCash' => $expectedCash,
            'productQtySummary' => $productQtySummary,
            'salesCount' => $sales->count(),
            'internalConsumptionCount' => $internalConsumption->count(),
            'difference' => $shift->status === PosShift::STATUS_CLOSED ? (float) ($shift->difference ?? 0) : null,
        ];
    }

    /**
     * Invoice-style amounts for one POS line. Price is the exclusive unit.
     *
     * @param  array<int|string, array<string, mixed>>  $taxData
     * @return array{exclusive_unit: float, inclusive_unit: float, qty: float, exclusive: float, tax: float, inclusive: float}
     */
    private function invoiceLineAmounts(PosProduct $line, array $taxData): array
    {
        $rate = 0.0;
        foreach (explode(',', (string) $line->tax) as $taxId) {
            $taxId = (int) trim($taxId);
            if ($taxId > 0 && isset($taxData[$taxId]['rate'])) {
                $rate += (float) $taxData[$taxId]['rate'];
            }
        }

        $exclusiveUnit = round((float) $line->price, 2);
        $storedUnit = ($line->unit_subtotal !== null && (float) $line->unit_subtotal > 0)
            ? (float) $line->unit_subtotal
            : null;
        $variantPrice = ($line->variant && $line->variant->sale_price !== null)
            ? (float) $line->variant->sale_price
            : null;
        $lineProduct = $line->product;
        $inclusiveUnit = PosController::resolveRestaurantInclusiveUnit(
            $exclusiveUnit,
            $rate,
            $storedUnit,
            $lineProduct instanceof \App\Models\ProductService ? $lineProduct : null,
            $variantPrice
        );
        $amounts = PosController::amountsFromInclusiveTotal($inclusiveUnit * (float) $line->quantity, $rate);

        return [
            'exclusive_unit' => $exclusiveUnit,
            'inclusive_unit' => $inclusiveUnit,
            'qty' => (float) $line->quantity,
            'exclusive' => (float) $amounts['net'],
            'tax' => (float) $amounts['tax'],
            'inclusive' => (float) $amounts['line_total'],
        ];
    }

    /**
     * @param  Collection<int, PosProduct>  $lines
     * @param  Collection<int, Pos>  $posMeta
     * @param  array<int|string, array<string, mixed>>  $taxData
     */
    private function buildProductQtySummary(Collection $lines, Collection $posMeta, array $taxData): Collection
    {
        $groups = [];
        foreach ($lines as $line) {
            $pos = $posMeta->get($line->pos_id);
            if (! $pos) {
                continue;
            }
            if (Pos::normalizeDocumentType($pos->document_type ?? null) === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION) {
                continue;
            }

            $key = ((int) $line->product_id).'|'.((int) ($pos->table_id ?? 0)).'|'.((int) ($pos->pos_user_id ?? 0));
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'product' => $line->product,
                    'table_id' => $pos->table_id,
                    'pos_user_id' => $pos->pos_user_id,
                    'qty_total' => 0.0,
                    'exclusive_total' => 0.0,
                    'tax_total' => 0.0,
                    'inclusive_total' => 0.0,
                    'units' => [],
                ];
            }

            $amounts = $this->invoiceLineAmounts($line, $taxData);
            $groups[$key]['qty_total'] += $amounts['qty'];
            $groups[$key]['exclusive_total'] += $amounts['exclusive'];
            $groups[$key]['tax_total'] += $amounts['tax'];
            $groups[$key]['inclusive_total'] += $amounts['inclusive'];
            $groups[$key]['units'][(string) $amounts['exclusive_unit']] = $amounts['inclusive_unit'];
            if ($groups[$key]['product'] === null && $line->product) {
                $groups[$key]['product'] = $line->product;
            }
        }

        $tableNames = PosTable::whereIn('id', collect($groups)->pluck('table_id')->filter()->unique())->pluck('name', 'id');
        $waiterNames = User::whereIn('id', collect($groups)->pluck('pos_user_id')->filter()->unique())->pluck('name', 'id');

        return collect($groups)
            ->map(function (array $group) use ($tableNames, $waiterNames) {
                $singleUnit = count($group['units']) === 1;
                $exclusiveUnit = $singleUnit ? (float) array_key_first($group['units']) : null;
                $inclusiveUnit = $singleUnit ? (float) reset($group['units']) : null;

                return (object) [
                    'product' => $group['product'],
                    'table_name' => $group['table_id'] ? ($tableNames[$group['table_id']] ?? null) : null,
                    'waiter_name' => $group['pos_user_id'] ? ($waiterNames[$group['pos_user_id']] ?? null) : null,
                    'qty_total' => $group['qty_total'],
                    'exclusive_unit' => $exclusiveUnit,
                    'inclusive_unit' => $inclusiveUnit,
                    'exclusive_total' => round($group['exclusive_total'], 2),
                    'tax_total' => round($group['tax_total'], 2),
                    'inclusive_total' => round($group['inclusive_total'], 2),
                ];
            })
            ->sortByDesc('qty_total')
            ->values();
    }

    /**
     * @param  Collection<int, Pos>  $sales
     * @param  array<int|string, array<string, mixed>>  $taxData
     * @return array{0: Collection<int, Pos>, 1: Collection<int, Pos>}
     */
    private function splitSalesWithInvoiceAmounts(Collection $sales, array $taxData): array
    {
        $sales->each(function (Pos $pos) use ($taxData) {
            $exclusive = 0.0;
            $tax = 0.0;
            $inclusive = 0.0;
            foreach ($pos->items as $line) {
                if (! empty($line->voided_at) || ! empty($line->removed_from_cart_at)) {
                    continue;
                }
                $amounts = $this->invoiceLineAmounts($line, $taxData);
                $exclusive += $amounts['exclusive'];
                $tax += $amounts['tax'];
                $inclusive += $amounts['inclusive'];
            }

            $pos->exclusive_total = round($exclusive, 2);
            $pos->tax_total = round($tax, 2);
            $pos->inclusive_total = round($inclusive, 2);
            $pos->document_type_label = Pos::documentTypeLabel($pos->document_type ?? null);
            $pos->payment_method_label = $pos->posPayments
                ->map(fn ($payment) => PosPayment::labelForMethod($payment->payment_method ?? ''))
                ->filter()
                ->unique()
                ->implode(', ');
        });

        $internal = $sales
            ->filter(fn (Pos $pos) => Pos::normalizeDocumentType($pos->document_type ?? null) === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION)
            ->values();
        $regular = $sales
            ->reject(fn (Pos $pos) => Pos::normalizeDocumentType($pos->document_type ?? null) === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION)
            ->values();

        return [$regular, $internal];
    }

    /**
     * Products at or below the minimum stock alert quantity for the POS shift report footer.
     * Uses the same warehouse stock logic as the Minimum Stock Alert report:
     * per-product low_stock_threshold wins, otherwise the POS low-stock default applies.
     *
     * @return array{rows: Collection, globalThreshold: float}
     */
    public function minimumStockAlertRows(int $creatorId, ?int $warehouseId = null): array
    {
        $globalThreshold = (float) (Utility::getValByName('pos_low_stock_threshold') ?: 10);

        $rows = WarehouseProduct::query()
            ->join('product_services', 'warehouse_products.product_id', '=', 'product_services.id')
            ->join('warehouses', 'warehouse_products.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('product_service_categories', 'product_services.category_id', '=', 'product_service_categories.id')
            ->leftJoin('product_warehouse_stock_alerts', function ($join) {
                $join->on('product_warehouse_stock_alerts.product_id', '=', 'warehouse_products.product_id')
                    ->on('product_warehouse_stock_alerts.warehouse_id', '=', 'warehouse_products.warehouse_id');
            })
            ->where('warehouse_products.created_by', $creatorId)
            ->where('product_services.created_by', $creatorId)
            ->whereIn('product_services.type', ['product', 'kit'])
            // Per-warehouse alert low wins, then the product's own low,
            // then the POS low-stock default — same fallback order as
            // the product form's "Low stock alert at" field.
            ->whereRaw('warehouse_products.quantity <= COALESCE(product_warehouse_stock_alerts.low_stock_threshold, product_services.low_stock_threshold, ?)', [$globalThreshold])
            ->when($warehouseId, fn ($q) => $q->where('warehouse_products.warehouse_id', $warehouseId))
            ->select([
                'warehouse_products.warehouse_id',
                'warehouse_products.product_id',
                'warehouse_products.quantity as warehouse_quantity',
                'warehouses.name as warehouse_name',
                'product_services.name as product_name',
                'product_services.sku',
                'product_services.type',
                'product_services.sale_price',
                \DB::raw('COALESCE(product_warehouse_stock_alerts.low_stock_threshold, product_services.low_stock_threshold) as low_stock_threshold'),
                'product_service_categories.name as category_name',
            ])
            ->orderBy('warehouses.name')
            ->orderBy('warehouse_products.quantity')
            ->orderBy('product_services.name')
            ->get();

        return ['rows' => $rows, 'globalThreshold' => $globalThreshold];
    }

    /**
     * @param  Collection<int, int>  $shiftIds
     * @return array<string, mixed>
     */
    public function buildAggregates(Collection $shiftIds, int $creatorId, int $productFilter = 0): array
    {
        if ($shiftIds->isEmpty()) {
            return [
                'paymentsByMethod' => [],
                'products' => [],
                'voidedLines' => collect(),
                'voidedCount' => 0,
                'cashDrops' => collect(),
                'cashIns' => collect(),
                'expenses' => collect(),
            ];
        }

        $posIds = Pos::whereIn('shift_id', $shiftIds)->pluck('id');
        if ($posIds->isEmpty()) {
            return [
                'paymentsByMethod' => [],
                'products' => [],
                'voidedLines' => collect(),
                'voidedCount' => 0,
                'cashDrops' => collect(),
                'cashIns' => collect(),
                'expenses' => collect(),
            ];
        }

        $payments = PosPayment::whereIn('pos_id', $posIds)->get();
        $paymentsByMethod = [];
        foreach ($payments->groupBy(fn ($p) => $p->payment_method ?? PosPayment::METHOD_CASH) as $method => $items) {
            $label = $this->paymentMethodLabel($method);
            if (! isset($paymentsByMethod[$label])) {
                $paymentsByMethod[$label] = ['name' => $label, 'amount' => 0, 'count' => 0];
            }
            $paymentsByMethod[$label]['amount'] += $items->sum('amount');
            $paymentsByMethod[$label]['count'] += $items->count();
        }
        $balanceCash = (float) InvoicePayment::whereIn('pos_shift_id', $shiftIds)->where('payment_method', 0)->sum('amount');
        if ($balanceCash > 0) {
            $label = __('Balance invoice (cash)');
            if (! isset($paymentsByMethod[$label])) {
                $paymentsByMethod[$label] = ['name' => $label, 'amount' => 0, 'count' => 0];
            }
            $paymentsByMethod[$label]['amount'] += $balanceCash;
        }

        $productQuery = PosProduct::query()
            ->whereIn('pos_id', $posIds)
            ->whereNull('voided_at')
            ->selectRaw('product_id, SUM(quantity) as qty_total, SUM(price * quantity - discount) as amount_total')
            ->groupBy('product_id')
            ->with('product')
            ->orderByRaw('SUM(quantity) DESC');
        if ($productFilter > 0) {
            $productQuery->where('product_id', $productFilter);
        }
        $products = $productQuery->get()->map(function ($row) {
            return [
                'name' => $row->product ? $row->product->name : __('Unknown'),
                'quantity' => (float) $row->qty_total,
                'amount' => (float) $row->amount_total,
            ];
        })->values()->all();

        $voidedCount = PosProduct::whereIn('pos_id', $posIds)->whereNotNull('voided_at')->count();
        $voidedLines = PosProduct::whereIn('pos_id', $posIds)
            ->whereNotNull('voided_at')
            ->with(['product', 'pos', 'voidedByUser'])
            ->orderByDesc('voided_at')
            ->limit(200)
            ->get();

        $cashDrops = PosCashDrop::whereIn('shift_id', $shiftIds)->with(['user', 'shift.branch'])->orderByDesc('created_at')->get();
        $cashIns = PosCashIn::whereIn('shift_id', $shiftIds)->with(['user', 'shift.branch'])->orderByDesc('created_at')->get();
        $expenses = Expense::whereIn('pos_shift_id', $shiftIds)
            ->where('created_by', $creatorId)
            ->cashOutPosted()
            ->with(['shift.branch'])
            ->orderByDesc('date')
            ->get();

        return [
            'paymentsByMethod' => array_values($paymentsByMethod),
            'products' => $products,
            'voidedLines' => $voidedLines,
            'voidedCount' => $voidedCount,
            'cashDrops' => $cashDrops,
            'cashIns' => $cashIns,
            'expenses' => $expenses,
        ];
    }

    /**
     * Summary totals without per-shift metric loops (for large result sets).
     *
     * @param  Collection<int, int>  $shiftIds
     * @return array<string, float|int>
     */
    public function buildSummaryFast(Collection $shiftIds, int $creatorId): array
    {
        if ($shiftIds->isEmpty()) {
            return [
                'shift_count' => 0,
                'sales_total' => 0,
                'cash_total' => 0,
                'card_total' => 0,
                'credit_total' => 0,
                'cash_drops_total' => 0,
                'difference_total' => 0,
                'closed_shift_count' => 0,
            ];
        }

        $posIds = Pos::whereIn('shift_id', $shiftIds)->pluck('id');
        $salesTotal = 0;
        if ($posIds->isNotEmpty()) {
            $salesTotal = (float) PosPayment::whereIn('pos_id', $posIds)->sum('amount');
            $salesTotal += (float) InvoicePayment::whereIn('pos_shift_id', $shiftIds)->where('payment_method', 0)->sum('amount');
        }

        $cashTotal = $posIds->isEmpty() ? 0 : (float) PosPayment::whereIn('pos_id', $posIds)->where(function ($q) {
            $q->where('payment_method', PosPayment::METHOD_CASH)->orWhereNull('payment_method');
        })->sum('amount');
        $cashTotal += (float) InvoicePayment::whereIn('pos_shift_id', $shiftIds)->where('payment_method', 0)->sum('amount');

        $cardTotal = $posIds->isEmpty() ? 0 : (float) PosPayment::whereIn('pos_id', $posIds)
            ->whereIn('payment_method', ['card', 'visa', 'mpesa', 'bank_transfer', 'cheque'])->sum('amount');
        $creditTotal = $posIds->isEmpty() ? 0 : (float) PosPayment::whereIn('pos_id', $posIds)
            ->where('payment_method', PosPayment::METHOD_CREDIT)->sum('amount');

        return [
            'shift_count' => $shiftIds->count(),
            'sales_total' => $salesTotal,
            'cash_total' => $cashTotal,
            'card_total' => $cardTotal,
            'credit_total' => $creditTotal,
            'cash_drops_total' => (float) PosCashDrop::whereIn('shift_id', $shiftIds)->sum('amount'),
            'difference_total' => (float) PosShift::whereIn('id', $shiftIds)->where('status', PosShift::STATUS_CLOSED)->sum('difference'),
            'closed_shift_count' => PosShift::whereIn('id', $shiftIds)->where('status', PosShift::STATUS_CLOSED)->count(),
        ];
    }

    /**
     * @param  Collection<int, PosShift>  $shifts
     * @return array<string, float|int>
     */
    public function buildSummaryFromMetrics(Collection $shifts, int $creatorId): array
    {
        $summary = [
            'shift_count' => $shifts->count(),
            'sales_total' => 0,
            'cash_total' => 0,
            'card_total' => 0,
            'credit_total' => 0,
            'cash_drops_total' => 0,
            'difference_total' => 0,
            'closed_shift_count' => 0,
        ];

        foreach ($shifts as $shift) {
            $m = $this->buildShiftMetrics($shift, $creatorId);
            $summary['sales_total'] += $m['salesTotal'];
            $summary['cash_total'] += $m['cashTotal'];
            $summary['card_total'] += $m['cardTotal'];
            $summary['credit_total'] += $m['creditTotal'];
            $summary['cash_drops_total'] += $m['cashDropsTotal'];
            if ($shift->status === PosShift::STATUS_CLOSED && $m['difference'] !== null) {
                $summary['difference_total'] += $m['difference'];
                $summary['closed_shift_count']++;
            }
        }

        return $summary;
    }

    public function paymentMethodLabel(?string $method): string
    {
        $method = $method ?? PosPayment::METHOD_CASH;
        return match ($method) {
            PosPayment::METHOD_CASH, '' => __('pos.payment_cash'),
            PosPayment::METHOD_CREDIT => __('pos.payment_credit'),
            'card', 'visa' => __('Card'),
            'mpesa' => 'M-Pesa',
            'bank_transfer' => __('Bank Transfer'),
            'cheque' => __('Cheque'),
            default => ucfirst($method),
        };
    }

    /**
     * @return array<int, string>
     */
    public function paymentMethodOptions(): array
    {
        return [
            '' => __('All'),
            PosPayment::METHOD_CASH => __('pos.payment_cash'),
            'card' => __('Card'),
            'mpesa' => 'M-Pesa',
            'bank_transfer' => __('Bank Transfer'),
            'cheque' => __('Cheque'),
            PosPayment::METHOD_CREDIT => __('pos.payment_credit'),
        ];
    }
}
