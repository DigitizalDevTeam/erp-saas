<?php

namespace App\Services;

use App\Exceptions\TreasuryException;
use App\Models\ChartOfAccount;
use App\Models\CostCentre;
use App\Models\DimensionAuditEvent;
use App\Models\Employee;
use App\Models\TreasuryAdvance;
use App\Models\TreasuryAdvanceExpenseLine;
use App\Models\TreasuryEntry;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryLineDimension;
use App\Models\TreasuryOperation;
use App\Models\User;
use App\Models\Vender;
use App\Services\Accounting\ChartAccountResolver;
use App\Services\Accounting\JournalEntryPoster;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 6 — employee / supplier advances (plan §5, §6,
 * §7, §10, CA10).
 *
 * Lifecycle (plan §6): REQUEST → APPROVAL → DISBURSEMENT (cash
 * outflow created) → EXPENSE SUBSTANTIATION (per-line evidence,
 * approve / reject / return for correction) → REGULARISATION
 * (return of unspent money, or a separate reimbursement when
 * approved expenses exceed the advance) → CLOSURE (only when the
 * balance is regularised and all lines are resolved).
 *
 * Posting (plan §7): every movement posts through
 * JournalEntryPoster only, on its own operation (RF01 — one
 * operation per confirmed movement):
 * - Disbursement: Dr advance asset account / Cr source account.
 *   The advance asset account is per recipient type — employee
 *   advances 4512 "Adiantamentos aos trabalhadores", supplier
 *   advances 429 "Adiantamentos a fornecedores" on the Hoya chart
 *   (configurable per company in Treasury settings).
 * - Line approval: Dr expense account (the chart account on the
 *   line) / Cr advance asset account, capped at the advance
 *   balance still unsubstantiated. The excess becomes the
 *   advance's reimbursement — it is never posted as an expense
 *   twice, and it is paid only after its own approval.
 * - Return of unspent money: Dr source account / Cr advance asset
 *   account (the remainder reversed).
 * - Reimbursement payment: Dr expense account(s) / Cr source
 *   account, grouped by the lines' expense accounts.
 *
 * The expense lines are substantiation records, NOT Expense-model
 * rows — Phase 5 petty cash posts through Expense; advances use
 * their own lines, so nothing is ever double-posted.
 *
 * CA10: advance 5,000, approved expenses 4,200, return 800 clears
 * the balance with no second expense outflow — the return reverses
 * the advance asset, it does not post a second expense.
 */
class TreasuryAdvanceService
{
    public const AUDIT_ENTITY = 'treasury_advance';

    protected TreasuryAccountService $accounts;
    protected JournalEntryPoster $poster;
    protected ChartAccountResolver $resolver;

    public function __construct(
        ?TreasuryAccountService $accounts = null,
        ?JournalEntryPoster $poster = null,
        ?ChartAccountResolver $resolver = null,
    ) {
        $this->accounts = $accounts ?? new TreasuryAccountService();
        $this->poster = $poster ?? new JournalEntryPoster();
        $this->resolver = $resolver ?? new ChartAccountResolver();
    }

    // ------------------------------------------------------------------
    // Lookup (CA16 — company scope on every action)
    // ------------------------------------------------------------------

    public function advanceForCompany(int $creatorId, int $advanceId): TreasuryAdvance
    {
        $advance = TreasuryAdvance::where('created_by', $creatorId)->find($advanceId);

        if (! $advance) {
            throw new TreasuryException('Advance not found for this company.');
        }

        return $advance;
    }

    public function lineForCompany(int $creatorId, int $lineId): TreasuryAdvanceExpenseLine
    {
        // Lines carry no company column of their own — scope through
        // the advance they belong to (CA16).
        $line = TreasuryAdvanceExpenseLine::whereHas('advance', function ($query) use ($creatorId) {
            $query->where('created_by', $creatorId);
        })->find($lineId);

        if (! $line) {
            throw new TreasuryException('Expense line not found for this company.');
        }

        return $line;
    }

    // ------------------------------------------------------------------
    // Request (draft) — create / edit / submit / return / cancel
    // ------------------------------------------------------------------

    /**
     * Create an advance request as a draft. The currency comes from
     * the source account, never from the form; a foreign currency
     * needs a rate + source + date (convertToBase) — a missing rate
     * refuses, never guesses (D2). The operation is created in
     * draft with no reference — the reference is allocated only at
     * disbursement, so a cancelled draft never consumes a number.
     *
     * @param array{source_account_id: int, recipient_type: string, recipient_id: int, purpose?: string|null, amount_requested: string, exchange_rate?: string|null, rate_source?: string|null, rate_date?: string|null, due_date?: string|null, branch_id?: int|null, request_date?: string|null} $data
     */
    public function createAdvance(int $creatorId, array $data, int $actorId): TreasuryAdvance
    {
        return DB::transaction(function () use ($creatorId, $data, $actorId) {
            $validated = $this->validateDraftData($creatorId, $data);

            $operation = TreasuryOperation::create([
                'reference' => null,
                'type' => TreasuryOperation::TYPE_ADVANCE,
                'company_id' => $creatorId,
                'branch_id' => $validated['branch_id'],
                'financial_account_id' => $validated['source']->id,
                'counterparty_type' => $validated['recipient_type'] === TreasuryAdvance::RECIPIENT_EMPLOYEE ? 'employee' : 'vendor',
                'counterparty_id' => $validated['recipient_id'],
                'currency' => $validated['currency'],
                'amount_original' => $validated['amount_requested'],
                'exchange_rate' => $validated['exchange_rate'],
                'rate_source' => $validated['rate_source'],
                'rate_date' => $validated['rate_date'],
                'amount_base' => $validated['amount_requested_base'],
                'posting_date' => null,
                'effective_date' => $validated['request_date'],
                'value_date' => $validated['request_date'],
                'state' => TreasuryOperation::STATE_DRAFT,
                'source_type' => 'treasury_advance',
                'source_id' => null,
                'created_by' => $creatorId,
            ]);

            $advance = TreasuryAdvance::create([
                'created_by' => $creatorId,
                'branch_id' => $validated['branch_id'],
                'operation_id' => $operation->id,
                'source_account_id' => $validated['source']->id,
                'recipient_type' => $validated['recipient_type'],
                'recipient_id' => $validated['recipient_id'],
                'recipient_name' => $validated['recipient_name'],
                'purpose' => $validated['purpose'],
                'currency' => $validated['currency'],
                'exchange_rate' => $validated['exchange_rate'],
                'rate_source' => $validated['rate_source'],
                'rate_date' => $validated['rate_date'],
                'amount_requested' => $validated['amount_requested'],
                'amount_requested_base' => $validated['amount_requested_base'],
                'due_date' => $validated['due_date'],
                'state' => TreasuryAdvance::STATE_DRAFT,
                'requested_by' => $actorId,
            ]);

            $operation->source_id = $advance->id;
            $operation->save();

            $this->audit($creatorId, self::AUDIT_ENTITY, $advance->id, 'created', null, $advance->fresh()->toArray(), $actorId);

            return $advance->fresh();
        });
    }

    /** Edit a draft request: same validation as create, amounts re-derived. */
    public function updateAdvance(TreasuryAdvance $advance, array $data, int $actorId): TreasuryAdvance
    {
        if (! $advance->isDraft()) {
            throw new TreasuryException('Only a draft advance can be edited (state: ' . $advance->state . ').');
        }

        return DB::transaction(function () use ($advance, $data, $actorId) {
            $creatorId = (int) $advance->created_by;
            $validated = $this->validateDraftData($creatorId, $data);
            $before = $advance->fresh()->toArray();

            $advance->update([
                'branch_id' => $validated['branch_id'],
                'source_account_id' => $validated['source']->id,
                'recipient_type' => $validated['recipient_type'],
                'recipient_id' => $validated['recipient_id'],
                'recipient_name' => $validated['recipient_name'],
                'purpose' => $validated['purpose'],
                'currency' => $validated['currency'],
                'exchange_rate' => $validated['exchange_rate'],
                'rate_source' => $validated['rate_source'],
                'rate_date' => $validated['rate_date'],
                'amount_requested' => $validated['amount_requested'],
                'amount_requested_base' => $validated['amount_requested_base'],
                'due_date' => $validated['due_date'],
            ]);

            $operation = $advance->operation;
            if ($operation) {
                $operation->update([
                    'branch_id' => $validated['branch_id'],
                    'financial_account_id' => $validated['source']->id,
                    'counterparty_type' => $validated['recipient_type'] === TreasuryAdvance::RECIPIENT_EMPLOYEE ? 'employee' : 'vendor',
                    'counterparty_id' => $validated['recipient_id'],
                    'currency' => $validated['currency'],
                    'amount_original' => $validated['amount_requested'],
                    'exchange_rate' => $validated['exchange_rate'],
                    'rate_source' => $validated['rate_source'],
                    'rate_date' => $validated['rate_date'],
                    'amount_base' => $validated['amount_requested_base'],
                    'effective_date' => $validated['request_date'],
                    'value_date' => $validated['request_date'],
                ]);
            }

            $this->audit($creatorId, self::AUDIT_ENTITY, $advance->id, 'updated', $before, $advance->fresh()->toArray(), $actorId);

            return $advance->fresh();
        });
    }

    public function submitAdvance(int $creatorId, int $advanceId, int $actorId): TreasuryAdvance
    {
        $advance = $this->advanceForCompany($creatorId, $advanceId);

        if (! $advance->isDraft()) {
            throw new TreasuryException('Only a draft advance can be submitted (state: ' . $advance->state . ').');
        }

        $before = $advance->fresh()->toArray();
        $advance->update(['state' => TreasuryAdvance::STATE_PENDING_APPROVAL]);
        $this->audit($creatorId, self::AUDIT_ENTITY, $advance->id, 'submitted', $before, $advance->fresh()->toArray(), $actorId);

        return $advance->fresh();
    }

    /**
     * Approve the full requested amount. The approver must be a
     * different user than the requester and must hold the manage
     * treasury advances permission — no self-approval.
     */
    public function approveAdvance(int $creatorId, int $advanceId, int $actorId, ?string $reason = null): TreasuryAdvance
    {
        $advance = $this->advanceForCompany($creatorId, $advanceId);

        if (! $advance->isPendingApproval()) {
            throw new TreasuryException('Only an advance awaiting approval can be approved (state: ' . $advance->state . ').');
        }
        if ((int) $advance->requested_by === $actorId) {
            throw new TreasuryException('You cannot approve an advance you requested yourself.');
        }
        if (! $this->userHasPermission($creatorId, $actorId, 'manage treasury advances')) {
            throw new TreasuryException('The approver does not hold the manage treasury advances permission.');
        }

        $before = $advance->fresh()->toArray();
        $advance->update([
            'state' => TreasuryAdvance::STATE_APPROVED,
            'amount_approved' => $advance->amount_requested,
            'amount_approved_base' => $advance->amount_requested_base,
            'approved_by' => $actorId,
            'approved_at' => now(),
        ]);
        $this->audit($creatorId, self::AUDIT_ENTITY, $advance->id, 'approved', $before, $advance->fresh()->toArray(), $actorId);

        return $advance->fresh();
    }

    public function rejectAdvance(int $creatorId, int $advanceId, int $actorId, string $reason): TreasuryAdvance
    {
        $advance = $this->advanceForCompany($creatorId, $advanceId);

        if (! $advance->isPendingApproval()) {
            throw new TreasuryException('Only an advance awaiting approval can be rejected (state: ' . $advance->state . ').');
        }
        if ((int) $advance->requested_by === $actorId) {
            throw new TreasuryException('You cannot reject an advance you requested yourself.');
        }
        if (! $this->userHasPermission($creatorId, $actorId, 'manage treasury advances')) {
            throw new TreasuryException('The approver does not hold the manage treasury advances permission.');
        }
        if (trim($reason) === '') {
            throw new TreasuryException('A rejection needs a reason.');
        }

        $before = $advance->fresh()->toArray();
        $advance->update([
            'state' => TreasuryAdvance::STATE_REJECTED,
            'approved_by' => $actorId,
            'approved_at' => now(),
            'rejection_reason' => trim($reason),
        ]);
        $this->audit($creatorId, self::AUDIT_ENTITY, $advance->id, 'rejected', $before, $advance->fresh()->toArray(), $actorId);

        return $advance->fresh();
    }

    public function returnAdvanceToDraft(int $creatorId, int $advanceId, int $actorId): TreasuryAdvance
    {
        $advance = $this->advanceForCompany($creatorId, $advanceId);

        // Awaiting approval, or rejected: the requester fixes the
        // request and resubmits. A rejected advance is not terminal.
        if (! $advance->isPendingApproval() && ! $advance->isRejected()) {
            throw new TreasuryException('Only an advance awaiting approval or rejected can be returned to draft (state: ' . $advance->state . ').');
        }

        $before = $advance->fresh()->toArray();
        $advance->update(['state' => TreasuryAdvance::STATE_DRAFT, 'rejection_reason' => null]);
        $this->audit($creatorId, self::AUDIT_ENTITY, $advance->id, 'returned_to_draft', $before, $advance->fresh()->toArray(), $actorId);

        return $advance->fresh();
    }

    public function cancelAdvance(int $creatorId, int $advanceId, int $actorId): TreasuryAdvance
    {
        $advance = $this->advanceForCompany($creatorId, $advanceId);

        if (! $advance->isDraft() && ! $advance->isPendingApproval()) {
            throw new TreasuryException('Only a draft or pending advance can be cancelled (state: ' . $advance->state . ').');
        }

        $before = $advance->fresh()->toArray();
        $advance->update(['state' => TreasuryAdvance::STATE_CANCELLED]);

        $operation = $advance->operation;
        if ($operation && $operation->isDraft()) {
            $operation->update(['state' => TreasuryOperation::STATE_CANCELLED]);
        }

        $this->audit($creatorId, self::AUDIT_ENTITY, $advance->id, 'cancelled', $before, $advance->fresh()->toArray(), $actorId);

        return $advance->fresh();
    }

    // ------------------------------------------------------------------
    // Disbursement — the cash outflow (plan §7)
    // ------------------------------------------------------------------

    /**
     * Preview a disbursement: the recheck results before confirm.
     * Options: posting_date (default today), adjustment (CA17).
     */
    public function previewDisburse(int $creatorId, int $advanceId, int $actorId, array $options = []): array
    {
        $options['executor_user_id'] = (int) ($options['executor_user_id'] ?? $actorId);
        $advance = $this->advanceForCompany($creatorId, $advanceId);
        $source = $this->accounts->accountForCompany($creatorId, (int) $advance->source_account_id);

        $posting = $this->resolvePostingDate($advance, $options);
        $checks = $this->recheckDisburse($creatorId, $advance, $source, $posting, $options);

        $canDisburse = true;
        foreach ($checks as $check) {
            if (! $check['passed']) {
                $canDisburse = false;
                break;
            }
        }

        return [
            'advance' => $advance,
            'source' => $source,
            'posting_date' => $posting['posting_date'],
            'posted_balance' => $this->accounts->postedBalance($source),
            'available_balance' => $this->accounts->availableBalance($source),
            'checks' => $checks,
            'can_disburse' => $canDisburse,
            'adjustment' => $posting['adjustment'],
        ];
    }

    /**
     * Disburse an approved advance. Posts Dr advance asset account
     * / Cr source account through the poster, records the outflow
     * entry on the disbursement operation, and allocates the
     * reference only now — a cancelled draft never consumes one.
     *
     * Options: posting_date (default today), adjustment (CA17).
     *
     * @return array{advance: TreasuryAdvance, operation: TreasuryOperation, journal: \App\Models\JournalEntry, entry: TreasuryEntry, posting_date: string, adjustment: array|null}
     */
    public function disburseAdvance(int $creatorId, int $advanceId, int $actorId, array $options = []): array
    {
        $options['executor_user_id'] = (int) ($options['executor_user_id'] ?? $actorId);

        return DB::transaction(function () use ($creatorId, $advanceId, $actorId, $options) {
            $advance = TreasuryAdvance::lockForUpdate()->findOrFail(
                $this->advanceForCompany($creatorId, $advanceId)->id
            );
            $source = TreasuryFinancialAccount::lockForUpdate()->findOrFail((int) $advance->source_account_id);

            $posting = $this->resolvePostingDate($advance, $options);

            // Transactional recheck under lock: every failure
            // refuses the disbursement and leaves the advance approved.
            $checks = $this->recheckDisburse($creatorId, $advance, $source, $posting, $options);
            foreach ($checks as $check) {
                if (! $check['passed']) {
                    throw new TreasuryException($check['detail']);
                }
            }

            $operation = $advance->operation()->lockForUpdate()->firstOrFail();
            $sourceChartId = $this->chartAccountId($source);
            $assetChartId = $this->resolveAdvanceAssetAccount($creatorId, (string) $advance->recipient_type);
            $postingDate = $posting['posting_date'];
            $amountBase2 = $this->round2((string) $advance->amount_approved_base);

            // The reference is allocated only now, when the advance
            // leaves the approved state — a cancelled request never
            // consumes a number.
            $operation->reference = $this->nextReference($creatorId, TreasuryOperation::TYPE_ADVANCE, $postingDate);
            $operation->state = TreasuryOperation::STATE_CONFIRMED;
            $operation->posting_date = $postingDate;
            $operation->amount_original = $advance->amount_approved;
            $operation->amount_base = $advance->amount_approved_base;
            $operation->save();

            $reference = (string) $operation->reference;

            $journal = $this->poster->post(
                $creatorId,
                $postingDate,
                $reference,
                'Treasury advance ' . $reference . ' — disbursement to ' . ($advance->recipient_name ?? $advance->recipient_type),
                [
                    ['account' => $assetChartId, 'debit' => $amountBase2, 'description' => 'Treasury advance ' . $reference . ' — ' . ($advance->recipient_name ?? $advance->recipient_type)],
                    ['account' => $sourceChartId, 'credit' => $amountBase2, 'description' => 'Treasury advance ' . $reference . ' — ' . $source->code],
                ]
            );

            $entry = $this->recordEntry($operation, $source, TreasuryEntry::DIRECTION_OUTFLOW, (string) $advance->amount_approved, (string) $advance->amount_approved_base, (string) $advance->exchange_rate, $journal->id, $creatorId);

            $advance->update([
                'state' => TreasuryAdvance::STATE_DISBURSED,
                'amount_paid' => $advance->amount_approved,
                'amount_paid_base' => $advance->amount_approved_base,
                'amount_outstanding' => $advance->amount_approved,
                'amount_outstanding_base' => $advance->amount_approved_base,
                'disbursed_at' => now(),
                'disbursement_journal_id' => $journal->id,
            ]);

            if (! $source->currency_locked) {
                $source->currency_locked = true;
                $source->save();
            }

            $this->audit($creatorId, self::AUDIT_ENTITY, $advance->id, 'disbursed', null, [
                'advance_id' => $advance->id,
                'reference' => $reference,
                'journal_id' => $journal->id,
                'amount_paid' => (string) $advance->amount_paid,
                'posting_date' => $postingDate,
            ], $actorId);

            return [
                'advance' => $advance->fresh(),
                'operation' => $operation->fresh(),
                'journal' => $journal,
                'entry' => $entry,
                'posting_date' => $postingDate,
                'adjustment' => $posting['adjustment'],
            ];
        });
    }

    /**
     * @return array<int, array{key: string, passed: bool, detail: string}>
     */
    protected function recheckDisburse(int $creatorId, TreasuryAdvance $advance, TreasuryFinancialAccount $source, array $posting, array $options): array
    {
        $checks = [];
        $add = function (string $key, bool $passed, string $detail) use (&$checks) {
            $checks[] = ['key' => $key, 'passed' => $passed, 'detail' => $detail];
        };

        // 1. State — approved, and the lock means a second
        // disbursement of the same advance sees the new state.
        $add('state', $advance->isApproved(), $advance->isApproved()
            ? 'Advance is approved and can be disbursed.'
            : 'Only an approved advance can be disbursed (state: ' . $advance->state . ').');

        // 2. Source account active.
        $add('source_account', $source->isActive(), $source->isActive()
            ? 'Source account ' . $source->code . ' is active.'
            : 'The source account ' . $source->code . ' is inactive.');

        // 3. Rate present (D2): never guess a rate.
        $rateOk = $advance->exchange_rate !== null && is_numeric($advance->exchange_rate) && bccomp((string) $advance->exchange_rate, '0', 10) > 0;
        $add('rate', $rateOk, $rateOk
            ? 'Exchange rate ' . $advance->exchange_rate . ' is stored.'
            : 'No exchange rate is stored for this advance — disbursement is blocked, never guess a rate (D2).');

        // 4. Closed period (CA17): blocked, unless an authorised
        // adjustment to an open period is supplied.
        $postingDate = Carbon::parse($posting['posting_date']);
        $periodOpen = $this->isPeriodOpen($creatorId, $postingDate);
        $adjustmentOk = $posting['adjustment'] !== null;
        $add('period', $periodOpen || $adjustmentOk, $periodOpen
            ? 'Posting period ' . $postingDate->year . ' is open.'
            : ($adjustmentOk
                ? 'Posting year ' . $postingDate->year . ' is closed — posting as an authorised adjustment to ' . $posting['posting_date'] . '.'
                : 'Fiscal year ' . $postingDate->year . ' is closed. Treasury postings into a closed period are blocked — an authorised adjustment to an open period is required (CA17).'));

        // 5. Availability: posted − active reservations must cover
        // the approved amount (base currency).
        $amountBase = (string) ($advance->amount_approved_base ?? '0.0000');
        $available = $this->accounts->availableBalance($source);
        $availabilityOk = bccomp($amountBase, $available, 4) <= 0;
        $add('availability', $availabilityOk, $availabilityOk
            ? 'Available ' . $available . ' covers ' . $amountBase . '.'
            : 'Insufficient available balance on account ' . $source->code . ': ' . $available . ' available for ' . $amountBase . ' required.');

        // 6. Negative-balance policy on the projected source balance.
        $projected = bcsub($this->accounts->postedBalance($source), $amountBase, 4);
        $policy = $this->accounts->checkNegativeBalance($source, $projected);
        $negativeOk = $policy === 'allowed';
        $negativeDetail = 'Projected balance ' . $projected . ' is allowed by the account policy.';
        if ($policy === 'blocked') {
            $negativeDetail = 'The account policy blocks this disbursement: the projected balance ' . $projected . ' would go below the allowed limit.';
        } elseif ($policy === 'needs_approval') {
            $approverId = (int) ($source->negative_balance_approver_user_id ?? 0);
            $executorId = (int) ($options['executor_user_id'] ?? 0);
            $negativeOk = $approverId > 0 && $executorId > 0 && $approverId === $executorId;
            $negativeDetail = $negativeOk
                ? 'Projected balance ' . $projected . ' needs approval, and the named approver is disbursing this advance.'
                : 'The projected balance ' . $projected . ' needs approval from the account approver (user #' . $approverId . ') before it can go negative.';
        }
        $add('negative_balance', $negativeOk, $negativeDetail);

        // 7. Posting accounts resolve (advance asset + source chart),
        // and the 2-decimal journal amount is not zero.
        $accountsOk = true;
        $accountsDetail = 'Posting accounts resolve.';
        try {
            $this->resolveAdvanceAssetAccount($creatorId, (string) $advance->recipient_type);
            $this->chartAccountId($source);
            if (bccomp($this->round2($amountBase), '0', 2) <= 0) {
                throw new TreasuryException('The approved amount is too small to post (it rounds to zero in the ledger).');
            }
        } catch (TreasuryException $e) {
            $accountsOk = false;
            $accountsDetail = $e->getMessage();
        }
        $add('posting_accounts', $accountsOk, $accountsDetail);

        return $checks;
    }

    // ------------------------------------------------------------------
    // Expense substantiation lines
    // ------------------------------------------------------------------

    /**
     * Add an expense line to a disbursed advance. The line starts
     * pending review; the advance moves to under_review while any
     * line awaits a decision.
     *
     * @param array{expense_date: string, counterparty?: string|null, category?: string|null, document?: string|null, amount: string, chart_account_id: int, cost_centre_id?: int|null, evidence?: string|null} $data
     */
    public function addLine(int $creatorId, int $advanceId, array $data, int $actorId): TreasuryAdvanceExpenseLine
    {
        return DB::transaction(function () use ($creatorId, $advanceId, $data, $actorId) {
            $advance = TreasuryAdvance::lockForUpdate()->findOrFail(
                $this->advanceForCompany($creatorId, $advanceId)->id
            );

            if (! $advance->isDisbursed()) {
                throw new TreasuryException('Expense lines can only be added while the advance is disbursed (state: ' . $advance->state . ').');
            }

            $validated = $this->validateLineData($creatorId, $advance, $data);

            $line = TreasuryAdvanceExpenseLine::create([
                'advance_id' => $advance->id,
                'expense_date' => $validated['expense_date'],
                'counterparty' => $validated['counterparty'],
                'category' => $validated['category'],
                'document' => $validated['document'],
                'amount' => $validated['amount'],
                'amount_base' => $validated['amount_base'],
                'chart_account_id' => $validated['chart_account_id'],
                'cost_centre_id' => $validated['cost_centre_id'],
                'evidence' => $validated['evidence'],
                'state' => TreasuryAdvanceExpenseLine::STATE_PENDING,
                'submitted_by' => $actorId,
            ]);

            $this->refreshState($advance->fresh());

            $this->audit($creatorId, self::AUDIT_ENTITY, $advance->id, 'line_added', null, $line->fresh()->toArray(), $actorId);

            return $line->fresh();
        });
    }

    /** Edit a line returned for correction (its amounts re-derive). */
    public function updateLine(TreasuryAdvanceExpenseLine $line, array $data, int $actorId): TreasuryAdvanceExpenseLine
    {
        if (! $line->isReturned()) {
            throw new TreasuryException('Only a line returned for correction can be edited (state: ' . $line->state . ').');
        }

        return DB::transaction(function () use ($line, $data, $actorId) {
            $advance = TreasuryAdvance::lockForUpdate()->findOrFail((int) $line->advance_id);
            // Lines carry no company column — scope through the advance.
            $validated = $this->validateLineData((int) $advance->created_by, $advance, $data);
            $before = $line->fresh()->toArray();

            $line->update([
                'expense_date' => $validated['expense_date'],
                'counterparty' => $validated['counterparty'],
                'category' => $validated['category'],
                'document' => $validated['document'],
                'amount' => $validated['amount'],
                'amount_base' => $validated['amount_base'],
                'chart_account_id' => $validated['chart_account_id'],
                'cost_centre_id' => $validated['cost_centre_id'],
                'evidence' => $validated['evidence'],
            ]);

            $this->audit((int) $advance->created_by, self::AUDIT_ENTITY, (int) $line->advance_id, 'line_updated', $before, $line->fresh()->toArray(), $actorId);

            return $line->fresh();
        });
    }

    /** Resubmit a returned line for review. */
    public function resubmitLine(int $creatorId, int $lineId, int $actorId): TreasuryAdvanceExpenseLine
    {
        $line = $this->lineForCompany($creatorId, $lineId);

        if (! $line->isReturned()) {
            throw new TreasuryException('Only a line returned for correction can be resubmitted (state: ' . $line->state . ').');
        }

        $line->update([
            'state' => TreasuryAdvanceExpenseLine::STATE_PENDING,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_reason' => null,
        ]);

        $this->refreshState($line->advance()->lockForUpdate()->firstOrFail());
        $this->audit($creatorId, self::AUDIT_ENTITY, (int) $line->advance_id, 'line_resubmitted', null, $line->fresh()->toArray(), $actorId);

        return $line->fresh();
    }

    /**
     * Review one line: approve (posts Dr expense / Cr advance asset,
     * capped at the advance balance still unsubstantiated — the
     * excess becomes the advance's reimbursement), reject (no
     * posting), or return for correction.
     *
     * Options: posting_date (default today), adjustment (CA17).
     *
     * @return array{line: TreasuryAdvanceExpenseLine, advance: TreasuryAdvance, journal: \App\Models\JournalEntry|null, operation: TreasuryOperation|null, movable_amount: string, movable_amount_base: string}
     */
    public function reviewLine(int $creatorId, int $lineId, string $decision, int $actorId, ?string $reason = null, array $options = []): array
    {
        $decision = strtolower(trim($decision));
        if (! in_array($decision, ['approve', 'reject', 'return'], true)) {
            throw new TreasuryException('A line review must approve, reject, or return the line for correction.');
        }

        return DB::transaction(function () use ($creatorId, $lineId, $decision, $actorId, $reason, $options) {
            $line = TreasuryAdvanceExpenseLine::lockForUpdate()->findOrFail(
                $this->lineForCompany($creatorId, $lineId)->id
            );
            $advance = TreasuryAdvance::lockForUpdate()->findOrFail((int) $line->advance_id);

            if (! $line->isPending()) {
                throw new TreasuryException('Only a pending line can be reviewed (state: ' . $line->state . ').');
            }
            if ((int) $line->submitted_by === $actorId) {
                throw new TreasuryException('You cannot review an expense line you submitted yourself.');
            }
            if (! $this->userHasPermission($creatorId, $actorId, 'manage treasury advances')) {
                throw new TreasuryException('The reviewer does not hold the manage treasury advances permission.');
            }
            if ($decision !== 'approve' && trim((string) $reason) === '') {
                throw new TreasuryException('A rejection or return needs a reason.');
            }

            $journal = null;
            $operation = null;
            $movable = '0.0000';
            $movableBase = '0.0000';

            if ($decision === 'approve') {
                $posting = $this->resolvePostingDate($advance, $options);
                $postingDate = Carbon::parse($posting['posting_date']);
                if (! $this->isPeriodOpen($creatorId, $postingDate) && $posting['adjustment'] === null) {
                    throw new TreasuryException('Fiscal year ' . $postingDate->year . ' is closed. Treasury postings into a closed period are blocked — an authorised adjustment to an open period is required (CA17).');
                }

                // Cap the substantiation at the advance balance still
                // unsubstantiated — the excess is the reimbursement,
                // never a second expense posting.
                $remaining = bcsub(bcsub((string) $advance->amount_paid, (string) $advance->amount_substantiated, 4), (string) $advance->amount_returned, 4);
                if (bccomp($remaining, '0', 4) < 0) {
                    $remaining = '0.0000';
                }
                $movable = bccomp((string) $line->amount, $remaining, 4) <= 0 ? (string) $line->amount : $remaining;
                $excess = bcsub((string) $line->amount, $movable, 4);
                $rate = (string) ($advance->exchange_rate ?? '1');
                $movableBase = bcmul($movable, $rate, 4);
                $excessBase = bcmul($excess, $rate, 4);

                $assetChartId = $this->resolveAdvanceAssetAccount($creatorId, (string) $advance->recipient_type);
                $movableBase2 = $this->round2($movableBase);

                if (bccomp($movableBase2, '0', 2) > 0) {
                    $reference = $this->nextReference($creatorId, TreasuryOperation::TYPE_ADVANCE, $posting['posting_date']);

                    $operation = TreasuryOperation::create([
                        'reference' => $reference,
                        'type' => TreasuryOperation::TYPE_ADVANCE,
                        'company_id' => $creatorId,
                        'branch_id' => $advance->branch_id,
                        'financial_account_id' => $advance->source_account_id,
                        'counterparty_type' => $advance->recipient_type === TreasuryAdvance::RECIPIENT_EMPLOYEE ? 'employee' : 'vendor',
                        'counterparty_id' => $advance->recipient_id,
                        'currency' => $advance->currency,
                        'amount_original' => $movable,
                        'exchange_rate' => $advance->exchange_rate,
                        'rate_source' => $advance->rate_source,
                        'rate_date' => $advance->rate_date,
                        'amount_base' => $movableBase,
                        'posting_date' => $posting['posting_date'],
                        'effective_date' => $line->expense_date ? $line->expense_date->format('Y-m-d') : $posting['posting_date'],
                        'value_date' => $posting['posting_date'],
                        'state' => TreasuryOperation::STATE_CONFIRMED,
                        'source_type' => 'treasury_advance_line',
                        'source_id' => $line->id,
                        'created_by' => $creatorId,
                    ]);

                    $journal = $this->poster->post(
                        $creatorId,
                        $posting['posting_date'],
                        $reference,
                        'Treasury advance ' . $reference . ' — expense substantiation (' . ($line->category ?? 'expense') . ')',
                        [
                            ['account' => (int) $line->chart_account_id, 'debit' => $movableBase2, 'description' => 'Treasury advance ' . $reference . ' — ' . ($line->category ?? 'expense') . ' (' . ($line->document ?? 'no document') . ')'],
                            ['account' => $assetChartId, 'credit' => $movableBase2, 'description' => 'Treasury advance ' . $reference . ' — ' . ($advance->recipient_name ?? $advance->recipient_type)],
                        ]
                    );
                }

                $line->update([
                    'state' => TreasuryAdvanceExpenseLine::STATE_APPROVED,
                    'reviewed_by' => $actorId,
                    'reviewed_at' => now(),
                    'review_reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
                    'journal_id' => $journal ? $journal->id : null,
                    'operation_id' => $operation ? $operation->id : null,
                    'reimbursement_amount' => $excess,
                    'reimbursement_amount_base' => $excessBase,
                ]);

                // Tag the recognised expense with the line's cost
                // centre (Cost Centre module's table — no new table).
                if ($line->cost_centre_id) {
                    $this->tagLineDimension($creatorId, $line, $movableBase, $actorId);
                }

                $this->refreshAmounts($advance->fresh());
                $this->refreshState($advance->fresh());

                $this->audit($creatorId, self::AUDIT_ENTITY, $advance->id, 'line_approved', null, [
                    'line_id' => $line->id,
                    'amount' => (string) $line->amount,
                    'substantiated' => $movable,
                    'reimbursement' => $excess,
                    'journal_id' => $journal ? $journal->id : null,
                ], $actorId);
            } elseif ($decision === 'reject') {
                $line->update([
                    'state' => TreasuryAdvanceExpenseLine::STATE_REJECTED,
                    'reviewed_by' => $actorId,
                    'reviewed_at' => now(),
                    'review_reason' => trim((string) $reason),
                ]);

                $this->refreshState($advance->fresh());
                $this->audit($creatorId, self::AUDIT_ENTITY, $advance->id, 'line_rejected', null, $line->fresh()->toArray(), $actorId);
            } else {
                $line->update([
                    'state' => TreasuryAdvanceExpenseLine::STATE_RETURNED,
                    'reviewed_by' => $actorId,
                    'reviewed_at' => now(),
                    'review_reason' => trim((string) $reason),
                ]);

                $this->refreshState($advance->fresh());
                $this->audit($creatorId, self::AUDIT_ENTITY, $advance->id, 'line_returned', null, $line->fresh()->toArray(), $actorId);
            }

            return [
                'line' => $line->fresh(),
                'advance' => $advance->fresh(),
                'journal' => $journal,
                'operation' => $operation,
                'movable_amount' => $movable,
                'movable_amount_base' => $movableBase,
            ];
        });
    }

    // ------------------------------------------------------------------
    // Regularisation — return of unspent money (CA10)
    // ------------------------------------------------------------------

    /**
     * Return unspent money: Dr source account / Cr advance asset
     * account, on its own confirmed operation (the remainder
     * reversed). CA10: a return reverses the advance asset — it
     * never posts a second expense outflow.
     *
     * Options: posting_date (default today), adjustment (CA17).
     *
     * @return array{advance: TreasuryAdvance, operation: TreasuryOperation, journal: \App\Models\JournalEntry, entry: TreasuryEntry, posting_date: string, adjustment: array|null}
     */
    public function returnUnspent(int $creatorId, int $advanceId, string $amount, int $actorId, ?string $reason = null, array $options = []): array
    {
        $options['executor_user_id'] = (int) ($options['executor_user_id'] ?? $actorId);

        return DB::transaction(function () use ($creatorId, $advanceId, $amount, $actorId, $reason, $options) {
            $advance = TreasuryAdvance::lockForUpdate()->findOrFail(
                $this->advanceForCompany($creatorId, $advanceId)->id
            );

            if (! $advance->isDisbursed()) {
                throw new TreasuryException('Unspent money can only be returned while the advance is disbursed (state: ' . $advance->state . ').');
            }

            $return = $this->decimalString($amount, 'Return amount');
            if (bccomp($return, '0', 4) <= 0) {
                throw new TreasuryException('The return amount must be greater than zero.');
            }
            if (bccomp($return, (string) $advance->amount_outstanding, 4) > 0) {
                throw new TreasuryException('The return (' . $return . ') exceeds the outstanding balance (' . $advance->amount_outstanding . ').');
            }

            $source = TreasuryFinancialAccount::lockForUpdate()->findOrFail((int) $advance->source_account_id);
            $posting = $this->resolvePostingDate($advance, $options);
            $postingDate = Carbon::parse($posting['posting_date']);
            if (! $this->isPeriodOpen($creatorId, $postingDate) && $posting['adjustment'] === null) {
                throw new TreasuryException('Fiscal year ' . $postingDate->year . ' is closed. Treasury postings into a closed period are blocked — an authorised adjustment to an open period is required (CA17).');
            }

            $rate = (string) ($advance->exchange_rate ?? '1');
            $returnBase = bcmul($return, $rate, 4);
            if (bccomp($this->round2($returnBase), '0', 2) <= 0) {
                throw new TreasuryException('The return amount is too small to post (it rounds to zero in the ledger).');
            }

            $sourceChartId = $this->chartAccountId($source);
            $assetChartId = $this->resolveAdvanceAssetAccount($creatorId, (string) $advance->recipient_type);
            $reference = $this->nextReference($creatorId, TreasuryOperation::TYPE_ADVANCE, $posting['posting_date']);

            $operation = TreasuryOperation::create([
                'reference' => $reference,
                'type' => TreasuryOperation::TYPE_ADVANCE,
                'company_id' => $creatorId,
                'branch_id' => $advance->branch_id,
                'financial_account_id' => $source->id,
                'counterparty_type' => $advance->recipient_type === TreasuryAdvance::RECIPIENT_EMPLOYEE ? 'employee' : 'vendor',
                'counterparty_id' => $advance->recipient_id,
                'currency' => $advance->currency,
                'amount_original' => $return,
                'exchange_rate' => $advance->exchange_rate,
                'rate_source' => $advance->rate_source,
                'rate_date' => $advance->rate_date,
                'amount_base' => $returnBase,
                'posting_date' => $posting['posting_date'],
                'effective_date' => $posting['posting_date'],
                'value_date' => $posting['posting_date'],
                'state' => TreasuryOperation::STATE_CONFIRMED,
                'source_type' => 'treasury_advance_return',
                'source_id' => $advance->id,
                'created_by' => $creatorId,
            ]);

            $returnBase2 = $this->round2($returnBase);
            $journal = $this->poster->post(
                $creatorId,
                $posting['posting_date'],
                $reference,
                'Treasury advance ' . $reference . ' — return of unspent money (' . ($advance->recipient_name ?? $advance->recipient_type) . ')',
                [
                    ['account' => $sourceChartId, 'debit' => $returnBase2, 'description' => 'Treasury advance ' . $reference . ' — return to ' . $source->code],
                    ['account' => $assetChartId, 'credit' => $returnBase2, 'description' => 'Treasury advance ' . $reference . ' — ' . ($advance->recipient_name ?? $advance->recipient_type)],
                ]
            );

            $entry = $this->recordEntry($operation, $source, TreasuryEntry::DIRECTION_INFLOW, $return, $returnBase, $rate, $journal->id, $creatorId);

            $advance->update([
                'amount_returned' => bcadd((string) $advance->amount_returned, $return, 4),
                'amount_returned_base' => bcadd((string) $advance->amount_returned_base, $returnBase, 4),
            ]);
            $this->refreshAmounts($advance->fresh());
            $this->refreshState($advance->fresh());

            $this->audit($creatorId, self::AUDIT_ENTITY, $advance->id, 'unspent_returned', null, [
                'advance_id' => $advance->id,
                'reference' => $reference,
                'journal_id' => $journal->id,
                'amount' => $return,
                'reason' => $reason,
            ], $actorId);

            return [
                'advance' => $advance->fresh(),
                'operation' => $operation->fresh(),
                'journal' => $journal,
                'entry' => $entry,
                'posting_date' => $posting['posting_date'],
                'adjustment' => $posting['adjustment'],
            ];
        });
    }

    // ------------------------------------------------------------------
    // Reimbursement — approved expenses above the paid amount
    // ------------------------------------------------------------------

    /** Approve the reimbursement: a different user than the requester. */
    public function approveReimbursement(int $creatorId, int $advanceId, int $actorId, ?string $reason = null): TreasuryAdvance
    {
        $advance = $this->advanceForCompany($creatorId, $advanceId);

        if ($advance->reimbursement_state !== TreasuryAdvance::REIMBURSEMENT_PENDING) {
            throw new TreasuryException('There is no pending reimbursement to approve (state: ' . $advance->reimbursement_state . ').');
        }
        if ((int) $advance->requested_by === $actorId) {
            throw new TreasuryException('You cannot approve a reimbursement for an advance you requested yourself.');
        }
        if (! $this->userHasPermission($creatorId, $actorId, 'manage treasury advances')) {
            throw new TreasuryException('The approver does not hold the manage treasury advances permission.');
        }

        $before = $advance->fresh()->toArray();
        $advance->update([
            'reimbursement_state' => TreasuryAdvance::REIMBURSEMENT_APPROVED,
            'reimbursement_approved_by' => $actorId,
            'reimbursement_approved_at' => now(),
            'reimbursement_reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : $advance->reimbursement_reason,
        ]);
        $this->audit($creatorId, self::AUDIT_ENTITY, $advance->id, 'reimbursement_approved', $before, $advance->fresh()->toArray(), $actorId);

        return $advance->fresh();
    }

    /** Decline the reimbursement: the excess stays the recipient's cost. */
    public function rejectReimbursement(int $creatorId, int $advanceId, int $actorId, string $reason): TreasuryAdvance
    {
        $advance = $this->advanceForCompany($creatorId, $advanceId);

        if ($advance->reimbursement_state !== TreasuryAdvance::REIMBURSEMENT_PENDING) {
            throw new TreasuryException('There is no pending reimbursement to reject (state: ' . $advance->reimbursement_state . ').');
        }
        if ((int) $advance->requested_by === $actorId) {
            throw new TreasuryException('You cannot reject a reimbursement for an advance you requested yourself.');
        }
        if (! $this->userHasPermission($creatorId, $actorId, 'manage treasury advances')) {
            throw new TreasuryException('The approver does not hold the manage treasury advances permission.');
        }
        if (trim($reason) === '') {
            throw new TreasuryException('A rejection needs a reason.');
        }

        $before = $advance->fresh()->toArray();
        $advance->update([
            'reimbursement_state' => TreasuryAdvance::REIMBURSEMENT_REJECTED,
            'reimbursement_approved_by' => $actorId,
            'reimbursement_approved_at' => now(),
            'reimbursement_reason' => trim($reason),
        ]);
        $this->refreshState($advance->fresh());
        $this->audit($creatorId, self::AUDIT_ENTITY, $advance->id, 'reimbursement_rejected', $before, $advance->fresh()->toArray(), $actorId);

        return $advance->fresh();
    }

    /**
     * Pay an approved reimbursement: Dr expense account(s) / Cr
     * source account, grouped by the lines' expense accounts so
     * each expense is recognised exactly once. The outflow is an
     * entry on its own confirmed operation.
     *
     * Options: posting_date (default today), adjustment (CA17).
     *
     * @return array{advance: TreasuryAdvance, operation: TreasuryOperation, journal: \App\Models\JournalEntry, entry: TreasuryEntry, posting_date: string, adjustment: array|null}
     */
    public function payReimbursement(int $creatorId, int $advanceId, int $actorId, array $options = []): array
    {
        $options['executor_user_id'] = (int) ($options['executor_user_id'] ?? $actorId);

        return DB::transaction(function () use ($creatorId, $advanceId, $actorId, $options) {
            $advance = TreasuryAdvance::lockForUpdate()->findOrFail(
                $this->advanceForCompany($creatorId, $advanceId)->id
            );

            if ($advance->reimbursement_state !== TreasuryAdvance::REIMBURSEMENT_APPROVED) {
                throw new TreasuryException('Only an approved reimbursement can be paid (state: ' . $advance->reimbursement_state . ').');
            }

            $source = TreasuryFinancialAccount::lockForUpdate()->findOrFail((int) $advance->source_account_id);
            $posting = $this->resolvePostingDate($advance, $options);
            $postingDate = Carbon::parse($posting['posting_date']);
            if (! $this->isPeriodOpen($creatorId, $postingDate) && $posting['adjustment'] === null) {
                throw new TreasuryException('Fiscal year ' . $postingDate->year . ' is closed. Treasury postings into a closed period are blocked — an authorised adjustment to an open period is required (CA17).');
            }

            // Group the excess by expense account — one Dr line per
            // account, so each expense is recognised exactly once.
            $groups = [];
            foreach ($advance->lines()->where('state', TreasuryAdvanceExpenseLine::STATE_APPROVED)->get() as $line) {
                if (bccomp((string) $line->reimbursement_amount_base, '0', 4) <= 0) {
                    continue;
                }
                $key = (int) $line->chart_account_id;
                $groups[$key] = bcadd($groups[$key] ?? '0.0000', $this->round2((string) $line->reimbursement_amount_base), 2);
            }
            $groups = array_filter($groups, fn ($v) => bccomp($v, '0', 2) > 0);
            if ($groups === []) {
                throw new TreasuryException('The approved reimbursement has no amount to pay.');
            }

            $totalBase2 = '0.00';
            foreach ($groups as $amount2) {
                $totalBase2 = bcadd($totalBase2, $amount2, 2);
            }

            // Availability + negative-balance policy on the source.
            $available = $this->accounts->availableBalance($source);
            if (bccomp($totalBase2, $available, 2) > 0) {
                throw new TreasuryException('Insufficient available balance on account ' . $source->code . ': ' . $available . ' available for ' . $totalBase2 . ' required.');
            }
            $projected = bcsub($this->accounts->postedBalance($source), $totalBase2, 4);
            $policy = $this->accounts->checkNegativeBalance($source, $projected);
            if ($policy === 'blocked') {
                throw new TreasuryException('The account policy blocks this payment: the projected balance ' . $projected . ' would go below the allowed limit.');
            }
            if ($policy === 'needs_approval') {
                $approverId = (int) ($source->negative_balance_approver_user_id ?? 0);
                if ($approverId <= 0 || $approverId !== $actorId) {
                    throw new TreasuryException('The projected balance ' . $projected . ' needs approval from the account approver (user #' . $approverId . ') before it can go negative.');
                }
            }

            $sourceChartId = $this->chartAccountId($source);
            $reference = $this->nextReference($creatorId, TreasuryOperation::TYPE_ADVANCE, $posting['posting_date']);

            $operation = TreasuryOperation::create([
                'reference' => $reference,
                'type' => TreasuryOperation::TYPE_ADVANCE,
                'company_id' => $creatorId,
                'branch_id' => $advance->branch_id,
                'financial_account_id' => $source->id,
                'counterparty_type' => $advance->recipient_type === TreasuryAdvance::RECIPIENT_EMPLOYEE ? 'employee' : 'vendor',
                'counterparty_id' => $advance->recipient_id,
                'currency' => $advance->currency,
                'amount_original' => $advance->reimbursement_amount,
                'exchange_rate' => $advance->exchange_rate,
                'rate_source' => $advance->rate_source,
                'rate_date' => $advance->rate_date,
                'amount_base' => $advance->reimbursement_amount_base,
                'posting_date' => $posting['posting_date'],
                'effective_date' => $posting['posting_date'],
                'value_date' => $posting['posting_date'],
                'state' => TreasuryOperation::STATE_CONFIRMED,
                'source_type' => 'treasury_advance_reimbursement',
                'source_id' => $advance->id,
                'created_by' => $creatorId,
            ]);

            $lines = [];
            foreach ($groups as $chartId => $amount2) {
                $lines[] = ['account' => $chartId, 'debit' => $amount2, 'description' => 'Treasury advance ' . $reference . ' — reimbursement to ' . ($advance->recipient_name ?? $advance->recipient_type)];
            }
            $lines[] = ['account' => $sourceChartId, 'credit' => $totalBase2, 'description' => 'Treasury advance ' . $reference . ' — ' . $source->code];

            $journal = $this->poster->post(
                $creatorId,
                $posting['posting_date'],
                $reference,
                'Treasury advance ' . $reference . ' — reimbursement to ' . ($advance->recipient_name ?? $advance->recipient_type),
                $lines
            );

            $entry = $this->recordEntry($operation, $source, TreasuryEntry::DIRECTION_OUTFLOW, (string) $advance->reimbursement_amount, (string) $advance->reimbursement_amount_base, (string) $advance->exchange_rate, $journal->id, $creatorId);

            $advance->update([
                'reimbursement_state' => TreasuryAdvance::REIMBURSEMENT_PAID,
                'reimbursement_paid_at' => now(),
                'reimbursement_journal_id' => $journal->id,
                'reimbursement_operation_id' => $operation->id,
            ]);
            $this->refreshState($advance->fresh());

            $this->audit($creatorId, self::AUDIT_ENTITY, $advance->id, 'reimbursement_paid', null, [
                'advance_id' => $advance->id,
                'reference' => $reference,
                'journal_id' => $journal->id,
                'amount' => (string) $advance->reimbursement_amount,
            ], $actorId);

            return [
                'advance' => $advance->fresh(),
                'operation' => $operation->fresh(),
                'journal' => $journal,
                'entry' => $entry,
                'posting_date' => $posting['posting_date'],
                'adjustment' => $posting['adjustment'],
            ];
        });
    }

    // ------------------------------------------------------------------
    // Closure
    // ------------------------------------------------------------------

    /** Close a regularised advance — only when the balance is cleared. */
    public function closeAdvance(int $creatorId, int $advanceId, int $actorId): TreasuryAdvance
    {
        $advance = $this->advanceForCompany($creatorId, $advanceId);

        if (! $advance->isRegularised()) {
            throw new TreasuryException('Only a regularised advance can be closed (state: ' . $advance->state . '). Return the unspent money and resolve every expense line first.');
        }

        if ($advance->hasOpenReimbursement()) {
            throw new TreasuryException('The reimbursement (' . $advance->reimbursement_state . ') must be paid or rejected before the advance can be closed — the money is still owed to the recipient.');
        }

        $before = $advance->fresh()->toArray();
        $advance->update([
            'state' => TreasuryAdvance::STATE_CLOSED,
            'closed_at' => now(),
            'closed_by' => $actorId,
        ]);
        $this->audit($creatorId, self::AUDIT_ENTITY, $advance->id, 'closed', $before, $advance->fresh()->toArray(), $actorId);

        return $advance->fresh();
    }

    // ------------------------------------------------------------------
    // Derived figures + state (recomputed from the rows, never drift)
    // ------------------------------------------------------------------

    /**
     * Recompute substantiated / reimbursement / outstanding from
     * the approved lines + returns. Called after every mutation
     * that changes them, so the advance row always agrees with
     * its lines.
     */
    protected function refreshAmounts(TreasuryAdvance $advance): void
    {
        $substantiated = '0.0000';
        $substantiatedBase = '0.0000';
        $reimbursement = '0.0000';
        $reimbursementBase = '0.0000';

        foreach ($advance->lines()->where('state', TreasuryAdvanceExpenseLine::STATE_APPROVED)->get() as $line) {
            // Substantiated is the FULL approved expense total — the
            // spec example is 5,000 advance / 5,300 expenses. The
            // part past the advance is the reimbursement below, not
            // something hidden from the substantiated figure.
            $substantiated = bcadd($substantiated, (string) $line->amount, 4);
            $substantiatedBase = bcadd($substantiatedBase, (string) $line->amount_base, 4);
            $reimbursement = bcadd($reimbursement, (string) $line->reimbursement_amount, 4);
            $reimbursementBase = bcadd($reimbursementBase, (string) $line->reimbursement_amount_base, 4);
        }

        $outstanding = bcsub(bcsub((string) $advance->amount_paid, $substantiated, 4), (string) $advance->amount_returned, 4);
        $outstandingBase = bcsub(bcsub((string) $advance->amount_paid_base, $substantiatedBase, 4), (string) $advance->amount_returned_base, 4);

        $updates = [
            'amount_substantiated' => $substantiated,
            'amount_substantiated_base' => $substantiatedBase,
            'amount_outstanding' => bccomp($outstanding, '0', 4) < 0 ? '0.0000' : $outstanding,
            'amount_outstanding_base' => bccomp($outstandingBase, '0', 4) < 0 ? '0.0000' : $outstandingBase,
            'reimbursement_amount' => $reimbursement,
            'reimbursement_amount_base' => $reimbursementBase,
        ];

        // A reimbursement appears the moment an approved line runs
        // past the paid amount — it starts pending a decision.
        if (bccomp($reimbursement, '0', 4) > 0 && $advance->reimbursement_state === TreasuryAdvance::REIMBURSEMENT_NONE) {
            $updates['reimbursement_state'] = TreasuryAdvance::REIMBURSEMENT_PENDING;
        }

        $advance->update($updates);
    }

    /**
     * Recompute the workflow state from the lines + figures. Only
     * runs while the advance is disbursed — drafts, approvals,
     * rejections and closures are explicit transitions, never
     * derived.
     */
    protected function refreshState(TreasuryAdvance $advance): void
    {
        if (! $advance->isDisbursed()) {
            return;
        }

        $fresh = $advance->fresh();
        $pending = $fresh->lines()->where('state', TreasuryAdvanceExpenseLine::STATE_PENDING)->exists();
        $returned = $fresh->lines()->where('state', TreasuryAdvanceExpenseLine::STATE_RETURNED)->exists();

        if ($pending || $returned) {
            $state = TreasuryAdvance::STATE_UNDER_REVIEW;
        } elseif (bccomp((string) $fresh->amount_outstanding, '0', 4) <= 0) {
            // The advance itself is settled — nothing left to
            // return. An open reimbursement is a separate payable
            // with its own approve/pay flow; it does not hold the
            // advance out of regularised (closeAdvance still
            // refuses while one is open).
            $state = TreasuryAdvance::STATE_REGULARISED;
        } elseif (bccomp((string) $fresh->amount_substantiated, '0', 4) > 0) {
            $state = TreasuryAdvance::STATE_PARTIALLY_SUBSTANTIATED;
        } else {
            $state = TreasuryAdvance::STATE_DISBURSED;
        }

        if ($state !== $fresh->state) {
            $fresh->update(['state' => $state]);
        }
    }

    /**
     * Tag an approved line's recognised expense with its cost
     * centre, on the Cost Centre module's table (no new table).
     * A rejected or returned line supersedes its rows.
     */
    protected function tagLineDimension(int $creatorId, TreasuryAdvanceExpenseLine $line, string $allocatedBase, int $actorId): void
    {
        if (! Schema::hasTable('treasury_line_dimensions')) {
            return;
        }

        TreasuryLineDimension::create([
            'company_id' => $creatorId,
            'created_by' => $creatorId,
            'payment_type' => TreasuryLineDimension::TYPE_TREASURY_ADVANCE,
            'payment_id' => $line->id,
            'bill_id' => null,
            'cost_centre_id' => $line->cost_centre_id,
            'project_id' => null,
            'asset_id' => null,
            'allocated_amount' => $allocatedBase,
            'percentage' => '100.000000',
            'source' => TreasuryLineDimension::SOURCE_MANUAL,
            'status' => TreasuryLineDimension::STATUS_ACTIVE,
        ]);
    }

    // ------------------------------------------------------------------
    // Draft + line validation
    // ------------------------------------------------------------------

    /**
     * @return array{source: TreasuryFinancialAccount, recipient_type: string, recipient_id: int, recipient_name: string|null, branch_id: int|null, purpose: string|null, currency: string, exchange_rate: string, rate_source: string|null, rate_date: string|null, amount_requested: string, amount_requested_base: string, due_date: string|null, request_date: string}
     */
    protected function validateDraftData(int $creatorId, array $data): array
    {
        $sourceId = (int) ($data['source_account_id'] ?? 0);
        if ($sourceId <= 0) {
            throw new TreasuryException('A source account is required.');
        }
        $source = $this->accounts->accountForCompany($creatorId, $sourceId);
        if (! $source->isActive()) {
            throw new TreasuryException('The source account ' . $source->code . ' is inactive.');
        }
        if ($source->type === TreasuryFinancialAccount::TYPE_FUNDS_IN_TRANSIT) {
            throw new TreasuryException('A funds-in-transit account cannot fund an advance — it only holds money between legs.');
        }

        $recipientType = (string) ($data['recipient_type'] ?? '');
        if (! in_array($recipientType, TreasuryAdvance::RECIPIENT_TYPES, true)) {
            throw new TreasuryException('The recipient must be an employee or a supplier.');
        }
        $recipientId = (int) ($data['recipient_id'] ?? 0);
        if ($recipientId <= 0) {
            throw new TreasuryException('A recipient is required.');
        }
        $recipientName = $this->resolveRecipientName($creatorId, $recipientType, $recipientId);

        // Optional block: a recipient with money still outstanding
        // cannot take a second advance while the company has the
        // block on (off by default).
        if (TreasurySettings::get($creatorId, TreasurySettings::KEY_ADVANCE_BLOCK_ON_OUTSTANDING) === '1'
            && $this->recipientHasOpenAdvance($creatorId, $recipientType, $recipientId)
        ) {
            throw new TreasuryException('This recipient still has an outstanding advance. The company blocks a new advance until it is regularised.');
        }

        $amount = $this->decimalString($data['amount_requested'] ?? null, 'Amount requested');
        if (bccomp($amount, '0', 4) <= 0) {
            throw new TreasuryException('The requested amount must be greater than zero.');
        }

        $currency = (string) $source->currency;
        [$rate, $rateSource, $rateDate, $amountBase] = $this->accounts->convertToBase($creatorId, $currency, $amount, [
            'exchange_rate' => $data['exchange_rate'] ?? null,
            'rate_source' => $data['rate_source'] ?? null,
            'rate_date' => $data['rate_date'] ?? null,
        ]);

        $dueDate = $this->nullableDate($data['due_date'] ?? null, 'Due date');
        $requestDate = $this->nullableDate($data['request_date'] ?? null, 'Request date') ?? date('Y-m-d');

        return [
            'source' => $source,
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'recipient_name' => $recipientName,
            'branch_id' => isset($data['branch_id']) && (int) $data['branch_id'] > 0 ? (int) $data['branch_id'] : null,
            'purpose' => isset($data['purpose']) && trim((string) $data['purpose']) !== '' ? trim((string) $data['purpose']) : null,
            'currency' => $currency,
            'exchange_rate' => $rate ?? '1.0000000000',
            'rate_source' => $rateSource,
            'rate_date' => $rateDate,
            'amount_requested' => $amount,
            'amount_requested_base' => $amountBase,
            'due_date' => $dueDate,
            'request_date' => $requestDate,
        ];
    }

    /**
     * @return array{expense_date: string, counterparty: string|null, category: string|null, document: string|null, amount: string, amount_base: string, chart_account_id: int, cost_centre_id: int|null, evidence: string|null}
     */
    protected function validateLineData(int $creatorId, TreasuryAdvance $advance, array $data): array
    {
        $expenseDate = $this->nullableDate($data['expense_date'] ?? null, 'Expense date') ?? date('Y-m-d');

        $amount = $this->decimalString($data['amount'] ?? null, 'Amount');
        if (bccomp($amount, '0', 4) <= 0) {
            throw new TreasuryException('The expense amount must be greater than zero.');
        }

        $chartAccountId = (int) ($data['chart_account_id'] ?? 0);
        if ($chartAccountId <= 0) {
            throw new TreasuryException('An expense account is required for the line.');
        }
        $chart = ChartOfAccount::where('created_by', $creatorId)->find($chartAccountId);
        if (! $chart) {
            throw new TreasuryException('The expense account does not belong to this company.');
        }

        $costCentreId = isset($data['cost_centre_id']) && (int) $data['cost_centre_id'] > 0 ? (int) $data['cost_centre_id'] : null;
        if ($costCentreId !== null) {
            $centre = CostCentre::where('created_by', $creatorId)->find($costCentreId);
            if (! $centre) {
                throw new TreasuryException('The cost centre does not belong to this company.');
            }
        }

        $rate = (string) ($advance->exchange_rate ?? '1');
        $amountBase = bcmul($amount, $rate, 4);

        return [
            'expense_date' => $expenseDate,
            'counterparty' => isset($data['counterparty']) && trim((string) $data['counterparty']) !== '' ? trim((string) $data['counterparty']) : null,
            'category' => isset($data['category']) && trim((string) $data['category']) !== '' ? trim((string) $data['category']) : null,
            'document' => isset($data['document']) && trim((string) $data['document']) !== '' ? trim((string) $data['document']) : null,
            'amount' => $amount,
            'amount_base' => $amountBase,
            'chart_account_id' => $chartAccountId,
            'cost_centre_id' => $costCentreId,
            'evidence' => isset($data['evidence']) && trim((string) $data['evidence']) !== '' ? trim((string) $data['evidence']) : null,
        ];
    }

    protected function resolveRecipientName(int $creatorId, string $recipientType, int $recipientId): ?string
    {
        if ($recipientType === TreasuryAdvance::RECIPIENT_EMPLOYEE) {
            $employee = Employee::where('created_by', $creatorId)->find($recipientId);
            if (! $employee) {
                throw new TreasuryException('The employee does not belong to this company.');
            }

            return (string) ($employee->name ?? '');
        }

        $supplier = Vender::where('created_by', $creatorId)->find($recipientId);
        if (! $supplier) {
            throw new TreasuryException('The supplier does not belong to this company.');
        }

        return (string) ($supplier->name ?? '');
    }

    protected function recipientHasOpenAdvance(int $creatorId, string $recipientType, int $recipientId): bool
    {
        return TreasuryAdvance::where('created_by', $creatorId)
            ->where('recipient_type', $recipientType)
            ->where('recipient_id', $recipientId)
            ->whereIn('state', TreasuryAdvance::DISBURSED_STATES)
            ->where('amount_outstanding', '>', 0)
            ->exists();
    }

    protected function recordEntry(TreasuryOperation $operation, TreasuryFinancialAccount $account, string $direction, string $amountOriginal, string $amountBase, string $rate, int $journalId, int $creatorId): TreasuryEntry
    {
        return TreasuryEntry::create([
            'operation_id' => $operation->id,
            'financial_account_id' => $account->id,
            'direction' => $direction,
            'amount_original' => $amountOriginal,
            'amount_base' => $amountBase,
            'exchange_rate' => $rate,
            'confirmed_at' => now(),
            'journal_id' => $journalId,
            'created_by' => $creatorId,
        ]);
    }

    protected function chartAccountId(?TreasuryFinancialAccount $account): int
    {
        $id = $account && $account->bankAccount ? (int) $account->bankAccount->chart_account_id : 0;
        if ($id <= 0) {
            throw new TreasuryException('The financial account has no chart account. Set one on the account before confirming.');
        }

        return $id;
    }

    // ------------------------------------------------------------------
    // References, posting accounts, periods
    // ------------------------------------------------------------------

    /** Next reference for the company + type + year, serialised (Phase 1/2 pattern). */
    protected function nextReference(int $creatorId, string $type, string $date): string
    {
        $year = Carbon::parse($date)->year;
        $code = TreasuryOperation::TYPE_CODES[$type] ?? 'OPR';
        $prefix = $this->companyReferencePrefix($creatorId);

        $head = $prefix . '-' . $code . '-' . $year . '-';

        $max = 0;
        $existing = TreasuryOperation::where('created_by', $creatorId)
            ->where('type', $type)
            ->where('reference', 'like', $head . '%')
            ->lockForUpdate()
            ->pluck('reference');

        foreach ($existing as $reference) {
            $suffix = substr((string) $reference, strlen($head));
            if (ctype_digit($suffix)) {
                $max = max($max, (int) $suffix);
            }
        }

        return $head . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Company code for references: configured prefix, else the company name. */
    protected function companyReferencePrefix(int $creatorId): string
    {
        $configured = strtoupper(trim((string) (TreasurySettings::get($creatorId, TreasurySettings::KEY_REFERENCE_PREFIX) ?? '')));

        if ($configured !== '') {
            return preg_replace('/[^A-Z0-9]/', '', $configured) ?: 'CO';
        }

        $user = User::find($creatorId);
        $letters = $user ? preg_replace('/[^A-Za-z]/', '', (string) $user->name) : '';

        return $letters !== '' ? strtoupper(substr($letters, 0, 4)) : 'CO';
    }

    /** Resolve a chart account by a configured code/prefix, else the default prefix. Refuses when nothing resolves. */
    protected function resolveAccountOrFail(int $creatorId, ?string $configured, string $defaultPrefix, string $label): int
    {
        $code = trim((string) ($configured ?? ''));

        if ($code !== '') {
            $id = $this->resolver->resolveByCodeOrPrefix($creatorId, $code);
            if ($id !== null) {
                return $id;
            }
            throw new TreasuryException('No chart account found for ' . $label . ' (' . $code . '). Check the Treasury settings.');
        }

        $id = $this->resolver->resolveByCodeOrPrefix($creatorId, $defaultPrefix);
        if ($id !== null) {
            return $id;
        }

        throw new TreasuryException('No chart account found for ' . $label . '. Set one in Treasury settings (code or prefix).');
    }

    /** Employee advances: Hoya chart 4512 "Adiantamentos aos trabalhadores". */
    protected function resolveAdvanceAssetAccount(int $creatorId, string $recipientType): int
    {
        if ($recipientType === TreasuryAdvance::RECIPIENT_SUPPLIER) {
            return $this->resolveAccountOrFail(
                $creatorId,
                TreasurySettings::get($creatorId, TreasurySettings::KEY_SUPPLIER_ADVANCE_ACCOUNT_CODE),
                '429',
                'supplier advance asset (429)'
            );
        }

        return $this->resolveAccountOrFail(
            $creatorId,
            TreasurySettings::get($creatorId, TreasurySettings::KEY_EMPLOYEE_ADVANCE_ACCOUNT_CODE),
            '4512',
            'employee advance asset (4512)'
        );
    }

    /**
     * @return array{posting_date: string, adjustment: array|null}
     */
    protected function resolvePostingDate(TreasuryAdvance $advance, array $options): array
    {
        $intended = trim((string) ($options['posting_date'] ?? '')) ?: date('Y-m-d');

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
        // executor, and must hold the advance permission.
        $executorId = (int) ($options['executor_user_id'] ?? 0);
        if ($executorId > 0 && $authorisedBy === $executorId) {
            throw new TreasuryException('The adjustment must be authorised by a different user than the executor.');
        }
        if (! $this->userHasPermission((int) $advance->created_by, $authorisedBy, 'manage treasury advances')) {
            throw new TreasuryException('The adjustment authoriser does not hold the manage treasury advances permission.');
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

    /** Same FY-CLOSE rule as the receipt/execution services (CA17). */
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

    /** Direct + role permission check (Phase 2/3 pattern). */
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

    protected function rateString(mixed $value, ?string $label = null): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            if ($label !== null) {
                throw new TreasuryException($label . ' must be a number.');
            }

            return '1.0000000000';
        }

        return number_format((float) $value, 10, '.', '');
    }

    protected function nullableDate(mixed $value, string $label): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            throw new TreasuryException($label . ' is not a valid date.');
        }
    }

    /** Round a 4-decimal amount to the ledger's 2-decimal precision (half away from zero). */
    protected function round2(string $amount4): string
    {
        return number_format(round((float) $amount4, 2), 2, '.', '');
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
