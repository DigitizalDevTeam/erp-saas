<?php

namespace App\Services;

use App\Exceptions\TreasuryException;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\DimensionAuditEvent;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\JournalEntry;
use App\Models\JournalItem;
use App\Models\Transaction;
use App\Models\TreasuryEntry;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryOpeningBalance;
use App\Models\TreasuryOperation;
use App\Models\TreasuryReceipt;
use App\Models\TreasurySettlementAllocation;
use App\Models\User;
use App\Models\Utility;
use App\Services\Accounting\ChartAccountResolver;
use App\Services\Accounting\JournalEntryPoster;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 1 — receipts + allocations (plan §3, §5, §6, §7).
 *
 * Rules enforced here (service layer, not only the UI):
 * - RF01 — post once: each confirmed receipt posts exactly one bank
 *   journal; each allocation posts exactly one credit-liability -> AR
 *   journal. All writes happen in one DB transaction.
 * - RF02 — only a confirmed receipt settles an invoice, through an
 *   allocation. The invoice is the source document; Treasury never
 *   re-creates invoice logic — settling writes an InvoicePayment row
 *   (so Invoice::getDue() moves) and updates the invoice status.
 * - RF03 — confirmed means immutable: a confirmed receipt is
 *   corrected only by an authorised reversal linked to the original,
 *   with a reason. Drafts can be edited; cancelled history is kept.
 * - RF04 — money DECIMAL(20,4), rates DECIMAL(20,10); all math is
 *   bcmath, never float, so allocations sum exactly.
 * - RF05 — posting / effective / value dates are kept apart.
 * - RF06 — every action is scoped to the company (creatorId) on the
 *   server; a cross-company id is refused, never trusted.
 * - D2 — original + base + rate + rate source + rate date on every
 *   operation. A missing required rate refuses — never guess a rate.
 *   Historical rates are preserved (CA19): a confirmed operation
 *   keeps the rate it was confirmed with.
 * - CA17 — a closed fiscal period blocks confirm / allocate / reverse.
 *
 * Amount model (plan §7): `amount_original` is the gross amount the
 * customer paid, before bank fees and withholding. Cash credited to
 * the bank is gross - fees - withholding. The settlement value (what
 * is applied to invoices) is gross + discount: the discount granted
 * is added to the settlement, the fee/withholding are the company's
 * own lines. Unapplied credit = settlement value - allocated.
 *
 * Confirm journal (base currency, through JournalEntryPoster):
 *   Dr bank/cash account      gross - fees - withholding
 *   Dr fee expense            fees              (when > 0)
 *   Dr withholding receivable withholding       (when > 0)
 *   Dr discount expense       discount          (when > 0)
 *   Cr customer credit liab.  gross + discount
 * Allocation journal:
 *   Dr customer credit liab.  allocated (base)
 *   Cr accounts receivable    allocated (base)
 * A reversal posts the equal and opposite of each original journal.
 */
class TreasuryReceiptService
{
    public const AUDIT_ENTITY = 'treasury_receipt';
    public const AUDIT_OPERATION_ENTITY = 'treasury_operation';
    public const AUDIT_ALLOCATION_ENTITY = 'treasury_settlement_allocation';

    protected TreasuryAccountService $accounts;
    protected JournalEntryPoster $poster;
    protected ChartAccountResolver $resolver;

    public function __construct(
        ?TreasuryAccountService $accounts = null,
        ?JournalEntryPoster $poster = null,
        ?ChartAccountResolver $resolver = null
    ) {
        $this->accounts = $accounts ?? new TreasuryAccountService();
        $this->poster = $poster ?? new JournalEntryPoster();
        $this->resolver = $resolver ?? new ChartAccountResolver();
    }

    // ------------------------------------------------------------------
    // Receipts — create / edit / delete (drafts only)
    // ------------------------------------------------------------------

    public function createReceipt(int $creatorId, array $data, int $actorId): TreasuryReceipt
    {
        $account = $this->accounts->accountForCompany($creatorId, (int) ($data['financial_account_id'] ?? 0));

        if (! $account->isActive()) {
            throw new TreasuryException('Receipts cannot be created on an inactive account.');
        }

        $customerId = isset($data['customer_id']) && $data['customer_id'] !== '' ? (int) $data['customer_id'] : null;
        $customer = $customerId !== null ? $this->customerForCompany($creatorId, $customerId) : null;

        $classification = trim((string) ($data['classification'] ?? ''));
        $classificationReason = trim((string) ($data['classification_reason'] ?? ''));
        if ($customer === null && ($classification === '' || $classificationReason === '')) {
            throw new TreasuryException('A receipt without an invoice needs a classification and a reason.');
        }

        $method = (string) ($data['method'] ?? TreasuryReceipt::METHOD_TRANSFER);
        if (! in_array($method, TreasuryReceipt::METHODS, true)) {
            throw new TreasuryException('Unknown receipt method.');
        }

        $amount = $this->decimalString($data['amount'] ?? null, 'Amount');
        if (bccomp($amount, '0', 4) <= 0) {
            throw new TreasuryException('The receipt amount must be greater than zero.');
        }

        $fees = $this->decimalString($data['fees'] ?? '0', 'Fees');
        $withholding = $this->decimalString($data['withholding'] ?? '0', 'Withholding');
        $discount = $this->decimalString($data['discount'] ?? '0', 'Discount');
        foreach (['fees' => $fees, 'withholding' => $withholding, 'discount' => $discount] as $label => $value) {
            if (bccomp($value, '0', 4) < 0) {
                throw new TreasuryException(ucfirst($label) . ' cannot be negative.');
            }
        }
        if (bccomp(bcadd($fees, $withholding, 4), $amount, 4) > 0) {
            throw new TreasuryException('Fees plus withholding cannot exceed the gross amount (the bank credit would be negative).');
        }

        if ($method === TreasuryReceipt::METHOD_CHEQUE
            && (trim((string) ($data['cheque_bank'] ?? '')) === '' || trim((string) ($data['cheque_number'] ?? '')) === '')
        ) {
            throw new TreasuryException('A cheque receipt needs the bank and the cheque number.');
        }

        $currency = strtoupper(trim((string) ($data['currency'] ?? $account->currency)));
        if ($currency !== $account->currency) {
            throw new TreasuryException('The receipt currency must match the account currency (' . $account->currency . ').');
        }

        // Rate is resolved now and stored; re-validated at confirm
        // (the base currency may have changed since creation — D2).
        [$rate, $rateSource, $rateDate, $amountBase] = $this->accounts->convertToBase($creatorId, $currency, $amount, $data);

        $postingDate = $this->dateString($data['posting_date'] ?? date('Y-m-d'), 'Posting date');
        $effectiveDate = $this->dateString($data['effective_date'] ?? $postingDate, 'Effective date');
        $valueDate = $this->dateString($data['value_date'] ?? $postingDate, 'Value date');
        $branchId = $account->branch_id ? (int) $account->branch_id : null;

        return DB::transaction(function () use ($creatorId, $account, $customer, $classification, $classificationReason, $method, $amount, $fees, $withholding, $discount, $currency, $rate, $rateSource, $rateDate, $amountBase, $postingDate, $effectiveDate, $valueDate, $branchId, $actorId, $data) {
            $operation = TreasuryOperation::create([
                'reference' => null, // Allocated only when it leaves Draft.
                'type' => TreasuryOperation::TYPE_RECEIPT,
                'company_id' => $creatorId,
                'branch_id' => $branchId,
                'financial_account_id' => $account->id,
                'counterparty_type' => $customer ? 'customer' : 'entity',
                'counterparty_id' => $customer ? $customer->id : null,
                'currency' => $currency,
                'amount_original' => $amount,
                'exchange_rate' => $rate,
                'rate_source' => $rateSource,
                'rate_date' => $rateDate,
                'amount_base' => $amountBase,
                'posting_date' => $postingDate,
                'effective_date' => $effectiveDate,
                'value_date' => $valueDate,
                'state' => TreasuryOperation::STATE_DRAFT,
                'source_type' => 'manual',
                'source_id' => null,
                'created_by' => $creatorId,
            ]);

            $receipt = TreasuryReceipt::create([
                'operation_id' => $operation->id,
                'customer_id' => $customer ? $customer->id : null,
                'method' => $method,
                'external_transaction_id' => $this->nullableString($data['external_transaction_id'] ?? null, 100),
                'value_date' => $valueDate,
                'cheque_bank' => $this->nullableString($data['cheque_bank'] ?? null, 100),
                'cheque_number' => $this->nullableString($data['cheque_number'] ?? null, 50),
                'fees' => $fees,
                'withholding' => $withholding,
                'discount' => $discount,
                'state' => TreasuryReceipt::STATE_DRAFT,
                'classification' => $classification !== '' ? $classification : null,
                'classification_reason' => $classificationReason !== '' ? $classificationReason : null,
                'evidence' => $this->nullableString($data['evidence'] ?? null, 1000),
                'created_by' => $creatorId,
            ]);

            $this->audit($creatorId, self::AUDIT_ENTITY, $receipt->id, 'created', null, $receipt->fresh()->toArray(), $actorId);

            return $receipt->fresh();
        });
    }

    /** Drafts only. A confirmed receipt is never edited (RF03). */
    public function updateReceipt(TreasuryReceipt $receipt, array $data, int $actorId): TreasuryReceipt
    {
        if (! $receipt->isDraft()) {
            throw new TreasuryException('Only a draft receipt can be edited.');
        }

        $creatorId = (int) $receipt->created_by;
        $operation = $receipt->operation;
        $before = $receipt->fresh()->toArray();

        return DB::transaction(function () use ($receipt, $operation, $data, $actorId, $creatorId, $before) {
            $changed = false;

            if (array_key_exists('financial_account_id', $data)) {
                $account = $this->accounts->accountForCompany($creatorId, (int) $data['financial_account_id']);
                if (! $account->isActive()) {
                    throw new TreasuryException('Receipts cannot be created on an inactive account.');
                }
                if ((string) $operation->currency !== $account->currency) {
                    throw new TreasuryException('The receipt currency must match the account currency (' . $account->currency . ').');
                }
                $operation->financial_account_id = $account->id;
                $operation->branch_id = $account->branch_id ? (int) $account->branch_id : null;
                $changed = true;
            }

            if (array_key_exists('customer_id', $data)) {
                $customerId = $data['customer_id'] !== '' && $data['customer_id'] !== null ? (int) $data['customer_id'] : null;
                $customer = $customerId !== null ? $this->customerForCompany($creatorId, $customerId) : null;
                $receipt->customer_id = $customer ? $customer->id : null;
                $operation->counterparty_type = $customer ? 'customer' : 'entity';
                $operation->counterparty_id = $customer ? $customer->id : null;
                $changed = true;
            }

            if (array_key_exists('method', $data)) {
                if (! in_array($data['method'], TreasuryReceipt::METHODS, true)) {
                    throw new TreasuryException('Unknown receipt method.');
                }
                $receipt->method = $data['method'];
                $changed = true;
            }

            $recomputeBase = false;
            if (array_key_exists('amount', $data)) {
                $amount = $this->decimalString($data['amount'], 'Amount');
                if (bccomp($amount, '0', 4) <= 0) {
                    throw new TreasuryException('The receipt amount must be greater than zero.');
                }
                $operation->amount_original = $amount;
                $recomputeBase = true;
                $changed = true;
            }
            foreach (['fees', 'withholding', 'discount'] as $field) {
                if (array_key_exists($field, $data)) {
                    $value = $this->decimalString($data[$field], ucfirst($field));
                    if (bccomp($value, '0', 4) < 0) {
                        throw new TreasuryException(ucfirst($field) . ' cannot be negative.');
                    }
                    $receipt->{$field} = $value;
                    $changed = true;
                }
            }
            if (bccomp(bcadd((string) $receipt->fees, (string) $receipt->withholding, 4), (string) $operation->amount_original, 4) > 0) {
                throw new TreasuryException('Fees plus withholding cannot exceed the gross amount (the bank credit would be negative).');
            }
            if ($recomputeBase || array_key_exists('exchange_rate', $data) || array_key_exists('rate_source', $data) || array_key_exists('rate_date', $data)) {
                [$rate, $rateSource, $rateDate, $amountBase] = $this->accounts->convertToBase(
                    $creatorId,
                    (string) $operation->currency,
                    (string) $operation->amount_original,
                    array_merge($operation->toArray(), $data)
                );
                $operation->exchange_rate = $rate;
                $operation->rate_source = $rateSource;
                $operation->rate_date = $rateDate;
                $operation->amount_base = $amountBase;
            }

            foreach (['posting_date', 'effective_date', 'value_date'] as $field) {
                if (array_key_exists($field, $data)) {
                    $operation->{$field} = $this->dateString($data[$field], ucfirst(str_replace('_', ' ', $field)));
                    $changed = true;
                }
            }
            if (array_key_exists('value_date', $data)) {
                $receipt->value_date = $operation->value_date;
            }

            foreach (['external_transaction_id' => 100, 'cheque_bank' => 100, 'cheque_number' => 50, 'classification' => 50, 'classification_reason' => 1000, 'evidence' => 1000] as $field => $max) {
                if (array_key_exists($field, $data)) {
                    $receipt->{$field} = $this->nullableString($data[$field], $max);
                    $changed = true;
                }
            }

            if ($receipt->customer_id === null
                && (trim((string) $receipt->classification) === '' || trim((string) $receipt->classification_reason) === '')
            ) {
                throw new TreasuryException('A receipt without an invoice needs a classification and a reason.');
            }
            if ($receipt->method === TreasuryReceipt::METHOD_CHEQUE
                && (trim((string) $receipt->cheque_bank) === '' || trim((string) $receipt->cheque_number) === '')
            ) {
                throw new TreasuryException('A cheque receipt needs the bank and the cheque number.');
            }

            if ($changed || $operation->isDirty()) {
                $operation->save();
                $receipt->save();
                $this->audit($creatorId, self::AUDIT_ENTITY, $receipt->id, 'updated', $before, $receipt->fresh()->toArray(), $actorId);
            }

            return $receipt->fresh();
        });
    }

    /** Drafts only — deleting a draft is not touching confirmed history. */
    public function deleteReceipt(TreasuryReceipt $receipt, int $actorId): void
    {
        if (! $receipt->isDraft()) {
            throw new TreasuryException('Only a draft receipt can be deleted.');
        }

        $creatorId = (int) $receipt->created_by;
        $before = $receipt->fresh()->toArray();
        $operation = $receipt->operation;

        DB::transaction(function () use ($receipt, $operation) {
            $receipt->delete();
            $operation?->delete();
        });

        $this->audit($creatorId, self::AUDIT_ENTITY, (int) $before['id'], 'deleted', $before, null, $actorId);
    }

    /**
     * Attach evidence (a note / file reference) to a draft or pending
     * receipt. CA04: evidence changes neither cash nor debt until
     * confirmation — this writes the text field only, never a posting.
     */
    public function attachEvidence(TreasuryReceipt $receipt, string $evidence, int $actorId): TreasuryReceipt
    {
        if (! $receipt->isDraft() && ! $receipt->isPending()) {
            throw new TreasuryException('Evidence can only be attached while the receipt is a draft or pending.');
        }

        $before = $receipt->fresh()->toArray();
        $receipt->evidence = $this->nullableString($evidence, 1000);
        $receipt->save();

        $this->audit((int) $receipt->created_by, self::AUDIT_ENTITY, $receipt->id, 'evidence_attached', $before, $receipt->fresh()->toArray(), $actorId);

        return $receipt->fresh();
    }

    // ------------------------------------------------------------------
    // Workflow — submit / confirm / reject / cancel
    // ------------------------------------------------------------------

    /**
     * Draft -> Pending. The reference number is allocated here, when
     * the operation leaves Draft — a draft that is cancelled never
     * consumes a number.
     */
    public function submitReceipt(TreasuryReceipt $receipt, int $actorId): TreasuryReceipt
    {
        if (! $receipt->isDraft()) {
            throw new TreasuryException('Only a draft receipt can be submitted.');
        }

        $creatorId = (int) $receipt->created_by;
        $operation = $receipt->operation;

        return DB::transaction(function () use ($receipt, $operation, $actorId, $creatorId) {
            // Serialize reference allocation for this company + type +
            // year so two concurrent submits cannot take the same number.
            $reference = $this->nextReference($creatorId, TreasuryOperation::TYPE_RECEIPT, (string) $operation->posting_date);

            $operation->reference = $reference;
            $operation->state = TreasuryOperation::STATE_PENDING;
            $operation->save();

            $before = $receipt->fresh()->toArray();
            $receipt->state = TreasuryReceipt::STATE_PENDING;
            $receipt->save();

            $this->audit($creatorId, self::AUDIT_ENTITY, $receipt->id, 'submitted', $before, $receipt->fresh()->toArray(), $actorId);

            return $receipt->fresh();
        });
    }

    /**
     * Pending -> Confirmed. Posts the ONE bank journal for the receipt
     * (RF01), creates the treasury entry, and issues the receipt
     * number (== the operation reference). The money sits as unapplied
     * customer credit until it is allocated to invoices.
     */
    public function confirmReceipt(TreasuryReceipt $receipt, int $actorId): TreasuryReceipt
    {
        if (! $receipt->isPending()) {
            throw new TreasuryException('Only a pending receipt can be confirmed.');
        }

        $creatorId = (int) $receipt->created_by;

        return DB::transaction(function () use ($receipt, $actorId, $creatorId) {
            $operation = $receipt->operation()->lockForUpdate()->first();
            $receipt = TreasuryReceipt::lockForUpdate()->findOrFail($receipt->id);

            if ($operation->reference === null || $operation->reference === '') {
                throw new TreasuryException('The receipt has no reference number. Submit it first.');
            }

            $this->assertPeriodOpen($creatorId, Carbon::parse((string) $operation->posting_date));

            $account = $this->accounts->accountForCompany($creatorId, (int) $operation->financial_account_id);
            if (! $account->isActive()) {
                throw new TreasuryException('The financial account is inactive.');
            }

            // Re-validate the rate at confirmation (the base currency may
            // have changed since the receipt was created — D2/CA19: the
            // stored rate is kept when it is still valid, never guessed).
            [$rate, $rateSource, $rateDate, $amountBase] = $this->accounts->convertToBase(
                $creatorId,
                (string) $operation->currency,
                (string) $operation->amount_original,
                array_merge($operation->toArray(), ['exchange_rate' => $operation->exchange_rate])
            );

            $bankChartId = $account->bankAccount ? (int) $account->bankAccount->chart_account_id : 0;
            if ($bankChartId <= 0) {
                throw new TreasuryException('The financial account has no chart account. Set one on the account before confirming.');
            }

            $settlementBase = $this->settlementValueBase($operation, $receipt);
            $creditAccountId = $settlementBase !== '0.0000' && bccomp($settlementBase, '0', 4) > 0
                ? $this->resolveCustomerCreditAccount($creatorId)
                : null;

            $lines = $this->confirmJournalLines($creatorId, $account, $operation, $receipt, $bankChartId, $creditAccountId, $amountBase);

            $before = $receipt->fresh()->toArray();

            $journal = $this->poster->post(
                $creatorId,
                (string) $operation->posting_date,
                (string) $operation->reference,
                'Treasury receipt ' . $operation->reference,
                $lines
            );

            $operation->exchange_rate = $rate;
            $operation->rate_source = $rateSource;
            $operation->rate_date = $rateDate;
            $operation->amount_base = $amountBase;
            $operation->state = TreasuryOperation::STATE_CONFIRMED;
            $operation->save();

            // The entry is the BANK movement: net cash actually credited
            // (gross - fees - withholding). The gross lives on the
            // operation; the fee/withholding/discount lines live on the
            // journal. Recording gross here would overstate the posted
            // balance by the fees whenever a fee exists.
            $netCash = $receipt->netCashAmount();
            $entry = TreasuryEntry::create([
                'operation_id' => $operation->id,
                'financial_account_id' => $operation->financial_account_id,
                'direction' => TreasuryEntry::DIRECTION_INFLOW,
                'amount_original' => $netCash,
                'amount_base' => bcmul($netCash, $rate, 4),
                'exchange_rate' => $rate,
                'confirmed_at' => now(),
                'journal_id' => $journal->id,
                'created_by' => $creatorId,
            ]);

            $receipt->state = TreasuryReceipt::STATE_CONFIRMED;
            $receipt->receipt_number = (string) $operation->reference;
            $receipt->confirmed_by = $actorId;
            $receipt->confirmed_at = now();
            $receipt->save();

            // First confirmed movement locks the account currency.
            $account = $this->accounts->accountForCompany($creatorId, (int) $operation->financial_account_id);
            if (! $account->currency_locked) {
                $account->currency_locked = true;
                $account->save();
            }

            $this->audit($creatorId, self::AUDIT_ENTITY, $receipt->id, 'confirmed', $before, $receipt->fresh()->toArray(), $actorId);
            $this->audit($creatorId, self::AUDIT_OPERATION_ENTITY, $operation->id, 'confirmed', null, ['journal_id' => $journal->id, 'entry_id' => $entry->id], $actorId);

            return $receipt->fresh();
        });
    }

    public function rejectReceipt(TreasuryReceipt $receipt, string $reason, int $actorId): TreasuryReceipt
    {
        if (! $receipt->isPending()) {
            throw new TreasuryException('Only a pending receipt can be rejected.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new TreasuryException('A rejection needs a reason.');
        }

        $creatorId = (int) $receipt->created_by;

        return DB::transaction(function () use ($receipt, $reason, $actorId, $creatorId) {
            $before = $receipt->fresh()->toArray();

            $receipt->state = TreasuryReceipt::STATE_REJECTED;
            $receipt->reversal_reason = $reason;
            $receipt->save();

            $operation = $receipt->operation;
            if ($operation) {
                $operation->state = TreasuryOperation::STATE_REJECTED;
                $operation->reversal_reason = $reason;
                $operation->save();
            }

            $this->audit($creatorId, self::AUDIT_ENTITY, $receipt->id, 'rejected', $before, $receipt->fresh()->toArray(), $actorId);

            return $receipt->fresh();
        });
    }

    public function cancelReceipt(TreasuryReceipt $receipt, ?string $reason, int $actorId): TreasuryReceipt
    {
        if (! $receipt->isDraft() && ! $receipt->isPending()) {
            throw new TreasuryException('Only a draft or pending receipt can be cancelled.');
        }

        $creatorId = (int) $receipt->created_by;

        return DB::transaction(function () use ($receipt, $reason, $actorId, $creatorId) {
            $before = $receipt->fresh()->toArray();

            $receipt->state = TreasuryReceipt::STATE_CANCELLED;
            $receipt->reversal_reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
            $receipt->save();

            $operation = $receipt->operation;
            if ($operation) {
                $operation->state = TreasuryOperation::STATE_CANCELLED;
                $operation->reversal_reason = $receipt->reversal_reason;
                $operation->save();
            }

            $this->audit($creatorId, self::AUDIT_ENTITY, $receipt->id, 'cancelled', $before, $receipt->fresh()->toArray(), $actorId);

            return $receipt->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Reversal (CA15)
    // ------------------------------------------------------------------

    /**
     * Reverse a confirmed receipt (RF03). The original stays; the
     * reversal is a NEW confirmed operation + receipt with negated
     * amounts, linked via `reversal_of_id`. Active allocations are
     * reversed first (their journals mirrored, their InvoicePayments
     * removed, the invoices reopened), then the confirm journal is
     * mirrored, so the posted balance nets to zero.
     */
    public function reverseReceipt(TreasuryReceipt $receipt, string $reason, int $actorId): TreasuryReceipt
    {
        if (! $receipt->isConfirmed()) {
            throw new TreasuryException('Only a confirmed receipt can be reversed.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new TreasuryException('A reversal needs a reason.');
        }

        $creatorId = (int) $receipt->created_by;

        return DB::transaction(function () use ($receipt, $reason, $actorId, $creatorId) {
            $operation = $receipt->operation()->lockForUpdate()->first();
            $receipt = TreasuryReceipt::lockForUpdate()->findOrFail($receipt->id);

            $this->assertPeriodOpen($creatorId, Carbon::parse((string) $operation->posting_date));

            $before = $receipt->fresh()->toArray();

            // 1. Reverse every active allocation: mirror its journal,
            //    remove its InvoicePayment + Transaction, reopen the debt.
            $allocations = TreasurySettlementAllocation::where('receipt_id', $receipt->id)
                ->where('state', TreasurySettlementAllocation::STATE_ACTIVE)
                ->orderBy('id')
                ->get();

            foreach ($allocations as $allocation) {
                $this->reverseAllocation($allocation, $actorId);
            }

            // 2. Mirror the confirm journal (equal and opposite).
            $entry = TreasuryEntry::where('operation_id', $operation->id)
                ->whereNull('reversal_of_id')
                ->first();

            $reversalJournal = null;
            if ($entry && $entry->journal_id) {
                $reversalJournal = $this->postMirrorJournal(
                    $creatorId,
                    (int) $entry->journal_id,
                    (string) $operation->reference . '-REV',
                    'Reversal of Treasury receipt ' . $operation->reference . ': ' . $reason
                );
            }

            // 3. The reversal operation + receipt (negated amounts).
            $reversalReference = $this->nextReference($creatorId, TreasuryOperation::TYPE_RECEIPT, (string) $operation->posting_date);

            $reversalOperation = TreasuryOperation::create([
                'reference' => $reversalReference,
                'type' => TreasuryOperation::TYPE_RECEIPT,
                'company_id' => $creatorId,
                'branch_id' => $operation->branch_id,
                'financial_account_id' => $operation->financial_account_id,
                'counterparty_type' => $operation->counterparty_type,
                'counterparty_id' => $operation->counterparty_id,
                'currency' => $operation->currency,
                'amount_original' => $this->negate((string) $operation->amount_original),
                'exchange_rate' => $operation->exchange_rate,
                'rate_source' => $operation->rate_source,
                'rate_date' => $operation->rate_date,
                'amount_base' => $this->negate((string) $operation->amount_base),
                'posting_date' => date('Y-m-d'),
                'effective_date' => date('Y-m-d'),
                'value_date' => date('Y-m-d'),
                'state' => TreasuryOperation::STATE_CONFIRMED,
                'source_type' => 'manual',
                'source_id' => null,
                'reversal_of_id' => $operation->id,
                'reversal_reason' => $reason,
                'created_by' => $creatorId,
            ]);

            // The reversal entry negates the ORIGINAL ENTRY (the net
            // bank movement), not the operation gross — so the posted
            // balance nets to zero even when the receipt had fees.
            TreasuryEntry::create([
                'operation_id' => $reversalOperation->id,
                'financial_account_id' => $operation->financial_account_id,
                'direction' => TreasuryEntry::DIRECTION_INFLOW,
                'amount_original' => $entry ? $this->negate((string) $entry->amount_original) : $this->negate((string) $operation->amount_original),
                'amount_base' => $entry ? $this->negate((string) $entry->amount_base) : $this->negate((string) $operation->amount_base),
                'exchange_rate' => $operation->exchange_rate,
                'confirmed_at' => now(),
                'reversal_of_id' => $entry ? $entry->id : null,
                'journal_id' => $reversalJournal ? $reversalJournal->id : null,
                'created_by' => $creatorId,
            ]);

            $reversalReceipt = TreasuryReceipt::create([
                'operation_id' => $reversalOperation->id,
                'customer_id' => $receipt->customer_id,
                'method' => $receipt->method,
                'external_transaction_id' => $receipt->external_transaction_id,
                'value_date' => date('Y-m-d'),
                'fees' => $this->negate((string) $receipt->fees),
                'withholding' => $this->negate((string) $receipt->withholding),
                'discount' => $this->negate((string) $receipt->discount),
                'state' => TreasuryReceipt::STATE_CONFIRMED,
                'confirmed_by' => $actorId,
                'confirmed_at' => now(),
                'receipt_number' => $reversalReference,
                'classification' => $receipt->classification,
                'classification_reason' => $receipt->classification_reason,
                'reversal_of_id' => $receipt->id,
                'reversal_reason' => $reason,
                'created_by' => $creatorId,
            ]);

            // 4. The original is kept, marked reversed.
            $receipt->state = TreasuryReceipt::STATE_REVERSED;
            $receipt->reversal_reason = $reason;
            $receipt->save();

            $operation->state = TreasuryOperation::STATE_REVERSED;
            $operation->reversal_reason = $reason;
            $operation->save();

            $this->audit($creatorId, self::AUDIT_ENTITY, $receipt->id, 'reversed', $before, $receipt->fresh()->toArray(), $actorId);
            $this->audit($creatorId, self::AUDIT_ENTITY, $reversalReceipt->id, 'created', null, $reversalReceipt->fresh()->toArray(), $actorId);

            return $reversalReceipt->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Allocations
    // ------------------------------------------------------------------

    /**
     * Allocate a confirmed receipt to invoices, in order.
     *
     * Each allocation is checked inside one transaction with the
     * invoice row locked (CA02): two concurrent settlements recheck
     * the balance and cannot over-allocate. An allocation can never
     * exceed the invoice's outstanding debt or the receipt's
     * remaining unapplied credit.
     *
     * Cross-currency (receipt currency != invoice currency, and
     * invoices are base currency): needs an approver (a different
     * user than the creator) and a rate + source + date — otherwise
     * blocked.
     *
     * @param  array<int, array{invoice_id: int, amount: string, exchange_rate?: string, rate_source?: string, rate_date?: string}>  $allocations
     * @return TreasurySettlementAllocation[]
     */
    public function allocate(TreasuryReceipt $receipt, array $allocations, int $actorId, ?int $approvedBy = null): array
    {
        if (! $receipt->isConfirmed()) {
            throw new TreasuryException('Only a confirmed receipt can be allocated.');
        }

        if ($allocations === []) {
            throw new TreasuryException('Nothing to allocate.');
        }

        $creatorId = (int) $receipt->created_by;

        return DB::transaction(function () use ($receipt, $allocations, $actorId, $approvedBy, $creatorId) {
            $operation = $receipt->operation()->lockForUpdate()->first();
            $receipt = TreasuryReceipt::lockForUpdate()->findOrFail($receipt->id);

            $this->assertPeriodOpen($creatorId, Carbon::parse((string) $operation->posting_date));

            $created = [];

            foreach ($allocations as $row) {
                $created[] = $this->allocateOne($receipt, $operation, $row, $actorId, $approvedBy, $creatorId);
            }

            return $created;
        });
    }

    /**
     * Apply a customer's unapplied credit to an invoice. No new cash
     * inflow is created: no operation, no entry, no bank movement —
     * only the credit-liability -> AR allocation journal and the
     * invoice settlement. Allocations are taken oldest receipt first
     * (FIFO).
     *
     * @return TreasurySettlementAllocation[]
     */
    public function applyUnappliedCredit(int $creatorId, int $customerId, int $invoiceId, string $amount, string $currency, int $actorId, ?int $approvedBy = null): array
    {
        $customer = $this->customerForCompany($creatorId, $customerId);
        $amount = $this->decimalString($amount, 'Amount');

        if (bccomp($amount, '0', 4) <= 0) {
            throw new TreasuryException('The amount must be greater than zero.');
        }

        $currency = strtoupper(trim($currency));
        $baseCurrency = TreasurySettings::baseCurrency($creatorId);
        $isCrossCurrency = $currency !== $baseCurrency;

        if ($isCrossCurrency && $approvedBy === null) {
            throw new TreasuryException('Applying foreign-currency credit needs an approver. Add an approver (a different user) or use base-currency credit.');
        }
        if ($approvedBy !== null && $approvedBy === $actorId) {
            throw new TreasuryException('The approver cannot be the same user who applies the credit.');
        }

        $available = $this->unappliedCredit($creatorId, $customerId, $currency);
        if (bccomp($amount, $available, 4) > 0) {
            throw new TreasuryException('The customer has only ' . $available . ' ' . $currency . ' of unapplied credit.');
        }

        return DB::transaction(function () use ($creatorId, $customer, $invoiceId, $amount, $currency, $actorId, $approvedBy) {
            $invoice = Invoice::where('created_by', $creatorId)->lockForUpdate()->find($invoiceId);
            if (! $invoice) {
                throw new TreasuryException('Invoice not found for this company.');
            }
            $this->assertPeriodOpen($creatorId, Carbon::parse((string) $invoice->issue_date));

            $remaining = $amount;
            $created = [];

            $receipts = TreasuryReceipt::where('created_by', $creatorId)
                ->where('customer_id', $customer->id)
                ->where('state', TreasuryReceipt::STATE_CONFIRMED)
                ->whereHas('operation', fn ($q) => $q->where('currency', $currency))
                ->orderBy('confirmed_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($receipts as $receipt) {
                if (bccomp($remaining, '0', 4) <= 0) {
                    break;
                }

                $left = $this->remainingUnapplied($receipt);
                if (bccomp($left, '0', 4) <= 0) {
                    continue;
                }

                $take = bccomp($left, $remaining, 4) >= 0 ? $remaining : $left;
                $operation = $receipt->operation;

                $created[] = $this->allocateOne(
                    $receipt,
                    $operation,
                    [
                        'invoice_id' => $invoice->id,
                        'amount' => $take,
                        'exchange_rate' => $operation->exchange_rate,
                        'rate_source' => $operation->rate_source,
                        'rate_date' => $operation->rate_date ? $operation->rate_date->format('Y-m-d') : null,
                        'source' => TreasurySettlementAllocation::SOURCE_UNAPPLIED_CREDIT,
                    ],
                    $actorId,
                    $approvedBy,
                    $creatorId
                );

                $remaining = bcsub($remaining, $take, 4);
            }

            if (bccomp($remaining, '0', 4) > 0) {
                throw new TreasuryException('Unapplied credit changed while applying it. Try again.');
            }

            return $created;
        });
    }

    /**
     * Unapplied credit for a customer in one currency: confirmed
     * receipts' settlement value minus their active allocations.
     */
    public function unappliedCredit(int $creatorId, int $customerId, string $currency): string
    {
        $currency = strtoupper(trim($currency));

        $receipts = TreasuryReceipt::where('created_by', $creatorId)
            ->where('customer_id', $customerId)
            ->where('state', TreasuryReceipt::STATE_CONFIRMED)
            ->whereHas('operation', fn ($q) => $q->where('currency', $currency))
            ->with('operation')
            ->get();

        $total = '0.0000';
        foreach ($receipts as $receipt) {
            $total = bcadd($total, $this->remainingUnapplied($receipt), 4);
        }

        return $total;
    }

    /** Remaining unapplied credit on one confirmed receipt (receipt currency). */
    public function remainingUnapplied(TreasuryReceipt $receipt): string
    {
        $allocated = (string) TreasurySettlementAllocation::where('receipt_id', $receipt->id)
            ->where('state', TreasurySettlementAllocation::STATE_ACTIVE)
            ->sum('allocated_amount');

        return bcsub($receipt->settlementValue(), $allocated, 4);
    }

    /** Remaining unapplied credit on one confirmed receipt (base currency). */
    public function remainingUnappliedBase(TreasuryReceipt $receipt): string
    {
        $allocated = (string) TreasurySettlementAllocation::where('receipt_id', $receipt->id)
            ->where('state', TreasurySettlementAllocation::STATE_ACTIVE)
            ->sum('allocated_amount_base');

        return bcsub($receipt->settlementValueBase(), $allocated, 4);
    }

    // ------------------------------------------------------------------
    // Single allocation (inside the caller's transaction)
    // ------------------------------------------------------------------

    protected function allocateOne(TreasuryReceipt $receipt, TreasuryOperation $operation, array $row, int $actorId, ?int $approvedBy, int $creatorId): TreasurySettlementAllocation
    {
        $invoiceId = (int) ($row['invoice_id'] ?? 0);
        $amount = $this->decimalString($row['amount'] ?? null, 'Allocation amount');

        if ($invoiceId <= 0) {
            throw new TreasuryException('An allocation needs an invoice.');
        }
        if (bccomp($amount, '0', 4) <= 0) {
            throw new TreasuryException('An allocation amount must be greater than zero.');
        }

        // Lock the invoice row: concurrent settlements recheck the
        // balance and cannot over-allocate (CA02).
        $invoice = Invoice::where('created_by', $creatorId)->lockForUpdate()->find($invoiceId);
        if (! $invoice) {
            throw new TreasuryException('Invoice not found for this company.');
        }
        if ((int) $invoice->status === Invoice::STATUS_CANCELLED) {
            throw new TreasuryException('A cancelled invoice cannot be settled.');
        }
        if ((int) $invoice->customer_id !== (int) $receipt->customer_id && $receipt->customer_id !== null) {
            throw new TreasuryException('The invoice belongs to a different customer than the receipt.');
        }

        $baseCurrency = TreasurySettings::baseCurrency($creatorId);
        $receiptCurrency = (string) $operation->currency;
        $isCrossCurrency = $receiptCurrency !== $baseCurrency;

        if ($isCrossCurrency) {
            if ($approvedBy === null) {
                throw new TreasuryException('Allocating a foreign-currency receipt needs an approver. Add an approver (a different user) or confirm the receipt in the base currency.');
            }
            if ($approvedBy === $actorId) {
                throw new TreasuryException('The approver cannot be the same user who allocates.');
            }
        }

        [$rate, $rateSource, $rateDate, $amountBase] = $this->allocationRate($creatorId, $operation, $row, $amount);

        // Never exceed the invoice's outstanding debt (rechecked
        // under lock, after any allocation in this same call).
        $due = $this->invoiceDue($invoice);
        if (bccomp($amountBase, $due, 4) > 0) {
            throw new TreasuryException('The allocation (' . $amountBase . ' ' . $baseCurrency . ') exceeds the invoice outstanding amount (' . $due . ' ' . $baseCurrency . ').');
        }

        // Never exceed the receipt's remaining unapplied credit.
        $remaining = $this->remainingUnapplied($receipt);
        if (bccomp($amount, $remaining, 4) > 0) {
            throw new TreasuryException('The allocation exceeds the receipt unapplied credit (' . $remaining . ' ' . $receiptCurrency . ').');
        }

        $arAccountId = $this->resolveReceivableAccount($creatorId);
        $creditAccountId = $this->resolveCustomerCreditAccount($creatorId);

        $journal = $this->poster->post(
            $creatorId,
            (string) $operation->posting_date,
            (string) $operation->reference . ' / INV-' . $invoice->invoice_id,
            'Treasury receipt ' . $operation->reference . ' allocated to invoice ' . $invoice->invoice_id,
            [
                ['account' => $creditAccountId, 'debit' => (float) $amountBase, 'description' => 'Customer credit applied'],
                ['account' => $arAccountId, 'credit' => (float) $amountBase, 'description' => 'Invoice ' . $invoice->invoice_id . ' settled'],
            ]
        );

        $allocation = TreasurySettlementAllocation::create([
            'receipt_id' => $receipt->id,
            'payment_id' => null,
            'target_type' => TreasurySettlementAllocation::TARGET_INVOICE,
            'target_id' => $invoice->id,
            'allocated_amount' => $amount,
            'allocated_amount_base' => $amountBase,
            'currency' => $receiptCurrency,
            'exchange_rate' => $rate,
            'rate_source' => $rateSource,
            'rate_date' => $rateDate,
            'state' => TreasurySettlementAllocation::STATE_ACTIVE,
            'source' => $row['source'] ?? TreasurySettlementAllocation::SOURCE_RECEIPT,
            'approved_by' => $isCrossCurrency ? $approvedBy : null,
            'journal_id' => $journal->id,
            'created_by' => $creatorId,
        ]);

        // Settle the invoice the way the app already does: an
        // InvoicePayment row (so getDue() moves), status update,
        // customer balance and Transaction row.
        $invoicePayment = $this->settleInvoice($invoice, $receipt, $operation, $amountBase, $actorId);
        $allocation->invoice_payment_id = $invoicePayment->id;
        $allocation->save();

        $this->audit($creatorId, self::AUDIT_ALLOCATION_ENTITY, $allocation->id, 'created', null, $allocation->fresh()->toArray(), $actorId);

        return $allocation->fresh();
    }

    /**
     * Reverse one active allocation (inside the caller's transaction):
     * mirror its journal, remove its InvoicePayment + Transaction,
     * restore the customer balance, reopen the invoice.
     */
    protected function reverseAllocation(TreasurySettlementAllocation $allocation, int $actorId): void
    {
        $creatorId = (int) $allocation->created_by;

        if ($allocation->journal_id) {
            $this->postMirrorJournal(
                $creatorId,
                (int) $allocation->journal_id,
                'REV-ALLOC-' . $allocation->id,
                'Reversal of allocation ' . $allocation->id . ' (receipt reversed)'
            );
        }

        if ($allocation->invoice_payment_id) {
            $payment = InvoicePayment::find($allocation->invoice_payment_id);
            if ($payment) {
                Transaction::destroyTransaction((int) $payment->id, 'Partial', 'Customer');
                $payment->delete();
            }
        }

        $invoice = Invoice::find($allocation->target_id);
        if ($invoice) {
            // Restore the customer balance (the reverse of the credit
            // applied when the invoice was settled).
            if ($invoice->customer_id) {
                Utility::updateUserBalance('customer', (int) $invoice->customer_id, (string) $allocation->allocated_amount_base, 'debit');
            }
            $this->refreshInvoiceStatus($invoice);
        }

        $before = $allocation->fresh()->toArray();
        $allocation->state = TreasurySettlementAllocation::STATE_REVERSED;
        $allocation->save();

        $this->audit($creatorId, self::AUDIT_ALLOCATION_ENTITY, $allocation->id, 'reversed', $before, $allocation->fresh()->toArray(), $actorId);
    }

    // ------------------------------------------------------------------
    // Invoice settlement (mirrors InvoiceController::createPayment)
    // ------------------------------------------------------------------

    protected function settleInvoice(Invoice $invoice, TreasuryReceipt $receipt, TreasuryOperation $operation, string $amountBase, int $actorId): InvoicePayment
    {
        $creatorId = (int) $invoice->created_by;
        $account = $this->accounts->accountForCompany($creatorId, (int) $operation->financial_account_id);

        $invoicePayment = new InvoicePayment();
        $invoicePayment->invoice_id = $invoice->id;
        $invoicePayment->date = (string) $operation->posting_date;
        $invoicePayment->amount = $amountBase;
        $invoicePayment->account_id = $account->bank_account_id;
        $invoicePayment->payment_method = 0;
        $invoicePayment->reference = (string) $operation->reference;
        $invoicePayment->description = 'Treasury receipt ' . ($receipt->receipt_number ?? $operation->reference);
        $invoicePayment->save();

        $invoice = Invoice::where('id', $invoice->id)->first();
        $due = $this->invoiceDue($invoice);
        if ((int) $invoice->status === Invoice::STATUS_DRAFT) {
            $invoice->send_date = date('Y-m-d');
        }
        if (bccomp($due, '0', 4) <= 0) {
            $invoice->status = Invoice::STATUS_PAID;
        } else {
            $invoice->status = Invoice::STATUS_PARTIALLY_PAID;
        }
        $invoice->save();

        $invoicePayment->user_id = $invoice->customer_id;
        $invoicePayment->user_type = 'Customer';
        $invoicePayment->type = 'Partial';
        $invoicePayment->created_by = $actorId;
        $invoicePayment->payment_id = $invoicePayment->id;
        $invoicePayment->category = 'Invoice';
        $invoicePayment->account = $account->bank_account_id;

        Transaction::addTransaction($invoicePayment);

        if ($invoice->customer_id) {
            Utility::updateUserBalance('customer', (int) $invoice->customer_id, $amountBase, 'credit');
        }

        return $invoicePayment;
    }

    /** Recompute an invoice's status from its outstanding amount (mirrors paymentDestroy). */
    protected function refreshInvoiceStatus(Invoice $invoice): void
    {
        $due = $this->invoiceDue($invoice);
        $total = number_format((float) $invoice->getTotal(), 4, '.', '');

        if (bccomp($due, '0', 4) <= 0) {
            $invoice->status = Invoice::STATUS_PAID;
        } elseif (bccomp($due, $total, 4) < 0) {
            $invoice->status = Invoice::STATUS_PARTIALLY_PAID;
        } else {
            $invoice->status = Invoice::STATUS_UNPAID;
        }
        $invoice->save();
    }

    /** Outstanding amount on an invoice, as a 4dp string (getDue() is float math). */
    public function invoiceDue(Invoice $invoice): string
    {
        return number_format((float) $invoice->getDue(), 4, '.', '');
    }

    // ------------------------------------------------------------------
    // Journals
    // ------------------------------------------------------------------

    /**
     * Confirm journal lines (base currency). Balanced by construction:
     * Dr = (gross - fees - withholding) + fees + withholding + discount
     *    = gross + discount = Cr (settlement value).
     *
     * @return array<int, array{account: int, debit?: float, credit?: float, description?: string}>
     */
    protected function confirmJournalLines(int $creatorId, TreasuryFinancialAccount $account, TreasuryOperation $operation, TreasuryReceipt $receipt, int $bankChartId, ?int $creditAccountId, string $amountBase): array
    {
        $rate = (string) ($operation->exchange_rate ?? '1');
        $gross = (string) $operation->amount_original;
        $fees = (string) $receipt->fees;
        $withholding = (string) $receipt->withholding;
        $discount = (string) $receipt->discount;

        $toBase = fn (string $value): string => bcmul($value, $rate, 4);

        $netCash = bcsub(bcsub($gross, $fees, 4), $withholding, 4);
        $lines = [
            [
                'account' => $bankChartId,
                'debit' => (float) $toBase($netCash),
                'description' => 'Treasury receipt ' . $operation->reference . ' — bank credit',
            ],
        ];

        if (bccomp($fees, '0', 4) > 0) {
            $lines[] = [
                'account' => $this->resolveFeeAccount($creatorId),
                'debit' => (float) $toBase($fees),
                'description' => 'Bank fee on receipt ' . $operation->reference,
            ];
        }
        if (bccomp($withholding, '0', 4) > 0) {
            $lines[] = [
                'account' => $this->resolveWithholdingAccount($creatorId),
                'debit' => (float) $toBase($withholding),
                'description' => 'Withholding on receipt ' . $operation->reference,
            ];
        }
        if (bccomp($discount, '0', 4) > 0) {
            $lines[] = [
                'account' => $this->resolveDiscountAccount($creatorId),
                'debit' => (float) $toBase($discount),
                'description' => 'Discount granted on receipt ' . $operation->reference,
            ];
        }

        $settlementBase = $this->settlementValueBase($operation, $receipt);
        if (bccomp($settlementBase, '0', 4) > 0) {
            if ($creditAccountId === null) {
                throw new TreasuryException('No customer-credit account is configured. Set the customer credit account in Treasury settings.');
            }
            $lines[] = [
                'account' => $creditAccountId,
                'credit' => (float) $settlementBase,
                'description' => 'Unapplied customer credit — receipt ' . $operation->reference,
            ];
        }

        return $lines;
    }

    /**
     * Post the equal and opposite of an existing journal (reversal).
     * Reads the original JournalItems and swaps debit/credit.
     */
    protected function postMirrorJournal(int $creatorId, int $journalId, string $reference, string $description): JournalEntry
    {
        $original = JournalEntry::find($journalId);

        if (! $original) {
            throw new TreasuryException('Original journal ' . $journalId . ' not found for reversal.');
        }

        $lines = [];
        foreach (JournalItem::where('journal', $original->id)->orderBy('id')->get() as $item) {
            $lines[] = [
                'account' => (int) $item->account,
                'debit' => (float) $item->credit,
                'credit' => (float) $item->debit,
                'description' => $item->description,
            ];
        }

        return $this->poster->post($creatorId, date('Y-m-d'), $reference, $description, $lines);
    }

    // ------------------------------------------------------------------
    // Rates, references, accounts
    // ------------------------------------------------------------------

    /**
     * Rate for one allocation. Same currency as the receipt: the
     * receipt's own rate (historical, CA19). Foreign receipt: the
     * allocation must carry its own rate + source + date.
     *
     * @return array{0: string, 1: string|null, 2: string|null, 3: string} rate, source, date, base amount
     */
    protected function allocationRate(int $creatorId, TreasuryOperation $operation, array $row, string $amount): array
    {
        $baseCurrency = TreasurySettings::baseCurrency($creatorId);
        $receiptCurrency = (string) $operation->currency;

        if ($receiptCurrency === $baseCurrency) {
            return ['1.0000000000', null, null, $this->toBase($amount, '1')];
        }

        $rate = trim((string) ($row['exchange_rate'] ?? $operation->exchange_rate ?? ''));
        $source = trim((string) ($row['rate_source'] ?? $operation->rate_source ?? ''));
        $date = trim((string) ($row['rate_date'] ?? ($operation->rate_date ? $operation->rate_date->format('Y-m-d') : '')));

        if ($rate === '' || ! is_numeric($rate) || (float) $rate <= 0 || $source === '' || $date === '') {
            throw new TreasuryException('No exchange rate for ' . $receiptCurrency . ' on this date. Enter a rate and its source — a rate is never guessed.');
        }

        if (! in_array($source, TreasuryOpeningBalance::RATE_SOURCES, true)) {
            throw new TreasuryException('Unknown exchange-rate source (bank rate, central bank rate or manual entry).');
        }

        $rate = number_format((float) $rate, 10, '.', '');

        return [$rate, $source, $date, $this->toBase($amount, $rate)];
    }

    /** Settlement value of a receipt in the base currency (gross + discount) x rate. */
    protected function settlementValueBase(TreasuryOperation $operation, TreasuryReceipt $receipt): string
    {
        $rate = (string) ($operation->exchange_rate ?? '1');
        $settlement = bcadd((string) $operation->amount_original, (string) $receipt->discount, 4);

        return bcmul($settlement, $rate, 4);
    }

    /**
     * Next reference for a company + type + year. Allocated only when
     * the operation leaves Draft. The caller holds the transaction
     * (and the operation row lock), so two concurrent allocations of
     * the same number cannot happen.
     */
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

    protected function resolveReceivableAccount(int $creatorId): int
    {
        return $this->resolveAccountOrFail($creatorId, null, '411', 'accounts receivable (411)');
    }

    protected function resolveCustomerCreditAccount(int $creatorId): int
    {
        return $this->resolveAccountOrFail(
            $creatorId,
            TreasurySettings::get($creatorId, TreasurySettings::KEY_CUSTOMER_CREDIT_ACCOUNT_CODE),
            '211',
            'customer credit (211)'
        );
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

    protected function resolveWithholdingAccount(int $creatorId): int
    {
        return $this->resolveAccountOrFail(
            $creatorId,
            TreasurySettings::get($creatorId, TreasurySettings::KEY_WITHHOLDING_ACCOUNT_CODE),
            '24',
            'withholding tax (24)'
        );
    }

    protected function resolveDiscountAccount(int $creatorId): int
    {
        return $this->resolveAccountOrFail(
            $creatorId,
            TreasurySettings::get($creatorId, TreasurySettings::KEY_DISCOUNT_ACCOUNT_CODE),
            '61',
            'discounts granted (61)'
        );
    }

    /**
     * Closed fiscal period (CA17): an FY-{year}-CLOSE journal exists
     * for the posting year. Same rule the Cost Centre module uses.
     */
    public function assertPeriodOpen(int $creatorId, Carbon $date): void
    {
        if (! Schema::hasTable('journal_entries')) {
            return;
        }

        $closed = DB::table('journal_entries')
            ->where('created_by', $creatorId)
            ->where('reference', 'like', 'FY-' . $date->year . '-CLOSE%')
            ->exists();

        if ($closed) {
            throw new TreasuryException(
                __('Fiscal year :year is closed. Treasury postings into a closed period are blocked.', ['year' => $date->year])
            );
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public function receiptForCompany(int $creatorId, int $receiptId): TreasuryReceipt
    {
        $receipt = TreasuryReceipt::where('created_by', $creatorId)->find($receiptId);

        if (! $receipt) {
            throw new TreasuryException('Receipt not found for this company.');
        }

        return $receipt;
    }

    protected function customerForCompany(int $creatorId, int $customerId): Customer
    {
        $customer = Customer::where('created_by', $creatorId)->find($customerId);

        if (! $customer) {
            throw new TreasuryException('Customer not found for this company.');
        }

        return $customer;
    }

    protected function decimalString(mixed $value, string $label): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw new TreasuryException($label . ' must be a number.');
        }

        return number_format((float) $value, 4, '.', '');
    }

    protected function dateString(mixed $value, string $label): string
    {
        $value = trim((string) $value);
        if ($value === '' || strtotime($value) === false) {
            throw new TreasuryException('A valid ' . strtolower($label) . ' is required.');
        }

        return date('Y-m-d', strtotime($value));
    }

    protected function nullableString(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    protected function toBase(string $amount, ?string $rate): string
    {
        if ($rate === null || $rate === '') {
            $rate = '1';
        }

        return bcmul($amount, $rate, 4);
    }

    protected function negate(string $amount): string
    {
        return bcmul($amount, '-1', 4);
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
