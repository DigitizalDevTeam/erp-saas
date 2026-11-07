<?php

namespace App\Console\Commands;

use App\Services\BillingReminderService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * Contract Management Phase 3 — daily overdue-invoice reminders
 * (plan §6; AC12).
 *
 * Syncs reminder rows for every overdue contract invoice, then
 * sends the ones that are due under the per-company cadence
 * (first reminder 3 days after due, then every 7 days, max 3
 * — settings keys billing_reminder_first_days /
 * billing_reminder_interval_days / billing_reminder_max). A
 * failed reminder retries the SAME invoice and the SAME stored
 * PDF, never a new invoice. Reminders stop when the invoice is
 * settled. A failure for one invoice never blocks the others;
 * the exit code stays 0 unless nothing could run at all.
 */
class SendBillingReminders extends Command
{
    protected $signature = 'billing:send-reminders
        {--company_id= : Only process one company (creator id)}
        {--date= : Send as of this date (Y-m-d, default today)}';

    protected $description = 'Send overdue-invoice reminders for billing contracts';

    public function handle(BillingReminderService $reminders): int
    {
        $asOf = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : Carbon::today();
        $companyId = $this->option('company_id') ? (int) $this->option('company_id') : null;

        $query = \App\Models\BillingContract::whereIn('state', [
            \App\Models\BillingContract::STATE_ACTIVE,
            \App\Models\BillingContract::STATE_SUSPENDED,
            \App\Models\BillingContract::STATE_ENDED,
        ]);
        if ($companyId !== null) {
            $query->where('created_by', $companyId);
        }

        $creatorIds = $query->distinct()->pluck('created_by')->map(fn ($id) => (int) $id)->all();

        if ($creatorIds === []) {
            $this->info('No billing contracts to process.');
            return self::SUCCESS;
        }

        $total = ['sent' => 0, 'failed' => 0, 'stopped' => 0, 'created' => 0];

        foreach ($creatorIds as $creatorId) {
            // The scheduler has no Auth user; log in as the company
            // user so Auth-dependent helpers behave as on the web.
            $user = \App\Models\User::where('created_by', $creatorId)
                ->where('type', 'company')
                ->first()
                ?? \App\Models\User::find($creatorId);

            if ($user) {
                Auth::login($user);
            }

            $userId = $user ? (int) $user->id : $creatorId;

            try {
                $result = $reminders->sendDueReminders($creatorId, $userId, $asOf);
            } catch (\Throwable $e) {
                $this->error("Company {$creatorId}: " . $e->getMessage());
                continue;
            }

            $total['sent'] += $result['sent'];
            $total['failed'] += $result['failed'];
            $total['stopped'] += $result['stopped'];
            $total['created'] += $result['synced']['created'];

            $this->info("Company {$creatorId}: created {$result['synced']['created']}, sent {$result['sent']}, failed {$result['failed']}, stopped {$result['stopped']}.");
        }

        $this->info("Done: created {$total['created']}, sent {$total['sent']}, failed {$total['failed']}, stopped {$total['stopped']}.");

        return self::SUCCESS;
    }
}
