<?php

namespace App\Http\Controllers;

use App\Exceptions\FixedAssetException;
use App\Models\FixedAsset;
use App\Models\FixedAssetApproval;
use App\Models\FixedAssetMaintenanceCost;
use App\Models\FixedAssetMaintenanceRequest;
use App\Services\FixedAssetMaintenanceService;
use App\Services\FixedAssetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Fixed Assets Phase 4 — Maintenance requests (plan §5, §10;
 * spec §15).
 *
 * Request → approval → in_progress → completed → verified.
 * Costs are recorded against a request and post Dr maintenance
 * expense / Cr accounts payable immediately. All actions need
 * "manage fixed assets" (no new permission — plan §8 lists
 * exactly 11); the service itself refuses self-approval.
 */
class FixedAssetMaintenanceController extends Controller
{
    public function __construct(
        protected FixedAssetMaintenanceService $maintenance,
        protected FixedAssetService $assets,
    ) {
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $requests = FixedAssetMaintenanceRequest::forCompany($creatorId)
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->input('search').'%';
                $q->whereHas('asset', function ($inner) use ($search) {
                    $inner->where('name', 'like', $search)->orWhere('code', 'like', $search);
                });
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->with(['asset', 'costs'])
            ->orderByDesc('id')
            ->paginate(20);

        return view('fixed-assets.maintenance.index', compact('requests'));
    }

    public function create(Request $request)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $selectedAsset = $request->filled('asset_id')
            ? FixedAsset::forCompany($creatorId)->find($request->input('asset_id'))
            : null;

        $assets = FixedAsset::forCompany($creatorId)
            ->whereIn('status', [
                FixedAsset::STATUS_AVAILABLE,
                FixedAsset::STATUS_ACTIVE,
                FixedAsset::STATUS_ASSIGNED,
                FixedAsset::STATUS_FULLY_DEPRECIATED,
                FixedAsset::STATUS_OUT_OF_SERVICE,
                FixedAsset::STATUS_DAMAGED,
                FixedAsset::STATUS_IMPAIRED,
            ])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'status']);

        return view('fixed-assets.maintenance.form', compact('assets', 'selectedAsset'));
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'asset_id' => ['required', 'integer'],
            'type' => ['required', 'string'],
            'priority' => ['nullable', 'string'],
            'description' => ['required', 'string', 'max:2000'],
            'expected_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $creatorId = (int) Auth::user()->creatorId();

        try {
            $asset = FixedAsset::forCompany($creatorId)->findOrFail((int) $request->input('asset_id'));
            $maintenanceRequest = $this->maintenance->requestMaintenance($asset, $request->only(['type', 'priority', 'description', 'expected_cost']), $creatorId, (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('fixed-asset-maintenance.show', $maintenanceRequest->id)
            ->with('success', __('Maintenance requested.'));
    }

    public function show(FixedAssetMaintenanceRequest $maintenance)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        try {
            $this->assets->assertSameCompany($maintenance, $creatorId);
        } catch (FixedAssetException) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $maintenance->load(['asset', 'costs', 'requester']);
        $approvals = FixedAssetApproval::where('created_by', $creatorId)
            ->where('transaction_type', FixedAssetApproval::TYPE_MAINTENANCE)
            ->where('transaction_id', $maintenance->id)
            ->with(['approver', 'requester'])
            ->orderBy('created_at')
            ->get();

        return view('fixed-assets.maintenance.show', compact('maintenance', 'approvals'));
    }

    public function decide(Request $request, FixedAssetMaintenanceRequest $maintenance)
    {
        $request->validate([
            'decision' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        try {
            $approval = FixedAssetApproval::where('created_by', $creatorId)
                ->where('transaction_type', FixedAssetApproval::TYPE_MAINTENANCE)
                ->where('transaction_id', $maintenance->id)
                ->where('decision', FixedAssetApproval::DECISION_PENDING)
                ->first();
            if (! $approval) {
                return redirect()->back()->with('error', __('No pending approval found for this maintenance request.'));
            }

            $this->maintenance->decideMaintenanceApproval($approval, (int) Auth::id(), $request->input('decision'), $request->input('reason'));
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Maintenance decision recorded.'));
    }

    public function start(FixedAssetMaintenanceRequest $maintenance)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->maintenance->startMaintenance($maintenance, (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Maintenance started.'));
    }

    public function complete(Request $request, FixedAssetMaintenanceRequest $maintenance)
    {
        $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->maintenance->completeMaintenance($maintenance, (int) Auth::user()->creatorId(), (int) Auth::id(), $request->input('notes'));
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Maintenance completed. The asset is back to its previous status.'));
    }

    public function verify(FixedAssetMaintenanceRequest $maintenance)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->maintenance->verifyMaintenance($maintenance, (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Maintenance verified.'));
    }

    public function cancel(FixedAssetMaintenanceRequest $maintenance)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->maintenance->cancelMaintenance($maintenance, (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Maintenance request cancelled.'));
    }

    public function recordCost(Request $request, FixedAssetMaintenanceRequest $maintenance)
    {
        $request->validate([
            'cost_type' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'max:1000'],
            'vendor' => ['nullable', 'string', 'max:255'],
        ]);

        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->maintenance->recordCost($maintenance, $request->only(['cost_type', 'amount', 'description', 'vendor']), (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Maintenance cost recorded and posted.'));
    }
}
