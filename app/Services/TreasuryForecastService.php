<?php

namespace App\Services;

use App\Exceptions\TreasuryException;
use App\Models\BillingContract;
use App\Models\BillingContractOccurrence;
use App\Models\Invoice;
use App\Models\PaySlip;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryForecastItem;
use App\Models\TreasuryOperation;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 8 — cash-flow forecast (plan §5, §8, §10,
 * CA13, D7).
 *
 * The forecast is built from live data on every refresh:
 * - opening balance = posted balances of the active accounts
 *   (base currency, from TreasuryAccountService);
 * - open invoices (status sent / unpaid / partially paid) at
 *   their outstanding amount, expected on the due date;
 * - unpaid payslips (status 0) at net pay, expected on the last
 *   day of the salary month;
 * - manual projections and contract estimates, entered by hand
 *   and kept across refreshes.
 *
 * CA13: a contract projection is REPLACED by its issued
 * invoice — when an open invoice carries the projection's
 * expected reference, the projection is marked replaced and
 * excluded from the totals, and the invoice item carries only
 * the outstanding amount. A partial payment leaves only the
 * outstanding amount in forecast. Forecast and actual are
 * shown separately, never mixed: actuals are read from
 * confirmed treasury operations, never from forecast rows.
 *
 * Contract Management Phase 3: real contract projections now
 * come from ACTIVE billing contracts (each future pending
 * occurrence becomes one forecast item, linked by source_id =
 * occurrence id). Manual contract estimates (source_id null)
 * still exist and are labelled as estimates; real projections
 * are labelled as contract projections and are system-managed
 * (they cannot be edited or deleted by hand — they follow the
 * contract).
 *
 * Scenarios are applied at read time, never stored:
 * late_receipts shifts inflows +7 days, higher_expenses
 * multiplies outflows by 1.10, earlier_payments shifts outflows
 * −7 days. D7: horizons 7/15/30/90 (per-company setting) and
 * the below-threshold flag uses the per-company threshold
 * (default 0 — the first day the forecast goes negative).
 *
 * Money is DECIMAL(20,4), bcmath only, never float (RF04).
 * Company scope is creatorId() on every query (CA16).
 */
class TreasuryForecastService
{
    /** Sources regenerated from live data on every refresh. */
    public const SYSTEM_SOURCE_TYPES = TreasuryForecastItem::SYSTEM_SOURCE_TYPES;

    /** Invoice statuses that still owe money. */
    public const OPEN_INVOICE_STATUSES = [1, 2, 3];

    protected TreasuryAccountService $accounts;

    public function __construct(?TreasuryAccountService $accounts = null)
    {
        $this->accounts = $accounts ?? new TreasuryAccountService();
    }

    // ------------------------------------------------------------------
    // Lookup (CA16 — company scope on every action)
    // ------------------------------------------------------------------

    public function projectionForCompany(int $creatorId, int $itemId): TreasuryForecastItem
    {
        $item = TreasuryForecastItem::where('created_by', $creatorId)->find($itemId);

        if (! $item) {
            throw new TreasuryException('Forecast item not found for this company.');
        }

        return $item;
    }

    // ------------------------------------------------------------------
    // Settings (D7)
    // ------------------------------------------------------------------

    /** Forecast horizons in days, from the per-company setting. */
    public function horizons(int $creatorId): array
    {
        $raw = (string) (TreasurySettings::get($creatorId, TreasurySettings::KEY_FORECAST_HORIZONS) ?? '');

        $days = array_values(array_filter(array_map(
            fn ($part) => ctype_digit(trim($part)) && (int) trim($part) > 0 ? (int) trim($part) : null,
            explode(',', $raw)
        )));

        return $days !== [] ? $days : [7, 15, 30, 90];
    }

    /** Below-threshold flag: first day the closing balance drops under this. */
    public function threshold(int $creatorId): string
    {
        $raw = trim((string) (TreasurySettings::get($creatorId, TreasurySettings::KEY_CASHFLOW_THRESHOLD) ?? ''));

        return is_numeric($raw) && (float) $raw >= 0
            ? number_format((float) $raw, 4, '.', '')
            : '0.0000';
    }

    /** Opening balance = posted balances of the active accounts, base currency. */
    public function openingBalance(int $creatorId): string
    {
        $total = '0.0000';

        $accounts = TreasuryFinancialAccount::where('created_by', $creatorId)
            ->where('status', 'active')
            ->get();

        foreach ($accounts as $account) {
            $total = bcadd($total, $this->accounts->postedBalance($account), 4);
        }

        return $total;
    }

    // ------------------------------------------------------------------
    // Refresh — regenerate the system items, mark replacements
    // ------------------------------------------------------------------

    /**
     * Regenerate the forecast from live data as of $asOf (today by
     * default). System items are deleted and rebuilt; manual and
     * contract items persist. Contract projections whose invoice
     * has been issued are marked replaced (CA13).
     *
     * Returns counts: balances / invoices / payroll / replaced.
     */
    public function refreshForecast(int $creatorId, ?Carbon $asOf = null): array
    {
        $asOf = $asOf ? $asOf->copy()->startOfDay() : Carbon::today();

        return DB::transaction(function () use ($creatorId, $asOf) {
            TreasuryForecastItem::where('created_by', $creatorId)
                ->where('state', TreasuryForecastItem::STATE_FORECAST)
                ->whereIn('source_type', self::SYSTEM_SOURCE_TYPES)
                ->delete();

            $counts = ['balances' => 0, 'invoices' => 0, 'payroll' => 0, 'replaced' => 0];
            $baseCurrency = TreasurySettings::baseCurrency($creatorId);

            // Opening balance, one item per active account.
            $accounts = TreasuryFinancialAccount::where('created_by', $creatorId)
                ->where('status', 'active')
                ->get();

            foreach ($accounts as $account) {
                $posted = $this->accounts->postedBalance($account);
                if (bccomp($posted, '0.0000', 4) === 0) {
                    continue;
                }

                $direction = bccomp($posted, '0.0000', 4) < 0
                    ? TreasuryForecastItem::DIRECTION_OUTFLOW
                    : TreasuryForecastItem::DIRECTION_INFLOW;

                TreasuryForecastItem::create([
                    'company_id' => $creatorId,
                    'created_by' => $creatorId,
                    'source_type' => TreasuryForecastItem::SOURCE_BALANCE,
                    'source_id' => $account->id,
                    'direction' => $direction,
                    'amount' => $this->abs4($posted),
                    'amount_base' => $this->abs4($posted),
                    'currency' => $baseCurrency,
                    'expected_date' => $asOf->toDateString(),
                    'scenario' => TreasuryForecastItem::SCENARIO_BASELINE,
                    'description' => 'Opening balance — ' . $account->code,
                    'state' => TreasuryForecastItem::STATE_FORECAST,
                ]);
                $counts['balances']++;
            }

            // Open invoices at their outstanding amount (CA13: a
            // partial payment leaves only the outstanding).
            $invoices = Invoice::where('created_by', $creatorId)
                ->whereIn('status', self::OPEN_INVOICE_STATUSES)
                ->with(['items', 'payments', 'creditNote', 'salesDebitNote'])
                ->orderBy('due_date')
                ->get();

            foreach ($invoices as $invoice) {
                $due = $this->decimalString($invoice->getDue(), 'Invoice due');
                if (bccomp($due, '0.0000', 4) <= 0) {
                    continue;
                }

                TreasuryForecastItem::create([
                    'company_id' => $creatorId,
                    'created_by' => $creatorId,
                    'source_type' => TreasuryForecastItem::SOURCE_INVOICE,
                    'source_id' => $invoice->id,
                    'direction' => TreasuryForecastItem::DIRECTION_INFLOW,
                    'amount' => $due,
                    'amount_base' => $due,
                    'currency' => $baseCurrency,
                    'expected_date' => $invoice->due_date,
                    'scenario' => TreasuryForecastItem::SCENARIO_BASELINE,
                    'description' => 'Invoice ' . ($invoice->ref_number ?: '#' . $invoice->invoice_id),
                    'state' => TreasuryForecastItem::STATE_FORECAST,
                ]);
                $counts['invoices']++;
            }

            // Unpaid payslips (status 0) at net pay, expected on
            // the last day of the salary month.
            $payslips = PaySlip::where('created_by', $creatorId)
                ->where('status', 0)
                ->get();

            foreach ($payslips as $payslip) {
                $net = $this->decimalString($payslip->net_payble ?? '0', 'Payslip net pay');
                if (bccomp($net, '0.0000', 4) <= 0) {
                    continue;
                }

                $expected = $this->salaryMonthEnd((string) ($payslip->salary_month ?? ''));
                if ($expected === null) {
                    continue;
                }

                TreasuryForecastItem::create([
                    'company_id' => $creatorId,
                    'created_by' => $creatorId,
                    'source_type' => TreasuryForecastItem::SOURCE_PAYROLL,
                    'source_id' => $payslip->id,
                    'direction' => TreasuryForecastItem::DIRECTION_OUTFLOW,
                    'amount' => $net,
                    'amount_base' => $net,
                    'currency' => $baseCurrency,
                    'expected_date' => $expected,
                    'scenario' => TreasuryForecastItem::SCENARIO_BASELINE,
                    'description' => 'Payroll — ' . $payslip->salary_month,
                    'state' => TreasuryForecastItem::STATE_FORECAST,
                ]);
                $counts['payroll']++;
            }

            // CA13: a MANUAL contract estimate is replaced by its
            // issued invoice. The projection stays for audit, marked
            // with the invoice reference, and is excluded from the
            // totals; the invoice item above carries the
            // outstanding amount. Real contract projections
            // (source_id = occurrence id) are handled by
            // syncContractProjections below and skip this block.
            $projections = TreasuryForecastItem::where('created_by', $creatorId)
                ->where('state', TreasuryForecastItem::STATE_FORECAST)
                ->where('source_type', TreasuryForecastItem::SOURCE_CONTRACT)
                ->whereNull('replacement_reference')
                ->whereNull('source_id')
                ->whereNotNull('document')
                ->get();

            foreach ($projections as $projection) {
                $reference = trim((string) $projection->document);
                if ($reference === '') {
                    continue;
                }

                $issued = Invoice::where('created_by', $creatorId)
                    ->where('ref_number', $reference)
                    ->exists();

                if ($issued) {
                    $projection->replacement_reference = $reference;
                    $projection->save();
                    $counts['replaced']++;
                }
            }

            // CA13 (real contracts, Phase 3): projections from
            // ACTIVE billing contracts — one forecast item per
            // future pending occurrence, linked by source_id.
            $counts['contracts'] = $this->syncContractProjections($creatorId);

            return $counts;
        });
    }

    /**
     * Contract Management Phase 3 — real contract projections
     * (CA13). Each pending occurrence of an ACTIVE billing
     * contract becomes one forecast item (source_type contract,
     * source_id = occurrence id, inflow, amount = occurrence
     * amount — ex-tax, as stored — expected on the billing
     * date). When the occurrence is invoiced, the projection is
     * marked replaced and the invoice item carries the amount
     * instead — never double-counted. When the occurrence is
     * skipped/cancelled or the contract stops being active, the
     * projection is removed. Manual contract estimates
     * (source_id null) are never touched here.
     *
     * @return int Number of projection rows created in this run.
     */
    protected function syncContractProjections(int $creatorId): int
    {
        if (! Schema::hasTable('billing_contracts')
            || ! Schema::hasTable('billing_contract_occurrences')
        ) {
            return 0;
        }

        $kept = [];
        $created = 0;

        $contracts = BillingContract::where('created_by', $creatorId)
            ->where('state', BillingContract::STATE_ACTIVE)
            ->get();

        foreach ($contracts as $contract) {
            $occurrences = BillingContractOccurrence::where('contract_id', $contract->id)
                ->where('created_by', $creatorId)
                ->where('state', BillingContractOccurrence::STATE_PENDING)
                ->get();

            foreach ($occurrences as $occurrence) {
                $kept[] = (int) $occurrence->id;

                $amount = $this->decimalString($occurrence->amount, 'Occurrence amount');
                $rate = $this->decimalString($contract->exchange_rate ?? '1', 'Contract exchange rate');
                $amountBase = bcmul($amount, $rate, 4);
                $billingDate = Carbon::parse($occurrence->billing_date)->format('Y-m-d');

                $item = TreasuryForecastItem::firstOrCreate(
                    [
                        'created_by' => $creatorId,
                        'source_type' => TreasuryForecastItem::SOURCE_CONTRACT,
                        'source_id' => $occurrence->id,
                    ],
                    [
                        'company_id' => $creatorId,
                        'direction' => TreasuryForecastItem::DIRECTION_INFLOW,
                        'amount' => $amount,
                        'amount_base' => $amountBase,
                        'currency' => $contract->currency,
                        'expected_date' => $billingDate,
                        'scenario' => TreasuryForecastItem::SCENARIO_BASELINE,
                        'document' => $contract->reference,
                        'description' => 'Contract ' . $contract->reference . ' — '
                            . Carbon::parse($occurrence->period_start)->format('Y-m-d')
                            . ' to ' . Carbon::parse($occurrence->period_end)->format('Y-m-d'),
                        'state' => TreasuryForecastItem::STATE_FORECAST,
                    ]
                );

                if ($item->wasRecentlyCreated) {
                    $created++;
                } elseif ($item->replacement_reference === null && (
                    bccomp((string) $item->amount, $amount, 4) !== 0
                    || bccomp((string) $item->amount_base, $amountBase, 4) !== 0
                    || (string) $item->expected_date !== $billingDate
                )) {
                    // An amendment changed the amount or the date:
                    // keep the projection in step with the occurrence.
                    $item->amount = $amount;
                    $item->amount_base = $amountBase;
                    $item->expected_date = $billingDate;
                    $item->save();
                }
            }
        }

        // Occurrence-linked projections: invoiced occurrences are
        // replaced by their invoice item; skipped, cancelled or
        // no-longer-active ones are removed.
        $linked = TreasuryForecastItem::where('created_by', $creatorId)
            ->where('source_type', TreasuryForecastItem::SOURCE_CONTRACT)
            ->whereNotNull('source_id')
            ->whereNull('replacement_reference')
            ->get();

        foreach ($linked as $item) {
            $occurrence = BillingContractOccurrence::find($item->source_id);

            if ($occurrence !== null
                && $occurrence->state === BillingContractOccurrence::STATE_INVOICED
                && $occurrence->invoice_id !== null
            ) {
                $contract = BillingContract::find($occurrence->contract_id);
                $item->replacement_reference = $contract ? $contract->reference : '';
                $item->save();
                continue;
            }

            if ($occurrence === null || ! in_array((int) $item->source_id, $kept, true)) {
                $item->delete();
            }
        }

        return $created;
    }

    // ------------------------------------------------------------------
    // Calendar — forecast vs actual, grouped, per scenario
    // ------------------------------------------------------------------

    /**
     * Build the cash-flow calendar for $days days from $from.
     * Items dated before $from are due now and land in the first
     * period. Replaced projections are excluded. Actuals are read
     * from confirmed treasury operations and shown separately.
     */
    public function forecast(int $creatorId, int $days, string $scenario, string $groupBy, ?Carbon $from = null): array
    {
        $days = max(1, $days);
        $scenario = in_array($scenario, TreasuryForecastItem::SCENARIOS, true)
            ? $scenario : TreasuryForecastItem::SCENARIO_BASELINE;
        $groupBy = in_array($groupBy, ['day', 'week', 'month'], true) ? $groupBy : 'day';
        $from = $from ? $from->copy()->startOfDay() : Carbon::today();
        $to = $from->copy()->addDays($days - 1);
        $threshold = $this->threshold($creatorId);
        $baseCurrency = TreasurySettings::baseCurrency($creatorId);

        $items = $this->forecastItems($creatorId, $from, $to, $scenario);

        // Daily closings drive the first-day-below-threshold flag,
        // whatever the grouping is.
        $daily = $this->bucketItems($items, $from, $to, 'day');
        $firstBelow = null;
        $closing = $this->openingBalance($creatorId);
        foreach ($daily as $day) {
            $closing = bcadd(bcsub($closing, $day['outflow'], 4), $day['inflow'], 4);
            if ($firstBelow === null && bccomp($closing, $threshold, 4) < 0) {
                $firstBelow = ['date' => $day['key'], 'closing' => $closing];
            }
        }

        $periods = $this->bucketItems($items, $from, $to, $groupBy);
        $running = $this->openingBalance($creatorId);
        $totalInflow = '0.0000';
        $totalOutflow = '0.0000';
        foreach ($periods as &$period) {
            $running = bcadd(bcsub($running, $period['outflow'], 4), $period['inflow'], 4);
            $period['closing'] = $running;
            $period['below_threshold'] = bccomp($running, $threshold, 4) < 0;
            $totalInflow = bcadd($totalInflow, $period['inflow'], 4);
            $totalOutflow = bcadd($totalOutflow, $period['outflow'], 4);
        }
        unset($period);

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'days' => $days,
            'scenario' => $scenario,
            'group_by' => $groupBy,
            'currency' => $baseCurrency,
            'opening_balance' => $this->openingBalance($creatorId),
            'threshold' => $threshold,
            'first_day_below_threshold' => $firstBelow,
            'periods' => $periods,
            'totals' => [
                'inflow' => $totalInflow,
                'outflow' => $totalOutflow,
                'closing' => $running,
            ],
            'actuals' => $this->actuals($creatorId, $from, $to),
        ];
    }

    /** First day (and closing) the forecast drops under the threshold, or null. */
    public function firstDayBelowThreshold(int $creatorId, int $days, string $scenario): ?array
    {
        $calendar = $this->forecast($creatorId, $days, $scenario, 'day');

        return $calendar['first_day_below_threshold'];
    }

    /**
     * Confirmed movements in the window, from treasury operations
     * — the actuals side of forecast vs actual. Reversed
     * operations count too (their entry and its negated reversal
     * net to zero, same as postedBalance).
     */
    public function actuals(int $creatorId, Carbon $from, Carbon $to): array
    {
        $rows = DB::table('treasury_entries')
            ->join('treasury_operations', 'treasury_operations.id', '=', 'treasury_entries.operation_id')
            ->where('treasury_operations.created_by', $creatorId)
            ->whereIn('treasury_operations.state', [TreasuryOperation::STATE_CONFIRMED, TreasuryOperation::STATE_REVERSED])
            ->whereBetween('treasury_operations.posting_date', [$from->toDateString(), $to->toDateString()])
            ->select(
                'treasury_operations.posting_date',
                'treasury_operations.reference',
                'treasury_operations.type',
                'treasury_entries.direction',
                'treasury_entries.amount_base'
            )
            ->orderBy('treasury_operations.posting_date')
            ->orderBy('treasury_entries.id')
            ->get();

        $inflow = '0.0000';
        $outflow = '0.0000';
        $items = [];
        foreach ($rows as $row) {
            if ($row->direction === 'outflow') {
                $outflow = bcadd($outflow, (string) $row->amount_base, 4);
            } else {
                $inflow = bcadd($inflow, (string) $row->amount_base, 4);
            }
            $items[] = [
                'date' => (string) $row->posting_date,
                'reference' => (string) ($row->reference ?? ''),
                'type' => (string) $row->type,
                'direction' => (string) $row->direction,
                'amount_base' => (string) $row->amount_base,
            ];
        }

        return ['inflow' => $inflow, 'outflow' => $outflow, 'items' => $items];
    }

    // ------------------------------------------------------------------
    // Manual projections (persist across refreshes)
    // ------------------------------------------------------------------

    /**
     * Create a manual projection or a contract estimate. Contract
     * estimates are labelled as estimates until the Contract
     * Management module lands (plan risk 4) — they are never
     * presented as issued invoices.
     */
    public function createManualProjection(int $creatorId, array $data, int $actorId): TreasuryForecastItem
    {
        $sourceType = (string) ($data['source_type'] ?? '');
        if (! in_array($sourceType, [TreasuryForecastItem::SOURCE_MANUAL, TreasuryForecastItem::SOURCE_CONTRACT, TreasuryForecastItem::SOURCE_EXPENSE], true)) {
            throw new TreasuryException('Manual projections must be manual, contract or expense items.');
        }

        $direction = (string) ($data['direction'] ?? '');
        if (! in_array($direction, [TreasuryForecastItem::DIRECTION_INFLOW, TreasuryForecastItem::DIRECTION_OUTFLOW], true)) {
            throw new TreasuryException('Direction must be inflow or outflow.');
        }

        $amount = $this->decimalString($data['amount'] ?? '0', 'Amount');
        if (bccomp($amount, '0.0000', 4) <= 0) {
            throw new TreasuryException('Amount must be greater than zero.');
        }

        $expected = $this->requiredDate($data['expected_date'] ?? null, 'Expected date');
        $baseCurrency = TreasurySettings::baseCurrency($creatorId);

        return TreasuryForecastItem::create([
            'company_id' => $creatorId,
            'created_by' => $creatorId,
            'source_type' => $sourceType,
            'source_id' => null,
            'direction' => $direction,
            'amount' => $amount,
            'amount_base' => $amount,
            'currency' => $baseCurrency,
            'expected_date' => $expected,
            'scenario' => TreasuryForecastItem::SCENARIO_BASELINE,
            'probability' => isset($data['probability']) && $data['probability'] !== '' && $data['probability'] !== null
                ? $this->decimalString($data['probability'], 'Probability')
                : null,
            'document' => $sourceType === TreasuryForecastItem::SOURCE_CONTRACT
                ? substr(trim((string) ($data['document'] ?? '')), 0, 150) ?: null
                : null,
            'description' => substr(trim((string) ($data['description'] ?? '')), 0, 200) ?: null,
            'state' => TreasuryForecastItem::STATE_FORECAST,
        ]);
    }

    /** Edit a manual projection (system items are regenerated, never edited). */
    public function updateManualProjection(int $creatorId, int $itemId, array $data): TreasuryForecastItem
    {
        $item = $this->projectionForCompany($creatorId, $itemId);

        if (! in_array($item->source_type, [TreasuryForecastItem::SOURCE_MANUAL, TreasuryForecastItem::SOURCE_CONTRACT, TreasuryForecastItem::SOURCE_EXPENSE], true)) {
            throw new TreasuryException('Only manual projections can be edited — system items are regenerated on refresh.');
        }

        // A real contract projection (linked to an occurrence) is
        // system-managed: it follows the contract, never a hand edit.
        if ($item->source_type === TreasuryForecastItem::SOURCE_CONTRACT && $item->source_id !== null) {
            throw new TreasuryException('This contract projection is managed by the billing contract and cannot be edited.');
        }

        if ($item->isReplaced()) {
            throw new TreasuryException('This contract projection was replaced by its invoice and can no longer be edited.');
        }

        if (isset($data['direction'])) {
            $direction = (string) $data['direction'];
            if (! in_array($direction, [TreasuryForecastItem::DIRECTION_INFLOW, TreasuryForecastItem::DIRECTION_OUTFLOW], true)) {
                throw new TreasuryException('Direction must be inflow or outflow.');
            }
            $item->direction = $direction;
        }

        if (isset($data['amount'])) {
            $amount = $this->decimalString($data['amount'], 'Amount');
            if (bccomp($amount, '0.0000', 4) <= 0) {
                throw new TreasuryException('Amount must be greater than zero.');
            }
            $item->amount = $amount;
            $item->amount_base = $amount;
        }

        if (isset($data['expected_date'])) {
            $item->expected_date = $this->requiredDate($data['expected_date'], 'Expected date');
        }

        if (array_key_exists('description', $data)) {
            $item->description = substr(trim((string) $data['description']), 0, 200) ?: null;
        }

        if ($item->source_type === TreasuryForecastItem::SOURCE_CONTRACT && array_key_exists('document', $data)) {
            $item->document = substr(trim((string) $data['document']), 0, 150) ?: null;
        }

        $item->save();

        return $item->fresh();
    }

    public function deleteManualProjection(int $creatorId, int $itemId): void
    {
        $item = $this->projectionForCompany($creatorId, $itemId);

        if (! in_array($item->source_type, [TreasuryForecastItem::SOURCE_MANUAL, TreasuryForecastItem::SOURCE_CONTRACT, TreasuryForecastItem::SOURCE_EXPENSE], true)) {
            throw new TreasuryException('Only manual projections can be deleted — system items are regenerated on refresh.');
        }

        // A real contract projection (linked to an occurrence) is
        // system-managed: it follows the contract, never a hand delete.
        if ($item->source_type === TreasuryForecastItem::SOURCE_CONTRACT && $item->source_id !== null) {
            throw new TreasuryException('This contract projection is managed by the billing contract and cannot be deleted.');
        }

        $item->delete();
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Forecast items in the window, with the scenario transform
     * applied. Items dated before $from are due now and are kept
     * (the bucketing step lands them in the first period).
     *
     * Balance items are excluded: they are the per-account record
     * of the opening balance, and the calendar already starts from
     * openingBalance() — counting them again as inflow would
     * double the opening.
     */
    protected function forecastItems(int $creatorId, Carbon $from, Carbon $to, string $scenario): array
    {
        $rows = TreasuryForecastItem::where('created_by', $creatorId)
            ->where('state', TreasuryForecastItem::STATE_FORECAST)
            ->whereNull('replacement_reference')
            ->where('source_type', '!=', TreasuryForecastItem::SOURCE_BALANCE)
            ->where('expected_date', '<=', $to->toDateString())
            ->orderBy('expected_date')
            ->orderBy('id')
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $date = Carbon::parse($row->expected_date)->startOfDay();
            $amount = (string) $row->amount_base;

            if ($scenario === TreasuryForecastItem::SCENARIO_LATE_RECEIPTS && $row->direction === TreasuryForecastItem::DIRECTION_INFLOW) {
                $date = $date->copy()->addDays(7);
            }
            if ($scenario === TreasuryForecastItem::SCENARIO_HIGHER_EXPENSES && $row->direction === TreasuryForecastItem::DIRECTION_OUTFLOW) {
                $amount = bcmul($amount, '1.10', 4);
            }
            if ($scenario === TreasuryForecastItem::SCENARIO_EARLIER_PAYMENTS && $row->direction === TreasuryForecastItem::DIRECTION_OUTFLOW) {
                $date = $date->copy()->subDays(7);
            }

            // A delayed inflow that lands past the window is not
            // in yet — it drops out of this window's closing.
            if ($date->greaterThan($to)) {
                continue;
            }

            $items[] = [
                'id' => $row->id,
                'source_type' => $row->source_type,
                'direction' => $row->direction,
                'amount_base' => $amount,
                'expected_date' => $date->toDateString(),
                'description' => (string) ($row->description ?? ''),
            ];
        }

        return $items;
    }

    /** Bucket items into day / week (Monday start) / month periods covering [$from, $to]. */
    protected function bucketItems(array $items, Carbon $from, Carbon $to, string $groupBy): array
    {
        $periods = [];
        $cursor = $from->copy();

        if ($groupBy === 'day') {
            while ($cursor->lessThanOrEqualTo($to)) {
                $key = $cursor->toDateString();
                $periods[$key] = $this->emptyPeriod($key, $cursor->format('D j M Y'));
                $cursor->addDay();
            }
        } elseif ($groupBy === 'week') {
            $cursor = $cursor->copy()->startOfWeek(Carbon::MONDAY);
            while ($cursor->lessThanOrEqualTo($to)) {
                $key = $cursor->toDateString();
                $periods[$key] = $this->emptyPeriod($key, 'Week of ' . $cursor->format('j M Y'));
                $cursor->addWeek();
            }
        } else {
            $cursor = $cursor->copy()->startOfMonth();
            while ($cursor->lessThanOrEqualTo($to)) {
                $key = $cursor->toDateString();
                $periods[$key] = $this->emptyPeriod($key, $cursor->format('F Y'));
                $cursor->addMonthNoOverflow();
            }
        }

        $keys = array_keys($periods);
        $firstKey = $keys[0];

        foreach ($items as $item) {
            $date = Carbon::parse($item['expected_date'])->startOfDay();

            if ($groupBy === 'day') {
                $key = $date->lessThan($from) ? $firstKey : $date->toDateString();
            } elseif ($groupBy === 'week') {
                $weekStart = $date->copy()->startOfWeek(Carbon::MONDAY);
                $key = $weekStart->lessThan($from->copy()->startOfWeek(Carbon::MONDAY)) ? $firstKey : $weekStart->toDateString();
            } else {
                $monthStart = $date->copy()->startOfMonth();
                $key = $monthStart->lessThan($from->copy()->startOfMonth()) ? $firstKey : $monthStart->toDateString();
            }

            if (! isset($periods[$key])) {
                continue;
            }

            if ($item['direction'] === TreasuryForecastItem::DIRECTION_INFLOW) {
                $periods[$key]['inflow'] = bcadd($periods[$key]['inflow'], $item['amount_base'], 4);
            } else {
                $periods[$key]['outflow'] = bcadd($periods[$key]['outflow'], $item['amount_base'], 4);
            }
            $periods[$key]['items'][] = $item;
        }

        return array_values($periods);
    }

    protected function emptyPeriod(string $key, string $label): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'inflow' => '0.0000',
            'outflow' => '0.0000',
            'closing' => '0.0000',
            'below_threshold' => false,
            'items' => [],
        ];
    }

    /** Last day of a 'Y-m' salary month, or null when unparseable. */
    protected function salaryMonthEnd(string $salaryMonth): ?string
    {
        try {
            $date = Carbon::createFromFormat('Y-m', $salaryMonth);
        } catch (\Throwable) {
            return null;
        }

        if ($date === false) {
            return null;
        }

        return $date->endOfMonth()->toDateString();
    }

    protected function requiredDate(mixed $value, string $label): string
    {
        try {
            $date = Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw new TreasuryException($label . ' is not a valid date.');
        }

        return $date->toDateString();
    }

    protected function decimalString(mixed $value, string $label): string
    {
        if (! is_numeric($value)) {
            throw new TreasuryException($label . ' must be a number.');
        }

        return number_format((float) $value, 4, '.', '');
    }

    protected function abs4(string $amount): string
    {
        return bccomp($amount, '0.0000', 4) < 0 ? bcsub('0.0000', $amount, 4) : $amount;
    }
}
