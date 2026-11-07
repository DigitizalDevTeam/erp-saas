<?php

namespace App\Services;

use App\Exceptions\BillingContractException;
use App\Models\BillingContract;
use App\Models\BillingContractOccurrence;
use App\Models\Invoice;
use Carbon\Carbon;

/**
 * Contract Management Phase 3 — contract invoice payments
 * (AC12 remainder; plan §7, §9).
 *
 * Read-only aggregation over the EXISTING invoice payment path
 * (InvoicePayment + Invoice::getDue()): no second payment path
 * is written here. Per contract, every issued invoice with its
 * total, paid, due and state — paid / partially paid / unpaid /
 * overdue. Overdue = due date passed and amount outstanding > 0.
 * A partial payment only reduces the balance; the occurrence
 * stays 'invoiced' and no second invoice is ever issued for the
 * same period (proved in the Phase 2 tests, re-proved here with
 * a payment recorded through the existing flow).
 *
 * Money is bcmath only, never float. All queries are scoped by
 * creatorId() (AC11).
 */
class BillingPaymentService
{
    public const STATE_PAID = 'paid';
    public const STATE_PARTIALLY_PAID = 'partially_paid';
    public const STATE_UNPAID = 'unpaid';
    public const STATE_OVERDUE = 'overdue';

    /**
     * One row per issued invoice of the contract: invoice,
     * occurrence, total, paid, due, state, days overdue.
     *
     * @return array<int, array<string, mixed>>
     */
    public function paymentsForContract(BillingContract $contract, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();

        $occurrences = BillingContractOccurrence::where('contract_id', $contract->id)
            ->where('created_by', (int) $contract->created_by)
            ->where('state', BillingContractOccurrence::STATE_INVOICED)
            ->whereNotNull('invoice_id')
            ->with(['invoice.items', 'invoice.payments', 'invoice.creditNote', 'invoice.salesDebitNote'])
            ->orderBy('period_start')
            ->get();

        $rows = [];
        foreach ($occurrences as $occurrence) {
            $invoice = $occurrence->invoice;
            if (! $invoice) {
                continue;
            }

            $rows[] = $this->rowForInvoice($occurrence, $invoice, $asOf);
        }

        return $rows;
    }

    /**
     * Totals for a contract: invoiced, paid, outstanding, overdue.
     *
     * @return array{invoiced: string, paid: string, outstanding: string, overdue: string, count: int, overdue_count: int}
     */
    public function summaryForContract(BillingContract $contract, ?Carbon $asOf = null): array
    {
        $rows = $this->paymentsForContract($contract, $asOf);

        $invoiced = '0.0000';
        $paid = '0.0000';
        $outstanding = '0.0000';
        $overdue = '0.0000';
        $overdueCount = 0;

        foreach ($rows as $row) {
            $invoiced = bcadd($invoiced, $row['total'], 4);
            $paid = bcadd($paid, $row['paid'], 4);
            $outstanding = bcadd($outstanding, $row['due'], 4);
            if ($row['state'] === self::STATE_OVERDUE) {
                $overdue = bcadd($overdue, $row['due'], 4);
                $overdueCount++;
            }
        }

        return [
            'invoiced' => $invoiced,
            'paid' => $paid,
            'outstanding' => $outstanding,
            'overdue' => $overdue,
            'count' => count($rows),
            'overdue_count' => $overdueCount,
        ];
    }

    /**
     * Overdue contract invoices for a company: issued invoices
     * whose due date has passed with an outstanding balance.
     * Used by the reminders scheduler and the overdue list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function overdueInvoices(int $creatorId, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? Carbon::today())->copy()->startOfDay();

        $occurrences = BillingContractOccurrence::where('created_by', $creatorId)
            ->where('state', BillingContractOccurrence::STATE_INVOICED)
            ->whereNotNull('invoice_id')
            ->with(['contract', 'invoice.items', 'invoice.payments', 'invoice.creditNote', 'invoice.salesDebitNote'])
            ->orderBy('due_date')
            ->get();

        $rows = [];
        foreach ($occurrences as $occurrence) {
            $invoice = $occurrence->invoice;
            if (! $invoice) {
                continue;
            }

            $row = $this->rowForInvoice($occurrence, $invoice, $asOf);
            if ($row['state'] === self::STATE_OVERDUE) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** One payment row: total / paid / due / state for an issued invoice. */
    protected function rowForInvoice(BillingContractOccurrence $occurrence, Invoice $invoice, Carbon $asOf): array
    {
        $total = $this->decimalString($invoice->getTotal(), 'Invoice total');
        $due = $this->decimalString($invoice->getDue(), 'Invoice due');
        if (bccomp($due, '0.0000', 4) < 0) {
            $due = '0.0000';
        }
        // Paid is the sum of actual payments, not total minus due:
        // getDue() also nets credit/debit notes, which are not
        // payments and must not inflate the paid figure.
        $paid = '0.0000';
        foreach ($invoice->payments as $payment) {
            $paid = bcadd($paid, $this->decimalString($payment->amount, 'Payment amount'), 4);
        }

        $dueDate = Carbon::parse($invoice->due_date)->startOfDay();
        $daysOverdue = $dueDate->lessThan($asOf) ? (int) $dueDate->diffInDays($asOf) : 0;

        if (bccomp($due, '0.0000', 4) <= 0) {
            $state = self::STATE_PAID;
        } elseif ($daysOverdue > 0) {
            $state = self::STATE_OVERDUE;
        } elseif (bccomp($paid, '0.0000', 4) > 0) {
            $state = self::STATE_PARTIALLY_PAID;
        } else {
            $state = self::STATE_UNPAID;
        }

        return [
            'occurrence' => $occurrence,
            'contract' => $occurrence->contract,
            'invoice' => $invoice,
            'total' => $total,
            'paid' => $paid,
            'due' => $due,
            'state' => $state,
            'days_overdue' => $daysOverdue,
        ];
    }

    /** An issued invoice of a contract, company-scoped, or throw. */
    public function invoiceForCompany(int $creatorId, int $invoiceId): Invoice
    {
        $invoice = Invoice::where('created_by', $creatorId)->find($invoiceId);

        if (! $invoice) {
            throw new BillingContractException('Invoice not found for this company.');
        }

        return $invoice;
    }

    protected function decimalString(mixed $value, string $label): string
    {
        if (! is_numeric($value)) {
            throw new BillingContractException($label . ' must be a number.');
        }

        return number_format((float) $value, 4, '.', '');
    }
}
