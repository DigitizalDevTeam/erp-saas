<?php

namespace App\Services;

use App\Models\BillingContract;
use App\Models\BillingContractAuditEvent;
use App\Models\BillingContractOccurrence;
use App\Models\BillingDeliveryAttempt;
use App\Models\BillingRenewal;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Utility;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Contract Management Phase 4 — dashboard, reports and audit
 * trail (plan §9, §10).
 *
 * READ-ONLY aggregation. This service never creates a posting, a
 * journal, an invoice or an occurrence: every figure is computed
 * from the data Phases 1–3 already wrote. Totals reconcile to
 * their source — issued invoices for a contract equal the sum of
 * its issued occurrences (subtotal = occurrence amount, ex-tax,
 * by the Phase 2 construction), and a forecast period equals the
 * sum of its pending occurrences at their effective revision. A
 * report that does not reconcile is a bug, not a rounding
 * difference: the invoiced and forecast reports carry a
 * 'reconciliation' block that proves it.
 *
 * Planned and issued amounts are always separate columns, never
 * mixed (plan §11 risk 8): a forecast is an ESTIMATE, not money.
 * Every query is scoped by creatorId() (AC11). Money is
 * DECIMAL(20,4), bcmath only, never float.
 */
class BillingReportService
{
    public const REPORT_EXPIRING = 'expiring';
    public const REPORT_RENEWALS_DUE = 'renewals-due';
    public const REPORT_FORECAST = 'forecast';
    public const REPORT_INVOICED = 'invoiced';
    public const REPORT_COLLECTIONS = 'collections';
    public const REPORT_OVERDUE = 'overdue';
    public const REPORT_DELIVERY_FAILURES = 'delivery-failures';
    public const REPORT_CALENDAR = 'calendar';
    public const REPORT_AUDIT_TRAIL = 'audit-trail';

    public const REPORTS = [
        self::REPORT_EXPIRING => 'Expiring Contracts',
        self::REPORT_RENEWALS_DUE => 'Renewals Due',
        self::REPORT_FORECAST => 'Forecast Revenue (Estimate)',
        self::REPORT_INVOICED => 'Invoiced Totals',
        self::REPORT_COLLECTIONS => 'Collections',
        self::REPORT_OVERDUE => 'Overdue Balances',
        self::REPORT_DELIVERY_FAILURES => 'Delivery Failures',
        self::REPORT_CALENDAR => 'Billing Calendar',
        self::REPORT_AUDIT_TRAIL => 'Audit Trail',
    ];

    protected BillingPaymentService $payments;

    public function __construct(?BillingPaymentService $payments = null)
    {
        $this->payments = $payments ?? new BillingPaymentService();
    }

    // ------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------

    /**
     * Normalise raw request input into the filter shape every report
     * understands. Invalid dates are dropped, never guessed; a from
     * after a to is swapped so the range still means something.
     *
     * @return array{from: ?string, to: ?string, customer_id: ?int, contract_id: ?int, state: ?string, frequency: ?string, search: ?string, days: int}
     */
    public function normalizeFilters(array $input): array
    {
        $from = $this->parseDate($input['from'] ?? null);
        $to = $this->parseDate($input['to'] ?? null);
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'from' => $from,
            'to' => $to,
            'customer_id' => isset($input['customer_id']) && ctype_digit((string) $input['customer_id'])
                ? (int) $input['customer_id'] : null,
            'contract_id' => isset($input['contract_id']) && ctype_digit((string) $input['contract_id'])
                ? (int) $input['contract_id'] : null,
            'state' => isset($input['state']) && trim((string) $input['state']) !== ''
                ? substr(trim((string) $input['state']), 0, 30) : null,
            'frequency' => in_array($input['frequency'] ?? null, BillingContract::FREQUENCIES, true)
                ? $input['frequency'] : null,
            'search' => isset($input['search']) && trim((string) $input['search']) !== ''
                ? substr(trim((string) $input['search']), 0, 100) : null,
            'days' => isset($input['days']) && ctype_digit((string) $input['days'])
                ? max(1, min(365, (int) $input['days'])) : 30,
        ];
    }

    protected function parseDate(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Dashboard (plan §9)
    // ------------------------------------------------------------------

    /**
     * All dashboard KPIs in one array. Forecasts are ESTIMATES,
     * grouped by currency, and exclude suspended/cancelled
     * coverage; planned and issued are separate figures.
     *
     * @return array{active_contracts: int, forecast_this_month: array, issued_this_month: array, overdue_balance: array, overdue_count: int, failed_deliveries: int, stuck_generated: int, expiring_soon: array}
     */
    public function dashboard(int $creatorId, int $days = 30): array
    {
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth()->format('Y-m-d');
        $monthEnd = $today->copy()->endOfMonth()->format('Y-m-d');

        $activeContracts = BillingContract::where('created_by', $creatorId)
            ->where('state', BillingContract::STATE_ACTIVE)
            ->count();

        // Forecast this month: pending occurrences of ACTIVE
        // contracts with a billing date in the current month.
        $forecast = $this->sumOccurrenceAmounts(
            BillingContractOccurrence::where('created_by', $creatorId)
                ->where('state', BillingContractOccurrence::STATE_PENDING)
                ->whereDate('billing_date', '>=', $monthStart)
                ->whereDate('billing_date', '<=', $monthEnd)
                ->whereHas('contract', fn ($q) => $q->where('state', BillingContract::STATE_ACTIVE))
                ->get(['currency', 'amount'])
        );

        // Issued this month: invoice subtotals of occurrences
        // invoiced in the current month (issue date, not billing
        // date — the money exists from the issue date).
        $issued = $this->sumInvoiceSubtotals(
            BillingContractOccurrence::where('created_by', $creatorId)
                ->where('state', BillingContractOccurrence::STATE_INVOICED)
                ->whereNotNull('invoice_id')
                ->whereHas('invoice', fn ($q) => $q
                    ->whereDate('issue_date', '>=', $monthStart)
                    ->whereDate('issue_date', '<=', $monthEnd))
                ->with('invoice')
                ->get()
        );

        $overdueRows = $this->payments->overdueInvoices($creatorId, $today);
        $overdue = [];
        foreach ($overdueRows as $row) {
            $currency = (string) ($row['occurrence']->currency ?? 'MZN');
            $overdue[$currency] = bcadd($overdue[$currency] ?? '0.0000', $row['due'], 4);
        }

        // Failed occurrences: deliveries that exhausted their
        // retries (final failure) plus occurrences stuck in the
        // 'generated' state — claimed by the engine but never
        // invoiced, i.e. a crashed run.
        $failedDeliveries = BillingDeliveryAttempt::where('created_by', $creatorId)
            ->where('status', BillingDeliveryAttempt::STATUS_FAILED)
            ->whereNull('next_retry_at')
            ->count();
        $stuckGenerated = BillingContractOccurrence::where('created_by', $creatorId)
            ->where('state', BillingContractOccurrence::STATE_GENERATED)
            ->count();

        $expiring = $this->expiringContracts($creatorId, $days, $today);

        return [
            'active_contracts' => $activeContracts,
            'forecast_this_month' => $forecast,
            'issued_this_month' => $issued,
            'overdue_balance' => $overdue,
            'overdue_count' => count($overdueRows),
            'failed_deliveries' => $failedDeliveries,
            'stuck_generated' => $stuckGenerated,
            'expiring_soon' => $expiring,
        ];
    }

    // ------------------------------------------------------------------
    // Reports
    // ------------------------------------------------------------------

    /**
     * One entry point for every report, so the screen, the CSV,
     * the XLSX and the PDF can never disagree: they all read this
     * output.
     *
     * @return array{title: string, description: string, headings: array<int, string>, rows: array<int, array<int, string>>, totals: array<string, string>, reconciliation?: array<string, mixed>}
     */
    public function report(int $creatorId, string $key, array $filters): array
    {
        if (! isset(self::REPORTS[$key])) {
            throw new \InvalidArgumentException('Unknown billing report: ' . $key);
        }

        return match ($key) {
            self::REPORT_EXPIRING => $this->expiringReport($creatorId, $filters),
            self::REPORT_RENEWALS_DUE => $this->renewalsDueReport($creatorId, $filters),
            self::REPORT_FORECAST => $this->forecastReport($creatorId, $filters),
            self::REPORT_INVOICED => $this->invoicedReport($creatorId, $filters),
            self::REPORT_COLLECTIONS => $this->collectionsReport($creatorId, $filters),
            self::REPORT_OVERDUE => $this->overdueReport($creatorId, $filters),
            self::REPORT_DELIVERY_FAILURES => $this->deliveryFailuresReport($creatorId, $filters),
            self::REPORT_CALENDAR => $this->calendarReport($creatorId, $filters),
            self::REPORT_AUDIT_TRAIL => $this->auditTrailReport($creatorId, $filters),
        };
    }

    /** Contracts ending within the window (default: next 30 days). */
    protected function expiringReport(int $creatorId, array $filters): array
    {
        $days = (int) ($filters['days'] ?? 30);
        $today = Carbon::today();
        $contracts = $this->expiringContracts($creatorId, $days, $today, $filters);

        $rows = [];
        foreach ($contracts as $contract) {
            $rows[] = [
                $contract->reference,
                $contract->customer ? $contract->customer->name : '—',
                $contract->end_date ? $contract->end_date->format('Y-m-d') : '—',
                (string) ($contract->end_date ? (int) $today->diffInDays($contract->end_date) : 0),
                ucfirst(str_replace('_', ' ', $contract->state)),
                $contract->currency,
                $this->money($this->periodAmount($contract)),
                $this->renewalStatusLabel($contract),
            ];
        }

        return [
            'title' => 'Expiring Contracts',
            'description' => 'Active or suspended contracts ending within ' . $days . ' days.',
            'headings' => ['Reference', 'Customer', 'End date', 'Days left', 'State', 'Currency', 'Period amount', 'Renewal'],
            'rows' => $rows,
            'totals' => ['Contracts' => (string) count($rows)],
        ];
    }

    /** Contracts ending soon with their renewal state. */
    protected function renewalsDueReport(int $creatorId, array $filters): array
    {
        $days = (int) ($filters['days'] ?? 30);
        $today = Carbon::today();
        $contracts = $this->expiringContracts($creatorId, $days, $today, $filters);

        $rows = [];
        foreach ($contracts as $contract) {
            $renewal = $this->latestRenewal($contract);
            $rows[] = [
                $contract->reference,
                $contract->customer ? $contract->customer->name : '—',
                $contract->end_date ? $contract->end_date->format('Y-m-d') : '—',
                (string) ($contract->end_date ? (int) $today->diffInDays($contract->end_date) : 0),
                $renewal ? ucfirst(str_replace('_', ' ', $renewal->status)) : 'None',
                $renewal && $renewal->newContract ? $renewal->newContract->reference : '—',
                $renewal && $renewal->notice_sent_at ? $renewal->notice_sent_at->format('Y-m-d') : '—',
            ];
        }

        return [
            'title' => 'Renewals Due',
            'description' => 'Contracts ending within ' . $days . ' days and whether a renewal is in progress.',
            'headings' => ['Reference', 'Customer', 'End date', 'Days left', 'Renewal status', 'New contract', 'Notice sent'],
            'rows' => $rows,
            'totals' => ['Contracts' => (string) count($rows)],
        ];
    }

    /**
     * Forecast revenue by month and currency. Planned = pending
     * occurrences of active contracts (ESTIMATE — not money until
     * invoiced). Issued = invoiced occurrences in the same billing
     * month, as a SEPARATE column. Planned + issued = scheduled
     * total for the month; nothing is counted twice.
     */
    protected function forecastReport(int $creatorId, array $filters): array
    {
        $from = $filters['from'] ?? Carbon::today()->startOfMonth()->format('Y-m-d');
        $to = $filters['to'] ?? Carbon::today()->addMonths(5)->endOfMonth()->format('Y-m-d');

        // Planned = pending occurrences of ACTIVE contracts only
        // (suspended/cancelled coverage is excluded — plan §9).
        // Issued = invoiced occurrences regardless of the
        // contract's current state: the invoice is real money.
        $pending = $this->occurrencesInWindow($creatorId, $from, $to, $filters)
            ->where('state', BillingContractOccurrence::STATE_PENDING)
            ->whereHas('contract', fn ($q) => $q->where('state', BillingContract::STATE_ACTIVE))
            ->orderBy('billing_date')
            ->get();
        $invoiced = $this->occurrencesInWindow($creatorId, $from, $to, $filters)
            ->where('state', BillingContractOccurrence::STATE_INVOICED)
            ->with('invoice')
            ->orderBy('billing_date')
            ->get();
        $occurrences = $pending->merge($invoiced)->sortBy('billing_date');

        $months = [];
        foreach ($occurrences as $occurrence) {
            $month = Carbon::parse($occurrence->billing_date)->format('Y-m');
            $currency = (string) $occurrence->currency;
            $key = $month . '|' . $currency;
            if (! isset($months[$key])) {
                $months[$key] = [
                    'month' => $month,
                    'currency' => $currency,
                    'planned' => '0.0000',
                    'issued' => '0.0000',
                ];
            }

            if ($occurrence->state === BillingContractOccurrence::STATE_PENDING) {
                $months[$key]['planned'] = bcadd($months[$key]['planned'], (string) $occurrence->amount, 4);
            } else {
                $invoice = $occurrence->invoice;
                $subtotal = $invoice
                    ? $this->decimalString($invoice->getSubTotal(), 'Invoice subtotal')
                    : (string) $occurrence->amount;
                $months[$key]['issued'] = bcadd($months[$key]['issued'], $subtotal, 4);
            }
        }

        ksort($months);
        $rows = [];
        $plannedTotals = [];
        $issuedTotals = [];
        foreach ($months as $month) {
            $scheduled = bcadd($month['planned'], $month['issued'], 4);
            $rows[] = [
                $month['month'],
                $month['currency'],
                $this->money($month['planned']),
                $this->money($month['issued']),
                $this->money($scheduled),
            ];
            $plannedTotals[$month['currency']] = bcadd($plannedTotals[$month['currency']] ?? '0.0000', $month['planned'], 4);
            $issuedTotals[$month['currency']] = bcadd($issuedTotals[$month['currency']] ?? '0.0000', $month['issued'], 4);
        }

        $totals = [];
        foreach ($plannedTotals as $currency => $amount) {
            $totals[$currency . ' — Planned (estimate)'] = $this->money($amount);
        }
        foreach ($issuedTotals as $currency => $amount) {
            $totals[$currency . ' — Issued'] = $this->money($amount);
        }

        // Reconciliation: planned for the window equals the sum of
        // the pending occurrence amounts in the window, per
        // currency; issued equals the invoiced occurrences'
        // invoice subtotals.
        $pendingSum = $this->sumOccurrenceAmounts(
            $occurrences->where('state', BillingContractOccurrence::STATE_PENDING)
        );
        $issuedSum = $this->sumInvoiceSubtotals(
            $occurrences->where('state', BillingContractOccurrence::STATE_INVOICED)
        );
        $matches = $this->sumsMatch($pendingSum, $plannedTotals)
            && $this->sumsMatch($issuedSum, $issuedTotals);

        return [
            'title' => 'Forecast Revenue (Estimate)',
            'description' => 'ESTIMATE — planned amounts are not money until invoiced. Planned = pending occurrences of active contracts; issued = invoiced occurrences in the same billing month.',
            'headings' => ['Month', 'Currency', 'Planned (estimate)', 'Issued', 'Scheduled total'],
            'rows' => $rows,
            'totals' => $totals,
            'reconciliation' => [
                'label' => 'Forecast planned amounts reconcile to pending occurrences; issued amounts reconcile to invoice subtotals',
                'occurrence_planned_sum' => $pendingSum,
                'planned_sum' => $plannedTotals,
                'occurrence_issued_sum' => $issuedSum,
                'issued_sum' => $issuedTotals,
                'matches' => $matches,
            ],
        ];
    }

    /**
     * Invoiced totals by contract / customer / period. Subtotal is
     * the ex-tax figure that must equal the occurrence amount.
     */
    protected function invoicedReport(int $creatorId, array $filters): array
    {
        $occurrences = $this->invoicedOccurrences($creatorId, $filters);

        $rows = [];
        $subtotalTotals = [];
        $taxTotals = [];
        $totalTotals = [];
        foreach ($occurrences as $occurrence) {
            $invoice = $occurrence->invoice;
            if (! $invoice) {
                continue;
            }
            $contract = $occurrence->contract;
            $subtotal = $this->decimalString($invoice->getSubTotal(), 'Invoice subtotal');
            $tax = $this->decimalString($invoice->getTotalTax(), 'Invoice tax');
            $total = $this->decimalString($invoice->getTotal(), 'Invoice total');
            $currency = (string) $occurrence->currency;

            $rows[] = [
                $contract ? $contract->reference : '—',
                $contract && $contract->customer ? $contract->customer->name : '—',
                $occurrence->period_start->format('Y-m-d') . ' → ' . $occurrence->coverageEndInclusive(),
                $this->invoiceNumber($creatorId, (int) $invoice->invoice_id),
                $invoice->issue_date ? Carbon::parse($invoice->issue_date)->format('Y-m-d') : '—',
                $currency,
                $this->money($subtotal),
                $this->money($tax),
                $this->money($total),
            ];
            $subtotalTotals[$currency] = bcadd($subtotalTotals[$currency] ?? '0.0000', $subtotal, 4);
            $taxTotals[$currency] = bcadd($taxTotals[$currency] ?? '0.0000', $tax, 4);
            $totalTotals[$currency] = bcadd($totalTotals[$currency] ?? '0.0000', $total, 4);
        }

        $totals = [];
        foreach ($subtotalTotals as $currency => $amount) {
            $totals[$currency . ' — Subtotal'] = $this->money($amount);
        }
        foreach ($taxTotals as $currency => $amount) {
            $totals[$currency . ' — Tax'] = $this->money($amount);
        }
        foreach ($totalTotals as $currency => $amount) {
            $totals[$currency . ' — Total'] = $this->money($amount);
        }

        // Reconciliation: sum of invoice subtotals equals sum of
        // the issued occurrence amounts, per currency.
        $occurrenceSum = $this->sumOccurrenceAmounts($occurrences);
        $matches = $this->sumsMatch($occurrenceSum, $subtotalTotals);

        return [
            'title' => 'Invoiced Totals',
            'description' => 'Issued contract invoices. Subtotal (ex-tax) reconciles to the issued occurrence amounts.',
            'headings' => ['Contract', 'Customer', 'Coverage period', 'Invoice', 'Issue date', 'Currency', 'Subtotal', 'Tax', 'Total'],
            'rows' => $rows,
            'totals' => $totals,
            'reconciliation' => [
                'label' => 'Invoice subtotals reconcile to issued occurrence amounts',
                'occurrence_sum' => $occurrenceSum,
                'invoice_subtotal_sum' => $subtotalTotals,
                'matches' => $matches,
            ],
        ];
    }

    /**
     * Collections: every issued invoice with total / paid /
     * outstanding / state. Adjustments = total − paid − due
     * (credit notes reduce the due, debit notes increase it), so
     * every row reconciles: paid + due + adjustments = total.
     */
    protected function collectionsReport(int $creatorId, array $filters): array
    {
        $rows = [];
        $totalTotals = [];
        $paidTotals = [];
        $dueTotals = [];

        foreach ($this->contractsForReport($creatorId, $filters) as $contract) {
            foreach ($this->payments->paymentsForContract($contract) as $row) {
                $currency = (string) $row['occurrence']->currency;
                $adjustments = bcsub(
                    bcsub($row['total'], $row['paid'], 4),
                    $row['due'],
                    4
                );
                $rows[] = [
                    $contract->reference,
                    $contract->customer ? $contract->customer->name : '—',
                    $this->invoiceNumber($creatorId, (int) $row['invoice']->invoice_id),
                    $row['invoice']->issue_date ? Carbon::parse($row['invoice']->issue_date)->format('Y-m-d') : '—',
                    $row['invoice']->due_date ? Carbon::parse($row['invoice']->due_date)->format('Y-m-d') : '—',
                    $currency,
                    $this->money($row['total']),
                    $this->money($row['paid']),
                    $this->money($row['due']),
                    $this->money($adjustments),
                    ucfirst(str_replace('_', ' ', $row['state'])),
                ];
                $totalTotals[$currency] = bcadd($totalTotals[$currency] ?? '0.0000', $row['total'], 4);
                $paidTotals[$currency] = bcadd($paidTotals[$currency] ?? '0.0000', $row['paid'], 4);
                $dueTotals[$currency] = bcadd($dueTotals[$currency] ?? '0.0000', $row['due'], 4);
            }
        }

        $totals = [];
        foreach ($totalTotals as $currency => $amount) {
            $totals[$currency . ' — Total'] = $this->money($amount);
        }
        foreach ($paidTotals as $currency => $amount) {
            $totals[$currency . ' — Paid'] = $this->money($amount);
        }
        foreach ($dueTotals as $currency => $amount) {
            $totals[$currency . ' — Outstanding'] = $this->money($amount);
        }

        return [
            'title' => 'Collections',
            'description' => 'Paid / partially paid / outstanding per issued contract invoice. Paid + outstanding + adjustments = total on every row.',
            'headings' => ['Contract', 'Customer', 'Invoice', 'Issue date', 'Due date', 'Currency', 'Total', 'Paid', 'Outstanding', 'Adjustments', 'State'],
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /** Overdue balances: due date passed with an amount outstanding. */
    protected function overdueReport(int $creatorId, array $filters): array
    {
        $today = Carbon::today();
        $rows = [];
        $dueTotals = [];
        $count = 0;

        foreach ($this->payments->overdueInvoices($creatorId, $today) as $row) {
            if ($this->rowFilteredOut($row['contract'], $filters)) {
                continue;
            }
            $currency = (string) $row['occurrence']->currency;
            $rows[] = [
                $row['contract']->reference,
                $row['contract']->customer ? $row['contract']->customer->name : '—',
                $this->invoiceNumber($creatorId, (int) $row['invoice']->invoice_id),
                $row['invoice']->due_date ? Carbon::parse($row['invoice']->due_date)->format('Y-m-d') : '—',
                (string) $row['days_overdue'],
                $currency,
                $this->money($row['total']),
                $this->money($row['paid']),
                $this->money($row['due']),
            ];
            $dueTotals[$currency] = bcadd($dueTotals[$currency] ?? '0.0000', $row['due'], 4);
            $count++;
        }

        $totals = ['Overdue invoices' => (string) $count];
        foreach ($dueTotals as $currency => $amount) {
            $totals[$currency . ' — Overdue'] = $this->money($amount);
        }

        return [
            'title' => 'Overdue Balances',
            'description' => 'Issued contract invoices past their due date with an outstanding balance.',
            'headings' => ['Contract', 'Customer', 'Invoice', 'Due date', 'Days overdue', 'Currency', 'Total', 'Paid', 'Outstanding'],
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /** Delivery failures: email attempts that failed, with retry state. */
    protected function deliveryFailuresReport(int $creatorId, array $filters): array
    {
        $attempts = BillingDeliveryAttempt::where('created_by', $creatorId)
            ->where('status', BillingDeliveryAttempt::STATUS_FAILED)
            ->with(['contract.customer', 'invoice'])
            ->orderByDesc('updated_at')
            ->get();

        $rows = [];
        foreach ($attempts as $attempt) {
            $contract = $attempt->contract;
            if ($this->rowFilteredOut($contract, $filters)) {
                continue;
            }
            $invoice = $attempt->invoice;
            $rows[] = [
                $contract ? $contract->reference : '—',
                $contract && $contract->customer ? $contract->customer->name : '—',
                $invoice ? $this->invoiceNumber($creatorId, (int) $invoice->invoice_id) : '—',
                (string) $attempt->attempt_count,
                $attempt->next_retry_at
                    ? 'Retrying at ' . $attempt->next_retry_at->format('Y-m-d H:i')
                    : 'Final — resend manually',
                $attempt->sent_at ? $attempt->sent_at->format('Y-m-d H:i') : '—',
                mb_substr((string) ($attempt->last_error ?? ''), 0, 200),
            ];
        }

        return [
            'title' => 'Delivery Failures',
            'description' => 'Contract invoice emails that failed. A final failure needs a manual resend — the retry never creates a new invoice.',
            'headings' => ['Contract', 'Customer', 'Invoice', 'Attempts', 'Retry', 'Last sent', 'Last error'],
            'rows' => $rows,
            'totals' => ['Failed deliveries' => (string) count($rows)],
        ];
    }

    /** Billing calendar: due + overdue occurrences in the window. */
    protected function calendarReport(int $creatorId, array $filters): array
    {
        $from = $filters['from'] ?? Carbon::today()->startOfMonth()->format('Y-m-d');
        $to = $filters['to'] ?? Carbon::today()->endOfMonth()->format('Y-m-d');
        $today = Carbon::today()->format('Y-m-d');

        $occurrences = $this->occurrencesInWindow($creatorId, $from, $to, $filters)
            ->with(['contract.customer', 'invoice'])
            ->orderBy('billing_date')
            ->orderBy('id')
            ->get();

        $rows = [];
        foreach ($occurrences as $occurrence) {
            $contract = $occurrence->contract;
            $state = $occurrence->state;
            if ($state === BillingContractOccurrence::STATE_PENDING) {
                $state = $occurrence->billing_date->format('Y-m-d') < $today ? 'Overdue' : 'Due';
            } else {
                $state = ucfirst($state);
            }
            $rows[] = [
                $occurrence->billing_date->format('Y-m-d'),
                $contract ? $contract->reference : '—',
                $contract && $contract->customer ? $contract->customer->name : '—',
                $occurrence->period_start->format('Y-m-d') . ' → ' . $occurrence->coverageEndInclusive(),
                (string) $occurrence->currency,
                $this->money((string) $occurrence->amount),
                $state,
                $occurrence->invoice ? $this->invoiceNumber($creatorId, (int) $occurrence->invoice->invoice_id) : '—',
            ];
        }

        return [
            'title' => 'Billing Calendar',
            'description' => 'Due and overdue occurrences from ' . $from . ' to ' . $to . ', with coverage, amount and state.',
            'headings' => ['Billing date', 'Contract', 'Customer', 'Coverage period', 'Currency', 'Amount', 'State', 'Invoice'],
            'rows' => $rows,
            'totals' => ['Occurrences' => (string) count($rows)],
        ];
    }

    /** Audit trail: every lifecycle, amendment, approval, generation, delivery and renewal event. */
    protected function auditTrailReport(int $creatorId, array $filters): array
    {
        $query = BillingContractAuditEvent::where('created_by', $creatorId)
            ->with('contract')
            ->orderByDesc('id');

        if (! empty($filters['contract_id'])) {
            $query->where('contract_id', (int) $filters['contract_id']);
        }
        if (! empty($filters['state'])) {
            $query->where('action', (string) $filters['state']);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }
        if (! empty($filters['search'])) {
            $query->where('action', 'like', '%' . $filters['search'] . '%');
        }

        $events = $query->limit(500)->get();
        $actorIds = $events->pluck('actor_id')->filter()->unique()->values()->all();
        $actors = $actorIds !== []
            ? User::whereIn('id', $actorIds)->pluck('name', 'id')->all()
            : [];

        $rows = [];
        foreach ($events as $event) {
            $rows[] = [
                $event->created_at ? $event->created_at->format('Y-m-d H:i') : '—',
                $event->contract ? $event->contract->reference : '—',
                $event->actor_id ? ($actors[(int) $event->actor_id] ?? ('User #' . $event->actor_id)) : 'System',
                $event->action,
                $event->reason ?? '—',
                $this->summariseChange($event->before_values, $event->after_values),
            ];
        }

        return [
            'title' => 'Audit Trail',
            'description' => 'Every lifecycle, amendment, approval, generation, delivery and renewal event, with actor and before/after values. Append-only.',
            'headings' => ['Date', 'Contract', 'Actor', 'Action', 'Reason', 'Change'],
            'rows' => $rows,
            'totals' => ['Events' => (string) count($rows)],
        ];
    }

    // ------------------------------------------------------------------
    // Shared queries
    // ------------------------------------------------------------------

    /** Active or suspended contracts ending within the window, with the report filters applied. */
    protected function expiringContracts(int $creatorId, int $days, Carbon $asOf, array $filters = [])
    {
        $until = $asOf->copy()->addDays($days);

        $query = BillingContract::where('created_by', $creatorId)
            ->whereIn('state', [BillingContract::STATE_ACTIVE, BillingContract::STATE_SUSPENDED])
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', $asOf->format('Y-m-d'))
            ->whereDate('end_date', '<=', $until->format('Y-m-d'))
            ->with(['customer', 'lines'])
            ->orderBy('end_date')
            ->orderBy('id');

        $this->applyContractFilters($query, $filters);

        return $query->get();
    }

    /** Contracts visible to a report, with the report filters applied. */
    protected function contractsForReport(int $creatorId, array $filters)
    {
        $query = BillingContract::where('created_by', $creatorId)
            ->with(['customer'])
            ->orderBy('reference');

        $this->applyContractFilters($query, $filters);

        return $query->get();
    }

    protected function applyContractFilters($query, array $filters): void
    {
        if (! empty($filters['contract_id'])) {
            $query->where('id', (int) $filters['contract_id']);
        }
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }
        if (! empty($filters['frequency'])) {
            $query->where('billing_frequency', (string) $filters['frequency']);
        }
        if (! empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', $search)->orWhere('title', 'like', $search);
            });
        }
    }

    /** Occurrences (any state) with a billing date in the window, filters applied. */
    protected function occurrencesInWindow(int $creatorId, string $from, string $to, array $filters)
    {
        $query = BillingContractOccurrence::where('created_by', $creatorId)
            ->whereDate('billing_date', '>=', $from)
            ->whereDate('billing_date', '<=', $to);

        if (! empty($filters['contract_id'])) {
            $query->where('contract_id', (int) $filters['contract_id']);
        }
        if (! empty($filters['customer_id'])) {
            $query->whereHas('contract', fn ($q) => $q->where('customer_id', (int) $filters['customer_id']));
        }
        if (! empty($filters['frequency'])) {
            $query->whereHas('contract', fn ($q) => $q->where('billing_frequency', (string) $filters['frequency']));
        }

        return $query;
    }

    /** Invoiced occurrences for the invoiced report, filters applied. */
    protected function invoicedOccurrences(int $creatorId, array $filters)
    {
        $query = BillingContractOccurrence::where('created_by', $creatorId)
            ->where('state', BillingContractOccurrence::STATE_INVOICED)
            ->whereNotNull('invoice_id')
            ->with(['contract.customer', 'invoice']);

        if (! empty($filters['contract_id'])) {
            $query->where('contract_id', (int) $filters['contract_id']);
        }
        if (! empty($filters['customer_id'])) {
            $query->whereHas('contract', fn ($q) => $q->where('customer_id', (int) $filters['customer_id']));
        }
        if (! empty($filters['frequency'])) {
            $query->whereHas('contract', fn ($q) => $q->where('billing_frequency', (string) $filters['frequency']));
        }
        if (! empty($filters['from'])) {
            $query->whereHas('invoice', fn ($q) => $q->whereDate('issue_date', '>=', $filters['from']));
        }
        if (! empty($filters['to'])) {
            $query->whereHas('invoice', fn ($q) => $q->whereDate('issue_date', '<=', $filters['to']));
        }

        return $query->orderBy('billing_date')->orderBy('id')->get();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Per-period amount of a contract: the sum of its current lines. */
    protected function periodAmount(BillingContract $contract): string
    {
        $sum = '0.0000';
        foreach ($contract->lines as $line) {
            $sum = bcadd($sum, (string) $line->amount, 4);
        }

        return $sum;
    }

    /** Renewal label for the expiring report. */
    protected function renewalStatusLabel(BillingContract $contract): string
    {
        $renewal = $this->latestRenewal($contract);
        if (! $renewal) {
            return 'No renewal';
        }

        $label = ucfirst(str_replace('_', ' ', $renewal->status));
        if ($renewal->newContract) {
            $label .= ' (' . $renewal->newContract->reference . ')';
        }

        return $label;
    }

    /** Latest renewal for a contract, or null before the Phase 3 migration. */
    protected function latestRenewal(BillingContract $contract): ?BillingRenewal
    {
        if (! Schema::hasTable('billing_renewals')) {
            return null;
        }

        return BillingRenewal::where('contract_id', $contract->id)
            ->with('newContract')
            ->orderByDesc('id')
            ->first();
    }

    /** Sum occurrence amounts grouped by currency. */
    protected function sumOccurrenceAmounts($occurrences): array
    {
        $sums = [];
        foreach ($occurrences as $occurrence) {
            $currency = (string) $occurrence->currency;
            $sums[$currency] = bcadd($sums[$currency] ?? '0.0000', (string) $occurrence->amount, 4);
        }

        return $sums;
    }

    /** Sum invoice subtotals of invoiced occurrences, grouped by currency. */
    protected function sumInvoiceSubtotals($occurrences): array
    {
        $sums = [];
        foreach ($occurrences as $occurrence) {
            $invoice = $occurrence->invoice;
            if (! $invoice) {
                continue;
            }
            $currency = (string) $occurrence->currency;
            $sums[$currency] = bcadd(
                $sums[$currency] ?? '0.0000',
                $this->decimalString($invoice->getSubTotal(), 'Invoice subtotal'),
                4
            );
        }

        return $sums;
    }

    /** Two per-currency sum maps are equal to the 4th decimal. */
    protected function sumsMatch(array $left, array $right): bool
    {
        $currencies = array_unique(array_merge(array_keys($left), array_keys($right)));
        foreach ($currencies as $currency) {
            if (bccomp($left[$currency] ?? '0.0000', $right[$currency] ?? '0.0000', 4) !== 0) {
                return false;
            }
        }

        return true;
    }

    /** Whether a contract row is excluded by the customer/contract/frequency/search filters. */
    protected function rowFilteredOut(?BillingContract $contract, array $filters): bool
    {
        if ($contract === null) {
            return false;
        }
        if (! empty($filters['contract_id']) && (int) $contract->id !== (int) $filters['contract_id']) {
            return true;
        }
        if (! empty($filters['customer_id']) && (int) $contract->customer_id !== (int) $filters['customer_id']) {
            return true;
        }
        if (! empty($filters['frequency']) && $contract->billing_frequency !== (string) $filters['frequency']) {
            return true;
        }
        if (! empty($filters['search'])) {
            $search = mb_strtolower((string) $filters['search']);
            $haystack = mb_strtolower($contract->reference . ' ' . $contract->title);
            if (! str_contains($haystack, $search)) {
                return true;
            }
        }

        return false;
    }

    /** One-line "key: before → after" summary of an audit event. */
    protected function summariseChange(mixed $before, mixed $after): string
    {
        if (! is_array($after) || $after === []) {
            return '—';
        }

        $parts = [];
        foreach ($after as $key => $value) {
            $old = is_array($before) && array_key_exists($key, $before) ? $before[$key] : null;
            $parts[] = $key . ': ' . $this->scalarForAudit($old) . ' → ' . $this->scalarForAudit($value);
        }

        return implode('; ', $parts);
    }

    protected function scalarForAudit(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (is_array($value)) {
            return json_encode($value);
        }

        return (string) $value;
    }

    protected function money(string $amount): string
    {
        return number_format((float) $amount, 2, '.', ',');
    }

    protected function decimalString(mixed $value, string $label): string
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException($label . ' must be a number.');
        }

        return number_format((float) $value, 4, '.', '');
    }

    protected function invoiceNumber(int $creatorId, int $number): string
    {
        $settings = Utility::settingsById($creatorId);

        return ($settings['invoice_prefix'] ?? '#Fatura') . sprintf('%05d', $number);
    }
}
