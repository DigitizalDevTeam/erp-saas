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
    /**
     * Warehouses the user may transfer FROM.
     * Transfer permission lists every company warehouse. Otherwise a non-company user
     * with an assigned warehouse can only transfer from that warehouse.
     */
    protected function fromWarehouseIds()
    {
        $user = Auth::user();
        if (
            $user->type !== 'company'
            && ($assigned = $user->assignedWarehouseIds()) !== []
            && ! $user->can('menu.products_system.transfer')
        ) {
            return $assigned;
        }

        return warehouse::where('created_by', $user->creatorId())->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Warehouses the user may transfer TO (any company warehouse).
     */
    protected function toWarehouseIds()
    {
        $user = Auth::user();

        return warehouse::where('created_by', $user->creatorId())->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** @deprecated Use fromWarehouseIds() / toWarehouseIds() */
    protected function allowedWarehouseIds()
    {
        return $this->fromWarehouseIds();
    }

    protected function canCreateTransfer(): bool
    {
        $user = Auth::user();

        return $user->can('create warehouse') || $user->can('menu.products_system.transfer');
    }

    protected function canDeleteTransfer(): bool
    {
        $user = Auth::user();

        return $user->can('delete warehouse') || $user->can('menu.products_system.transfer');
    }

    protected function canPrintTransfer(): bool
    {
        $user = Auth::user();

        return $user->can('manage warehouse')
            || $user->can('show warehouse')
            || $user->can('create warehouse')
            || $user->can('menu.products_system.transfer');
    }

    public function index()
    {
        $warehouse_transfers = WarehouseTransfer::where('created_by', '=', \Auth::user()->creatorId())
            ->with(['product' => fn ($q) => $q->withTrashed(), 'fromWarehouse', 'toWarehouse'])
            ->latest()
            ->get();

        return view('warehouse-transfer.index', compact('warehouse_transfers'));
    }

    public function create()
    {
        $fromIds = $this->fromWarehouseIds();
        $toIds = $this->toWarehouseIds();

        $from_warehouses = warehouse::where('created_by', '=', \Auth::user()->creatorId())
            ->whereIn('id', $fromIds)
            ->get();
        $to_warehouses = warehouse::where('created_by', \Auth::user()->creatorId())
            ->whereIn('id', $toIds)
            ->get()
            ->pluck('name', 'id');
        $to_warehouses->prepend('Select Warehouse', '');
        $ware_pro = WarehouseProduct::join('product_services', 'warehouse_products.product_id', '=', 'product_services.id')
            ->whereNull('product_services.deleted_at')
            ->whereIn('warehouse_products.warehouse_id', $fromIds)
            ->pluck('name', 'product_id');
        $ware_pro->prepend('Select products', '');

        return view('warehouse-transfer.create', compact('from_warehouses', 'to_warehouses', 'ware_pro'));
    }

    public function store(Request $request)
    {
        if (! $this->canCreateTransfer()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

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
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();

            return redirect()->back()->with('error', $messages->first());
        }

        $fromId = (int) $request->from_warehouse;
        $toId = (int) $request->to_warehouse;
        $fromIds = $this->fromWarehouseIds();
        $toIds = $this->toWarehouseIds();

        if (! in_array($fromId, $fromIds, true) || ! in_array($toId, $toIds, true)) {
            return redirect()->route('warehouse-transfer.index')->with('error', __('Permission denied.'));
        }

        if ($fromId === $toId) {
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
            DB::transaction(function () use ($request, $lines, $batchId, $fromId, $toId) {
                $transferDate = $request->date ?: date('Y-m-d');
                $fromName = warehouse::find($fromId)->name ?? ('#' . $fromId);
                $toName = warehouse::find($toId)->name ?? ('#' . $toId);

                foreach ($lines as $line) {
                    $fromWarehouse = WarehouseProduct::where('warehouse_id', $fromId)
                        ->where('product_id', $line['product_id'])
                        ->first();

                    if (empty($fromWarehouse) || (float) $line['quantity'] > (float) $fromWarehouse->quantity) {
                        throw new \RuntimeException(__('Product out of stock or insufficient quantity in source warehouse for one or more lines.'));
                    }

                    $warehouse_transfer = new WarehouseTransfer();
                    $warehouse_transfer->transfer_batch_id = $batchId;
                    $warehouse_transfer->from_warehouse = $fromId;
                    $warehouse_transfer->to_warehouse = $toId;
                    $warehouse_transfer->product_id = $line['product_id'];
                    $warehouse_transfer->quantity = $line['quantity'];
                    $warehouse_transfer->date = $transferDate;
                    $warehouse_transfer->created_by = \Auth::user()->creatorId();
                    $warehouse_transfer->save();

                    Utility::warehouse_transfer_qty(
                        $fromId,
                        $toId,
                        $line['product_id'],
                        $line['quantity']
                    );

                    $route = $fromName . ' → ' . $toName;
                    Utility::addProductStock(
                        $line['product_id'],
                        $line['quantity'],
                        'transfer_out',
                        __('Transfer out') . ': ' . $route,
                        $warehouse_transfer->id
                    );
                    Utility::addProductStock(
                        $line['product_id'],
                        $line['quantity'],
                        'transfer_in',
                        __('Transfer in') . ': ' . $route,
                        $warehouse_transfer->id
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

    public function show()
    {
        return redirect()->route('warehouse-transfer.index');
    }

    public function destroy(WarehouseTransfer $warehouseTransfer)
    {
        if (! $this->canDeleteTransfer()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ($warehouseTransfer->created_by == \Auth::user()->creatorId()) {
            $warehouseTransfer->load(['fromWarehouse', 'toWarehouse']);
            $fromName = $warehouseTransfer->fromWarehouse->name ?? ('#' . $warehouseTransfer->from_warehouse);
            $toName = $warehouseTransfer->toWarehouse->name ?? ('#' . $warehouseTransfer->to_warehouse);
            $route = $fromName . ' → ' . $toName;

            Utility::warehouse_transfer_qty($warehouseTransfer->to_warehouse, $warehouseTransfer->from_warehouse, $warehouseTransfer->product_id, $warehouseTransfer->quantity, 'delete');

            Utility::addProductStock(
                $warehouseTransfer->product_id,
                $warehouseTransfer->quantity,
                'transfer_reversal_in',
                __('Transfer cancelled') . ' — ' . __('Transfer in reversed') . ': ' . $route,
                $warehouseTransfer->id
            );
            Utility::addProductStock(
                $warehouseTransfer->product_id,
                $warehouseTransfer->quantity,
                'transfer_reversal_out',
                __('Transfer cancelled') . ' — ' . __('Transfer out reversed') . ': ' . $route,
                $warehouseTransfer->id
            );

            $warehouseTransfer->delete();

            return redirect()->route('warehouse-transfer.index')->with('success', __('Warehouse Transfer successfully deleted.'));
        }

        return redirect()->back()->with('error', __('Permission denied.'));
    }

    public function getproduct(Request $request)
    {
        $fromIds = $this->fromWarehouseIds();
        $toIds = $this->toWarehouseIds();
        $warehouseId = (int) ($request->warehouse_id ?? 0);

        if ($warehouseId === 0) {
            $ware_products = WarehouseProduct::join('product_services', 'warehouse_products.product_id', '=', 'product_services.id')
                ->whereNull('product_services.deleted_at')
                ->whereIn('warehouse_products.warehouse_id', $fromIds)
                ->get()
                ->pluck('name', 'product_id')->toArray();
            $to_warehouses = warehouse::where('created_by', \Auth::user()->creatorId())
                ->whereIn('id', $toIds)
                ->get()
                ->pluck('name', 'id');
        } else {
            if (! in_array($warehouseId, $fromIds, true)) {
                return response()->json(['ware_products' => [], 'to_warehouses' => []]);
            }
            $ware_products = WarehouseProduct::join('product_services', 'warehouse_products.product_id', '=', 'product_services.id')
                ->whereNull('product_services.deleted_at')
                ->where('warehouse_id', $warehouseId)
                ->whereIn('warehouse_products.warehouse_id', $fromIds)
                ->get()
                ->pluck('name', 'product_id')->toArray();
            $to_warehouses = warehouse::where('id', '!=', $warehouseId)
                ->where('created_by', \Auth::user()->creatorId())
                ->whereIn('id', $toIds)
                ->get()
                ->pluck('name', 'id');
        }
        $result = [];
        $result['ware_products'] = $ware_products;
        $result['to_warehouses'] = $to_warehouses;

        return response()->json($result);
    }

    public function getquantity(Request $request)
    {
        $fromIds = $this->fromWarehouseIds();
        $productId = (int) ($request->product_id ?? 0);
        $warehouseId = (int) ($request->warehouse_id ?? 0);

        if ($productId <= 0) {
            $pro_qty = WarehouseProduct::where('created_by', '=', \Auth::user()->creatorId())
                ->whereIn('warehouse_id', $fromIds)
                ->get()->pluck('quantity', 'product_id')->toArray();

            return response()->json($pro_qty);
        }

        if ($warehouseId <= 0) {
            return response()->json(0);
        }
        if (! in_array($warehouseId, $fromIds, true)) {
            return response()->json(0);
        }

        $row = WarehouseProduct::where('created_by', \Auth::user()->creatorId())
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();
        $max = $row ? (float) $row->quantity : 0;

        return response()->json($max);
    }

    public function print($id)
    {
        if (! $this->canPrintTransfer()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $record = WarehouseTransfer::with([
            'product' => fn ($q) => $q->withTrashed(),
            'fromWarehouse',
            'toWarehouse',
        ])->findOrFail($id);
        if ((int) $record->created_by !== (int) \Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $batchId = $record->transfer_batch_id;
        if (! empty($batchId)) {
            $lines = WarehouseTransfer::with([
                'product' => fn ($q) => $q->withTrashed(),
                'fromWarehouse',
                'toWarehouse',
            ])
                ->where('created_by', $record->created_by)
                ->where('transfer_batch_id', $batchId)
                ->orderBy('id')
                ->get();
        } else {
            $lines = collect([$record]);
        }

        if ($lines->isEmpty()) {
            $lines = collect([$record]);
        }

        $batchLabel = ! empty($batchId) ? $batchId : ('TR-' . $record->id);

        return view('warehouse-transfer.print', compact('record', 'lines', 'batchLabel'));
    }
}
