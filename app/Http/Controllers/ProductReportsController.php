<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\ChartOfAccount;
use App\Models\Invoice;
use App\Models\Pos;
use App\Models\ProductExpiryLot;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\StockAudit;
use App\Models\StockAuditLine;
use App\Models\StockReport;
use App\Models\Utility;
use App\Models\warehouse;
use App\Models\WarehouseLoss;
use App\Models\WarehouseProduct;
use App\Models\WarehouseTransfer;
use App\Services\Accounting\JournalEntryPoster;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ProductReportsController extends Controller
{
    protected function creatorId(): int
    {
        return (int) \Auth::user()->creatorId();
    }

    protected function ensureStockReport(?string $menuPermission = null): void
    {
        $user = \Auth::user();
        if ($menuPermission && $user->can($menuPermission)) {
            return;
        }
        if ($user->can('stock report')) {
            return;
        }
        abort(403, __('Permission Denied.'));
    }

    protected function printResponse(string $view, string $title, array $data = [])
    {
        return response()->view($view, array_merge($data, compact('title')));
    }

    public function index()
    {
        $this->ensureStockReport('menu.dashboard.product_system.overview');

        $kpis = $this->dashboardKpis();

        return view('reports.product.index', compact('kpis'));
    }

    /**
     * Lightweight stock overview KPIs for the product-system dashboard.
     * Unavailable workflows are returned as available=false (N/A), never as fake zeros.
     */
    protected function dashboardKpis(): array
    {
        $creatorId = $this->creatorId();
        $globalThreshold = (float) (Utility::getValByName('pos_low_stock_threshold') ?: 10);
        $today = now()->toDateString();
        $nearUntil = now()->addDays(30)->toDateString();
        $dateFrom = now()->subDays(30)->format('Y-m-d');
        $dateTo = now()->format('Y-m-d');

        $totalStockValue = (float) ProductService::query()
            ->where('created_by', $creatorId)
            ->where('type', 'product')
            ->where('quantity', '!=', 0)
            ->selectRaw('COALESCE(SUM(quantity * COALESCE(purchase_price, 0)), 0) as total')
            ->value('total');

        $stockByWarehouse = WarehouseProduct::query()
            ->join('warehouses', 'warehouse_products.warehouse_id', '=', 'warehouses.id')
            ->join('product_services', 'warehouse_products.product_id', '=', 'product_services.id')
            ->where('warehouse_products.created_by', $creatorId)
            ->where('product_services.created_by', $creatorId)
            ->where('product_services.type', 'product')
            ->groupBy('warehouses.id', 'warehouses.name')
            ->orderBy('warehouses.name')
            ->select([
                'warehouses.id',
                'warehouses.name',
                DB::raw('COALESCE(SUM(warehouse_products.quantity), 0) as qty'),
                DB::raw('COALESCE(SUM(warehouse_products.quantity * COALESCE(product_services.purchase_price, 0)), 0) as value'),
            ])
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'qty' => round((float) $row->qty, 2),
                    'value' => round((float) $row->value, 2),
                ];
            })
            ->values()
            ->all();

        $belowMin = (int) WarehouseProduct::query()
            ->join('product_services', 'warehouse_products.product_id', '=', 'product_services.id')
            ->where('warehouse_products.created_by', $creatorId)
            ->where('product_services.created_by', $creatorId)
            ->whereIn('product_services.type', ['product', 'kit'])
            ->where(function ($qq) use ($globalThreshold) {
                $qq->where(function ($q2) {
                    $q2->whereNotNull('product_services.low_stock_threshold')
                        ->whereRaw('warehouse_products.quantity <= product_services.low_stock_threshold');
                })->orWhere(function ($q2) use ($globalThreshold) {
                    $q2->whereNull('product_services.low_stock_threshold')
                        ->whereRaw('warehouse_products.quantity <= ?', [$globalThreshold]);
                });
            })
            ->count();

        $outOfStock = (int) ProductService::query()
            ->where('created_by', $creatorId)
            ->whereIn('type', ['product', 'kit'])
            ->where('quantity', '<=', 0)
            ->count();

        $nearExpiry = (int) DB::table('product_expiry_lots as l')
            ->join('product_services as p', 'p.id', '=', 'l.product_id')
            ->where('l.created_by', $creatorId)
            ->where('p.created_by', $creatorId)
            ->where('l.quantity_remaining', '>', 0)
            ->whereNotNull('l.expiry_date')
            ->where('l.expiry_date', '>=', $today)
            ->where('l.expiry_date', '<=', $nearUntil)
            ->count();

        $expired = (int) DB::table('product_expiry_lots as l')
            ->join('product_services as p', 'p.id', '=', 'l.product_id')
            ->where('l.created_by', $creatorId)
            ->where('p.created_by', $creatorId)
            ->whereNotNull('l.expiry_date')
            ->where('l.expiry_date', '<', $today)
            ->where('l.quantity_remaining', '>', 0)
            ->count();

        $openDeliveries = (int) Purchase::query()
            ->where('created_by', $creatorId)
            ->whereIn('status', [0, 1, 2, 3]) // Draft, Sent, Unpaid, Partially Paid
            ->count();

        $highValueMovements = (int) DB::table('stock_reports as sr')
            ->join('product_services as p', 'p.id', '=', 'sr.product_id')
            ->where('sr.created_by', $creatorId)
            ->where('p.created_by', $creatorId)
            ->whereIn('sr.type', ['invoice', 'pos'])
            ->whereDate('sr.created_at', '>=', $dateFrom)
            ->whereDate('sr.created_at', '<=', $dateTo)
            ->selectRaw('COUNT(DISTINCT sr.product_id) as c')
            ->value('c');

        return [
            'total_stock_value' => [
                'available' => true,
                'value' => round($totalStockValue, 2),
                'display' => 'currency',
                'route' => 'reports.product.total-stock-value',
                'label' => __('Total stock value'),
            ],
            'stock_by_warehouse' => [
                'available' => true,
                'value' => count($stockByWarehouse),
                'warehouses' => $stockByWarehouse,
                'display' => 'warehouse_list',
                'route' => 'reports.product.warehouse-stock',
                'label' => __('Stock by warehouse'),
            ],
            'below_min' => [
                'available' => true,
                'value' => $belowMin,
                'display' => 'count',
                'route' => 'reports.product.minimum-stock-alert',
                'label' => __('Products below minimum stock'),
            ],
            'out_of_stock' => [
                'available' => true,
                'value' => $outOfStock,
                'display' => 'count',
                'route' => 'reports.product.out-of-stock',
                'label' => __('Out-of-stock products'),
            ],
            'near_expiry' => [
                'available' => true,
                'value' => $nearExpiry,
                'display' => 'count',
                'route' => 'reports.product.near-expiry',
                'label' => __('Products near expiry'),
            ],
            'expired' => [
                'available' => true,
                'value' => $expired,
                'display' => 'count',
                'route' => 'reports.product.expired-products',
                'label' => __('Expired products'),
            ],
            'open_deliveries' => [
                'available' => true,
                'value' => $openDeliveries,
                'display' => 'count',
                'route' => 'purchase.index',
                'label' => __('Open supplier deliveries'),
            ],
            'high_value_movements' => [
                'available' => true,
                'value' => $highValueMovements,
                'display' => 'count',
                'route' => 'reports.product.high-rotation',
                'label' => __('High-value stock movements'),
            ],
        ];
    }

    /**
     * Current stock per product (store check).
     */
    public function storeCheck(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.store_check');

        $creatorId = $this->creatorId();
        $warehouses = warehouse::where('created_by', $creatorId)->orderBy('name')->pluck('name', 'id');
        $warehouseId = $request->filled('warehouse_id') ? (int) $request->warehouse_id : null;
        if ($warehouseId && ! $warehouses->has($warehouseId)) {
            $warehouseId = null;
        }
        $checkDate = $request->filled('check_date')
            ? Carbon::parse($request->check_date)->toDateString()
            : Carbon::now()->toDateString();

        $periodType = $request->input('period_type', 'all');
        if (! in_array($periodType, ['all', 'week', 'month', 'custom'], true)) {
            $periodType = 'all';
        }

        $periodStart = null;
        $periodEnd = null;
        $periodLabel = __('All periods');

        if ($periodType === 'week') {
            $ref = $request->filled('week_ref') ? Carbon::parse($request->week_ref) : Carbon::now();
            $periodStart = $ref->copy()->startOfWeek();
            $periodEnd = $ref->copy()->endOfWeek();
            $periodLabel = __('Week') . ': ' . $periodStart->format('Y-m-d') . ' — ' . $periodEnd->format('Y-m-d');
        } elseif ($periodType === 'month') {
            $ref = $request->filled('month_ref') && preg_match('/^\d{4}-\d{2}$/', (string) $request->month_ref)
                ? Carbon::parse($request->month_ref . '-01')
                : Carbon::now()->startOfMonth();
            $periodStart = $ref->copy()->startOfMonth();
            $periodEnd = $ref->copy()->endOfMonth();
            $periodLabel = $ref->format('F Y');
        } elseif ($periodType === 'custom') {
            if ($request->filled('date_from')) {
                $periodStart = Carbon::parse($request->date_from)->startOfDay();
            }
            if ($request->filled('date_to')) {
                $periodEnd = Carbon::parse($request->date_to)->endOfDay();
            }
            if ($periodStart && $periodEnd) {
                $periodLabel = $periodStart->format('Y-m-d') . ' — ' . $periodEnd->format('Y-m-d');
            } elseif ($periodStart) {
                $periodLabel = __('From') . ' ' . $periodStart->format('Y-m-d');
            } elseif ($periodEnd) {
                $periodLabel = __('To') . ' ' . $periodEnd->format('Y-m-d');
            } else {
                $periodLabel = __('Custom range') . ' — ' . __('set dates');
            }
        }

        $categories = ProductServiceCategory::where('created_by', $creatorId)
            ->where('type', 'product & service')
            ->orderBy('name')
            ->pluck('name', 'id');

        $q = ProductService::where('created_by', $creatorId)
            ->where('type', 'product');

        if ($request->filled('search')) {
            $s = '%' . trim((string) $request->search) . '%';
            $q->where(function ($qq) use ($s) {
                $qq->where('name', 'like', $s)->orWhere('sku', 'like', $s);
            });
        }

        if ($request->filled('category_id')) {
            $q->where('category_id', (int) $request->category_id);
        }

        if ($request->filled('row_filter')) {
            $rf = '%' . trim((string) $request->row_filter) . '%';
            $q->where('row_no', 'like', $rf);
        }

        if ($request->filled('column_filter')) {
            $cf = '%' . trim((string) $request->column_filter) . '%';
            $q->where('shelf_no', 'like', $cf);
        }

        $products = $q->orderBy('name')->get();

        $warehouseQtyMap = [];
        if ($warehouseId && $products->isNotEmpty()) {
            $warehouseQtyMap = WarehouseProduct::where('created_by', $creatorId)
                ->where('warehouse_id', $warehouseId)
                ->whereIn('product_id', $products->pluck('id'))
                ->pluck('quantity', 'product_id')
                ->map(fn ($q) => (float) $q)
                ->all();
        }

        foreach ($products as $p) {
            if ($warehouseId) {
                $p->system_quantity = (float) ($warehouseQtyMap[$p->id] ?? 0);
            } else {
                $p->system_quantity = (float) ($p->quantity ?? 0);
            }
        }

        $history = StockAudit::with('warehouse')
            ->where('created_by', $creatorId)
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        if ($request->boolean('print')) {
            return $this->printResponse('reports.product.print.store_check', __('Store check'), compact('products', 'warehouseId', 'checkDate'));
        }

        return view('reports.product.store_check', compact(
            'products',
            'categories',
            'warehouses',
            'warehouseId',
            'checkDate',
            'periodType',
            'periodStart',
            'periodEnd',
            'periodLabel',
            'history'
        ));
    }

    /**
     * Persist a stock audit snapshot (system vs physical counts).
     */
    public function storeStockAudit(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.store_check');

        $creatorId = $this->creatorId();

        $validated = $request->validate([
            'warehouse_id' => [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where(fn ($q) => $q->where('created_by', $creatorId)),
            ],
            'check_date' => 'required|date',
            'period_type' => 'required|in:all,week,month,custom',
            'week_ref' => 'nullable|date',
            'month_ref' => 'nullable|string|max:7',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'integer',
            'physical' => 'nullable|array',
            'physical.*' => 'nullable|numeric',
        ]);

        $periodType = $validated['period_type'];
        $periodStart = null;
        $periodEnd = null;

        if ($periodType === 'week') {
            $ref = $request->filled('week_ref') ? Carbon::parse($request->week_ref) : Carbon::now();
            $periodStart = $ref->copy()->startOfWeek()->toDateString();
            $periodEnd = $ref->copy()->endOfWeek()->toDateString();
        } elseif ($periodType === 'month') {
            $ref = $request->filled('month_ref') && preg_match('/^\d{4}-\d{2}$/', (string) $request->month_ref)
                ? Carbon::parse($request->month_ref . '-01')
                : Carbon::now()->startOfMonth();
            $periodStart = $ref->copy()->startOfMonth()->toDateString();
            $periodEnd = $ref->copy()->endOfMonth()->toDateString();
        } elseif ($periodType === 'custom') {
            $periodStart = $request->filled('date_from') ? Carbon::parse($request->date_from)->toDateString() : null;
            $periodEnd = $request->filled('date_to') ? Carbon::parse($request->date_to)->toDateString() : null;
        }

        $productIds = array_values(array_unique(array_map('intval', $validated['product_ids'])));
        $warehouseId = (int) $validated['warehouse_id'];
        $checkDate = Carbon::parse($validated['check_date'])->toDateString();

        $products = ProductService::where('created_by', $creatorId)
            ->where('type', 'product')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        if ($products->count() !== count($productIds)) {
            return redirect()
                ->route('reports.product.store-check', $request->only([
                    'warehouse_id', 'check_date', 'period_type', 'week_ref', 'month_ref', 'date_from', 'date_to',
                    'search', 'category_id', 'row_filter', 'column_filter',
                ]))
                ->with('error', __('Invalid product selection.'));
        }

        $warehouseQtyMap = WarehouseProduct::where('created_by', $creatorId)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $productIds)
            ->pluck('quantity', 'product_id')
            ->map(fn ($q) => (float) $q)
            ->all();

        $physicalMap = $validated['physical'] ?? [];

        $audit = DB::transaction(function () use ($validated, $products, $physicalMap, $creatorId, $periodType, $periodStart, $periodEnd, $warehouseId, $checkDate, $warehouseQtyMap) {
            $audit = StockAudit::create([
                'created_by' => $creatorId,
                'warehouse_id' => $warehouseId,
                'check_date' => $checkDate,
                'period_type' => $periodType,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($products as $product) {
                $pid = (int) $product->id;
                $raw = $physicalMap[$pid] ?? null;
                $physical = ($raw === '' || $raw === null) ? null : (float) $raw;
                $system = round((float) ($warehouseQtyMap[$pid] ?? 0), 4);
                $difference = $physical === null ? null : round($physical - $system, 4);

                StockAuditLine::create([
                    'stock_audit_id' => $audit->id,
                    'product_id' => $pid,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'category_id' => $product->category_id,
                    'row_no' => $product->row_no,
                    'shelf_no' => $product->shelf_no,
                    'system_quantity' => $system,
                    'physical_quantity' => $physical,
                    'difference' => $difference,
                ]);
            }

            return $audit;
        });

        return redirect()
            ->route('reports.product.store-check.show', $audit->id)
            ->with('success', __('Stock audit saved successfully.'));
    }

    public function storeCheckHistory(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.store_check');

        $creatorId = $this->creatorId();
        $warehouses = warehouse::where('created_by', $creatorId)->orderBy('name')->pluck('name', 'id');

        $warehouseId = $request->filled('warehouse_id') ? (int) $request->warehouse_id : null;
        if ($warehouseId && ! $warehouses->has($warehouseId)) {
            $warehouseId = null;
        }

        $dateFrom = $request->filled('date_from') ? Carbon::parse($request->date_from)->toDateString() : null;
        $dateTo = $request->filled('date_to') ? Carbon::parse($request->date_to)->toDateString() : null;

        $query = StockAudit::with('warehouse')
            ->where('created_by', $creatorId)
            ->withCount('lines');

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        if ($dateFrom) {
            $query->whereDate(DB::raw('COALESCE(check_date, DATE(created_at))'), '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate(DB::raw('COALESCE(check_date, DATE(created_at))'), '<=', $dateTo);
        }

        $audits = $query
            ->orderByRaw('COALESCE(check_date, DATE(created_at)) DESC')
            ->orderByDesc('id')
            ->paginate(25)
            ->appends($request->query());

        return view('reports.product.store_check_history', compact(
            'audits',
            'warehouses',
            'warehouseId',
            'dateFrom',
            'dateTo'
        ));
    }

    public function storeCheckShow(Request $request, $id)
    {
        $this->ensureStockReport('menu.dashboard.product_system.store_check');

        $audit = StockAudit::with(['warehouse', 'lines'])
            ->where('created_by', $this->creatorId())
            ->findOrFail($id);

        if ($request->boolean('print')) {
            return view('reports.product.print.store_check_show', compact('audit'));
        }

        return view('reports.product.store_check_show', compact('audit'));
    }

    /**
     * Warehouse-wise stock with row/shelf from product master.
     */
    public function warehouseStock(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.warehouse_stock');

        $creatorId = $this->creatorId();
        $warehouses = warehouse::where('created_by', $creatorId)->orderBy('name')->get();

        $query = WarehouseProduct::query()
            ->join('product_services', 'warehouse_products.product_id', '=', 'product_services.id')
            ->join('warehouses', 'warehouse_products.warehouse_id', '=', 'warehouses.id')
            ->where('warehouse_products.created_by', $creatorId)
            ->where('product_services.created_by', $creatorId)
            ->select([
                'warehouse_products.id',
                'warehouse_products.warehouse_id',
                'warehouse_products.product_id',
                'warehouse_products.quantity',
                'warehouses.name as warehouse_name',
                'product_services.name as product_name',
                'product_services.sku',
            ])
            ->selectRaw('COALESCE(NULLIF(warehouse_products.row_no, ""), NULLIF(product_services.row_no, "")) as row_no')
            ->selectRaw('COALESCE(NULLIF(warehouse_products.shelf_no, ""), NULLIF(product_services.shelf_no, "")) as shelf_no');

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_products.warehouse_id', (int) $request->warehouse_id);
        }
        if ($request->filled('search')) {
            $s = '%' . trim((string) $request->search) . '%';
            $query->where(function ($qq) use ($s) {
                $qq->where('product_services.name', 'like', $s)->orWhere('product_services.sku', 'like', $s);
            });
        }

        $rows = $query->orderBy('warehouses.name')->orderBy('product_services.name')->get();

        if ($request->boolean('print')) {
            return $this->printResponse('reports.product.print.warehouse_stock', __('Warehouse stock'), compact('rows'));
        }

        return view('reports.product.warehouse_stock', compact('rows', 'warehouses'));
    }

    /**
     * Total stock value: qty × purchase/sale, with and without VAT. All warehouses or one.
     */
    public function totalStockValue(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.total_stock_value');

        [
            'rows' => $rows,
            'warehouses' => $warehouses,
            'totals' => $totals,
        ] = $this->totalStockValueData($request);

        if ($request->boolean('print')) {
            return $this->printResponse('reports.product.print.total_stock_value', __('Total stock value'), compact('rows', 'totals'));
        }

        return view('reports.product.total_stock_value', compact('rows', 'warehouses', 'totals'));
    }

    public function totalStockValueExport(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.total_stock_value');

        ['rows' => $rows, 'totals' => $totals] = $this->totalStockValueData($request);

        $fileName = 'total_stock_value_' . date('Y-m-d_His') . '.csv';
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=$fileName",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($rows, $totals) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($file, [
                __('ID'),
                __('SKU'),
                __('Description'),
                __('Warehouse'),
                __('QTY'),
                __('Unit Price (Purchase)'),
                __('Unit Price (Sale)'),
                __('Purchase Value (without VAT)'),
                __('Purchase Value (with VAT)'),
                __('Sale Value (without VAT)'),
                __('Sale Value (with VAT)'),
            ]);

            foreach ($rows as $r) {
                fputcsv($file, [
                    $r->product_id,
                    $r->sku ?: '',
                    $r->description,
                    $r->warehouse_name ?: '',
                    $r->qty,
                    $r->unit_purchase_price ?? 0,
                    $r->unit_sale_price ?? 0,
                    $r->purchase_ex_vat,
                    $r->purchase_inc_vat,
                    $r->sale_ex_vat,
                    $r->sale_inc_vat,
                ]);
            }

            fputcsv($file, []);
            fputcsv($file, [
                '',
                '',
                __('Total'),
                '',
                $totals['qty'],
                '',
                '',
                $totals['purchase_ex_vat'],
                $totals['purchase_inc_vat'],
                $totals['sale_ex_vat'],
                $totals['sale_inc_vat'],
            ]);

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    protected function totalStockValueData(Request $request): array
    {
        $creatorId = $this->creatorId();
        $warehouses = warehouse::where('created_by', $creatorId)->orderBy('name')->get();
        $warehouseId = $request->filled('warehouse_id') ? (int) $request->warehouse_id : 0;

        if ($warehouseId > 0) {
            $query = WarehouseProduct::query()
                ->join('product_services', 'warehouse_products.product_id', '=', 'product_services.id')
                ->join('warehouses', 'warehouse_products.warehouse_id', '=', 'warehouses.id')
                ->where('warehouse_products.created_by', $creatorId)
                ->where('product_services.created_by', $creatorId)
                ->where('product_services.type', 'product')
                ->where('warehouse_products.warehouse_id', $warehouseId)
                ->where('warehouse_products.quantity', '!=', 0)
                ->select([
                    'product_services.id as product_id',
                    'product_services.sku',
                    'product_services.name',
                    'product_services.description',
                    'product_services.purchase_price',
                    'product_services.sale_price',
                    'product_services.sale_price_type',
                    'product_services.tax_id',
                    'warehouse_products.quantity as qty',
                    'warehouses.name as warehouse_name',
                ]);

            if ($request->filled('search')) {
                $s = '%' . trim((string) $request->search) . '%';
                $query->where(function ($qq) use ($s) {
                    $qq->where('product_services.name', 'like', $s)
                        ->orWhere('product_services.sku', 'like', $s)
                        ->orWhere('product_services.description', 'like', $s)
                        ->orWhere('product_services.id', 'like', $s);
                });
            }

            $raw = $query->orderBy('product_services.name')->get();
        } else {
            $query = ProductService::query()
                ->where('created_by', $creatorId)
                ->where('type', 'product')
                ->where('quantity', '!=', 0)
                ->select([
                    'id as product_id',
                    'sku',
                    'name',
                    'description',
                    'purchase_price',
                    'sale_price',
                    'sale_price_type',
                    'tax_id',
                    'quantity as qty',
                ]);

            if ($request->filled('search')) {
                $s = '%' . trim((string) $request->search) . '%';
                $query->where(function ($qq) use ($s) {
                    $qq->where('name', 'like', $s)
                        ->orWhere('sku', 'like', $s)
                        ->orWhere('description', 'like', $s)
                        ->orWhere('id', 'like', $s);
                });
            }

            $raw = $query->orderBy('name')->get();
        }

        $rows = collect();
        $totals = [
            'qty' => 0.0,
            'purchase_ex_vat' => 0.0,
            'purchase_inc_vat' => 0.0,
            'sale_ex_vat' => 0.0,
            'sale_inc_vat' => 0.0,
        ];

        foreach ($raw as $item) {
            $qty = (float) ($item->qty ?? 0);
            $purchaseUnit = (float) ($item->purchase_price ?? 0);
            $saleUnit = (float) ($item->sale_price ?? 0);
            $taxRate = (float) Utility::totalTaxRate((string) ($item->tax_id ?? ''));
            $saleType = (string) ($item->sale_price_type ?? 'exclusive');

            $purchaseEx = $qty * $purchaseUnit;
            $purchaseInc = $taxRate > 0 ? $purchaseEx * (1 + ($taxRate / 100)) : $purchaseEx;

            if ($saleType === 'inclusive') {
                $saleInc = $qty * $saleUnit;
                $saleEx = $taxRate > 0 ? $saleInc / (1 + ($taxRate / 100)) : $saleInc;
            } else {
                $saleEx = $qty * $saleUnit;
                $saleInc = $taxRate > 0 ? $saleEx * (1 + ($taxRate / 100)) : $saleEx;
            }

            $desc = trim((string) ($item->name ?? ''));
            $extra = trim((string) ($item->description ?? ''));
            if ($extra !== '') {
                $desc = $desc !== '' ? ($desc . ' — ' . $extra) : $extra;
            }

            $row = (object) [
                'product_id' => (int) $item->product_id,
                'sku' => $item->sku,
                'description' => $desc,
                'warehouse_name' => $warehouseId > 0 ? ($item->warehouse_name ?? '') : __('All warehouses'),
                'qty' => $qty,
                'unit_purchase_price' => round($purchaseUnit, 4),
                'unit_sale_price' => round($saleUnit, 4),
                'purchase_ex_vat' => round($purchaseEx, 2),
                'purchase_inc_vat' => round($purchaseInc, 2),
                'sale_ex_vat' => round($saleEx, 2),
                'sale_inc_vat' => round($saleInc, 2),
            ];

            $rows->push($row);
            $totals['qty'] += $qty;
            $totals['purchase_ex_vat'] += $purchaseEx;
            $totals['purchase_inc_vat'] += $purchaseInc;
            $totals['sale_ex_vat'] += $saleEx;
            $totals['sale_inc_vat'] += $saleInc;
        }

        foreach (['qty', 'purchase_ex_vat', 'purchase_inc_vat', 'sale_ex_vat', 'sale_inc_vat'] as $k) {
            $totals[$k] = round($totals[$k], 2);
        }

        return compact('rows', 'warehouses', 'totals');
    }

    /**
     * High-rotation / best-selling products from invoice & POS stock movements.
     */
    public function highRotation(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.high_rotation');

        [
            'rows' => $rows,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'sortBy' => $sortBy,
            'limit' => $limit,
        ] = $this->highRotationData($request);

        if ($request->boolean('print')) {
            return $this->printResponse('reports.product.print.high_rotation', __('High-value stock movements'), compact('rows', 'dateFrom', 'dateTo'));
        }

        return view('reports.product.high_rotation', compact('rows', 'dateFrom', 'dateTo', 'sortBy', 'limit'));
    }

    public function highRotationExport(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.high_rotation');

        ['rows' => $rows] = $this->highRotationData($request);

        $fileName = 'high_value_stock_movements_' . date('Y-m-d_His') . '.csv';
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=$fileName",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($rows) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($file, [
                __('Rank'),
                __('ID'),
                __('SKU'),
                __('Description'),
                __('Qty sold'),
                __('Movements'),
                __('Est. sale value'),
                __('Current stock'),
                __('Rotation'),
            ]);

            foreach ($rows as $i => $r) {
                fputcsv($file, [
                    $i + 1,
                    $r->product_id,
                    $r->sku ?: '',
                    $r->description,
                    $r->qty_sold,
                    $r->movements,
                    $r->sale_value,
                    $r->current_stock,
                    $r->rotation,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    protected function highRotationData(Request $request): array
    {
        $creatorId = $this->creatorId();
        $dateFrom = $request->input('date_from', now()->subDays(30)->format('Y-m-d'));
        $dateTo = $request->input('date_to', now()->format('Y-m-d'));
        $sortBy = in_array($request->input('sort_by'), ['qty', 'value', 'rotation'], true)
            ? $request->input('sort_by')
            : 'qty';
        $limit = max(10, min(500, (int) $request->input('limit', 50)));

        $types = ['invoice', 'pos'];
        if ($request->input('source') === 'invoice') {
            $types = ['invoice'];
        } elseif ($request->input('source') === 'pos') {
            $types = ['pos'];
        }

        $q = DB::table('stock_reports as sr')
            ->join('product_services as p', 'p.id', '=', 'sr.product_id')
            ->where('sr.created_by', $creatorId)
            ->where('p.created_by', $creatorId)
            ->whereIn('sr.type', $types)
            ->whereDate('sr.created_at', '>=', $dateFrom)
            ->whereDate('sr.created_at', '<=', $dateTo)
            ->select([
                'p.id as product_id',
                'p.sku',
                'p.name',
                'p.description',
                'p.sale_price',
                'p.quantity as current_stock',
                DB::raw('SUM(ABS(sr.quantity)) as qty_sold'),
                DB::raw('COUNT(sr.id) as movements'),
            ])
            ->groupBy('p.id', 'p.sku', 'p.name', 'p.description', 'p.sale_price', 'p.quantity');

        if ($request->filled('search')) {
            $s = '%' . trim((string) $request->search) . '%';
            $q->where(function ($qq) use ($s) {
                $qq->where('p.name', 'like', $s)
                    ->orWhere('p.sku', 'like', $s)
                    ->orWhere('p.description', 'like', $s)
                    ->orWhere('p.id', 'like', $s);
            });
        }

        $raw = $q->get();

        $rows = $raw->map(function ($item) {
            $qtySold = (float) $item->qty_sold;
            $salePrice = (float) ($item->sale_price ?? 0);
            $saleValue = round($qtySold * $salePrice, 2);
            $currentStock = (float) ($item->current_stock ?? 0);
            $rotation = $currentStock > 0
                ? round($qtySold / $currentStock, 2)
                : ($qtySold > 0 ? round($qtySold, 2) : 0);

            $desc = trim((string) ($item->name ?? ''));
            $extra = trim((string) ($item->description ?? ''));
            if ($extra !== '') {
                $desc = $desc !== '' ? ($desc . ' — ' . $extra) : $extra;
            }

            return (object) [
                'product_id' => (int) $item->product_id,
                'sku' => $item->sku,
                'description' => $desc,
                'qty_sold' => round($qtySold, 2),
                'movements' => (int) $item->movements,
                'sale_value' => $saleValue,
                'current_stock' => round($currentStock, 2),
                'rotation' => $rotation,
            ];
        });

        if ($sortBy === 'value') {
            $rows = $rows->sortByDesc('sale_value')->values();
        } elseif ($sortBy === 'rotation') {
            $rows = $rows->sortByDesc('rotation')->values();
        } else {
            $rows = $rows->sortByDesc('qty_sold')->values();
        }

        $rows = $rows->take($limit)->values();

        return compact('rows', 'dateFrom', 'dateTo', 'sortBy', 'limit');
    }

    /**
     * Products with zero or negative on-hand quantity.
     */
    public function outOfStock(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.out_of_stock');

        ['products' => $products, 'categories' => $categories] = $this->outOfStockData($request);

        if ($request->boolean('print')) {
            return $this->printResponse('reports.product.print.out_of_stock', __('Out-of-stock products'), compact('products'));
        }

        return view('reports.product.out_of_stock', compact('products', 'categories'));
    }

    public function outOfStockExport(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.out_of_stock');

        ['products' => $products] = $this->outOfStockData($request);

        $fileName = 'out_of_stock_products_' . date('Y-m-d_His') . '.csv';
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=$fileName",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($products) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($file, [
                __('Product'),
                __('SKU'),
                __('Category'),
                __('Type'),
                __('Current Stock'),
                __('Sale Price'),
            ]);

            foreach ($products as $p) {
                fputcsv($file, [
                    $p->name,
                    $p->sku ?: '',
                    $p->category->name ?? '',
                    ucfirst((string) $p->type),
                    $p->quantity,
                    $p->sale_price ?? 0,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    protected function outOfStockData(Request $request): array
    {
        $creatorId = $this->creatorId();

        $categories = ProductServiceCategory::where('created_by', $creatorId)
            ->where('type', 'product & service')
            ->orderBy('name')
            ->pluck('name', 'id');

        $q = ProductService::with('category')
            ->where('created_by', $creatorId)
            ->whereIn('type', ['product', 'kit'])
            ->where('quantity', '<=', 0);

        if ($request->filled('search')) {
            $s = '%' . trim((string) $request->search) . '%';
            $q->where(function ($qq) use ($s) {
                $qq->where('name', 'like', $s)->orWhere('sku', 'like', $s);
            });
        }

        if ($request->filled('category_id')) {
            $q->where('category_id', (int) $request->category_id);
        }

        $products = $q->orderBy('name')->get();

        return compact('products', 'categories');
    }

    /**
     * Products at or below "Low stock alert at" (or global default threshold).
     */
    public function minimumStockAlert(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.minimum_stock_alert');

        [
            'rows' => $rows,
            'categories' => $categories,
            'warehouses' => $warehouses,
            'globalThreshold' => $globalThreshold,
            'warehouseId' => $warehouseId,
        ] = $this->minimumStockAlertData($request);

        if ($request->boolean('print')) {
            return $this->printResponse('reports.product.print.minimum_stock_alert', __('Minimum Stock Alert'), compact('rows', 'globalThreshold'));
        }

        return view('reports.product.minimum_stock_alert', compact('rows', 'categories', 'warehouses', 'globalThreshold', 'warehouseId'));
    }

    public function minimumStockAlertExport(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.minimum_stock_alert');

        ['rows' => $rows, 'globalThreshold' => $globalThreshold] = $this->minimumStockAlertData($request);

        $fileName = 'minimum_stock_alert_' . date('Y-m-d_His') . '.csv';
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=$fileName",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($rows, $globalThreshold) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($file, [
                __('Warehouse'),
                __('Product'),
                __('SKU'),
                __('Category'),
                __('Type'),
                __('Warehouse Stock'),
                __('Low stock alert at'),
                __('Shortage'),
                __('Sale Price'),
            ]);

            foreach ($rows as $r) {
                $threshold = $r->low_stock_threshold !== null ? (float) $r->low_stock_threshold : (float) $globalThreshold;
                $stockQty = (float) $r->warehouse_quantity;
                $shortage = max(0, $threshold - $stockQty);
                fputcsv($file, [
                    $r->warehouse_name,
                    $r->product_name,
                    $r->sku ?: '',
                    $r->category_name ?: '',
                    ucfirst((string) $r->type),
                    $stockQty,
                    $threshold,
                    $shortage,
                    $r->sale_price ?? 0,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    protected function minimumStockAlertData(Request $request): array
    {
        $creatorId = $this->creatorId();
        $globalThreshold = (float) (Utility::getValByName('pos_low_stock_threshold') ?: 10);
        if ($request->filled('threshold')) {
            $globalThreshold = (float) $request->threshold;
        }

        $categories = ProductServiceCategory::where('created_by', $creatorId)
            ->where('type', 'product & service')
            ->orderBy('name')
            ->pluck('name', 'id');

        $warehouses = warehouse::where('created_by', $creatorId)->orderBy('name')->pluck('name', 'id');
        $warehouseId = $request->filled('warehouse_id') ? (int) $request->warehouse_id : null;
        if ($warehouseId && ! $warehouses->has($warehouseId)) {
            $warehouseId = null;
        }

        $q = WarehouseProduct::query()
            ->join('product_services', 'warehouse_products.product_id', '=', 'product_services.id')
            ->join('warehouses', 'warehouse_products.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('product_service_categories', 'product_services.category_id', '=', 'product_service_categories.id')
            ->where('warehouse_products.created_by', $creatorId)
            ->where('product_services.created_by', $creatorId)
            ->whereIn('product_services.type', ['product', 'kit'])
            ->where(function ($qq) use ($globalThreshold) {
                $qq->where(function ($q2) {
                    $q2->whereNotNull('product_services.low_stock_threshold')
                        ->whereRaw('warehouse_products.quantity <= product_services.low_stock_threshold');
                })->orWhere(function ($q2) use ($globalThreshold) {
                    $q2->whereNull('product_services.low_stock_threshold')
                        ->whereRaw('warehouse_products.quantity <= ?', [$globalThreshold]);
                });
            })
            ->select([
                'warehouse_products.id as warehouse_product_id',
                'warehouse_products.warehouse_id',
                'warehouse_products.product_id',
                'warehouse_products.quantity as warehouse_quantity',
                'warehouses.name as warehouse_name',
                'product_services.name as product_name',
                'product_services.sku',
                'product_services.type',
                'product_services.sale_price',
                'product_services.low_stock_threshold',
                'product_service_categories.name as category_name',
            ]);

        if ($warehouseId) {
            $q->where('warehouse_products.warehouse_id', $warehouseId);
        }

        if ($request->filled('search')) {
            $s = '%' . trim((string) $request->search) . '%';
            $q->where(function ($qq) use ($s) {
                $qq->where('product_services.name', 'like', $s)->orWhere('product_services.sku', 'like', $s);
            });
        }

        if ($request->filled('category_id')) {
            $q->where('product_services.category_id', (int) $request->category_id);
        }

        $rows = $q->orderBy('warehouses.name')
            ->orderBy('warehouse_products.quantity')
            ->orderBy('product_services.name')
            ->get();

        return compact('rows', 'categories', 'warehouses', 'globalThreshold', 'warehouseId');
    }

    /**
     * Batch/lot lines expiring within N days (still have remaining stock).
     */
    public function nearExpiry(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.near_expiry');

        ['rows' => $rows, 'warehouses' => $warehouses, 'days' => $days] = $this->nearExpiryData($request);

        if ($request->boolean('print')) {
            return $this->printResponse('reports.product.print.near_expiry', __('Products near expiry'), compact('rows'));
        }

        return view('reports.product.near_expiry', compact('rows', 'warehouses', 'days'));
    }

    public function nearExpiryExport(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.near_expiry');

        ['rows' => $rows, 'days' => $days] = $this->nearExpiryData($request);

        $fileName = 'products_near_expiry_' . date('Y-m-d_His') . '.csv';
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=$fileName",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($rows) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($file, [
                __('Product'),
                __('SKU'),
                __('Batch / Lot'),
                __('Warehouse'),
                __('Expiry date'),
                __('Days left'),
                __('Qty remaining'),
                __('Alert days'),
            ]);

            $today = Carbon::today();
            foreach ($rows as $r) {
                $expiry = $r->expiry_date ? Carbon::parse($r->expiry_date) : null;
                $daysLeft = $expiry ? $today->diffInDays($expiry, false) : '';
                fputcsv($file, [
                    $r->product_name,
                    $r->sku ?: '',
                    $r->batch_no ?: '',
                    $r->warehouse_name ?: '',
                    $expiry ? $expiry->format('Y-m-d') : '',
                    $daysLeft,
                    $r->quantity_remaining,
                    $r->expiry_alert_days ?? '',
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    protected function nearExpiryData(Request $request): array
    {
        $creatorId = $this->creatorId();
        $days = max(1, min(365, (int) $request->input('days', 30)));
        $today = now()->toDateString();
        $until = now()->addDays($days)->toDateString();

        $warehouses = warehouse::where('created_by', $creatorId)->orderBy('name')->get();

        $q = DB::table('product_expiry_lots as l')
            ->join('product_services as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->where('l.created_by', $creatorId)
            ->where('p.created_by', $creatorId)
            ->where('l.quantity_remaining', '>', 0)
            ->whereNotNull('l.expiry_date')
            ->where('l.expiry_date', '>=', $today)
            ->where('l.expiry_date', '<=', $until);

        if ($request->filled('warehouse_id')) {
            $q->where('l.warehouse_id', (int) $request->warehouse_id);
        }

        if ($request->filled('search')) {
            $s = '%' . trim((string) $request->search) . '%';
            $q->where(function ($qq) use ($s) {
                $qq->where('p.name', 'like', $s)
                    ->orWhere('p.sku', 'like', $s)
                    ->orWhere('l.batch_no', 'like', $s);
            });
        }

        if ($request->boolean('use_product_alert_days')) {
            $q->whereNotNull('p.expiry_alert_days')
                ->where('p.expiry_alert_days', '>', 0)
                ->whereRaw('l.expiry_date <= DATE_ADD(?, INTERVAL p.expiry_alert_days DAY)', [$today]);
        }

        $rows = $q->select([
                'l.id',
                'l.batch_no',
                'l.expiry_date',
                'l.mfg_date',
                'l.quantity_remaining',
                'l.warehouse_id',
                'p.name as product_name',
                'p.sku',
                'p.expiry_alert_days',
                'w.name as warehouse_name',
            ])
            ->orderBy('l.expiry_date')
            ->orderBy('p.name')
            ->get();

        return compact('rows', 'warehouses', 'days');
    }

    /**
     * Batch/lot lines that have already expired and still have remaining stock.
     */
    public function expiredProducts(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.expired_products');

        ['rows' => $rows, 'warehouses' => $warehouses] = $this->expiredProductsData($request);

        if ($request->boolean('print')) {
            return $this->printResponse('reports.product.print.expired_products', __('Expired products'), compact('rows'));
        }

        return view('reports.product.expired_products', compact('rows', 'warehouses'));
    }

    public function expiredProductsExport(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.expired_products');

        ['rows' => $rows] = $this->expiredProductsData($request);

        $fileName = 'expired_products_' . date('Y-m-d_His') . '.csv';
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=$fileName",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($rows) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($file, [
                __('Product'),
                __('SKU'),
                __('Batch / Lot'),
                __('Warehouse'),
                __('Expiry date'),
                __('Days overdue'),
                __('Qty remaining'),
            ]);

            $today = Carbon::today();
            foreach ($rows as $r) {
                $expiry = $r->expiry_date ? Carbon::parse($r->expiry_date) : null;
                $daysOverdue = $expiry ? $expiry->diffInDays($today) : '';
                fputcsv($file, [
                    $r->product_name,
                    $r->sku ?: '',
                    $r->batch_no ?: '',
                    $r->warehouse_name ?: '',
                    $expiry ? $expiry->format('Y-m-d') : '',
                    $daysOverdue,
                    $r->quantity_remaining,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    protected function expiredProductsData(Request $request): array
    {
        $creatorId = $this->creatorId();
        $today = now()->toDateString();

        $warehouses = warehouse::where('created_by', $creatorId)->orderBy('name')->get();

        $q = DB::table('product_expiry_lots as l')
            ->join('product_services as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->where('l.created_by', $creatorId)
            ->where('p.created_by', $creatorId)
            ->whereNotNull('l.expiry_date')
            ->where('l.expiry_date', '<', $today);

        $remaining = $request->input('remaining', 'with_stock');
        if ($remaining === 'with_stock') {
            $q->where('l.quantity_remaining', '>', 0);
        } elseif ($remaining === 'depleted') {
            $q->where('l.quantity_remaining', '<=', 0);
        }

        if ($request->filled('warehouse_id')) {
            $q->where('l.warehouse_id', (int) $request->warehouse_id);
        }

        if ($request->filled('search')) {
            $s = '%' . trim((string) $request->search) . '%';
            $q->where(function ($qq) use ($s) {
                $qq->where('p.name', 'like', $s)
                    ->orWhere('p.sku', 'like', $s)
                    ->orWhere('l.batch_no', 'like', $s);
            });
        }

        $rows = $q->select([
                'l.id',
                'l.product_id',
                'l.batch_no',
                'l.expiry_date',
                'l.mfg_date',
                'l.quantity_remaining',
                'l.warehouse_id',
                'p.name as product_name',
                'p.sku',
                'p.purchase_price',
                'w.name as warehouse_name',
            ])
            ->orderBy('l.expiry_date')
            ->orderBy('p.name')
            ->get();

        return compact('rows', 'warehouses');
    }

    /**
     * Convert selected expired lots into warehouse loss write-offs.
     */
    public function convertExpiredToLoss(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.expired_products');

        if (! \Auth::user()->can('create warehouse') && ! \Auth::user()->can('manage warehouse') && \Auth::user()->type !== 'company') {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validated = $request->validate([
            'lot_ids' => 'required|array|min:1',
            'lot_ids.*' => 'integer',
            'issuer_name' => 'nullable|string|max:150',
            'authorized_by' => 'nullable|string|max:150',
            'witness_name' => 'nullable|string|max:150',
        ]);

        $creatorId = $this->creatorId();
        $lotIds = array_values(array_unique(array_map('intval', $validated['lot_ids'])));
        $userName = \Auth::user()->name ?? __('System');
        $issuer = trim((string) ($validated['issuer_name'] ?? '')) ?: $userName;
        $authorized = trim((string) ($validated['authorized_by'] ?? '')) ?: $userName;
        $witness = trim((string) ($validated['witness_name'] ?? '')) ?: $userName;
        $date = now()->toDateString();

        $lots = ProductExpiryLot::query()
            ->where('created_by', $creatorId)
            ->whereIn('id', $lotIds)
            ->where('quantity_remaining', '>', 0)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<', $date)
            ->get();

        if ($lots->isEmpty()) {
            return redirect()->back()->with('error', __('No expired lots with remaining stock were selected.'));
        }

        $writeOffNos = [];

        DB::transaction(function () use ($lots, $creatorId, $issuer, $authorized, $witness, $date, &$writeOffNos) {
            $byWarehouse = $lots->groupBy(function ($lot) {
                return (int) ($lot->warehouse_id ?: 0);
            });

            $seq = (int) (WarehouseLoss::where('created_by', $creatorId)->max('id') ?? 0);

            foreach ($byWarehouse as $warehouseId => $warehouseLots) {
                if ($warehouseId <= 0) {
                    continue;
                }

                $seq++;
                $writeOffNo = 'WO-' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
                $writeOffNos[] = $writeOffNo;
                $totalCost = 0.0;

                foreach ($warehouseLots as $lot) {
                    $qty = (float) $lot->quantity_remaining;
                    if ($qty <= 0) {
                        continue;
                    }

                    $product = ProductService::where('created_by', $creatorId)->find($lot->product_id);
                    if (! $product) {
                        continue;
                    }

                    $warehouseProduct = WarehouseProduct::where('warehouse_id', $warehouseId)
                        ->where('product_id', $lot->product_id)
                        ->first();
                    $available = $warehouseProduct ? (float) $warehouseProduct->quantity : 0.0;
                    $deductQty = min($qty, $available);
                    if ($deductQty <= 0) {
                        // Still clear the expired lot remainder even if warehouse qty already zero.
                        $lot->quantity_remaining = 0;
                        $lot->save();
                        continue;
                    }

                    $unitCost = max(0, round((float) ($product->purchase_price ?? 0), 4));
                    $lineCost = round($deductQty * $unitCost, 2);
                    $totalCost += $lineCost;

                    $batchLabel = trim((string) ($lot->batch_no ?? ''));
                    $reason = __('Expired product write-off')
                        . ($batchLabel !== '' ? ' — ' . __('Batch') . ': ' . $batchLabel : '')
                        . ' — ' . __('Expiry') . ': ' . optional($lot->expiry_date)->format('Y-m-d');

                    $loss = WarehouseLoss::create([
                        'write_off_no' => $writeOffNo,
                        'warehouse_id' => $warehouseId,
                        'product_id' => (int) $lot->product_id,
                        'quantity' => $deductQty,
                        'unit_cost' => $unitCost,
                        'cost_of_goods' => $lineCost,
                        'date' => $date,
                        'reason' => $reason,
                        'issuer_name' => $issuer,
                        'authorized_by' => $authorized,
                        'witness_name' => $witness,
                        'delivery_note_required' => false,
                        'delivery_destination' => null,
                        'created_by' => $creatorId,
                    ]);
                    $seq = max($seq, (int) $loss->id);

                    Utility::warehouse_quantity('minus', $deductQty, (int) $lot->product_id, $warehouseId);
                    Utility::total_quantity('minus', $deductQty, (int) $lot->product_id);
                    Utility::addProductStock((int) $lot->product_id, $deductQty, 'warehouse_loss', $reason, 0);

                    $lot->quantity_remaining = 0;
                    $lot->save();
                }

                $this->postExpiredLossJournal($creatorId, $date, $writeOffNo, $totalCost);
            }
        });

        if ($writeOffNos === []) {
            return redirect()->back()->with('error', __('Could not convert lots: warehouse is required on expired lots.'));
        }

        return redirect()
            ->route('warehouse-loss.index')
            ->with('success', __('Expired products converted to warehouse loss:') . ' ' . implode(', ', $writeOffNos));
    }

    protected function postExpiredLossJournal(int $creatorId, string $date, string $writeOffNo, float $totalCost): void
    {
        if ($totalCost <= 0) {
            return;
        }

        $expenseAccount = ChartOfAccount::where('created_by', $creatorId)->where('code', '5025')->orderBy('id')->first();
        $inventoryAccount = ChartOfAccount::where('created_by', $creatorId)->where('code', '1510')->orderBy('id')->first();
        if (! $expenseAccount || ! $inventoryAccount) {
            Log::warning('Expired-to-loss journal skipped: missing chart accounts 5025/1510', [
                'created_by' => $creatorId,
                'write_off_no' => $writeOffNo,
            ]);

            return;
        }

        $description = __('Expired product write-off') . ' ' . $writeOffNo;
        app(JournalEntryPoster::class)->post(
            $creatorId,
            $date,
            $writeOffNo,
            $description,
            [
                [
                    'account' => (int) $expenseAccount->id,
                    'debit' => $totalCost,
                    'credit' => 0,
                    'description' => $description,
                ],
                [
                    'account' => (int) $inventoryAccount->id,
                    'debit' => 0,
                    'credit' => $totalCost,
                    'description' => $description,
                ],
            ]
        );
    }

    /**
     * All movements: stock_reports + warehouse transfers.
     */
    public function transactions(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.transactions');

        ['movements' => $movements, 'products' => $products] = $this->transactionsData($request);

        if ($request->boolean('print')) {
            return $this->printResponse('reports.product.print.transactions', __('Product transactions'), compact('movements'));
        }

        return view('reports.product.transactions', compact('movements', 'products'));
    }

    public function transactionsExport(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.transactions');

        ['movements' => $movements] = $this->transactionsData($request);

        $fileName = 'product_transactions_' . date('Y-m-d_His') . '.csv';
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=$fileName",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($movements) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($file, [
                __('Date'),
                __('Product'),
                __('IN / OUT'),
                __('Qty'),
                __('Type'),
                __('Reference'),
            ]);

            foreach ($movements as $m) {
                $at = $m->at
                    ? ($m->at instanceof \Carbon\Carbon
                        ? $m->at->format('Y-m-d H:i')
                        : Carbon::parse($m->at)->format('Y-m-d H:i'))
                    : '';
                fputcsv($file, [
                    $at,
                    $m->product_name,
                    strtoupper((string) $m->direction),
                    $m->quantity,
                    ucfirst(str_replace('_', ' ', (string) $m->kind)),
                    $m->reference,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    protected function transactionsData(Request $request): array
    {
        $creatorId = $this->creatorId();
        $products = ProductService::where('created_by', $creatorId)
            ->where('type', 'product')
            ->orderBy('name')
            ->get()
            ->pluck('name', 'id');

        $movements = collect();

        $stockQuery = StockReport::with('product')
            ->where('created_by', $creatorId);

        if ($request->filled('product_id')) {
            $stockQuery->where('product_id', (int) $request->product_id);
        }
        if ($request->filled('movement_type')) {
            if ($request->movement_type === 'transfer') {
                $stockQuery->whereRaw('1 = 0');
            } else {
                $stockQuery->where('type', $request->movement_type);
            }
        }
        if ($request->filled('date_from')) {
            $stockQuery->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $stockQuery->whereDate('created_at', '<=', $request->date_to);
        }

        foreach ($stockQuery->orderByDesc('created_at')->orderByDesc('id')->limit(5000)->get() as $sr) {
            $direction = $this->stockDirection($sr->type);
            $movements->push((object) [
                'at' => $sr->created_at,
                'product_id' => $sr->product_id,
                'product_name' => $sr->product->name ?? ('#' . $sr->product_id),
                'direction' => $direction,
                'quantity' => (float) $sr->quantity,
                'kind' => $sr->type,
                'reference' => $sr->description,
                'ref_type' => $sr->type,
                'ref_id' => (int) $sr->type_id,
                'stock_report_id' => $sr->id,
            ]);
        }

        $wtQuery = WarehouseTransfer::with(['product', 'fromWarehouse', 'toWarehouse'])
            ->where('created_by', $creatorId);

        if ($request->filled('product_id')) {
            $wtQuery->where('product_id', (int) $request->product_id);
        }
        if ($request->filled('date_from')) {
            $wtQuery->whereDate('date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $wtQuery->whereDate('date', '<=', $request->date_to);
        }
        if ($request->filled('movement_type')) {
            if ($request->movement_type !== 'transfer') {
                $wtQuery->whereRaw('1 = 0');
            }
        }

        foreach ($wtQuery->orderByDesc('date')->orderByDesc('id')->limit(2000)->get() as $wt) {
            $name = $wt->product->name ?? ('#' . $wt->product_id);
            $date = $wt->date ? Carbon::parse($wt->date)->startOfDay() : $wt->created_at;
            $qty = (float) $wt->quantity;
            $from = $wt->fromWarehouse->name ?? ('#' . $wt->from_warehouse);
            $to = $wt->toWarehouse->name ?? ('#' . $wt->to_warehouse);

            $movements->push((object) [
                'at' => $date,
                'product_id' => $wt->product_id,
                'product_name' => $name,
                'direction' => 'out',
                'quantity' => $qty,
                'kind' => 'transfer',
                'reference' => __('Transfer out') . ': ' . $from . ' → ' . $to,
                'ref_type' => 'warehouse_transfer',
                'ref_id' => (int) $wt->id,
                'stock_report_id' => null,
            ]);
            $movements->push((object) [
                'at' => $date,
                'product_id' => $wt->product_id,
                'product_name' => $name,
                'direction' => 'in',
                'quantity' => $qty,
                'kind' => 'transfer',
                'reference' => __('Transfer in') . ': ' . $from . ' → ' . $to,
                'ref_type' => 'warehouse_transfer',
                'ref_id' => (int) $wt->id,
                'stock_report_id' => null,
            ]);
        }

        $movements = $movements->sortByDesc(function ($m) {
            return $m->at instanceof \Carbon\Carbon ? $m->at->timestamp : strtotime((string) $m->at);
        })->values();

        return compact('movements', 'products');
    }

    protected function stockDirection(string $type): string
    {
        $in = ['purchase', 'bill', 'manually'];

        return in_array($type, $in, true) ? 'in' : 'out';
    }

    /**
     * Detail for one invoice / purchase / bill / POS / warehouse transfer.
     */
    public function document(Request $request, string $docType, int $id)
    {
        $this->ensureStockReport('menu.dashboard.product_system.transactions');

        $docType = strtolower($docType);
        $creatorId = $this->creatorId();

        if ($docType === 'invoice') {
            $doc = Invoice::with(['items.product', 'customer'])->where('created_by', $creatorId)->findOrFail($id);

            return view('reports.product.document_invoice', compact('doc'));
        }

        if ($docType === 'purchase') {
            $doc = Purchase::with(['items.product', 'vender'])->where('created_by', $creatorId)->findOrFail($id);

            return view('reports.product.document_purchase', compact('doc'));
        }

        if ($docType === 'bill') {
            $doc = Bill::with(['items.product', 'vender'])->where('created_by', $creatorId)->findOrFail($id);

            return view('reports.product.document_bill', compact('doc'));
        }

        if ($docType === 'pos') {
            $doc = Pos::with(['items.product', 'invoice'])->where('created_by', $creatorId)->findOrFail($id);

            return view('reports.product.document_pos', compact('doc'));
        }

        if ($docType === 'warehouse_transfer') {
            $doc = WarehouseTransfer::with(['product', 'fromWarehouse', 'toWarehouse'])
                ->where('created_by', $creatorId)
                ->findOrFail($id);

            return view('reports.product.document_transfer', compact('doc'));
        }

        abort(404);
    }

    /**
     * Last 5 purchase lines per product.
     */
    public function lastSupplies(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.last_supplies');

        $creatorId = $this->creatorId();
        $productList = ProductService::where('created_by', $creatorId)
            ->where('type', 'product')
            ->orderBy('name')
            ->get()
            ->pluck('name', 'id');

        $lines = collect();
        $product = null;

        if ($request->filled('product_id')) {
            $product = ProductService::where('created_by', $creatorId)->find((int) $request->product_id);
            if ($product) {
                $lines = PurchaseProduct::query()
                    ->join('purchases', 'purchase_products.purchase_id', '=', 'purchases.id')
                    ->leftJoin('venders', 'purchases.vender_id', '=', 'venders.id')
                    ->where('purchases.created_by', $creatorId)
                    ->where('purchase_products.product_id', $product->id)
                    ->select([
                        'purchase_products.*',
                        'purchases.purchase_date',
                        'purchases.purchase_id as purchase_seq',
                        'purchases.id as purchase_table_id',
                        'venders.name as supplier_name',
                    ])
                    ->orderByDesc('purchases.purchase_date')
                    ->orderByDesc('purchases.id')
                    ->limit(5)
                    ->get();
            }
        }

        if ($request->boolean('print')) {
            $title = __('Last 5 supplies') . ($product ? (': ' . $product->name) : '');
            return $this->printResponse('reports.product.print.last_supplies', $title, compact('lines', 'product'));
        }

        return view('reports.product.last_supplies', compact('productList', 'lines', 'product'));
    }

    public function lastSuppliesExport(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.last_supplies');

        if (!$request->filled('product_id')) {
            return redirect()->route('reports.product.last-supplies')
                ->with('error', __('Please select a product.'));
        }

        $creatorId = $this->creatorId();
        $product = ProductService::where('created_by', $creatorId)->find((int) $request->product_id);
        if (!$product) {
            return redirect()->route('reports.product.last-supplies')
                ->with('error', __('Product not found.'));
        }

        $lines = PurchaseProduct::query()
            ->join('purchases', 'purchase_products.purchase_id', '=', 'purchases.id')
            ->leftJoin('venders', 'purchases.vender_id', '=', 'venders.id')
            ->where('purchases.created_by', $creatorId)
            ->where('purchase_products.product_id', $product->id)
            ->select([
                'purchase_products.*',
                'purchases.purchase_date',
                'purchases.purchase_id as purchase_seq',
                'purchases.id as purchase_table_id',
                'venders.name as supplier_name',
            ])
            ->orderByDesc('purchases.purchase_date')
            ->orderByDesc('purchases.id')
            ->limit(5)
            ->get();

        $safeName = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $product->name);
        $fileName = 'last_supplies_' . $safeName . '_' . date('Y-m-d_His') . '.csv';

        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=$fileName",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($lines, $product) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($file, [
                __('Product'),
                __('Date'),
                __('Purchase #'),
                __('Supplier'),
                __('Qty'),
                __('Unit price'),
                __('Line total'),
            ]);

            foreach ($lines as $line) {
                $lineTotal = (float) $line->price * (float) $line->quantity - (float) $line->discount;
                fputcsv($file, [
                    $product->name,
                    $line->purchase_date ? Carbon::parse($line->purchase_date)->format('Y-m-d') : '',
                    \Auth::user()->purchaseNumberFormat($line->purchase_seq),
                    $line->supplier_name ?? '',
                    $line->quantity,
                    $line->price,
                    $lineTotal,
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Group warehouse bin stock by row / shelf (location check). Optional filters: warehouse, row, shelf.
     */
    public function locationCheck(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.location_check');

        ['warehouses' => $warehouses, 'byLocation' => $byLocation, 'byLocationFallback' => $byLocationFallback] = $this->locationCheckData($request);

        if ($request->boolean('print')) {
            return $this->printResponse('reports.product.print.location_check', __('Row & column check'), compact('byLocation', 'byLocationFallback'));
        }

        return view('reports.product.location_check', compact('byLocation', 'byLocationFallback', 'warehouses'));
    }

    public function locationCheckExport(Request $request)
    {
        $this->ensureStockReport('menu.dashboard.product_system.location_check');

        ['byLocation' => $byLocation, 'byLocationFallback' => $byLocationFallback] = $this->locationCheckData($request);

        $fileName = 'location_check_' . date('Y-m-d_His') . '.csv';
        $headers = [
            'Content-type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=$fileName",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($byLocation, $byLocationFallback) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));
            fputcsv($file, [
                __('Source'),
                __('Warehouse'),
                __('Row'),
                __('Shelf'),
                __('Product'),
                __('SKU'),
                __('Qty'),
            ]);

            foreach ($byLocation as $key => $group) {
                $locParts = array_pad(explode('|', $key, 3), 3, '—');
                $hdr = $group->first();
                foreach ($group as $r) {
                    fputcsv($file, [
                        __('Warehouse bins'),
                        $hdr->warehouse_name ?? '',
                        $locParts[1],
                        $locParts[2],
                        $r->product_name,
                        $r->sku ?? '',
                        $r->quantity,
                    ]);
                }
            }

            foreach ($byLocationFallback as $key => $group) {
                $fbParts = array_pad(explode('|', $key, 3), 3, '—');
                foreach ($group as $p) {
                    fputcsv($file, [
                        __('Product card location only'),
                        '',
                        $fbParts[1],
                        $fbParts[2],
                        $p->name,
                        $p->sku ?? '',
                        $p->quantity,
                    ]);
                }
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    protected function locationCheckData(Request $request): array
    {
        $creatorId = $this->creatorId();

        $warehouses = warehouse::where('created_by', $creatorId)->orderBy('name')->get();

        $bins = WarehouseProduct::query()
            ->join('product_services', 'warehouse_products.product_id', '=', 'product_services.id')
            ->join('warehouses', 'warehouse_products.warehouse_id', '=', 'warehouses.id')
            ->where('warehouse_products.created_by', $creatorId)
            ->where('product_services.created_by', $creatorId)
            ->where('warehouse_products.quantity', '>', 0)
            ->where(function ($q) {
                $q->where('warehouse_products.row_no', '!=', '')
                    ->orWhere('warehouse_products.shelf_no', '!=', '');
            })
            ->select([
                'warehouse_products.warehouse_id',
                'warehouse_products.row_no',
                'warehouse_products.shelf_no',
                'warehouse_products.quantity',
                'warehouses.name as warehouse_name',
                'product_services.name as product_name',
                'product_services.sku',
            ]);

        if ($request->filled('warehouse_id')) {
            $bins->where('warehouse_products.warehouse_id', (int) $request->warehouse_id);
        }
        if ($request->filled('row_filter')) {
            $bins->where('warehouse_products.row_no', 'like', '%' . trim((string) $request->row_filter) . '%');
        }
        if ($request->filled('shelf_filter')) {
            $bins->where('warehouse_products.shelf_no', 'like', '%' . trim((string) $request->shelf_filter) . '%');
        }

        $bins = $bins
            ->orderBy('warehouses.name')
            ->orderBy('warehouse_products.row_no')
            ->orderBy('warehouse_products.shelf_no')
            ->orderBy('product_services.name')
            ->get();

        $byLocation = $bins->groupBy(function ($r) {
            $row = trim((string) ($r->row_no ?? ''));
            $shelf = trim((string) ($r->shelf_no ?? ''));

            return (int) $r->warehouse_id . '|' . ($row !== '' ? $row : '—') . '|' . ($shelf !== '' ? $shelf : '—');
        });

        $productFallback = ProductService::where('created_by', $creatorId)
            ->where('type', 'product')
            ->where('quantity', '>', 0)
            ->where(function ($q) {
                $q->where(function ($q2) {
                    $q2->whereNotNull('row_no')->where('row_no', '!=', '');
                })->orWhere(function ($q2) {
                    $q2->whereNotNull('shelf_no')->where('shelf_no', '!=', '');
                });
            })
            ->whereNotExists(function ($q) use ($creatorId) {
                $q->select(DB::raw(1))
                    ->from('warehouse_products')
                    ->whereColumn('warehouse_products.product_id', 'product_services.id')
                    ->where('warehouse_products.created_by', $creatorId)
                    ->where(function ($qq) {
                        $qq->where('warehouse_products.row_no', '!=', '')
                            ->orWhere('warehouse_products.shelf_no', '!=', '');
                    });
            })
            ->orderBy('row_no')
            ->orderBy('shelf_no')
            ->orderBy('name')
            ->get();

        $byLocationFallback = $productFallback->groupBy(function ($p) {
            $row = trim((string) ($p->row_no ?? ''));
            $shelf = trim((string) ($p->shelf_no ?? ''));

            return 'card|' . ($row !== '' ? $row : '—') . '|' . ($shelf !== '' ? $shelf : '—');
        });

        return compact('warehouses', 'byLocation', 'byLocationFallback');
    }
}
