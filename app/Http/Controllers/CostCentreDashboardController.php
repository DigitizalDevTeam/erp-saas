<?php

namespace App\Http\Controllers;

use App\Services\CostCentreDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CostCentreDashboardController extends Controller
{
    public function __construct(
        protected CostCentreDashboardService $dashboard = new CostCentreDashboardService(),
    ) {}

    /**
     * Cost centre dashboard (Phase 6, plan §10): one screen per
     * company for a fiscal month — budget status per centre, overspend
     * warnings, expense vs cash totals, reconciliation summary, and
     * links to the allocation ledger for each centre.
     */
    public function index(Request $request)
    {
        if (! Auth::user()->can('view cost centre reports')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        $fiscalYear = (int) ($request->input('fiscal_year') ?: date('Y'));
        $period = (int) ($request->input('period') ?: date('n'));
        if ($period < 1 || $period > 12) {
            $period = (int) date('n');
        }
        if ($fiscalYear < 2000 || $fiscalYear > 2100) {
            $fiscalYear = (int) date('Y');
        }

        $dashboard = $this->dashboard->dashboard($creatorId, $fiscalYear, $period);

        return view('cost-centres.dashboard', array_merge($dashboard, [
            'selectedPeriod' => $period,
        ]));
    }
}
