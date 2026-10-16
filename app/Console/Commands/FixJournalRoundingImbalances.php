<?php

namespace App\Console\Commands;

use App\Services\Accounting\JournalRoundingRepairService;
use App\Services\BalanceSheetReportService;
use App\Services\TrialBalanceReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixJournalRoundingImbalances extends Command
{
    protected $signature = 'accounting:fix-journal-rounding
                            {--company= : Repair only this company id}';

    protected $description = 'Deduplicate ledger lines and fix sub-unit debit/credit rounding on journals and other documents so books balance';

    public function handle(JournalRoundingRepairService $service): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;

        $this->info('Fixing ledger rounding / duplicate postings...');

        $result = $service->repair($companyId);

        $this->info("Duplicate lines removed: {$result['deduped']}");
        $this->info("Duplicate closing journals removed: {$result['duplicateJournalsRemoved']}");
        $this->info("Document groups adjusted: {$result['adjusted']}");

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        if ($companyId !== null) {
            $row = DB::table('transaction_lines')
                ->where('created_by', $companyId)
                ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
                ->first();
            $diff = round((float) $row->d - (float) $row->c, 2);
            $this->line("Company {$companyId} ledger debit−credit: {$diff}");

            $year = (int) date('Y');
            $range = TrialBalanceReportService::defaultDateRange($year);
            $bs = app(BalanceSheetReportService::class)->build(
                $companyId,
                $range['start'],
                $range['end'],
                null,
                $year,
                0,
                15
            );
            $this->line('Balance Sheet difference (current): '.$bs['totals']['difference']['current']);
            $this->line('Balance Sheet difference (previous): '.$bs['totals']['difference']['previous']);
        }

        return self::SUCCESS;
    }
}
