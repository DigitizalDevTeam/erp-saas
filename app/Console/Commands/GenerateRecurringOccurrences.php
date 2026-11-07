<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TreasuryAlertService;
use App\Services\TreasuryRecurringService;
use Illuminate\Console\Command;

/**
 * Treasury Phase 8 — scheduled job (plan §6, CA14).
 *
 * Generates recurring occurrences for every active rule, then
 * refreshes that company's alerts. Idempotent: a run twice
 * creates one occurrence per rule and period (unique
 * period_key), and never duplicates an alert.
 *
 * Automatic execution is disabled by default — an occurrence
 * never creates a payment by itself; it is confirmed or
 * marked paid by a user.
 */
class GenerateRecurringOccurrences extends Command
{
    protected $signature = 'treasury:recurring-generate {--company_id=}';
    protected $description = 'Generate recurring commitment occurrences and refresh cash-flow alerts';

    public function handle(): int
    {
        $companyId = $this->option('company_id');
        $recurring = new TreasuryRecurringService();
        $alerts = new TreasuryAlertService();

        $companies = User::query()
            ->where('type', 'company')
            ->when($companyId, fn ($q) => $q->where('id', (int) $companyId))
            ->orderBy('id')
            ->get();

        $totalGenerated = 0;
        $totalAlerts = 0;

        foreach ($companies as $company) {
            $result = $recurring->generateOccurrences((int) $company->id);
            $alertResult = $alerts->refreshAlerts((int) $company->id);

            $totalGenerated += $result['generated'];
            $totalAlerts += $alertResult['raised'];

            $this->info("Company {$company->id}: {$result['generated']} occurrence(s) generated, {$alertResult['raised']} alert(s) raised.");
        }

        $this->info("Done: {$totalGenerated} occurrence(s) generated, {$totalAlerts} alert(s) raised.");

        return self::SUCCESS;
    }
}
