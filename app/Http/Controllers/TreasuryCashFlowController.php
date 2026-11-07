<?php

namespace App\Http\Controllers;

use App\Exceptions\TreasuryException;
use App\Models\TreasuryCashFlowAlert;
use App\Models\TreasuryForecastItem;
use App\Services\TreasuryAlertService;
use App\Services\TreasuryForecastService;
use App\Services\TreasurySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Treasury Phase 8 — cash-flow calendar + manual projections +
 * alerts (plan §8, §10).
 *
 * The calendar is forecast vs actual, per scenario, grouped by
 * day/week/month. Forecast and actual are shown separately,
 * never mixed. Contract projections are manual estimates until
 * the Contract Management module lands — they are labelled as
 * estimates, never presented as issued invoices (plan risk 4).
 *
 * All rules live in TreasuryForecastService / TreasuryAlertService;
 * this controller only gates permissions, scopes to the company
 * and turns service exceptions into redirects.
 */
class TreasuryCashFlowController extends Controller
{
    protected TreasuryForecastService $forecast;
    protected TreasuryAlertService $alerts;

    public function __construct(TreasuryForecastService $forecast, TreasuryAlertService $alerts)
    {
        $this->forecast = $forecast;
        $this->alerts = $alerts;
    }

    public function index(Request $request)
    {
        if (! Auth::user()->can('manage treasury cash flow')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $days = max(1, min(365, (int) $request->input('days', 30)));
        $group = in_array($request->input('group'), ['day', 'week', 'month'], true) ? $request->input('group') : 'day';
        $scenario = in_array($request->input('scenario'), TreasuryForecastItem::SCENARIOS, true)
            ? $request->input('scenario')
            : 'baseline';

        $this->forecast->refreshForecast($creatorId);
        $this->alerts->refreshAlerts($creatorId);

        $calendar = $this->forecast->forecast($creatorId, $days, $scenario, $group);
        $threshold = $calendar['threshold'];
        $firstBelow = $calendar['first_day_below_threshold'];
        $baseCurrency = TreasurySettings::baseCurrency($creatorId);
        $activeAlerts = TreasuryCashFlowAlert::where('created_by', $creatorId)
            ->where('state', TreasuryCashFlowAlert::STATE_ACTIVE)
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();

        return view('treasury.cash-flow.index', compact(
            'calendar', 'days', 'group', 'scenario', 'threshold', 'firstBelow', 'baseCurrency', 'activeAlerts'
        ));
    }

    /** Manual projections (incl. contract estimates) — list + create form. */
    public function projections(Request $request)
    {
        if (! Auth::user()->can('manage treasury cash flow')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        $items = TreasuryForecastItem::where('created_by', $creatorId)
            ->whereIn('source_type', [TreasuryForecastItem::SOURCE_MANUAL, TreasuryForecastItem::SOURCE_CONTRACT])
            ->orderBy('expected_date')
            ->orderBy('id')
            ->paginate(25);

        $baseCurrency = TreasurySettings::baseCurrency($creatorId);

        return view('treasury.cash-flow.projections', compact('items', 'baseCurrency'));
    }

    public function storeProjection(Request $request)
    {
        if (! Auth::user()->can('manage treasury cash flow')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        try {
            $this->forecast->createManualProjection($creatorId, [
                'source_type' => $request->input('source_type', 'manual'),
                'document' => $request->input('reference'),
                'direction' => $request->input('direction'),
                'amount' => $request->input('amount'),
                'expected_date' => $request->input('expected_date'),
                'probability' => $request->input('probability'),
                'description' => $request->input('note'),
            ], (int) Auth::id());
        } catch (TreasuryException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-cash-flow.projections')
            ->with('success', __('Projection added.'));
    }

    public function destroyProjection(int $id)
    {
        if (! Auth::user()->can('manage treasury cash flow')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        try {
            $this->forecast->deleteManualProjection($creatorId, $id);
        } catch (TreasuryException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->route('treasury-cash-flow.projections')
            ->with('success', __('Projection deleted.'));
    }
}
