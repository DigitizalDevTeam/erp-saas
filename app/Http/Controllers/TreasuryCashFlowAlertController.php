<?php

namespace App\Http\Controllers;

use App\Exceptions\TreasuryException;
use App\Models\TreasuryCashFlowAlert;
use App\Services\TreasuryAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Treasury Phase 8 — cash-flow alerts list (plan §8, §10).
 *
 * Alerts are records recomputed by the forecast-refresh job and
 * the cash-flow screen; no email is sent in this phase. An alert
 * can be acknowledged; it resolves itself when its condition
 * clears on the next refresh.
 */
class TreasuryCashFlowAlertController extends Controller
{
    protected TreasuryAlertService $alerts;

    public function __construct(TreasuryAlertService $alerts)
    {
        $this->alerts = $alerts;
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('manage treasury cash flow')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        // Recompute first, so the list is never stale (the refresh
        // is idempotent — one active alert per condition).
        $this->alerts->refreshAlerts($creatorId);

        $query = TreasuryCashFlowAlert::where('created_by', $creatorId)
            ->orderByRaw("CASE state WHEN 'active' THEN 0 WHEN 'acknowledged' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('occurred_at');

        if ($request->filled('state')) {
            $query->where('state', $request->state);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $alerts = $query->paginate(25);

        return view('treasury.cash-flow.alerts', compact('alerts'));
    }

    public function acknowledge(int $id)
    {
        if (! Auth::user()->can('manage treasury cash flow')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->alerts->acknowledge((int) Auth::user()->creatorId(), $id, (int) Auth::id());
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Alert acknowledged.'));
    }
}
