<?php

namespace App\Http\Controllers;

use App\Exceptions\FixedAssetException;
use App\Models\CostCentre;
use App\Models\Employee;
use App\Models\FixedAsset;
use App\Models\FixedAssetApproval;
use App\Models\FixedAssetLocation;
use App\Models\FixedAssetTransfer;
use App\Services\FixedAssetService;
use App\Services\FixedAssetTransferService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Fixed Assets Phase 2 — Transfer workflow controller
 * (spec §12, plan §10 row 2).
 *
 * Request → manager approval → handover → destination
 * receipt. The asset master moves only on receipt. Approval
 * decisions go through FixedAssetTransferService so the
 * no-self-approval rule and the audit trail are enforced in
 * exactly one place.
 */
class FixedAssetTransferController extends Controller
{
    public function __construct(
        protected FixedAssetTransferService $transfers,
        protected FixedAssetService $assets,
    ) {
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $transfers = FixedAssetTransfer::forCompany($creatorId)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->input('search').'%';
                $q->where(function ($inner) use ($search) {
                    $inner->whereHas('asset', fn ($a) => $a->where('code', 'like', $search)->orWhere('name', 'like', $search))
                        ->orWhereHas('toCustodian', fn ($c) => $c->where('name', 'like', $search));
                });
            })
            ->with(['asset', 'fromLocation', 'toLocation', 'fromCustodian', 'toCustodian'])
            ->orderByDesc('id')
            ->paginate(20);

        return view('fixed-assets.transfers.index', compact('transfers'));
    }

    public function show(FixedAssetTransfer $transfer)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        try {
            $this->assets->assertSameCompany($transfer, $creatorId);
        } catch (FixedAssetException) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $transfer->load(['asset.category', 'fromLocation', 'toLocation', 'fromCustodian', 'toCustodian', 'fromCostCentre', 'toCostCentre']);
        $approvals = FixedAssetApproval::where('created_by', $creatorId)
            ->where('transaction_type', FixedAssetApproval::TYPE_TRANSFER)
            ->where('transaction_id', $transfer->id)
            ->orderBy('level')
            ->get();

        return view('fixed-assets.transfers.show', compact('transfer', 'approvals'));
    }

    public function create(Request $request)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $assets = FixedAsset::forCompany($creatorId)
            ->whereIn('status', [FixedAsset::STATUS_AVAILABLE, FixedAsset::STATUS_ASSIGNED])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'location_id', 'custodian_id', 'cost_centre_id']);
        $employees = Employee::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']);
        $locations = FixedAssetLocation::forCompany($creatorId)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
        $costCentres = CostCentre::where('created_by', $creatorId)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']);
        $selectedAsset = $request->filled('asset_id') ? FixedAsset::forCompany($creatorId)->find($request->input('asset_id')) : null;

        return view('fixed-assets.transfers.form', compact('assets', 'employees', 'locations', 'costCentres', 'selectedAsset'));
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $data = $request->validate([
            'asset_id' => ['required', 'integer'],
            'to_location_id' => ['nullable', 'integer'],
            'to_custodian_id' => ['nullable', 'integer'],
            'to_cost_centre_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string'],
        ]);

        $creatorId = (int) Auth::user()->creatorId();
        $asset = FixedAsset::forCompany($creatorId)->find($data['asset_id']);
        if (! $asset) {
            return redirect()->back()->withInput()->with('error', __('Asset not found for this company.'));
        }

        try {
            $transfer = $this->transfers->requestTransfer($asset, $data, $creatorId, (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('fixed-asset-transfers.show', $transfer->id)
            ->with('success', __('Transfer requested. It needs approval before handover.'));
    }

    public function decide(Request $request, FixedAssetTransfer $transfer)
    {
        $request->validate([
            'decision' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if (! Auth::user()->can('approve fixed asset transfers')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        try {
            $approval = FixedAssetApproval::where('created_by', $creatorId)
                ->where('transaction_type', FixedAssetApproval::TYPE_TRANSFER)
                ->where('transaction_id', $transfer->id)
                ->where('decision', FixedAssetApproval::DECISION_PENDING)
                ->first();
            if (! $approval) {
                return redirect()->back()->with('error', __('No pending approval found for this transfer.'));
            }

            $this->transfers->decideTransferApproval($approval, (int) Auth::id(), $request->input('decision'), $request->input('reason'));
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Decision recorded.'));
    }

    public function handOver(FixedAssetTransfer $transfer)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->transfers->handOverTransfer($transfer, (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Transfer handed over. Waiting for destination receipt.'));
    }

    public function receive(FixedAssetTransfer $transfer)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->transfers->receiveTransfer($transfer, (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Transfer received. The asset location and custodian are updated.'));
    }

    public function reject(Request $request, FixedAssetTransfer $transfer)
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        if (! Auth::user()->can('approve fixed asset transfers')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->transfers->rejectTransfer($transfer, (int) Auth::user()->creatorId(), (int) Auth::id(), $request->input('reason'));
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Transfer rejected. The asset is back to its previous status.'));
    }

    public function cancel(FixedAssetTransfer $transfer)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->transfers->cancelTransfer($transfer, (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Transfer cancelled. The asset is back to its previous status.'));
    }
}
