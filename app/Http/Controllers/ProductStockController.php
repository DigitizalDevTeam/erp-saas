<?php

namespace App\Http\Controllers;

use App\Models\ProductService;
use App\Models\ProductStock;
use App\Models\ProductIdentifier;
use App\Models\Utility;
use App\Models\warehouse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductStockController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {

        if(\Auth::user()->can('manage product & service'))
        {
            $warehouses = warehouse::where('created_by', '=', \Auth::user()->creatorId())
                ->orderBy('name')
                ->get();
            $warehouseId = $request->get('warehouse_id');
            if ($warehouseId !== null && $warehouseId !== '' && ! $warehouses->contains('id', (int) $warehouseId)) {
                $warehouseId = null;
            }

            $productServices = ProductService::where('created_by', '=', \Auth::user()->creatorId())
                ->where('type', '=', 'product')
                ->orderBy('name')
                ->get();

            foreach ($productServices as $productService) {
                if ($warehouseId) {
                    $productService->display_quantity = $productService->warehouseProduct($productService->id, (int) $warehouseId);
                } else {
                    $productService->display_quantity = $productService->quantity;
                }
            }

            return view('productstock.index', compact('productServices', 'warehouses', 'warehouseId'));
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {


    }


    /**
     * Display the specified resource.
     *
     * @param \App\Models\ProductStock $productStock
     *
     * @return \Illuminate\Http\Response
     */
    public function show(ProductStock $productStock)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param \App\Models\ProductStock $productStock
     *
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $productService = ProductService::find($id);
        if(\Auth::user()->can('edit product & service'))
        {
            if($productService->created_by == \Auth::user()->creatorId())
            {
                $warehouseModels = warehouse::where('created_by', \Auth::user()->creatorId())->orderBy('name')->get();
                $warehouses = $warehouseModels->pluck('name', 'id');
                $warehouses->prepend(__('Select Warehouse'), '');
                $singleWarehouseId = $warehouseModels->count() === 1 ? $warehouseModels->first()->id : null;

                $productService->load(['variants', 'unit']);

                return view('productstock.edit', compact('productService', 'warehouses', 'singleWarehouseId'));
            }
            else
            {
                return response()->json(['error' => __('Permission denied.')], 401);
            }
        }
        else
        {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
    }


    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\ProductStock $productStock
     *
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        if(\Auth::user()->can('edit product & service'))
        {
            $productService = ProductService::find($id);
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

            if($productService->created_by == \Auth::user()->creatorId())
            {
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

                    // Handle identifiers if tracking is enabled
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
                        $qtyToAdd2 = (int) round($qtyToAdd);;
                        if (count($unique) !== $qtyToAdd2) {
                            return redirect()->back()->with('error', __('Serial/MAC count must match quantity for tracked products.'));
                        }

                        $creatorId = \Auth::user()->creatorId();
                        $existing = ProductIdentifier::where('created_by', $creatorId)->whereIn('identifier_value', $unique)->exists();
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
                            ProductIdentifier::create([
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

                    // Handle expiry lot tracking
                    if ($request->filled('expiry_date') || $request->filled('batch_no')) {
                        \App\Models\ProductExpiryLot::create([
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

                // Update total product quantity
                $productService->quantity = ($productService->quantity ?? 0) + $totalQtyAdded;
                $productService->created_by = \Auth::user()->creatorId();

                if ($request->filled('row_no') || $request->filled('shelf_no')) {
                    if ($request->filled('row_no')) {
                        $productService->row_no = trim((string) $request->row_no);
                    }
                    if ($request->filled('shelf_no')) {
                        $productService->shelf_no = trim((string) $request->shelf_no);
                    }
                }
                $productService->save();

                // Product Stock Report
                $type        = 'manually';
                $type_id     = 0;
                $description = $totalQtyAdded . '  ' . __('quantity added by manually');
                Utility::addProductStock($productService->id, $totalQtyAdded, $type, $description, $type_id);

                return redirect()->route('productstock.index')->with('success', __('Product quantity updated manually.'));
            }
            else
            {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Models\ProductStock $productStock
     *
     * @return \Illuminate\Http\Response
     */
    public function destroy(ProductStock $productStock)
    {
        //
    }
}
