<?php

namespace App\Http\Controllers;

use App\Services\TreasuryReportService;
use Illuminate\Support\Facades\Auth;

/**
 * Treasury Phase 9 — dashboard (plan §9 KPIs).
 *
 * Read-only: balances, expected receipts/payments, overdue items,
 * pending approvals, reconciliation state, 30-day forecast,
 * advances outstanding, alerts, funds in transit and open cash
 * sessions. All figures come from TreasuryReportService; this
 * controller only gates the permission and scopes to the company.
 */
class TreasuryDashboardController extends Controller
{
    public function __construct(protected TreasuryReportService $reports = new TreasuryReportService())
    {
    }

    public function index()
    {
        if (! Auth::user()->can('view treasury reports')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $dashboard = $this->reports->dashboard($creatorId);

        return view('treasury.dashboard.index', compact('dashboard'));
    }
}
