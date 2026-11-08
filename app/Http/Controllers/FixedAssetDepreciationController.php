<?php

namespace App\Http\Controllers;

use App\Exceptions\FixedAssetException;
use App\Models\FixedAssetApproval;
use App\Models\FixedAssetDepreciationRun;
use App\Services\FixedAssetDepreciationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

/**
 * Fixed Assets Phase 3 — monthly depreciation run controller
 * (spec §13, plan §10 row 3).
 *
 * Preview → create → review → approve → post. The preview
 * calculates the period's amounts without persisting anything;
 * posting is a separate, explicit step after approval and
 * writes ONE journal per run via JournalEntryPoster. Approval
 * decisions go through FixedAssetDepreciationService so the
 * no-self-approval rule and the audit trail are enforced in
 * exactly one place.
 */
class FixedAssetDepreciationController extends Controller
{
    public function __construct(
        protected FixedAssetDepreciationService $depreciation,
    ) {
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $runs = Schema::hasTable('fixed_asset_depreciation_runs')
            ? FixedAssetDepreciationRun::forCompany($creatorId)
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
                ->orderByDesc('period')
                ->paginate(20)
            : collect();

        return view('fixed-assets.depreciation.index', compact('runs'));
    }

    public function create()
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return view('fixed-assets.depreciation.create', ['period' => now()->format('Y-m')]);
    }

    /** Preview the period's amounts — pure calculation, nothing is persisted. */
    public function preview(Request $request)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate(['period' => ['required', 'regex:/^\d{4}-\d{2}$/']]);
        $creatorId = (int) Auth::user()->creatorId();
        $period = $request->input('period');

        try {
            $lines = $this->depreciation->computeLines($creatorId, $this->depreciation->periodDate($period));
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $total = '0';
        foreach ($lines as $line) {
            $total = bcadd($total, $line['amount'], 2);
        }

        return view('fixed-assets.depreciation.preview', compact('period', 'lines', 'total'));
    }

    public function store(Request $request)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate(['period' => ['required', 'regex:/^\d{4}-\d{2}$/']]);
        $creatorId = (int) Auth::user()->creatorId();

        try {
            $run = $this->depreciation->createRun($creatorId, (int) Auth::id(), $request->input('period'));
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('fixed-asset-depreciation.show', $run->id)
            ->with('success', __('Depreciation run for :period created.', ['period' => $request->input('period')]));
    }

    public function show(FixedAssetDepreciationRun $run)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        try {
            $this->depreciation->assertRunCompany($run, $creatorId);
        } catch (FixedAssetException) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $run->load(['entries.asset.category', 'entries.costCentre', 'entries.schedule']);
        $approvals = FixedAssetApproval::where('created_by', $creatorId)
            ->where('transaction_type', FixedAssetApproval::TYPE_DEPRECIATION)
            ->whereIn('transaction_id', $run->entries->pluck('id')->all())
            ->orderBy('asset_id')
            ->orderBy('level')
            ->get();

        return view('fixed-assets.depreciation.show', compact('run', 'approvals'));
    }

    public function submitReview(FixedAssetDepreciationRun $run)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->depreciation->reviewRun($run, (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Run marked as reviewed.'));
    }

    public function submitApproval(FixedAssetDepreciationRun $run)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->depreciation->submitForApproval($run, (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Run submitted for approval.'));
    }

    public function decide(Request $request, FixedAssetDepreciationRun $run)
    {
        if (! Auth::user()->can('post fixed asset depreciation')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate(['comment' => ['nullable', 'string', 'max:1000']]);

        try {
            $this->depreciation->approveRun($run, (int) Auth::user()->creatorId(), (int) Auth::id(), $request->input('comment'));
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Depreciation run approved.'));
    }

    public function post(FixedAssetDepreciationRun $run)
    {
        if (! Auth::user()->can('post fixed asset depreciation')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->depreciation->postRun($run, (int) Auth::user()->creatorId(), (int) Auth::id());
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        } catch (\App\Exceptions\CostCentreException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Depreciation run posted.'));
    }

    public function cancel(Request $request, FixedAssetDepreciationRun $run)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        try {
            $this->depreciation->cancelRun($run, (int) Auth::user()->creatorId(), (int) Auth::id(), $request->input('reason'));
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Depreciation run cancelled.'));
    }

    /** Monthly totals report: this period / year-to-date / remaining (plan §9). */
    public function report(Request $request)
    {
        if (! Auth::user()->can('manage fixed assets')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $period = $request->input('period', now()->format('Y-m'));
        if (! preg_match('/^\d{4}-\d{2}$/', (string) $period)) {
            $period = now()->format('Y-m');
        }

        $creatorId = (int) Auth::user()->creatorId();

        try {
            $totals = $this->depreciation->monthlyTotals($creatorId, $period);
        } catch (FixedAssetException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        // Cost-centre codes for the planned lines (the posted lines
        // already carry them through their costCentre relation).
        foreach ($totals['lines'] as $index => $line) {
            if (! $line['posted'] && $line['cost_centre_id']) {
                $centre = \App\Models\CostCentre::where('created_by', $creatorId)->find($line['cost_centre_id']);
                $totals['lines'][$index]['cost_centre_code'] = $centre?->code;
            }
        }

        return view('fixed-assets.depreciation.report', compact('period', 'totals'));
    }
}
