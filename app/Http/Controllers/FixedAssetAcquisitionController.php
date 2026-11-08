<?php

namespace App\Http\Controllers;

use App\Exceptions\FixedAssetException;
use App\Models\FixedAssetAcquisition;
use App\Services\FixedAssetAcquisitionService;
use App\Services\FixedAssetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Fixed Assets Phase 1 — Acquisition workflow controller.
 *
 * Review → approval → capitalization (spec §6, plan §10 row 1).
 * Approval decisions go through FixedAssetAcquisitionService so
 * the no-self-approval rule, level rules and the audit trail
 * are enforced in exactly one place.
 */
class FixedAssetAcquisitionController extends Controller
{
    public function __construct(
        protected FixedAssetAcquisitionService $acquisitions,
        protected FixedAssetService $assets,
    ) {
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $acquisitions = FixedAssetAcquisition::forCompany($creatorId)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('source_type'), fn ($q) => $q->where('source_type', $request->input('source_type')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->input('search').'%';
                $q->where(function ($inner) use ($search) {
                    $inner->where('source_reference', 'like', $search)
                        ->orWhereHas('asset', fn ($a) => $a->where('code', 'like', $search)->orWhere('name', 'like', $search));
                });
            })
            ->with(['asset.category', 'asset', 'costs'])
            ->orderByDesc('id')
            ->paginate(20);

        return view('fixed-assets.acquisitions.index', compact('acquisitions'));
    }

    public function show(FixedAssetAcquisition $acquisition)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        try {
            $this->assets->assertSameCompany($acquisition, $creatorId);
        } catch (FixedAssetException) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $acquisition->load(['asset.category', 'asset.location', 'costs', 'capitalization.journal', 'approvals']);
        $asset = $acquisition->asset;
        $schedule = collect();
        if ($asset && \Illuminate\Support\Facades\Schema::hasTable('fixed_asset_depreciation_schedules')) {
            $schedule = \App\Models\FixedAssetDepreciationSchedule::where('created_by', $creatorId)->where('asset_id', $asset->id)->orderBy('period')->get();
        }

        return view('fixed-assets.acquisitions.show', compact('acquisition', 'asset', 'schedule'));
    }

    public function submitForReview(FixedAssetAcquisition $acquisition)
    {
        return $this->run($acquisition, 'submitForReview', 'Acquisition submitted for review.');
    }

    public function returnForCorrection(Request $request, FixedAssetAcquisition $acquisition)
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->run($acquisition, 'returnForCorrection', 'Acquisition returned for correction.', $request->input('reason'));
    }

    public function submitForApproval(FixedAssetAcquisition $acquisition)
    {
        return $this->run($acquisition, 'submitForApproval', 'Acquisition submitted for approval.');
    }

    public function decide(Request $request, FixedAssetAcquisition $acquisition)
    {
        $request->validate([
            'decision' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        if (! Auth::user()->can('approve fixed asset acquisitions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        try {
            $approval = $acquisition->approvals()->where('decision', \App\Models\FixedAssetApproval::DECISION_PENDING)->first();
            if (! $approval) {
                return redirect()->back()->with('error', __('No pending approval found for this acquisition.'));
            }

            $this->acquisitions->decideAcquisitionApproval($approval, (int) Auth::id(), $request->input('decision'), $request->input('reason'));
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Decision recorded.'));
    }

    public function reject(Request $request, FixedAssetAcquisition $acquisition)
    {
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        if (! Auth::user()->can('approve fixed asset acquisitions')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->acquisitions->rejectAcquisition($acquisition, (int) Auth::user()->creatorId(), (int) Auth::id(), $request->input('reason'));
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Acquisition rejected.'));
    }

    public function cancel(FixedAssetAcquisition $acquisition)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->acquisitions->cancelAcquisition($acquisition, (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Acquisition cancelled.'));
    }

    public function capitalize(FixedAssetAcquisition $acquisition)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $result = $this->acquisitions->capitalize($acquisition, (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $journal = $result['journal_id'] ? __(' Journal #:journal_id posted.', ['journal_id' => $result['journal_id']]) : '';

        return redirect()->back()->with('success', __('Asset capitalized. Depreciation schedule activated from :date.:journal', ['date' => $result['depreciation_start_date'], 'journal' => $journal]));
    }

    protected function run(FixedAssetAcquisition $acquisition, string $method, string $successMessage, ?string $reason = null)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            if ($reason !== null) {
                $this->acquisitions->$method($acquisition, (int) Auth::user()->creatorId(), (int) Auth::id(), $reason);
            } else {
                $this->acquisitions->$method($acquisition, (int) Auth::user()->creatorId(), (int) Auth::id());
            }
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __($successMessage));
    }
}
