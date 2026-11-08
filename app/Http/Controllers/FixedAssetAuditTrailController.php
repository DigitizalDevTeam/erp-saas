<?php

namespace App\Http\Controllers;

use App\Services\FixedAssetReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Fixed Assets Phase 6 — audit trail screen (plan §9, §10).
 *
 * Phase 0 seeded the 'view fixed asset audit trail' permission
 * and the menu node, but there was no screen behind them. This is
 * it: every recorded Fixed Assets event (who did what, when, on
 * which asset, with the before/after where recorded), newest
 * first, paged 50 at a time, company-scoped via creatorId().
 *
 * The same data is available as the Audit Trail report (with CSV /
 * XLSX / PDF export); the screen is the day-to-day view.
 */
class FixedAssetAuditTrailController extends Controller
{
    public function __construct(
        protected FixedAssetReportService $reports = new FixedAssetReportService(),
    ) {
    }

    public function index(Request $request)
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $filters = $this->reports->normalizeFilters($request->all());
        $events = $this->reports->auditTrailQuery($creatorId, $filters)->paginate(50)->withQueryString();

        return view('fixed-asset-audit-trail.index', [
            'events' => $events,
            'filters' => $filters,
        ]);
    }

    protected function canView(): bool
    {
        $user = Auth::user();

        return $user !== null
            && ($user->can('manage fixed assets') || $user->can('view fixed asset audit trail'));
    }
}
