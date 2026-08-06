<?php

namespace App\Console\Commands;

use App\Services\Accounting\BankAccountOpeningBalanceRepairService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairBankAccountOpeningBalances extends Command
{
    protected $signature = 'accounting:repair-bank-openings
                            {--company= : Repair only this company id}';

    protected $description = 'Post missing equity counterparts for one-sided Bank Account opening balances';

    public function handle(BankAccountOpeningBalanceRepairService $service): int
    {
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;

        $this->info('Repairing one-sided bank account opening balances...');

        $result = $service->repair($companyId);

        $this->info("Repaired: {$result['repaired']}");
        $this->info("Skipped: {$result['skipped']}");

        foreach ($result['warnings'] as $warning) {
            $this->warn($warning);
        }

        if ($companyId !== null) {
            $row = DB::table('transaction_lines')
                ->where('created_by', $companyId)
                ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
                ->first();
            $diff = round((float) $row->d - (float) $row->c, 2);
            $this->line("Company {$companyId} ledger debit−credit after repair: {$diff}");
        }

        return self::SUCCESS;
    }
}
