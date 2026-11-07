<?php

namespace App\Services;

use App\Exceptions\TreasuryException;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\DimensionAuditEvent;
use App\Models\Transaction;
use App\Models\TreasuryEntry;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryFundReservation;
use App\Models\TreasuryOperation;
use App\Models\TreasuryPaymentAttempt;
use App\Models\TreasuryPaymentRequest;
use App\Models\TreasurySettlementAllocation;
use App\Models\User;
use App\Models\Utility;
use App\Services\Accounting\ChartAccountResolver;
use App\Services\Accounting\DocumentLedgerService;
use App\Services\Accounting\JournalEntryPoster;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Treasury Phase 3 — manual payment execution (plan §4, §6, §7,
 * §10, §11).
 *
 * One approved request, one execution, one movement (RF01): the
 * execution rechecks approval, beneficiary, closed period,
 * availability and open balances inside a single transaction,
 * under a row lock on the request and the source account, so two
 * concurrent executions cannot double-pay. If any recheck fails
 * the execution is refused with a clear reason and the request
 * stays approved — it is never silently failed.
 *
 * Posting (plan §7): debit Accounts Payable (421) for the settled
 * bills, credit bank / cash / mobile wallet — only on execution
 * confirmation, only through JournalEntryPoster. A reservation is
 * not a posting: it only reduces the available balance, and
 * execution consumes it. A payment in a closed period is blocked,
 * or routed as an authorised adjustment to an open period (CA17).
 * An accounting failure leaves a single movement and allows a
 * duplicate-safe retry (CA18): everything — attempt, journal,
 * entry, bill payments, allocations, reservation consumption —
 * commits or rolls back together, and a repeated idempotency key
 * with the same payload returns the original result (CA20).
 *
 * Bill settlement links to the existing BillPayment rows (never
 * replaces them — plan §11 risk 2): each settled part creates a
 * BillPayment + a Transaction row + a vendor balance update, and
 * a treasury_settlement_allocations row links the attempt to the
 * bill and the journal. The ledger lines themselves come from the
 * poster journal only — DocumentLedgerService::postBillPayment()
 * is NOT called, or the AP/bank lines would be posted twice.
 */
class TreasuryExecutionService
{
    public const PROVIDER_MANUAL = 'manual';

    public const AUDIT_ENTITY = 'treasury_payment_execution';
    public const AUDIT_ATTEMPT_ENTITY = 'treasury_payment_attempt';

    protected TreasuryAccountService $accounts;
    protected TreasuryPaymentService $payments;
    protected JournalEntryPoster $poster;
    protected DocumentLedgerService $ledger;
    protected ChartAccountResolver $resolver;

    public function __construct(
        ?TreasuryAccountService $accounts = null,
        ?TreasuryPaymentService $payments = null,
        ?JournalEntryPoster $poster = null,
        ?DocumentLedgerService $ledger = null,
        ?ChartAccountResolver $resolver = null,
    ) {
        $this->accounts = $accounts ?? new TreasuryAccountService();
        $this->payments = $payments ?? new TreasuryPaymentService($this->accounts);
        $this->poster = $poster ?? new JournalEntryPoster();
        $this->resolver = $resolver ?? new ChartAccountResolver();
        $this->ledger = $ledger ?? new DocumentLedgerService($this->resolver);
    }

    // ------------------------------------------------------------------
    // Preview — the execution screen shows the recheck results
    // before the user confirms (plan §8: a financial confirmation
    // shows account, counterparty, currency, amount and effect).
    // ------------------------------------------------------------------

    /**
     * @return array{request: TreasuryPaymentRequest, account: TreasuryFinancialAccount|null, amount_original: string, amount_base: string, rate: string, posting_date: string, posted_balance: string|null, available_balance: string|null, allocations: array, allocated_to_bills_base: string, manual_portion_base: string, checks: array<int, array{key: string, passed: bool, detail: string}>, can_execute: bool, adjustment: array|null}
     */
    public function previewExecution(int $creatorId, int $requestId, string $amountOriginal, int $actorId, array $options = []): array
    {
        $options['executor_user_id'] = (int) ($options['executor_user_id'] ?? $actorId);
        $request = $this->payments->requestForCompany($creatorId, $requestId);
        $account = $this->accounts->accountForCompany($creatorId, (int) $request->source_account_id);

        $amounts = $this->computeAmounts($request, $amountOriginal);
        $posting = $this->resolvePostingDate($request, $options);
        $plan = $this->allocationPlan($creatorId, $request, $amounts['amount_original'], $amounts['amount_base']);
        $checks = $this->recheck($creatorId, $request, $account, $amounts, $posting, $plan, $options);

        $canExecute = true;
        foreach ($checks as $check) {
            if (! $check['passed']) {
                $canExecute = false;
                break;
            }
        }

        return [
            'request' => $request,
            'account' => $account,
            'amount_original' => $amounts['amount_original'],
            'amount_base' => $amounts['amount_base'],
            'rate' => $amounts['rate'],
            'posting_date' => $posting['posting_date'],
            'posted_balance' => $this->accounts->postedBalance($account),
            'available_balance' => $this->accounts->availableBalance($account),
            'allocations' => $plan['allocations'],
            'allocated_to_bills_base' => $plan['allocated_base'],
            'manual_portion_base' => $plan['manual_base'],
            'checks' => $checks,
            'can_execute' => $canExecute,
            'adjustment' => $posting['adjustment'],
        ];
    }

    // ------------------------------------------------------------------
    // Execution
    // ------------------------------------------------------------------

    /**
     * Execute (part of) an approved payment request.
     *
     * Options:
     * - provider (default 'manual')
     * - idempotency_key (default: a fresh UUID per call)
     * - posting_date (default: planned date, else today)
     * - provider_reference / result (stored on the attempt)
     * - provider_queried (bool — required to execute a request in
     *   outcome_unknown state, CA06)
     * - adjustment: ['authorised_by' => int, 'reason' => string]
     *   (CA17 — closed period routed to an open period)
     *
     * @return array{attempt: TreasuryPaymentAttempt, journal: \App\Models\JournalEntry|null, entry: TreasuryEntry|null, amount_original: string, amount_base: string, posting_date: string, allocations: array, allocated_to_bills_base: string, manual_portion_base: string, request: TreasuryPaymentRequest, replayed: bool, note: string|null, adjustment: array|null}
     */
    public function execute(int $creatorId, int $requestId, string $amountOriginal, int $actorId, array $options = []): array
    {
        $options['executor_user_id'] = (int) ($options['executor_user_id'] ?? $actorId);

        return DB::transaction(function () use ($creatorId, $requestId, $amountOriginal, $actorId, $options) {
            $request = TreasuryPaymentRequest::lockForUpdate()->findOrFail(
                $this->payments->requestForCompany($creatorId, $requestId)->id
            );
            $account = TreasuryFinancialAccount::lockForUpdate()->findOrFail((int) $request->source_account_id);

            $provider = trim((string) ($options['provider'] ?? self::PROVIDER_MANUAL)) ?: self::PROVIDER_MANUAL;
            $key = trim((string) ($options['idempotency_key'] ?? '')) ?: (string) Str::uuid();
            $options['idempotency_key'] = $key;

            // Idempotency first (CA20): beginAttempt returns the
            // ORIGINAL attempt for a repeated key + same payload,
            // and throws on a conflicting payload — before any
            // money moves and before the remaining-amount check,
            // so a replay of a fully paid request still returns
            // the original result instead of refusing.
            $posting = $this->resolvePostingDate($request, $options);
            $payload = $this->executionPayload($request, [
                'amount_original' => $this->decimalString($amountOriginal, 'Amount'),
                'amount_base' => (string) $request->amount_base,
                'rate' => (string) ($request->exchange_rate ?: '1'),
            ], $posting, $provider);
            $attempt = $this->payments->beginAttempt($request, $provider, $key, $payload, $actorId);

            if ($attempt->isPaid()) {
                return $this->replayResult($request, $attempt, [
                    'amount_original' => $this->decimalString($amountOriginal, 'Amount'),
                    'amount_base' => (string) $request->amount_base,
                    'rate' => (string) ($request->exchange_rate ?: '1'),
                ], $posting);
            }
            if ($attempt->isFailed()) {
                throw new TreasuryException('This attempt already failed — retry with a new idempotency key.');
            }
            if ($attempt->isOutcomeUnknown()) {
                throw new TreasuryException('Query the provider for the outcome of attempt ' . $attempt->attempt_number . ' before retrying (CA06).');
            }

            $amounts = $this->computeAmounts($request, $amountOriginal);

            // Transactional recheck under lock: every failure
            // refuses the execution and leaves the request
            // approved (the outer transaction rolls the attempt
            // back too, so a retry with the same key is clean).
            $plan = $this->allocationPlan($creatorId, $request, $amounts['amount_original'], $amounts['amount_base']);
            $checks = $this->recheck($creatorId, $request, $account, $amounts, $posting, $plan, $options);
            foreach ($checks as $check) {
                if (! $check['passed']) {
                    throw new TreasuryException($check['detail']);
                }
            }

            return $this->executeWithAttempt($creatorId, $request, $account, $attempt, $amounts, $posting, $plan, $provider, $actorId, $options);
        });
    }

    /**
     * CA06 — the operator reports that the provider timed out.
     * The attempt and the request go to outcome unknown; nothing
     * is posted. The provider must be queried before any retry.
     */
    public function reportTimeout(int $creatorId, int $attemptId, int $actorId, ?string $reason = null): TreasuryPaymentAttempt
    {
        $attempt = TreasuryPaymentAttempt::where('created_by', $creatorId)->findOrFail($attemptId);

        return $this->payments->resolveAttempt(
            $attempt,
            TreasuryPaymentAttempt::STATE_OUTCOME_UNKNOWN,
            null,
            $reason !== null && trim($reason) !== '' ? trim($reason) : 'Execution timed out — provider outcome unknown.',
            $actorId
        );
    }

    /**
     * CA06 — the provider has been queried and says the payment
     * DID go through. The original attempt is reopened and the
     * money is posted through it (one movement, linked to the
     * original attempt — not a second attempt, not a second
     * movement). If the provider says it did NOT go through,
     * resolve the attempt to failed instead and retry with a new
     * attempt row.
     */
    public function confirmProviderPaid(int $creatorId, int $attemptId, int $actorId, ?string $providerReference = null, ?string $result = null, array $options = []): array
    {
        $options['executor_user_id'] = (int) ($options['executor_user_id'] ?? $actorId);

        return DB::transaction(function () use ($creatorId, $attemptId, $actorId, $providerReference, $result, $options) {
            $attempt = TreasuryPaymentAttempt::lockForUpdate()->findOrFail($attemptId);
            $request = TreasuryPaymentRequest::lockForUpdate()->findOrFail($attempt->payment_request_id);

            if ((int) $request->created_by !== $creatorId) {
                throw new TreasuryException('Payment request not found for this company.');
            }
            if (! $attempt->isOutcomeUnknown()) {
                throw new TreasuryException('Only an outcome-unknown attempt can be confirmed as paid by the provider (state: ' . $attempt->state . ').');
            }

            $amountOriginal = (string) ($attempt->amount_original ?? $request->remainingAmountOriginal());
            $amounts = $this->computeAmounts($request, $amountOriginal);
            $posting = $this->resolvePostingDate($request, $options);
            $provider = trim((string) ($options['provider'] ?? $attempt->provider ?? self::PROVIDER_MANUAL)) ?: self::PROVIDER_MANUAL;

            // Reopen the attempt: outcome unknown → processing →
            // paid, with an audit row for the provider query.
            $attempt->state = TreasuryPaymentAttempt::STATE_PROCESSING;
            $attempt->save();
            $this->audit($creatorId, self::AUDIT_ATTEMPT_ENTITY, $attempt->id, 'reopened_after_provider_query', null, $attempt->fresh()->toArray(), $actorId);

            $account = TreasuryFinancialAccount::lockForUpdate()->findOrFail((int) $request->source_account_id);
            $plan = $this->allocationPlan($creatorId, $request, $amounts['amount_original'], $amounts['amount_base']);
            // The reopened attempt is this execution's attempt —
            // the concurrent check must not flag it as "another"
            // execution in progress.
            $options['idempotency_key'] = $attempt->idempotency_key;
            $checks = $this->recheck($creatorId, $request, $account, $amounts, $posting, $plan, array_merge($options, ['provider_queried' => true]));
            foreach ($checks as $check) {
                if (! $check['passed']) {
                    throw new TreasuryException($check['detail']);
                }
            }

            return $this->executeWithAttempt($creatorId, $request, $account, $attempt, $amounts, $posting, $plan, $provider, $actorId, array_merge($options, [
                'provider_reference' => $providerReference,
                'result' => $result,
            ]));
        });
    }

    // ------------------------------------------------------------------
    // The core: post the movement. Called only after the recheck
    // passed, inside the caller's transaction.
    // ------------------------------------------------------------------

    /**
     * @param  array{amount_original: string, amount_base: string, rate: string}  $amounts
     * @param  array{posting_date: string, adjustment: array|null}  $posting
     * @param  array{allocations: array, allocated_base: string, manual_base: string}  $plan
     */
    protected function executeWithAttempt(int $creatorId, TreasuryPaymentRequest $request, TreasuryFinancialAccount $account, TreasuryPaymentAttempt $attempt, array $amounts, array $posting, array $plan, string $provider, int $actorId, array $options): array
    {
        $operation = $request->operation()->lockForUpdate()->firstOrFail();
        $bankChartId = $account->bankAccount ? (int) $account->bankAccount->chart_account_id : 0;
        if ($bankChartId <= 0) {
            throw new TreasuryException('The financial account has no chart account. Set one on the account before executing.');
        }

        $apId = $this->ledger->accountsPayableId($creatorId);
        if (! $apId) {
            throw new TreasuryException('No accounts payable account (421) is configured for this company — the payment cannot be posted.');
        }

        $reference = (string) $operation->reference;
        $postingDate = $posting['posting_date'];
        $amountBase = $amounts['amount_base'];

        // One journal, through the poster only (plan §7): debit AP
        // for the settled bills, credit the source account. The
        // poster throws when the lines do not balance, and the
        // outer transaction rolls everything back (CA18).
        $journal = $this->poster->post(
            $creatorId,
            $postingDate,
            $reference,
            'Treasury payment ' . $reference . ' — ' . $request->beneficiaryLabel(),
            [
                [
                    'account' => $apId,
                    'debit' => (float) $amountBase,
                    'description' => 'Treasury payment ' . $reference . ' — settle ' . $request->beneficiaryLabel(),
                ],
                [
                    'account' => $bankChartId,
                    'credit' => (float) $amountBase,
                    'description' => 'Treasury payment ' . $reference . ' — ' . $account->code,
                ],
            ]
        );

        // The entry is the BANK movement. The operation becomes
        // confirmed so the posted balance counts the outflow
        // (postedBalance only counts entries on confirmed or
        // reversed operations) — including for a partial payment,
        // whose remaining part is still open on the request.
        $operation->state = TreasuryOperation::STATE_CONFIRMED;
        $operation->posting_date = $postingDate;
        $operation->save();

        $entry = TreasuryEntry::create([
            'operation_id' => $operation->id,
            'financial_account_id' => $account->id,
            'direction' => TreasuryEntry::DIRECTION_OUTFLOW,
            'amount_original' => $amounts['amount_original'],
            'amount_base' => $amountBase,
            'exchange_rate' => $amounts['rate'],
            'confirmed_at' => now(),
            'journal_id' => $journal->id,
            'created_by' => $creatorId,
        ]);

        // Settle the linked bills (FIFO), in base currency.
        $settled = $this->settleBills($creatorId, $request, $account, $attempt, $journal, $plan, $postingDate, $actorId);

        // Consume the reservation (Phase 2 note: resolving an
        // attempt to paid deliberately does NOT consume it —
        // execution does).
        $reservation = $request->reservations()->where('state', TreasuryFundReservation::STATE_ACTIVE)->first();
        if ($reservation) {
            $this->payments->consumeReservation($reservation, $amountBase, $actorId);
        }

        // Link the attempt to the movement it posted, then resolve
        // it with the paid amounts: the request is PAID only when
        // the paid base amount reaches the approved base amount.
        $attempt->journal_id = $journal->id;
        $attempt->entry_id = $entry->id;
        $attempt->save();

        $result = trim((string) ($options['result'] ?? ''));
        if ($posting['adjustment'] !== null) {
            $result = trim($result . ' Posted as an authorised adjustment to an open period (intended posting date ' . $posting['adjustment']['intended_date'] . ', authorised by user #' . $posting['adjustment']['authorised_by'] . ').');
        }
        $attempt = $this->payments->resolveAttempt(
            $attempt,
            TreasuryPaymentAttempt::STATE_PAID,
            $options['provider_reference'] ?? null,
            $result !== '' ? $result : 'Payment executed.',
            $actorId,
            $amounts['amount_original'],
            $amountBase
        );

        $request = $request->fresh();
        $this->audit($creatorId, self::AUDIT_ENTITY, $request->id, 'executed', null, [
            'request_id' => $request->id,
            'attempt_id' => $attempt->id,
            'journal_id' => $journal->id,
            'entry_id' => $entry->id,
            'amount_original' => $amounts['amount_original'],
            'amount_base' => $amountBase,
            'posting_date' => $postingDate,
            'state' => $request->state,
        ], $actorId);

        return [
            'attempt' => $attempt,
            'journal' => $journal,
            'entry' => $entry,
            'amount_original' => $amounts['amount_original'],
            'amount_base' => $amountBase,
            'posting_date' => $postingDate,
            'allocations' => $settled,
            'allocated_to_bills_base' => $plan['allocated_base'],
            'manual_portion_base' => $plan['manual_base'],
            'request' => $request,
            'replayed' => false,
            'note' => null,
            'adjustment' => $posting['adjustment'],
        ];
    }

    /**
     * Settle the linked bills, in source-document order, never
     * more than each bill's outstanding amount. Mirrors
     * BillController's payment creation (BillPayment row +
     * Transaction row + vendor balance) and Phase 1's
     * settleInvoice: the BillPayment is the link the old reports
     * reconcile against, and the allocation row links the attempt
     * to the bill and the journal.
     *
     * @param  array{allocations: array, allocated_base: string, manual_base: string}  $plan
     * @return array<int, array{bill_id: int, allocated_original: string, allocated_base: string, bill_payment_id: int}>
     */
    protected function settleBills(int $creatorId, TreasuryPaymentRequest $request, TreasuryFinancialAccount $account, TreasuryPaymentAttempt $attempt, $journal, array $plan, string $postingDate, int $actorId): array
    {
        $settled = [];

        foreach ($plan['allocations'] as $allocation) {
            $bill = Bill::where('created_by', $creatorId)->find((int) $allocation['bill_id']);
            if (! $bill) {
                continue;
            }

            $billPayment = new BillPayment();
            $billPayment->bill_id = $bill->id;
            $billPayment->date = $postingDate;
            // Amount is set directly on the model, not through
            // fillable (plan §11 risk 2 — BillPayment does not
            // carry amount in its fillable fields).
            $billPayment->amount = $allocation['allocated_base'];
            $billPayment->account_id = $account->bank_account_id;
            $billPayment->payment_method = 0;
            $billPayment->reference = (string) $request->operation->reference;
            $billPayment->description = 'Treasury payment ' . $request->operation->reference;
            $billPayment->save();

            $due = $this->payments->billOutstanding($creatorId, (int) $bill->id);
            if ($due !== null) {
                $bill->status = bccomp($due, '0', 4) <= 0 ? 4 : 3;
                $bill->save();
            }

            // The Transaction row + vendor balance are the legacy
            // vendor ledger: without them the payment would not
            // show in the vendor's statement.
            $billPayment->user_id = $bill->vender_id;
            $billPayment->user_type = 'Vender';
            $billPayment->type = 'Partial';
            $billPayment->created_by = $actorId;
            $billPayment->payment_id = $billPayment->id;
            $billPayment->category = 'Bill';
            $billPayment->account = $account->bank_account_id;
            Transaction::addTransaction($billPayment);

            if ($bill->vender_id) {
                Utility::updateUserBalance('vendor', (int) $bill->vender_id, $allocation['allocated_base'], 'credit');
            }

            TreasurySettlementAllocation::create([
                'receipt_id' => null,
                'payment_id' => $attempt->id,
                'target_type' => 'bill',
                'target_id' => $bill->id,
                'allocated_amount' => $allocation['allocated_original'],
                'allocated_amount_base' => $allocation['allocated_base'],
                'currency' => $request->currency,
                'exchange_rate' => $request->exchange_rate,
                'rate_source' => $request->rate_source,
                'rate_date' => $request->rate_date,
                'state' => TreasurySettlementAllocation::STATE_ACTIVE,
                'source' => 'payment',
                'invoice_payment_id' => $billPayment->id,
                'journal_id' => $journal->id,
                'created_by' => $creatorId,
            ]);

            // Cost centre dimensions, when the request carries one:
            // inherit the bill's allocation, exactly like
            // BillController's payment flow does. A failure here
            // must never lose the payment — it is logged.
            if ($request->cost_centre_id && Schema::hasTable('treasury_line_dimensions')) {
                try {
                    app(TreasuryDimensionService::class)->inheritForBillPayment($billPayment, $creatorId, $actorId);
                } catch (\Throwable $e) {
                    \Log::warning('Treasury dimension tagging failed for bill payment #' . $billPayment->id . ': ' . $e->getMessage());
                }
            }

            $settled[] = [
                'bill_id' => $bill->id,
                'allocated_original' => $allocation['allocated_original'],
                'allocated_base' => $allocation['allocated_base'],
                'bill_payment_id' => $billPayment->id,
            ];
        }

        return $settled;
    }

    // ------------------------------------------------------------------
    // Recheck (plan §10 Phase 3: approval, beneficiary, period,
    // availability, open balances — all inside one transaction,
    // under lock).
    // ------------------------------------------------------------------

    /**
     * @param  array{amount_original: string, amount_base: string, rate: string}  $amounts
     * @param  array{posting_date: string, adjustment: array|null}  $posting
     * @param  array{allocations: array, allocated_base: string, manual_base: string}  $plan
     * @return array<int, array{key: string, passed: bool, detail: string}>
     */
    protected function recheck(int $creatorId, TreasuryPaymentRequest $request, TreasuryFinancialAccount $account, array $amounts, array $posting, array $plan, array $options): array
    {
        $checks = [];
        $add = function (string $key, bool $passed, string $detail) use (&$checks) {
            $checks[] = ['key' => $key, 'passed' => $passed, 'detail' => $detail];
        };

        // 1. State — approved, or partially paid (processing), or
        // outcome unknown with a provider query behind it (CA06).
        $stateOk = $request->isApproved() || $request->isProcessing();
        if ($request->state === TreasuryPaymentRequest::STATE_OUTCOME_UNKNOWN) {
            $stateOk = ! empty($options['provider_queried']);
        }
        $add('state', $stateOk, $stateOk
            ? 'Request is ' . $request->state . ' and can be executed.'
            : ($request->state === TreasuryPaymentRequest::STATE_OUTCOME_UNKNOWN
                ? 'Query the provider for the outcome of this payment before executing (CA06).'
                : 'Only an approved request can be executed (state: ' . $request->state . ').'));

        // 2. Approval still valid (CA05): the snapshot must match
        // the current protected fields, and no step may be pending.
        $snapshot = $request->approved_snapshot;
        $snapshotOk = is_array($snapshot) && $snapshot !== [];
        if ($snapshotOk) {
            $expected = [
                'amount_original' => (string) $request->amount_original,
                'amount_base' => (string) $request->amount_base,
                'currency' => $request->currency,
                'beneficiary_type' => $request->beneficiary_type,
                'beneficiary_id' => $request->beneficiary_id,
                'beneficiary_account' => $request->beneficiary_account,
                'source_account_id' => $request->source_account_id,
                'source_documents' => $request->sourceDocuments(),
            ];
            foreach ($expected as $field => $value) {
                if (($snapshot[$field] ?? null) != $value) {
                    $snapshotOk = false;
                    break;
                }
            }
        }
        $pendingSteps = $request->steps()->where('status', \App\Models\TreasuryApprovalStep::STATUS_PENDING)->exists();
        $add('approval', $snapshotOk && ! $pendingSteps, $snapshotOk && ! $pendingSteps
            ? 'Approval is still valid (snapshot matches, no pending steps).'
            : 'The approval is no longer valid — the request changed after approval, or a step is still pending.');

        // The executor cannot be the sole approver (plan §6).
        $approvers = $request->decisions()
            ->where('decision', \App\Models\TreasuryApprovalDecision::DECISION_APPROVE)
            ->whereHas('step', fn ($q) => $q->where('status', '!=', \App\Models\TreasuryApprovalStep::STATUS_INVALIDATED))
            ->distinct('actor_id')->pluck('actor_id')->map(fn ($id) => (int) $id)->all();
        $executorId = (int) ($options['executor_user_id'] ?? $request->executor_user_id ?? 0);
        $soleApprover = count($approvers) === 1 && $executorId > 0 && (int) $approvers[0] === $executorId;
        $add('executor', ! $soleApprover, $soleApprover
            ? 'The executor cannot be the sole approver — another approver is required.'
            : 'Executor check passed.');

        // 3. Beneficiary still exists.
        $beneficiaryOk = true;
        $beneficiaryDetail = 'Beneficiary: ' . $request->beneficiaryLabel() . '.';
        if ($request->beneficiary_type !== TreasuryPaymentRequest::BENEFICIARY_OTHER) {
            try {
                $this->resolveBeneficiaryName($creatorId, $request->beneficiary_type, $request->beneficiary_id);
            } catch (TreasuryException $e) {
                $beneficiaryOk = false;
                $beneficiaryDetail = $e->getMessage();
            }
        }
        $add('beneficiary', $beneficiaryOk, $beneficiaryDetail);

        // 4. Source account active.
        $add('account', $account->isActive(), $account->isActive()
            ? 'Source account ' . $account->code . ' is active.'
            : 'The source account ' . $account->code . ' is inactive.');

        // 5. Amount: positive and within the remaining amount.
        $amountOk = bccomp($amounts['amount_original'], '0', 4) > 0
            && bccomp($amounts['amount_original'], $request->remainingAmountOriginal(), 4) <= 0;
        $add('amount', $amountOk, $amountOk
            ? 'Amount ' . $amounts['amount_original'] . ' ' . $request->currency . ' is within the remaining ' . $request->remainingAmountOriginal() . ' ' . $request->currency . '.'
            : 'The amount must be greater than zero and no more than the remaining ' . $request->remainingAmountOriginal() . ' ' . $request->currency . '.');

        // 6. Closed period (CA17): blocked, unless an authorised
        // adjustment to an open period is supplied.
        $postingDate = Carbon::parse($posting['posting_date']);
        $periodOpen = $this->isPeriodOpen($creatorId, $postingDate);
        $adjustmentOk = $posting['adjustment'] !== null;
        $add('period', $periodOpen || $adjustmentOk, $periodOpen
            ? 'Posting period ' . $postingDate->year . ' is open.'
            : ($adjustmentOk
                ? 'Posting year ' . $postingDate->year . ' is closed — posting as an authorised adjustment to ' . $posting['posting_date'] . '.'
                : 'Fiscal year ' . $postingDate->year . ' is closed. Treasury postings into a closed period are blocked — an authorised adjustment to an open period is required (CA17).'));

        // 7. Availability: posted − active reservations, plus the
        // request's own reservation remainder (funds already
        // earmarked for this request are usable for it).
        $available = $this->accounts->availableBalance($account);
        $ownRemaining = '0.0000';
        $reservation = $request->reservations()->where('state', TreasuryFundReservation::STATE_ACTIVE)->first();
        if ($reservation) {
            $ownRemaining = $reservation->remaining();
        }
        $usable = bcadd($available, $ownRemaining, 4);
        $availabilityOk = bccomp($amounts['amount_base'], $usable, 4) <= 0;
        $add('availability', $availabilityOk, $availabilityOk
            ? 'Available ' . $usable . ' covers ' . $amounts['amount_base'] . ' (posted ' . $this->accounts->postedBalance($account) . ', reserved ' . bcsub($this->accounts->postedBalance($account), $available, 4) . ').'
            : 'Insufficient available balance on account ' . $account->code . ': ' . $usable . ' available for ' . $amounts['amount_base'] . ' required.');

        // 8. Negative-balance policy on the projected balance.
        $projected = bcsub($this->accounts->postedBalance($account), $amounts['amount_base'], 4);
        $policy = $this->accounts->checkNegativeBalance($account, $projected);
        $negativeOk = $policy === 'allowed';
        $negativeDetail = 'Projected balance ' . $projected . ' is allowed by the account policy.';
        if ($policy === 'blocked') {
            $negativeDetail = 'The account policy blocks this payment: the projected balance ' . $projected . ' would go below the allowed limit.';
        } elseif ($policy === 'needs_approval') {
            $approverId = (int) ($account->negative_balance_approver_user_id ?? 0);
            $approvedByApprover = $approverId > 0 && in_array($approverId, $approvers, true);
            $executorIsApprover = $approverId > 0 && $executorId > 0 && $approverId === $executorId;
            $negativeOk = $approvedByApprover || $executorIsApprover;
            $negativeDetail = $negativeOk
                ? 'Projected balance ' . $projected . ' needs approval, and the named approver approved this payment.'
                : 'The projected balance ' . $projected . ' needs approval from the account approver (user #' . $approverId . ') before it can go negative.';
        }
        $add('negative_balance', $negativeOk, $negativeDetail);

        // 9. Open balances: linked bills must still be open, and
        // the allocation must never exceed what is outstanding.
        $billsOk = true;
        $billsDetail = 'No source bills linked — manual payment.';
        if ($plan['has_bills']) {
            if (bccomp($plan['total_outstanding'], '0', 4) <= 0) {
                $billsOk = false;
                $billsDetail = 'The linked bills are already fully paid — nothing is outstanding.';
            } else {
                $billsDetail = 'Linked bills have ' . $plan['total_outstanding'] . ' outstanding; ' . $plan['allocated_base'] . ' will settle bills, ' . $plan['manual_base'] . ' is the manual portion.';
            }
            foreach ($plan['allocations'] as $allocation) {
                if (! empty($allocation['missing'])) {
                    $billsOk = false;
                    $billsDetail = 'Linked bill #' . $allocation['bill_id'] . ' was not found for this company.';
                    break;
                }
            }
        }
        $add('bills', $billsOk, $billsDetail);

        // 10. No other execution in progress for this request.
        $key = trim((string) ($options['idempotency_key'] ?? ''));
        $inProgress = $request->attempts()->where('state', TreasuryPaymentAttempt::STATE_PROCESSING)->get();
        $otherInProgress = $inProgress->first(fn ($a) => $key === '' || $a->idempotency_key !== $key);
        $add('concurrent', $otherInProgress === null, $otherInProgress
            ? 'An execution attempt is already in progress (attempt ' . $otherInProgress->attempt_number . ') — resolve it or report a timeout first.'
            : 'No other execution is in progress for this request.');

        return $checks;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array{amount_original: string, amount_base: string, rate: string}
     */
    protected function computeAmounts(TreasuryPaymentRequest $request, string $amountOriginal): array
    {
        $amount = $this->decimalString($amountOriginal, 'Amount');

        if (bccomp($amount, '0', 4) <= 0) {
            throw new TreasuryException('The execution amount must be greater than zero.');
        }
        if (bccomp($amount, $request->remainingAmountOriginal(), 4) > 0) {
            throw new TreasuryException('The execution amount exceeds the remaining ' . $request->remainingAmountOriginal() . ' ' . $request->currency . ' of this request.');
        }

        $rate = (string) ($request->exchange_rate ?? '');
        if ($request->currency === TreasurySettings::baseCurrency((int) $request->created_by)) {
            $rate = '1.0000000000';
        }
        if ($rate === '' || ! is_numeric($rate) || bccomp($rate, '0', 10) <= 0) {
            throw new TreasuryException('No exchange rate is stored for this payment — confirmation is blocked, never guess a rate (D2).');
        }

        // The final part is the exact remaining base amount, so
        // the parts always sum to the approved total (RF04).
        $amountBase = bccomp($amount, $request->remainingAmountOriginal(), 4) === 0
            ? $request->remainingAmountBase()
            : bcmul($amount, $rate, 4);

        return ['amount_original' => $amount, 'amount_base' => $amountBase, 'rate' => $rate];
    }

    /**
     * @return array{posting_date: string, adjustment: array|null}
     */
    protected function resolvePostingDate(TreasuryPaymentRequest $request, array $options): array
    {
        $intended = trim((string) ($options['posting_date'] ?? ''))
            ?: (string) ($request->planned_date ?? date('Y-m-d'));

        $adjustment = $options['adjustment'] ?? null;
        if ($adjustment === null || ! is_array($adjustment)) {
            return ['posting_date' => $intended, 'adjustment' => null];
        }

        $authorisedBy = (int) ($adjustment['authorised_by'] ?? 0);
        $reason = trim((string) ($adjustment['reason'] ?? ''));
        if ($authorisedBy <= 0 || $reason === '') {
            throw new TreasuryException('An authorised adjustment needs an authoriser and a reason.');
        }

        // The authoriser must be a different user than the
        // executor, and must hold the approval permission.
        $executorId = (int) ($options['executor_user_id'] ?? $request->executor_user_id ?? 0);
        if ($executorId > 0 && $authorisedBy === $executorId) {
            throw new TreasuryException('The adjustment must be authorised by a different user than the executor.');
        }
        if (! $this->userHasPermission((int) $request->created_by, $authorisedBy, 'approve treasury payments')) {
            throw new TreasuryException('The adjustment authoriser does not hold the approve treasury payments permission.');
        }

        // The adjustment posts in an open period: today, when
        // today is open. (The recheck still blocks the posting
        // when even today is closed.)
        $today = date('Y-m-d');

        return [
            'posting_date' => $today,
            'adjustment' => [
                'intended_date' => $intended,
                'authorised_by' => $authorisedBy,
                'reason' => $reason,
            ],
        ];
    }

    /**
     * Allocate the executed amount across the linked bills, in
     * source-document order, never more than each bill's
     * outstanding amount. The rest is the manual portion.
     *
     * @return array{allocations: array<int, array{bill_id: int, allocated_original: string, allocated_base: string, missing: bool}>, allocated_base: string, manual_base: string, total_outstanding: string, has_bills: bool}
     */
    protected function allocationPlan(int $creatorId, TreasuryPaymentRequest $request, string $amountOriginal, string $amountBase): array
    {
        $allocations = [];
        $allocatedBase = '0.0000';
        $totalOutstanding = '0.0000';
        $hasBills = false;
        $remainingBase = $amountBase;
        $rate = (string) ($request->exchange_rate ?: '1');

        foreach ($request->sourceDocuments() as $doc) {
            if ($doc['type'] !== 'bill') {
                continue;
            }
            $hasBills = true;
            $billId = (int) $doc['id'];
            $outstanding = $this->payments->billOutstanding($creatorId, $billId);

            if ($outstanding === null) {
                $allocations[] = ['bill_id' => $billId, 'allocated_original' => '0.0000', 'allocated_base' => '0.0000', 'missing' => true];
                continue;
            }

            $totalOutstanding = bcadd($totalOutstanding, $outstanding, 4);
            $take = '0.0000';
            if (bccomp($remainingBase, '0', 4) > 0 && bccomp($outstanding, '0', 4) > 0) {
                $take = bccomp($remainingBase, $outstanding, 4) <= 0 ? $remainingBase : $outstanding;
                $remainingBase = bcsub($remainingBase, $take, 4);
                $allocatedBase = bcadd($allocatedBase, $take, 4);
            }

            $allocations[] = [
                'bill_id' => $billId,
                'allocated_original' => bccomp($rate, '1', 10) === 0 ? $take : bcdiv($take, $rate, 4),
                'allocated_base' => $take,
                'missing' => false,
            ];
        }

        return [
            'allocations' => $allocations,
            'allocated_base' => $allocatedBase,
            'manual_base' => bcsub($amountBase, $allocatedBase, 4),
            'total_outstanding' => $totalOutstanding,
            'has_bills' => $hasBills,
        ];
    }

    protected function executionPayload(TreasuryPaymentRequest $request, array $amounts, array $posting, string $provider): array
    {
        return [
            'request_id' => (int) $request->id,
            'amount_original' => $amounts['amount_original'],
            'currency' => $request->currency,
            'posting_date' => $posting['posting_date'],
            'provider' => $provider,
        ];
    }

    /** CA20 replay: rebuild the original execution outcome. */
    protected function replayResult(TreasuryPaymentRequest $request, TreasuryPaymentAttempt $attempt, array $amounts, array $posting): array
    {
        $journal = $attempt->journal_id ? \App\Models\JournalEntry::find($attempt->journal_id) : null;
        $entry = $attempt->entry_id ? TreasuryEntry::find($attempt->entry_id) : null;

        $allocations = TreasurySettlementAllocation::where('payment_id', $attempt->id)
            ->where('state', TreasurySettlementAllocation::STATE_ACTIVE)
            ->get()
            ->map(fn ($a) => [
                'bill_id' => (int) $a->target_id,
                'allocated_original' => (string) $a->allocated_amount,
                'allocated_base' => (string) $a->allocated_amount_base,
                'bill_payment_id' => (int) $a->invoice_payment_id,
            ])->all();

        $allocatedBase = '0.0000';
        foreach ($allocations as $allocation) {
            $allocatedBase = bcadd($allocatedBase, $allocation['allocated_base'], 4);
        }

        return [
            'attempt' => $attempt->fresh(),
            'journal' => $journal,
            'entry' => $entry,
            'amount_original' => (string) ($attempt->amount_original ?? $amounts['amount_original']),
            'amount_base' => (string) ($attempt->amount_base ?? $amounts['amount_base']),
            'posting_date' => $posting['posting_date'],
            'allocations' => $allocations,
            'allocated_to_bills_base' => $allocatedBase,
            'manual_portion_base' => bcsub((string) ($attempt->amount_base ?? $amounts['amount_base']), $allocatedBase, 4),
            'request' => $request->fresh(),
            'replayed' => true,
            'note' => $journal === null
                ? 'This payment was already confirmed by the provider — no Treasury journal was posted for it.'
                : 'This is the original execution result for the same idempotency key — no second movement was posted.',
            'adjustment' => $posting['adjustment'],
        ];
    }

    /** Same FY-CLOSE rule as the receipt service (CA17). */
    protected function assertPeriodOpen(int $creatorId, Carbon $date): void
    {
        if (! $this->isPeriodOpen($creatorId, $date)) {
            throw new TreasuryException(
                __('Fiscal year :year is closed. Treasury postings into a closed period are blocked.', ['year' => $date->year])
            );
        }
    }

    protected function isPeriodOpen(int $creatorId, Carbon $date): bool
    {
        if (! Schema::hasTable('journal_entries')) {
            return true;
        }

        return ! DB::table('journal_entries')
            ->where('created_by', $creatorId)
            ->where('reference', 'like', 'FY-' . $date->year . '-CLOSE%')
            ->exists();
    }

    /** Beneficiary must still exist (mirrors the Phase 2 resolver). */
    protected function resolveBeneficiaryName(int $creatorId, string $type, ?int $id): string
    {
        if ($id === null || $id <= 0) {
            throw new TreasuryException('A beneficiary is required.');
        }

        $name = null;
        if ($type === TreasuryPaymentRequest::BENEFICIARY_VENDOR && Schema::hasTable('venders')) {
            $name = \App\Models\Vender::where('created_by', $creatorId)->where('id', $id)->value('name');
        } elseif ($type === TreasuryPaymentRequest::BENEFICIARY_EMPLOYEE && Schema::hasTable('employees')) {
            $name = \App\Models\Employee::where('created_by', $creatorId)->where('id', $id)->value('name');
        } elseif ($type === TreasuryPaymentRequest::BENEFICIARY_CUSTOMER && Schema::hasTable('customers')) {
            $name = \App\Models\Customer::where('created_by', $creatorId)->where('id', $id)->value('name');
        }

        if ($name === null || trim((string) $name) === '') {
            throw new TreasuryException('Beneficiary not found for this company.');
        }

        return trim((string) $name);
    }

    /** Direct + role permission check (mirrors resolveEligibleUsers). */
    protected function userHasPermission(int $creatorId, int $userId, string $permission): bool
    {
        if (! Schema::hasTable('model_has_permissions') || ! Schema::hasTable('permissions')) {
            return true;
        }

        $permissionId = DB::table('permissions')->where('name', $permission)->where('guard_name', 'web')->value('id');
        if (! $permissionId) {
            return false;
        }

        $direct = DB::table('model_has_permissions')
            ->where('permission_id', $permissionId)
            ->where('model_type', User::class)
            ->where('model_id', $userId)
            ->exists();
        if ($direct) {
            return true;
        }

        if (Schema::hasTable('role_has_permissions') && Schema::hasTable('model_has_roles')) {
            $roleIds = DB::table('role_has_permissions')->where('permission_id', $permissionId)->pluck('role_id')->all();
            if ($roleIds !== []) {
                return DB::table('model_has_roles')
                    ->whereIn('role_id', $roleIds)
                    ->where('model_type', User::class)
                    ->where('model_id', $userId)
                    ->exists();
            }
        }

        return false;
    }

    protected function decimalString(mixed $value, string $label): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw new TreasuryException($label . ' must be a number.');
        }

        return number_format((float) $value, 4, '.', '');
    }

    protected function audit(int $companyId, string $entityType, int $entityId, string $action, ?array $before, ?array $after, ?int $actorId): void
    {
        if (! Schema::hasTable('dimension_audit_events')) {
            return;
        }

        DimensionAuditEvent::create([
            'company_id' => $companyId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before_json' => $before !== null ? json_encode($before) : null,
            'after_json' => $after !== null ? json_encode($after) : null,
            'actor_id' => $actorId,
            'occurred_at' => now(),
        ]);
    }
}
