<?php

namespace App\Http\Controllers;

use App\Exceptions\TreasuryException;
use App\Models\TreasuryRecurringOccurrence;
use App\Services\TreasuryRecurringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Treasury Phase 8 — recurring occurrences (plan §6, §10).
 *
 * Flow: generate (job) → confirm → paid (or skip). A paid
 * occurrence is a record that the commitment was settled; it
 * does not post a payment by itself.
 */
class TreasuryRecurringOccurrenceController extends Controller
{
    protected TreasuryRecurringService $service;

    public function __construct(TreasuryRecurringService $service)
    {
        $this->service = $service;
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('manage treasury recurring')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $query = TreasuryRecurringOccurrence::whereHas('rule', function ($q) use ($creatorId) {
            $q->where('created_by', $creatorId);
        })
            ->with('rule')
            ->orderBy('scheduled_date')
            ->orderBy('id');

        if ($request->filled('state')) {
            $query->where('state', $request->state);
        }
        if ($request->filled('rule_id')) {
            $query->where('rule_id', (int) $request->rule_id);
        }
        if ($request->filled('from')) {
            $query->where('scheduled_date', '>=', $request->from);
        }
        if ($request->filled('to')) {
            $query->where('scheduled_date', '<=', $request->to);
        }

        $occurrences = $query->paginate(25);

        return view('treasury.recurring.occurrences.index', compact('occurrences'));
    }

    public function confirm(int $id)
    {
        if (! Auth::user()->can('manage treasury recurring')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->service->confirmOccurrence((int) Auth::user()->creatorId(), $id, (int) Auth::id());
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Occurrence confirmed.'));
    }

    public function markPaid(int $id)
    {
        if (! Auth::user()->can('manage treasury recurring')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->service->markPaid((int) Auth::user()->creatorId(), $id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Occurrence marked paid.'));
    }

    public function skip(Request $request, int $id)
    {
        if (! Auth::user()->can('manage treasury recurring')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->service->skipOccurrence((int) Auth::user()->creatorId(), $id, $request->input('reason'));
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Occurrence skipped.'));
    }
}
