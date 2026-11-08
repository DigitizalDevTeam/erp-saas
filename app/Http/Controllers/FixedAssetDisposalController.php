<?php

namespace App\Http\Controllers;

use App\Exceptions\FixedAssetException;
use App\Models\FixedAsset;
use App\Models\FixedAssetApproval;
use App\Models\FixedAssetDisposal;
use App\Services\FixedAssetDisposalService;
use App\Services\FixedAssetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Fixed Assets Phase 5 — Disposals (plan §5, §10; spec §16, §17).
 *
 * Request → approval → execution. Only an approved disposal
 * can be executed; the execution posts the disposal journal
 * and flips the asset to disposed / written_off. All actions
 * need "manage fixed assets"; deciding an approval needs
 * "approve fixed asset disposals" (the service itself refuses
 * self-approval).
 */
class FixedAssetDisposalController extends Controller
{
    public function __construct(
        protected FixedAssetDisposalService $disposals,
        protected FixedAssetService $assets,
    ) {
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $disposals = FixedAssetDisposal::forCompany($creatorId)
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->input('search').'%';
                $q->whereHas('asset', function ($inner) use ($search) {
                    $inner->where('name', 'like', $search)->orWhere('code', 'like', $search);
                });
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('method'), fn ($q) => $q->where('method', $request->input('method')))
            ->with(['asset'])
            ->orderByDesc('id')
            ->paginate(20);

        return view('fixed-assets.disposals.index', compact('disposals'));
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

        // Only assets that can actually be disposed (the service
        // re-checks the exact rules at request time).
        $assets = FixedAsset::forCompany($creatorId)
            ->whereIn('status', [
                FixedAsset::STATUS_AVAILABLE,
                FixedAsset::STATUS_ACTIVE,
                FixedAsset::STATUS_DAMAGED,
                FixedAsset::STATUS_LOST,
                FixedAsset::STATUS_STOLEN,
                FixedAsset::STATUS_FULLY_DEPRECIATED,
                FixedAsset::STATUS_OUT_OF_SERVICE,
                FixedAsset::STATUS_IMPAIRED,
            ])
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'status']);

        return view('fixed-assets.disposals.form', compact('assets', 'selectedAsset'));
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'asset_id' => ['required', 'integer'],
            'method' => ['required', 'string'],
            'reason' => ['required', 'string', 'max:2000'],
            'expected_proceeds' => ['nullable', 'numeric', 'min:0'],
            'buyer_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $creatorId = (int) Auth::user()->creatorId();

        try {
            $asset = FixedAsset::forCompany($creatorId)->findOrFail((int) $request->input('asset_id'));
            $disposal = $this->disposals->requestDisposal($asset, $request->only(['method', 'reason', 'expected_proceeds', 'buyer_name', 'notes']), $creatorId, (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('fixed-asset-disposals.show', $disposal->id)
            ->with('success', __('Disposal requested. It needs approval before it can be executed.'));
    }

    public function show(FixedAssetDisposal $disposal)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        try {
            $this->assets->assertSameCompany($disposal, $creatorId);
        } catch (FixedAssetException) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $disposal->load(['asset', 'journal']);
        $approvals = FixedAssetApproval::where('created_by', $creatorId)
            ->where('transaction_type', FixedAssetApproval::TYPE_DISPOSAL)
            ->where('transaction_id', $disposal->id)
            ->with(['approver', 'requester'])
            ->orderBy('created_at')
            ->get();

        return view('fixed-assets.disposals.show', compact('disposal', 'approvals'));
    }

    public function decide(Request $request, FixedAssetDisposal $disposal)
    {
        $request->validate([
            'decision' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if (! Auth::user()->can('approve fixed asset disposals')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        try {
            $approval = FixedAssetApproval::where('created_by', $creatorId)
                ->where('transaction_type', FixedAssetApproval::TYPE_DISPOSAL)
                ->where('transaction_id', $disposal->id)
                ->where('decision', FixedAssetApproval::DECISION_PENDING)
                ->first();
            if (! $approval) {
                return redirect()->back()->with('error', __('No pending approval found for this disposal.'));
            }

            $this->disposals->decideDisposalApproval($approval, (int) Auth::id(), $request->input('decision'), $request->input('reason'));
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Disposal decision recorded.'));
    }

    public function executeForm(FixedAssetDisposal $disposal)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        try {
            $this->assets->assertSameCompany($disposal, $creatorId);
        } catch (FixedAssetException) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! $disposal->isApproved()) {
            return redirect()->back()->with('error', __('Only an approved disposal can be executed.'));
        }

        $disposal->load(['asset']);

        return view('fixed-assets.disposals.execute', compact('disposal'));
    }

    public function execute(Request $request, FixedAssetDisposal $disposal)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'actual_proceeds' => ['required', 'numeric', 'min:0'],
            'proceeds_received' => ['nullable', 'boolean'],
            'disposal_date' => ['nullable', 'date'],
        ]);

        try {
            $this->disposals->executeDisposal($disposal, $request->only(['actual_proceeds', 'proceeds_received', 'disposal_date']), (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('fixed-asset-disposals.show', $disposal->id)
            ->with('success', __('Disposal executed and posted.'));
    }

    public function cancel(FixedAssetDisposal $disposal)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->disposals->cancelDisposal($disposal, (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Disposal cancelled.'));
    }
}
