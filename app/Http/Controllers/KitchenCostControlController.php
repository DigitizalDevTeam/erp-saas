<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\KitchenCostAlert;
use App\Models\KitchenProductionOrder;
use App\Models\KitchenRecipe;
use App\Models\ProductService;
use App\Services\KitchenCostControlService;
use App\Services\KitchenRecipeCostService;
use Illuminate\Http\Request;

/**
 * Kitchen cost control (Phase 6, plan §7): cost per portion + food
 * cost % on recipe, production order and dish; expected vs actual
 * cost and yield; Danilo's bands as flags; cost-and-margin-by-dish
 * report; alerts when a band is breached.
 *
 * Permission 'view kitchen reports' gates the whole screen (plan §8).
 * The controller is a thin shell — all rules live in
 * KitchenCostControlService.
 */
class KitchenCostControlController extends Controller
{
    public function index(Request $request, KitchenCostControlService $service)
    {
        if (! \Auth::user()->can('view kitchen reports')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = \Auth::user()->creatorId();

        $from = $request->input('from') ?: date('Y-m-01');
        $to = $request->input('to') ?: date('Y-m-t');
        $group = in_array($request->input('group'), ['week', 'month'], true) ? $request->input('group') : 'month';
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;

        $dishRows = $service->dishCosts($creatorId, [
            'from' => $from,
            'to' => $to,
            'branch_id' => $branchId,
            'search' => $request->input('search'),
        ]);

        $ordersQuery = KitchenProductionOrder::where('created_by', $creatorId)
            ->whereIn('status', [KitchenProductionOrder::STATUS_COMPLETED, KitchenProductionOrder::STATUS_VALIDATED])
            ->whereDate('completed_at', '>=', $from)
            ->whereDate('completed_at', '<=', $to)
            ->with(['recipe.product'])
            ->orderByDesc('completed_at');

        if ($branchId) {
            $ordersQuery->where('branch_id', $branchId);
        }

        $orders = $ordersQuery->paginate(20)->withQueryString();

        $variances = [];
        foreach ($orders as $order) {
            $variances[(int) $order->id] = $service->orderVariance($order);
        }

        $summary = $service->periodSummary($creatorId, $from, $to, $group);
        $openAlerts = $service->openAlerts($creatorId);
        $branches = Branch::where('created_by', $creatorId)->orderBy('name')->get();

        $badDishes = collect($dishRows)->filter(fn ($row) => KitchenCostControlService::bandIsAlert($row['expected']['band'] ?? null)
            || KitchenCostControlService::bandIsAlert($row['actual_band']))->count();

        return view('kitchen.cost-control.index', [
            'dishRows' => $dishRows,
            'orders' => $orders,
            'variances' => $variances,
            'summary' => $summary,
            'openAlerts' => $openAlerts,
            'branches' => $branches,
            'badDishes' => $badDishes,
            'bandLabel' => KitchenRecipeCostService::bandLabel(...),
            'filters' => $request->only(['from', 'to', 'group', 'branch_id', 'search']),
        ]);
    }

    public function show($productId, KitchenCostControlService $service)
    {
        if (! \Auth::user()->can('view kitchen reports')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = \Auth::user()->creatorId();

        $dish = ProductService::where('id', (int) $productId)
            ->where('created_by', $creatorId)
            ->firstOrFail();

        $rows = $service->dishCosts($creatorId);
        $row = null;
        foreach ($rows as $candidate) {
            if ((int) $candidate['product']->id === (int) $dish->id) {
                $row = $candidate;
                break;
            }
        }

        if ($row === null) {
            return redirect()->route('kitchen.cost-control.index')
                ->with('error', __('This dish has no approved recipe, so there is no cost to show yet.'));
        }

        $history = $service->costHistory($creatorId, (int) $dish->id);

        $orders = KitchenProductionOrder::where('created_by', $creatorId)
            ->where('kitchen_recipe_id', (int) $row['recipe']->id)
            ->whereIn('status', [KitchenProductionOrder::STATUS_COMPLETED, KitchenProductionOrder::STATUS_VALIDATED])
            ->orderByDesc('completed_at')
            ->paginate(20)->withQueryString();

        $variances = [];
        foreach ($orders as $order) {
            $variances[(int) $order->id] = $service->orderVariance($order);
        }

        $alerts = KitchenCostAlert::where('created_by', $creatorId)
            ->where('product_id', (int) $dish->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return view('kitchen.cost-control.show', [
            'dish' => $dish,
            'row' => $row,
            'history' => $history,
            'orders' => $orders,
            'variances' => $variances,
            'alerts' => $alerts,
            'bandLabel' => KitchenRecipeCostService::bandLabel(...),
        ]);
    }

    public function alerts(Request $request)
    {
        if (! \Auth::user()->can('view kitchen reports')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = \Auth::user()->creatorId();

        $query = KitchenCostAlert::where('created_by', $creatorId)
            ->with(['product', 'order']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('alert_type')) {
            $query->where('alert_type', $request->input('alert_type'));
        }

        $alerts = $query->orderByDesc('id')->paginate(20)->withQueryString();

        $openCount = KitchenCostAlert::where('created_by', $creatorId)
            ->where('status', KitchenCostAlert::STATUS_OPEN)
            ->count();

        return view('kitchen.cost-control.alerts', [
            'alerts' => $alerts,
            'openCount' => $openCount,
            'types' => KitchenCostAlert::TYPES,
            'statuses' => KitchenCostAlert::STATUSES,
            'bandLabel' => KitchenRecipeCostService::bandLabel(...),
            'filters' => $request->only(['status', 'alert_type']),
        ]);
    }

    public function acknowledge($id, KitchenCostControlService $service)
    {
        if (! \Auth::user()->can('view kitchen reports')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $alert = KitchenCostAlert::where('id', (int) $id)
            ->where('created_by', \Auth::user()->creatorId())
            ->firstOrFail();

        try {
            $service->acknowledgeAlert($alert, \Auth::user());
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Alert acknowledged.'));
    }

    public function refreshAlerts(KitchenCostControlService $service)
    {
        if (! \Auth::user()->can('view kitchen reports')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $created = $service->refreshDishAlerts(\Auth::user()->creatorId());

        return redirect()->route('kitchen.cost-alerts.index')
            ->with('success', __(':count new dish alert(s) raised for dishes in a bad or very bad band.', ['count' => count($created)]));
    }
}
