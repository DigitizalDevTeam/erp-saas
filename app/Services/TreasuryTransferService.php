<?php

namespace App\Services;

use App\Exceptions\TreasuryException;
use App\Models\DimensionAuditEvent;
use App\Models\TreasuryEntry;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryOperation;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Services\Accounting\ChartAccountResolver;
use App\Services\Accounting\JournalEntryPoster;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 4 — internal transfers with FX (plan §5, §6, §7,
 * §10, CA08, CA19).
 *
 * A transfer moves money between two financial accounts of the SAME
 * company (intercompany needs the intercompany workflow, which does
 * not exist yet — Phase 4 does not build it).
 *
 * States (plan §6): DRAFT → CONFIRMED (immediate: both legs
 * atomically, in one transaction under row locks, so two concurrent
 * confirms cannot double-move) or DRAFT → IN TRANSIT (delayed:
 * outflow confirmed to the transit account, inflow clears it on
 * receipt) → RETURNED (destination rejected: the money stays in
 * transit until a return is confirmed, then the transit balance is
 * cleared). A draft can be edited or cancelled; a confirmed transfer
 * is never edited (RF03).
 *
 * FX model (plan D2, CA19): the exchange rate converts source →
 * destination currency and is saved with its source and date; the
 * base rate converts source → company base currency. The
 * destination → base rate is DERIVED (base ÷ exchange), never
 * entered twice, so the two legs always reconcile and any residual
 * lands on the exchange-difference account as an explicit line.
 * If a required rate is missing, confirmation is blocked — never
 * guess a rate. Historical rates are preserved on the transfer and
 * the operation (CA19).
 *
 * Posting (plan §7): every leg posts through JournalEntryPoster
 * only — Dr destination (or transit) / Cr source, the fee as its
 * own expense line, realised differences posted explicitly. The
 * ledger is 2-decimal by design (the poster rounds each line), so
 * the journal lines are built from 2-decimal-rounded amounts and
 * the difference line is the 2-decimal residual; the transfer and
 * its entries keep the exact 4-decimal amounts for Treasury
 * balances. A payment in a closed period is blocked, or routed as
 * an authorised adjustment to an open period (CA17).
 *
 * CA08: a delayed transfer never double-counts — the money is in
 * exactly one place at a time (source, then transit, then
 * destination), and the sum of the three posted balances is
 * unchanged by the move (except the explicit exchange-difference
 * residual, which is a real gain/loss).
 */
class TreasuryTransferService
{
    public const AUDIT_ENTITY = 'treasury_transfer';

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

    public function transferForCompany(int $creatorId, int $transferId): TreasuryTransfer
    {
        $transfer = TreasuryTransfer::where('created_by', $creatorId)->find($transferId);

        if (! $transfer) {
            throw new TreasuryException('Transfer not found for this company.');
        }

        return $transfer;
    }

    // ------------------------------------------------------------------
    // Create / edit / cancel (draft only)
    // ------------------------------------------------------------------

    /**
     * Create a transfer draft. Currencies come from the accounts,
     * never from the form; the credited amount is derived from the
     * rate. The operation is created in draft with no reference —
     * the reference is allocated only when the transfer leaves
     * Draft (confirm), so a cancelled draft never consumes a number.
     *
     * @param array{source_account_id: int, destination_account_id: int, transit_account_id?: int|null, timing?: string, amount_debited: string, exchange_rate?: string|null, rate_source?: string|null, rate_date?: string|null, base_exchange_rate?: string|null, fee_amount?: string|null, evidence?: string|null, branch_id?: int|null, transfer_date?: string|null} $data
     */
    public function createTransfer(int $creatorId, array $data, int $actorId): TreasuryTransfer
    {
        return DB::transaction(function () use ($creatorId, $data, $actorId) {
            $validated = $this->validateDraftData($creatorId, $data);

            $operation = TreasuryOperation::create([
                'reference' => null,
                'type' => TreasuryOperation::TYPE_TRANSFER,
                'company_id' => $creatorId,
                'branch_id' => $validated['branch_id'],
                'financial_account_id' => $validated['source']->id,
                'counterparty_type' => 'entity',
                'counterparty_id' => null,
                'currency' => $validated['source_currency'],
                'amount_original' => $validated['amount_debited'],
                'exchange_rate' => $validated['base_exchange_rate'],
                'rate_source' => $validated['rate_source'],
                'rate_date' => $validated['rate_date'],
                'amount_base' => $validated['amount_debited_base'],
                'posting_date' => null,
                'effective_date' => $validated['transfer_date'],
                'value_date' => $validated['transfer_date'],
                'state' => TreasuryOperation::STATE_DRAFT,
                'source_type' => 'treasury_transfer',
                'source_id' => null,
                'created_by' => $creatorId,
            ]);

            $transfer = TreasuryTransfer::create([
                'operation_id' => $operation->id,
                'created_by' => $creatorId,
                'branch_id' => $validated['branch_id'],
                'source_account_id' => $validated['source']->id,
                'destination_account_id' => $validated['destination']->id,
                'transit_account_id' => $validated['transit'] ? $validated['transit']->id : null,
                'source_currency' => $validated['source_currency'],
                'destination_currency' => $validated['destination_currency'],
                'timing' => $validated['timing'],
                'amount_debited' => $validated['amount_debited'],
                'exchange_rate' => $validated['exchange_rate'],
                'rate_source' => $validated['rate_source'],
                'rate_date' => $validated['rate_date'],
                'base_exchange_rate' => $validated['base_exchange_rate'],
                'amount_credited' => $validated['amount_credited'],
                'amount_debited_base' => $validated['amount_debited_base'],
                'amount_credited_base' => $validated['amount_credited_base'],
                'fee_amount' => $validated['fee_amount'],
                'fee_base' => $validated['fee_base'],
                'state' => TreasuryTransfer::STATE_DRAFT,
                'evidence' => $validated['evidence'],
            ]);

            $operation->source_id = $transfer->id;
            $operation->save();

            $this->audit($creatorId, self::AUDIT_ENTITY, $transfer->id, 'created', null, $transfer->fresh()->toArray(), $actorId);

            return $transfer->fresh();
        });
    }

    /** Edit a draft: same validation as create, amounts re-derived. */
    public function updateTransfer(TreasuryTransfer $transfer, array $data, int $actorId): TreasuryTransfer
    {
        if (! $transfer->isDraft()) {
            throw new TreasuryException('Only a draft transfer can be edited (state: ' . $transfer->state . ').');
        }

        return DB::transaction(function () use ($transfer, $data, $actorId) {
            $creatorId = (int) $transfer->created_by;
            $validated = $this->validateDraftData($creatorId, $data);
            $before = $transfer->fresh()->toArray();

            $transfer->update([
                'branch_id' => $validated['branch_id'],
                'source_account_id' => $validated['source']->id,
                'destination_account_id' => $validated['destination']->id,
                'transit_account_id' => $validated['transit'] ? $validated['transit']->id : null,
                'source_currency' => $validated['source_currency'],
                'destination_currency' => $validated['destination_currency'],
                'timing' => $validated['timing'],
                'amount_debited' => $validated['amount_debited'],
                'exchange_rate' => $validated['exchange_rate'],
                'rate_source' => $validated['rate_source'],
                'rate_date' => $validated['rate_date'],
                'base_exchange_rate' => $validated['base_exchange_rate'],
                'amount_credited' => $validated['amount_credited'],
                'amount_debited_base' => $validated['amount_debited_base'],
                'amount_credited_base' => $validated['amount_credited_base'],
                'fee_amount' => $validated['fee_amount'],
                'fee_base' => $validated['fee_base'],
                'evidence' => $validated['evidence'],
            ]);

            $operation = $transfer->operation;
            if ($operation) {
                $operation->update([
                    'financial_account_id' => $validated['source']->id,
                    'branch_id' => $validated['branch_id'],
                    'currency' => $validated['source_currency'],
                    'amount_original' => $validated['amount_debited'],
                    'exchange_rate' => $validated['base_exchange_rate'],
                    'rate_source' => $validated['rate_source'],
                    'rate_date' => $validated['rate_date'],
                    'amount_base' => $validated['amount_debited_base'],
                    'effective_date' => $validated['transfer_date'],
                    'value_date' => $validated['transfer_date'],
                ]);
            }

            $this->audit($creatorId, self::AUDIT_ENTITY, $transfer->id, 'updated', $before, $transfer->fresh()->toArray(), $actorId);

            return $transfer->fresh();
        });
    }

    /** Cancel a draft — the operation is cancelled too and the (null) reference is never consumed. */
    public function cancelTransfer(TreasuryTransfer $transfer, int $actorId): TreasuryTransfer
    {
        if (! $transfer->isDraft()) {
            throw new TreasuryException('Only a draft transfer can be cancelled (state: ' . $transfer->state . ').');
        }

        return DB::transaction(function () use ($transfer, $actorId) {
            $before = $transfer->fresh()->toArray();
            $transfer->state = TreasuryTransfer::STATE_CANCELLED;
            $transfer->save();

            $operation = $transfer->operation;
            if ($operation) {
                $operation->state = TreasuryOperation::STATE_CANCELLED;
                $operation->save();
            }

            $this->audit((int) $transfer->created_by, self::AUDIT_ENTITY, $transfer->id, 'cancelled', $before, $transfer->fresh()->toArray(), $actorId);

            return $transfer->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Preview — the confirm screen shows the recheck results before
    // the user confirms (plan §8: a financial confirmation shows
    // account, counterparty, currency, amount and effect).
    // ------------------------------------------------------------------

    /**
     * @return array{transfer: TreasuryTransfer, source: TreasuryFinancialAccount, destination: TreasuryFinancialAccount, transit: TreasuryFinancialAccount|null, amounts: array, posting_date: string, posted_balance: string, available_balance: string, destination_balance: string, transit_balance: string|null, checks: array<int, array{key: string, passed: bool, detail: string}>, can_confirm: bool, adjustment: array|null}
     */
    public function previewConfirm(int $creatorId, int $transferId, int $actorId, array $options = []): array
    {
        $options['executor_user_id'] = (int) ($options['executor_user_id'] ?? $actorId);
        $transfer = $this->transferForCompany($creatorId, $transferId);
        $source = $this->accounts->accountForCompany($creatorId, (int) $transfer->source_account_id);
        $destination = $this->accounts->accountForCompany($creatorId, (int) $transfer->destination_account_id);
        $transit = $transfer->transit_account_id
            ? $this->accounts->accountForCompany($creatorId, (int) $transfer->transit_account_id)
            : null;

        $amounts = $this->computeAmounts($transfer);
        $posting = $this->resolvePostingDate($transfer, $options);
        $checks = $this->recheckConfirm($creatorId, $transfer, $source, $destination, $transit, $amounts, $posting, $options);

        $canConfirm = true;
        foreach ($checks as $check) {
            if (! $check['passed']) {
                $canConfirm = false;
                break;
            }
        }

        return [
            'transfer' => $transfer,
            'source' => $source,
            'destination' => $destination,
            'transit' => $transit,
            'amounts' => $amounts,
            'posting_date' => $posting['posting_date'],
            'posted_balance' => $this->accounts->postedBalance($source),
            'available_balance' => $this->accounts->availableBalance($source),
            'destination_balance' => $this->accounts->postedBalance($destination),
            'transit_balance' => $transit ? $this->accounts->postedBalance($transit) : null,
            'checks' => $checks,
            'can_confirm' => $canConfirm,
            'adjustment' => $posting['adjustment'],
        ];
    }

    // ------------------------------------------------------------------
    // Confirm — post the outflow leg (immediate: both legs at once)
    // ------------------------------------------------------------------

    /**
     * Confirm a draft transfer.
     *
     * Options:
     * - posting_date (default: today)
     * - adjustment: ['authorised_by' => int, 'reason' => string]
     *   (CA17 — closed period routed to an open period)
     *
     * @return array{transfer: TreasuryTransfer, operation: TreasuryOperation, journal: \App\Models\JournalEntry, entries: array, amounts: array, posting_date: string, adjustment: array|null}
     */
    public function confirmTransfer(int $creatorId, int $transferId, int $actorId, array $options = []): array
    {
        $options['executor_user_id'] = (int) ($options['executor_user_id'] ?? $actorId);

        return DB::transaction(function () use ($creatorId, $transferId, $actorId, $options) {
            $transfer = TreasuryTransfer::lockForUpdate()->findOrFail(
                $this->transferForCompany($creatorId, $transferId)->id
            );
            $source = TreasuryFinancialAccount::lockForUpdate()->findOrFail((int) $transfer->source_account_id);
            $destination = TreasuryFinancialAccount::lockForUpdate()->findOrFail((int) $transfer->destination_account_id);
            $transit = $transfer->transit_account_id
                ? TreasuryFinancialAccount::lockForUpdate()->findOrFail((int) $transfer->transit_account_id)
                : null;

            $amounts = $this->computeAmounts($transfer);
            $posting = $this->resolvePostingDate($transfer, $options);

            // Transactional recheck under lock: every failure
            // refuses the confirm and leaves the transfer a draft.
            $checks = $this->recheckConfirm($creatorId, $transfer, $source, $destination, $transit, $amounts, $posting, $options);
            foreach ($checks as $check) {
                if (! $check['passed']) {
                    throw new TreasuryException($check['detail']);
                }
            }

            return $this->confirmWithRecheck($creatorId, $transfer, $source, $destination, $transit, $amounts, $posting, $actorId, $options);
        });
    }

    /**
     * @param array{amount_debited: string, amount_credited: string, amount_debited_base: string, amount_credited_base: string, exchange_rate: string, base_exchange_rate: string, destination_base_rate: string, exchange_difference_base: string, fee_amount: string, fee_base: string} $amounts
     * @param array{posting_date: string, adjustment: array|null} $posting
     */
    protected function confirmWithRecheck(int $creatorId, TreasuryTransfer $transfer, TreasuryFinancialAccount $source, TreasuryFinancialAccount $destination, ?TreasuryFinancialAccount $transit, array $amounts, array $posting, int $actorId, array $options): array
    {
        $operation = $transfer->operation()->lockForUpdate()->firstOrFail();
        $sourceChartId = $this->chartAccountId($source);
        $postingDate = $posting['posting_date'];

        // The reference is allocated only now, when the transfer
        // leaves Draft — a cancelled draft never consumes a number.
        $operation->reference = $this->nextReference($creatorId, TreasuryOperation::TYPE_TRANSFER, $postingDate);
        $operation->state = TreasuryOperation::STATE_CONFIRMED;
        $operation->posting_date = $postingDate;
        $operation->amount_original = $amounts['amount_debited'];
        $operation->amount_base = $amounts['amount_debited_base'];
        $operation->save();

        $reference = (string) $operation->reference;
        $entries = [];

        if ($transfer->isDelayed()) {
            // Delayed: outflow to the transit account. The transit
            // leg is valued in the SOURCE currency (the money in
            // transit is still source-currency money until it
            // arrives), so no exchange difference arises here — it
            // is recognised at clear, when the destination leg is
            // valued (CA08: the money is in exactly one place).
            $transitChartId = $this->chartAccountId($transit);
            $built = $this->principalLines(
                $creatorId, $transitChartId, $amounts['amount_debited_base'],
                $sourceChartId, $amounts['amount_debited_base'],
                'Treasury transfer ' . $reference . ' — in transit (' . $transit->code . ')',
                'Treasury transfer ' . $reference . ' — ' . $source->code
            );
            $lines = array_merge($built['lines'], $this->feeLines($creatorId, $amounts['fee_base'], $sourceChartId, $reference));

            $journal = $this->poster->post(
                $creatorId,
                $postingDate,
                $reference,
                'Treasury transfer ' . $reference . ' — ' . $source->code . ' to transit',
                $lines
            );

            $entries[] = $this->recordEntry($operation, $source, TreasuryEntry::DIRECTION_OUTFLOW, $amounts['amount_debited'], $amounts['amount_debited_base'], $amounts['base_exchange_rate'], $journal->id, $creatorId);
            $entries[] = $this->recordEntry($operation, $transit, TreasuryEntry::DIRECTION_INFLOW, $amounts['amount_debited'], $amounts['amount_debited_base'], $amounts['base_exchange_rate'], $journal->id, $creatorId);
            $entries = $this->recordFeeEntry($operation, $source, $amounts, $journal->id, $creatorId, $entries);

            $transfer->state = TreasuryTransfer::STATE_IN_TRANSIT;
            $transfer->confirmed_at = now();
        } else {
            // Immediate: both legs atomically, one journal.
            $destinationChartId = $this->chartAccountId($destination);
            $built = $this->principalLines(
                $creatorId, $destinationChartId, $amounts['amount_credited_base'],
                $sourceChartId, $amounts['amount_debited_base'],
                'Treasury transfer ' . $reference . ' — ' . $destination->code,
                'Treasury transfer ' . $reference . ' — ' . $source->code
            );
            $lines = array_merge($built['lines'], $this->feeLines($creatorId, $amounts['fee_base'], $sourceChartId, $reference));

            $journal = $this->poster->post(
                $creatorId,
                $postingDate,
                $reference,
                'Treasury transfer ' . $reference . ' — ' . $source->code . ' to ' . $destination->code,
                $lines
            );

            $entries[] = $this->recordEntry($operation, $source, TreasuryEntry::DIRECTION_OUTFLOW, $amounts['amount_debited'], $amounts['amount_debited_base'], $amounts['base_exchange_rate'], $journal->id, $creatorId);
            $entries[] = $this->recordEntry($operation, $destination, TreasuryEntry::DIRECTION_INFLOW, $amounts['amount_credited'], $amounts['amount_credited_base'], $amounts['destination_base_rate'], $journal->id, $creatorId);
            $entries = $this->recordFeeEntry($operation, $source, $amounts, $journal->id, $creatorId, $entries);

            $transfer->state = TreasuryTransfer::STATE_CONFIRMED;
            $transfer->confirmed_at = now();
            $transfer->cleared_at = now();
            $transfer->exchange_difference_account_id = $built['difference_account_id'];
        }

        // Persist the computed amounts + the fee account used.
        $transfer->amount_credited = $amounts['amount_credited'];
        $transfer->amount_debited_base = $amounts['amount_debited_base'];
        $transfer->amount_credited_base = $amounts['amount_credited_base'];
        $transfer->exchange_difference_base = $amounts['exchange_difference_base'];
        if ($amounts['fee_base'] !== '0.0000' && bccomp($amounts['fee_base'], '0', 4) > 0) {
            $transfer->fee_account_id = $this->resolveFeeAccount($creatorId);
        }
        $transfer->save();

        $this->lockCurrencies([$source, $destination, $transit]);

        $this->audit($creatorId, self::AUDIT_ENTITY, $transfer->id, 'confirmed', null, [
            'transfer_id' => $transfer->id,
            'reference' => $reference,
            'journal_id' => $journal->id,
            'state' => $transfer->state,
            'amount_debited' => $amounts['amount_debited'],
            'amount_credited' => $amounts['amount_credited'],
            'amount_debited_base' => $amounts['amount_debited_base'],
            'amount_credited_base' => $amounts['amount_credited_base'],
            'exchange_difference_base' => $amounts['exchange_difference_base'],
            'posting_date' => $postingDate,
        ], $actorId);

        return [
            'transfer' => $transfer->fresh(),
            'operation' => $operation->fresh(),
            'journal' => $journal,
            'entries' => $entries,
            'amounts' => $amounts,
            'posting_date' => $postingDate,
            'adjustment' => $posting['adjustment'],
        ];
    }

    // ------------------------------------------------------------------
    // Clear — delayed transfer arrives at the destination (CA08)
    // ------------------------------------------------------------------

    /**
     * Clear an in-transit transfer: the inflow is received at the
     * destination and the transit balance is cleared to exactly
     * zero. The exchange difference is recognised here — when the
     * destination leg is valued — not at confirm.
     *
     * Options: posting_date (default today), adjustment (CA17).
     *
     * @return array{transfer: TreasuryTransfer, journal: \App\Models\JournalEntry, entries: array, amounts: array, posting_date: string, adjustment: array|null}
     */
    public function clearTransfer(int $creatorId, int $transferId, int $actorId, array $options = []): array
    {
        $options['executor_user_id'] = (int) ($options['executor_user_id'] ?? $actorId);

        return DB::transaction(function () use ($creatorId, $transferId, $actorId, $options) {
            $transfer = TreasuryTransfer::lockForUpdate()->findOrFail(
                $this->transferForCompany($creatorId, $transferId)->id
            );
            // State first: a non-in-transit transfer has no transit
            // account to load (immediate transfers never set one).
            if (! $transfer->isInTransit()) {
                throw new TreasuryException('Only an in-transit transfer can be cleared (state: ' . $transfer->state . ').');
            }
            $transit = TreasuryFinancialAccount::lockForUpdate()->findOrFail((int) $transfer->transit_account_id);
            $destination = TreasuryFinancialAccount::lockForUpdate()->findOrFail((int) $transfer->destination_account_id);
            $source = TreasuryFinancialAccount::lockForUpdate()->findOrFail((int) $transfer->source_account_id);

            $amounts = $this->computeAmounts($transfer);
            $posting = $this->resolvePostingDate($transfer, $options);
            $checks = $this->recheckClear($creatorId, $transfer, $source, $destination, $transit, $amounts, $posting, $options);
            foreach ($checks as $check) {
                if (! $check['passed']) {
                    throw new TreasuryException($check['detail']);
                }
            }

            $operation = $transfer->operation()->lockForUpdate()->firstOrFail();
            $reference = (string) $operation->reference;
            $postingDate = $posting['posting_date'];
            $destinationChartId = $this->chartAccountId($destination);
            $transitChartId = $this->chartAccountId($transit);

            // Dr destination (credited_base) / Cr transit
            // (debited_base): the transit leg was valued at the
            // source amount, so the residual is the exchange
            // difference — posted explicitly, and the transit
            // account nets to exactly zero.
            $built = $this->principalLines(
                $creatorId, $destinationChartId, $amounts['amount_credited_base'],
                $transitChartId, $amounts['amount_debited_base'],
                'Treasury transfer ' . $reference . ' — ' . $destination->code,
                'Treasury transfer ' . $reference . ' — clear transit (' . $transit->code . ')'
            );

            $journal = $this->poster->post(
                $creatorId,
                $postingDate,
                $reference,
                'Treasury transfer ' . $reference . ' — clear transit to ' . $destination->code,
                $built['lines']
            );

            $entries = [];
            // Transit outflow must equal the transit inflow
            // (debited_base) so the transit account nets to zero.
            $entries[] = $this->recordEntry($operation, $transit, TreasuryEntry::DIRECTION_OUTFLOW, $amounts['amount_debited'], $amounts['amount_debited_base'], $amounts['base_exchange_rate'], $journal->id, $creatorId);
            $entries[] = $this->recordEntry($operation, $destination, TreasuryEntry::DIRECTION_INFLOW, $amounts['amount_credited'], $amounts['amount_credited_base'], $amounts['destination_base_rate'], $journal->id, $creatorId);

            $transfer->state = TreasuryTransfer::STATE_CONFIRMED;
            $transfer->cleared_at = now();
            $transfer->exchange_difference_account_id = $built['difference_account_id'];
            $transfer->save();

            $this->audit($creatorId, self::AUDIT_ENTITY, $transfer->id, 'cleared', null, [
                'transfer_id' => $transfer->id,
                'reference' => $reference,
                'journal_id' => $journal->id,
                'state' => $transfer->state,
                'amount_credited' => $amounts['amount_credited'],
                'amount_credited_base' => $amounts['amount_credited_base'],
                'exchange_difference_base' => $amounts['exchange_difference_base'],
                'posting_date' => $postingDate,
            ], $actorId);

            return [
                'transfer' => $transfer->fresh(),
                'journal' => $journal,
                'entries' => $entries,
                'amounts' => $amounts,
                'posting_date' => $postingDate,
                'adjustment' => $posting['adjustment'],
            ];
        });
    }

    // ------------------------------------------------------------------
    // Return — destination rejected, money comes back (CA08)
    // ------------------------------------------------------------------

    /**
     * Return an in-transit transfer to the source account. The fee
     * is NOT refunded — it was charged at confirm and stays
     * charged. The operation stays confirmed; the return entries
     * net the original outflow, so source and transit both return
     * to their pre-transfer balances.
     *
     * Options: posting_date (default today), adjustment (CA17).
     *
     * @return array{transfer: TreasuryTransfer, journal: \App\Models\JournalEntry, entries: array, amounts: array, posting_date: string, adjustment: array|null}
     */
    public function returnTransfer(int $creatorId, int $transferId, int $actorId, ?string $reason = null, array $options = []): array
    {
        $options['executor_user_id'] = (int) ($options['executor_user_id'] ?? $actorId);

        return DB::transaction(function () use ($creatorId, $transferId, $actorId, $reason, $options) {
            $transfer = TreasuryTransfer::lockForUpdate()->findOrFail(
                $this->transferForCompany($creatorId, $transferId)->id
            );
            // State first: a non-in-transit transfer has no transit
            // account to load (immediate transfers never set one).
            if (! $transfer->isInTransit()) {
                throw new TreasuryException('Only an in-transit transfer can be returned (state: ' . $transfer->state . ').');
            }
            $transit = TreasuryFinancialAccount::lockForUpdate()->findOrFail((int) $transfer->transit_account_id);
            $source = TreasuryFinancialAccount::lockForUpdate()->findOrFail((int) $transfer->source_account_id);

            $amounts = $this->computeAmounts($transfer);
            $posting = $this->resolvePostingDate($transfer, $options);
            $checks = $this->recheckReturn($creatorId, $transfer, $source, $transit, $amounts, $posting, $options);
            foreach ($checks as $check) {
                if (! $check['passed']) {
                    throw new TreasuryException($check['detail']);
                }
            }

            $operation = $transfer->operation()->lockForUpdate()->firstOrFail();
            $reference = (string) $operation->reference;
            $postingDate = $posting['posting_date'];
            $sourceChartId = $this->chartAccountId($source);
            $transitChartId = $this->chartAccountId($transit);

            // Same amount both sides (source currency): no
            // exchange difference on a return.
            $journal = $this->poster->post(
                $creatorId,
                $postingDate,
                $reference,
                'Treasury transfer ' . $reference . ' — return to ' . $source->code,
                [
                    [
                        'account' => $sourceChartId,
                        'debit' => (float) $amounts['amount_debited_base'],
                        'description' => 'Treasury transfer ' . $reference . ' — return to ' . $source->code,
                    ],
                    [
                        'account' => $transitChartId,
                        'credit' => (float) $amounts['amount_debited_base'],
                        'description' => 'Treasury transfer ' . $reference . ' — return from transit (' . $transit->code . ')',
                    ],
                ]
            );

            $entries = [];
            $entries[] = $this->recordEntry($operation, $transit, TreasuryEntry::DIRECTION_OUTFLOW, $amounts['amount_debited'], $amounts['amount_debited_base'], $amounts['base_exchange_rate'], $journal->id, $creatorId);
            $entries[] = $this->recordEntry($operation, $source, TreasuryEntry::DIRECTION_INFLOW, $amounts['amount_debited'], $amounts['amount_debited_base'], $amounts['base_exchange_rate'], $journal->id, $creatorId);

            $transfer->state = TreasuryTransfer::STATE_RETURNED;
            $transfer->returned_at = now();
            $transfer->return_reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
            $transfer->save();

            $this->audit($creatorId, self::AUDIT_ENTITY, $transfer->id, 'returned', null, [
                'transfer_id' => $transfer->id,
                'reference' => $reference,
                'journal_id' => $journal->id,
                'state' => $transfer->state,
                'reason' => $transfer->return_reason,
                'posting_date' => $postingDate,
            ], $actorId);

            return [
                'transfer' => $transfer->fresh(),
                'journal' => $journal,
                'entries' => $entries,
                'amounts' => $amounts,
                'posting_date' => $postingDate,
                'adjustment' => $posting['adjustment'],
            ];
        });
    }

    // ------------------------------------------------------------------
    // Rechecks (plan §10 Phase 4 — all inside one transaction,
    // under lock, like the Phase 3 execution recheck)
    // ------------------------------------------------------------------

    /**
     * @param array{amount_debited: string, amount_credited: string, amount_debited_base: string, amount_credited_base: string, exchange_rate: string, base_exchange_rate: string, destination_base_rate: string, exchange_difference_base: string, fee_amount: string, fee_base: string} $amounts
     * @param array{posting_date: string, adjustment: array|null} $posting
     * @return array<int, array{key: string, passed: bool, detail: string}>
     */
    protected function recheckConfirm(int $creatorId, TreasuryTransfer $transfer, TreasuryFinancialAccount $source, TreasuryFinancialAccount $destination, ?TreasuryFinancialAccount $transit, array $amounts, array $posting, array $options): array
    {
        $checks = [];
        $add = function (string $key, bool $passed, string $detail) use (&$checks) {
            $checks[] = ['key' => $key, 'passed' => $passed, 'detail' => $detail];
        };

        // 1. State — a draft, and the lock means a second confirm
        // of the same transfer sees the new state and refuses.
        $add('state', $transfer->isDraft(), $transfer->isDraft()
            ? 'Transfer is a draft and can be confirmed.'
            : 'Only a draft transfer can be confirmed (state: ' . $transfer->state . ').');

        // 2. Accounts active and different.
        $add('source_account', $source->isActive(), $source->isActive()
            ? 'Source account ' . $source->code . ' is active.'
            : 'The source account ' . $source->code . ' is inactive.');
        $add('destination_account', $destination->isActive(), $destination->isActive()
            ? 'Destination account ' . $destination->code . ' is active.'
            : 'The destination account ' . $destination->code . ' is inactive.');
        $add('accounts_different', (int) $source->id !== (int) $destination->id, (int) $source->id !== (int) $destination->id
            ? 'Source and destination accounts are different.'
            : 'Source and destination accounts must be different.');
        if ($transfer->isDelayed()) {
            $add('transit_account', $transit !== null && $transit->isActive(), $transit !== null && $transit->isActive()
                ? 'Transit account ' . $transit->code . ' is active.'
                : 'A delayed transfer needs an active transit account.');
        }

        // 3. Rate present (D2): never guess a rate.
        $rateOk = true;
        $rateDetail = 'Same currency — no exchange rate needed.';
        if ($transfer->source_currency !== $transfer->destination_currency) {
            $rateOk = $amounts['exchange_rate'] !== '' && is_numeric($amounts['exchange_rate']) && bccomp($amounts['exchange_rate'], '0', 10) > 0
                && $amounts['base_exchange_rate'] !== '' && is_numeric($amounts['base_exchange_rate']) && bccomp($amounts['base_exchange_rate'], '0', 10) > 0;
            $rateDetail = $rateOk
                ? 'Exchange rate ' . $amounts['exchange_rate'] . ' ' . $transfer->source_currency . ' → ' . $transfer->destination_currency . ' (' . ($transfer->rate_source ?? 'manual') . ') is stored.'
                : 'No exchange rate is stored for this transfer — confirmation is blocked, never guess a rate (D2).';
        }
        $add('rate', $rateOk, $rateDetail);

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
        // principal + fee (both in base currency).
        $totalBase = bcadd($amounts['amount_debited_base'], $amounts['fee_base'], 4);
        $available = $this->accounts->availableBalance($source);
        $availabilityOk = bccomp($totalBase, $available, 4) <= 0;
        $add('availability', $availabilityOk, $availabilityOk
            ? 'Available ' . $available . ' covers ' . $totalBase . ' (principal ' . $amounts['amount_debited_base'] . ' + fee ' . $amounts['fee_base'] . ').'
            : 'Insufficient available balance on account ' . $source->code . ': ' . $available . ' available for ' . $totalBase . ' required.');

        // 6. Negative-balance policy on the projected source balance.
        $projected = bcsub($this->accounts->postedBalance($source), $totalBase, 4);
        $policy = $this->accounts->checkNegativeBalance($source, $projected);
        $negativeOk = $policy === 'allowed';
        $negativeDetail = 'Projected balance ' . $projected . ' is allowed by the account policy.';
        if ($policy === 'blocked') {
            $negativeDetail = 'The account policy blocks this transfer: the projected balance ' . $projected . ' would go below the allowed limit.';
        } elseif ($policy === 'needs_approval') {
            $approverId = (int) ($source->negative_balance_approver_user_id ?? 0);
            $executorId = (int) ($options['executor_user_id'] ?? 0);
            $negativeOk = $approverId > 0 && $executorId > 0 && $approverId === $executorId;
            $negativeDetail = $negativeOk
                ? 'Projected balance ' . $projected . ' needs approval, and the named approver is confirming this transfer.'
                : 'The projected balance ' . $projected . ' needs approval from the account approver (user #' . $approverId . ') before it can go negative.';
        }
        $add('negative_balance', $negativeOk, $negativeDetail);

        // 7. Posting accounts resolve (fee / exchange difference).
        $accountsOk = true;
        $accountsDetail = 'Posting accounts resolve.';
        try {
            if (bccomp($amounts['fee_base'], '0', 4) > 0) {
                $this->resolveFeeAccount($creatorId);
            }
            $diff2 = bcsub($this->round2($amounts['amount_debited_base']), $this->round2($amounts['amount_credited_base']), 2);
            if (bccomp($diff2, '0', 2) > 0) {
                $this->resolveExchangeLossAccount($creatorId);
            } elseif (bccomp($diff2, '0', 2) < 0) {
                $this->resolveExchangeGainAccount($creatorId);
            }
        } catch (TreasuryException $e) {
            $accountsOk = false;
            $accountsDetail = $e->getMessage();
        }
        $add('posting_accounts', $accountsOk, $accountsDetail);

        return $checks;
    }

    /**
     * @param array{amount_debited: string, amount_credited: string, amount_debited_base: string, amount_credited_base: string, exchange_rate: string, base_exchange_rate: string, destination_base_rate: string, exchange_difference_base: string, fee_amount: string, fee_base: string} $amounts
     * @param array{posting_date: string, adjustment: array|null} $posting
     * @return array<int, array{key: string, passed: bool, detail: string}>
     */
    protected function recheckClear(int $creatorId, TreasuryTransfer $transfer, TreasuryFinancialAccount $source, TreasuryFinancialAccount $destination, TreasuryFinancialAccount $transit, array $amounts, array $posting, array $options): array
    {
        $checks = [];
        $add = function (string $key, bool $passed, string $detail) use (&$checks) {
            $checks[] = ['key' => $key, 'passed' => $passed, 'detail' => $detail];
        };

        $add('state', $transfer->isInTransit(), $transfer->isInTransit()
            ? 'Transfer is in transit and can be cleared.'
            : 'Only an in-transit transfer can be cleared (state: ' . $transfer->state . ').');
        $add('destination_account', $destination->isActive(), $destination->isActive()
            ? 'Destination account ' . $destination->code . ' is active.'
            : 'The destination account ' . $destination->code . ' is inactive.');

        $postingDate = Carbon::parse($posting['posting_date']);
        $periodOpen = $this->isPeriodOpen($creatorId, $postingDate);
        $adjustmentOk = $posting['adjustment'] !== null;
        $add('period', $periodOpen || $adjustmentOk, $periodOpen
            ? 'Posting period ' . $postingDate->year . ' is open.'
            : ($adjustmentOk
                ? 'Posting year ' . $postingDate->year . ' is closed — posting as an authorised adjustment to ' . $posting['posting_date'] . '.'
                : 'Fiscal year ' . $postingDate->year . ' is closed. Treasury postings into a closed period are blocked — an authorised adjustment to an open period is required (CA17).'));

        // The transit account must still hold the money: its
        // available balance covers the outflow back out of it.
        $transitAvailable = $this->accounts->availableBalance($transit);
        $add('transit_balance', bccomp($amounts['amount_debited_base'], $transitAvailable, 4) <= 0, bccomp($amounts['amount_debited_base'], $transitAvailable, 4) <= 0
            ? 'Transit account holds ' . $transitAvailable . ' — the ' . $amounts['amount_debited_base'] . ' in transit can be cleared.'
            : 'Transit account holds only ' . $transitAvailable . ' — the ' . $amounts['amount_debited_base'] . ' in transit cannot be cleared.');

        try {
            $diff2 = bcsub($this->round2($amounts['amount_debited_base']), $this->round2($amounts['amount_credited_base']), 2);
            if (bccomp($diff2, '0', 2) > 0) {
                $this->resolveExchangeLossAccount($creatorId);
            } elseif (bccomp($diff2, '0', 2) < 0) {
                $this->resolveExchangeGainAccount($creatorId);
            }
            $add('posting_accounts', true, 'Posting accounts resolve.');
        } catch (TreasuryException $e) {
            $add('posting_accounts', false, $e->getMessage());
        }

        return $checks;
    }

    /**
     * @param array{amount_debited: string, amount_credited: string, amount_debited_base: string, amount_credited_base: string, exchange_rate: string, base_exchange_rate: string, destination_base_rate: string, exchange_difference_base: string, fee_amount: string, fee_base: string} $amounts
     * @param array{posting_date: string, adjustment: array|null} $posting
     * @return array<int, array{key: string, passed: bool, detail: string}>
     */
    protected function recheckReturn(int $creatorId, TreasuryTransfer $transfer, TreasuryFinancialAccount $source, TreasuryFinancialAccount $transit, array $amounts, array $posting, array $options): array
    {
        $checks = [];
        $add = function (string $key, bool $passed, string $detail) use (&$checks) {
            $checks[] = ['key' => $key, 'passed' => $passed, 'detail' => $detail];
        };

        $add('state', $transfer->isInTransit(), $transfer->isInTransit()
            ? 'Transfer is in transit and can be returned.'
            : 'Only an in-transit transfer can be returned (state: ' . $transfer->state . ').');
        $add('source_account', $source->isActive(), $source->isActive()
            ? 'Source account ' . $source->code . ' is active.'
            : 'The source account ' . $source->code . ' is inactive.');

        $postingDate = Carbon::parse($posting['posting_date']);
        $periodOpen = $this->isPeriodOpen($creatorId, $postingDate);
        $adjustmentOk = $posting['adjustment'] !== null;
        $add('period', $periodOpen || $adjustmentOk, $periodOpen
            ? 'Posting period ' . $postingDate->year . ' is open.'
            : ($adjustmentOk
                ? 'Posting year ' . $postingDate->year . ' is closed — posting as an authorised adjustment to ' . $posting['posting_date'] . '.'
                : 'Fiscal year ' . $postingDate->year . ' is closed. Treasury postings into a closed period are blocked — an authorised adjustment to an open period is required (CA17).'));

        $transitAvailable = $this->accounts->availableBalance($transit);
        $add('transit_balance', bccomp($amounts['amount_debited_base'], $transitAvailable, 4) <= 0, bccomp($amounts['amount_debited_base'], $transitAvailable, 4) <= 0
            ? 'Transit account holds ' . $transitAvailable . ' — the ' . $amounts['amount_debited_base'] . ' in transit can be returned.'
            : 'Transit account holds only ' . $transitAvailable . ' — the ' . $amounts['amount_debited_base'] . ' in transit cannot be returned.');

        return $checks;
    }

    // ------------------------------------------------------------------
    // Amounts + journal lines
    // ------------------------------------------------------------------

    /**
     * Derive every amount from the stored rate pair. Deterministic
     * truncation to 4 decimals (the module's convention, RF04); the
     * exchange-difference line absorbs the residual so the journal
     * always balances exactly.
     *
     * @return array{amount_debited: string, amount_credited: string, amount_debited_base: string, amount_credited_base: string, exchange_rate: string, base_exchange_rate: string, destination_base_rate: string, exchange_difference_base: string, fee_amount: string, fee_base: string}
     */
    public function computeAmounts(TreasuryTransfer $transfer): array
    {
        $debited = $this->decimalString($transfer->amount_debited, 'Amount');
        // A missing stored rate must block — never fall back to 1.
        $rateRaw = $transfer->exchange_rate;
        $baseRateRaw = $transfer->base_exchange_rate;
        if ($rateRaw === null || $rateRaw === '' || ! is_numeric($rateRaw)) {
            throw new TreasuryException('No exchange rate is stored for this transfer — confirmation is blocked, never guess a rate (D2).');
        }
        if ($baseRateRaw === null || $baseRateRaw === '' || ! is_numeric($baseRateRaw)) {
            throw new TreasuryException('No base exchange rate is stored for this transfer — confirmation is blocked, never guess a rate (D2).');
        }
        $rate = $this->rateString($rateRaw);
        $baseRate = $this->rateString($baseRateRaw);
        $fee = $this->decimalString($transfer->fee_amount ?? '0', 'Fee');

        if (bccomp($debited, '0', 4) <= 0) {
            throw new TreasuryException('The transfer amount must be greater than zero.');
        }
        if (bccomp($rate, '0', 10) <= 0 || bccomp($baseRate, '0', 10) <= 0) {
            throw new TreasuryException('No exchange rate is stored for this transfer — confirmation is blocked, never guess a rate (D2).');
        }

        $credited = bcmul($debited, $rate, 4);
        $debitedBase = bcmul($debited, $baseRate, 4);
        // Destination → base is derived, never entered twice
        // (CA19): 1 destination unit = baseRate ÷ rate base units.
        $destinationBaseRate = bcdiv($baseRate, $rate, 10);
        $creditedBase = bcmul($credited, $destinationBaseRate, 4);
        $difference = bcsub($debitedBase, $creditedBase, 4);
        $feeBase = bcmul($fee, $baseRate, 4);

        return [
            'amount_debited' => $debited,
            'amount_credited' => $credited,
            'amount_debited_base' => $debitedBase,
            'amount_credited_base' => $creditedBase,
            'exchange_rate' => $rate,
            'base_exchange_rate' => $baseRate,
            'destination_base_rate' => $destinationBaseRate,
            'exchange_difference_base' => $difference,
            'fee_amount' => $fee,
            'fee_base' => $feeBase,
        ];
    }

    /**
     * Build the principal journal lines for one leg, balanced at
     * the ledger's 2-decimal precision. The residual between the
     * two legs (rounding, or a genuinely different cross rate) is
     * an explicit exchange-difference line — never folded into a
     * leg, never dropped.
     *
     * @return array{lines: array, difference_account_id: int|null, difference_2dp: string}
     */
    protected function principalLines(int $creatorId, int $debitAccount, string $debitAmount4, int $creditAccount, string $creditAmount4, string $debitDescription, string $creditDescription): array
    {
        $debit2 = $this->round2($debitAmount4);
        $credit2 = $this->round2($creditAmount4);

        if (bccomp($debit2, '0', 2) <= 0 && bccomp($credit2, '0', 2) <= 0) {
            throw new TreasuryException('The transfer amount is too small to post (both legs round to zero).');
        }

        // Signed residual, positive = loss (we credited more base
        // than we debited): credit2 − debit2.
        $difference2 = bcsub($credit2, $debit2, 2);

        $lines = [
            [
                'account' => $debitAccount,
                'debit' => (float) $debit2,
                'description' => $debitDescription,
            ],
            [
                'account' => $creditAccount,
                'credit' => (float) $credit2,
                'description' => $creditDescription,
            ],
        ];

        $differenceAccountId = null;
        if (bccomp($difference2, '0', 2) > 0) {
            // Credit leg is bigger: extra debit = realised loss.
            $differenceAccountId = $this->resolveExchangeLossAccount($creatorId);
            $lines[] = [
                'account' => $differenceAccountId,
                'debit' => (float) $difference2,
                'description' => 'Realised exchange difference (loss)',
            ];
        } elseif (bccomp($difference2, '0', 2) < 0) {
            // Debit leg is bigger: extra credit = realised gain.
            $differenceAccountId = $this->resolveExchangeGainAccount($creatorId);
            $lines[] = [
                'account' => $differenceAccountId,
                'credit' => (float) bcmul($difference2, '-1', 2),
                'description' => 'Realised exchange difference (gain)',
            ];
        }

        return [
            'lines' => $lines,
            'difference_account_id' => $differenceAccountId,
            'difference_2dp' => $difference2,
        ];
    }

    /** Fee lines: Dr fee expense / Cr source, self-balancing. */
    protected function feeLines(int $creatorId, string $feeBase4, int $sourceChartId, string $reference): array
    {
        $fee2 = $this->round2($feeBase4);
        if (bccomp($fee2, '0', 2) <= 0) {
            return [];
        }

        $feeAccountId = $this->resolveFeeAccount($creatorId);

        return [
            [
                'account' => $feeAccountId,
                'debit' => (float) $fee2,
                'description' => 'Treasury transfer ' . $reference . ' — transfer fee',
            ],
            [
                'account' => $sourceChartId,
                'credit' => (float) $fee2,
                'description' => 'Treasury transfer ' . $reference . ' — transfer fee',
            ],
        ];
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

    /**
     * The fee is real money out of the source account — it needs
     * its own outflow entry, or the Treasury posted balance would
     * not see it (the journal alone does not feed postedBalance).
     * A return never refunds it.
     *
     * @param array{amount_debited: string, amount_credited: string, amount_debited_base: string, amount_credited_base: string, exchange_rate: string, base_exchange_rate: string, destination_base_rate: string, exchange_difference_base: string, fee_amount: string, fee_base: string} $amounts
     */
    protected function recordFeeEntry(TreasuryOperation $operation, TreasuryFinancialAccount $source, array $amounts, int $journalId, int $creatorId, array $entries): array
    {
        if (bccomp($amounts['fee_base'], '0', 4) > 0) {
            $entries[] = $this->recordEntry($operation, $source, TreasuryEntry::DIRECTION_OUTFLOW, $amounts['fee_amount'], $amounts['fee_base'], $amounts['base_exchange_rate'], $journalId, $creatorId);
        }

        return $entries;
    }

    /** Lock an account's currency once it has a confirmed movement (Phase 0 rule). */
    protected function lockCurrencies(array $accounts): void
    {
        foreach ($accounts as $account) {
            if ($account && ! $account->currency_locked) {
                $account->currency_locked = true;
                $account->save();
            }
        }
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
    // Draft validation (create + edit share it)
    // ------------------------------------------------------------------

    /**
     * @return array{source: TreasuryFinancialAccount, destination: TreasuryFinancialAccount, transit: TreasuryFinancialAccount|null, branch_id: int|null, timing: string, amount_debited: string, exchange_rate: string, rate_source: string|null, rate_date: string|null, base_exchange_rate: string, amount_credited: string, amount_debited_base: string, amount_credited_base: string, fee_amount: string, fee_base: string, source_currency: string, destination_currency: string, evidence: string|null, transfer_date: string}
     */
    protected function validateDraftData(int $creatorId, array $data): array
    {
        $sourceId = (int) ($data['source_account_id'] ?? 0);
        $destinationId = (int) ($data['destination_account_id'] ?? 0);
        if ($sourceId <= 0 || $destinationId <= 0) {
            throw new TreasuryException('Source and destination accounts are required.');
        }
        if ($sourceId === $destinationId) {
            throw new TreasuryException('Source and destination accounts must be different.');
        }

        $source = $this->accounts->accountForCompany($creatorId, $sourceId);
        $destination = $this->accounts->accountForCompany($creatorId, $destinationId);

        if (! $source->isActive() || ! $destination->isActive()) {
            throw new TreasuryException('Source and destination accounts must both be active.');
        }
        if ($source->type === TreasuryFinancialAccount::TYPE_FUNDS_IN_TRANSIT) {
            throw new TreasuryException('A funds-in-transit account cannot be the source of a transfer — it only holds money between legs.');
        }
        if ($destination->type === TreasuryFinancialAccount::TYPE_FUNDS_IN_TRANSIT) {
            throw new TreasuryException('A funds-in-transit account cannot be the destination of a transfer — pick a transit account for a delayed transfer instead.');
        }

        $timing = (string) ($data['timing'] ?? TreasuryTransfer::TIMING_IMMEDIATE);
        if (! in_array($timing, TreasuryTransfer::TIMINGS, true)) {
            throw new TreasuryException('Transfer timing must be immediate or delayed.');
        }

        $transit = null;
        if ($timing === TreasuryTransfer::TIMING_DELAYED) {
            $transitId = (int) ($data['transit_account_id'] ?? 0);
            if ($transitId <= 0) {
                throw new TreasuryException('A delayed transfer needs a transit account.');
            }
            $transit = $this->accounts->accountForCompany($creatorId, $transitId);
            if (! $transit->isActive()) {
                throw new TreasuryException('The transit account must be active.');
            }
            if ($transit->type !== TreasuryFinancialAccount::TYPE_FUNDS_IN_TRANSIT) {
                throw new TreasuryException('The transit account must be a funds-in-transit account.');
            }
            if ($transit->currency !== $source->currency) {
                throw new TreasuryException('The transit account must use the source currency (' . $source->currency . ') — money in transit is still source-currency money until it arrives.');
            }
        }

        $amount = $this->decimalString($data['amount_debited'] ?? null, 'Amount');
        if (bccomp($amount, '0', 4) <= 0) {
            throw new TreasuryException('The transfer amount must be greater than zero.');
        }

        $fee = $this->decimalString($data['fee_amount'] ?? '0', 'Fee');
        if (bccomp($fee, '0', 4) < 0) {
            throw new TreasuryException('The fee cannot be negative.');
        }

        $sourceCurrency = (string) $source->currency;
        $destinationCurrency = (string) $destination->currency;
        $baseCurrency = TreasurySettings::baseCurrency($creatorId);

        // Rates: same currency needs none; otherwise the
        // source → destination rate is required, and the source →
        // base rate is required only when neither leg is the base
        // currency (otherwise it is 1, or the same rate).
        $rateSource = null;
        $rateDate = null;
        if ($sourceCurrency === $destinationCurrency) {
            $rate = '1.0000000000';
            if ($sourceCurrency === $baseCurrency) {
                $baseRate = '1.0000000000';
            } else {
                $baseInput = $data['base_exchange_rate'] ?? null;
                if ($baseInput === null || trim((string) $baseInput) === '') {
                    throw new TreasuryException('A base exchange rate is required when neither account uses the base currency (' . $baseCurrency . ').');
                }
                $baseRate = $this->rateString($baseInput, 'Base exchange rate');
                if (bccomp($baseRate, '0', 10) <= 0) {
                    throw new TreasuryException('A base exchange rate is required when neither account uses the base currency (' . $baseCurrency . ').');
                }
            }
        } else {
            $rateInput = $data['exchange_rate'] ?? null;
            if ($rateInput === null || trim((string) $rateInput) === '') {
                throw new TreasuryException('An exchange rate is required when the accounts use different currencies — never guess a rate (D2).');
            }
            $rate = $this->rateString($rateInput, 'Exchange rate');
            if (bccomp($rate, '0', 10) <= 0) {
                throw new TreasuryException('An exchange rate is required when the accounts use different currencies — never guess a rate (D2).');
            }
            $rateSource = trim((string) ($data['rate_source'] ?? ''));
            if ($rateSource === '') {
                throw new TreasuryException('The exchange-rate source is required (bank rate, central bank, or manual entry).');
            }
            $rateSource = substr($rateSource, 0, 20);
            $rateDate = $this->nullableDate($data['rate_date'] ?? null, 'Rate date') ?? date('Y-m-d');

            if ($sourceCurrency === $baseCurrency) {
                $baseRate = '1.0000000000';
            } elseif ($destinationCurrency === $baseCurrency) {
                $baseRate = $rate;
            } else {
                $baseInput = $data['base_exchange_rate'] ?? null;
                if ($baseInput === null || trim((string) $baseInput) === '') {
                    throw new TreasuryException('A base exchange rate is required when neither account uses the base currency (' . $baseCurrency . ').');
                }
                $baseRate = $this->rateString($baseInput, 'Base exchange rate');
                if (bccomp($baseRate, '0', 10) <= 0) {
                    throw new TreasuryException('A base exchange rate is required when neither account uses the base currency (' . $baseCurrency . ').');
                }
            }
        }

        $credited = bcmul($amount, $rate, 4);
        $debitedBase = bcmul($amount, $baseRate, 4);
        $destinationBaseRate = bcdiv($baseRate, $rate, 10);
        $creditedBase = bcmul($credited, $destinationBaseRate, 4);

        $transferDate = $this->nullableDate($data['transfer_date'] ?? null, 'Transfer date') ?? date('Y-m-d');

        return [
            'source' => $source,
            'destination' => $destination,
            'transit' => $transit,
            'branch_id' => isset($data['branch_id']) && (int) $data['branch_id'] > 0 ? (int) $data['branch_id'] : null,
            'timing' => $timing,
            'amount_debited' => $amount,
            'exchange_rate' => $rate,
            'rate_source' => $rateSource,
            'rate_date' => $rateDate,
            'base_exchange_rate' => $baseRate,
            'amount_credited' => $credited,
            'amount_debited_base' => $debitedBase,
            'amount_credited_base' => $creditedBase,
            'fee_amount' => $fee,
            'fee_base' => bcmul($fee, $baseRate, 4),
            'source_currency' => $sourceCurrency,
            'destination_currency' => $destinationCurrency,
            'evidence' => isset($data['evidence']) && trim((string) $data['evidence']) !== '' ? trim((string) $data['evidence']) : null,
            'transfer_date' => $transferDate,
        ];
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

    protected function resolveFeeAccount(int $creatorId): int
    {
        return $this->resolveAccountOrFail(
            $creatorId,
            TreasurySettings::get($creatorId, TreasurySettings::KEY_FEE_ACCOUNT_CODE),
            '61',
            'bank fees (61)'
        );
    }

    /** Realised loss: Hoya chart 694 "Diferenças de câmbio desfavoráveis". */
    protected function resolveExchangeLossAccount(int $creatorId): int
    {
        return $this->resolveAccountOrFail(
            $creatorId,
            TreasurySettings::get($creatorId, TreasurySettings::KEY_EXCHANGE_LOSS_ACCOUNT_CODE),
            '694',
            'exchange differences — loss (694)'
        );
    }

    /** Realised gain: Hoya chart 784 "Diferenças de câmbio favoráveis". */
    protected function resolveExchangeGainAccount(int $creatorId): int
    {
        return $this->resolveAccountOrFail(
            $creatorId,
            TreasurySettings::get($creatorId, TreasurySettings::KEY_EXCHANGE_GAIN_ACCOUNT_CODE),
            '784',
            'exchange differences — gain (784)'
        );
    }

    /**
     * @return array{posting_date: string, adjustment: array|null}
     */
    protected function resolvePostingDate(TreasuryTransfer $transfer, array $options): array
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
        // executor, and must hold the transfer permission.
        $executorId = (int) ($options['executor_user_id'] ?? 0);
        if ($executorId > 0 && $authorisedBy === $executorId) {
            throw new TreasuryException('The adjustment must be authorised by a different user than the executor.');
        }
        if (! $this->userHasPermission((int) $transfer->created_by, $authorisedBy, 'manage treasury transfers')) {
            throw new TreasuryException('The adjustment authoriser does not hold the manage treasury transfers permission.');
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
