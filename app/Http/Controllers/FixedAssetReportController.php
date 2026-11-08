<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CostCentre;
use App\Models\Employee;
use App\Models\FixedAssetCategory;
use App\Models\FixedAssetLocation;
use App\Models\FixedAssetReportExport;
use App\Services\FixedAssetReportExportService;
use App\Services\FixedAssetReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Fixed Assets Phase 6 — fixed-asset dashboard + reports
 * (plan §9, §10).
 *
 * One controller for the dashboard and all nine reports so the
 * screen, the CSV, the XLSX and the PDF can never disagree: they
 * all read the same FixedAssetReportService output. CSV streams
 * synchronously; XLSX and PDF are queued
 * (GenerateFixedAssetReportExport) and downloaded through a signed,
 * 24-hour link. Every action is company-scoped and gated by
 * 'manage fixed assets' — the same view gate as the asset register.
 * The audit-trail report additionally accepts 'view fixed asset
 * audit trail', the permission of the dedicated audit screen.
 */
class FixedAssetReportController extends Controller
{
    public function __construct(
        protected FixedAssetReportService $reports = new FixedAssetReportService(),
        protected FixedAssetReportExportService $exports = new FixedAssetReportExportService(),
    ) {
    }

    /** Dashboard: KPIs + breakdowns + alerts. */
    public function dashboard(Request $request)
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $days = $this->reports->normalizeFilters($request->all())['days'];
        $data = $this->reports->dashboard($creatorId, $days);

        return view('fixed-asset-reports.dashboard', [
            'data' => $data,
            'days' => $days,
        ]);
    }

    /** Report hub: the nine reports + recent exports. */
    public function index()
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('fixed-asset-reports.index', [
            'reports' => FixedAssetReportService::REPORTS,
            'exports' => $this->exports->recentExports($creatorId),
            'downloadUrls' => $this->downloadUrls($creatorId),
        ]);
    }

    /** One report: filters + table + CSV export + queued XLSX/PDF request. */
    public function show(Request $request, string $report)
    {
        if (! $this->canViewReport($report)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! isset(FixedAssetReportService::REPORTS[$report])) {
            return redirect()->route('fixed-asset-reports.index')->with('error', __('Unknown report.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $filters = $this->reports->normalizeFilters($request->all());
        $data = $this->reports->report($creatorId, $report, $filters);

        // XLSX / PDF are queued — the file appears in the exports list.
        if (in_array($request->input('export'), ['xlsx', 'pdf'], true)) {
            $export = $this->exports->requestExport($creatorId, $report, (string) $request->input('export'), $filters);

            return redirect()->route('fixed-asset-reports.index')
                ->with('success', __('Export queued — it will appear in Recent exports below when it is ready.') . ' #' . $export->id);
        }

        // CSV streams synchronously with the same headings and rows.
        if ($csv = $this->exports->csvResponse(
            $request,
            'fixed-assets-' . $report,
            $data['title'],
            $data['description'],
            $data['headings'],
            $data['rows']
        )) {
            return $csv;
        }

        return view('fixed-asset-reports.show', [
            'reportKey' => $report,
            'data' => $data,
            'filters' => $filters,
            'categories' => FixedAssetCategory::forCompany($creatorId)->orderBy('code')->get(),
            'locations' => FixedAssetLocation::forCompany($creatorId)->orderBy('code')->get(),
            'custodians' => Employee::where('created_by', $creatorId)->orderBy('name')->get(),
            'branches' => Branch::where('created_by', $creatorId)->orderBy('name')->get(),
            'costCentres' => CostCentre::where('created_by', $creatorId)->orderBy('code')->get(),
            'exports' => $this->exports->recentExports($creatorId, 5),
            'downloadUrls' => $this->downloadUrls($creatorId),
        ]);
    }

    /** Explicit POST for a queued XLSX/PDF export. */
    public function requestExport(Request $request, string $report)
    {
        if (! $this->canViewReport($report)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! isset(FixedAssetReportService::REPORTS[$report])) {
            return redirect()->route('fixed-asset-reports.index')->with('error', __('Unknown report.'));
        }

        $format = (string) $request->input('format', 'xlsx');
        if (! in_array($format, ['xlsx', 'pdf'], true)) {
            return redirect()->back()->with('error', __('Exports are xlsx or pdf — csv downloads directly.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $filters = $this->reports->normalizeFilters($request->all());
        $export = $this->exports->requestExport($creatorId, $report, $format, $filters);

        return redirect()->route('fixed-asset-reports.index')
            ->with('success', __('Export queued — it will appear in Recent exports below when it is ready.') . ' #' . $export->id);
    }

    /**
     * Download a ready export. The route is signed (24 hours) AND
     * the token must match the row AND the row must belong to the
     * caller's company AND be ready AND unexpired — any one of
     * those failing is a 404, never a leak.
     */
    public function download(Request $request, int $exportId)
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $export = FixedAssetReportExport::where('created_by', $creatorId)->find($exportId);

        if (! $export
            || (string) $request->input('token') !== (string) $export->token
            || ! $export->isReady()
            || $export->isExpired()
            || $export->file_path === null
            || ! Storage::disk('local')->exists($export->file_path)
        ) {
            abort(404);
        }

        $filename = 'fixed-assets-' . $export->report . '-' . $export->created_at->format('Y-m-d') . '.' . $export->format;

        return Storage::disk('local')->download($export->file_path, $filename);
    }

    /** Signed download URLs for ready, unexpired exports (rebuilt per request — never stored). */
    protected function downloadUrls(int $creatorId): array
    {
        $urls = [];
        foreach ($this->exports->recentExports($creatorId) as $export) {
            if ($export->isReady() && ! $export->isExpired() && $export->file_path !== null) {
                $urls[$export->id] = $this->exports->downloadUrl($export);
            }
        }

        return $urls;
    }

    protected function canView(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->can('manage fixed assets');
    }

    /** The audit-trail report also opens to its own permission. */
    protected function canViewReport(string $report): bool
    {
        if ($this->canView()) {
            return true;
        }

        $user = Auth::user();

        return $report === FixedAssetReportService::REPORT_AUDIT_TRAIL
            && $user !== null
            && $user->can('view fixed asset audit trail');
    }
}
