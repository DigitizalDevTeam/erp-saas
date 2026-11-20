<?php

namespace App\Console\Commands;

use App\Models\CustomerCreditLedger;
use App\Services\CustomerCreditService;
use Illuminate\Console\Command;

/**
 * Credit Notes Phase 3 — materialise expired customer credit.
 *
 * Credit expires 14 days after it was added (client answer Q2).
 * Expiry is enforced lazily wherever credit is read or applied,
 * so this job is only the audit trail: it writes the `expired`
 * ledger rows and drops the customers' credit balances. Run daily
 * — see Kernel. If the scheduler never runs, nothing is overstated:
 * usableCredit() already excludes expired lots.
 */
class ExpireCustomerCredit extends Command
{
    protected $signature = 'credit:expire';

    protected $description = 'Expire customer credit lots past their 14 days and record the expiry in the ledger.';

    public function handle(CustomerCreditService $credit): int
    {
        $creatorIds = CustomerCreditLedger::where('type', CustomerCreditLedger::TYPE_ADDED)
            ->where('remaining', '>', 0)
            ->where('expires_at', '<=', now())
            ->distinct()
            ->pluck('created_by');

        $expired = 0;
        foreach ($creatorIds as $creatorId) {
            $expired += $credit->expireDueCredit((int) $creatorId);
        }

        $this->info('Expired ' . $expired . ' customer credit lot(s).');

        return self::SUCCESS;
    }
}
