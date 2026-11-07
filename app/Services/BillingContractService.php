<?php

namespace App\Services;

use App\Exceptions\BillingContractException;
use App\Models\BillingContract;
use App\Models\BillingContractAuditEvent;
use App\Models\BillingContractLine;
use App\Models\BillingContractOccurrence;
use App\Models\BillingContractRevision;
use App\Models\Customer;
use App\Models\Tax;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Contract Management Phase 1 — billing contract lifecycle
 * (plan §4, §6).
 *
 * State machine, enforced here and never only in the UI:
 * draft -> pending_approval -> active -> suspended -> ended /
 * cancelled. Draft is editable; pending approval has no billing;
 * active bills on schedule (Phase 2 engine); suspended bills
 * nothing until resumed; ended/cancelled need an effective date
 * and cancel their unissued periods.
 *
 * No self-approval: the user who creates a contract cannot
 * approve it. Approval mode requires an approve step before
 * activation; automatic mode activates on submit.
 *
 * Proration is OFF by default (D3): a contract starting
 * mid-period bills the FULL period amount unless the partial
 * rule is 'prorate' (first/last period by days) or 'skip'.
 *
 * Every transition writes an append-only audit event. All money
 * math is bcmath, never float.
 */
class BillingContractService
{
    protected BillingOccurrenceService $occurrences;

    public function __construct(BillingOccurrenceService $occurrences)
    {
        $this->occurrences = $occurrences;
    }

    // ------------------------------------------------------------------
    // Create / update (draft only)
    // ------------------------------------------------------------------

    public function createContract(int $creatorId, array $data, int $userId): BillingContract
    {
        $data = $this->validateData($creatorId, $data);
        $lines = $data['lines'];
        unset($data['lines']);

        return DB::transaction(function () use ($creatorId, $data, $lines, $userId) {
            $data['created_by'] = $creatorId;
            $data['reference'] = $this->nextReference($creatorId, (string) Carbon::parse($data['start_date'])->format('Y'));
            $data['state'] = BillingContract::STATE_DRAFT;

            $contract = BillingContract::create($data);
            $this->saveLines($contract, $lines);

            $this->logEvent($contract, 'created', $userId, null, null, ['state' => 'draft', 'reference' => $contract->reference]);

            return $contract->fresh();
        });
    }

    public function updateContract(BillingContract $contract, array $data, int $userId): BillingContract
    {
        $this->assertCompany($contract, (int) $contract->created_by);

        if ($contract->state !== BillingContract::STATE_DRAFT) {
            throw new BillingContractException('Only a draft contract can be edited. Use an amendment for an active contract.');
        }

        $data = $this->validateData((int) $contract->created_by, $data, $contract);
        $lines = $data['lines'];
        unset($data['lines']);

        return DB::transaction(function () use ($contract, $data, $lines, $userId) {
            $before = ['state' => $contract->state, 'title' => $contract->title];
            $contract->update($data);
            $contract->lines()->delete();
            $this->saveLines($contract, $lines);

            $this->logEvent($contract, 'updated', $userId, null, $before, ['state' => $contract->state, 'title' => $contract->title]);

            return $contract->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    public function submitForApproval(BillingContract $contract, int $userId): BillingContract
    {
        $this->assertCompany($contract, (int) $contract->created_by);

        if ($contract->state !== BillingContract::STATE_DRAFT) {
            throw new BillingContractException('Only a draft contract can be submitted.');
        }

        $this->assertReadyToActivate($contract);

        return DB::transaction(function () use ($contract, $userId) {
            if ($contract->approval_mode === BillingContract::APPROVAL_MODE_AUTOMATIC) {
                $contract->update([
                    'state' => BillingContract::STATE_ACTIVE,
                    'approved_by' => $userId,
                    'approved_at' => now(),
                ]);
                $this->logEvent($contract, 'activated', $userId, 'Automatic mode: activated on submit.', ['state' => 'draft'], ['state' => 'active']);
                $this->occurrences->generateForContract($contract->fresh(), $userId);
            } else {
                $contract->update(['state' => BillingContract::STATE_PENDING_APPROVAL]);
                $this->logEvent($contract, 'submitted', $userId, null, ['state' => 'draft'], ['state' => 'pending_approval']);
            }

            return $contract->fresh();
        });
    }

    public function approve(BillingContract $contract, int $userId): BillingContract
    {
        $this->assertCompany($contract, (int) $contract->created_by);

        if ($contract->state !== BillingContract::STATE_PENDING_APPROVAL) {
            throw new BillingContractException('Only a contract pending approval can be approved.');
        }

        if ((int) $contract->created_by === $userId) {
            throw new BillingContractException('You cannot approve a contract you created yourself.');
        }

        $this->assertReadyToActivate($contract);

        return DB::transaction(function () use ($contract, $userId) {
            $contract->update([
                'state' => BillingContract::STATE_ACTIVE,
                'approved_by' => $userId,
                'approved_at' => now(),
                'rejected_reason' => null,
            ]);
            $this->logEvent($contract, 'approved', $userId, null, ['state' => 'pending_approval'], ['state' => 'active']);
            $this->occurrences->generateForContract($contract->fresh(), $userId);

            return $contract->fresh();
        });
    }

    public function reject(BillingContract $contract, int $userId, string $reason): BillingContract
    {
        $this->assertCompany($contract, (int) $contract->created_by);

        if ($contract->state !== BillingContract::STATE_PENDING_APPROVAL) {
            throw new BillingContractException('Only a contract pending approval can be rejected.');
        }

        if (trim($reason) === '') {
            throw new BillingContractException('A rejection needs a reason.');
        }

        $contract->update([
            'state' => BillingContract::STATE_DRAFT,
            'rejected_reason' => $reason,
        ]);
        $this->logEvent($contract, 'rejected', $userId, $reason, ['state' => 'pending_approval'], ['state' => 'draft']);

        return $contract->fresh();
    }

    /**
     * One "Activate" button for the UI: draft -> submit, pending
     * approval -> approve. Same rules as the two steps it calls.
     */
    public function activate(BillingContract $contract, int $userId): BillingContract
    {
        if ($contract->state === BillingContract::STATE_DRAFT) {
            return $this->submitForApproval($contract, $userId);
        }

        if ($contract->state === BillingContract::STATE_PENDING_APPROVAL) {
            return $this->approve($contract, $userId);
        }

        throw new BillingContractException('Only a draft or pending-approval contract can be activated.');
    }

    public function suspend(BillingContract $contract, int $userId, string $reason): BillingContract
    {
        $this->assertCompany($contract, (int) $contract->created_by);

        if ($contract->state !== BillingContract::STATE_ACTIVE) {
            throw new BillingContractException('Only an active contract can be suspended.');
        }

        if (trim($reason) === '') {
            throw new BillingContractException('A suspension needs a reason.');
        }

        $contract->update([
            'state' => BillingContract::STATE_SUSPENDED,
            'suspended_reason' => $reason,
            'suspended_at' => now(),
        ]);
        $this->logEvent($contract, 'suspended', $userId, $reason, ['state' => 'active'], ['state' => 'suspended']);

        return $contract->fresh();
    }

    /**
     * Resume a suspended contract. $missedDecision is 'bill'
     * (missed periods stay pending — the Phase 2 engine picks them
     * up as catch-up) or 'skip' (missed periods are skipped, the
     * customer is never billed for the suspended time).
     */
    public function resume(BillingContract $contract, int $userId, string $missedDecision): BillingContract
    {
        $this->assertCompany($contract, (int) $contract->created_by);

        if ($contract->state !== BillingContract::STATE_SUSPENDED) {
            throw new BillingContractException('Only a suspended contract can be resumed.');
        }

        if (! in_array($missedDecision, ['bill', 'skip'], true)) {
            throw new BillingContractException('Resume needs a missed-period decision: bill or skip.');
        }

        return DB::transaction(function () use ($contract, $userId, $missedDecision) {
            $suspendedAt = $contract->suspended_at ? Carbon::parse($contract->suspended_at) : Carbon::today();

            if ($missedDecision === 'skip') {
                $this->occurrences->markMissedSkipped(
                    $contract,
                    $suspendedAt->copy()->startOfDay(),
                    Carbon::today(),
                    $userId,
                    'Suspended period skipped on resume.'
                );
            }

            $contract->update([
                'state' => BillingContract::STATE_ACTIVE,
                'suspended_reason' => null,
                'suspended_at' => null,
            ]);
            $this->logEvent($contract, 'resumed', $userId, 'Missed periods: ' . $missedDecision . '.', ['state' => 'suspended'], ['state' => 'active']);

            return $contract->fresh();
        });
    }

    /**
     * End a contract on an effective date. Pending occurrences
     * starting on or after that date are cancelled; the straddling
     * period is re-priced to the days actually covered. Returns
     * the affected pending occurrences (for the preview).
     */
    public function end(BillingContract $contract, int $userId, string $effectiveDate, ?string $reason = null): BillingContract
    {
        $this->assertCompany($contract, (int) $contract->created_by);

        if (! in_array($contract->state, [BillingContract::STATE_ACTIVE, BillingContract::STATE_SUSPENDED], true)) {
            throw new BillingContractException('Only an active or suspended contract can be ended.');
        }

        $effective = Carbon::parse($effectiveDate)->startOfDay();
        if ($effective->lessThan(Carbon::parse($contract->start_date)->startOfDay())) {
            throw new BillingContractException('The end date cannot be before the contract start date.');
        }

        return DB::transaction(function () use ($contract, $userId, $effective, $reason) {
            $contract->update([
                'state' => BillingContract::STATE_ENDED,
                'end_date' => $effective->format('Y-m-d'),
                'ended_at' => now(),
            ]);

            $cancelled = $this->occurrences->cancelPendingFrom(
                $contract->fresh(),
                $effective,
                $userId,
                $reason ?? 'Contract ended.'
            );

            $this->logEvent($contract, 'ended', $userId, $reason, ['state' => 'active'], [
                'state' => 'ended',
                'end_date' => $effective->format('Y-m-d'),
                'occurrences_cancelled' => $cancelled,
            ]);

            return $contract->fresh();
        });
    }

    public function cancel(BillingContract $contract, int $userId, string $reason, ?string $effectiveDate = null): BillingContract
    {
        $this->assertCompany($contract, (int) $contract->created_by);

        if (! in_array($contract->state, [
            BillingContract::STATE_DRAFT,
            BillingContract::STATE_PENDING_APPROVAL,
            BillingContract::STATE_ACTIVE,
            BillingContract::STATE_SUSPENDED,
        ], true)) {
            throw new BillingContractException('This contract cannot be cancelled from its current state.');
        }

        if (trim($reason) === '') {
            throw new BillingContractException('A cancellation needs a reason.');
        }

        $effective = $effectiveDate
            ? Carbon::parse($effectiveDate)->startOfDay()
            : Carbon::today();

        return DB::transaction(function () use ($contract, $userId, $reason, $effective) {
            $wasState = $contract->state;
            $contract->update([
                'state' => BillingContract::STATE_CANCELLED,
                'cancelled_at' => now(),
            ]);

            if (in_array($wasState, [BillingContract::STATE_ACTIVE, BillingContract::STATE_SUSPENDED], true)) {
                $this->occurrences->cancelPendingFrom($contract->fresh(), $effective, $userId, $reason);
            }

            $this->logEvent($contract, 'cancelled', $userId, $reason, ['state' => $wasState], ['state' => 'cancelled']);

            return $contract->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Amendment (active / suspended contracts only)
    // ------------------------------------------------------------------

    /**
     * Amend an active or suspended contract: snapshot the current
     * lines + schedule + terms into a new revision, apply the
     * changes from the effective date, and regenerate only the
     * future pending occurrences. Issued / generated occurrences
     * are never rewritten.
     */
    public function amend(BillingContract $contract, array $data, int $userId, string $effectiveDate, string $reason): BillingContract
    {
        $this->assertCompany($contract, (int) $contract->created_by);

        if (! in_array($contract->state, [BillingContract::STATE_ACTIVE, BillingContract::STATE_SUSPENDED], true)) {
            throw new BillingContractException('Only an active or suspended contract can be amended.');
        }

        if (trim($reason) === '') {
            throw new BillingContractException('An amendment needs a reason.');
        }

        $effective = Carbon::parse($effectiveDate)->startOfDay();
        if ($effective->lessThan(Carbon::today()->startOfDay())) {
            throw new BillingContractException('The amendment effective date cannot be in the past.');
        }

        $data = $this->validateData((int) $contract->created_by, $data, $contract, true);
        $lines = $data['lines'];
        unset($data['lines']);

        return DB::transaction(function () use ($contract, $data, $lines, $userId, $effective, $reason) {
            $revisionNumber = (int) (BillingContractRevision::where('contract_id', $contract->id)->max('revision_number') ?? 0) + 1;

            $revision = BillingContractRevision::create([
                'contract_id' => $contract->id,
                'revision_number' => $revisionNumber,
                'effective_date' => $effective->format('Y-m-d'),
                'reason' => $reason,
                'snapshot' => $this->snapshot($contract),
                'created_by' => $userId,
            ]);

            $contract->update(array_merge($data, ['current_revision_id' => $revision->id]));
            $contract->lines()->delete();
            $this->saveLines($contract, $lines);

            $regenerated = $this->occurrences->regenerateFuturePending($contract->fresh(), $effective, $userId);

            $this->logEvent($contract, 'amended', $userId, $reason, ['revision' => $revisionNumber - 1], [
                'revision' => $revisionNumber,
                'effective_date' => $effective->format('Y-m-d'),
                'occurrences_regenerated' => $regenerated['generated'],
            ]);

            return $contract->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Preview (no persistence)
    // ------------------------------------------------------------------

    /**
     * Preview of the first 3 occurrences for contract data that has
     * not been saved yet: coverage, billing dates, estimated
     * totals, and whether each period is partial (and how the
     * partial rule treats it).
     *
     * @return array<int, array<string, mixed>>
     */
    public function preview(array $data): array
    {
        $data = $this->validateData((int) ($data['created_by'] ?? 0), $data);
        $lines = $data['lines'];
        unset($data['lines']);

        $contract = new BillingContract($data);
        $contract->setRelation('lines', collect(array_map(
            fn ($line) => new BillingContractLine(array_merge($line, [
                // Transient lines have no stored amount yet; compute
                // it the same way saveLines() does.
                'amount' => $this->lineAmount($line),
            ])),
            $lines
        )));

        $from = Carbon::parse($contract->start_date)->startOfDay();
        $to = $from->copy()->addYears(5);
        $periods = $this->occurrences->periods($contract, $from, $to);

        $preview = [];
        foreach (array_slice($periods, 0, 3) as [$periodStart, $periodEnd]) {
            $occ = $this->occurrences->occurrenceForPeriod($contract, $periodStart, $periodEnd);
            if ($occ === null) {
                $preview[] = [
                    'period_start' => $periodStart->format('Y-m-d'),
                    'period_end' => $periodEnd->copy()->subDay()->format('Y-m-d'),
                    'billing_date' => null,
                    'due_date' => null,
                    'amount' => '0.0000',
                    'partial' => true,
                    'skipped' => true,
                ];
                continue;
            }

            $preview[] = [
                'period_start' => $occ['period_start']->format('Y-m-d'),
                'period_end' => $occ['period_end']->copy()->subDay()->format('Y-m-d'),
                'billing_date' => $occ['billing_date']->format('Y-m-d'),
                'due_date' => $occ['due_date']->format('Y-m-d'),
                'amount' => $occ['amount'],
                'partial' => $occ['partial'],
                'skipped' => false,
            ];
        }

        return $preview;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Next contract number for a company + year: CNT-2027-0001. */
    public function nextReference(int $creatorId, string $year): string
    {
        $seq = BillingContract::where('created_by', $creatorId)
            ->where('reference', 'like', "CNT-{$year}-%")
            ->lockForUpdate()
            ->count() + 1;

        return sprintf('CNT-%s-%04d', $year, $seq);
    }

    /** Pending occurrences that an end/cancel on $effectiveDate affects. */
    public function affectedOccurrences(BillingContract $contract, string $effectiveDate): array
    {
        $effective = Carbon::parse($effectiveDate)->startOfDay();

        return BillingContractOccurrence::where('contract_id', $contract->id)
            ->where('created_by', (int) $contract->created_by)
            ->where('state', BillingContractOccurrence::STATE_PENDING)
            ->whereDate('period_start', '>=', $effective->format('Y-m-d'))
            ->orderBy('period_start')
            ->get()
            ->all();
    }

    protected function assertReadyToActivate(BillingContract $contract): void
    {
        if ($contract->lines()->count() === 0) {
            throw new BillingContractException('A contract needs at least one billing line before activation.');
        }

        if (! $contract->customer_id) {
            throw new BillingContractException('A contract needs a customer before activation.');
        }
    }

    protected function assertCompany(BillingContract $contract, int $creatorId): void
    {
        if ((int) $contract->created_by !== $creatorId) {
            throw new BillingContractException('Contract not found for this company.');
        }
    }

    protected function saveLines(BillingContract $contract, array $lines): void
    {
        foreach ($lines as $line) {
            BillingContractLine::create([
                'contract_id' => $contract->id,
                'product_id' => $line['product_id'] ?? null,
                'description' => $line['description'],
                'quantity' => $this->dec($line['quantity'] ?? '1'),
                'unit_price' => $this->dec($line['unit_price'] ?? '0'),
                'discount_amount' => $this->dec($line['discount_amount'] ?? '0'),
                'tax_id' => $line['tax_id'] ?? null,
                'chart_account_id' => $line['chart_account_id'] ?? null,
                'cost_centre_id' => $line['cost_centre_id'] ?? null,
                'amount' => $this->lineAmount($line),
                'created_by' => (int) $contract->created_by,
            ]);
        }
    }

    protected function lineAmount(array $line): string
    {
        $gross = bcmul($this->dec($line['quantity'] ?? '1'), $this->dec($line['unit_price'] ?? '0'), 4);

        return bcsub($gross, $this->dec($line['discount_amount'] ?? '0'), 4);
    }

    protected function snapshot(BillingContract $contract): array
    {
        return [
            'title' => $contract->title,
            'customer_id' => $contract->customer_id,
            'start_date' => $contract->start_date ? Carbon::parse($contract->start_date)->format('Y-m-d') : null,
            'end_date' => $contract->end_date ? Carbon::parse($contract->end_date)->format('Y-m-d') : null,
            'currency' => $contract->currency,
            'exchange_rate' => (string) $contract->exchange_rate,
            'billing_frequency' => $contract->billing_frequency,
            'custom_interval' => $contract->custom_interval,
            'custom_unit' => $contract->custom_unit,
            'anchor_day' => $contract->anchor_day,
            'billing_mode' => $contract->billing_mode,
            'payment_terms_days' => $contract->payment_terms_days,
            'approval_mode' => $contract->approval_mode,
            'proration_enabled' => (bool) $contract->proration_enabled,
            'partial_period_rule' => $contract->partial_period_rule,
            'lines' => $contract->lines()->get()->map(fn (BillingContractLine $line) => [
                'product_id' => $line->product_id,
                'description' => $line->description,
                'quantity' => bcadd((string) $line->quantity, '0', 4),
                'unit_price' => bcadd((string) $line->unit_price, '0', 4),
                'discount_amount' => bcadd((string) $line->discount_amount, '0', 4),
                'tax_id' => $line->tax_id,
                'amount' => bcadd((string) $line->amount, '0', 4),
            ])->all(),
        ];
    }

    protected function logEvent(BillingContract $contract, string $action, ?int $actorId, ?string $reason, ?array $before, ?array $after): void
    {
        BillingContractAuditEvent::create([
            'contract_id' => $contract->id,
            'actor_id' => $actorId,
            'action' => $action,
            'reason' => $reason,
            'before_values' => $before,
            'after_values' => $after,
            'created_by' => (int) $contract->created_by,
        ]);
    }

    protected function dec(mixed $value): string
    {
        if (! is_numeric($value)) {
            throw new BillingContractException('An amount must be a number.');
        }

        return bcadd((string) $value, '0', 4);
    }

    protected function nullIfEmpty(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' || $value === null ? null : $value;
    }

    /**
     * Normalise + validate contract input. Returns the data ready
     * to persist (lines separated). Throws BillingContractException
     * with a plain message on the first problem.
     */
    protected function validateData(int $creatorId, array $data, ?BillingContract $existing = null, bool $isAmend = false): array
    {
        $customerId = (int) ($data['customer_id'] ?? $existing?->customer_id ?? 0);
        if ($customerId <= 0 || ! Customer::where('id', $customerId)->where('created_by', $creatorId)->exists()) {
            throw new BillingContractException('Choose a customer that belongs to this company.');
        }

        $title = trim((string) ($data['title'] ?? $existing?->title ?? ''));
        if ($title === '') {
            throw new BillingContractException('A contract needs a title.');
        }

        $start = $data['start_date'] ?? ($existing?->start_date ? Carbon::parse($existing->start_date)->format('Y-m-d') : null);
        if (! $start || ! $this->isDate($start)) {
            throw new BillingContractException('A contract needs a valid start date.');
        }

        $end = $data['end_date'] ?? ($existing?->end_date ? Carbon::parse($existing->end_date)->format('Y-m-d') : null);
        if ($end !== null && $end !== '' && ! $this->isDate($end)) {
            throw new BillingContractException('The end date is not a valid date.');
        }
        if ($end !== null && $end !== '' && Carbon::parse($end)->lessThanOrEqualTo(Carbon::parse($start))) {
            throw new BillingContractException('The end date must be after the start date.');
        }

        $frequency = $data['billing_frequency'] ?? $existing?->billing_frequency ?? BillingContract::FREQUENCY_MONTHLY;
        if (! in_array($frequency, BillingContract::FREQUENCIES, true)) {
            throw new BillingContractException('Unknown billing frequency: ' . $frequency);
        }

        $customInterval = $data['custom_interval'] ?? $existing?->custom_interval ?? null;
        $customUnit = $data['custom_unit'] ?? $existing?->custom_unit ?? null;
        if ($frequency === BillingContract::FREQUENCY_CUSTOM) {
            if ((int) ($customInterval ?? 0) < 1) {
                throw new BillingContractException('A custom frequency needs an interval of at least 1.');
            }
            if (! in_array($customUnit, BillingContract::CUSTOM_UNITS, true)) {
                throw new BillingContractException('A custom frequency needs a unit: days, weeks, months or years.');
            }
        }

        $anchorDay = (int) ($data['anchor_day'] ?? $existing?->anchor_day ?? Carbon::parse($start)->day);
        if ($anchorDay < 1 || $anchorDay > 31) {
            throw new BillingContractException('The anchor day must be between 1 and 31.');
        }

        $billingMode = $data['billing_mode'] ?? $existing?->billing_mode ?? BillingContract::BILLING_MODE_ADVANCE;
        if (! in_array($billingMode, BillingContract::BILLING_MODES, true)) {
            throw new BillingContractException('Unknown billing mode: ' . $billingMode);
        }

        $approvalMode = $data['approval_mode'] ?? $existing?->approval_mode ?? BillingContract::APPROVAL_MODE_APPROVAL;
        if (! in_array($approvalMode, BillingContract::APPROVAL_MODES, true)) {
            throw new BillingContractException('Unknown approval mode: ' . $approvalMode);
        }

        $partialRule = $data['partial_period_rule'] ?? $existing?->partial_period_rule ?? BillingContract::PARTIAL_FULL;
        if (! in_array($partialRule, BillingContract::PARTIAL_RULES, true)) {
            throw new BillingContractException('Unknown partial-period rule: ' . $partialRule);
        }

        // 'prorate' and proration_enabled are the same choice, kept
        // in sync both ways.
        $proration = ! empty($data['proration_enabled']) || ($existing?->proration_enabled ?? false);
        if (array_key_exists('proration_enabled', $data)) {
            $proration = (bool) $data['proration_enabled'];
        }
        if ($partialRule === BillingContract::PARTIAL_PRORATE) {
            $proration = true;
        }
        if ($proration && $partialRule !== BillingContract::PARTIAL_PRORATE) {
            $partialRule = BillingContract::PARTIAL_PRORATE;
        }

        $currency = strtoupper(trim((string) ($data['currency'] ?? $existing?->currency ?? 'MZN')));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new BillingContractException('The currency must be a 3-letter code.');
        }

        // Never guess a rate: a foreign-currency contract needs an
        // explicit rate (D11, same rule as Treasury D2).
        $rate = $data['exchange_rate'] ?? $existing?->exchange_rate ?? null;
        if ($currency === 'MZN') {
            $rate = '1.0000000000';
        } elseif ($rate === null || $rate === '' || ! is_numeric($rate) || (float) $rate <= 0) {
            throw new BillingContractException('A contract in ' . $currency . ' needs an exchange rate to MZN.');
        }
        $rate = bcadd((string) $rate, '0', 10);

        $lines = $this->validateLines($creatorId, $data['lines'] ?? null);

        $out = [
            'customer_id' => $customerId,
            'title' => $title,
            'description' => $data['description'] ?? $existing?->description ?? null,
            'start_date' => Carbon::parse($start)->format('Y-m-d'),
            'end_date' => ($end === null || $end === '') ? null : Carbon::parse($end)->format('Y-m-d'),
            'currency' => $currency,
            'exchange_rate' => $rate,
            'billing_frequency' => $frequency,
            'custom_interval' => $frequency === BillingContract::FREQUENCY_CUSTOM ? (int) $customInterval : null,
            'custom_unit' => $frequency === BillingContract::FREQUENCY_CUSTOM ? $customUnit : null,
            'anchor_day' => $anchorDay,
            'billing_mode' => $billingMode,
            'payment_terms_days' => max(0, (int) ($data['payment_terms_days'] ?? $existing?->payment_terms_days ?? 15)),
            'approval_mode' => $approvalMode,
            'proration_enabled' => $proration,
            'partial_period_rule' => $partialRule,
            'renewal_notice_days' => isset($data['renewal_notice_days']) && $data['renewal_notice_days'] !== ''
                ? max(0, (int) $data['renewal_notice_days'])
                : ($existing?->renewal_notice_days ?? 30),
            'auto_renew' => array_key_exists('auto_renew', $data) ? (bool) $data['auto_renew'] : ($existing?->auto_renew ?? false),
            'delivery_auto_email' => array_key_exists('delivery_auto_email', $data) ? (bool) $data['delivery_auto_email'] : ($existing?->delivery_auto_email ?? false),
            'delivery_to' => $this->nullIfEmpty($data['delivery_to'] ?? $existing?->delivery_to ?? null),
            'delivery_cc' => $this->nullIfEmpty($data['delivery_cc'] ?? $existing?->delivery_cc ?? null),
            'notes' => $data['notes'] ?? $existing?->notes ?? null,
            'branch_id' => $data['branch_id'] ?? $existing?->branch_id ?? null,
            'lines' => $lines,
        ];

        // On update/amend the caller merges these over the existing
        // row; fields the form did not send keep their values.
        if ($existing !== null && ! $isAmend) {
            $out['branch_id'] = $data['branch_id'] ?? $existing->branch_id;
        }

        return $out;
    }

    /** At least one line; each line has a description and sane money. */
    protected function validateLines(int $creatorId, mixed $lines): array
    {
        if (! is_array($lines)) {
            throw new BillingContractException('A contract needs at least one billing line.');
        }

        $clean = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $description = trim((string) ($line['description'] ?? ''));
            if ($description === '') {
                continue;
            }

            $quantity = $this->dec($line['quantity'] ?? '1');
            $unitPrice = $this->dec($line['unit_price'] ?? '0');
            $discount = $this->dec($line['discount_amount'] ?? '0');

            if (bccomp($quantity, '0', 4) <= 0) {
                throw new BillingContractException('A billing line quantity must be greater than zero.');
            }
            if (bccomp($unitPrice, '0', 4) < 0) {
                throw new BillingContractException('A billing line price cannot be negative.');
            }
            if (bccomp($discount, '0', 4) < 0 || bccomp($discount, bcmul($quantity, $unitPrice, 4), 4) > 0) {
                throw new BillingContractException('A billing line discount cannot exceed its gross amount.');
            }

            $taxId = $line['tax_id'] ?? null;
            if ($taxId !== null && $taxId !== '' && ! Tax::where('id', (int) $taxId)->where('created_by', $creatorId)->exists()) {
                throw new BillingContractException('Choose a tax that belongs to this company.');
            }

            $clean[] = [
                'product_id' => ! empty($line['product_id']) ? (int) $line['product_id'] : null,
                'description' => $description,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_amount' => $discount,
                'tax_id' => ($taxId === null || $taxId === '') ? null : (int) $taxId,
                'chart_account_id' => ! empty($line['chart_account_id']) ? (int) $line['chart_account_id'] : null,
                'cost_centre_id' => ! empty($line['cost_centre_id']) ? (int) $line['cost_centre_id'] : null,
            ];
        }

        if ($clean === []) {
            throw new BillingContractException('A contract needs at least one billing line.');
        }

        return $clean;
    }

    protected function isDate(mixed $value): bool
    {
        try {
            Carbon::parse($value);

            return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value);
        } catch (\Throwable) {
            return false;
        }
    }
}
