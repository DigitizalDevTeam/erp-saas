<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\KitchenProductionPlan;
use App\Models\KitchenRecipe;
use App\Models\ProductServiceUnit;
use App\Models\warehouse;
use App\Services\KitchenProductionPlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Kitchen Production — Production Plans (Phase 3, plan §3, §5–§6).
 *
 *   DRAFT -> PENDING APPROVAL -> APPROVED -> IN PRODUCTION -> COMPLETED
 *   (REJECTED returns the plan to draft for correction.)
 *
 * The workflow lives in KitchenProductionPlanService (service layer,
 * not only the blade): the approval chain (Head Chef -> F&B Manager
 * when the company has one -> General Manager) and the no-self-approval
 * rule are enforced there. An approved plan turns into one DRAFT
 * production order per plan line; the plan itself never deducts stock.
 *
 * Buffet and plate of the day are UNDECIDED by the client and DROP TO
 * V2 (plan §10 gate) — they are NOT built here.
 */
class KitchenProductionPlanController extends Controller
{
    public function __construct(
        protected KitchenProductionPlanService $plans,
    ) {
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('manage kitchen production')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $plans = KitchenProductionPlan::where('created_by', $creatorId)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('meal_service'), fn ($q) => $q->where('meal_service', $request->input('meal_service')))
            ->when($request->filled('plan_date'), fn ($q) => $q->whereDate('plan_date', $request->input('plan_date')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->input('search').'%';
                $q->where('plan_number', 'like', $search);
            })
            ->orderByDesc('plan_date')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('kitchen.production-plans.index', [
            'plans' => $plans,
            'statuses' => KitchenProductionPlan::STATUSES,
            'mealServices' => KitchenProductionPlan::MEAL_SERVICES,
        ]);
    }

    public function create()
    {
        if (! Auth::user()->can('manage kitchen production')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return view('kitchen.production-plans.form', array_merge(
            $this->formData(),
            ['plan' => new KitchenProductionPlan(['plan_date' => date('Y-m-d'), 'meal_service' => 'breakfast'])],
        ));
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('manage kitchen production')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $plan = $this->plans->create(
                $this->validatedPlan($request),
                Auth::user(),
            );

            return redirect()->route('kitchen.production-plans.show', $plan->id)
                ->with('success', __('Production plan :number created.', ['number' => $plan->plan_number]));
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function show(KitchenProductionPlan $plan)
    {
        if (! Auth::user()->can('manage kitchen production')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($plan);

        $plan->load('lines.recipe.product', 'lines.plannedUnit', 'orders.recipe.product', 'warehouse', 'branch');

        $requirements = [];
        $requirementsError = null;
        try {
            $requirements = $this->plans->requirements($plan);
        } catch (\InvalidArgumentException $e) {
            $requirementsError = $e->getMessage();
        }

        return view('kitchen.production-plans.show', [
            'plan' => $plan,
            'requirements' => $requirements,
            'requirementsError' => $requirementsError,
            'fbManagerRequired' => $this->plans->fbManagerRequired((int) $plan->created_by),
            'sourceWarehouses' => warehouse::where('created_by', (int) Auth::user()->creatorId())
                ->where('id', '!=', $plan->warehouse_id)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function edit(KitchenProductionPlan $plan)
    {
        if (! Auth::user()->can('manage kitchen production')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($plan);

        if (! $plan->isDraft() && ! $plan->isRejected()) {
            return redirect()->back()->with('error', __('Only a draft or rejected plan can be edited.'));
        }

        return view('kitchen.production-plans.form', array_merge(
            $this->formData(),
            ['plan' => $plan->load('lines')],
        ));
    }

    public function update(Request $request, KitchenProductionPlan $plan)
    {
        if (! Auth::user()->can('manage kitchen production')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($plan);

        try {
            $plan = $this->plans->updateDraft(
                $plan,
                $this->validatedPlan($request),
                Auth::user(),
            );

            return redirect()->route('kitchen.production-plans.show', $plan->id)
                ->with('success', __('Production plan :number updated.', ['number' => $plan->plan_number]));
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function destroy(KitchenProductionPlan $plan)
    {
        if (! Auth::user()->can('manage kitchen production')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($plan);

        if (! $plan->isDraft()) {
            return redirect()->back()->with('error', __('Only a draft plan can be deleted.'));
        }

        $plan->delete();

        return redirect()->route('kitchen.production-plans.index')
            ->with('success', __('Production plan deleted.'));
    }

    public function submit(KitchenProductionPlan $plan)
    {
        if (! Auth::user()->can('manage kitchen production')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($plan);

        try {
            $plan = $this->plans->submit($plan, Auth::user());

            return redirect()->route('kitchen.production-plans.show', $plan->id)
                ->with('success', __('Plan :number submitted for approval.', ['number' => $plan->plan_number]));
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function approve(KitchenProductionPlan $plan)
    {
        if (! Auth::user()->can('approve kitchen production plans')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($plan);

        try {
            $plan = $this->plans->approveStep($plan, Auth::user());

            $message = $plan->isApproved()
                ? __('Plan :number approved.', ['number' => $plan->plan_number])
                : __('Plan :number approved for this step.', ['number' => $plan->plan_number]);

            return redirect()->route('kitchen.production-plans.show', $plan->id)
                ->with('success', $message);
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function reject(Request $request, KitchenProductionPlan $plan)
    {
        if (! Auth::user()->can('approve kitchen production plans')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($plan);

        try {
            $plan = $this->plans->reject($plan, Auth::user(), $request->input('rejection_note'));

            return redirect()->route('kitchen.production-plans.show', $plan->id)
                ->with('success', __('Plan :number rejected and returned to draft.', ['number' => $plan->plan_number]));
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function refreshOccupancy(KitchenProductionPlan $plan)
    {
        if (! Auth::user()->can('manage kitchen production')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($plan);

        try {
            $plan = $this->plans->refreshOccupancy($plan);

            return redirect()->route('kitchen.production-plans.show', $plan->id)
                ->with('success', __('Occupancy refreshed for plan :number.', ['number' => $plan->plan_number]));
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function createOrders(KitchenProductionPlan $plan)
    {
        if (! Auth::user()->can('manage kitchen production')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($plan);

        try {
            $orders = $this->plans->createOrdersFromPlan($plan, Auth::user());

            return redirect()->route('kitchen.production-plans.show', $plan->id)
                ->with('success', __(':count production order(s) created from plan :number.', ['count' => count($orders), 'number' => $plan->plan_number]));
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function complete(KitchenProductionPlan $plan)
    {
        if (! Auth::user()->can('manage kitchen production')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $this->assertSameCompany($plan);

        try {
            $plan = $this->plans->complete($plan, Auth::user());

            return redirect()->route('kitchen.production-plans.show', $plan->id)
                ->with('success', __('Plan :number completed.', ['number' => $plan->plan_number]));
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    protected function assertSameCompany(KitchenProductionPlan $plan): void
    {
        if ((int) $plan->created_by !== (int) Auth::user()->creatorId()) {
            abort(403);
        }
    }

    protected function formData(): array
    {
        $creatorId = (int) Auth::user()->creatorId();

        return [
            'recipes' => KitchenRecipe::where('created_by', $creatorId)
                ->where('status', KitchenRecipe::STATUS_APPROVED)
                ->with('product')
                ->orderBy('code')
                ->get(['id', 'code', 'version', 'product_id']),
            'warehouses' => warehouse::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name', 'branch_id']),
            'branches' => Branch::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']),
            'units' => ProductServiceUnit::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']),
            'mealServices' => KitchenProductionPlan::MEAL_SERVICES,
            'sources' => KitchenProductionPlan::SOURCES,
        ];
    }

    protected function validatedPlan(Request $request): array
    {
        $creatorId = (int) Auth::user()->creatorId();

        $validated = $request->validate([
            'plan_date' => ['required', 'date'],
            'meal_service' => ['required', Rule::in(KitchenProductionPlan::MEAL_SERVICES)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('created_by', $creatorId)],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('created_by', $creatorId)],
            'section' => ['nullable', 'string', Rule::in(\App\Models\KitchenProductionOrder::SECTIONS)],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.kitchen_recipe_id' => ['required', 'integer', Rule::exists('kitchen_recipes', 'id')->where('created_by', $creatorId)->where('status', KitchenRecipe::STATUS_APPROVED)],
            'lines.*.planned_quantity' => ['required', 'numeric', 'min:0.0001'],
            'lines.*.planned_unit_id' => ['nullable', 'integer', Rule::exists('product_service_units', 'id')->where('created_by', $creatorId)],
            'lines.*.source' => ['nullable', 'string', Rule::in(KitchenProductionPlan::SOURCES)],
            'lines.*.notes' => ['nullable', 'string'],
        ]);

        return $validated;
    }
}
