<?php

namespace App\Http\Controllers;

use App\Models\ProductService;
use App\Models\Purchase;
use App\Models\Utility;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use App\Models\WarehouseTransfer;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class WarehouseTransferController extends Controller
{
    protected function allowedWarehouseIds()
    {
        $user = Auth::user();
        if ($user->type !== 'company' && !empty($user->warehouse_id)) {
            return [(int) $user->warehouse_id];
        }

        return warehouse::where('created_by', $user->creatorId())->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function index()
    {
        $warehouse_transfers = WarehouseTransfer::where('created_by', '=', \Auth::user()->creatorId())
            ->with(['product', 'fromWarehouse', 'toWarehouse'])
            ->latest()
            ->get();
        return view('warehouse-transfer.index',compact('warehouse_transfers'));
    }

    public function create()
    {
        $allowed = $this->allowedWarehouseIds();
        $from_warehouses = warehouse::where('created_by', '=', \Auth::user()->creatorId())
            ->whereIn('id', $allowed)
            ->get();
        $to_warehouses = warehouse::where('created_by', \Auth::user()->creatorId())
            ->whereIn('id', $allowed)
            ->get()
            ->pluck('name', 'id');
        $to_warehouses->prepend('Select Warehouse', '');
        $ware_pro= WarehouseProduct::join('product_services', 'warehouse_products.product_id', '=', 'product_services.id')
                                ->whereIn('warehouse_products.warehouse_id', $allowed)
                                ->pluck('name','product_id');
        $ware_pro->prepend('Select products', '');

        return view('warehouse-transfer.create',compact('from_warehouses','to_warehouses','ware_pro'));

    }

    public function store(Request $request)
    {
        if(\Auth::user()->can('create warehouse'))
        {
            $lines = [];
            if ($request->has('items') && is_array($request->items)) {
                foreach ($request->items as $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    $pid = isset($row['product_id']) ? (int) $row['product_id'] : 0;
                    $qty = isset($row['quantity']) ? (float) $row['quantity'] : 0;
                    if ($pid > 0 && $qty > 0) {
                        $lines[] = ['product_id' => $pid, 'quantity' => $qty];
                    }
                }
            } elseif ($request->filled('product_id') && $request->filled('quantity')) {
                $lines[] = [
                    'product_id' => (int) $request->product_id,
                    'quantity' => (float) $request->quantity,
                ];
            }

            $validator = \Validator::make(
                array_merge($request->all(), ['_lines' => $lines]), [
                    'from_warehouse' => 'required',
                    'to_warehouse' => 'required',
                    '_lines' => 'required|array|min:1',
                ]
            );
            if($validator->fails())
            {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            if ((int) $request->from_warehouse === (int) $request->to_warehouse) {
                return redirect()->route('warehouse-transfer.index')->with('error', __('From and To warehouse must be different.'));
            }

            $batchId = count($lines) > 1 ? (string) Str::uuid() : null;

            foreach ($lines as $line) {
                $validatorLine = \Validator::make($line, [
                    'product_id' => 'required|integer|min:1',
                    'quantity' => 'required|numeric|min:0.0001',
                ]);
                if ($validatorLine->fails()) {
                    return redirect()->route('warehouse-transfer.index')->with('error', $validatorLine->getMessageBag()->first());
                }
            }

            try {
                DB::transaction(function () use ($request, $lines, $batchId) {
                    $transferDate = $request->date ?: date('Y-m-d');
                    foreach ($lines as $line) {
                        $fromWarehouse = WarehouseProduct::where('warehouse_id', $request->from_warehouse)
                            ->where('product_id', $line['product_id'])
                            ->first();

                        if (empty($fromWarehouse) || (float) $line['quantity'] > (float) $fromWarehouse->quantity) {
                            throw new \RuntimeException(__('Product out of stock or insufficient quantity in source warehouse for one or more lines.'));
                        }

                        $warehouse_transfer = new WarehouseTransfer();
                        $warehouse_transfer->transfer_batch_id = $batchId;
                        $warehouse_transfer->from_warehouse = $request->from_warehouse;
                        $warehouse_transfer->to_warehouse = $request->to_warehouse;
                        $warehouse_transfer->product_id = $line['product_id'];
                        $warehouse_transfer->quantity = $line['quantity'];
                        $warehouse_transfer->date = $transferDate;
                        $warehouse_transfer->created_by = \Auth::user()->creatorId();
                        $warehouse_transfer->save();

                        Utility::warehouse_transfer_qty(
                            $request->from_warehouse,
                            $request->to_warehouse,
                            $line['product_id'],
                            $line['quantity']
                        );
                    }
                });
            } catch (\Throwable $e) {
                return redirect()->route('warehouse-transfer.index')->with('error', $e->getMessage());
            }

            $msg = count($lines) > 1
                ? __('Warehouse transfer of :n items created successfully.', ['n' => count($lines)])
                : __('Warehouse Transfer successfully created.');

            return redirect()->route('warehouse-transfer.index')->with('success', $msg);
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function show()
    {
        return redirect()->route('warehouse-transfer.index');

    }

    public function destroy(WarehouseTransfer $warehouseTransfer)
    {
        if(\Auth::user()->can('delete warehouse'))
        {
            if($warehouseTransfer->created_by == \Auth::user()->creatorId())
            {
                Utility::warehouse_transfer_qty($warehouseTransfer->to_warehouse,$warehouseTransfer->from_warehouse,$warehouseTransfer->product_id,$warehouseTransfer->quantity,'delete');

                $warehouseTransfer->delete();

                return redirect()->route('warehouse-transfer.index')->with('success', __('Warehouse Transfer successfully deleted.'));
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

    public function getproduct(Request $request)
    {
        $allowed = $this->allowedWarehouseIds();
        if($request->warehouse_id == 0)
        {
            $ware_products= WarehouseProduct::join('product_services', 'warehouse_products.product_id', '=', 'product_services.id')
                ->whereIn('warehouse_products.warehouse_id', $allowed)
                ->get()
                ->pluck('name', 'product_id')->toArray();
            $to_warehouses     = warehouse::where('created_by', \Auth::user()->creatorId())->whereIn('id', $allowed)->get()->pluck('name', 'id');
        }
        else
        {
            $ware_products= WarehouseProduct::join('product_services', 'warehouse_products.product_id', '=', 'product_services.id')
                ->where('warehouse_id', $request->warehouse_id)
                ->whereIn('warehouse_products.warehouse_id', $allowed)
                ->get()
                ->pluck('name', 'product_id')->toArray();
            $to_warehouses     = warehouse::where('id','!=',$request->warehouse_id)->where('created_by', \Auth::user()->creatorId())->whereIn('id', $allowed)->get()->pluck('name', 'id');
        }
        $result = [];
        $result['ware_products'] = $ware_products;
        $result['to_warehouses'] = $to_warehouses;
        return response()->json($result);
    }

    public function getquantity(Request $request)
    {
        $allowed = $this->allowedWarehouseIds();
        $productId = (int) ($request->product_id ?? 0);
        $warehouseId = (int) ($request->warehouse_id ?? 0);

        if ($productId <= 0) {
            $pro_qty = WarehouseProduct::where('created_by', '=', \Auth::user()->creatorId())
                ->whereIn('warehouse_id', $allowed)
                ->get()->pluck('quantity', 'product_id')->toArray();

            return response()->json($pro_qty);
        }

        if ($warehouseId <= 0) {
            return response()->json(0);
        }
        if (! in_array($warehouseId, $allowed, true)) {
            return response()->json(0);
        }

        $row = WarehouseProduct::where('created_by', \Auth::user()->creatorId())
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();
        $max = $row ? (float) $row->quantity : 0;

        return response()->json($max);
    }
}
