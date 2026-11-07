<?php

namespace App\Services;

use App\Exceptions\TreasuryException;
use App\Models\Bill;
use App\Models\Customer;
use App\Models\DimensionAuditEvent;
use App\Models\Employee;
use App\Models\TreasuryApprovalDecision;
use App\Models\TreasuryApprovalStep;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryFundReservation;
use App\Models\TreasuryOperation;
use App\Models\TreasuryPaymentAttempt;
use App\Models\TreasuryPaymentRequest;
use App\Models\User;
use App\Models\Vender;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 2 — payment requests + approvals + reservations
 * (plan §4, §5, §6, §10, §11).
 *
 * Rules enforced here (service layer, not only the UI):
 * - RF01 — post once: Phase 2 posts NO journal. The reservation is
 *   not a posting; execution (Phase 3) posts exactly once.
 * - RF02 — Treasury sits IN FRONT of BillPayment/PurchasePayment as
 *   the request/approval layer and links to them; it never replaces
 *   one of them, so old payments keep reconciling.
 * - RF03 — confirmed means immutable: an approved request is changed
 *   only through invalidation (CA05) — approvals restart, the
 *   snapshot is cleared and the reservation is released. History
 *   (decisions) is append-only and never deleted.
 * - RF04 — money DECIMAL(20,4), rates DECIMAL(20,10); all math is
 *   bcmath, never float, so amounts sum exactly.
 * - RF05 — posting / effective / value dates are kept apart.
 * - RF06 — every action is scoped to the company (creatorId) on the
 *   server; a cross-company id is refused, never trusted.
 * - D2 — original + base + rate + rate source + rate date on every
 *   operation. A missing required rate refuses — never guess a rate.
 * - D4/D5 — approval rules and the reservation threshold are
 *   per-company settings (TreasurySettings). Default: one approval
 *   level for every request, a second independent level at/above
 *   100,000 base; reservations off unless the company turns them on.
 * - CA05 — any change to amount, beneficiary, currency, destination
 *   details or source account invalidates approvals and restarts the
 *   workflow.
 * - CA06 — an execution timeout sets the outcome to unknown; the
 *   provider is queried before any retry.
 * - CA20 — a repeated idempotency key with the same payload returns
 *   the original result; a different payload returns a conflict.
 * - Segregation of duties: requester cannot approve their own
 *   request; executor cannot be sole approver; one person cannot
 *   fill two levels that need independent approvers.
 */
class TreasuryPaymentService
{
    public const AUDIT_ENTITY = 'treasury_payment_request';
    public const AUDIT_STEP_ENTITY = 'treasury_approval_step';
    public const AUDIT_RESERVATION_ENTITY = 'treasury_fund_reservation';
    public const AUDIT_ATTEMPT_ENTITY = 'treasury_payment_attempt';

    protected TreasuryAccountService $accounts;

    public function __construct(?TreasuryAccountService $accounts = null)
    {
        $this->accounts = $accounts ?? new TreasuryAccountService();
    }

    // ------------------------------------------------------------------
    // Requests — create / edit (drafts, and CA05 invalidation)
    // ------------------------------------------------------------------

    public function createRequest(int $creatorId, array $data, int $actorId): TreasuryPaymentRequest
    {
        $account = $this->accounts->accountForCompany($creatorId, (int) ($data['source_account_id'] ?? 0));

        if (! $account->isActive()) {
            throw new TreasuryException('Payment requests cannot use an inactive account.');
        }

        $validated = $this->validateRequestData($creatorId, $data, null);
        [$rate, $rateSource, $rateDate, $amountBase] = $this->accounts->convertToBase(
            $creatorId,
            $validated['currency'],
            $validated['amount_original'],
            $data
        );

        return DB::transaction(function () use ($creatorId, $validated, $rate, $rateSource, $rateDate, $amountBase, $actorId, $account) {
            $operation = TreasuryOperation::create([
                'reference' => null, // Allocated only when it leaves Draft.
                'type' => TreasuryOperation::TYPE_PAYMENT,
                'company_id' => $creatorId,
                'branch_id' => $validated['branch_id'],
                'financial_account_id' => $account->id,
                'counterparty_type' => $this->counterpartyType($validated['beneficiary_type']),
                'counterparty_id' => $validated['beneficiary_id'],
                'currency' => $validated['currency'],
                'amount_original' => $validated['amount_original'],
                'exchange_rate' => $rate,
                'rate_source' => $rateSource,
                'rate_date' => $rateDate,
                'amount_base' => $amountBase,
                'posting_date' => $validated['planned_date'] ?? date('Y-m-d'),
                'effective_date' => $validated['planned_date'] ?? date('Y-m-d'),
                'value_date' => $validated['due_date'] ?? $validated['planned_date'] ?? date('Y-m-d'),
                'state' => TreasuryOperation::STATE_DRAFT,
                'source_type' => $validated['source_documents'][0]['type'] ?? 'manual',
                'source_id' => $validated['source_documents'][0]['id'] ?? null,
                'created_by' => $creatorId,
            ]);

            $request = TreasuryPaymentRequest::create(array_merge($validated, [
                'operation_id' => $operation->id,
                'created_by' => $creatorId,
                'exchange_rate' => $rate,
                'rate_source' => $rateSource,
                'rate_date' => $rateDate,
                'amount_base' => $amountBase,
                'state' => TreasuryPaymentRequest::STATE_DRAFT,
                'requested_by' => $actorId,
            ]));

            $this->audit($creatorId, self::AUDIT_ENTITY, $request->id, 'created', null, $request->fresh()->toArray(), $actorId);

            return $request;
        });
    }

    /**
     * Edit a request. Drafts are freely editable. Editing a request
     * that is under review / awaiting approval / approved is allowed
     * too, but a change to a protected field (amount, beneficiary,
     * currency, destination details, source account, source
     * documents) invalidates the approvals and restarts the workflow
     * (CA05). Anything else (reason, dates, priority, cost centre)
     * edits in place.
     */
    public function updateRequest(TreasuryPaymentRequest $request, array $data, int $actorId): TreasuryPaymentRequest
    {
        if (! in_array($request->state, [
            TreasuryPaymentRequest::STATE_DRAFT,
            TreasuryPaymentRequest::STATE_UNDER_REVIEW,
            TreasuryPaymentRequest::STATE_AWAITING_APPROVAL,
            TreasuryPaymentRequest::STATE_APPROVED,
        ], true)) {
            throw new TreasuryException('This payment request can no longer be edited (state: ' . $request->state . ').');
        }

        $creatorId = (int) $request->created_by;
        $validated = $this->validateRequestData($creatorId, array_merge($request->toArray(), $data), $request->id);

        $protectedChanged = false;
        foreach (['amount_original', 'currency', 'beneficiary_type', 'beneficiary_id', 'beneficiary_account', 'source_account_id', 'source_documents'] as $field) {
            $old = $field === 'source_documents' ? $request->sourceDocuments() : (string) ($request->{$field} ?? '');
            $new = $field === 'source_documents' ? $validated['source_documents'] : (string) ($validated[$field] ?? '');
            if ($field === 'amount_original') {
                $old = number_format((float) $old, 4, '.', '');
                $new = number_format((float) $new, 4, '.', '');
            }
            if ($old !== $new) {
                $protectedChanged = true;
                break;
            }
        }

        // A changed currency or amount needs a (possibly new) rate.
        [$rate, $rateSource, $rateDate, $amountBase] = $this->accounts->convertToBase(
            $creatorId,
            $validated['currency'],
            $validated['amount_original'],
            array_merge($request->toArray(), $data)
        );

        return DB::transaction(function () use ($request, $validated, $rate, $rateSource, $rateDate, $amountBase, $actorId, $protectedChanged, $creatorId) {
            $before = $request->fresh()->toArray();

            $request->fill(array_merge($validated, [
                'exchange_rate' => $rate,
                'rate_source' => $rateSource,
                'rate_date' => $rateDate,
                'amount_base' => $amountBase,
            ]));
            $request->save();

            // Keep the linked operation in step (it is still a draft /
            // pending operation, never a confirmed movement).
            if ($request->operation) {
                $request->operation->update([
                    'financial_account_id' => $validated['source_account_id'],
                    'counterparty_type' => $this->counterpartyType($validated['beneficiary_type']),
                    'counterparty_id' => $validated['beneficiary_id'],
                    'currency' => $validated['currency'],
                    'amount_original' => $validated['amount_original'],
                    'exchange_rate' => $rate,
                    'rate_source' => $rateSource,
                    'rate_date' => $rateDate,
                    'amount_base' => $amountBase,
                    'posting_date' => $validated['planned_date'] ?? $request->operation->posting_date,
                    'effective_date' => $validated['planned_date'] ?? $request->operation->effective_date,
                    'value_date' => $validated['due_date'] ?? $validated['planned_date'] ?? $request->operation->value_date,
                    'source_type' => $validated['source_documents'][0]['type'] ?? 'manual',
                    'source_id' => $validated['source_documents'][0]['id'] ?? null,
                ]);
            }

            $this->audit($creatorId, self::AUDIT_ENTITY, $request->id, 'updated', $before, $request->fresh()->toArray(), $actorId);

            if ($protectedChanged && ! $request->isDraft()) {
                $this->invalidateApprovals($request, $actorId, 'Protected details changed — approvals restart (CA05).');
            }

            return $request->fresh();
        });
    }

    /** Drafts only — deleting a draft is not touching confirmed history. */
    public function deleteRequest(TreasuryPaymentRequest $request): void
    {
        if (! $request->isDraft()) {
            throw new TreasuryException('Only a draft payment request can be deleted.');
        }

        $creatorId = (int) $request->created_by;
        $before = $request->fresh()->toArray();
        $operationId = $request->operation_id;

        $request->steps()->delete();
        $request->delete();

        if ($operationId) {
            TreasuryOperation::where('id', $operationId)->delete();
        }

        $this->audit($creatorId, self::AUDIT_ENTITY, (int) $before['id'], 'deleted', $before, null, $request->requested_by ? (int) $request->requested_by : null);
    }

    // ------------------------------------------------------------------
    // Workflow — submit / send for approval / decide / reject / return
    // ------------------------------------------------------------------

    /**
     * Draft → under review. The operation reference is allocated here,
     * when the request leaves Draft; a draft that is cancelled never
     * consumes a number. Approval steps are generated from the
     * company's rules, and similar-payment warnings are computed.
     */
    public function submitRequest(TreasuryPaymentRequest $request, int $actorId): TreasuryPaymentRequest
    {
        return DB::transaction(function () use ($request, $actorId) {
            $request = TreasuryPaymentRequest::lockForUpdate()->findOrFail($request->id);

            if (! $request->isDraft()) {
                throw new TreasuryException('Only a draft payment request can be submitted.');
            }

            $creatorId = (int) $request->created_by;
            $account = $this->accounts->accountForCompany($creatorId, (int) $request->source_account_id);
            if (! $account->isActive()) {
                throw new TreasuryException('The source account is inactive.');
            }

            $operation = $request->operation()->lockForUpdate()->first();
            if (! $operation) {
                throw new TreasuryException('The payment request has no linked operation.');
            }

            if ($operation->reference === null || $operation->reference === '') {
                $operation->reference = $this->nextReference($creatorId, TreasuryOperation::TYPE_PAYMENT, (string) ($operation->posting_date ?? date('Y-m-d')));
            }
            $operation->state = TreasuryOperation::STATE_PENDING;
            $operation->save();

            $request->similar_flags = $this->similarFlags($request);
            $request->state = TreasuryPaymentRequest::STATE_UNDER_REVIEW;
            $request->save();

            $this->generateSteps($request, $actorId);

            $this->audit($creatorId, self::AUDIT_ENTITY, $request->id, 'submitted', null, $request->fresh()->toArray(), $actorId);

            return $request->fresh();
        });
    }

    /** Under review → awaiting approval (officer review is done). */
    public function sendForApproval(TreasuryPaymentRequest $request, int $actorId): TreasuryPaymentRequest
    {
        return DB::transaction(function () use ($request, $actorId) {
            $request = TreasuryPaymentRequest::lockForUpdate()->findOrFail($request->id);

            if (! $request->isUnderReview()) {
                throw new TreasuryException('Only a request under review can be sent for approval.');
            }

            if ($request->steps()->where('status', TreasuryApprovalStep::STATUS_PENDING)->count() === 0) {
                $this->generateSteps($request, $actorId);
            }

            $request->state = TreasuryPaymentRequest::STATE_AWAITING_APPROVAL;
            $request->save();

            $this->audit((int) $request->created_by, self::AUDIT_ENTITY, $request->id, 'sent_for_approval', null, $request->fresh()->toArray(), $actorId);

            return $request->fresh();
        });
    }

    /**
     * Decide one approval step. Levels are decided in order (lowest
     * pending level first). Enforces, in the service layer:
     * - the actor is eligible for the level (or a valid substitute);
     * - the requester cannot approve their own request;
     * - one person cannot fill two levels that need independent
     *   approvers;
     * - the executor cannot be the sole approver.
     */
    public function decide(TreasuryApprovalStep $step, int $actorId, string $decision, ?string $reason = null): TreasuryApprovalStep
    {
        if (! in_array($decision, TreasuryApprovalDecision::DECISIONS, true)) {
            throw new TreasuryException('Unknown decision (approve, reject or return).');
        }

        return DB::transaction(function () use ($step, $actorId, $decision, $reason) {
            $step = TreasuryApprovalStep::lockForUpdate()->findOrFail($step->id);
            $request = TreasuryPaymentRequest::lockForUpdate()->findOrFail($step->payment_request_id);

            if (! $request->isAwaitingApproval()) {
                throw new TreasuryException('This request is not awaiting approval (state: ' . $request->state . ').');
            }

            if (! $step->isPending()) {
                throw new TreasuryException('This approval step is already decided (' . $step->status . ').');
            }

            $lowestPending = $request->steps()->where('status', TreasuryApprovalStep::STATUS_PENDING)->orderBy('level')->first();
            if (! $lowestPending) {
                throw new TreasuryException('There is no pending approval level on this request.');
            }
            if ((int) $lowestPending->id !== (int) $step->id) {
                throw new TreasuryException('Level ' . $lowestPending->level . ' must be decided first.');
            }

            $asSubstitute = false;
            if (! in_array($actorId, $step->eligibleUserIds(), true)) {
                if ((int) $step->substitute_user_id === $actorId && $step->substituteIsValid()) {
                    $asSubstitute = true;
                } else {
                    throw new TreasuryException('You are not an eligible approver for this level.');
                }
            }

            if ($actorId === (int) $request->requested_by) {
                throw new TreasuryException('You cannot approve a payment request you submitted yourself.');
            }

            // One person cannot fill two levels that need independent
            // approvers (approve decisions only — a reject/return is a
            // single act on the whole request, not a second approval).
            // Decisions on invalidated steps are history from a
            // previous round (CA05 restart) and do not count.
            if ($decision === TreasuryApprovalDecision::DECISION_APPROVE) {
                $alreadyDecided = TreasuryApprovalDecision::where('payment_request_id', $request->id)
                    ->where('actor_id', $actorId)
                    ->where('decision', TreasuryApprovalDecision::DECISION_APPROVE)
                    ->whereHas('step', fn ($q) => $q->where('status', '!=', TreasuryApprovalStep::STATUS_INVALIDATED))
                    ->exists();
                if ($alreadyDecided) {
                    throw new TreasuryException('One person cannot approve two levels of the same request.');
                }
            }

            // The executor cannot be the sole approver: refuse the
            // decision that would complete approval when every
            // approver so far is the executor.
            if ($decision === TreasuryApprovalDecision::DECISION_APPROVE
                && (int) ($request->executor_user_id ?? 0) === $actorId
                && $this->wouldCompleteApproval($request, $step)
            ) {
                $otherApprovers = TreasuryApprovalDecision::where('payment_request_id', $request->id)
                    ->where('actor_id', '!=', $actorId)
                    ->where('decision', TreasuryApprovalDecision::DECISION_APPROVE)
                    ->whereHas('step', fn ($q) => $q->where('status', '!=', TreasuryApprovalStep::STATUS_INVALIDATED))
                    ->exists();
                if (! $otherApprovers) {
                    throw new TreasuryException('The executor cannot be the sole approver — another approver is required.');
                }
            }

            $decidedAt = now();

            TreasuryApprovalDecision::create([
                'payment_request_id' => $request->id,
                'approval_step_id' => $step->id,
                'actor_id' => $actorId,
                'decision' => $decision,
                'reason' => $reason !== null ? trim($reason) : null,
                'substitute_user_id' => $asSubstitute ? $actorId : null,
                'substitute_expiry' => $asSubstitute ? $step->substitute_expiry : null,
                'decided_at' => $decidedAt,
            ]);

            $creatorId = (int) $request->created_by;

            if ($decision === TreasuryApprovalDecision::DECISION_REJECT) {
                $step->status = TreasuryApprovalStep::STATUS_REJECTED;
                $step->decided_at = $decidedAt;
                $step->save();

                $request->steps()->where('status', TreasuryApprovalStep::STATUS_PENDING)->update(['status' => TreasuryApprovalStep::STATUS_REJECTED]);
                $request->state = TreasuryPaymentRequest::STATE_REJECTED;
                $request->rejection_reason = $reason !== null ? trim($reason) : null;
                $request->save();

                if ($request->operation) {
                    $request->operation->state = TreasuryOperation::STATE_REJECTED;
                    $request->operation->save();
                }

                $this->audit($creatorId, self::AUDIT_STEP_ENTITY, $step->id, 'rejected', null, $step->fresh()->toArray(), $actorId);

                return $step->fresh();
            }

            if ($decision === TreasuryApprovalDecision::DECISION_RETURN) {
                $step->status = TreasuryApprovalStep::STATUS_RETURNED;
                $step->decided_at = $decidedAt;
                $step->save();

                $request->steps()->where('status', TreasuryApprovalStep::STATUS_PENDING)->update(['status' => TreasuryApprovalStep::STATUS_RETURNED]);
                $request->state = TreasuryPaymentRequest::STATE_DRAFT;
                $request->return_reason = $reason !== null ? trim($reason) : null;
                $request->save();

                if ($request->operation) {
                    $request->operation->state = TreasuryOperation::STATE_DRAFT;
                    $request->operation->save();
                }

                $this->audit($creatorId, self::AUDIT_STEP_ENTITY, $step->id, 'returned', null, $step->fresh()->toArray(), $actorId);

                return $step->fresh();
            }

            // Approve: the level completes when its required votes are met.
            $votes = TreasuryApprovalDecision::where('approval_step_id', $step->id)
                ->where('decision', TreasuryApprovalDecision::DECISION_APPROVE)
                ->distinct('actor_id')
                ->count('actor_id');

            if ($votes >= (int) $step->required_votes) {
                $step->status = TreasuryApprovalStep::STATUS_APPROVED;
                $step->decided_at = $decidedAt;
                $step->save();
            }

            $this->audit($creatorId, self::AUDIT_STEP_ENTITY, $step->id, 'approved', null, $step->fresh()->toArray(), $actorId);

            if ($request->steps()->where('status', TreasuryApprovalStep::STATUS_PENDING)->count() === 0) {
                $this->completeApproval($request, $actorId);
            }

            return $step->fresh();
        });
    }

    /** Reject from under review or awaiting approval (officer action). */
    public function rejectRequest(TreasuryPaymentRequest $request, int $actorId, ?string $reason = null): TreasuryPaymentRequest
    {
        return DB::transaction(function () use ($request, $actorId, $reason) {
            $request = TreasuryPaymentRequest::lockForUpdate()->findOrFail($request->id);

            if (! in_array($request->state, [TreasuryPaymentRequest::STATE_UNDER_REVIEW, TreasuryPaymentRequest::STATE_AWAITING_APPROVAL], true)) {
                throw new TreasuryException('Only a request under review or awaiting approval can be rejected.');
            }

            $request->steps()->where('status', TreasuryApprovalStep::STATUS_PENDING)->update(['status' => TreasuryApprovalStep::STATUS_REJECTED]);
            $request->state = TreasuryPaymentRequest::STATE_REJECTED;
            $request->rejection_reason = $reason !== null ? trim($reason) : null;
            $request->save();

            if ($request->operation) {
                $request->operation->state = TreasuryOperation::STATE_REJECTED;
                $request->operation->save();
            }

            $this->audit((int) $request->created_by, self::AUDIT_ENTITY, $request->id, 'rejected', null, $request->fresh()->toArray(), $actorId);

            return $request->fresh();
        });
    }

    /** Send back to draft from under review or awaiting approval. */
    public function returnToDraft(TreasuryPaymentRequest $request, int $actorId, ?string $reason = null): TreasuryPaymentRequest
    {
        return DB::transaction(function () use ($request, $actorId, $reason) {
            $request = TreasuryPaymentRequest::lockForUpdate()->findOrFail($request->id);

            if (! in_array($request->state, [TreasuryPaymentRequest::STATE_UNDER_REVIEW, TreasuryPaymentRequest::STATE_AWAITING_APPROVAL], true)) {
                throw new TreasuryException('Only a request under review or awaiting approval can be returned to draft.');
            }

            $request->steps()->where('status', TreasuryApprovalStep::STATUS_PENDING)->update(['status' => TreasuryApprovalStep::STATUS_RETURNED]);
            $request->state = TreasuryPaymentRequest::STATE_DRAFT;
            $request->return_reason = $reason !== null ? trim($reason) : null;
            $request->save();

            if ($request->operation) {
                $request->operation->state = TreasuryOperation::STATE_DRAFT;
                $request->operation->save();
            }

            $this->audit((int) $request->created_by, self::AUDIT_ENTITY, $request->id, 'returned_to_draft', null, $request->fresh()->toArray(), $actorId);

            return $request->fresh();
        });
    }

    /** Cancel from draft / under review / awaiting approval / approved. */
    public function cancelRequest(TreasuryPaymentRequest $request, int $actorId, ?string $reason = null): TreasuryPaymentRequest
    {
        return DB::transaction(function () use ($request, $actorId, $reason) {
            $request = TreasuryPaymentRequest::lockForUpdate()->findOrFail($request->id);

            if (! in_array($request->state, [
                TreasuryPaymentRequest::STATE_DRAFT,
                TreasuryPaymentRequest::STATE_UNDER_REVIEW,
                TreasuryPaymentRequest::STATE_AWAITING_APPROVAL,
                TreasuryPaymentRequest::STATE_APPROVED,
            ], true)) {
                throw new TreasuryException('This payment request can no longer be cancelled (state: ' . $request->state . ').');
            }

            $this->releaseActiveReservation($request, $actorId, 'Request cancelled' . ($reason ? ': ' . trim($reason) : ''));

            $request->steps()->where('status', TreasuryApprovalStep::STATUS_PENDING)->update(['status' => TreasuryApprovalStep::STATUS_INVALIDATED]);
            $request->state = TreasuryPaymentRequest::STATE_CANCELLED;
            $request->save();

            if ($request->operation) {
                $request->operation->state = TreasuryOperation::STATE_CANCELLED;
                $request->operation->save();
            }

            $this->audit((int) $request->created_by, self::AUDIT_ENTITY, $request->id, 'cancelled', null, $request->fresh()->toArray(), $actorId);

            return $request->fresh();
        });
    }

    /**
     * Assign a time-limited substitute to a pending step. The
     * substitute may decide in place of an eligible approver until
     * the expiry passes.
     */
    public function assignSubstitute(TreasuryApprovalStep $step, int $substituteUserId, ?string $expiry, int $actorId): TreasuryApprovalStep
    {
        return DB::transaction(function () use ($step, $substituteUserId, $expiry, $actorId) {
            $step = TreasuryApprovalStep::lockForUpdate()->findOrFail($step->id);
            $request = TreasuryPaymentRequest::findOrFail($step->payment_request_id);

            if (! $step->isPending()) {
                throw new TreasuryException('Only a pending approval step can have a substitute.');
            }

            $creatorId = (int) $request->created_by;
            $substitute = User::where('id', $substituteUserId)
                ->where(function ($q) use ($creatorId) {
                    $q->where('id', $creatorId)->orWhere('created_by', $creatorId);
                })
                ->first();

            if (! $substitute) {
                throw new TreasuryException('Substitute user not found for this company.');
            }

            if ($substituteUserId === (int) $request->requested_by) {
                throw new TreasuryException('The requester cannot be a substitute approver.');
            }

            $expiryDate = null;
            if ($expiry !== null && trim($expiry) !== '') {
                if (strtotime($expiry) === false) {
                    throw new TreasuryException('A valid substitute expiry is required.');
                }
                $expiryDate = date('Y-m-d H:i:s', strtotime($expiry));
                if (strtotime($expiryDate) <= time()) {
                    throw new TreasuryException('The substitute expiry must be in the future.');
                }
            }

            $step->substitute_user_id = $substituteUserId;
            $step->substitute_expiry = $expiryDate;
            $step->save();

            $this->audit($creatorId, self::AUDIT_STEP_ENTITY, $step->id, 'substitute_assigned', null, $step->fresh()->toArray(), $actorId);

            return $step->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Approval rules + steps
    // ------------------------------------------------------------------

    /**
     * The approval levels that apply to an amount (base currency).
     * A level applies when its minimum amount is reached. Always at
     * least the level-1 rule applies.
     *
     * @return array<int, array{level: int, min_amount_base: string, permission: string, required_votes: int}>
     */
    public function approvalRulesFor(int $creatorId, string $amountBase): array
    {
        $rules = TreasurySettings::approvalRules($creatorId);
        $applicable = [];

        foreach ($rules as $rule) {
            if (bccomp($amountBase, (string) $rule['min_amount_base'], 4) >= 0) {
                $applicable[] = $rule;
            }
        }

        if ($applicable === []) {
            $applicable[] = $rules[0];
        }

        usort($applicable, fn ($a, $b) => $a['level'] <=> $b['level']);

        return array_values($applicable);
    }

    /** Generate the approval steps for a request from the company rules. */
    public function generateSteps(TreasuryPaymentRequest $request, int $actorId): array
    {
        $creatorId = (int) $request->created_by;
        $rules = $this->approvalRulesFor($creatorId, (string) $request->amount_base);
        $ruleVersion = 1;

        $steps = [];
        foreach ($rules as $rule) {
            $eligible = $this->resolveEligibleUsers($creatorId, (string) $rule['permission']);

            if ($eligible === []) {
                throw new TreasuryException('No approver holds the "' . $rule['permission'] . '" permission for this company.');
            }

            $steps[] = TreasuryApprovalStep::create([
                'payment_request_id' => $request->id,
                'rule_version' => $ruleVersion,
                'level' => (int) $rule['level'],
                'permission' => (string) $rule['permission'],
                'required_votes' => (int) $rule['required_votes'],
                'eligible_user_ids' => $eligible,
                'status' => TreasuryApprovalStep::STATUS_PENDING,
            ]);
        }

        return $steps;
    }

    /**
     * All steps approved: snapshot the approved data + rule version,
     * stamp the approval, reserve funds if configured (D4).
     */
    protected function completeApproval(TreasuryPaymentRequest $request, int $actorId): void
    {
        $creatorId = (int) $request->created_by;

        $request->approved_snapshot = [
            'amount_original' => (string) $request->amount_original,
            'amount_base' => (string) $request->amount_base,
            'currency' => $request->currency,
            'beneficiary_type' => $request->beneficiary_type,
            'beneficiary_id' => $request->beneficiary_id,
            'beneficiary_account' => $request->beneficiary_account,
            'source_account_id' => $request->source_account_id,
            'source_documents' => $request->sourceDocuments(),
            'rule_version' => (int) ($request->steps()->max('rule_version') ?? 1),
            'decided_at' => now()->toDateTimeString(),
        ];
        $request->approved_rule_version = (int) ($request->steps()->max('rule_version') ?? 1);
        $request->approved_at = now();
        $request->state = TreasuryPaymentRequest::STATE_APPROVED;
        $request->save();

        $this->audit($creatorId, self::AUDIT_ENTITY, $request->id, 'approved', null, $request->fresh()->toArray(), $actorId);

        $this->createReservation($request, $actorId);
    }

    /**
     * CA05: a change to protected details invalidates the approvals
     * and restarts the workflow. The decisions stay as history; the
     * steps are marked invalidated, the snapshot is cleared, any
     * active reservation is released, and fresh steps are generated.
     * The request goes back to under review — approval can never
     * execute on stale data.
     */
    public function invalidateApprovals(TreasuryPaymentRequest $request, int $actorId, string $reason): void
    {
        $creatorId = (int) $request->created_by;

        $this->releaseActiveReservation($request, $actorId, $reason);

        $request->steps()->whereIn('status', [TreasuryApprovalStep::STATUS_PENDING, TreasuryApprovalStep::STATUS_APPROVED])
            ->update(['status' => TreasuryApprovalStep::STATUS_INVALIDATED]);

        $request->approved_snapshot = null;
        $request->approved_rule_version = null;
        $request->approved_at = null;
        $request->state = TreasuryPaymentRequest::STATE_UNDER_REVIEW;
        $request->save();

        $this->generateSteps($request, $actorId);

        $this->audit($creatorId, self::AUDIT_ENTITY, $request->id, 'approvals_invalidated', null, $request->fresh()->toArray(), $actorId);
    }

    /** Would approving this step complete the whole approval? */
    protected function wouldCompleteApproval(TreasuryPaymentRequest $request, TreasuryApprovalStep $step): bool
    {
        $pending = $request->steps()->where('status', TreasuryApprovalStep::STATUS_PENDING)->get();

        foreach ($pending as $pendingStep) {
            if ((int) $pendingStep->id === (int) $step->id) {
                $votes = TreasuryApprovalDecision::where('approval_step_id', $step->id)
                    ->where('decision', TreasuryApprovalDecision::DECISION_APPROVE)
                    ->distinct('actor_id')
                    ->count('actor_id');
                // This decision adds one more vote.
                if ($votes + 1 < (int) $pendingStep->required_votes) {
                    return false;
                }
                continue;
            }

            return false;
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Attempts + idempotency (CA06, CA20)
    // ------------------------------------------------------------------

    /**
     * Begin an execution attempt. A repeated idempotency key with the
     * same payload returns the ORIGINAL attempt (no new row); a
     * different payload returns a conflict (CA20).
     */
    public function beginAttempt(TreasuryPaymentRequest $request, string $provider, string $idempotencyKey, array $payload, int $actorId): TreasuryPaymentAttempt
    {
        $key = trim($idempotencyKey);
        if ($key === '') {
            throw new TreasuryException('An idempotency key is required for every payment attempt.');
        }

        $hash = hash('sha256', json_encode($this->canonicalPayload($payload)));

        return DB::transaction(function () use ($request, $provider, $key, $hash, $actorId) {
            $request = TreasuryPaymentRequest::lockForUpdate()->findOrFail($request->id);

            $existing = TreasuryPaymentAttempt::where('idempotency_key', $key)->first();
            if ($existing) {
                if ($existing->payload_hash !== $hash) {
                    throw new TreasuryException('Idempotency key conflict: this key was already used with a different payload.');
                }
                if ((int) $existing->payment_request_id !== (int) $request->id) {
                    throw new TreasuryException('Idempotency key conflict: this key belongs to another payment request.');
                }

                return $existing;
            }

            if (! in_array($request->state, [
                TreasuryPaymentRequest::STATE_APPROVED,
                TreasuryPaymentRequest::STATE_PROCESSING,
                TreasuryPaymentRequest::STATE_OUTCOME_UNKNOWN,
            ], true)) {
                throw new TreasuryException('Payment attempts can only start for an approved request (state: ' . $request->state . ').');
            }

            $attempt = TreasuryPaymentAttempt::create([
                'payment_request_id' => $request->id,
                'attempt_number' => (int) $request->attempts()->count() + 1,
                'idempotency_key' => $key,
                'provider' => trim($provider) === '' ? null : trim($provider),
                'state' => TreasuryPaymentAttempt::STATE_PROCESSING,
                'payload_hash' => $hash,
                'created_by' => (int) $request->created_by,
            ]);

            if ($request->isApproved()) {
                $request->state = TreasuryPaymentRequest::STATE_PROCESSING;
                $request->save();
            }

            $this->audit((int) $request->created_by, self::AUDIT_ATTEMPT_ENTITY, $attempt->id, 'started', null, $attempt->fresh()->toArray(), $actorId);

            return $attempt->fresh();
        });
    }

    /** Resolve a processing attempt: paid / failed / outcome unknown. */
    public function resolveAttempt(TreasuryPaymentAttempt $attempt, string $outcome, ?string $providerReference, ?string $result, int $actorId): TreasuryPaymentAttempt
    {
        if (! in_array($outcome, [
            TreasuryPaymentAttempt::STATE_PAID,
            TreasuryPaymentAttempt::STATE_FAILED,
            TreasuryPaymentAttempt::STATE_OUTCOME_UNKNOWN,
        ], true)) {
            throw new TreasuryException('Unknown attempt outcome (paid, failed or outcome unknown).');
        }

        return DB::transaction(function () use ($attempt, $outcome, $providerReference, $result, $actorId) {
            $attempt = TreasuryPaymentAttempt::lockForUpdate()->findOrFail($attempt->id);
            $request = TreasuryPaymentRequest::lockForUpdate()->findOrFail($attempt->payment_request_id);

            if (! $attempt->isProcessing()) {
                throw new TreasuryException('Only a processing attempt can be resolved (state: ' . $attempt->state . ').');
            }

            $attempt->state = $outcome;
            $attempt->provider_reference = $providerReference !== null ? trim($providerReference) : null;
            $attempt->result = $result;
            $attempt->save();

            if ($outcome === TreasuryPaymentAttempt::STATE_PAID) {
                $request->state = TreasuryPaymentRequest::STATE_PAID;
            } elseif ($outcome === TreasuryPaymentAttempt::STATE_FAILED) {
                $request->state = TreasuryPaymentRequest::STATE_FAILED;
                $this->releaseActiveReservation($request, $actorId, 'Payment attempt failed.');
            } else {
                $request->state = TreasuryPaymentRequest::STATE_OUTCOME_UNKNOWN;
            }
            $request->save();

            $this->audit((int) $request->created_by, self::AUDIT_ATTEMPT_ENTITY, $attempt->id, 'resolved_' . $outcome, null, $attempt->fresh()->toArray(), $actorId);

            return $attempt->fresh();
        });
    }

    /**
     * Retry after a failed or outcome-unknown attempt. A retry is a
     * NEW attempt row, never a re-send of the old one. When the latest
     * attempt is outcome unknown, the provider must have been queried
     * first (CA06) — otherwise the retry is refused.
     */
    public function retryAttempt(TreasuryPaymentRequest $request, string $provider, string $idempotencyKey, array $payload, int $actorId, bool $providerQueried = false): TreasuryPaymentAttempt
    {
        $latest = $request->attempts()->orderByDesc('attempt_number')->first();

        if (! $latest) {
            throw new TreasuryException('There is no attempt to retry yet.');
        }

        if ($latest->isOutcomeUnknown() && ! $providerQueried) {
            throw new TreasuryException('Query the provider for the outcome of attempt ' . $latest->attempt_number . ' before retrying (CA06).');
        }

        if (! $latest->isOutcomeUnknown() && ! $latest->isFailed()) {
            throw new TreasuryException('Only a failed or outcome-unknown attempt can be retried (latest: ' . $latest->state . ').');
        }

        return $this->beginAttempt($request, $provider, $idempotencyKey, $payload, $actorId);
    }

    /** Canonical payload for the idempotency hash (sorted keys). */
    protected function canonicalPayload(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonicalPayload($value);
            }
        }

        return $payload;
    }

    // ------------------------------------------------------------------
    // Reservations (risk 3 — a reservation is never a posting)
    // ------------------------------------------------------------------

    /**
     * Create the fund reservation for an approved request, when the
     * company has reservations on and the amount reaches the
     * threshold (D4). Refuses when the account's negative-balance
     * policy blocks the projected balance (D3).
     */
    public function createReservation(TreasuryPaymentRequest $request, int $actorId): ?TreasuryFundReservation
    {
        $creatorId = (int) $request->created_by;

        if (TreasurySettings::get($creatorId, TreasurySettings::KEY_RESERVATION_POLICY) !== 'on') {
            return null;
        }

        if (bccomp((string) $request->amount_base, TreasurySettings::reservationThreshold($creatorId), 4) < 0) {
            return null;
        }

        if ($request->reservations()->where('state', TreasuryFundReservation::STATE_ACTIVE)->exists()) {
            return $request->activeReservation;
        }

        $account = $this->accounts->accountForCompany($creatorId, (int) $request->source_account_id);
        $available = $this->accounts->availableBalance($account);
        $projected = bcsub($available, (string) $request->amount_base, 4);

        if ($this->accounts->checkNegativeBalance($account, $projected) === 'blocked') {
            throw new TreasuryException('Insufficient available balance on account ' . $account->code . ' to reserve ' . $request->amount_base . ' ' . TreasurySettings::baseCurrency($creatorId) . '.');
        }

        $reservation = TreasuryFundReservation::create([
            'financial_account_id' => $account->id,
            'payment_request_id' => $request->id,
            'amount' => (string) $request->amount_base,
            'amount_original' => (string) $request->amount_original,
            'currency' => $request->currency,
            'expires_at' => $request->planned_date
                ? Carbon::parse($request->planned_date)->endOfDay()
                : now()->addDays(30),
            'consumed_amount' => '0.0000',
            'released_amount' => '0.0000',
            'state' => TreasuryFundReservation::STATE_ACTIVE,
            'created_by' => $creatorId,
        ]);

        $this->audit($creatorId, self::AUDIT_RESERVATION_ENTITY, $reservation->id, 'created', null, $reservation->fresh()->toArray(), $actorId);

        return $reservation;
    }

    /** Consume part of an active reservation (execution, Phase 3). */
    public function consumeReservation(TreasuryFundReservation $reservation, string $amount, int $actorId): TreasuryFundReservation
    {
        $amount = $this->decimalString($amount, 'Amount');

        return DB::transaction(function () use ($reservation, $amount, $actorId) {
            $reservation = TreasuryFundReservation::lockForUpdate()->findOrFail($reservation->id);

            if (! $reservation->isActive()) {
                throw new TreasuryException('Only an active reservation can be consumed (state: ' . $reservation->state . ').');
            }

            if (bccomp($amount, '0', 4) <= 0 || bccomp($amount, $reservation->remaining(), 4) > 0) {
                throw new TreasuryException('Cannot consume ' . $amount . ' from this reservation (remaining: ' . $reservation->remaining() . ').');
            }

            $reservation->consumed_amount = bcadd((string) $reservation->consumed_amount, $amount, 4);
            if (bccomp($reservation->remaining(), '0', 4) === 0) {
                $reservation->state = TreasuryFundReservation::STATE_CONSUMED;
            }
            $reservation->save();

            $this->audit((int) $reservation->created_by, self::AUDIT_RESERVATION_ENTITY, $reservation->id, 'consumed', null, $reservation->fresh()->toArray(), $actorId);

            return $reservation->fresh();
        });
    }

    /** Release the remainder of an active reservation. */
    public function releaseReservation(TreasuryFundReservation $reservation, ?string $reason, int $actorId): TreasuryFundReservation
    {
        return DB::transaction(function () use ($reservation, $reason, $actorId) {
            $reservation = TreasuryFundReservation::lockForUpdate()->findOrFail($reservation->id);

            if (! $reservation->isActive()) {
                return $reservation;
            }

            $reservation->released_amount = bcadd((string) $reservation->released_amount, $reservation->remaining(), 4);
            $reservation->state = TreasuryFundReservation::STATE_RELEASED;
            $reservation->reason = $reason !== null ? trim($reason) : null;
            $reservation->save();

            $this->audit((int) $reservation->created_by, self::AUDIT_RESERVATION_ENTITY, $reservation->id, 'released', null, $reservation->fresh()->toArray(), $actorId);

            return $reservation->fresh();
        });
    }

    /** Release the request's active reservation, if it has one. */
    public function releaseActiveReservation(TreasuryPaymentRequest $request, int $actorId, string $reason): void
    {
        $reservation = $request->reservations()->where('state', TreasuryFundReservation::STATE_ACTIVE)->first();

        if ($reservation) {
            $this->releaseReservation($reservation, $reason, $actorId);
        }
    }

    /** Expire active reservations past their expiry (releases the rest). */
    public function expireReservations(): int
    {
        $expired = 0;

        $due = TreasuryFundReservation::where('state', TreasuryFundReservation::STATE_ACTIVE)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($due as $reservation) {
            $reservation->released_amount = bcadd((string) $reservation->released_amount, $reservation->remaining(), 4);
            $reservation->state = TreasuryFundReservation::STATE_EXPIRED;
            $reservation->reason = 'Reservation expired.';
            $reservation->save();
            $expired++;
        }

        return $expired;
    }

    // ------------------------------------------------------------------
    // Lookups — scoped to the company on the server (RF06)
    // ------------------------------------------------------------------

    public function requestForCompany(int $creatorId, int $requestId): TreasuryPaymentRequest
    {
        $request = TreasuryPaymentRequest::where('created_by', $creatorId)->find($requestId);

        if (! $request) {
            throw new TreasuryException('Payment request not found for this company.');
        }

        return $request;
    }

    public function stepForCompany(int $creatorId, int $stepId): TreasuryApprovalStep
    {
        $step = TreasuryApprovalStep::whereHas('request', fn ($q) => $q->where('created_by', $creatorId))->find($stepId);

        if (! $step) {
            throw new TreasuryException('Approval step not found for this company.');
        }

        return $step;
    }

    /** Outstanding amount of a bill (products total − payments). */
    public function billOutstanding(int $creatorId, int $billId): ?string
    {
        if (! Schema::hasTable('bills')) {
            return null;
        }

        $bill = Bill::where('created_by', $creatorId)->find($billId);

        if (! $bill || $bill->voided_at) {
            return null;
        }

        $total = '0.0000';
        if (Schema::hasTable('bill_products')) {
            $total = (string) (DB::table('bill_products')->where('bill_id', $billId)->sum('total') ?? '0');
        }

        $paid = '0.0000';
        if (Schema::hasTable('bill_payments')) {
            $paid = (string) (DB::table('bill_payments')->where('bill_id', $billId)->sum('amount') ?? '0');
        }

        return bcsub($total, $paid, 4);
    }

    // ------------------------------------------------------------------
    // Validation + helpers
    // ------------------------------------------------------------------

    /**
     * Validate request input. Returns the normalised values. The
     * beneficiary is resolved to a stored name so lists never need a
     * join; source documents are checked to exist for the company.
     */
    protected function validateRequestData(int $creatorId, array $data, ?int $existingId): array
    {
        $accountId = (int) ($data['source_account_id'] ?? 0);
        if ($accountId <= 0) {
            throw new TreasuryException('A source account is required.');
        }

        $beneficiaryType = (string) ($data['beneficiary_type'] ?? '');
        if (! in_array($beneficiaryType, TreasuryPaymentRequest::BENEFICIARY_TYPES, true)) {
            throw new TreasuryException('Unknown beneficiary type.');
        }

        $beneficiaryId = isset($data['beneficiary_id']) && $data['beneficiary_id'] !== '' ? (int) $data['beneficiary_id'] : null;
        $beneficiaryName = trim((string) ($data['beneficiary_name'] ?? ''));

        if ($beneficiaryType === TreasuryPaymentRequest::BENEFICIARY_OTHER) {
            if ($beneficiaryName === '') {
                throw new TreasuryException('A beneficiary name is required for an "other" beneficiary.');
            }
            $beneficiaryId = null;
        } else {
            $beneficiaryName = $this->resolveBeneficiaryName($creatorId, $beneficiaryType, $beneficiaryId);
        }

        $amount = $this->decimalString($data['amount_original'] ?? $data['amount'] ?? null, 'Amount');
        if (bccomp($amount, '0', 4) <= 0) {
            throw new TreasuryException('The payment amount must be greater than zero.');
        }

        $currency = strtoupper(trim((string) ($data['currency'] ?? TreasurySettings::baseCurrency($creatorId))));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new TreasuryException('Currency must be a 3-letter code.');
        }

        $priority = (string) ($data['priority'] ?? TreasuryPaymentRequest::PRIORITY_NORMAL);
        if (! in_array($priority, TreasuryPaymentRequest::PRIORITIES, true)) {
            throw new TreasuryException('Unknown priority.');
        }

        $dueDate = $this->nullableDate($data['due_date'] ?? null, 'Due date');
        $plannedDate = $this->nullableDate($data['planned_date'] ?? null, 'Planned date');

        $branchId = null;
        if (isset($data['branch_id']) && $data['branch_id'] !== '' && $data['branch_id'] !== null) {
            $branchId = (int) $data['branch_id'];
        }

        $costCentreId = isset($data['cost_centre_id']) && $data['cost_centre_id'] !== '' ? (int) $data['cost_centre_id'] : null;
        if ($costCentreId !== null && Schema::hasTable('cost_centres')) {
            $ok = DB::table('cost_centres')->where('id', $costCentreId)->where('created_by', $creatorId)->exists();
            if (! $ok) {
                throw new TreasuryException('Cost centre not found for this company.');
            }
        }

        $projectId = isset($data['project_id']) && $data['project_id'] !== '' ? (int) $data['project_id'] : null;

        $executorId = isset($data['executor_user_id']) && $data['executor_user_id'] !== '' ? (int) $data['executor_user_id'] : null;
        if ($executorId !== null) {
            $ok = User::where('id', $executorId)
                ->where(function ($q) use ($creatorId) {
                    $q->where('id', $creatorId)->orWhere('created_by', $creatorId);
                })
                ->exists();
            if (! $ok) {
                throw new TreasuryException('Executor user not found for this company.');
            }
        }

        $clientReference = trim((string) ($data['client_reference'] ?? ''));
        if ($clientReference !== '') {
            $duplicate = TreasuryPaymentRequest::where('created_by', $creatorId)
                ->where('client_reference', $clientReference)
                ->whereIn('state', TreasuryPaymentRequest::OPEN_STATES)
                ->when($existingId !== null, fn ($q) => $q->where('id', '!=', $existingId))
                ->exists();
            if ($duplicate) {
                throw new TreasuryException('A payment request with client reference "' . $clientReference . '" already exists for this company.');
            }
        }

        $sourceDocuments = $this->validateSourceDocuments($creatorId, $data['source_documents'] ?? []);

        return [
            'source_account_id' => $accountId,
            'branch_id' => $branchId,
            'beneficiary_type' => $beneficiaryType,
            'beneficiary_id' => $beneficiaryId,
            'beneficiary_name' => $beneficiaryName,
            'beneficiary_account' => $this->nullableString($data['beneficiary_account'] ?? null, 150),
            'amount_original' => $amount,
            'currency' => $currency,
            'due_date' => $dueDate,
            'planned_date' => $plannedDate,
            'priority' => $priority,
            'reason' => $this->nullableString($data['reason'] ?? null, 1000),
            'cost_centre_id' => $costCentreId,
            'project_id' => $projectId,
            'source_documents' => $sourceDocuments,
            'client_reference' => $clientReference === '' ? null : $clientReference,
            'executor_user_id' => $executorId,
        ];
    }

    /** Source documents must exist for the company and not be voided. */
    protected function validateSourceDocuments(int $creatorId, mixed $documents): array
    {
        if ($documents === null || $documents === '' || $documents === []) {
            return [];
        }

        if (is_string($documents)) {
            $decoded = json_decode($documents, true);
            $documents = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($documents)) {
            throw new TreasuryException('Source documents are not valid.');
        }

        $validated = [];
        foreach (array_values($documents) as $doc) {
            if (! is_array($doc) || ! isset($doc['type'], $doc['id'])) {
                throw new TreasuryException('Each source document needs a type and an id.');
            }

            $type = (string) $doc['type'];
            $id = (int) $doc['id'];

            if ($type === 'bill') {
                $bill = Schema::hasTable('bills')
                    ? Bill::where('created_by', $creatorId)->find($id)
                    : null;
                if (! $bill) {
                    throw new TreasuryException('Bill #' . $id . ' was not found for this company.');
                }
                if ($bill->voided_at) {
                    throw new TreasuryException('Bill #' . $id . ' is voided.');
                }
            } elseif ($type === 'invoice') {
                $invoice = Schema::hasTable('invoices')
                    ? \App\Models\Invoice::where('created_by', $creatorId)->find($id)
                    : null;
                if (! $invoice) {
                    throw new TreasuryException('Invoice #' . $id . ' was not found for this company.');
                }
            } else {
                throw new TreasuryException('Unknown source document type "' . $type . '".');
            }

            $validated[] = ['type' => $type, 'id' => $id];
        }

        return $validated;
    }

    protected function resolveBeneficiaryName(int $creatorId, string $type, ?int $id): string
    {
        if ($id === null || $id <= 0) {
            throw new TreasuryException('A beneficiary is required.');
        }

        $name = null;
        if ($type === TreasuryPaymentRequest::BENEFICIARY_VENDOR && Schema::hasTable('venders')) {
            $name = Vender::where('created_by', $creatorId)->where('id', $id)->value('name');
        } elseif ($type === TreasuryPaymentRequest::BENEFICIARY_EMPLOYEE && Schema::hasTable('employees')) {
            $name = Employee::where('created_by', $creatorId)->where('id', $id)->value('name');
        } elseif ($type === TreasuryPaymentRequest::BENEFICIARY_CUSTOMER && Schema::hasTable('customers')) {
            $name = Customer::where('created_by', $creatorId)->where('id', $id)->value('name');
        }

        if ($name === null || trim((string) $name) === '') {
            throw new TreasuryException('Beneficiary not found for this company.');
        }

        return trim((string) $name);
    }

    /**
     * Similar-payment warnings for review (not blocking): the same
     * beneficiary for a similar amount, or a source bill already
     * linked to another open request.
     *
     * @return string[]
     */
    protected function similarFlags(TreasuryPaymentRequest $request): array
    {
        $flags = [];
        $creatorId = (int) $request->created_by;

        $others = TreasuryPaymentRequest::where('created_by', $creatorId)
            ->where('id', '!=', $request->id)
            ->whereIn('state', TreasuryPaymentRequest::OPEN_STATES)
            ->get();

        foreach ($others as $other) {
            if ($other->beneficiary_type === $request->beneficiary_type
                && (int) $other->beneficiary_id === (int) $request->beneficiary_id
            ) {
                $diff = bcsub((string) $other->amount_base, (string) $request->amount_base, 4);
                $tolerance = bcmul((string) $request->amount_base, '0.01', 4);
                if (bccomp($diff[0] === '-' ? substr($diff, 1) : $diff, $tolerance, 4) <= 0) {
                    $flags[] = 'Similar payment to the same beneficiary for a similar amount (request #' . $other->id . ').';
                }
            }

            foreach ($request->sourceDocuments() as $doc) {
                foreach ($other->sourceDocuments() as $otherDoc) {
                    if ($doc['type'] === $otherDoc['type'] && (int) $doc['id'] === (int) $otherDoc['id']) {
                        $flags[] = ucfirst($doc['type']) . ' #' . $doc['id'] . ' is already linked to payment request #' . $other->id . '.';
                    }
                }
            }
        }

        // A source bill that looks fully paid is worth a review note.
        foreach ($request->sourceDocuments() as $doc) {
            if ($doc['type'] === 'bill') {
                $outstanding = $this->billOutstanding($creatorId, (int) $doc['id']);
                if ($outstanding !== null && bccomp($outstanding, '0', 4) <= 0) {
                    $flags[] = 'Bill #' . $doc['id'] . ' looks fully paid already.';
                }
            }
        }

        return array_values(array_unique($flags));
    }

    /** Users of the company who hold the permission (Spatie when present). */
    protected function resolveEligibleUsers(int $creatorId, string $permission): array
    {
        $companyUserIds = User::where(function ($q) use ($creatorId) {
            $q->where('id', $creatorId)->orWhere('created_by', $creatorId);
        })->pluck('id')->map(fn ($id) => (int) $id)->all();

        if (! Schema::hasTable('model_has_permissions') || ! Schema::hasTable('permissions')) {
            // No Spatie tables (unit tests): every company user is eligible.
            return $companyUserIds;
        }

        $permissionId = DB::table('permissions')->where('name', $permission)->where('guard_name', 'web')->value('id');

        if (! $permissionId) {
            return [];
        }

        $ids = DB::table('model_has_permissions')
            ->where('permission_id', $permissionId)
            ->where('model_type', User::class)
            ->pluck('model_id')->map(fn ($id) => (int) $id)->all();

        if (Schema::hasTable('role_has_permissions') && Schema::hasTable('model_has_roles')) {
            $roleIds = DB::table('role_has_permissions')->where('permission_id', $permissionId)->pluck('role_id')->all();
            if ($roleIds !== []) {
                $roleUserIds = DB::table('model_has_roles')
                    ->whereIn('role_id', $roleIds)
                    ->where('model_type', User::class)
                    ->pluck('model_id')->map(fn ($id) => (int) $id)->all();
                $ids = array_merge($ids, $roleUserIds);
            }
        }

        return array_values(array_unique(array_intersect($ids, $companyUserIds)));
    }

    /** Next reference for the company + type + year, serialised. */
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

    protected function counterpartyType(string $beneficiaryType): string
    {
        return match ($beneficiaryType) {
            TreasuryPaymentRequest::BENEFICIARY_VENDOR => 'vendor',
            TreasuryPaymentRequest::BENEFICIARY_EMPLOYEE => 'employee',
            TreasuryPaymentRequest::BENEFICIARY_CUSTOMER => 'customer',
            default => 'entity',
        };
    }

    protected function decimalString(mixed $value, string $label): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw new TreasuryException($label . ' must be a number.');
        }

        return number_format((float) $value, 4, '.', '');
    }

    protected function nullableDate(mixed $value, string $label): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (strtotime((string) $value) === false) {
            throw new TreasuryException('A valid ' . strtolower($label) . ' is required.');
        }

        return date('Y-m-d', strtotime((string) $value));
    }

    protected function nullableString(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * Append-only audit row (DimensionAuditEvent is the shared audit
     * table). Guarded by a live hasTable check on every call — never
     * memoized — so a missing table degrades to no audit row instead
     * of a crash.
     */
    protected function audit(int $companyId, string $entityType, int $entityId, string $action, ?array $before, ?array $after, ?int $actorId): void
    {
        if (! Schema::hasTable('dimension_audit_events')) {
            return;
        }

        DimensionAuditEvent::record($companyId, $entityType, $entityId, $action, $before, $after, $actorId);
    }
}
