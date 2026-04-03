<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\Invoice;
use App\Models\Pos;
use App\Models\ProductService;
use App\Models\Purchase;
use App\Models\StockReport;
use App\Models\PurchaseProduct;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use App\Models\WarehouseTransfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductReportsController extends Controller
{
    protected function creatorId(): int
    {
        return (int) \Auth::user()->creatorId();
    }

    protected function ensureStockReport(): void
    {
        if (! \Auth::user()->can('stock report')) {
            abort(403, __('Permission Denied.'));
        }
    }

    public function index()
    {
        $this->ensureStockReport();

        return view('reports.product.index');
    }

    /**
     * Current stock per product (store check).
     */
    public function storeCheck(Request $request)
    {
        $this->ensureStockReport();

        $q = ProductService::where('created_by', $this->creatorId())
            ->where('type', 'product');

        if ($request->filled('search')) {
            $s = '%' . trim((string) $request->search) . '%';
            $q->where(function ($qq) use ($s) {
                $qq->where('name', 'like', $s)->orWhere('sku', 'like', $s);
            });
        }

        $products = $q->orderBy('name')->get();

        return view('reports.product.store_check', compact('products'));
    }

    /**
     * Warehouse-wise stock with row/shelf from product master.
     */
    public function warehouseStock(Request $request)
    {
        $this->ensureStockReport();

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

        return view('reports.product.warehouse_stock', compact('rows', 'warehouses'));
    }

    /**
     * All movements: stock_reports + warehouse transfers.
     */
    public function transactions(Request $request)
    {
        $this->ensureStockReport();

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
            $date = $wt->date ? \Carbon\Carbon::parse($wt->date)->startOfDay() : $wt->created_at;
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

        return view('reports.product.transactions', compact('movements', 'products'));
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
        $this->ensureStockReport();

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
        $this->ensureStockReport();

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

        return view('reports.product.last_supplies', compact('productList', 'lines', 'product'));
    }

    /**
     * Group warehouse bin stock by row / shelf (location check). Optional filters: warehouse, row, shelf.
     */
    public function locationCheck(Request $request)
    {
        $this->ensureStockReport();

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

        return view('reports.product.location_check', compact('byLocation', 'byLocationFallback', 'warehouses'));
    }
}
