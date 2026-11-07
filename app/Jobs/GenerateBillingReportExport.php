<?php

namespace App\Jobs;

use App\Exports\CostCentreReportExport;
use App\Models\BillingReportExport;
use App\Services\BillingReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Contract Management Phase 4 — build one queued XLSX/PDF billing
 * report export.
 *
 * The rows are rebuilt from the stored filters at run time, so the
 * file always carries the same headings and rows as the report
 * screen — an export that disagrees with its screen is a
 * reconciliation break. The file lands under
 * storage/app/billing-exports/{creatorId}/{token}.{ext}; the row
 * is marked ready only after the file exists. A failure marks the
 * row failed with the message instead of leaving it queued forever.
 *
 * Tenant isolation (plan §11 risk 6): the job receives only the
 * export row id. It re-loads the row and rebuilds the report for
 * THAT row's created_by — it never trusts a company id from the
 * queued payload, because there is none.
 */
class GenerateBillingReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $exportId)
    {
    }

    public function handle(BillingReportService $reports): void
    {
        $export = BillingReportExport::find($this->exportId);
        if (! $export) {
            return;
        }

        try {
            $filters = is_array($export->filters) ? $export->filters : [];
            $data = $reports->report((int) $export->created_by, (string) $export->report, $filters);

            $path = 'billing-exports/' . $export->created_by . '/' . $export->token . '.' . $export->format;

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
                Pdf::loadView('billing-reports.pdf', [
                    'title' => $data['title'],
                    'description' => $data['description'],
                    'headings' => $data['headings'],
                    'rows' => $data['rows'],
                    'totals' => $data['totals'],
                ])->save(Storage::disk('local')->path($path));
            }

            $export->file_path = $path;
            $export->status = BillingReportExport::STATUS_READY;
            $export->save();
        } catch (\Throwable $e) {
            $export->status = BillingReportExport::STATUS_FAILED;
            $export->error_message = substr($e->getMessage(), 0, 500);
            $export->save();
        }
    }
}
