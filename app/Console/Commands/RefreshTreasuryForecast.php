<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TreasuryAlertService;
use App\Services\TreasuryForecastService;
use Illuminate\Console\Command;

/**
 * Treasury Phase 8 — scheduled job (plan §8).
 *
 * Refreshes the forecast for every company (system items are
 * regenerated from live data; the calendar windows them by
 * horizon at read time) and recomputes its cash-flow alerts
 * (insufficient funds, overdue / upcoming occurrences, pending
 * approvals, delayed transfers, overdue advances, sync
 * failures).
 */
class RefreshTreasuryForecast extends Command
{
    protected $signature = 'treasury:forecast-refresh {--company_id=}';
    protected $description = 'Refresh treasury cash-flow forecasts and recompute alerts';

    public function handle(): int
    {
        $companyId = $this->option('company_id');
        $forecast = new TreasuryForecastService();
        $alerts = new TreasuryAlertService();

        $companies = User::query()
            ->where('type', 'company')
            ->when($companyId, fn ($q) => $q->where('id', (int) $companyId))
            ->orderBy('id')
            ->get();

        foreach ($companies as $company) {
            $forecast->refreshForecast((int) $company->id);
            $alertResult = $alerts->refreshAlerts((int) $company->id);

            $this->info("Company {$company->id}: forecast refreshed, {$alertResult['raised']} alert(s) raised, {$alertResult['resolved']} resolved.");
        }

        $this->info('Done.');

        return self::SUCCESS;
    }
}
