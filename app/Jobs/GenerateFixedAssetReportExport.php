<?php

namespace App\Jobs;

use App\Exports\CostCentreReportExport;
use App\Models\FixedAssetReportExport;
use App\Services\FixedAssetReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Fixed Assets Phase 6 — build one queued XLSX/PDF fixed-asset
 * report export.
 *
 * The rows are rebuilt from the stored filters at run time, so the
 * file always carries the same headings and rows as the report
 * screen — an export that disagrees with its screen is a
 * reconciliation break. The file lands under
 * storage/app/fixed-asset-exports/{creatorId}/{token}.{ext}; the row
 * is marked ready only after the file exists. A failure marks the
 * row failed with the message instead of leaving it queued forever.
 *
 * Tenant isolation (plan §19): the job receives only the export
 * row id. It re-loads the row and rebuilds the report for THAT
 * row's created_by — it never trusts a company id from the queued
 * payload, because there is none.
 */
class GenerateFixedAssetReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $exportId)
    {
    }

    public function handle(FixedAssetReportService $reports): void
    {
        $export = FixedAssetReportExport::find($this->exportId);
        if (! $export) {
            return;
        }

        try {
            $filters = is_array($export->filters) ? $export->filters : [];
            $data = $reports->report((int) $export->created_by, (string) $export->report, $filters);

            $path = 'fixed-asset-exports/' . $export->created_by . '/' . $export->token . '.' . $export->format;

            // Pdf::save() does not create parent directories (Excel::store
            // does), so the directory is created here for both formats.
            Storage::disk('local')->makeDirectory(dirname($path));

            if ($export->format === 'xlsx') {
                Excel::store(new CostCentreReportExport($data['headings'], $data['rows']), $path, 'local');
            } else {
                // Save through the local disk's own path: this app's
                // local disk root is storage/ (not storage/app), so
                // storage_path('app/...') would land somewhere the
                // download's exists() check never looks.
                Pdf::loadView('fixed-asset-reports.pdf', [
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'headings' => $data['headings'],
                    'rows' => $data['rows'],
                    'totals' => $data['totals'],
                ])->save(Storage::disk('local')->path($path));
            }

            $export->file_path = $path;
            $export->status = FixedAssetReportExport::STATUS_READY;
            $export->save();
        } catch (\Throwable $e) {
            $export->status = FixedAssetReportExport::STATUS_FAILED;
            $export->error_message = substr($e->getMessage(), 0, 500);
            $export->save();
        }
    }
}
