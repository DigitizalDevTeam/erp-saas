<?php

namespace App\Http\Controllers;

use App\Exceptions\FixedAssetException;
use App\Models\FixedAssetLocation;
use App\Models\FixedAssetPhysicalVerification;
use App\Models\FixedAssetVerificationItem;
use App\Services\FixedAssetService;
use App\Services\FixedAssetVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Fixed Assets Phase 4 — Physical verification campaigns
 * (plan §5, §10; spec §19).
 *
 * Planned → in_progress → closed; cancelled is terminal.
 * Checks are recorded per asset while the campaign runs.
 * Missing assets are flagged, never automatically written
 * off. All actions need "manage fixed assets" (no new
 * permission — plan §8 lists exactly 11).
 */
class FixedAssetVerificationController extends Controller
{
    public function __construct(
        protected FixedAssetVerificationService $verification,
        protected FixedAssetService $assets,
    ) {
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $campaigns = FixedAssetPhysicalVerification::forCompany($creatorId)
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->input('search').'%';
                $q->where('name', 'like', $search);
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->with(['location', 'items'])
            ->orderByDesc('id')
            ->paginate(20);

        return view('fixed-assets.verifications.index', compact('campaigns'));
    }

    public function create()
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $locations = FixedAssetLocation::forCompany($creatorId)->orderBy('code')->get(['id', 'code', 'name']);

        return view('fixed-assets.verifications.form', compact('locations'));
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'location_id' => ['nullable', 'integer'],
            'deadline' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $creatorId = (int) Auth::user()->creatorId();

        try {
            $campaign = $this->verification->createCampaign($request->only(['name', 'location_id', 'deadline', 'notes']), $creatorId, (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('fixed-asset-verifications.show', $campaign->id)
            ->with('success', __('Verification campaign created.'));
    }

    public function show(FixedAssetPhysicalVerification $verification)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->assets->assertSameCompany($verification, (int) Auth::user()->creatorId());
        } catch (FixedAssetException) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $verification->load(['location', 'items.asset', 'items.expectedLocation', 'items.expectedCustodian', 'items.checker']);
        $counts = $verification->resultCounts();

        return view('fixed-assets.verifications.show', compact('verification', 'counts'));
    }

    public function start(FixedAssetPhysicalVerification $verification)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->verification->startCampaign($verification, (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Verification campaign started. Assets are now under verification.'));
    }

    public function check(Request $request, FixedAssetPhysicalVerification $verification, FixedAssetVerificationItem $item)
    {
        $request->validate([
            'result' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ((int) $item->verification_id !== (int) $verification->id) {
            return redirect()->back()->with('error', __('This item does not belong to this campaign.'));
        }

        try {
            $this->verification->checkItem($item, $request->input('result'), (int) Auth::user()->creatorId(), (int) Auth::id(), $request->input('note'));
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Asset checked.'));
    }

    public function close(FixedAssetPhysicalVerification $verification)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->verification->closeCampaign($verification, (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Verification campaign closed. Missing assets are flagged, not written off.'));
    }

    public function cancel(FixedAssetPhysicalVerification $verification)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->verification->cancelCampaign($verification, (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Verification campaign cancelled.'));
    }
}
