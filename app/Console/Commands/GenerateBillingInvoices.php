<?php

namespace App\Console\Commands;

use App\Models\BillingContract;
use App\Services\BillingInvoiceService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Contract Management Phase 2 — daily recurring invoice run
 * (plan §6; spec default dispatch 08:00; AC02/AC03/AC07/AC08).
 *
 * Processes due occurrences company by company, one company at
 * a time, company-scoped via creatorId(). A failure for one
 * occurrence never blocks the others; the exit code stays 0
 * unless nothing could run at all, so the scheduler does not
 * spam failures for per-customer problems — the failures are
 * listed in the output instead.
 */
class GenerateBillingInvoices extends Command
{
    protected $signature = 'billing:generate-invoices
        {--company_id= : Only process one company (creator id)}
        {--contract_id= : Only process one contract}
        {--date= : Bill as of this date (Y-m-d, default today)}
        {--no-email : Issue invoices without sending emails}';

    protected $description = 'Issue invoices for due billing contract occurrences';

    public function handle(BillingInvoiceService $billing): int
    {
        $asOf = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : Carbon::today();
        $sendEmail = ! $this->option('no-email');
        $companyId = $this->option('company_id') ? (int) $this->option('company_id') : null;
        $contractId = $this->option('contract_id') ? (int) $this->option('contract_id') : null;

        $query = BillingContract::where('state', BillingContract::STATE_ACTIVE);
        if ($companyId !== null) {
            $query->where('created_by', $companyId);
        }
        if ($contractId !== null) {
            $query->where('id', $contractId);
        }

        $contracts = $query->orderBy('created_by')->orderBy('id')->get();

        if ($contracts->isEmpty()) {
            $this->info('No active billing contracts to process.');
            return self::SUCCESS;
        }

        $total = ['generated' => 0, 'already_invoiced' => 0, 'failed' => 0];

        foreach ($contracts as $contract) {
            $creatorId = (int) $contract->created_by;
            // The scheduler has no Auth user; log in as the company
            // user so shared helpers behave as on the web.
            $user = \App\Models\User::find($creatorId);
            if ($user) {
                Auth::login($user);
            }

            $results = $billing->generateDueForContract(
                $contract,
                (int) Auth::id(),
                $asOf,
                $sendEmail
            );

            // Delivery retries whose backoff has elapsed, same run.
            $retries = $billing->retryDueDeliveries($creatorId, (int) Auth::id());

            $total['generated'] += $results['generated'];
            $total['already_invoiced'] += $results['already_invoiced'];
            $total['failed'] += $results['failed'];

            $this->info(sprintf(
                'Contract %s (company %d): %d issued, %d already invoiced, %d failed, %d delivery retries (%d sent).',
                $contract->reference,
                $creatorId,
                $results['generated'],
                $results['already_invoiced'],
                $results['failed'],
                $retries['retried'],
                $retries['sent']
            ));

            foreach ($results['failures'] as $failure) {
                $this->error(sprintf(
                    '  - %s: %s',
                    $failure['period_key'],
                    $failure['error']
                ));
            }
        }

        $this->info(sprintf(
            'Done: %d issued, %d already invoiced, %d failed.',
            $total['generated'],
            $total['already_invoiced'],
            $total['failed']
        ));

        return self::SUCCESS;
    }
}
