<?php

namespace App\Http\Controllers;

use App\Models\ProductExpiryLot;
use App\Models\ProductService;
use App\Models\StockIntroduction;
use App\Models\StockIntroductionLine;
use App\Models\Utility;
use App\Models\warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductStockController extends Controller
{
    public function index(Request $request)
    {
        if (! \Auth::user()->can('manage product & service')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $warehouses = warehouse::where('created_by', '=', \Auth::user()->creatorId())
            ->orderBy('name')
            ->get();
        $warehouseId = $request->get('warehouse_id');
        if ($warehouseId !== null && $warehouseId !== '' && ! $warehouses->contains('id', (int) $warehouseId)) {
            $warehouseId = null;
        }

        $productServices = ProductService::where('created_by', '=', \Auth::user()->creatorId())
            ->whereIn('type', ['product', 'ingredient'])
            ->orderBy('name')
            ->get();

        foreach ($productServices as $productService) {
            if ($warehouseId) {
                $productService->display_quantity = $productService->warehouseProduct($productService->id, (int) $warehouseId);
            } else {
                $productService->display_quantity = $productService->quantity;
            }
        }

        $introductions = StockIntroduction::with(['warehouse', 'lines.product'])
            ->where('created_by', \Auth::user()->creatorId())
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('productstock.index', compact('productServices', 'warehouses', 'warehouseId', 'introductions'));
    }

    public function create()
    {
        if (! \Auth::user()->can('edit product & service') && ! \Auth::user()->can('create product & service')) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        $warehouses = warehouse::where('created_by', \Auth::user()->creatorId())->orderBy('name')->pluck('name', 'id');
        $warehouses->prepend(__('Select Warehouse'), '');
        $products = ProductService::where('created_by', \Auth::user()->creatorId())
            ->whereIn('type', ['product', 'ingredient'])
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'type'])
            ->mapWithKeys(function ($product) {
                $label = (string) $product->name;
                if (! empty($product->sku)) {
                    $label .= ' (' . $product->sku . ')';
                }
                if ($product->type === 'ingredient') {
                    $label .= ' — ' . __('Ingredient');
                }

                return [(string) $product->id => $label];
            });
        $products->prepend(__('Select Product'), '');
        $intro_no = $this->nextIntroNumber((int) \Auth::user()->creatorId());

        return view('productstock.create', compact('warehouses', 'products', 'intro_no'));
    }

    public function store(Request $request)
    {
        if (! \Auth::user()->can('edit product & service') && ! \Auth::user()->can('create product & service')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) \Auth::user()->creatorId();
        $hasWarehouse = warehouse::where('created_by', $creatorId)->exists();

        $rules = [
            'intro_date' => 'required|date',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:0.0001',
            'items.*.row_no' => 'nullable|string|max:64',
            'items.*.shelf_no' => 'nullable|string|max:64',
            'items.*.batch_no' => 'nullable|string|max:100',
            'items.*.expiry_date' => 'nullable|date',
            'items.*.mfg_date' => 'nullable|date',
        ];
        if ($hasWarehouse) {
            $rules['warehouse_id'] = [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where(fn ($q) => $q->where('created_by', $creatorId)),
            ];
        } else {
            $rules['warehouse_id'] = 'nullable';
        }

        $validated = $request->validate($rules);

        $lines = [];
        foreach ($validated['items'] as $row) {
            $pid = (int) ($row['product_id'] ?? 0);
            $qty = (float) ($row['quantity'] ?? 0);
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            $product = ProductService::where('created_by', $creatorId)->whereIn('type', ['product', 'ingredient'])->find($pid);
            if (! $product) {
                return redirect()->back()->with('error', __('Invalid product selection.'))->withInput();
            }
            $lines[] = [
                'product' => $product,
                'quantity' => $qty,
                'row_no' => $row['row_no'] ?? null,
                'shelf_no' => $row['shelf_no'] ?? null,
                'batch_no' => $row['batch_no'] ?? null,
                'expiry_date' => $row['expiry_date'] ?? null,
                'mfg_date' => $row['mfg_date'] ?? null,
            ];
        }

        if (empty($lines)) {
            return redirect()->back()->with('error', __('Please add at least one product line.'))->withInput();
        }

        $warehouseId = $hasWarehouse ? (int) $validated['warehouse_id'] : null;

        $intro = DB::transaction(function () use ($validated, $lines, $creatorId, $warehouseId) {
            $intro = StockIntroduction::create([
                'intro_no' => $this->nextIntroNumber($creatorId),
                'warehouse_id' => $warehouseId ?: 0,
                'intro_date' => $validated['intro_date'],
                'notes' => $validated['notes'] ?? null,
                'created_by' => $creatorId,
            ]);

            foreach ($lines as $line) {
                /** @var ProductService $product */
                $product = $line['product'];
                $qty = (float) $line['quantity'];

                StockIntroductionLine::create([
                    'stock_introduction_id' => $intro->id,
                    'product_id' => $product->id,
                    'quantity' => $qty,
                    'row_no' => $line['row_no'],
                    'shelf_no' => $line['shelf_no'],
                    'batch_no' => $line['batch_no'],
                    'expiry_date' => $line['expiry_date'],
                    'mfg_date' => $line['mfg_date'],
                ]);

                if ($warehouseId) {
                    Utility::addWarehouseStock(
                        (int) $product->id,
                        $qty,
                        $warehouseId,
                        $line['row_no'],
                        $line['shelf_no']
                    );
                }

                if (! empty($line['expiry_date']) || ! empty($line['batch_no'])) {
                    ProductExpiryLot::create([
                        'product_id' => (int) $product->id,
                        'warehouse_id' => $warehouseId,
                        'purchase_id' => null,
                        'purchase_product_id' => null,
                        'source' => 'manual',
                        'expiry_date' => $line['expiry_date'],
                        'batch_no' => $line['batch_no'] ? trim((string) $line['batch_no']) : null,
                        'mfg_date' => $line['mfg_date'] ?: null,
                        'quantity_in' => $qty,
                        'quantity_remaining' => $qty,
                        'created_by' => $creatorId,
                    ]);
                }

                $product->quantity = ((float) ($product->quantity ?? 0)) + $qty;
                if (! empty($line['row_no'])) {
                    $product->row_no = trim((string) $line['row_no']);
                }
                if (! empty($line['shelf_no'])) {
                    $product->shelf_no = trim((string) $line['shelf_no']);
                }
                $product->save();

                Utility::addProductStock(
                    $product->id,
                    $qty,
                    'stock_introduction',
                    $qty . ' ' . __('quantity added by stock introduction') . ' ' . $intro->intro_no,
                    $intro->id
                );
            }

            return $intro;
        });

        return redirect()
            ->route('productstock.show', $intro->id)
            ->with('success', __('Stock introduction saved successfully.'));
    }

    public function show($id)
    {
        if (! \Auth::user()->can('manage product & service')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $intro = StockIntroduction::with(['warehouse', 'lines.product'])
            ->where('created_by', \Auth::user()->creatorId())
            ->findOrFail($id);

        return view('productstock.show', compact('intro'));
    }

    public function edit($id)
    {
        $productService = ProductService::find($id);
        if (! \Auth::user()->can('edit product & service')) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
        if (! $productService || (int) $productService->created_by !== (int) \Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        $warehouseModels = warehouse::where('created_by', \Auth::user()->creatorId())->orderBy('name')->get();
        $warehouses = $warehouseModels->pluck('name', 'id');
        $warehouses->prepend(__('Select Warehouse'), '');
        $singleWarehouseId = $warehouseModels->count() === 1 ? $warehouseModels->first()->id : null;
        $productService->load(['variants', 'unit']);

        return view('productstock.edit', compact('productService', 'warehouses', 'singleWarehouseId'));
    }

    public function update(Request $request, $id)
    {
        if (! \Auth::user()->can('edit product & service')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $productService = ProductService::find($id);
        if (! $productService || (int) $productService->created_by !== (int) \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $hasWarehouse = warehouse::where('created_by', \Auth::user()->creatorId())->exists();
        $rules = [
            'row_no' => 'nullable|string|max:64',
            'shelf_no' => 'nullable|string|max:64',
        ];

        if ($productService->variants_enabled && $productService->variants->count() > 0) {
            $rules['variant_quantities'] = 'required|array';
            $rules['variant_quantities.*'] = 'numeric|min:0';
        } else {
            $rules['quantity'] = 'required|numeric|min:1';
        }

        if ($hasWarehouse) {
            $rules['warehouse_id'] = [
                'required',
                'integer',
                Rule::exists('warehouses', 'id')->where(function ($q) {
                    $q->where('created_by', \Auth::user()->creatorId());
                }),
            ];
        } else {
            $rules['warehouse_id'] = 'nullable';
        }
        $request->validate($rules);

        $warehouseId = $hasWarehouse ? (int) $request->warehouse_id : null;
        $rowNo = $request->input('row_no');
        $shelfNo = $request->input('shelf_no');
        $totalQtyAdded = 0;

        if ($productService->variants_enabled && $productService->variants->count() > 0) {
            $variantQuantities = $request->input('variant_quantities', []);
            foreach ($variantQuantities as $variantId => $qty) {
                $qty = (float) $qty;
                if ($qty > 0) {
                    $totalQtyAdded += $qty;
                    if ($warehouseId) {
                        Utility::addWarehouseStock((int) $productService->id, $qty, $warehouseId, $rowNo, $shelfNo, (int) $variantId);
                    }
                }
            }
        } else {
            $qtyToAdd = (float) $request->quantity;
            $totalQtyAdded = $qtyToAdd;

            if (($productService->identifier_tracking ?? false)) {
                $raw = $request->input('identifiers', '');
                $list = preg_split("/\r\n|\n|\r|,|;|\t/", (string) $raw);
                $list = array_values(array_filter(array_map(function ($v) {
                    $v = trim((string) $v);

                    return $v === '' ? null : $v;
                }, $list)));

                $unique = array_values(array_unique($list));
                if (count($unique) !== count($list)) {
                    return redirect()->back()->with('error', __('Duplicate Serial/MAC found in the list.'));
                }
                $qtyToAdd2 = (int) round($qtyToAdd);
                if (count($unique) !== $qtyToAdd2) {
                    return redirect()->back()->with('error', __('Serial/MAC count must match quantity for tracked products.'));
                }

                $creatorId = \Auth::user()->creatorId();
                $existing = \App\Models\ProductIdentifier::where('created_by', $creatorId)->whereIn('identifier_value', $unique)->exists();
                if ($existing) {
                    return redirect()->back()->with('error', __('One or more Serial/MAC already exists in the system.'));
                }

                $type = in_array($productService->identifier_type ?? 'serial', ['serial', 'mac', 'both'], true) ? ($productService->identifier_type ?? 'serial') : 'serial';
                $warrantyDays = (int) ($productService->warranty_days ?? 0);
                $warrantyEnd = null;
                if ($warrantyDays > 0) {
                    $warrantyEnd = \Carbon\Carbon::now()->addDays($warrantyDays)->toDateString();
                }

                foreach ($unique as $val) {
                    \App\Models\ProductIdentifier::create([
                        'product_id' => (int) $productService->id,
                        'warehouse_id' => $warehouseId,
                        'purchase_id' => null,
                        'purchase_product_id' => null,
                        'identifier_type' => $type,
                        'identifier_value' => $val,
                        'status' => 'in_stock',
                        'warranty_end_date' => $warrantyEnd,
                        'created_by' => (int) $creatorId,
                    ]);
                }
            }

            if ($request->filled('expiry_date') || $request->filled('batch_no')) {
                ProductExpiryLot::create([
                    'product_id' => (int) $productService->id,
                    'warehouse_id' => $warehouseId,
                    'purchase_id' => null,
                    'purchase_product_id' => null,
                    'source' => 'manual',
                    'expiry_date' => $request->expiry_date,
                    'batch_no' => $request->filled('batch_no') ? trim($request->batch_no) : null,
                    'mfg_date' => $request->filled('mfg_date') ? $request->mfg_date : null,
                    'quantity_in' => (float) $qtyToAdd,
                    'quantity_remaining' => (float) $qtyToAdd,
                    'created_by' => (int) \Auth::user()->creatorId(),
                ]);
            }

            if ($warehouseId) {
                Utility::addWarehouseStock((int) $productService->id, $qtyToAdd, $warehouseId, $rowNo, $shelfNo);
            }
        }

        $productService->quantity = ($productService->quantity ?? 0) + $totalQtyAdded;
        if ($request->filled('row_no')) {
            $productService->row_no = trim((string) $request->row_no);
        }
        if ($request->filled('shelf_no')) {
            $productService->shelf_no = trim((string) $request->shelf_no);
        }
        $productService->save();

        Utility::addProductStock($productService->id, $totalQtyAdded, 'manually', $totalQtyAdded . '  ' . __('quantity added by manually'), 0);

        return redirect()->route('productstock.index')->with('success', __('Product quantity updated manually.'));
    }

    public function destroy($id)
    {
        return redirect()->back();
    }

    public function printStock(Request $request)
    {
        if (! \Auth::user()->can('manage product & service')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) \Auth::user()->creatorId();
        $warehouses = warehouse::where('created_by', $creatorId)->orderBy('name')->get();
        $warehouseId = $request->get('warehouse_id');
        if ($warehouseId !== null && $warehouseId !== '' && ! $warehouses->contains('id', (int) $warehouseId)) {
            $warehouseId = null;
        }

        $productServices = ProductService::where('created_by', $creatorId)
            ->whereIn('type', ['product', 'ingredient'])
            ->orderBy('name')
            ->get();

        $warehouseName = null;
        if ($warehouseId) {
            $warehouseName = optional($warehouses->firstWhere('id', (int) $warehouseId))->name;
            foreach ($productServices as $productService) {
                $productService->display_quantity = $productService->warehouseProduct($productService->id, (int) $warehouseId);
            }
        } else {
            foreach ($productServices as $productService) {
                $productService->display_quantity = $productService->quantity;
            }
        }

        $user = \Auth::user();
        $rows = [];
        $stockValue = 0.0;
        $belowMin = 0;
        $outOfStock = 0;
        foreach ($productServices as $productService) {
            $qty = (float) ($productService->display_quantity ?? $productService->quantity ?? 0);
            $min = (float) ($productService->low_stock_threshold ?? 0);
            $unitCost = (float) ($productService->purchase_price ?? 0);
            $value = $qty * $unitCost;
            $stockValue += $value;
            if ($qty <= 0) {
                $outOfStock++;
                $status = __('OUT OF STOCK');
                $badge = 'zero';
            } elseif ($min > 0 && $qty <= $min) {
                $belowMin++;
                $status = __('RESTOCK');
                $badge = 'warn';
            } else {
                $status = __('NORMAL');
                $badge = 'ok';
            }
            $locParts = array_filter([
                $productService->shelf_no ?? null,
                $productService->row_no ?? null,
            ]);
            $rows[] = [
                'sku' => $productService->sku ?: ('#' . $productService->id),
                'name' => $productService->name,
                'warehouse' => $warehouseName ?: __('All Warehouses'),
                'location' => $locParts !== [] ? implode('-', $locParts) : '—',
                'stock' => rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') ?: '0',
                'minimum' => $min > 0 ? rtrim(rtrim(number_format($min, 2, '.', ''), '0'), '.') : '—',
                'value_fmt' => $user->priceFormat($value),
                'status' => $status,
                'badge_class' => $badge,
            ];
        }

        $kpis = [
            'articles' => count($rows),
            'stock_value' => $stockValue,
            'below_min' => $belowMin,
            'out_of_stock' => $outOfStock,
        ];

        return view('print.uthara.mapa_inventario', compact('productServices', 'warehouseId', 'warehouseName', 'rows', 'kpis'));
    }

    public function printIntroduction($id)
    {
        if (! \Auth::user()->can('manage product & service')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $intro = StockIntroduction::with(['warehouse', 'lines.product'])
            ->where('created_by', \Auth::user()->creatorId())
            ->findOrFail($id);

        return view('productstock.print_introduction', compact('intro'));
    }

    protected function nextIntroNumber(int $creatorId): string
    {
        $latest = StockIntroduction::where('created_by', $creatorId)->orderByDesc('id')->value('intro_no');
        $next = 1;
        if ($latest && preg_match('/(\d+)$/', (string) $latest, $m)) {
            $next = ((int) $m[1]) + 1;
        } else {
            $next = ((int) StockIntroduction::where('created_by', $creatorId)->count()) + 1;
        }

        return 'SI-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
