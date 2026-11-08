<?php

namespace App\Console\Commands;

use App\Models\FixedAssetReportExport;
use App\Services\FixedAssetReportExportService;
use Illuminate\Console\Command;

/**
 * Fixed Assets Phase 6 — remove expired fixed-asset report
 * exports (rows + files). Downloads are time-limited (24 hours);
 * this keeps the export directory from growing forever. Run
 * daily — see Kernel.
 */
class PruneFixedAssetReportExports extends Command
{
    protected $signature = 'fixed-assets:prune-report-exports';

    protected $description = 'Delete expired fixed asset report exports (rows and files).';

    public function handle(FixedAssetReportExportService $exports): int
    {
        $expired = FixedAssetReportExport::where('expires_at', '<', now())->get();

        foreach ($expired as $export) {
            $exports->deleteFile($export);
            $export->delete();
        }

        $this->info('Pruned ' . $expired->count() . ' expired fixed asset report export(s).');

        return self::SUCCESS;
    }
}
