<?php

namespace App\Http\Controllers;

use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryReportExport;
use App\Services\TreasuryReportExportService;
use App\Services\TreasuryReportService;
use App\Services\TreasurySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Treasury Phase 9 — reports (plan §9).
 *
 * One controller for all eight reports so the screen, the CSV, the
 * XLSX and the PDF can never disagree: they all read the same
 * TreasuryReportService output. CSV streams synchronously; XLSX
 * and PDF are queued (GenerateTreasuryReportExport) and downloaded
 * through a signed, 24-hour link. Every action is company-scoped
 * (CA16) and gated by 'view treasury reports'.
 */
class TreasuryReportController extends Controller
{
    public function __construct(
        protected TreasuryReportService $reports = new TreasuryReportService(),
        protected TreasuryReportExportService $exports = new TreasuryReportExportService(),
    ) {
    }

    /** Report hub: the eight reports + recent exports. */
    public function index()
    {
        if (! Auth::user()->can('view treasury reports')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();

        return view('treasury.reports.index', [
            'reports' => TreasuryReportService::REPORTS,
            'exports' => $this->exports->recentExports($creatorId),
            'downloadUrls' => $this->downloadUrls($creatorId),
        ]);
    }

    /** One report: filters + table + CSV export + queued XLSX/PDF request. */
    public function show(Request $request, string $report)
    {
        if (! Auth::user()->can('view treasury reports')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! isset(TreasuryReportService::REPORTS[$report])) {
            return redirect()->route('treasury-reports.index')->with('error', __('Unknown report.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $filters = $this->reports->normalizeFilters($request->all());
        $data = $this->reports->report($creatorId, $report, $filters);

        // XLSX / PDF are queued — the file appears in the exports list.
        if (in_array($request->input('export'), ['xlsx', 'pdf'], true)) {
            $export = $this->exports->requestExport($creatorId, $report, (string) $request->input('export'), $filters);

            return redirect()->route('treasury-reports.index')
                ->with('success', __('Export queued — it will appear in Recent exports below when it is ready.') . ' #' . $export->id);
        }

        // CSV streams synchronously with the same headings and rows.
        if ($csv = $this->exports->csvResponse(
            $request,
            'treasury-' . $report,
            $data['title'],
            $data['description'],
            $data['headings'],
            $data['rows'],
            [$data['title'] . ' — ' . TreasurySettings::baseCurrency($creatorId)]
        )) {
            return $csv;
        }

        $accounts = TreasuryFinancialAccount::where('created_by', $creatorId)
            ->orderBy('code')
            ->get();

        return view('treasury.reports.show', [
            'reportKey' => $report,
            'data' => $data,
            'filters' => $filters,
            'accounts' => $accounts,
            'baseCurrency' => TreasurySettings::baseCurrency($creatorId),
            'exports' => $this->exports->recentExports($creatorId, 5),
            'downloadUrls' => $this->downloadUrls($creatorId),
        ]);
    }

    /** Explicit POST for a queued XLSX/PDF export. */
    public function requestExport(Request $request, string $report)
    {
        if (! Auth::user()->can('view treasury reports')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! isset(TreasuryReportService::REPORTS[$report])) {
            return redirect()->route('treasury-reports.index')->with('error', __('Unknown report.'));
        }

        $format = (string) $request->input('format', 'xlsx');
        if (! in_array($format, ['xlsx', 'pdf'], true)) {
            return redirect()->back()->with('error', __('Exports are xlsx or pdf — csv downloads directly.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $filters = $this->reports->normalizeFilters($request->all());
        $export = $this->exports->requestExport($creatorId, $report, $format, $filters);

        return redirect()->route('treasury-reports.index')
            ->with('success', __('Export queued — it will appear in Recent exports below when it is ready.') . ' #' . $export->id);
    }

    /**
     * Download a ready export. The route is signed (24 hours) AND
     * the token must match the row AND the row must belong to the
     * caller's company AND be ready AND unexpired — any one of
     * those failing is a 404, never a leak (CA16).
     */
    public function download(Request $request, int $exportId)
    {
        if (! Auth::user()->can('view treasury reports')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $export = TreasuryReportExport::where('created_by', $creatorId)->find($exportId);

        if (! $export
            || (string) $request->input('token') !== (string) $export->token
            || ! $export->isReady()
            || $export->isExpired()
            || $export->file_path === null
            || ! Storage::disk('local')->exists($export->file_path)
        ) {
            abort(404);
        }

        $filename = 'treasury-' . $export->report . '-' . $export->created_at->format('Y-m-d') . '.' . $export->format;

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
}
