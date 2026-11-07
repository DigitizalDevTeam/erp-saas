<?php

namespace App\Http\Controllers;

use App\Models\BillingContract;
use App\Models\BillingReportExport;
use App\Models\Customer;
use App\Services\BillingReportExportService;
use App\Services\BillingReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Contract Management Phase 4 — billing dashboard + reports
 * (plan §9, §10).
 *
 * One controller for the dashboard and all nine reports so the
 * screen, the CSV, the XLSX and the PDF can never disagree: they
 * all read the same BillingReportService output. CSV streams
 * synchronously; XLSX and PDF are queued
 * (GenerateBillingReportExport) and downloaded through a signed,
 * 24-hour link. Every action is company-scoped (AC11) and gated
 * by 'manage billing contracts' or 'approve billing contracts'
 * — the same view gate as the contract master. No new permission:
 * the report permission is the view permission, as in Treasury
 * Phase 9.
 */
class BillingReportController extends Controller
{
    public function __construct(
        protected BillingReportService $reports = new BillingReportService(),
        protected BillingReportExportService $exports = new BillingReportExportService(),
    ) {
    }

    /** Dashboard: KPIs + expiring contracts + failed deliveries. */
    public function dashboard(Request $request)
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $days = $this->reports->normalizeFilters($request->all())['days'];
        $data = $this->reports->dashboard($creatorId, $days);

        return view('billing-reports.dashboard', [
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

        return view('billing-reports.index', [
            'reports' => BillingReportService::REPORTS,
            'exports' => $this->exports->recentExports($creatorId),
            'downloadUrls' => $this->downloadUrls($creatorId),
        ]);
    }

    /** One report: filters + table + CSV export + queued XLSX/PDF request. */
    public function show(Request $request, string $report)
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! isset(BillingReportService::REPORTS[$report])) {
            return redirect()->route('billing-reports.index')->with('error', __('Unknown report.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $filters = $this->reports->normalizeFilters($request->all());
        $data = $this->reports->report($creatorId, $report, $filters);

        // XLSX / PDF are queued — the file appears in the exports list.
        if (in_array($request->input('export'), ['xlsx', 'pdf'], true)) {
            $export = $this->exports->requestExport($creatorId, $report, (string) $request->input('export'), $filters);

            return redirect()->route('billing-reports.index')
                ->with('success', __('Export queued — it will appear in Recent exports below when it is ready.') . ' #' . $export->id);
        }

        // CSV streams synchronously with the same headings and rows.
        if ($csv = $this->exports->csvResponse(
            $request,
            'billing-' . $report,
            $data['title'],
            $data['description'],
            $data['headings'],
            $data['rows']
        )) {
            return $csv;
        }

        $customers = Customer::where('created_by', $creatorId)->orderBy('name')->get();
        $contracts = BillingContract::where('created_by', $creatorId)->orderBy('reference')->get();

        return view('billing-reports.show', [
            'reportKey' => $report,
            'data' => $data,
            'filters' => $filters,
            'customers' => $customers,
            'contracts' => $contracts,
            'exports' => $this->exports->recentExports($creatorId, 5),
            'downloadUrls' => $this->downloadUrls($creatorId),
        ]);
    }

    /** Explicit POST for a queued XLSX/PDF export. */
    public function requestExport(Request $request, string $report)
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! isset(BillingReportService::REPORTS[$report])) {
            return redirect()->route('billing-reports.index')->with('error', __('Unknown report.'));
        }

        $format = (string) $request->input('format', 'xlsx');
        if (! in_array($format, ['xlsx', 'pdf'], true)) {
            return redirect()->back()->with('error', __('Exports are xlsx or pdf — csv downloads directly.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $filters = $this->reports->normalizeFilters($request->all());
        $export = $this->exports->requestExport($creatorId, $report, $format, $filters);

        return redirect()->route('billing-reports.index')
            ->with('success', __('Export queued — it will appear in Recent exports below when it is ready.') . ' #' . $export->id);
    }

    /**
     * Download a ready export. The route is signed (24 hours) AND
     * the token must match the row AND the row must belong to the
     * caller's company AND be ready AND unexpired — any one of
     * those failing is a 404, never a leak (AC11).
     */
    public function download(Request $request, int $exportId)
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $export = BillingReportExport::where('created_by', $creatorId)->find($exportId);

        if (! $export
            || (string) $request->input('token') !== (string) $export->token
            || ! $export->isReady()
            || $export->isExpired()
            || $export->file_path === null
            || ! Storage::disk('local')->exists($export->file_path)
        ) {
            abort(404);
        }

        $filename = 'billing-' . $export->report . '-' . $export->created_at->format('Y-m-d') . '.' . $export->format;

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

        return $user->can('manage billing contracts') || $user->can('approve billing contracts');
    }
}
