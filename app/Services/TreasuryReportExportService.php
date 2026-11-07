<?php

namespace App\Services;

use App\Models\TreasuryReportExport;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Treasury Phase 9 — report exports (plan §9).
 *
 * CSV is streamed synchronously (small, like the Cost Centre
 * exports). XLSX and PDF are queued: the request creates a
 * treasury_report_exports row and dispatches
 * GenerateTreasuryReportExport; the download link is a signed URL
 * that expires after 24 hours, and the download itself re-checks
 * the company, the token, the status and the expiry — a token
 * alone never grants access (CA16).
 *
 * QUEUE_CONNECTION is sync by default in this app, so with no
 * worker running the job executes inline and the file is ready
 * immediately; with a worker it runs in the background. Either
 * way the row is the source of truth for the download.
 */
class TreasuryReportExportService
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

        if (! Auth::user()->can('view treasury reports')) {
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
    public function requestExport(int $creatorId, string $report, string $format, array $filters): TreasuryReportExport
    {
        if (! isset(TreasuryReportService::REPORTS[$report])) {
            throw new \InvalidArgumentException('Unknown Treasury report: ' . $report);
        }
        if (! in_array($format, ['xlsx', 'pdf'], true)) {
            throw new \InvalidArgumentException('Queued exports are xlsx or pdf — csv streams directly.');
        }

        $export = TreasuryReportExport::create([
            'created_by' => $creatorId,
            'report' => $report,
            'format' => $format,
            'filters' => $filters,
            'token' => Str::random(40),
            'status' => TreasuryReportExport::STATUS_QUEUED,
            'expires_at' => Carbon::now()->addHours(TreasuryReportExport::EXPIRY_HOURS),
        ]);

        \App\Jobs\GenerateTreasuryReportExport::dispatch($export->id);

        return $export->fresh();
    }

    /** Signed, time-limited download URL for a ready export. */
    public function downloadUrl(TreasuryReportExport $export): string
    {
        return URL::temporarySignedRoute(
            'treasury-reports.export-download',
            Carbon::now()->addHours(TreasuryReportExport::EXPIRY_HOURS),
            ['export' => $export->id, 'token' => $export->token]
        );
    }

    /** Recent exports for the company, newest first. */
    public function recentExports(int $creatorId, int $limit = 10)
    {
        return TreasuryReportExport::where('created_by', $creatorId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /** Delete one export's file (the row is deleted by the caller). */
    public function deleteFile(TreasuryReportExport $export): void
    {
        if ($export->file_path !== null && Storage::disk('local')->exists($export->file_path)) {
            Storage::disk('local')->delete($export->file_path);
        }
    }
}
