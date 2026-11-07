<?php

namespace App\Console\Commands;

use App\Models\BillingReportExport;
use App\Services\BillingReportExportService;
use Illuminate\Console\Command;

/**
 * Contract Management Phase 4 — remove expired billing report
 * exports (rows + files). Downloads are time-limited (24 hours);
 * this keeps the export directory from growing forever. Run
 * daily — see Kernel.
 */
class PruneBillingReportExports extends Command
{
    protected $signature = 'billing:prune-report-exports';

    protected $description = 'Delete expired billing report exports (rows and files).';

    public function handle(BillingReportExportService $exports): int
    {
        $expired = BillingReportExport::where('expires_at', '<', now())->get();

        foreach ($expired as $export) {
            $exports->deleteFile($export);
            $export->delete();
        }

        $this->info('Pruned ' . $expired->count() . ' expired billing report export(s).');

        return self::SUCCESS;
    }
}
