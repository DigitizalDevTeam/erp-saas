<?php

namespace App\Http\Controllers;

use App\Models\KitchenLoss;
use App\Models\KitchenProductionBatch;
use App\Models\KitchenProductionOrder;
use App\Models\ProductService;
use App\Models\ProductServiceUnit;
use App\Models\User;
use App\Models\warehouse;
use App\Services\KitchenLossService;
use Illuminate\Http\Request;

/**
 * Kitchen losses (Phase 5, plan §6): loss records by category,
 * approval where the category needs it, posting through the
 * WarehouseLoss write-off, and batch reconciliation.
 *
 * Permission 'manage kitchen losses' records, edits and posts losses;
 * 'approve kitchen losses' approves and rejects them.
 * The controller is a thin shell — all rules live in
 * KitchenLossService.
 */
class KitchenLossController extends Controller
{
    public function index(Request $request)
    {
        if (! \Auth::user()->can('manage kitchen losses')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = \Auth::user()->creatorId();

        $query = KitchenLoss::where('created_by', $creatorId)
            ->with(['product', 'warehouse', 'batch', 'order', 'recordedBy', 'approvedBy']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('loss_number', 'like', '%'.$search.'%')
                    ->orWhere('reason', 'like', '%'.$search.'%')
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', '%'.$search.'%'));
            });
        }

        $losses = $query->orderByDesc('id')->paginate(20)->withQueryString();

        $pendingCount = KitchenLoss::where('created_by', $creatorId)
            ->where('status', KitchenLoss::STATUS_RECORDED)
            ->count();

        return view('kitchen.losses.index', [
            'losses' => $losses,
            'categories' => KitchenLoss::CATEGORIES,
            'statuses' => KitchenLoss::STATUSES,
            'pendingCount' => $pendingCount,
            'filters' => $request->only(['status', 'category', 'search']),
        ]);
    }

    public function create(Request $request)
    {
        if (! \Auth::user()->can('manage kitchen losses')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = \Auth::user()->creatorId();

        $batch = null;
        if ($request->filled('batch_id')) {
            $batch = KitchenProductionBatch::where('id', (int) $request->input('batch_id'))
                ->where('created_by', $creatorId)
                ->first();
        }

        $order = null;
        if ($request->filled('order_id')) {
            $order = KitchenProductionOrder::where('id', (int) $request->input('order_id'))
                ->where('created_by', $creatorId)
                ->first();
        }

        return view('kitchen.losses.form', array_merge($this->formData($creatorId), [
            'loss' => null,
            'batch' => $batch,
            'order' => $order,
            'selectedProductId' => $batch ? (int) $batch->product_id : ($order ? (int) $order->product_id : null),
            'selectedWarehouseId' => $batch ? (int) $batch->warehouse_id : null,
            'selectedCategory' => $request->input('category'),
        ]));
    }

    public function store(Request $request, KitchenLossService $service)
    {
        if (! \Auth::user()->can('manage kitchen losses')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $loss = $service->record($this->lossData($request), \Auth::user());
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('kitchen.losses.show', $loss->id)
            ->with('success', __('Loss :number recorded.', ['number' => $loss->loss_number]));
    }

    public function show($id)
    {
        if (! \Auth::user()->can('manage kitchen losses')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $loss = $this->findLoss($id);

        $reconciliation = null;
        if ($loss->batch) {
            $reconciliation = app(KitchenLossService::class)
                ->reconciliation($loss->batch, (int) $loss->created_by);
        }

        return view('kitchen.losses.show', [
            'loss' => $loss,
            'reconciliation' => $reconciliation,
        ]);
    }

    public function edit($id)
    {
        if (! \Auth::user()->can('manage kitchen losses')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $loss = $this->findLoss($id);

        if (! $loss->isRecorded() && ! ($loss->isApproved() && ! $loss->needsApproval())) {
            return redirect()->route('kitchen.losses.show', $loss->id)
                ->with('error', __('Only a recorded loss can be edited.'));
        }

        return view('kitchen.losses.form', array_merge($this->formData((int) $loss->created_by), [
            'loss' => $loss,
            'batch' => $loss->batch,
            'order' => $loss->order,
            'selectedProductId' => (int) $loss->product_id,
            'selectedWarehouseId' => (int) $loss->warehouse_id,
            'selectedCategory' => $loss->category,
        ]));
    }

    public function update(Request $request, $id, KitchenLossService $service)
    {
        if (! \Auth::user()->can('manage kitchen losses')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $loss = $this->findLoss($id);

        try {
            $loss = $service->updateDraft($loss, $this->lossData($request), \Auth::user());
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('kitchen.losses.show', $loss->id)
            ->with('success', __('Loss :number updated.', ['number' => $loss->loss_number]));
    }

    public function destroy($id)
    {
        if (! \Auth::user()->can('manage kitchen losses')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $loss = $this->findLoss($id);

        if (! $loss->isRecorded() && ! ($loss->isApproved() && ! $loss->needsApproval())) {
            return redirect()->route('kitchen.losses.show', $loss->id)
                ->with('error', __('Only a recorded loss can be deleted.'));
        }

        $number = $loss->loss_number;
        $loss->delete();

        return redirect()->route('kitchen.losses.index')
            ->with('success', __('Loss :number deleted.', ['number' => $number]));
    }

    public function approve($id, KitchenLossService $service)
    {
        if (! \Auth::user()->can('approve kitchen losses')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $loss = $this->findLoss($id);

        try {
            $service->approve($loss, \Auth::user());
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('kitchen.losses.show', $loss->id)
            ->with('success', __('Loss :number approved.', ['number' => $loss->loss_number]));
    }

    public function reject(Request $request, $id, KitchenLossService $service)
    {
        if (! \Auth::user()->can('approve kitchen losses')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $loss = $this->findLoss($id);

        try {
            $service->reject($loss, \Auth::user(), $request->input('rejection_note'));
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('kitchen.losses.show', $loss->id)
            ->with('success', __('Loss :number rejected.', ['number' => $loss->loss_number]));
    }

    public function post($id, KitchenLossService $service)
    {
        if (! \Auth::user()->can('manage kitchen losses')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $loss = $this->findLoss($id);

        try {
            $loss = $service->post($loss, \Auth::user());
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $message = $loss->category === KitchenLoss::CATEGORY_TRANSFER
            ? __('Loss :number posted: stock moved to the destination warehouse.', ['number' => $loss->loss_number])
            : __('Loss :number posted: stock written off.', ['number' => $loss->loss_number]);

        return redirect()->route('kitchen.losses.show', $loss->id)->with('success', $message);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    protected function findLoss($id): KitchenLoss
    {
        $loss = KitchenLoss::where('id', $id)
            ->where('created_by', \Auth::user()->creatorId())
            ->with(['product', 'unit', 'warehouse', 'destinationWarehouse', 'batch', 'order', 'pos', 'writeOff', 'recordedBy', 'approvedBy', 'rejectedBy', 'postedBy'])
            ->first();

        if (! $loss) {
            abort(404);
        }

        return $loss;
    }

    protected function formData(int $creatorId): array
    {
        $products = ProductService::where('created_by', $creatorId)
            ->whereIn('type', ['product', 'ingredient'])
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'type', 'unit_id', 'purchase_price']);

        $warehouses = warehouse::where('created_by', $creatorId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $batches = KitchenProductionBatch::where('created_by', $creatorId)
            ->where('quantity_remaining', '>', 0)
            ->orderByDesc('id')
            ->get(['id', 'batch_number', 'product_id', 'warehouse_id', 'quantity_remaining', 'unit_id', 'unit_cost']);

        $orders = KitchenProductionOrder::where('created_by', $creatorId)
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'order_number', 'product_id', 'status']);

        $units = ProductServiceUnit::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']);

        return [
            'products' => $products,
            'warehouses' => $warehouses,
            'batches' => $batches,
            'orders' => $orders,
            'units' => $units,
            'categories' => KitchenLoss::CATEGORIES,
            'reuseDestinations' => KitchenLoss::REUSE_DESTINATIONS,
            'approvalCategories' => KitchenLoss::APPROVAL_CATEGORIES,
        ];
    }

    protected function lossData(Request $request): array
    {
        return [
            'category' => $request->input('category'),
            'product_id' => $request->input('product_id'),
            'quantity' => $request->input('quantity'),
            'unit_id' => $request->input('unit_id'),
            'unit_cost' => $request->input('unit_cost'),
            'warehouse_id' => $request->input('warehouse_id'),
            'destination_warehouse_id' => $request->input('destination_warehouse_id'),
            'reason' => $request->input('reason'),
            'reuse_flag' => $request->boolean('reuse_flag'),
            'food_safety_note' => $request->input('food_safety_note'),
            'reuse_destination' => $request->input('reuse_destination'),
            'kitchen_production_order_id' => $request->input('kitchen_production_order_id'),
            'kitchen_production_batch_id' => $request->input('kitchen_production_batch_id'),
        ];
    }
}
