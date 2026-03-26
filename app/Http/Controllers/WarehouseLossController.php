<?php

namespace App\Http\Controllers;

use App\Models\ProductService;
use App\Models\Utility;
use App\Models\warehouse;
use App\Models\WarehouseLoss;
use App\Models\WarehouseProduct;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WarehouseLossController extends Controller
{
    protected function allowedWarehouseIds()
    {
        $user = \Auth::user();
        if ($user->type !== 'company' && !empty($user->warehouse_id)) {
            return [(int) $user->warehouse_id];
        }

        return warehouse::where('created_by', $user->creatorId())->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function index()
    {
        $records = WarehouseLoss::where('created_by', \Auth::user()->creatorId())
            ->with(['warehouse', 'product'])
            ->latest()
            ->get();

        $warehouses = warehouse::where('created_by', \Auth::user()->creatorId())
            ->whereIn('id', $this->allowedWarehouseIds())
            ->pluck('name', 'id');
        $products = ProductService::where('created_by', \Auth::user()->creatorId())
            ->whereIn('type', ['product', 'kit'])
            ->pluck('name', 'id');

        return view('warehouse-loss.index', compact('records', 'warehouses', 'products'));
    }

    public function store(Request $request)
    {
        if (!\Auth::user()->can('create warehouse')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'warehouse_id' => 'required|integer',
            'product_id' => 'required|integer',
            'quantity' => 'required|numeric|min:0.0001',
            'date' => 'nullable|date',
        ]);

        $warehouseProduct = WarehouseProduct::where('warehouse_id', $request->warehouse_id)
            ->where('product_id', $request->product_id)
            ->first();
        if (!$warehouseProduct || (float) $warehouseProduct->quantity < (float) $request->quantity) {
            return redirect()->back()->with('error', __('Insufficient warehouse stock for this loss.'));
        }

        DB::transaction(function () use ($request) {
            WarehouseLoss::create([
                'warehouse_id' => $request->warehouse_id,
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
                'date' => $request->date ?: date('Y-m-d'),
                'reason' => $request->reason,
                'created_by' => \Auth::user()->creatorId(),
            ]);

            Utility::warehouse_quantity('minus', (float) $request->quantity, (int) $request->product_id, (int) $request->warehouse_id);
            Utility::total_quantity('minus', (float) $request->quantity, (int) $request->product_id);
            Utility::addProductStock((int) $request->product_id, (float) $request->quantity, 'warehouse_loss', __('Stock loss/damage write-off'), 0);
        });

        return redirect()->route('warehouse-loss.index')->with('success', __('Warehouse loss recorded successfully.'));
    }
}

