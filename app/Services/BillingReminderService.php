<?php

namespace App\Services;

use App\Exceptions\BillingContractException;
use App\Mail\CustomerInvoiceSend;
use App\Models\BillingContract;
use App\Models\BillingContractAuditEvent;
use App\Models\BillingContractOccurrence;
use App\Models\BillingReminder;
use App\Models\Customer;
use App\Models\Invoice;
use App\Support\LoggedMail;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Contract Management Phase 3 — overdue-invoice reminders
 * (plan §4, §6; AC12).
 *
 * One reminder row per overdue contract invoice. Cadence is per
 * company (settings keys billing_reminder_first_days /
 * billing_reminder_interval_days / billing_reminder_max,
 * defaults 3 / 7 / 3): the first reminder goes out first_days
 * after the due date, then every interval_days, at most max
 * reminders. A reminder is an email through the same
 * CustomerInvoiceSend mailable as the invoice itself, with the
 * SAME stored PDF (AC08 pattern) — a failed reminder retries
 * the same invoice/PDF, never a new invoice. Send attempts for
 * one reminder use the same backoff ladder as the invoice
 * delivery attempts (5 min → 30 min → 2 h, 4 max). Reminders
 * stop when the invoice is settled or cancelled, or when the
 * max is reached. Every send rechecks getDue() first, so a
 * payment that lands between scheduling and sending stops the
 * reminder (AC12).
 *
 * Money is bcmath only, never float. All queries are scoped by
 * creatorId() (AC11).
 */
class BillingReminderService
{
    public const KEY_FIRST_DAYS = 'billing_reminder_first_days';
    public const KEY_INTERVAL_DAYS = 'billing_reminder_interval_days';
    public const KEY_MAX = 'billing_reminder_max';

    public const DEFAULT_FIRST_DAYS = 3;
    public const DEFAULT_INTERVAL_DAYS = 7;
    public const DEFAULT_MAX = 3;

    public function __construct(
        protected BillingPaymentService $payments,
        protected BillingInvoiceService $billing,
    ) {
    }

    // ------------------------------------------------------------------
    // Settings (per company, with defaults)
    // ------------------------------------------------------------------

    /** [first_days, interval_days, max] for a company. */
    public function reminderSettings(int $creatorId): array
    {
        $rows = DB::table('settings')
            ->where('created_by', $creatorId)
            ->whereIn('name', [self::KEY_FIRST_DAYS, self::KEY_INTERVAL_DAYS, self::KEY_MAX])
            ->pluck('value', 'name');

        $first = (int) ($rows[self::KEY_FIRST_DAYS] ?? self::DEFAULT_FIRST_DAYS);
        $interval = (int) ($rows[self::KEY_INTERVAL_DAYS] ?? self::DEFAULT_INTERVAL_DAYS);
        $max = (int) ($rows[self::KEY_MAX] ?? self::DEFAULT_MAX);

        return [
            'first_days' => $first > 0 ? $first : self::DEFAULT_FIRST_DAYS,
            'interval_days' => $interval > 0 ? $interval : self::DEFAULT_INTERVAL_DAYS,
            'max' => $max > 0 ? $max : self::DEFAULT_MAX,
        ];
    }

    // ------------------------------------------------------------------
    // Scheduling
    // ------------------------------------------------------------------

    /**
     * Make sure every overdue contract invoice of the company has
     * a reminder row, and stop the rows whose invoice is settled
     * or cancelled. Returns [created, stopped].
     */
    public function syncReminders(int $creatorId, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $settings = $this->reminderSettings($creatorId);
        $created = 0;
        $stopped = 0;

        foreach ($this->payments->overdueInvoices($creatorId, $asOf) as $row) {
            $invoice = $row['invoice'];
            $occurrence = $row['occurrence'];

            $reminder = BillingReminder::firstOrCreate(
                ['invoice_id' => $invoice->id],
                [
                    'created_by' => $creatorId,
                    'contract_id' => $occurrence->contract_id,
                    'occurrence_id' => $occurrence->id,
                    'status' => BillingReminder::STATUS_SCHEDULED,
                    'sent_count' => 0,
                    'attempt_count' => 0,
                    'next_reminder_at' => Carbon::parse($invoice->due_date)->addDays($settings['first_days'])->format('Y-m-d'),
                ]
            );

            if ($reminder->wasRecentlyCreated) {
                $created++;
            }
        }

        // Stop the rows whose invoice is settled or cancelled.
        $open = BillingReminder::where('created_by', $creatorId)
            ->where('status', '!=', BillingReminder::STATUS_STOPPED)
            ->with(['invoice.items', 'invoice.payments', 'invoice.creditNote', 'invoice.salesDebitNote'])
            ->get();

        foreach ($open as $reminder) {
            $invoice = $reminder->invoice;
            if (! $invoice) {
                $reminder->update(['status' => BillingReminder::STATUS_STOPPED]);
                $stopped++;
                continue;
            }

            if ((int) $invoice->status === Invoice::STATUS_CANCELLED
                || bccomp($this->decimalString($invoice->getDue(), 'Invoice due'), '0.0000', 4) <= 0
            ) {
                $reminder->update(['status' => BillingReminder::STATUS_STOPPED]);
                $stopped++;
            }
        }

        return ['created' => $created, 'stopped' => $stopped];
    }

    /** Reminder rows that are due to send as of $asOf. */
    public function dueReminders(int $creatorId, ?Carbon $asOf = null)
    {
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $now = now();

        return BillingReminder::where('created_by', $creatorId)
            ->where('status', '!=', BillingReminder::STATUS_STOPPED)
            ->where(function ($q) use ($asOf, $now) {
                $q->where(function ($q) use ($asOf) {
                    $q->whereIn('status', [BillingReminder::STATUS_SCHEDULED, BillingReminder::STATUS_SENT])
                        ->whereNotNull('next_reminder_at')
                        ->whereDate('next_reminder_at', '<=', $asOf->format('Y-m-d'));
                })->orWhere(function ($q) use ($now) {
                    $q->where('status', BillingReminder::STATUS_FAILED)
                        ->whereNotNull('next_retry_at')
                        ->where('next_retry_at', '<=', $now);
                });
            })
            ->with(['contract', 'occurrence', 'invoice'])
            ->orderBy('next_reminder_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Send every due reminder for a company. A failure for one
     * invoice never blocks the others.
     *
     * @return array{synced: array, sent: int, failed: int, stopped: int}
     */
    public function sendDueReminders(int $creatorId, int $userId, ?Carbon $asOf = null): array
    {
        $synced = $this->syncReminders($creatorId, $asOf);
        $sent = 0;
        $failed = 0;
        $stopped = 0;

        foreach ($this->dueReminders($creatorId, $asOf) as $reminder) {
            $result = $this->sendReminder($reminder, $userId);

            if ($result['status'] === 'sent') {
                $sent++;
            } elseif ($result['status'] === 'stopped') {
                $stopped++;
            } elseif (in_array($result['status'], ['failed', 'waiting_retry', 'waiting'], true)) {
                $failed++;
            }
        }

        return ['synced' => $synced, 'sent' => $sent, 'failed' => $failed, 'stopped' => $stopped];
    }

    /** Manual send of a reminder now — counts as the next reminder in the cadence. */
    public function sendReminderNow(BillingReminder $reminder, int $userId): array
    {
        return $this->sendReminder($reminder, $userId, true);
    }

    // ------------------------------------------------------------------
    // Sending
    // ------------------------------------------------------------------

    /**
     * Send (or retry) the reminder for one overdue invoice. Always
     * the SAME invoice and the SAME stored PDF — a retry never
     * creates a new invoice or a new number. $manual ignores
     * next_reminder_at / next_retry_at (an operator explicitly
     * sending); scheduled sends only run when the cadence or the
     * backoff has elapsed.
     *
     * @return array{status: string, reminder: BillingReminder}
     */
    public function sendReminder(BillingReminder $reminder, int $userId, bool $manual = false): array
    {
        $reminder = $reminder->fresh();
        if (! $reminder) {
            throw new BillingContractException('Reminder not found.');
        }

        $contract = BillingContract::find($reminder->contract_id);
        $occurrence = BillingContractOccurrence::find($reminder->occurrence_id);
        $invoice = Invoice::with(['items', 'payments', 'creditNote', 'salesDebitNote'])->find($reminder->invoice_id);

        if (! $contract || ! $occurrence || ! $invoice) {
            $reminder->update(['status' => BillingReminder::STATUS_STOPPED]);
            return ['status' => 'stopped', 'reminder' => $reminder->fresh()];
        }

        if ((int) $contract->created_by !== (int) $reminder->created_by) {
            throw new BillingContractException('Reminder does not belong to this company.');
        }

        // Recheck the balance first: a payment that landed after
        // scheduling stops the reminder (AC12).
        if ((int) $invoice->status === Invoice::STATUS_CANCELLED
            || bccomp($this->decimalString($invoice->getDue(), 'Invoice due'), '0.0000', 4) <= 0
        ) {
            $reminder->update(['status' => BillingReminder::STATUS_STOPPED]);
            return ['status' => 'stopped', 'reminder' => $reminder->fresh()];
        }

        $settings = $this->reminderSettings((int) $reminder->created_by);

        if ($reminder->sent_count >= $settings['max']) {
            $reminder->update(['status' => BillingReminder::STATUS_STOPPED]);
            return ['status' => 'stopped', 'reminder' => $reminder->fresh()];
        }

        if (! $manual) {
            if ($reminder->status === BillingReminder::STATUS_FAILED) {
                // Retries are exhausted when no retry is scheduled:
                // only an explicit manual resend may try again.
                if ($reminder->next_retry_at === null) {
                    return ['status' => 'failed', 'reminder' => $reminder];
                }
                if ($reminder->next_retry_at->isFuture()) {
                    return ['status' => 'waiting_retry', 'reminder' => $reminder];
                }
            }

            if (in_array($reminder->status, [BillingReminder::STATUS_SCHEDULED, BillingReminder::STATUS_SENT], true)
                && $reminder->next_reminder_at !== null
                && $reminder->next_reminder_at->isFuture()
            ) {
                return ['status' => 'waiting', 'reminder' => $reminder];
            }
        }

        $customer = $contract->customer ?? Customer::find($contract->customer_id);
        $to = trim((string) ($contract->delivery_to ?? ''));
        if ($to === '') {
            $to = $customer ? (string) ($customer->operationalEmail() ?? '') : '';
        }
        $cc = $this->splitEmails($contract->delivery_cc);

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $reminder->update([
                'status' => BillingReminder::STATUS_FAILED,
                'attempt_count' => $reminder->attempt_count + 1,
                'next_retry_at' => null,
                'last_error' => 'No recipient email address on the contract or the customer.',
            ]);
            $this->logEvent($contract, 'reminder_failed', $userId,
                'No recipient email address.', null,
                ['invoice_id' => $invoice->id], (int) $occurrence->id);

            return ['status' => 'failed', 'reminder' => $reminder->fresh()];
        }

        $invoice->name = $customer ? $customer->name : '';
        $invoice->invoice = $this->invoiceNumberFormat((int) $contract->created_by, (int) $invoice->invoice_id);
        $invoice->url = route('invoice.pdf', Crypt::encrypt($invoice->id));

        $pdf = $this->billing->storedPdfBytes($invoice);

        try {
            LoggedMail::send(
                $to,
                new CustomerInvoiceSend(
                    $invoice,
                    $pdf,
                    'invoice-' . $invoice->invoice_id . '.pdf',
                    $cc,
                    true
                ),
                [
                    'source' => 'billing_contract_reminder',
                    'subject' => __('Payment reminder') . ' — ' . __('Invoice') . ' ' . $invoice->invoice,
                    'created_by' => (int) $contract->created_by,
                    'user_id' => $userId,
                ]
            );

            $sentCount = $reminder->sent_count + 1;
            $data = [
                'status' => $sentCount >= $settings['max']
                    ? BillingReminder::STATUS_STOPPED
                    : BillingReminder::STATUS_SENT,
                'sent_count' => $sentCount,
                'attempt_count' => 0,
                'recipients_to' => $to,
                'recipients_cc' => $cc !== [] ? implode(', ', $cc) : null,
                'next_retry_at' => null,
                'last_error' => null,
                'last_sent_at' => now(),
            ];
            if ($sentCount < $settings['max']) {
                $data['next_reminder_at'] = Carbon::parse($invoice->due_date)
                    ->addDays($settings['first_days'] + $sentCount * $settings['interval_days'])
                    ->format('Y-m-d');
            } else {
                $data['next_reminder_at'] = null;
            }

            $reminder->update($data);
            $this->logEvent($contract, 'reminder_sent', $userId, null, null,
                ['invoice_id' => $invoice->id, 'to' => $to, 'reminder' => $sentCount], (int) $occurrence->id);

            return ['status' => 'sent', 'reminder' => $reminder->fresh()];
        } catch (\Throwable $e) {
            $count = $reminder->attempt_count + 1;
            $next = $count < BillingInvoiceService::MAX_DELIVERY_ATTEMPTS
                ? now()->addMinutes(BillingInvoiceService::RETRY_DELAYS_MINUTES[$count - 1])
                : null;

            $reminder->update([
                'status' => BillingReminder::STATUS_FAILED,
                'attempt_count' => $count,
                'recipients_to' => $to,
                'recipients_cc' => $cc !== [] ? implode(', ', $cc) : null,
                'next_retry_at' => $next,
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
            ]);
            $this->logEvent($contract, 'reminder_failed', $userId,
                $e->getMessage(), null,
                ['invoice_id' => $invoice->id, 'attempt' => $count], (int) $occurrence->id);

            return ['status' => 'failed', 'reminder' => $reminder->fresh()];
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function invoiceNumberFormat(int $creatorId, int $number): string
    {
        $settings = \App\Models\Utility::settingsById($creatorId);

        return ($settings['invoice_prefix'] ?? '#Fatura') . sprintf('%05d', $number);
    }

    /** @return string[] */
    protected function splitEmails(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($email) => filter_var(trim($email), FILTER_VALIDATE_EMAIL) ? trim($email) : null,
            preg_split('/[,\s;]+/', $value) ?: []
        )));
    }

    protected function decimalString(mixed $value, string $label): string
    {
        if (! is_numeric($value)) {
            throw new BillingContractException($label . ' must be a number.');
        }

        return number_format((float) $value, 4, '.', '');
    }

    protected function logEvent(
        BillingContract $contract,
        string $action,
        ?int $actorId,
        ?string $reason,
        ?array $before,
        ?array $after,
        ?int $occurrenceId = null,
    ): void {
        BillingContractAuditEvent::create([
            'contract_id' => $contract->id,
            'occurrence_id' => $occurrenceId,
            'actor_id' => $actorId,
            'action' => $action,
            'reason' => $reason,
            'before_values' => $before,
            'after_values' => $after,
            'created_by' => (int) $contract->created_by,
        ]);
    }
}
