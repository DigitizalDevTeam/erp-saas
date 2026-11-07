<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\BankReconciliationSession;
use App\Models\BankStatementLine;
use App\Models\DimensionAuditEvent;
use App\Models\Invoice;
use App\Models\TreasuryAdvance;
use App\Models\TreasuryCashFlowAlert;
use App\Models\TreasuryCashSession;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryForecastItem;
use App\Models\TreasuryMissingMovement;
use App\Models\TreasuryOperation;
use App\Models\TreasuryPaymentRequest;
use App\Models\TreasuryReceipt;
use App\Models\TreasurySettlementAllocation;
use App\Models\TreasuryTransfer;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Treasury Phase 9 — dashboard, reports and audit trail (plan §9, §10).
 *
 * READ-ONLY aggregation. This service never creates a posting, a
 * journal, an operation or an entry: every figure is computed from
 * the data Phases 0–8 already wrote. Totals reconcile to their
 * source — bank movements for cash reports, journal-linked
 * operations for accounting figures — and forecast and actual are
 * always shown separately, never mixed (plan §9).
 *
 * Every query is scoped by creatorId() (CA16/RF06). Money is
 * DECIMAL(20,4), bcmath only, never float (RF04). Amounts are
 * totalled in base currency; a row keeps its original amount and
 * currency alongside the base amount so a multi-currency report
 * can be checked line by line (CA19).
 */
class TreasuryReportService
{
    public const REPORT_OPERATIONS = 'operations';
    public const REPORT_RECEIPTS = 'receipts';
    public const REPORT_PAYMENTS = 'payments';
    public const REPORT_ADVANCES = 'advances';
    public const REPORT_TRANSFERS = 'transfers';
    public const REPORT_CASH_FLOW = 'cash-flow';
    public const REPORT_RECONCILIATION = 'reconciliation';
    public const REPORT_FX = 'fx';

    public const REPORTS = [
        self::REPORT_OPERATIONS => 'Treasury Operations',
        self::REPORT_RECEIPTS => 'Receipts',
        self::REPORT_PAYMENTS => 'Payments',
        self::REPORT_ADVANCES => 'Advances',
        self::REPORT_TRANSFERS => 'Transfers',
        self::REPORT_CASH_FLOW => 'Cash Flow — Forecast vs Actual',
        self::REPORT_RECONCILIATION => 'Reconciliation',
        self::REPORT_FX => 'Exchange Differences',
    ];

    protected TreasuryAccountService $accounts;
    protected TreasuryForecastService $forecast;
    protected TreasuryReconciliationService $reconciliation;

    public function __construct(
        ?TreasuryAccountService $accounts = null,
        ?TreasuryForecastService $forecast = null,
        ?TreasuryReconciliationService $reconciliation = null
    ) {
        $this->accounts = $accounts ?? new TreasuryAccountService();
        $this->forecast = $forecast ?? new TreasuryForecastService();
        $this->reconciliation = $reconciliation ?? new TreasuryReconciliationService();
    }

    // ------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------

    /**
     * Normalise raw request input into the filter shape every report
     * understands. Invalid dates are dropped, never guessed; a from
     * after a to is swapped so the range still means something.
     *
     * @return array{from: ?string, to: ?string, account_id: ?int, currency: ?string, state: ?string, type: ?string, search: ?string, group: string, scenario: string, days: int}
     */
    public function normalizeFilters(array $input): array
    {
        $from = $this->parseDate($input['from'] ?? null);
        $to = $this->parseDate($input['to'] ?? null);
        if ($from !== null && $to !== null && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $currency = strtoupper(trim((string) ($input['currency'] ?? '')));
        $currency = preg_match('/^[A-Z]{3}$/', $currency) ? $currency : null;

        $group = in_array($input['group'] ?? null, ['day', 'week', 'month'], true) ? $input['group'] : 'day';
        $scenario = in_array($input['scenario'] ?? null, TreasuryForecastItem::SCENARIOS, true)
            ? $input['scenario'] : TreasuryForecastItem::SCENARIO_BASELINE;

        return [
            'from' => $from,
            'to' => $to,
            'account_id' => isset($input['account_id']) && ctype_digit((string) $input['account_id'])
                ? (int) $input['account_id'] : null,
            'currency' => $currency,
            'state' => isset($input['state']) && trim((string) $input['state']) !== ''
                ? substr(trim((string) $input['state']), 0, 30) : null,
            'type' => isset($input['type']) && trim((string) $input['type']) !== ''
                ? substr(trim((string) $input['type']), 0, 30) : null,
            'search' => isset($input['search']) && trim((string) $input['search']) !== ''
                ? substr(trim((string) $input['search']), 0, 100) : null,
            'group' => $group,
            'scenario' => $scenario,
            'days' => isset($input['days']) && ctype_digit((string) $input['days'])
                ? max(1, min(365, (int) $input['days'])) : 30,
            'actor_id' => isset($input['actor_id']) && ctype_digit((string) $input['actor_id'])
                ? (int) $input['actor_id'] : null,
        ];
    }

    // ------------------------------------------------------------------
    // Dashboard (plan §9 KPIs)
    // ------------------------------------------------------------------

    /**
     * All dashboard KPIs in one array. Nothing here writes — the
     * forecast is refreshed first (idempotent, Phase 8) so the
     * 7/15/30-day figures read live data.
     *
     * @return array{base_currency: string, accounts: array, total_posted_base: string, total_available_base: string, total_reserved_base: string, expected: array, overdue_receipts: array, overdue_payments: array, pending_approvals: array, reconciliation: array, forecast_30: array, advances: array, alerts: array, funds_in_transit: array, cash_sessions_open: int}
     */
    public function dashboard(int $creatorId): array
    {
        $baseCurrency = TreasurySettings::baseCurrency($creatorId);
        $today = Carbon::today()->toDateString();

        $this->forecast->refreshForecast($creatorId);

        // Balances per account (posted − active reservations).
        $accounts = [];
        $totalPosted = '0.0000';
        $totalAvailable = '0.0000';
        $financialAccounts = TreasuryFinancialAccount::where('created_by', $creatorId)
            ->where('status', 'active')
            ->orderBy('code')
            ->get();
        foreach ($financialAccounts as $account) {
            $posted = $this->accounts->postedBalance($account);
            $available = $this->accounts->availableBalance($account);
            $totalPosted = bcadd($totalPosted, $posted, 4);
            $totalAvailable = bcadd($totalAvailable, $available, 4);
            $accounts[] = [
                'id' => $account->id,
                'code' => (string) $account->code,
                'type' => (string) $account->type,
                'currency' => (string) $account->currency,
                'holder' => $account->bankAccount ? (string) $account->bankAccount->holder_name : '',
                'posted' => $posted,
                'available' => $available,
                'reserved' => bcsub($posted, $available, 4),
            ];
        }

        // Expected receipts / payments for 7 / 15 / 30 days. Inflows come
        // from the forecast (open invoices + manual projections). The
        // Phase 8 forecast only generates outflow items from payroll, so
        // approved payment requests (concrete, dated outflows) are added
        // explicitly, bucketed by due date — otherwise the dashboard
        // would show expected payments 0 while approved requests sit
        // due. The 30-day forecast closing itself still counts only
        // forecast items (see the Phase 9 rough edges).
        $expected = [];
        $approvedRequests = TreasuryPaymentRequest::where('created_by', $creatorId)
            ->where('state', TreasuryPaymentRequest::STATE_APPROVED)
            ->get();
        foreach ([7, 15, 30] as $days) {
            $calendar = $this->forecast->forecast($creatorId, $days, TreasuryForecastItem::SCENARIO_BASELINE, 'day');
            $outflow = $calendar['totals']['outflow'];
            $horizonEnd = Carbon::today()->copy()->addDays($days - 1);
            foreach ($approvedRequests as $request) {
                $remaining = bcsub((string) $request->amount_base, (string) ($request->paid_amount_base ?? '0'), 4);
                if (bccomp($remaining, '0', 4) <= 0) {
                    continue;
                }
                $due = $request->due_date ?? $request->planned_date;
                if ($due === null || Carbon::parse($due->format('Y-m-d'))->lessThanOrEqualTo($horizonEnd)) {
                    $outflow = bcadd($outflow, $remaining, 4);
                }
            }
            $expected[$days] = [
                'inflow' => $calendar['totals']['inflow'],
                'outflow' => $outflow,
                'closing' => $calendar['totals']['closing'],
            ];
        }

        // Overdue receipts: open invoices past due with money owing.
        $overdueReceipts = ['count' => 0, 'amount_base' => '0.0000'];
        $openInvoices = Invoice::where('created_by', $creatorId)
            ->whereIn('status', TreasuryForecastService::OPEN_INVOICE_STATUSES)
            ->where('due_date', '<', $today)
            ->with(['items', 'payments', 'creditNote', 'salesDebitNote'])
            ->get();
        foreach ($openInvoices as $invoice) {
            $due = $this->decimalString($invoice->getDue(), 'Invoice due');
            if (bccomp($due, '0.0000', 4) > 0) {
                $overdueReceipts['count']++;
                $overdueReceipts['amount_base'] = bcadd($overdueReceipts['amount_base'], $due, 4);
            }
        }

        // Overdue payments: open requests past their due date.
        $overduePayments = ['count' => 0, 'amount_base' => '0.0000'];
        $openRequests = TreasuryPaymentRequest::where('created_by', $creatorId)
            ->whereIn('state', TreasuryPaymentRequest::OPEN_STATES)
            ->where('due_date', '<', $today)
            ->get();
        foreach ($openRequests as $request) {
            $overduePayments['count']++;
            $overduePayments['amount_base'] = bcadd(
                $overduePayments['amount_base'],
                bcsub((string) $request->amount_base, (string) ($request->paid_amount_base ?? '0'), 4),
                4
            );
        }

        // Pending approvals: payment requests awaiting approval,
        // advances awaiting approval, closed cash sessions whose
        // variance still has no approver.
        $awaitingRequests = TreasuryPaymentRequest::where('created_by', $creatorId)
            ->where('state', TreasuryPaymentRequest::STATE_AWAITING_APPROVAL)
            ->get();
        $awaitingRequestAmount = '0.0000';
        foreach ($awaitingRequests as $request) {
            $awaitingRequestAmount = bcadd($awaitingRequestAmount, (string) $request->amount_base, 4);
        }
        $awaitingAdvances = TreasuryAdvance::where('created_by', $creatorId)
            ->where('state', TreasuryAdvance::STATE_PENDING_APPROVAL)
            ->get();
        $awaitingAdvanceAmount = '0.0000';
        foreach ($awaitingAdvances as $advance) {
            $awaitingAdvanceAmount = bcadd($awaitingAdvanceAmount, (string) $advance->amount_requested_base, 4);
        }
        $pendingVariances = TreasuryCashSession::where('created_by', $creatorId)
            ->where('state', TreasuryCashSession::STATE_CLOSED)
            ->where('variance_amount', '!=', 0)
            ->whereNull('variance_approved_by')
            ->count();

        // Reconciliation state: latest session per bank account.
        $reconciliation = $this->reconciliationState($creatorId);

        // 30-day forecast (baseline, by day) + threshold flag.
        $forecast30 = $this->forecast->forecast($creatorId, 30, TreasuryForecastItem::SCENARIO_BASELINE, 'day');

        // Advances outstanding, grouped by recipient.
        $advances = $this->advancesOutstanding($creatorId, $today);

        // Alerts summary: active alerts by type.
        $alertRows = TreasuryCashFlowAlert::where('created_by', $creatorId)
            ->where('state', TreasuryCashFlowAlert::STATE_ACTIVE)
            ->select('type', DB::raw('COUNT(*) as total'))
            ->groupBy('type')
            ->pluck('total', 'type');
        $alerts = [
            'by_type' => $alertRows->all(),
            'total' => (int) $alertRows->sum(),
            'critical' => (int) TreasuryCashFlowAlert::where('created_by', $creatorId)
                ->where('state', TreasuryCashFlowAlert::STATE_ACTIVE)
                ->where('severity', TreasuryCashFlowAlert::SEVERITY_CRITICAL)
                ->count(),
        ];

        // Funds in transit + open cash sessions.
        $inTransit = TreasuryTransfer::where('created_by', $creatorId)
            ->where('state', TreasuryTransfer::STATE_IN_TRANSIT)
            ->get();
        $inTransitAmount = '0.0000';
        foreach ($inTransit as $transfer) {
            $inTransitAmount = bcadd($inTransitAmount, (string) $transfer->amount_debited_base, 4);
        }

        return [
            'base_currency' => $baseCurrency,
            'accounts' => $accounts,
            'total_posted_base' => $totalPosted,
            'total_available_base' => $totalAvailable,
            'total_reserved_base' => bcsub($totalPosted, $totalAvailable, 4),
            'expected' => $expected,
            'overdue_receipts' => $overdueReceipts,
            'overdue_payments' => $overduePayments,
            'pending_approvals' => [
                'payment_requests' => ['count' => $awaitingRequests->count(), 'amount_base' => $awaitingRequestAmount],
                'advances' => ['count' => $awaitingAdvances->count(), 'amount_base' => $awaitingAdvanceAmount],
                'cash_variances' => $pendingVariances,
            ],
            'reconciliation' => $reconciliation,
            'forecast_30' => [
                'opening_balance' => $forecast30['opening_balance'],
                'inflow' => $forecast30['totals']['inflow'],
                'outflow' => $forecast30['totals']['outflow'],
                'closing' => $forecast30['totals']['closing'],
                'threshold' => $forecast30['threshold'],
                'first_day_below_threshold' => $forecast30['first_day_below_threshold'],
            ],
            'advances' => $advances,
            'alerts' => $alerts,
            'funds_in_transit' => ['count' => $inTransit->count(), 'amount_base' => $inTransitAmount],
            'cash_sessions_open' => TreasuryCashSession::where('created_by', $creatorId)
                ->where('state', TreasuryCashSession::STATE_OPEN)
                ->count(),
        ];
    }

    /** Latest reconciliation session per bank account, with its close figures. */
    protected function reconciliationState(int $creatorId): array
    {
        $sessions = BankReconciliationSession::where('created_by', $creatorId)
            ->orderByDesc('id')
            ->get()
            ->unique('bank_account_id')
            ->values();

        $bankLabels = BankAccount::whereIn('id', $sessions->pluck('bank_account_id')->all())
            ->pluck('holder_name', 'id');

        $rows = [];
        foreach ($sessions as $session) {
            $summary = $this->reconciliation->sessionSummary($session);
            $totalLines = BankStatementLine::where('session_id', $session->id)->count();
            $matchedLines = $totalLines - (int) $summary['unmatched_lines'];
            $rows[] = [
                'session_id' => $session->id,
                'bank_account_id' => $session->bank_account_id,
                'bank' => (string) ($bankLabels[$session->bank_account_id] ?? ('#' . $session->bank_account_id)),
                'status' => (string) $session->status,
                'period_from' => $session->period_from ? Carbon::parse($session->period_from)->format('Y-m-d') : '',
                'period_to' => $session->period_to ? Carbon::parse($session->period_to)->format('Y-m-d') : '',
                'statement_balance' => $summary['statement_balance'],
                'book_balance' => $summary['book_balance'],
                'outstanding_items' => $summary['outstanding_items'],
                'explained_differences' => $summary['explained_differences'],
                'difference' => $summary['difference'],
                'total_lines' => $totalLines,
                'matched_lines' => $matchedLines,
                'unmatched_lines' => (int) $summary['unmatched_lines'],
                'matched_pct' => $totalLines > 0 ? round($matchedLines * 100 / $totalLines, 1) : null,
            ];
        }

        return $rows;
    }

    /** Advances with money still outstanding, grouped by recipient. */
    protected function advancesOutstanding(int $creatorId, string $today): array
    {
        $open = TreasuryAdvance::where('created_by', $creatorId)
            ->whereIn('state', TreasuryAdvance::DISBURSED_STATES)
            ->where('amount_outstanding_base', '>', 0)
            ->get();

        $byRecipient = [];
        $total = '0.0000';
        $overdueCount = 0;
        foreach ($open as $advance) {
            $total = bcadd($total, (string) $advance->amount_outstanding_base, 4);
            $overdue = $advance->due_date !== null && (string) $advance->due_date < $today;
            if ($overdue) {
                $overdueCount++;
            }
            $key = $advance->recipient_type . '|' . ($advance->recipient_name ?? ('#' . $advance->recipient_id));
            if (! isset($byRecipient[$key])) {
                $byRecipient[$key] = [
                    'recipient_type' => (string) $advance->recipient_type,
                    'recipient' => (string) ($advance->recipient_name ?? ('#' . $advance->recipient_id)),
                    'count' => 0,
                    'outstanding_base' => '0.0000',
                    'overdue' => 0,
                ];
            }
            $byRecipient[$key]['count']++;
            $byRecipient[$key]['outstanding_base'] = bcadd(
                $byRecipient[$key]['outstanding_base'],
                (string) $advance->amount_outstanding_base,
                4
            );
            if ($overdue) {
                $byRecipient[$key]['overdue']++;
            }
        }

        return [
            'count' => $open->count(),
            'total_base' => $total,
            'overdue_count' => $overdueCount,
            'by_recipient' => array_values($byRecipient),
        ];
    }

    // ------------------------------------------------------------------
    // Reports
    // ------------------------------------------------------------------

    /**
     * Build one report. Every report returns the same shape so the
     * screen, the CSV, the XLSX and the PDF can never disagree:
     * title, headings, rows (plain arrays, display strings) and
     * totals (label => formatted value).
     *
     * @return array{key: string, title: string, headings: array<int, string>, rows: array<int, array<int, string>>, totals: array<string, string>, description: string}
     */
    public function report(int $creatorId, string $key, array $filters): array
    {
        if (! isset(self::REPORTS[$key])) {
            throw new \InvalidArgumentException('Unknown Treasury report: ' . $key);
        }

        return match ($key) {
            self::REPORT_OPERATIONS => $this->operationsReport($creatorId, $filters),
            self::REPORT_RECEIPTS => $this->receiptsReport($creatorId, $filters),
            self::REPORT_PAYMENTS => $this->paymentsReport($creatorId, $filters),
            self::REPORT_ADVANCES => $this->advancesReport($creatorId, $filters),
            self::REPORT_TRANSFERS => $this->transfersReport($creatorId, $filters),
            self::REPORT_CASH_FLOW => $this->cashFlowReport($creatorId, $filters),
            self::REPORT_RECONCILIATION => $this->reconciliationReport($creatorId, $filters),
            self::REPORT_FX => $this->fxReport($creatorId, $filters),
        };
    }

    /** One row per treasury entry (the bank movement) — the rows a cash report must reconcile to. */
    protected function operationsReport(int $creatorId, array $filters): array
    {
        $query = DB::table('treasury_entries')
            ->join('treasury_operations', 'treasury_operations.id', '=', 'treasury_entries.operation_id')
            ->where('treasury_operations.created_by', $creatorId)
            ->select(
                'treasury_operations.posting_date',
                'treasury_operations.reference',
                'treasury_operations.type',
                'treasury_operations.state',
                'treasury_operations.counterparty_type',
                'treasury_operations.counterparty_id',
                'treasury_operations.currency',
                'treasury_entries.financial_account_id',
                'treasury_entries.direction',
                'treasury_entries.amount_original',
                'treasury_entries.amount_base'
            )
            ->orderBy('treasury_operations.posting_date')
            ->orderBy('treasury_entries.id');

        $this->applyDateFilter($query, 'treasury_operations.posting_date', $filters);
        if ($filters['account_id'] !== null) {
            $query->where('treasury_entries.financial_account_id', $filters['account_id']);
        }
        if ($filters['currency'] !== null) {
            $query->where('treasury_operations.currency', $filters['currency']);
        }
        if ($filters['state'] !== null) {
            $query->where('treasury_operations.state', $filters['state']);
        }
        if ($filters['type'] !== null) {
            $query->where('treasury_operations.type', $filters['type']);
        }
        if ($filters['search'] !== null) {
            $query->where('treasury_operations.reference', 'like', '%' . $filters['search'] . '%');
        }

        $accountLabels = $this->accountLabels($creatorId);
        $rows = [];
        $inflow = '0.0000';
        $outflow = '0.0000';
        foreach ($query->get() as $entry) {
            if ($entry->direction === 'outflow') {
                $outflow = bcadd($outflow, (string) $entry->amount_base, 4);
            } else {
                $inflow = bcadd($inflow, (string) $entry->amount_base, 4);
            }
            $rows[] = [
                (string) ($entry->posting_date ?? ''),
                (string) ($entry->reference ?? ''),
                (string) $entry->type,
                $accountLabels[(int) $entry->financial_account_id] ?? ('#' . $entry->financial_account_id),
                $entry->counterparty_type !== null
                    ? (string) $entry->counterparty_type . ' #' . $entry->counterparty_id
                    : '',
                (string) $entry->direction,
                $this->money((string) $entry->amount_original) . ' ' . (string) $entry->currency,
                $this->money((string) $entry->amount_base),
                (string) $entry->state,
            ];
        }

        return [
            'key' => self::REPORT_OPERATIONS,
            'title' => self::REPORTS[self::REPORT_OPERATIONS],
            'headings' => ['Date', 'Reference', 'Type', 'Account', 'Counterparty', 'Direction', 'Amount', 'Amount (base)', 'State'],
            'rows' => $rows,
            'totals' => [
                'Inflows (base)' => $this->money($inflow),
                'Outflows (base)' => $this->money($outflow),
                'Net (base)' => $this->money(bcsub($inflow, $outflow, 4)),
                'Movements' => (string) count($rows),
            ],
            'description' => 'One row per confirmed bank movement. Totals reconcile to treasury entries; a reversal and its original net to zero.',
        ];
    }

    /** Receipts with fees, withholding, discount, allocated and unapplied credit. */
    protected function receiptsReport(int $creatorId, array $filters): array
    {
        $query = TreasuryReceipt::where('created_by', $creatorId)
            ->with('operation')
            ->orderByDesc('id');

        if ($filters['state'] !== null) {
            $query->where('state', $filters['state']);
        }
        if ($filters['type'] !== null) {
            $query->where('method', $filters['type']);
        }
        if ($filters['search'] !== null) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('receipt_number', 'like', '%' . $search . '%')
                    ->orWhere('external_transaction_id', 'like', '%' . $search . '%');
            });
        }

        $receipts = $query->get();

        // Allocations per receipt, bulk-loaded (active only).
        $allocated = [];
        if ($receipts->isNotEmpty()) {
            $allocated = TreasurySettlementAllocation::whereIn('receipt_id', $receipts->pluck('id')->all())
                ->where('state', TreasurySettlementAllocation::STATE_ACTIVE)
                ->select('receipt_id', DB::raw('SUM(allocated_amount_base) as total'))
                ->groupBy('receipt_id')
                ->pluck('total', 'receipt_id')
                ->all();
        }

        $customerNames = [];
        $customerIds = $receipts->pluck('customer_id')->filter()->unique()->all();
        if ($customerIds !== []) {
            $customerNames = DB::table('customers')->whereIn('id', $customerIds)->pluck('name', 'id')->all();
        }

        $rows = [];
        $gross = '0.0000';
        $fees = '0.0000';
        $withholding = '0.0000';
        $discount = '0.0000';
        $net = '0.0000';
        $allocatedTotal = '0.0000';
        $unappliedTotal = '0.0000';
        foreach ($receipts as $receipt) {
            $operation = $receipt->operation;
            $date = $operation ? (string) ($operation->posting_date ?? '') : '';
            if ($filters['from'] !== null && $date !== '' && $date < $filters['from']) {
                continue;
            }
            if ($filters['to'] !== null && $date !== '' && $date > $filters['to']) {
                continue;
            }
            if ($filters['currency'] !== null && $operation && (string) $operation->currency !== $filters['currency']) {
                continue;
            }

            $receiptGross = $operation ? (string) $operation->amount_original : '0.0000';
            $receiptNet = $receipt->netCashAmount();
            $receiptAllocated = (string) ($allocated[$receipt->id] ?? '0');
            $receiptUnapplied = bcsub($receipt->settlementValueBase(), $receiptAllocated, 4);
            if (bccomp($receiptUnapplied, '0.0000', 4) < 0) {
                $receiptUnapplied = '0.0000';
            }

            $gross = bcadd($gross, $receiptGross, 4);
            $fees = bcadd($fees, (string) ($receipt->fees ?? '0'), 4);
            $withholding = bcadd($withholding, (string) ($receipt->withholding ?? '0'), 4);
            $discount = bcadd($discount, (string) ($receipt->discount ?? '0'), 4);
            $net = bcadd($net, $receiptNet, 4);
            $allocatedTotal = bcadd($allocatedTotal, $receiptAllocated, 4);
            $unappliedTotal = bcadd($unappliedTotal, $receiptUnapplied, 4);

            $rows[] = [
                $date,
                (string) ($receipt->receipt_number ?? ($operation ? (string) $operation->reference : '')),
                (string) ($customerNames[$receipt->customer_id] ?? ($receipt->customer_id ? 'Customer #' . $receipt->customer_id : '')),
                $receipt->methodLabel(),
                $this->money($receiptGross) . ' ' . ($operation ? (string) $operation->currency : ''),
                $this->money((string) ($receipt->fees ?? '0')),
                $this->money((string) ($receipt->withholding ?? '0')),
                $this->money((string) ($receipt->discount ?? '0')),
                $this->money($receiptNet),
                $this->money($receiptAllocated),
                $this->money($receiptUnapplied),
                (string) $receipt->state,
            ];
        }

        return [
            'key' => self::REPORT_RECEIPTS,
            'title' => self::REPORTS[self::REPORT_RECEIPTS],
            'headings' => ['Date', 'Receipt', 'Customer', 'Method', 'Gross', 'Fees', 'Withholding', 'Discount', 'Net cash', 'Allocated (base)', 'Unapplied (base)', 'State'],
            'rows' => $rows,
            'totals' => [
                'Gross' => $this->money($gross),
                'Fees' => $this->money($fees),
                'Withholding' => $this->money($withholding),
                'Discount' => $this->money($discount),
                'Net cash' => $this->money($net),
                'Allocated (base)' => $this->money($allocatedTotal),
                'Unapplied (base)' => $this->money($unappliedTotal),
                'Receipts' => (string) count($rows),
            ],
            'description' => 'Gross is what the customer paid; net cash is gross minus fees and withholding. Allocated is the active settlement total; unapplied is settlement value minus allocated.',
        ];
    }

    /** Payment requests with attempts, idempotency keys and remaining amounts. */
    protected function paymentsReport(int $creatorId, array $filters): array
    {
        $query = TreasuryPaymentRequest::where('created_by', $creatorId)
            ->with('operation')
            ->orderByDesc('id');

        $this->applyDateFilter($query, 'due_date', $filters);
        if ($filters['currency'] !== null) {
            $query->where('currency', $filters['currency']);
        }
        if ($filters['state'] !== null) {
            $query->where('state', $filters['state']);
        }
        if ($filters['search'] !== null) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('beneficiary_name', 'like', '%' . $search . '%')
                    ->orWhere('client_reference', 'like', '%' . $search . '%');
            });
        }

        $requests = $query->get();

        // Attempts per request, bulk-loaded (latest first).
        $attemptsByRequest = [];
        if ($requests->isNotEmpty()) {
            $attempts = DB::table('treasury_payment_attempts')
                ->whereIn('payment_request_id', $requests->pluck('id')->all())
                ->orderByDesc('id')
                ->get();
            foreach ($attempts as $attempt) {
                $attemptsByRequest[(int) $attempt->payment_request_id][] = $attempt;
            }
        }

        $rows = [];
        $requested = '0.0000';
        $paid = '0.0000';
        $remaining = '0.0000';
        foreach ($requests as $request) {
            $attempts = $attemptsByRequest[$request->id] ?? [];
            $latest = $attempts[0] ?? null;
            $requestPaid = (string) ($request->paid_amount_base ?? '0');
            $requestRemaining = bcsub((string) $request->amount_base, $requestPaid, 4);

            $requested = bcadd($requested, (string) $request->amount_base, 4);
            $paid = bcadd($paid, $requestPaid, 4);
            $remaining = bcadd($remaining, $requestRemaining, 4);

            $rows[] = [
                (string) ($request->due_date ?? ''),
                (string) ($request->client_reference ?? ($request->operation ? (string) $request->operation->reference : '')),
                (string) ($request->beneficiary_name ?? '') !== ''
                    ? (string) $request->beneficiary_name . ' (' . (string) $request->beneficiary_type . ')'
                    : (string) $request->beneficiary_type,
                $this->money((string) $request->amount_original) . ' ' . (string) $request->currency,
                $this->money((string) $request->amount_base),
                $this->money($requestPaid),
                $this->money($requestRemaining),
                (string) $request->state,
                (string) count($attempts),
                $latest ? (string) $latest->state : '',
                $latest ? (string) ($latest->idempotency_key ?? '') : '',
            ];
        }

        return [
            'key' => self::REPORT_PAYMENTS,
            'title' => self::REPORTS[self::REPORT_PAYMENTS],
            'headings' => ['Due date', 'Reference', 'Beneficiary', 'Amount', 'Amount (base)', 'Paid (base)', 'Remaining (base)', 'State', 'Attempts', 'Latest attempt', 'Idempotency key'],
            'rows' => $rows,
            'totals' => [
                'Requested (base)' => $this->money($requested),
                'Paid (base)' => $this->money($paid),
                'Remaining (base)' => $this->money($remaining),
                'Requests' => (string) count($rows),
            ],
            'description' => 'Remaining is the base amount minus what was paid. A repeated idempotency key replays the original result — it never posts a second movement (CA20).',
        ];
    }

    /** Advances with substantiation, returns and pending reimbursements. */
    protected function advancesReport(int $creatorId, array $filters): array
    {
        $query = TreasuryAdvance::where('created_by', $creatorId)->orderByDesc('id');

        $this->applyDateFilter($query, 'due_date', $filters);
        if ($filters['currency'] !== null) {
            $query->where('currency', $filters['currency']);
        }
        if ($filters['state'] !== null) {
            $query->where('state', $filters['state']);
        }
        if ($filters['search'] !== null) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('recipient_name', 'like', '%' . $search . '%')
                    ->orWhere('purpose', 'like', '%' . $search . '%');
            });
        }

        $rows = [];
        $requested = '0.0000';
        $paid = '0.0000';
        $substantiated = '0.0000';
        $returned = '0.0000';
        $outstanding = '0.0000';
        $reimbursements = '0.0000';
        foreach ($query->get() as $advance) {
            $requested = bcadd($requested, (string) $advance->amount_requested_base, 4);
            $paid = bcadd($paid, (string) $advance->amount_paid_base, 4);
            $substantiated = bcadd($substantiated, (string) $advance->amount_substantiated_base, 4);
            $returned = bcadd($returned, (string) $advance->amount_returned_base, 4);
            $outstanding = bcadd($outstanding, (string) $advance->amount_outstanding_base, 4);
            if ($advance->reimbursement_state === TreasuryAdvance::REIMBURSEMENT_PENDING) {
                $reimbursements = bcadd($reimbursements, (string) ($advance->reimbursement_amount_base ?? '0'), 4);
            }

            $rows[] = [
                (string) ($advance->recipient_name ?? '') !== ''
                    ? (string) $advance->recipient_name . ' (' . (string) $advance->recipient_type . ')'
                    : (string) $advance->recipient_type,
                (string) ($advance->purpose ?? ''),
                $this->money((string) $advance->amount_requested_base),
                $this->money((string) $advance->amount_paid_base),
                $this->money((string) $advance->amount_substantiated_base),
                $this->money((string) $advance->amount_returned_base),
                $this->money((string) $advance->amount_outstanding_base),
                $this->money((string) ($advance->reimbursement_amount_base ?? '0')) . ' (' . (string) $advance->reimbursement_state . ')',
                (string) ($advance->due_date ?? ''),
                $advance->isOverdue() ? 'overdue' : '',
                (string) $advance->state,
            ];
        }

        return [
            'key' => self::REPORT_ADVANCES,
            'title' => self::REPORTS[self::REPORT_ADVANCES],
            'headings' => ['Recipient', 'Purpose', 'Requested (base)', 'Paid (base)', 'Substantiated (base)', 'Returned (base)', 'Outstanding (base)', 'Reimbursement (base)', 'Due date', 'Overdue', 'State'],
            'rows' => $rows,
            'totals' => [
                'Requested (base)' => $this->money($requested),
                'Paid (base)' => $this->money($paid),
                'Substantiated (base)' => $this->money($substantiated),
                'Returned (base)' => $this->money($returned),
                'Outstanding (base)' => $this->money($outstanding),
                'Reimbursements pending (base)' => $this->money($reimbursements),
                'Advances' => (string) count($rows),
            ],
            'description' => 'Outstanding is paid minus substantiated minus returned. A reimbursement is a separate payable — it never counts twice (CA10).',
        ];
    }

    /** Transfers with FX rates, fees and exchange differences. */
    protected function transfersReport(int $creatorId, array $filters): array
    {
        $query = TreasuryTransfer::where('created_by', $creatorId)
            ->with('operation')
            ->orderByDesc('id');

        $this->applyDateFilter($query, 'created_at', $filters);
        if ($filters['state'] !== null) {
            $query->where('state', $filters['state']);
        }
        if ($filters['currency'] !== null) {
            $query->where(function ($q) use ($filters) {
                $q->where('source_currency', $filters['currency'])
                    ->orWhere('destination_currency', $filters['currency']);
            });
        }
        if ($filters['account_id'] !== null) {
            $accountId = $filters['account_id'];
            $query->where(function ($q) use ($accountId) {
                $q->where('source_account_id', $accountId)
                    ->orWhere('destination_account_id', $accountId)
                    ->orWhere('transit_account_id', $accountId);
            });
        }

        $accountLabels = $this->accountLabels($creatorId);
        $rows = [];
        $debited = '0.0000';
        $credited = '0.0000';
        $fees = '0.0000';
        $losses = '0.0000';
        $gains = '0.0000';
        foreach ($query->get() as $transfer) {
            $difference = (string) ($transfer->exchange_difference_base ?? '0');
            // Difference = debited base − credited base: positive is a
            // loss (account 694), negative is a gain (account 784).
            if (bccomp($difference, '0.0000', 4) > 0) {
                $losses = bcadd($losses, $difference, 4);
            } elseif (bccomp($difference, '0.0000', 4) < 0) {
                $gains = bcadd($gains, bcsub('0.0000', $difference, 4), 4);
            }

            $debited = bcadd($debited, (string) $transfer->amount_debited_base, 4);
            $credited = bcadd($credited, (string) $transfer->amount_credited_base, 4);
            $fees = bcadd($fees, (string) ($transfer->fee_base ?? '0'), 4);

            $rows[] = [
                $transfer->created_at ? $transfer->created_at->toDateString() : '',
                (string) ($transfer->operation ? (string) $transfer->operation->reference : ''),
                ($accountLabels[(int) $transfer->source_account_id] ?? ('#' . $transfer->source_account_id))
                    . ' → ' . ($accountLabels[(int) $transfer->destination_account_id] ?? ('#' . $transfer->destination_account_id)),
                (string) $transfer->source_currency . ' → ' . (string) $transfer->destination_currency,
                $this->money((string) $transfer->amount_debited) . ' ' . (string) $transfer->source_currency,
                $this->money((string) $transfer->amount_credited) . ' ' . (string) $transfer->destination_currency,
                (string) ($transfer->exchange_rate ?? ''),
                $this->money((string) ($transfer->fee_amount ?? '0')),
                $this->money($difference),
                bccomp($difference, '0.0000', 4) > 0 ? 'loss' : (bccomp($difference, '0.0000', 4) < 0 ? 'gain' : '—'),
                (string) $transfer->timing,
                (string) $transfer->state,
            ];
        }

        return [
            'key' => self::REPORT_TRANSFERS,
            'title' => self::REPORTS[self::REPORT_TRANSFERS],
            'headings' => ['Date', 'Reference', 'Source → Destination', 'Currencies', 'Debited', 'Credited', 'Rate', 'Fee', 'Exchange diff. (base)', 'Kind', 'Timing', 'State'],
            'rows' => $rows,
            'totals' => [
                'Debited (base)' => $this->money($debited),
                'Credited (base)' => $this->money($credited),
                'Fees (base)' => $this->money($fees),
                'Exchange losses (base)' => $this->money($losses),
                'Exchange gains (base)' => $this->money($gains),
                'Net difference (base)' => $this->money(bcsub($losses, $gains, 4)),
                'Transfers' => (string) count($rows),
            ],
            'description' => 'A delayed transfer sits in its transit account until cleared — it is never counted in source and destination at the same time (CA08).',
        ];
    }

    /** Cash flow: forecast vs actual, grouped by day/week/month, per scenario. */
    protected function cashFlowReport(int $creatorId, array $filters): array
    {
        $days = $filters['days'];
        $group = $filters['group'];
        $scenario = $filters['scenario'];
        $from = $filters['from'] !== null ? Carbon::parse($filters['from'])->startOfDay() : null;

        $this->forecast->refreshForecast($creatorId);
        $calendar = $this->forecast->forecast($creatorId, $days, $scenario, $group, $from);
        $to = Carbon::parse($calendar['to'])->endOfDay();

        // Actuals bucketed into the same periods as the forecast.
        $actuals = $this->forecast->actuals($creatorId, Carbon::parse($calendar['from'])->startOfDay(), $to);
        $actualByDay = [];
        foreach ($actuals['items'] as $item) {
            $key = $item['date'];
            if (! isset($actualByDay[$key])) {
                $actualByDay[$key] = ['inflow' => '0.0000', 'outflow' => '0.0000'];
            }
            if ($item['direction'] === 'outflow') {
                $actualByDay[$key]['outflow'] = bcadd($actualByDay[$key]['outflow'], (string) $item['amount_base'], 4);
            } else {
                $actualByDay[$key]['inflow'] = bcadd($actualByDay[$key]['inflow'], (string) $item['amount_base'], 4);
            }
        }

        $rows = [];
        foreach ($calendar['periods'] as $period) {
            $actualInflow = '0.0000';
            $actualOutflow = '0.0000';
            $cursor = Carbon::parse($period['key']);
            $periodEnd = $group === 'day'
                ? $cursor->copy()
                : ($group === 'week' ? $cursor->copy()->endOfWeek(Carbon::SUNDAY) : $cursor->copy()->endOfMonth());
            for ($day = $cursor->copy(); $day->lessThanOrEqualTo($periodEnd); $day->addDay()) {
                $key = $day->toDateString();
                if (isset($actualByDay[$key])) {
                    $actualInflow = bcadd($actualInflow, $actualByDay[$key]['inflow'], 4);
                    $actualOutflow = bcadd($actualOutflow, $actualByDay[$key]['outflow'], 4);
                }
            }

            $rows[] = [
                (string) $period['label'],
                $this->money((string) $period['inflow']),
                $this->money((string) $period['outflow']),
                $this->money((string) $period['closing']),
                $this->money($actualInflow),
                $this->money($actualOutflow),
                $this->money(bcsub(bcsub($actualInflow, $actualOutflow, 4), '0.0000', 4)),
                $period['below_threshold'] ? 'below threshold' : '',
            ];
        }

        return [
            'key' => self::REPORT_CASH_FLOW,
            'title' => self::REPORTS[self::REPORT_CASH_FLOW],
            'headings' => ['Period', 'Forecast inflow', 'Forecast outflow', 'Forecast closing', 'Actual inflow', 'Actual outflow', 'Actual net', 'Flag'],
            'rows' => $rows,
            'totals' => [
                'Opening balance' => $this->money($calendar['opening_balance']),
                'Forecast inflow' => $this->money($calendar['totals']['inflow']),
                'Forecast outflow' => $this->money($calendar['totals']['outflow']),
                'Forecast closing' => $this->money($calendar['totals']['closing']),
                'Actual inflow' => $this->money($actuals['inflow']),
                'Actual outflow' => $this->money($actuals['outflow']),
                'First day below threshold' => $calendar['first_day_below_threshold'] !== null
                    ? (string) $calendar['first_day_below_threshold']['date'] : '—',
                'Scenario' => $scenario,
            ],
            'description' => 'Forecast and actual are shown separately, never mixed. Planned amounts and issued amounts for the same occurrence are kept apart so nothing is double-counted.',
        ];
    }

    /** Reconciliation sessions with close figures, match rates and missing movements. */
    protected function reconciliationReport(int $creatorId, array $filters): array
    {
        $query = BankReconciliationSession::where('created_by', $creatorId)->orderByDesc('id');

        $this->applyDateFilter($query, 'period_from', $filters);
        if ($filters['account_id'] !== null) {
            $query->where('bank_account_id', $filters['account_id']);
        }
        if ($filters['state'] !== null) {
            $query->where('status', $filters['state']);
        }

        $sessions = $query->get();
        $bankLabels = BankAccount::whereIn('id', $sessions->pluck('bank_account_id')->all())
            ->pluck('holder_name', 'id');

        $rows = [];
        $statementTotal = '0.0000';
        $bookTotal = '0.0000';
        $differenceTotal = '0.0000';
        foreach ($sessions as $session) {
            $summary = $this->reconciliation->sessionSummary($session);
            $totalLines = BankStatementLine::where('session_id', $session->id)->count();
            $matchedLines = $totalLines - (int) $summary['unmatched_lines'];
            $missing = TreasuryMissingMovement::where('session_id', $session->id)
                ->select('state', DB::raw('COUNT(*) as total'))
                ->groupBy('state')
                ->pluck('total', 'state')
                ->all();

            $statementTotal = bcadd($statementTotal, $summary['statement_balance'], 4);
            $bookTotal = bcadd($bookTotal, $summary['book_balance'], 4);
            $differenceTotal = bcadd($differenceTotal, $summary['difference'], 4);

            $rows[] = [
                (string) ($bankLabels[$session->bank_account_id] ?? ('#' . $session->bank_account_id)),
                ($session->period_from ? Carbon::parse($session->period_from)->format('Y-m-d') : '') . ' → ' . ($session->period_to ? Carbon::parse($session->period_to)->format('Y-m-d') : ''),
                (string) $session->status,
                $this->money($summary['statement_balance']),
                $this->money($summary['book_balance']),
                $this->money($summary['outstanding_items']),
                $this->money($summary['explained_differences']),
                $this->money($summary['difference']),
                (string) $totalLines,
                (string) $matchedLines,
                (string) $summary['unmatched_lines'],
                (string) ($missing[TreasuryMissingMovement::STATE_PROPOSED] ?? 0),
                (string) ($missing[TreasuryMissingMovement::STATE_CONFIRMED] ?? 0),
            ];
        }

        return [
            'key' => self::REPORT_RECONCILIATION,
            'title' => self::REPORTS[self::REPORT_RECONCILIATION],
            'headings' => ['Bank', 'Period', 'Status', 'Statement', 'Book', 'Outstanding', 'Explained', 'Difference', 'Lines', 'Matched', 'Unmatched', 'Missing proposed', 'Missing confirmed'],
            'rows' => $rows,
            'totals' => [
                'Statement total' => $this->money($statementTotal),
                'Book total' => $this->money($bookTotal),
                'Difference total' => $this->money($differenceTotal),
                'Sessions' => (string) count($rows),
            ],
            'description' => 'Difference is statement minus book minus outstanding. A session closes only when the difference is zero (CA11/CA12).',
        ];
    }

    /**
     * Exchange differences: transfers across currencies. A positive
     * difference (debited base − credited base) is a loss (account
     * 694); a negative one is a gain (account 784). Foreign-currency
     * receipts and payments convert at their stored rate and post no
     * difference line, so they are not listed here (CA19).
     */
    protected function fxReport(int $creatorId, array $filters): array
    {
        $query = TreasuryTransfer::where('created_by', $creatorId)
            ->with('operation')
            ->where(function ($q) {
                $q->whereColumn('source_currency', '!=', 'destination_currency')
                    ->orWhere('exchange_difference_base', '!=', 0);
            })
            ->orderByDesc('id');

        $this->applyDateFilter($query, 'created_at', $filters);
        if ($filters['currency'] !== null) {
            $query->where(function ($q) use ($filters) {
                $q->where('source_currency', $filters['currency'])
                    ->orWhere('destination_currency', $filters['currency']);
            });
        }
        if ($filters['state'] !== null) {
            $query->where('state', $filters['state']);
        }

        $rows = [];
        $losses = '0.0000';
        $gains = '0.0000';
        foreach ($query->get() as $transfer) {
            $difference = (string) ($transfer->exchange_difference_base ?? '0');
            if (bccomp($difference, '0.0000', 4) > 0) {
                $losses = bcadd($losses, $difference, 4);
            } elseif (bccomp($difference, '0.0000', 4) < 0) {
                $gains = bcadd($gains, bcsub('0.0000', $difference, 4), 4);
            }

            $rows[] = [
                $transfer->created_at ? $transfer->created_at->toDateString() : '',
                (string) ($transfer->operation ? (string) $transfer->operation->reference : ''),
                (string) $transfer->source_currency . ' → ' . (string) $transfer->destination_currency,
                (string) ($transfer->exchange_rate ?? ''),
                $this->money((string) $transfer->amount_debited) . ' ' . (string) $transfer->source_currency,
                $this->money((string) $transfer->amount_credited) . ' ' . (string) $transfer->destination_currency,
                $this->money((string) $transfer->amount_debited_base),
                $this->money((string) $transfer->amount_credited_base),
                $this->money($difference),
                bccomp($difference, '0.0000', 4) > 0 ? 'loss' : (bccomp($difference, '0.0000', 4) < 0 ? 'gain' : '—'),
                (string) $transfer->state,
            ];
        }

        return [
            'key' => self::REPORT_FX,
            'title' => self::REPORTS[self::REPORT_FX],
            'headings' => ['Date', 'Reference', 'Pair', 'Rate', 'Debited', 'Credited', 'Debited (base)', 'Credited (base)', 'Difference (base)', 'Kind', 'State'],
            'rows' => $rows,
            'totals' => [
                'Exchange losses (base)' => $this->money($losses),
                'Exchange gains (base)' => $this->money($gains),
                'Net difference (base)' => $this->money(bcsub($losses, $gains, 4)),
                'Transfers' => (string) count($rows),
            ],
            'description' => 'Only transfers post an exchange-difference line. Historical rates are preserved and conversion happens before currencies are aggregated (CA19).',
        ];
    }

    // ------------------------------------------------------------------
    // Audit trail
    // ------------------------------------------------------------------

    /**
     * Full Treasury audit trail. Every Treasury service writes a
     * DimensionAuditEvent row for every state change (create, submit,
     * approve, confirm, reverse, close, reopen …), so this one table
     * is the complete, append-only record — no derived rows, no
     * double-counting. Filterable by entity, action, actor and date;
     * company-scoped, newest first.
     *
     * @return array{paginator: LengthAwarePaginator, actors: array<int, string>, entity_types: array<int, string>, actions: array<int, string>}
     */
    public function auditTrail(int $creatorId, array $filters, int $page = 1, int $perPage = 25): array
    {
        $query = DimensionAuditEvent::where('company_id', $creatorId)
            ->whereRaw("entity_type LIKE ? ESCAPE '\\'", ['treasury\_%']);

        if ($filters['type'] !== null) {
            $query->where('entity_type', $filters['type']);
        }
        if ($filters['state'] !== null) {
            $query->where('action', $filters['state']);
        }
        if (! empty($filters['actor_id']) && ctype_digit((string) $filters['actor_id'])) {
            $query->where('actor_id', (int) $filters['actor_id']);
        }
        if ($filters['from'] !== null) {
            $query->where('occurred_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if ($filters['to'] !== null) {
            $query->where('occurred_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        $paginator = $query->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $actors = User::whereIn('id', $paginator->getCollection()->pluck('actor_id')->filter()->unique()->all())
            ->pluck('name', 'id')
            ->all();

        $entityTypes = DimensionAuditEvent::where('company_id', $creatorId)
            ->whereRaw("entity_type LIKE ? ESCAPE '\\'", ['treasury\_%'])
            ->distinct()->orderBy('entity_type')->pluck('entity_type')->all();
        $actions = DimensionAuditEvent::where('company_id', $creatorId)
            ->whereRaw("entity_type LIKE ? ESCAPE '\\'", ['treasury\_%'])
            ->distinct()->orderBy('action')->pluck('action')->all();

        return [
            'paginator' => $paginator,
            'actors' => $actors,
            'entity_types' => $entityTypes,
            'actions' => $actions,
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** "Account code — holder" labels for the company's financial accounts, bulk-loaded. */
    protected function accountLabels(int $creatorId): array
    {
        $accounts = TreasuryFinancialAccount::where('created_by', $creatorId)->get();
        $labels = [];
        foreach ($accounts as $account) {
            $labels[$account->id] = (string) $account->code
                . ($account->bankAccount ? ' — ' . (string) $account->bankAccount->holder_name : '');
        }

        return $labels;
    }

    protected function applyDateFilter($query, string $column, array $filters): void
    {
        if ($filters['from'] !== null) {
            $query->whereDate($column, '>=', $filters['from']);
        }
        if ($filters['to'] !== null) {
            $query->whereDate($column, '<=', $filters['to']);
        }
    }

    protected function parseDate(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Display money: 2 decimals with thousands separators (display only — the 4-decimal value is what sums). */
    protected function money(string $amount): string
    {
        return number_format((float) $amount, 2);
    }

    protected function decimalString(mixed $value, string $label): string
    {
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException($label . ' must be a number.');
        }

        return number_format((float) $value, 4, '.', '');
    }
}
