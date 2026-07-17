<?php

namespace App\Console\Commands;

use App\Services\Accounting\ChartOfAccountCsvImportService;
use Illuminate\Console\Command;

class SyncChartOfAccountsFromCsv extends Command
{
    protected $signature = 'chart:sync-csv
                            {--company= : Sync only this company id}
                            {--only-missing : Only import companies with no chart (skip repair)}';

    protected $description = 'Import or repair chart of accounts from chart_new_data.csv for all companies';

    public function handle(ChartOfAccountCsvImportService $service): int
    {
        if (! is_file(public_path('chart_new_data.csv'))) {
            $this->error('Missing public/chart_new_data.csv');

            return self::FAILURE;
        }

        $companyId = $this->option('company') ? (int) $this->option('company') : null;
        $onlyMissing = (bool) $this->option('only-missing');

        $missing = [];
        foreach ($service->companyIds($companyId) as $id) {
            if (! $service->companyHasChart($id)) {
                $missing[] = $id;
            }
        }

        if ($missing !== []) {
            $this->warn('Companies without chart (code 11 missing): ' . implode(', ', $missing));
        }

        $this->info('Syncing chart of accounts from CSV...');

        $summary = $service->syncAll($companyId, $onlyMissing);

        foreach ($summary['companies'] as $row) {
            $line = "{$row['company_name']} (#{$row['company_id']}): {$row['status']}";
            if ($row['status'] === 'imported') {
                $line .= ' (' . ($row['imported'] ?? 0) . ' rows)';
            }
            if ($row['status'] === 'error') {
                $line .= ' — ' . ($row['error'] ?? 'unknown error');
            }
            $this->line($line);
        }

        $totals = $summary['totals'];
        $this->newLine();
        $this->info("Imported: {$totals['imported']} | Repaired: {$totals['repaired']} | Skipped: {$totals['skipped']} | Errors: {$totals['errors']}");

        return ($totals['errors'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
