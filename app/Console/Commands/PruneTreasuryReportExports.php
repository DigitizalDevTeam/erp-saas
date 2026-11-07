<?php

namespace App\Console\Commands;

use App\Models\TreasuryReportExport;
use App\Services\TreasuryReportExportService;
use Illuminate\Console\Command;

/**
 * Treasury Phase 9 — remove expired report exports (rows + files).
 * Downloads are time-limited (24 hours); this keeps the export
 * directory from growing forever. Run daily — see Kernel.
 */
class PruneTreasuryReportExports extends Command
{
    protected $signature = 'treasury:prune-report-exports';

    protected $description = 'Delete expired Treasury report exports (rows and files).';

    public function handle(TreasuryReportExportService $exports): int
    {
        $expired = TreasuryReportExport::where('expires_at', '<', now())->get();

        foreach ($expired as $export) {
            $exports->deleteFile($export);
            $export->delete();
        }

        $this->info('Pruned ' . $expired->count() . ' expired Treasury report export(s).');

        return self::SUCCESS;
    }
}
