<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\KitchenProductionOrder;
use App\Models\KitchenRecipe;
use App\Models\ProductServiceUnit;
use App\Models\User;
use App\Models\warehouse;
use App\Services\KitchenProductionOrderService;
use App\Services\KitchenRecipeCostService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Kitchen Production — Production Orders (Phase 1).
 *
 *   DRAFT -> APPROVED -> IN PREPARATION -> COMPLETED -> VALIDATED
 *
 * The workflow lives in KitchenProductionOrderService (service layer,
 * not only the blade): approval allocates the order number, completion
 * deducts the actual ingredients once and receives one batch, and a
 * completed order can only be validated (never edited or completed twice).
 */
class KitchenProductionOrderController extends Controller
{
    public function __construct(
        protected KitchenProductionOrderService $orders,
    ) {
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('manage kitchen production orders')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $orders = KitchenProductionOrder::where('created_by', $creatorId)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->input('search').'%';
                $q->where(function ($inner) use ($search) {
                    $inner->where('order_number', 'like', $search)
                        ->orWhereHas('recipe', fn ($r) => $r->where('name', 'like', $search)->orWhere('code', 'like', $search));
                });
            })
            ->with('recipe', 'warehouse')
            ->orderByDesc('updated_at')
            ->paginate(20);

        return view('kitchen.production-orders.index', compact('orders'));
    }

    public function create()
    {
        if (! Auth::user()->can('manage kitchen production orders')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $order = new KitchenProductionOrder([
            'status' => KitchenProductionOrder::STATUS_DRAFT,
            'production_date' => date('Y-m-d'),
        ]);

        return view('kitchen.production-orders.form', array_merge($this->formData($creatorId), [
            'order' => $order,
        ]));
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('manage kitchen production orders')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $data = $this->validateOrderData($request, $creatorId);

        try {
            $order = $this->orders->create($data, Auth::user());
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('kitchen.production-orders.show', $order->id)
            ->with('success', __('Production order created as a draft. Approve it to allocate an order number.'));
    }

    public function show(KitchenProductionOrder $order)
    {
        if (! Auth::user()->can('manage kitchen production orders')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($order);

        $order->load('recipe.product', 'lines.ingredient', 'lines.plannedUnit', 'lines.lotUsages.lot', 'batch', 'warehouse', 'branch', 'responsibleUser');

        // Shortages against the planned quantities, so the cook sees
        // before starting whether the warehouse can cover the order.
        $shortages = $order->isInPreparation() || $order->isApproved()
            ? $this->orders->checkAvailability($order, [])
            : [];

        return view('kitchen.production-orders.show', [
            'order' => $order,
            'shortages' => $shortages,
            'bandLabel' => KitchenRecipeCostService::bandLabel($order->food_cost_band),
        ]);
    }

    public function edit(KitchenProductionOrder $order)
    {
        if (! Auth::user()->can('manage kitchen production orders')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($order);

        if (! $order->isDraft()) {
            return redirect()->back()->with('error', __('Only a draft production order can be edited.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('kitchen.production-orders.form', array_merge($this->formData($creatorId), [
            'order' => $order,
        ]));
    }

    public function update(Request $request, KitchenProductionOrder $order)
    {
        if (! Auth::user()->can('manage kitchen production orders')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($order);

        if (! $order->isDraft()) {
            return redirect()->back()->with('error', __('Only a draft production order can be edited.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $data = $this->validateOrderData($request, $creatorId, $order);

        try {
            $order = $this->orders->updateDraft($order, $data, Auth::user());
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('kitchen.production-orders.show', $order->id)
            ->with('success', __('Production order updated.'));
    }

    public function destroy(KitchenProductionOrder $order)
    {
        if (! Auth::user()->can('manage kitchen production orders')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($order);

        if (! $order->isDraft()) {
            return redirect()->back()->with('error', __('Only a draft production order can be deleted.'));
        }

        $order->lines()->delete();
        $order->delete();

        return redirect()->route('kitchen.production-orders.index')
            ->with('success', __('Production order deleted.'));
    }

    public function approve(KitchenProductionOrder $order)
    {
        if (! Auth::user()->can('approve kitchen production orders')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($order);

        try {
            $order = $this->orders->approve($order, Auth::user());
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('kitchen.production-orders.show', $order->id)
            ->with('success', __('Production order approved. Order number :number allocated.', ['number' => $order->order_number]));
    }

    public function start(KitchenProductionOrder $order)
    {
        if (! Auth::user()->can('manage kitchen production orders')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($order);

        try {
            $this->orders->start($order, Auth::user());
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('kitchen.production-orders.show', $order->id)
            ->with('success', __('Production started. Record the actual ingredients used and the actual yield to complete it.'));
    }

    public function completeForm(KitchenProductionOrder $order)
    {
        if (! Auth::user()->can('manage kitchen production orders')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($order);

        if (! $order->isInPreparation()) {
            return redirect()->back()->with('error', __('Only a production order in preparation can be completed.'));
        }

        $order->load('recipe.product', 'lines.ingredient', 'lines.plannedUnit', 'warehouse');

        return view('kitchen.production-orders.complete', [
            'order' => $order,
            'shortages' => $this->orders->checkAvailability($order, []),
            'units' => ProductServiceUnit::where('created_by', (int) Auth::user()->creatorId())->orderBy('name')->get(['id', 'name']),
            'defaultExpiry' => date('Y-m-d', strtotime('+3 days')),
        ]);
    }

    public function complete(Request $request, KitchenProductionOrder $order)
    {
        if (! Auth::user()->can('manage kitchen production orders')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($order);

        $request->validate([
            'actuals' => ['nullable', 'array'],
            'actuals.*' => ['nullable', 'numeric', 'min:0'],
            'actual_yield_quantity' => ['required', 'numeric', 'min:0.0001'],
            'actual_yield_unit_id' => ['nullable', 'integer', Rule::exists('product_service_units', 'id')->where('created_by', (int) Auth::user()->creatorId())],
            'expiry_date' => ['required', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string'],
        ]);

        $actuals = [];
        foreach ((array) $request->input('actuals', []) as $lineId => $qty) {
            if ($qty !== null && $qty !== '') {
                $actuals[(int) $lineId] = (float) $qty;
            }
        }

        try {
            $order = $this->orders->complete(
                $order,
                Auth::user(),
                $actuals,
                (float) $request->input('actual_yield_quantity'),
                $request->input('actual_yield_unit_id') ? (int) $request->input('actual_yield_unit_id') : null,
                $request->input('expiry_date'),
                $request->input('notes')
            );
        } catch (\Throwable $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('kitchen.production-orders.show', $order->id)
            ->with('success', __('Production order completed. Batch :batch received into kitchen stock.', ['batch' => $order->order_number.'-B1']));
    }

    public function validateOrder(KitchenProductionOrder $order)
    {
        if (! Auth::user()->can('approve kitchen production orders')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($order);

        try {
            $this->orders->validateOrder($order, Auth::user());
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('kitchen.production-orders.show', $order->id)
            ->with('success', __('Production order validated.'));
    }

    protected function formData(int $creatorId): array
    {
        return [
            // Only APPROVED recipes can be produced (plan §6).
            'recipes' => KitchenRecipe::where('created_by', $creatorId)
                ->where('status', KitchenRecipe::STATUS_APPROVED)
                ->orderBy('name')->get(['id', 'code', 'name', 'version', 'expected_yield_quantity', 'expected_yield_unit_id', 'standard_portion_quantity', 'standard_portion_unit_id']),
            'warehouses' => warehouse::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name', 'branch_id']),
            'branches' => Branch::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']),
            'units' => ProductServiceUnit::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']),
            'users' => User::where(function ($q) use ($creatorId) {
                $q->where('id', $creatorId)->orWhere('created_by', $creatorId);
            })->orderBy('name')->get(['id', 'name']),
        ];
    }

    protected function validateOrderData(Request $request, int $creatorId, ?KitchenProductionOrder $order = null): array
    {
        return $request->validate([
            'kitchen_recipe_id' => ['required', 'integer', Rule::exists('kitchen_recipes', 'id')->where('created_by', $creatorId)->where('status', KitchenRecipe::STATUS_APPROVED)],
            'production_date' => ['required', 'date'],
            'section' => ['nullable', 'string', Rule::in(KitchenProductionOrder::SECTIONS)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('created_by', $creatorId)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('created_by', $creatorId)],
            'planned_quantity' => ['required', 'numeric', 'min:0.0001'],
            'planned_unit_id' => ['nullable', 'integer', Rule::exists('product_service_units', 'id')->where('created_by', $creatorId)],
            'responsible_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(function ($q) use ($creatorId) {
                $q->where('id', $creatorId)->orWhere('created_by', $creatorId);
            })],
            'notes' => ['nullable', 'string'],
            'cost_centre_id' => ['nullable', 'integer'],
        ]);
    }

    protected function assertSameCompany(KitchenProductionOrder $order): void
    {
        if ((int) $order->created_by !== (int) Auth::user()->creatorId()) {
            abort(403, __('This production order belongs to another company.'));
        }
    }
}
