<?php

namespace App\Services;

use App\Exceptions\TreasuryException;
use App\Models\DimensionAuditEvent;
use App\Models\TreasuryPaymentAttempt;
use App\Models\TreasuryPaymentBatch;
use App\Models\TreasuryPaymentBatchItem;
use App\Models\TreasuryPaymentRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 3 — payment batches (plan §5, §6, CA07).
 *
 * A batch groups several approved payment requests under one
 * reference, one state and one outcome per instruction. A batch
 * is never all-or-nothing on the money: each instruction settles
 * or fails on its own, in its own transaction, and a failed item
 * keeps the successful items settled and stays in the batch for
 * review. Re-running a batch resumes only its pending items.
 *
 * Each item execution uses a deterministic idempotency key
 * (batch + item + try number), so two concurrent runs of the same
 * batch converge on the same attempt instead of double-paying,
 * and a failed item retries with a fresh key on the next run.
 */
class TreasuryPaymentBatchService
{
    public const AUDIT_ENTITY = 'treasury_payment_batch';

    protected TreasuryExecutionService $execution;
    protected TreasuryPaymentService $payments;

    public function __construct(
        ?TreasuryExecutionService $execution = null,
        ?TreasuryPaymentService $payments = null,
    ) {
        $this->execution = $execution ?? new TreasuryExecutionService();
        $this->payments = $payments ?? new TreasuryPaymentService();
    }

    public function batchForCompany(int $creatorId, int $batchId): TreasuryPaymentBatch
    {
        $batch = TreasuryPaymentBatch::where('created_by', $creatorId)->find($batchId);

        if (! $batch) {
            throw new TreasuryException('Payment batch not found for this company.');
        }

        return $batch;
    }

    /**
     * Create a draft batch. Each item defaults to the request's
     * remaining amount; an explicit amount must be positive and
     * within the remaining amount.
     *
     * @param  array{provider?: string, note?: string, branch_id?: int, items: array<int, array{payment_request_id: int, amount_original?: string}>}  $data
     */
    public function createBatch(int $creatorId, array $data, int $actorId): TreasuryPaymentBatch
    {
        $items = $data['items'] ?? [];
        if (! is_array($items) || $items === []) {
            throw new TreasuryException('A batch needs at least one payment request.');
        }

        return DB::transaction(function () use ($creatorId, $data, $items, $actorId) {
            $batch = TreasuryPaymentBatch::create([
                'created_by' => $creatorId,
                'branch_id' => isset($data['branch_id']) && $data['branch_id'] !== '' ? (int) $data['branch_id'] : null,
                'reference' => null, // Allocated only when it leaves Draft.
                'provider' => isset($data['provider']) && trim((string) $data['provider']) !== '' ? trim((string) $data['provider']) : TreasuryExecutionService::PROVIDER_MANUAL,
                'state' => TreasuryPaymentBatch::STATE_DRAFT,
                'note' => isset($data['note']) ? trim((string) $data['note']) : null,
            ]);

            $seen = [];
            foreach ($items as $item) {
                $requestId = (int) ($item['payment_request_id'] ?? 0);
                if ($requestId <= 0) {
                    throw new TreasuryException('Each batch item needs a payment request.');
                }
                if (in_array($requestId, $seen, true)) {
                    throw new TreasuryException('Payment request #' . $requestId . ' is listed twice in this batch.');
                }
                $seen[] = $requestId;

                $request = $this->payments->requestForCompany($creatorId, $requestId);

                if (! $request->isApproved() && ! $request->isProcessing()) {
                    throw new TreasuryException('Payment request #' . $requestId . ' is not approved (state: ' . $request->state . ').');
                }

                $alreadyBatched = TreasuryPaymentBatchItem::where('payment_request_id', $requestId)
                    ->whereHas('batch', fn ($q) => $q
                        ->where('created_by', $creatorId)
                        ->whereIn('state', TreasuryPaymentBatch::OPEN_STATES))
                    ->exists();
                if ($alreadyBatched) {
                    throw new TreasuryException('Payment request #' . $requestId . ' is already in an open batch.');
                }

                $amountOriginal = isset($item['amount_original']) && $item['amount_original'] !== ''
                    ? $this->decimalString($item['amount_original'], 'Amount')
                    : $request->remainingAmountOriginal();

                if (bccomp($amountOriginal, '0', 4) <= 0 || bccomp($amountOriginal, $request->remainingAmountOriginal(), 4) > 0) {
                    throw new TreasuryException('The batch amount for request #' . $requestId . ' must be greater than zero and no more than the remaining ' . $request->remainingAmountOriginal() . ' ' . $request->currency . '.');
                }

                $amountBase = bccomp($amountOriginal, $request->remainingAmountOriginal(), 4) === 0
                    ? $request->remainingAmountBase()
                    : bcmul($amountOriginal, (string) ($request->exchange_rate ?: '1'), 4);

                TreasuryPaymentBatchItem::create([
                    'batch_id' => $batch->id,
                    'payment_request_id' => $requestId,
                    'amount_original' => $amountOriginal,
                    'amount_base' => $amountBase,
                    'currency' => $request->currency,
                    'state' => TreasuryPaymentBatchItem::STATE_PENDING,
                    'created_by' => $creatorId,
                ]);
            }

            $this->audit($creatorId, self::AUDIT_ENTITY, $batch->id, 'created', null, $batch->fresh()->toArray(), $actorId);

            return $batch->fresh();
        });
    }

    /** Cancel a draft (or partially paid) batch; pending items are skipped. */
    public function cancelBatch(TreasuryPaymentBatch $batch, int $actorId): TreasuryPaymentBatch
    {
        return DB::transaction(function () use ($batch, $actorId) {
            $batch = TreasuryPaymentBatch::lockForUpdate()->findOrFail($batch->id);

            if (! in_array($batch->state, [TreasuryPaymentBatch::STATE_DRAFT, TreasuryPaymentBatch::STATE_PARTIALLY_PAID], true)) {
                throw new TreasuryException('Only a draft or partially paid batch can be cancelled (state: ' . $batch->state . ').');
            }

            $batch->items()->where('state', TreasuryPaymentBatchItem::STATE_PENDING)
                ->update(['state' => TreasuryPaymentBatchItem::STATE_SKIPPED]);

            $batch->state = TreasuryPaymentBatch::STATE_CANCELLED;
            $batch->save();

            $this->audit((int) $batch->created_by, self::AUDIT_ENTITY, $batch->id, 'cancelled', null, $batch->fresh()->toArray(), $actorId);

            return $batch->fresh();
        });
    }

    /**
     * Execute a batch (CA07). Each pending item settles or fails
     * on its own, in its own transaction; a failure never rolls
     * back the items that already settled. Re-running resumes
     * only the pending items.
     */
    public function executeBatch(int $creatorId, int $batchId, int $actorId, array $options = []): TreasuryPaymentBatch
    {
        // Claim the batch: allocate the reference on first
        // execution and move it to processing, under lock.
        $batch = DB::transaction(function () use ($creatorId, $batchId, $actorId) {
            $batch = TreasuryPaymentBatch::lockForUpdate()->findOrFail(
                $this->batchForCompany($creatorId, $batchId)->id
            );

            if (! in_array($batch->state, [
                TreasuryPaymentBatch::STATE_DRAFT,
                TreasuryPaymentBatch::STATE_PROCESSING,
                TreasuryPaymentBatch::STATE_PARTIALLY_PAID,
            ], true)) {
                throw new TreasuryException('This batch cannot be executed (state: ' . $batch->state . ').');
            }

            if ($batch->isDraft()) {
                $batch->reference = $this->nextReference($creatorId);
            }
            $batch->state = TreasuryPaymentBatch::STATE_PROCESSING;
            $batch->executed_by = $actorId;
            $batch->executed_at = now();
            $batch->save();

            // Failed items are retried on a re-run (CA07: the
            // failed item is kept for review, then settled when
            // the reason is fixed). Each retry uses a fresh
            // idempotency key (see executeItem).
            $batch->items()->where('state', TreasuryPaymentBatchItem::STATE_FAILED)
                ->update(['state' => TreasuryPaymentBatchItem::STATE_PENDING, 'failure_reason' => null]);

            return $batch->fresh();
        });

        $provider = trim((string) ($options['provider'] ?? $batch->provider ?? TreasuryExecutionService::PROVIDER_MANUAL)) ?: TreasuryExecutionService::PROVIDER_MANUAL;

        foreach ($batch->items()->where('state', TreasuryPaymentBatchItem::STATE_PENDING)->orderBy('id')->get() as $item) {
            $this->executeItem($creatorId, $batch, $item, $provider, $actorId, $options);
        }

        return $this->refreshBatchState($batch, $actorId);
    }

    protected function executeItem(int $creatorId, TreasuryPaymentBatch $batch, TreasuryPaymentBatchItem $item, string $provider, int $actorId, array $options): void
    {
        $tries = TreasuryPaymentAttempt::where('payment_request_id', $item->payment_request_id)
            ->where('idempotency_key', 'like', 'batch-' . $batch->id . '-item-' . $item->id . '-%')
            ->count() + 1;
        $key = 'batch-' . $batch->id . '-item-' . $item->id . '-try-' . $tries;

        try {
            $result = DB::transaction(function () use ($creatorId, $item, $provider, $actorId, $options, $key) {
                $outcome = $this->execution->execute(
                    $creatorId,
                    (int) $item->payment_request_id,
                    (string) $item->amount_original,
                    $actorId,
                    array_merge($options, [
                        'provider' => $provider,
                        'idempotency_key' => $key,
                    ])
                );

                $item->state = TreasuryPaymentBatchItem::STATE_PAID;
                $item->attempt_id = $outcome['attempt']->id;
                $item->failure_reason = null;
                $item->save();

                return $outcome;
            });

            $this->audit($creatorId, self::AUDIT_ENTITY, $batch->id, 'item_paid', null, [
                'item_id' => $item->id,
                'payment_request_id' => $item->payment_request_id,
                'attempt_id' => $result['attempt']->id,
            ], $actorId);
        } catch (TreasuryException $e) {
            $item->state = TreasuryPaymentBatchItem::STATE_FAILED;
            $item->failure_reason = $e->getMessage();
            $item->save();

            $this->audit($creatorId, self::AUDIT_ENTITY, $batch->id, 'item_failed', null, [
                'item_id' => $item->id,
                'payment_request_id' => $item->payment_request_id,
                'reason' => $e->getMessage(),
            ], $actorId);
        }
    }

    /** Recompute the batch state from its items (CA07 outcome). */
    protected function refreshBatchState(TreasuryPaymentBatch $batch, int $actorId): TreasuryPaymentBatch
    {
        return DB::transaction(function () use ($batch, $actorId) {
            $batch = TreasuryPaymentBatch::lockForUpdate()->findOrFail($batch->id);
            $items = $batch->items()->get();

            $paid = $items->where('state', TreasuryPaymentBatchItem::STATE_PAID)->count();
            $failed = $items->where('state', TreasuryPaymentBatchItem::STATE_FAILED)->count();
            $total = $items->count();

            if ($total > 0 && $paid === $total) {
                $batch->state = TreasuryPaymentBatch::STATE_PAID;
            } elseif ($paid > 0) {
                $batch->state = TreasuryPaymentBatch::STATE_PARTIALLY_PAID;
            } elseif ($failed > 0) {
                $batch->state = TreasuryPaymentBatch::STATE_FAILED;
            } else {
                $batch->state = TreasuryPaymentBatch::STATE_PROCESSING;
            }
            $batch->save();

            $this->audit((int) $batch->created_by, self::AUDIT_ENTITY, $batch->id, 'executed', null, $batch->fresh()->toArray(), $actorId);

            return $batch->fresh();
        });
    }

    /** Reference: {COMPANY}-BCH-{YEAR}-{SEQ}, allocated on first execution. */
    protected function nextReference(int $creatorId): string
    {
        $year = Carbon::now()->year;
        $prefix = $this->companyReferencePrefix($creatorId);
        $head = $prefix . '-BCH-' . $year . '-';

        $max = 0;
        $existing = TreasuryPaymentBatch::where('created_by', $creatorId)
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
