<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerCreditLedger;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Utility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credit Notes Phase 3 — customer credit ("checking account").
 *
 * A credit note kept as customer credit becomes a LOT in
 * `customer_credit_ledger` (one lot per note, oldest-first use,
 * expires 14 days after it was added). The service is the ONLY
 * writer of lots and the ONLY place that decrements
 * `customers.credit_balance` for use/expiry/reversal.
 *
 * Auto-apply: when an invoice is sent (debt becomes real) or when
 * a payment is recorded, available credit is applied oldest-first
 * as `InvoicePayment` rows with payment_type 'Credit'. No cash or
 * bank moves: no Transaction row, no bankAccountBalance call, and
 * NO DocumentLedgerService posting — postInvoicePayment with a null
 * bank chart would write a one-sided AR-credit line with no debit
 * (see DocumentLedgerService::postInvoicePayment), and Phase 1
 * already posts no journal for the note itself.
 */
class CustomerCreditService
{
    /** Client answer Q2 (Phase 3): credit expires 14 days after added. */
    public const EXPIRY_DAYS = 14;

    /** Marker on invoice_payments rows that settle an invoice with credit. */
    public const PAYMENT_TYPE_CREDIT = 'Credit';

    /**
     * True when the ledger table exists. The service is called from
     * hot paths (invoice send, payment create) that many suites
     * exercise with minimal hand-built fixtures that do not carry
     * the table — without this guard those tests 500 with
     * "no such table: customer_credit_ledger". In production the
     * table always exists once migration 310016 has run, so this is
     * a no-op there. Checked on every call, never memoized
     * (a memoized Schema::hasTable poisons later tests in the same
     * PHPUnit process).
     */
    private function ledgerReady(): bool
    {
        return Schema::hasTable('customer_credit_ledger');
    }

    /**
     * Add a credit lot for an approved credit note kept as credit.
     * Idempotent: if the lot already exists (approve called twice),
     * the existing lot is returned and nothing is added again.
     */
    public function addCredit(int $creatorId, CreditNote $note, int $actorId): ?CustomerCreditLedger
    {
        if (! $this->ledgerReady()) {
            return null;
        }
        if ((int) $note->is_debit === 1 || $note->disposition !== 'credit') {
            return null;
        }
        if (! $note->customer) {
            return null;
        }

        $existing = CustomerCreditLedger::where('credit_note_id', $note->id)
            ->where('type', CustomerCreditLedger::TYPE_ADDED)
            ->first();
        if ($existing) {
            return $existing;
        }

        $amount = round((float) $note->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        return DB::transaction(function () use ($creatorId, $note, $actorId, $amount) {
            $lot = CustomerCreditLedger::create([
                'customer_id' => $note->customer,
                'credit_note_id' => $note->id,
                'invoice_id' => $note->invoice > 0 ? $note->invoice : null,
                'type' => CustomerCreditLedger::TYPE_ADDED,
                'amount' => $amount,
                'remaining' => $amount,
                'expires_at' => now()->addDays(self::EXPIRY_DAYS),
                'created_by' => $creatorId,
            ]);

            $this->adjustCustomerCreditBalance((int) $note->customer, $amount);

            return $lot;
        });
    }

    /**
     * Usable lots for a customer: remaining > 0 and not yet expired.
     * Expiry is enforced here (lazy), so a lot is unusable even if
     * the daily expire job never ran.
     */
    public function usableLots(int $creatorId, int $customerId)
    {
        if (! $this->ledgerReady()) {
            return collect();
        }

        return CustomerCreditLedger::where('created_by', $creatorId)
            ->where('customer_id', $customerId)
            ->where('type', CustomerCreditLedger::TYPE_ADDED)
            ->where('remaining', '>', 0)
            ->where('expires_at', '>', now())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function usableCredit(int $creatorId, int $customerId): float
    {
        return round((float) $this->usableLots($creatorId, $customerId)->sum('remaining'), 2);
    }

    /**
     * Apply available credit to an invoice, oldest lot first.
     * Writes one `used` ledger row + one `InvoicePayment` row
     * (payment_type 'Credit', account_id 0) per lot consumed, drops
     * the customer's credit_balance and amount owed by the same
     * total, and moves the invoice to PAID / PARTIALLY_PAID.
     * Returns the total credit applied (0 when there is none).
     */
    public function applyAvailableCredit(int $creatorId, Invoice $invoice, int $actorId): float
    {
        if (! $this->ledgerReady()) {
            return 0.0;
        }

        return DB::transaction(function () use ($creatorId, $invoice, $actorId) {
            // Materialise expiries for this customer first, so the
            // ledger shows them and usableCredit() never sees them.
            $this->expireDueCredit($creatorId, (int) $invoice->customer_id);

            $invoice = $invoice->fresh();
            if (! $invoice || (int) $invoice->status === Invoice::STATUS_CANCELLED) {
                return 0.0;
            }

            $due = round((float) $invoice->getDue(), 2);
            if ($due <= 0) {
                return 0.0;
            }

            $lots = $this->usableLots($creatorId, (int) $invoice->customer_id);
            if ($lots->isEmpty()) {
                return 0.0;
            }

            $totalApplied = 0.0;
            $today = date('Y-m-d');

            foreach ($lots as $lot) {
                if ($due <= 0) {
                    break;
                }

                $take = round(min((float) $lot->remaining, $due), 2);
                if ($take <= 0) {
                    continue;
                }

                $newRemaining = round((float) $lot->remaining - $take, 2);

                $usedRow = CustomerCreditLedger::create([
                    'customer_id' => $invoice->customer_id,
                    'credit_note_id' => $lot->credit_note_id,
                    'invoice_id' => $invoice->id,
                    'type' => CustomerCreditLedger::TYPE_USED,
                    'amount' => -$take,
                    'remaining' => $newRemaining,
                    'expires_at' => $lot->expires_at,
                    'created_by' => $creatorId,
                ]);

                $lot->remaining = $newRemaining;
                $lot->save();

                $payment = InvoicePayment::create([
                    'invoice_id' => $invoice->id,
                    'date' => $today,
                    'amount' => $take,
                    'account_id' => 0,
                    'payment_method' => 0,
                    'payment_type' => self::PAYMENT_TYPE_CREDIT,
                    'reference' => 'CR-' . ($lot->credit_note_id ?? $lot->id),
                    'description' => __('Customer credit applied'),
                ]);

                // Link the ledger row to its payment row, so deleting
                // the payment reverses exactly this use (and no other).
                $usedRow->payment_id = $payment->id;
                $usedRow->save();

                $totalApplied = round($totalApplied + $take, 2);
                $due = round($due - $take, 2);
            }

            if ($totalApplied <= 0) {
                return 0.0;
            }

            // The debt drops exactly as a cash payment drops it, and
            // the credit pool drops by the same total.
            $this->adjustCustomerCreditBalance((int) $invoice->customer_id, -$totalApplied);
            Utility::updateUserBalance('customer', (int) $invoice->customer_id, $totalApplied, 'credit');

            $invoice = $invoice->fresh();
            $due = round((float) $invoice->getDue(), 2);
            $invoice->status = $due <= 0 ? Invoice::STATUS_PAID : Invoice::STATUS_PARTIALLY_PAID;
            $invoice->save();

            return $totalApplied;
        });
    }

    /**
     * Materialise expiries: every lot past its 14 days with money
     * left gets an `expired` row, its remaining goes to 0 and the
     * customer's credit_balance drops by the remainder. Expiry never
     * changes what the customer owes — the note already reduced the
     * original invoice's due at creation.
     * Returns the number of lots expired.
     */
    public function expireDueCredit(int $creatorId, ?int $customerId = null): int
    {
        if (! $this->ledgerReady()) {
            return 0;
        }

        $query = CustomerCreditLedger::where('created_by', $creatorId)
            ->where('type', CustomerCreditLedger::TYPE_ADDED)
            ->where('remaining', '>', 0)
            ->where('expires_at', '<=', now());
        if ($customerId !== null) {
            $query->where('customer_id', $customerId);
        }

        $expired = 0;
        foreach ($query->orderBy('id')->get() as $lot) {
            $remainder = round((float) $lot->remaining, 2);
            if ($remainder <= 0) {
                continue;
            }

            CustomerCreditLedger::create([
                'customer_id' => $lot->customer_id,
                'credit_note_id' => $lot->credit_note_id,
                'invoice_id' => null,
                'type' => CustomerCreditLedger::TYPE_EXPIRED,
                'amount' => -$remainder,
                'remaining' => 0,
                'expires_at' => $lot->expires_at,
                'created_by' => $creatorId,
            ]);

            $lot->remaining = 0;
            $lot->save();

            $this->adjustCustomerCreditBalance((int) $lot->customer_id, -$remainder);
            $expired++;
        }

        return $expired;
    }

    /**
     * A note's amount changed (update): move its lot by the delta.
     * The lot's remaining is clamped at 0 — credit already used
     * stays used, and the credit_balance moves only by the change
     * in the remaining. No lot exists yet for a pending note (it is
     * created at approval, with the current amount).
     */
    public function adjustLotForUpdatedNote(CreditNote $note, float $oldAmount): void
    {
        if (! $this->ledgerReady()) {
            return;
        }

        if ((int) $note->is_debit === 1 || $note->disposition !== 'credit') {
            return;
        }

        $lot = CustomerCreditLedger::where('credit_note_id', $note->id)
            ->where('type', CustomerCreditLedger::TYPE_ADDED)
            ->first();
        if (! $lot) {
            return;
        }

        $delta = round((float) $note->amount - $oldAmount, 2);
        if ($delta == 0) {
            return;
        }

        $oldRemaining = round((float) $lot->remaining, 2);
        $newRemaining = round(max(0, $oldRemaining + $delta), 2);

        $lot->amount = round((float) $lot->amount + $delta, 2);
        $lot->remaining = $newRemaining;
        $lot->save();

        $this->adjustCustomerCreditBalance((int) $lot->customer_id, round($newRemaining - $oldRemaining, 2));
    }

    /**
     * A note kept as credit was deleted: cancel only the lot's
     * unconsumed remainder. Credit already used on an invoice stays
     * used (its payment rows stay paid). The `balance` reversal for
     * the full note amount stays in the controller, exactly as
     * Phase 1 had it.
     */
    public function reverseLotForDestroyedNote(CreditNote $note): void
    {
        if (! $this->ledgerReady()) {
            return;
        }

        if ((int) $note->is_debit === 1 || $note->disposition !== 'credit') {
            return;
        }

        $lot = CustomerCreditLedger::where('credit_note_id', $note->id)
            ->where('type', CustomerCreditLedger::TYPE_ADDED)
            ->first();
        if (! $lot) {
            return;
        }

        $remainder = round((float) $lot->remaining, 2);

        if ($remainder > 0) {
            CustomerCreditLedger::create([
                'customer_id' => $lot->customer_id,
                'credit_note_id' => $lot->credit_note_id,
                'invoice_id' => null,
                'type' => CustomerCreditLedger::TYPE_REVERSED,
                'amount' => -$remainder,
                'remaining' => 0,
                'expires_at' => $lot->expires_at,
                'created_by' => (int) $note->created_by,
            ]);

            $this->adjustCustomerCreditBalance((int) $lot->customer_id, -$remainder);
        }

        $lot->remaining = 0;
        $lot->save();
    }

    /**
     * A credit-use payment row was deleted (paymentDestroy): give
     * the credit back to its lot(s). Each `used` row is linked to
     * exactly one payment row by `payment_id`, so the reversal is
     * exact. A `reversed` row for the same payment means it is
     * already done (the destroy path runs once, but the guard keeps
     * a double call from crediting twice).
     */
    public function reverseCreditUseForPayment(InvoicePayment $payment): void
    {
        if (! $this->ledgerReady()) {
            return;
        }

        if ($payment->payment_type !== self::PAYMENT_TYPE_CREDIT) {
            return;
        }

        $usedRows = CustomerCreditLedger::where('payment_id', $payment->id)
            ->where('type', CustomerCreditLedger::TYPE_USED)
            ->orderBy('id')
            ->get();

        foreach ($usedRows as $used) {
            $already = CustomerCreditLedger::where('payment_id', $payment->id)
                ->where('type', CustomerCreditLedger::TYPE_REVERSED)
                ->exists();
            if ($already) {
                continue;
            }

            $lot = CustomerCreditLedger::where('credit_note_id', $used->credit_note_id)
                ->where('type', CustomerCreditLedger::TYPE_ADDED)
                ->first();
            if (! $lot) {
                continue;
            }

            $giveBack = round((float) $used->amount * -1, 2);
            $newRemaining = round((float) $lot->remaining + $giveBack, 2);

            CustomerCreditLedger::create([
                'customer_id' => $lot->customer_id,
                'credit_note_id' => $lot->credit_note_id,
                'invoice_id' => $used->invoice_id,
                'payment_id' => $payment->id,
                'type' => CustomerCreditLedger::TYPE_REVERSED,
                'amount' => $giveBack,
                'remaining' => $newRemaining,
                'expires_at' => $lot->expires_at,
                'created_by' => (int) $used->created_by,
            ]);

            $lot->remaining = $newRemaining;
            $lot->save();

            $this->adjustCustomerCreditBalance((int) $lot->customer_id, $giveBack);
        }
    }

    /** Keep the denormalised total in step with the lots. */
    protected function adjustCustomerCreditBalance(int $customerId, float $delta): void
    {
        if ($delta == 0) {
            return;
        }

        $customer = Customer::find($customerId);
        if (! $customer) {
            return;
        }

        $customer->credit_balance = round(((float) ($customer->credit_balance ?? 0)) + $delta, 2);
        $customer->save();
    }
}
