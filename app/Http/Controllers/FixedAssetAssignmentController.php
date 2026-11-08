<?php

namespace App\Http\Controllers;

use App\Exceptions\FixedAssetException;
use App\Models\CostCentre;
use App\Models\Employee;
use App\Models\FixedAsset;
use App\Models\FixedAssetApproval;
use App\Models\FixedAssetAssignment;
use App\Models\FixedAssetLocation;
use App\Services\FixedAssetAssignmentService;
use App\Services\FixedAssetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Fixed Assets Phase 2 — Assignment workflow controller
 * (spec §11, plan §10 row 2).
 *
 * Requested → approved → handed over → accepted by the
 * custodian (or refused). The asset is "assigned" only on
 * acceptance. Approval decisions go through
 * FixedAssetAssignmentService so the no-self-approval rule
 * and the audit trail are enforced in exactly one place.
 *
 * Custodian acceptance: the custodian's linked user
 * (Employee user_id) records the decision, or a Fixed Asset
 * Officer (manage fixed assets) records it on the custodian's
 * behalf — employees do not always log in themselves.
 */
class FixedAssetAssignmentController extends Controller
{
    public function __construct(
        protected FixedAssetAssignmentService $assignments,
        protected FixedAssetService $assets,
    ) {
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $assignments = FixedAssetAssignment::forCompany($creatorId)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->input('search').'%';
                $q->where(function ($inner) use ($search) {
                    $inner->whereHas('asset', fn ($a) => $a->where('code', 'like', $search)->orWhere('name', 'like', $search))
                        ->orWhereHas('custodian', fn ($c) => $c->where('name', 'like', $search));
                });
            })
            ->with(['asset', 'custodian', 'location', 'costCentre'])
            ->orderByDesc('id')
            ->paginate(20);

        return view('fixed-assets.assignments.index', compact('assignments'));
    }

    public function show(FixedAssetAssignment $assignment)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        try {
            $this->assets->assertSameCompany($assignment, $creatorId);
        } catch (FixedAssetException) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $assignment->load(['asset.category', 'custodian', 'previousCustodian', 'location', 'previousLocation', 'costCentre', 'previousCostCentre']);
        $approvals = FixedAssetApproval::where('created_by', $creatorId)
            ->where('transaction_type', FixedAssetApproval::TYPE_ASSIGNMENT)
            ->where('transaction_id', $assignment->id)
            ->orderBy('level')
            ->get();

        return view('fixed-assets.assignments.show', compact('assignment', 'approvals'));
    }

    public function create(Request $request)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $assets = FixedAsset::forCompany($creatorId)
            ->where('status', FixedAsset::STATUS_AVAILABLE)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
        $employees = Employee::where('created_by', $creatorId)->orderBy('name')->get(['id', 'name']);
        $locations = FixedAssetLocation::forCompany($creatorId)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);
        $costCentres = CostCentre::where('created_by', $creatorId)->where('status', 'active')->orderBy('code')->get(['id', 'code', 'name']);
        $selectedAsset = $request->filled('asset_id') ? FixedAsset::forCompany($creatorId)->find($request->input('asset_id')) : null;

        return view('fixed-assets.assignments.form', compact('assets', 'employees', 'locations', 'costCentres', 'selectedAsset'));
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $data = $request->validate([
            'asset_id' => ['required', 'integer'],
            'custodian_id' => ['required', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'cost_centre_id' => ['nullable', 'integer'],
            'condition' => ['nullable', 'string', 'max:100'],
            'accessories' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $creatorId = (int) Auth::user()->creatorId();
        $asset = FixedAsset::forCompany($creatorId)->find($data['asset_id']);
        if (! $asset) {
            return redirect()->back()->withInput()->with('error', __('Asset not found for this company.'));
        }

        try {
            $assignment = $this->assignments->requestAssignment($asset, $data, $creatorId, (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('fixed-asset-assignments.show', $assignment->id)
            ->with('success', __('Assignment requested. It needs approval before handover.'));
    }

    public function decide(Request $request, FixedAssetAssignment $assignment)
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
                ->where('transaction_type', FixedAssetApproval::TYPE_ASSIGNMENT)
                ->where('transaction_id', $assignment->id)
                ->where('decision', FixedAssetApproval::DECISION_PENDING)
                ->first();
            if (! $approval) {
                return redirect()->back()->with('error', __('No pending approval found for this assignment.'));
            }

            $this->assignments->decideAssignmentApproval($approval, (int) Auth::id(), $request->input('decision'), $request->input('reason'));
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Decision recorded.'));
    }

    public function handOver(FixedAssetAssignment $assignment)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->assignments->handOverAssignment($assignment, (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Asset handed over. Waiting for custodian acceptance.'));
    }

    public function accept(FixedAssetAssignment $assignment)
    {
        if (! $this->canActAsCustodian($assignment)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->assignments->acceptAssignment($assignment, (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Assignment accepted. The asset is now assigned.'));
    }

    public function refuse(Request $request, FixedAssetAssignment $assignment)
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        if (! $this->canActAsCustodian($assignment)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->assignments->refuseAssignment($assignment, (int) Auth::user()->creatorId(), (int) Auth::id(), $request->input('reason'));
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Assignment refused. The asset is available again.'));
    }

    public function return(Request $request, FixedAssetAssignment $assignment)
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->assignments->returnAssignment($assignment, (int) Auth::user()->creatorId(), (int) Auth::id(), $request->input('reason'));
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Assignment returned. The asset is available again.'));
    }

    public function cancel(FixedAssetAssignment $assignment)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->assignments->cancelAssignment($assignment, (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Assignment cancelled.'));
    }

    /**
     * The custodian's linked user may accept / refuse their own
     * assignment; a Fixed Asset Officer may record the decision
     * on the custodian's behalf.
     */
    protected function canActAsCustodian(FixedAssetAssignment $assignment): bool
    {
        if (Auth::user()->can('manage fixed assets')) {
            return true;
        }

        $custodian = $assignment->custodian;

        return $custodian && (int) $custodian->user_id === (int) Auth::id();
    }
}
