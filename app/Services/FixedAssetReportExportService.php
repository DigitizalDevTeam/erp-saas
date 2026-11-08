<?php

namespace App\Services;

use App\Models\FixedAssetReportExport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fixed Assets Phase 6 — fixed-asset report exports (plan §9).
 *
 * Mirrors BillingReportExportService (Contract Phase 4): CSV
 * streams synchronously; XLSX and PDF are queued
 * (GenerateFixedAssetReportExport) and downloaded through a
 * signed, 24-hour link. The download re-checks the company, the
 * token, the status and the expiry — a token alone never grants
 * access.
 *
 * QUEUE_CONNECTION is sync by default in this app, so with no
 * worker running the job executes inline and the file is ready
 * immediately; with a worker it runs in the background. Either
 * way the row is the source of truth for the download.
 *
 * The fixed_asset_report_exports table only exists after the
 * Phase 6 migration: recentExports() returns an empty collection
 * before it, so the reports hub degrades instead of 500ing in the
 * window between pull and migrate (Phase 3 hasTable precedent).
 */
class FixedAssetReportExportService
{
    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    /**
     * Returns a streamed CSV download when ?export=csv is present,
     * or null when no export was requested (the caller renders HTML).
     * XLSX/PDF are never streamed here — they go through the queue.
     */
    public function csvResponse(
        Request $request,
        string $filename,
        string $title,
        string $description,
        array $headings,
        callable|array $rows,
        ?array $metaLine = null
    ): mixed {
        if ($request->input('export') !== 'csv') {
            return null;
        }

        if (! $this->canViewReports()) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $data = is_callable($rows) ? $rows() : $rows;
        $data = array_map(fn ($line) => array_values((array) $line), $data);
        $stamp = date('Y-m-d');

        return new StreamedResponse(function () use ($headings, $data, $title, $description, $metaLine) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $metaLine ?? [$title]);
            if ($description !== '') {
                fputcsv($out, [$description]);
            }
            fputcsv($out, $headings);
            foreach ($data as $line) {
                fputcsv($out, $line);
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '-' . $stamp . '.csv"',
        ]);
    }

    /** Queue an XLSX/PDF export and return the tracking row. */
    public function requestExport(int $creatorId, string $report, string $format, array $filters): FixedAssetReportExport
    {
        if (! isset(FixedAssetReportService::REPORTS[$report])) {
            throw new \InvalidArgumentException('Unknown fixed asset report: ' . $report);
        }
        if (! in_array($format, ['xlsx', 'pdf'], true)) {
            throw new \InvalidArgumentException('Queued exports are xlsx or pdf — csv streams directly.');
        }

        $export = FixedAssetReportExport::create([
            'created_by' => $creatorId,
            'report' => $report,
            'format' => $format,
            'filters' => $filters,
            'token' => Str::random(40),
            'status' => FixedAssetReportExport::STATUS_QUEUED,
            'expires_at' => Carbon::now()->addHours(FixedAssetReportExport::EXPIRY_HOURS),
        ]);

        \App\Jobs\GenerateFixedAssetReportExport::dispatch($export->id);

        return $export->fresh();
    }

    /** Signed, time-limited download URL for a ready export. */
    public function downloadUrl(FixedAssetReportExport $export): string
    {
        return URL::temporarySignedRoute(
            'fixed-asset-reports.export-download',
            Carbon::now()->addHours(FixedAssetReportExport::EXPIRY_HOURS),
            ['export' => $export->id, 'token' => $export->token]
        );
    }

    /** Recent exports for the company, newest first (empty before the Phase 6 migration). */
    public function recentExports(int $creatorId, int $limit = 10)
    {
        if (! Schema::hasTable('fixed_asset_report_exports')) {
            return collect();
        }

        return FixedAssetReportExport::where('created_by', $creatorId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function deleteFile(FixedAssetReportExport $export): void
    {
        if ($export->file_path !== null && Storage::disk('local')->exists($export->file_path)) {
            Storage::disk('local')->delete($export->file_path);
        }
    }

    protected function canViewReports(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->can('manage fixed assets');
    }
}
