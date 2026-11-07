<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\KitchenProductionOrder;
use App\Models\KitchenProductionPlan;
use App\Models\KitchenRequisition;
use App\Models\ProductService;
use App\Models\warehouse;
use App\Services\KitchenProductionPlanService;
use App\Services\KitchenRequisitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Kitchen Production — Requisitions (Phase 3, plan §3, §5–§6).
 *
 *   REQUESTED -> APPROVED -> ISSUED / PARTIALLY_ISSUED
 *   (REJECTED returns the request to the kitchen.)
 *
 * A requisition moves stock that is ALREADY in a warehouse into the
 * kitchen warehouse. It is NEVER a purchase order. The workflow lives
 * in KitchenRequisitionService (service layer, not only the blade):
 * approval cannot be by the requester, and issuing moves stock once
 * per quantity through the warehouse transfer path.
 */
class KitchenRequisitionController extends Controller
{
    public function __construct(
        protected KitchenRequisitionService $requisitions,
        protected KitchenProductionPlanService $plans,
    ) {
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('manage kitchen requisitions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $requisitions = KitchenRequisition::where('created_by', $creatorId)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->input('search').'%';
                $q->where('requisition_number', 'like', $search);
            })
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('kitchen.requisitions.index', [
            'requisitions' => $requisitions,
            'statuses' => KitchenRequisition::STATUSES,
        ]);
    }

    public function create(Request $request)
    {
        if (! Auth::user()->can('manage kitchen requisitions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        // Prefill from a plan's shortages or an order's shortages when
        // the user came from there (the lines are still editable).
        $prefill = null;
        if ($request->filled('plan_id')) {
            $plan = KitchenProductionPlan::where('id', (int) $request->input('plan_id'))
                ->where('created_by', $creatorId)
                ->first();
            if ($plan) {
                try {
                    $requirements = $this->plans->requirements($plan);
                    $prefill = [
                        'plan' => $plan,
                        'lines' => array_values(array_filter(array_map(function ($row) {
                            if ($row['shortage'] <= 0) {
                                return null;
                            }

                            // required = the total the plan needs; the
                            // shortage is re-computed on save.
                            return [
                                'ingredient_product_id' => $row['ingredient_id'],
                                'required_quantity' => $row['needed'],
                                'unit_id' => $row['unit_id'],
                                'unit_cost' => $row['unit_cost'],
                            ];
                        }, $requirements['lines']))),
                    ];
                } catch (\InvalidArgumentException $e) {
                    return redirect()->back()->with('error', $e->getMessage());
                }
            }
        }

        return view('kitchen.requisitions.form', array_merge(
            $this->formData(),
            ['requisition' => new KitchenRequisition(), 'prefill' => $prefill],
        ));
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('manage kitchen requisitions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $requisition = $this->requisitions->create(
                $this->validatedRequisition($request),
                $request->input('lines', []),
                Auth::user(),
            );

            return redirect()->route('kitchen.requisitions.show', $requisition->id)
                ->with('success', __('Requisition :number created.', ['number' => $requisition->requisition_number]));
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(KitchenRequisition $requisition)
    {
        if (! Auth::user()->can('manage kitchen requisitions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($requisition);

        $requisition->load('lines.ingredient', 'lines.unit', 'plan', 'order', 'sourceWarehouse', 'kitchenWarehouse', 'branch');

        return view('kitchen.requisitions.show', [
            'requisition' => $requisition,
        ]);
    }

    public function edit(KitchenRequisition $requisition)
    {
        if (! Auth::user()->can('manage kitchen requisitions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($requisition);

        if (! $requisition->isRequested()) {
            return redirect()->back()->with('error', __('Only a requested requisition can be edited.'));
        }

        return view('kitchen.requisitions.form', array_merge(
            $this->formData(),
            ['requisition' => $requisition->load('lines'), 'prefill' => null],
        ));
    }

    public function update(Request $request, KitchenRequisition $requisition)
    {
        if (! Auth::user()->can('manage kitchen requisitions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($requisition);

        try {
            $requisition = $this->requisitions->updateDraft(
                $requisition,
                $this->validatedRequisition($request),
                $request->input('lines', []),
                Auth::user(),
            );

            return redirect()->route('kitchen.requisitions.show', $requisition->id)
                ->with('success', __('Requisition :number updated.', ['number' => $requisition->requisition_number]));
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function destroy(KitchenRequisition $requisition)
    {
        if (! Auth::user()->can('manage kitchen requisitions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($requisition);

        if (! $requisition->isRequested()) {
            return redirect()->back()->with('error', __('Only a requested requisition can be deleted.'));
        }

        $requisition->delete();

        return redirect()->route('kitchen.requisitions.index')
            ->with('success', __('Requisition deleted.'));
    }

    public function approve(KitchenRequisition $requisition)
    {
        if (! Auth::user()->can('approve kitchen requisitions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($requisition);

        try {
            $requisition = $this->requisitions->approve($requisition, Auth::user());

            return redirect()->route('kitchen.requisitions.show', $requisition->id)
                ->with('success', __('Requisition :number approved.', ['number' => $requisition->requisition_number]));
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function reject(Request $request, KitchenRequisition $requisition)
    {
        if (! Auth::user()->can('approve kitchen requisitions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($requisition);

        try {
            $requisition = $this->requisitions->reject($requisition, Auth::user(), $request->input('rejection_note'));

            return redirect()->route('kitchen.requisitions.show', $requisition->id)
                ->with('success', __('Requisition :number rejected.', ['number' => $requisition->requisition_number]));
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function issueForm(KitchenRequisition $requisition)
    {
        if (! Auth::user()->can('manage kitchen requisitions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($requisition);

        if (! $requisition->isApproved() && ! $requisition->isPartiallyIssued()) {
            return redirect()->back()->with('error', __('Only an approved requisition can be issued.'));
        }

        $requisition->load('lines.ingredient', 'lines.unit', 'sourceWarehouse', 'kitchenWarehouse');

        return view('kitchen.requisitions.issue', [
            'requisition' => $requisition,
        ]);
    }

    public function issue(Request $request, KitchenRequisition $requisition)
    {
        if (! Auth::user()->can('manage kitchen requisitions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($requisition);

        $quantities = [];
        foreach ($request->input('quantities', []) as $lineId => $quantity) {
            $quantities[(int) $lineId] = (float) $quantity;
        }

        try {
            $requisition = $this->requisitions->issue($requisition, Auth::user(), $quantities);

            $message = $requisition->isIssued()
                ? __('Requisition :number issued in full.', ['number' => $requisition->requisition_number])
                : __('Requisition :number partially issued.', ['number' => $requisition->requisition_number]);

            return redirect()->route('kitchen.requisitions.show', $requisition->id)
                ->with('success', $message);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    /** Create a requisition from a plan's shortages (one click). */
    public function fromPlan(KitchenProductionPlan $plan, Request $request)
    {
        if (! Auth::user()->can('manage kitchen requisitions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ((int) $plan->created_by !== (int) Auth::user()->creatorId()) {
            abort(403);
        }

        $creatorId = (int) Auth::user()->creatorId();
        $sourceWarehouseId = (int) $request->input('source_warehouse_id');

        if ($sourceWarehouseId <= 0 || ! warehouse::where('id', $sourceWarehouseId)->where('created_by', $creatorId)->exists()) {
            return redirect()->back()->with('error', __('Select the source warehouse first.'));
        }

        try {
            $requisition = $this->requisitions->createFromPlan($plan, $sourceWarehouseId, Auth::user());

            return redirect()->route('kitchen.requisitions.show', $requisition->id)
                ->with('success', __('Requisition :number created from plan :plan.', ['number' => $requisition->requisition_number, 'plan' => $plan->plan_number]));
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /** Create a requisition from an order's shortages (one click). */
    public function fromOrder(KitchenProductionOrder $order, Request $request)
    {
        if (! Auth::user()->can('manage kitchen requisitions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ((int) $order->created_by !== (int) Auth::user()->creatorId()) {
            abort(403);
        }

        $creatorId = (int) Auth::user()->creatorId();
        $sourceWarehouseId = (int) $request->input('source_warehouse_id');

        if ($sourceWarehouseId <= 0 || ! warehouse::where('id', $sourceWarehouseId)->where('created_by', $creatorId)->exists()) {
            return redirect()->back()->with('error', __('Select the source warehouse first.'));
        }

        try {
            $requisition = $this->requisitions->createFromOrder($order, $sourceWarehouseId, Auth::user());

            return redirect()->route('kitchen.requisitions.show', $requisition->id)
                ->with('success', __('Requisition :number created from order :order.', ['number' => $requisition->requisition_number, 'order' => $order->order_number ?? '#'.$order->id]));
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    protected function assertSameCompany(KitchenRequisition $requisition): void
    {
        if ((int) $requisition->created_by !== (int) Auth::user()->creatorId()) {
            abort(403);
        }
    }

    protected function formData(): array
    {
        $creatorId = (int) Auth::user()->creatorId();

        return [
            'ingredients' => ProductService::where('created_by', $creatorId)
                ->where('type', 'ingredient')
                ->orderBy('name')
                ->get(['id', 'name', 'unit_id', 'purchase_price']),
            'warehouses' => warehouse::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name', 'branch_id']),
            'branches' => Branch::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']),
            'units' => \App\Models\ProductServiceUnit::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']),
        ];
    }

    protected function validatedRequisition(Request $request): array
    {
        $creatorId = (int) Auth::user()->creatorId();

        return $request->validate([
            'kitchen_production_plan_id' => ['nullable', 'integer', Rule::exists('kitchen_production_plans', 'id')->where('created_by', $creatorId)],
            'kitchen_production_order_id' => ['nullable', 'integer', Rule::exists('kitchen_production_orders', 'id')->where('created_by', $creatorId)],
            'source_warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('created_by', $creatorId)],
            'kitchen_warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('created_by', $creatorId), 'different:source_warehouse_id'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('created_by', $creatorId)],
            'notes' => ['nullable', 'string'],
        ]);
    }
}
