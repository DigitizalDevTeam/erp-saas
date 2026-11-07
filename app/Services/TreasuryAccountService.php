<?php

namespace App\Services;

use App\Exceptions\TreasuryException;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\DimensionAuditEvent;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryOpeningBalance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 0 — accounts foundation (plan §5).
 *
 * Rules enforced here (service layer, not only the UI):
 * - RF03: confirmed rows are immutable — a confirmed opening balance
 *   is corrected only by an authorised reversal, never by editing.
 * - RF04: money is DECIMAL(20,4), rates DECIMAL(20,10); all math is
 *   bcmath, never float, so splits sum exactly.
 * - RF06: every action is scoped to the company (creatorId) on the
 *   server; a cross-company id is refused, never trusted.
 * - D2: original amount + base amount + rate + rate source + rate date
 *   are stored on every opening balance. If the rate for a date is
 *   missing, confirmation is refused — never guess a rate.
 * - D3: negative-balance policy is block by default; allow only on a
 *   named account with a named limit and an approver.
 *
 * Balances stay computed, never stored as a single number:
 * posted = confirmed opening balances (+ confirmed inflows −
 * confirmed outflows, once later phases add operations);
 * available = posted − active reservations (none in Phase 0).
 */
class TreasuryAccountService
{
    public const AUDIT_ENTITY = 'treasury_financial_account';
    public const AUDIT_BALANCE_ENTITY = 'treasury_opening_balance';

    // ------------------------------------------------------------------
    // Accounts
    // ------------------------------------------------------------------

    public function createAccount(int $creatorId, array $data, int $actorId): TreasuryFinancialAccount
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $type = (string) ($data['type'] ?? TreasuryFinancialAccount::TYPE_BANK);
        $currency = strtoupper(trim((string) ($data['currency'] ?? TreasurySettings::baseCurrency($creatorId))));

        if ($code === '' || mb_strlen($code) > 50) {
            throw new TreasuryException('Account code is required (max 50 characters).');
        }

        if (! in_array($type, TreasuryFinancialAccount::TYPES, true)) {
            throw new TreasuryException('Unknown account type.');
        }

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new TreasuryException('Currency must be a 3-letter code.');
        }

        if (TreasuryFinancialAccount::where('created_by', $creatorId)->where('code', $code)->exists()) {
            throw new TreasuryException('An account with this code already exists for the company.');
        }

        $policy = (string) ($data['negative_balance_policy'] ?? TreasurySettings::get($creatorId, TreasurySettings::KEY_NEGATIVE_BALANCE_POLICY) ?? 'block');
        if (! in_array($policy, TreasuryFinancialAccount::NEGATIVE_BALANCE_POLICIES, true)) {
            throw new TreasuryException('Unknown negative-balance policy.');
        }

        $branchId = $this->resolveBranchId($creatorId, $data['branch_id'] ?? null);
        $ownerId = $this->resolveCompanyUserId($creatorId, $data['owner_user_id'] ?? null);
        $approverId = $this->resolveCompanyUserId($creatorId, $data['negative_balance_approver_user_id'] ?? null);
        $chartAccountId = (int) ($data['chart_account_id'] ?? 0);

        return DB::transaction(function () use ($creatorId, $data, $actorId, $code, $type, $currency, $policy, $branchId, $ownerId, $approverId, $chartAccountId) {
            // The bank account row is the shared master: holder name,
            // bank/institution name, account number, chart account,
            // contact and address live there and are reused.
            $bank = BankAccount::create([
                'holder_name' => trim((string) ($data['account_holder'] ?? '')),
                'bank_name' => trim((string) ($data['institution'] ?? '')),
                'account_number' => trim((string) ($data['account_number'] ?? '')),
                'chart_account_id' => $chartAccountId,
                'opening_balance' => 0,
                'contact_number' => trim((string) ($data['contact_number'] ?? '')) ?: '-',
                'bank_address' => trim((string) ($data['bank_address'] ?? '')) ?: '-',
                'created_by' => $creatorId,
            ]);

            $account = TreasuryFinancialAccount::create([
                'bank_account_id' => $bank->id,
                'created_by' => $creatorId,
                'code' => $code,
                'type' => $type,
                'currency' => $currency,
                'currency_locked' => false,
                'branch_id' => $branchId,
                'nib_or_iban' => $this->nullableString($data['nib_or_iban'] ?? null, 50),
                'owner_user_id' => $ownerId,
                'cash_limit' => $this->nullableDecimal($data['cash_limit'] ?? null),
                'minimum_balance' => $this->nullableDecimal($data['minimum_balance'] ?? null),
                'negative_balance_policy' => $policy,
                'negative_balance_limit' => $this->nullableDecimal($data['negative_balance_limit'] ?? null),
                'negative_balance_approver_user_id' => $approverId,
                'status' => TreasuryFinancialAccount::STATUS_ACTIVE,
            ]);

            $this->audit($creatorId, self::AUDIT_ENTITY, $account->id, 'created', null, $account->fresh()->toArray(), $actorId);

            return $account;
        });
    }

    public function updateAccount(TreasuryFinancialAccount $account, array $data, int $actorId): TreasuryFinancialAccount
    {
        $creatorId = (int) $account->created_by;
        $before = $account->fresh()->toArray();

        $bank = $account->bankAccount;

        return DB::transaction(function () use ($account, $data, $actorId, $creatorId, $before, $bank) {
            // Currency is locked after the first confirmed movement
            // (Phase 0: the first approved opening balance).
            if (isset($data['currency'])) {
                $newCurrency = strtoupper(trim((string) $data['currency']));
                if ($newCurrency !== $account->currency) {
                    if ($this->isCurrencyLocked($account)) {
                        throw new TreasuryException('Currency is locked: the account already has a confirmed movement.');
                    }
                    if (! preg_match('/^[A-Z]{3}$/', $newCurrency)) {
                        throw new TreasuryException('Currency must be a 3-letter code.');
                    }
                    $account->currency = $newCurrency;
                }
            }

            if (isset($data['code'])) {
                $code = strtoupper(trim((string) $data['code']));
                if ($code === '' || mb_strlen($code) > 50) {
                    throw new TreasuryException('Account code is required (max 50 characters).');
                }
                if ($code !== $account->code
                    && TreasuryFinancialAccount::where('created_by', $creatorId)->where('code', $code)->exists()
                ) {
                    throw new TreasuryException('An account with this code already exists for the company.');
                }
                $account->code = $code;
            }

            if (isset($data['type'])) {
                if (! in_array($data['type'], TreasuryFinancialAccount::TYPES, true)) {
                    throw new TreasuryException('Unknown account type.');
                }
                $account->type = $data['type'];
            }

            if (array_key_exists('branch_id', $data)) {
                $account->branch_id = $this->resolveBranchId($creatorId, $data['branch_id']);
            }
            if (array_key_exists('nib_or_iban', $data)) {
                $account->nib_or_iban = $this->nullableString($data['nib_or_iban'], 50);
            }
            if (array_key_exists('owner_user_id', $data)) {
                $account->owner_user_id = $this->resolveCompanyUserId($creatorId, $data['owner_user_id']);
            }
            if (array_key_exists('cash_limit', $data)) {
                $account->cash_limit = $this->nullableDecimal($data['cash_limit']);
            }
            if (array_key_exists('minimum_balance', $data)) {
                $account->minimum_balance = $this->nullableDecimal($data['minimum_balance']);
            }
            if (isset($data['negative_balance_policy'])) {
                if (! in_array($data['negative_balance_policy'], TreasuryFinancialAccount::NEGATIVE_BALANCE_POLICIES, true)) {
                    throw new TreasuryException('Unknown negative-balance policy.');
                }
                $account->negative_balance_policy = $data['negative_balance_policy'];
            }
            if (array_key_exists('negative_balance_limit', $data)) {
                $account->negative_balance_limit = $this->nullableDecimal($data['negative_balance_limit']);
            }
            if (array_key_exists('negative_balance_approver_user_id', $data)) {
                $account->negative_balance_approver_user_id = $this->resolveCompanyUserId($creatorId, $data['negative_balance_approver_user_id']);
            }

            $account->save();

            // Holder / financial identifiers live on the bank account
            // row. Changing them needs authorisation (controller gate)
            // and an audit row — recorded below with before/after.
            if ($bank) {
                $identifierChanged = false;
                foreach ([
                    'holder_name' => 'account_holder',
                    'bank_name' => 'institution',
                    'account_number' => 'account_number',
                    'contact_number' => 'contact_number',
                    'bank_address' => 'bank_address',
                ] as $column => $key) {
                    if (array_key_exists($key, $data)) {
                        $value = trim((string) $data[$key]);
                        if ($value !== '' && $value !== (string) $bank->{$column}) {
                            $bank->{$column} = $value;
                            $identifierChanged = true;
                        }
                    }
                }
                if (array_key_exists('chart_account_id', $data)) {
                    $bank->chart_account_id = (int) $data['chart_account_id'];
                }
                if ($identifierChanged || $bank->isDirty()) {
                    $bank->save();
                }
            }

            $this->audit($creatorId, self::AUDIT_ENTITY, $account->id, 'updated', $before, $account->fresh()->toArray(), $actorId);

            return $account->fresh();
        });
    }

    /**
     * Deactivate: blocks new activity, keeps reporting. Draft opening
     * balances must be resolved first (approved or deleted); approved
     * balances are history and do not block. Reservations and pending
     * transactions (Phase 2+) must also be resolved — that check is
     * added by the phase that creates them.
     */
    public function deactivateAccount(TreasuryFinancialAccount $account, int $actorId): TreasuryFinancialAccount
    {
        if (! $account->isActive()) {
            return $account;
        }

        $drafts = TreasuryOpeningBalance::where('financial_account_id', $account->id)
            ->where('status', TreasuryOpeningBalance::STATUS_DRAFT)
            ->count();

        if ($drafts > 0) {
            throw new TreasuryException('Resolve the draft opening balances first (approve or delete them) before deactivating the account.');
        }

        // Phase 2: active reservations and open payment requests must
        // be resolved first — an approved request holds a reservation
        // on this account, and a pending request may still need it.
        if (Schema::hasTable('treasury_fund_reservations')) {
            $activeReservations = DB::table('treasury_fund_reservations')
                ->where('financial_account_id', $account->id)
                ->where('state', 'active')
                ->count();

            if ($activeReservations > 0) {
                throw new TreasuryException('Resolve the active fund reservations first (consume or release them) before deactivating the account.');
            }
        }

        if (Schema::hasTable('treasury_payment_requests')) {
            $openRequests = DB::table('treasury_payment_requests')
                ->where('source_account_id', $account->id)
                ->whereIn('state', ['under_review', 'awaiting_approval', 'approved', 'processing', 'outcome_unknown'])
                ->count();

            if ($openRequests > 0) {
                throw new TreasuryException('Resolve the open payment requests first (approve, reject or cancel them) before deactivating the account.');
            }
        }

        $before = $account->fresh()->toArray();
        $account->status = TreasuryFinancialAccount::STATUS_INACTIVE;
        $account->save();

        $this->audit((int) $account->created_by, self::AUDIT_ENTITY, $account->id, 'deactivated', $before, $account->fresh()->toArray(), $actorId);

        return $account->fresh();
    }

    public function reactivateAccount(TreasuryFinancialAccount $account, int $actorId): TreasuryFinancialAccount
    {
        if ($account->isActive()) {
            return $account;
        }

        $before = $account->fresh()->toArray();
        $account->status = TreasuryFinancialAccount::STATUS_ACTIVE;
        $account->save();

        $this->audit((int) $account->created_by, self::AUDIT_ENTITY, $account->id, 'reactivated', $before, $account->fresh()->toArray(), $actorId);

        return $account->fresh();
    }

    /** Currency is locked once the account has a confirmed movement. */
    public function isCurrencyLocked(TreasuryFinancialAccount $account): bool
    {
        if ($account->currency_locked) {
            return true;
        }

        return TreasuryOpeningBalance::where('financial_account_id', $account->id)
            ->confirmed()
            ->exists();
    }

    // ------------------------------------------------------------------
    // Opening balances
    // ------------------------------------------------------------------

    public function createOpeningBalance(int $creatorId, int $accountId, array $data, int $operatorId): TreasuryOpeningBalance
    {
        $account = $this->accountForCompany($creatorId, $accountId);

        if (! $account->isActive()) {
            throw new TreasuryException('Opening balances cannot be added to an inactive account.');
        }

        $currency = strtoupper(trim((string) ($data['currency'] ?? $account->currency)));
        if ($currency !== $account->currency) {
            throw new TreasuryException('The opening balance currency must match the account currency (' . $account->currency . ').');
        }

        $amount = $this->decimalString($data['amount'] ?? null, 'Amount');
        $cutOff = trim((string) ($data['cut_off_date'] ?? ''));
        if ($cutOff === '' || strtotime($cutOff) === false) {
            throw new TreasuryException('A valid cut-off date is required.');
        }

        $batchReference = trim((string) ($data['batch_reference'] ?? ''));
        if ($batchReference === '') {
            $batchReference = $this->generateBatchReference($creatorId);
        }
        $this->assertBatchReferenceFree($creatorId, $batchReference);

        [$rate, $rateSource, $rateDate, $amountBase] = $this->convertToBase($creatorId, $currency, $amount, $data);

        return DB::transaction(function () use ($creatorId, $account, $currency, $amount, $amountBase, $rate, $rateSource, $rateDate, $cutOff, $batchReference, $operatorId, $data) {
            $balance = TreasuryOpeningBalance::create([
                'financial_account_id' => $account->id,
                'created_by' => $creatorId,
                'currency' => $currency,
                'amount' => $amount,
                'amount_base' => $amountBase,
                'exchange_rate' => $rate,
                'rate_source' => $rateSource,
                'rate_date' => $rateDate,
                'cut_off_date' => $cutOff,
                'batch_reference' => $batchReference,
                'operator_user_id' => $operatorId,
                'attachments' => $this->nullableString($data['attachments'] ?? null, 1000),
                'status' => TreasuryOpeningBalance::STATUS_DRAFT,
                'includes_liabilities' => ! empty($data['includes_liabilities']),
                'includes_receivables' => ! empty($data['includes_receivables']),
            ]);

            $this->audit($creatorId, self::AUDIT_BALANCE_ENTITY, $balance->id, 'created', null, $balance->fresh()->toArray(), $operatorId);

            return $balance;
        });
    }

    /** Drafts only. A confirmed row is never edited (RF03). */
    public function updateOpeningBalance(TreasuryOpeningBalance $balance, array $data): TreasuryOpeningBalance
    {
        if (! $balance->isDraft()) {
            throw new TreasuryException('A confirmed opening balance cannot be edited. Reverse it and create a corrected one instead.');
        }

        $creatorId = (int) $balance->created_by;
        $account = $this->accountForCompany($creatorId, (int) $balance->financial_account_id);

        $before = $balance->fresh()->toArray();
        $changed = false;

        if (array_key_exists('amount', $data)) {
            $balance->amount = $this->decimalString($data['amount'], 'Amount');
            $changed = true;
        }
        if (array_key_exists('cut_off_date', $data)) {
            $cutOff = trim((string) $data['cut_off_date']);
            if ($cutOff === '' || strtotime($cutOff) === false) {
                throw new TreasuryException('A valid cut-off date is required.');
            }
            $balance->cut_off_date = $cutOff;
            $changed = true;
        }
        if (array_key_exists('exchange_rate', $data) || array_key_exists('rate_source', $data) || array_key_exists('rate_date', $data)) {
            $merged = array_merge($balance->toArray(), $data);
            [$rate, $rateSource, $rateDate] = $this->resolveRate($creatorId, $balance->currency, $merged, true);
            $balance->exchange_rate = $rate;
            $balance->rate_source = $rateSource;
            $balance->rate_date = $rateDate;
            $changed = true;
        }
        if (array_key_exists('includes_liabilities', $data)) {
            $balance->includes_liabilities = ! empty($data['includes_liabilities']);
            $changed = true;
        }
        if (array_key_exists('includes_receivables', $data)) {
            $balance->includes_receivables = ! empty($data['includes_receivables']);
            $changed = true;
        }
        if (array_key_exists('attachments', $data)) {
            $balance->attachments = $this->nullableString($data['attachments'], 1000);
            $changed = true;
        }

        if ($changed) {
            // Recompute the base amount from the (possibly new) rate.
            $balance->amount_base = $this->toBase($balance->amount, $balance->exchange_rate);
            $balance->save();
            $this->audit($creatorId, self::AUDIT_BALANCE_ENTITY, $balance->id, 'updated', $before, $balance->fresh()->toArray(), $balance->operator_user_id ? (int) $balance->operator_user_id : null);
        }

        return $balance->fresh();
    }

    /** Drafts only — deleting a draft is not touching confirmed history. */
    public function deleteOpeningBalance(TreasuryOpeningBalance $balance): void
    {
        if (! $balance->isDraft()) {
            throw new TreasuryException('Only a draft opening balance can be deleted.');
        }

        $creatorId = (int) $balance->created_by;
        $before = $balance->fresh()->toArray();
        $operatorId = $balance->operator_user_id ? (int) $balance->operator_user_id : null;
        $balance->delete();

        $this->audit($creatorId, self::AUDIT_BALANCE_ENTITY, (int) $before['id'], 'deleted', $before, null, $operatorId);
    }

    /**
     * Approve a draft. Re-validates the exchange rate at approval time
     * (the base currency may have changed since the draft was created):
     * a missing required rate refuses approval — never guess a rate
     * (D2). Approving locks the account currency.
     */
    public function approveOpeningBalance(TreasuryOpeningBalance $balance, int $actorId): TreasuryOpeningBalance
    {
        if (! $balance->isDraft()) {
            throw new TreasuryException('Only a draft opening balance can be approved.');
        }

        $creatorId = (int) $balance->created_by;
        $account = $this->accountForCompany($creatorId, (int) $balance->financial_account_id);

        if (! $account->isActive()) {
            throw new TreasuryException('The account is inactive.');
        }

        [$rate, $rateSource, $rateDate, $amountBase] = $this->convertToBase(
            $creatorId,
            $balance->currency,
            (string) $balance->amount,
            array_merge($balance->toArray(), ['exchange_rate' => $balance->exchange_rate])
        );

        return DB::transaction(function () use ($balance, $account, $actorId, $rate, $rateSource, $rateDate, $amountBase) {
            $before = $balance->fresh()->toArray();

            $balance->exchange_rate = $rate;
            $balance->rate_source = $rateSource;
            $balance->rate_date = $rateDate;
            $balance->amount_base = $amountBase;
            $balance->status = TreasuryOpeningBalance::STATUS_APPROVED;
            $balance->save();

            // First confirmed movement locks the account currency.
            if (! $account->currency_locked) {
                $account->currency_locked = true;
                $account->save();
            }

            $this->audit((int) $balance->created_by, self::AUDIT_BALANCE_ENTITY, $balance->id, 'approved', $before, $balance->fresh()->toArray(), $actorId);

            return $balance->fresh();
        });
    }

    /**
     * Authorised reversal of a confirmed opening balance (RF03). The
     * original row is kept with status `reversed`; the reversal is a
     * NEW approved row with negated amounts linked via
     * `reversal_of_id`, so the posted balance nets to zero.
     */
    public function reverseOpeningBalance(TreasuryOpeningBalance $balance, string $reason, int $actorId): TreasuryOpeningBalance
    {
        if (! $balance->isApproved()) {
            throw new TreasuryException('Only an approved opening balance can be reversed.');
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw new TreasuryException('A reversal needs a reason.');
        }

        $creatorId = (int) $balance->created_by;

        return DB::transaction(function () use ($balance, $reason, $actorId, $creatorId) {
            $before = $balance->fresh()->toArray();

            $reversal = TreasuryOpeningBalance::create([
                'financial_account_id' => $balance->financial_account_id,
                'created_by' => $creatorId,
                'currency' => $balance->currency,
                'amount' => $this->negate((string) $balance->amount),
                'amount_base' => $this->negate((string) $balance->amount_base),
                'exchange_rate' => $balance->exchange_rate,
                'rate_source' => $balance->rate_source,
                'rate_date' => $balance->rate_date ? $balance->rate_date->format('Y-m-d') : null,
                'cut_off_date' => $balance->cut_off_date->format('Y-m-d'),
                'batch_reference' => $this->generateBatchReference($creatorId),
                'operator_user_id' => $actorId,
                'attachments' => null,
                'status' => TreasuryOpeningBalance::STATUS_APPROVED,
                'includes_liabilities' => (bool) $balance->includes_liabilities,
                'includes_receivables' => (bool) $balance->includes_receivables,
                'reversal_of_id' => $balance->id,
                'reversal_reason' => $reason,
            ]);

            $balance->status = TreasuryOpeningBalance::STATUS_REVERSED;
            $balance->save();

            $this->audit($creatorId, self::AUDIT_BALANCE_ENTITY, $balance->id, 'reversed', $before, $balance->fresh()->toArray(), $actorId);
            $this->audit($creatorId, self::AUDIT_BALANCE_ENTITY, $reversal->id, 'created', null, $reversal->fresh()->toArray(), $actorId);

            return $reversal->fresh();
        });
    }

    /**
     * Batch import: every row becomes a draft under one batch
     * reference (generated when not given). All rows are validated
     * first; nothing is created when any row fails. A batch reference
     * the company already used is refused.
     *
     * Row keys: code (account code) or financial_account_id, amount,
     * cut_off_date, currency (must match the account), exchange_rate,
     * rate_source, rate_date, includes_liabilities,
     * includes_receivables.
     *
     * @return TreasuryOpeningBalance[]
     */
    public function importOpeningBalances(int $creatorId, array $rows, int $operatorId, ?string $batchReference = null): array
    {
        if ($rows === []) {
            throw new TreasuryException('The import has no rows.');
        }

        $batchReference = trim((string) ($batchReference ?? ''));
        if ($batchReference === '') {
            $batchReference = $this->generateBatchReference($creatorId);
        }
        $this->assertBatchReferenceFree($creatorId, $batchReference);

        // Validate everything before creating anything.
        $prepared = [];
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                throw new TreasuryException('Import row ' . ($index + 1) . ' is not valid.');
            }

            $account = null;
            if (! empty($row['financial_account_id'])) {
                $account = $this->accountForCompany($creatorId, (int) $row['financial_account_id']);
            } elseif (! empty($row['code'])) {
                $account = TreasuryFinancialAccount::where('created_by', $creatorId)
                    ->where('code', strtoupper(trim((string) $row['code'])))
                    ->first();
                if (! $account) {
                    throw new TreasuryException('Import row ' . ($index + 1) . ': no account with code ' . $row['code'] . '.');
                }
            } else {
                throw new TreasuryException('Import row ' . ($index + 1) . ': account code is required.');
            }

            if (! $account->isActive()) {
                throw new TreasuryException('Import row ' . ($index + 1) . ': account ' . $account->code . ' is inactive.');
            }

            $currency = strtoupper(trim((string) ($row['currency'] ?? $account->currency)));
            if ($currency !== $account->currency) {
                throw new TreasuryException('Import row ' . ($index + 1) . ': currency must match the account currency (' . $account->currency . ').');
            }

            $amount = $this->decimalString($row['amount'] ?? null, 'Import row ' . ($index + 1) . ' amount');
            $cutOff = trim((string) ($row['cut_off_date'] ?? ''));
            if ($cutOff === '' || strtotime($cutOff) === false) {
                throw new TreasuryException('Import row ' . ($index + 1) . ': a valid cut-off date is required.');
            }

            [$rate, $rateSource, $rateDate, $amountBase] = $this->convertToBase($creatorId, $currency, $amount, $row);

            $prepared[] = [
                'account' => $account,
                'currency' => $currency,
                'amount' => $amount,
                'amount_base' => $amountBase,
                'rate' => $rate,
                'rate_source' => $rateSource,
                'rate_date' => $rateDate,
                'cut_off_date' => $cutOff,
                'includes_liabilities' => ! empty($row['includes_liabilities']),
                'includes_receivables' => ! empty($row['includes_receivables']),
            ];
        }

        return DB::transaction(function () use ($creatorId, $operatorId, $batchReference, $prepared) {
            $created = [];
            foreach ($prepared as $row) {
                $created[] = TreasuryOpeningBalance::create([
                    'financial_account_id' => $row['account']->id,
                    'created_by' => $creatorId,
                    'currency' => $row['currency'],
                    'amount' => $row['amount'],
                    'amount_base' => $row['amount_base'],
                    'exchange_rate' => $row['rate'],
                    'rate_source' => $row['rate_source'],
                    'rate_date' => $row['rate_date'],
                    'cut_off_date' => $row['cut_off_date'],
                    'batch_reference' => $batchReference,
                    'operator_user_id' => $operatorId,
                    'attachments' => null,
                    'status' => TreasuryOpeningBalance::STATUS_DRAFT,
                    'includes_liabilities' => $row['includes_liabilities'],
                    'includes_receivables' => $row['includes_receivables'],
                ]);
            }

            return $created;
        });
    }

    // ------------------------------------------------------------------
    // Balances + policy checks
    // ------------------------------------------------------------------

    /**
     * Posted balance = confirmed opening balances in base currency
     * (+ confirmed inflows − confirmed outflows, once later phases
     * add operations). Drafts never count; a reversed balance nets to
     * zero because its entry and its reversal entry (negated, on a
     * confirmed operation) both count.
     */
    public function postedBalance(TreasuryFinancialAccount $account): string
    {
        $amounts = TreasuryOpeningBalance::where('financial_account_id', $account->id)
            ->confirmed()
            ->pluck('amount_base');

        $total = '0.0000';
        foreach ($amounts as $amount) {
            $total = bcadd($total, (string) $amount, 4);
        }

        if (Schema::hasTable('treasury_entries') && Schema::hasTable('treasury_operations')) {
            // 'reversed' operations count too: their entry and their
            // reversal entry (negated, on a confirmed operation) net
            // to zero. Counting only 'confirmed' would leave the
            // negated reversal entry alone and read -amount.
            $entries = DB::table('treasury_entries')
                ->join('treasury_operations', 'treasury_operations.id', '=', 'treasury_entries.operation_id')
                ->where('treasury_entries.financial_account_id', $account->id)
                ->whereIn('treasury_operations.state', ['confirmed', 'reversed'])
                ->select('treasury_entries.direction', 'treasury_entries.amount_base')
                ->get();

            foreach ($entries as $entry) {
                $total = $entry->direction === 'outflow'
                    ? bcsub($total, (string) $entry->amount_base, 4)
                    : bcadd($total, (string) $entry->amount_base, 4);
            }
        }

        return $total;
    }

    /**
     * Available balance = posted balance − active reservations.
     * Phase 0 has no reservations, so available == posted; the
     * subtraction is wired (guarded) so Phase 2 only adds the table.
     */
    public function availableBalance(TreasuryFinancialAccount $account): string
    {
        $posted = $this->postedBalance($account);

        if (Schema::hasTable('treasury_fund_reservations')) {
            $reserved = (string) (DB::table('treasury_fund_reservations')
                ->where('financial_account_id', $account->id)
                ->where('state', 'active')
                ->sum('amount') ?? '0');
            $consumed = (string) (DB::table('treasury_fund_reservations')
                ->where('financial_account_id', $account->id)
                ->where('state', 'active')
                ->sum('consumed_amount') ?? '0');
            $released = (string) (DB::table('treasury_fund_reservations')
                ->where('financial_account_id', $account->id)
                ->where('state', 'active')
                ->sum('released_amount') ?? '0');

            $active = bcsub(bcsub($reserved, $consumed, 4), $released, 4);

            return bcsub($posted, $active, 4);
        }

        return $posted;
    }

    /**
     * Negative-balance policy (D3) for a projected balance.
     * Returns 'allowed', 'blocked' or 'needs_approval'.
     * - block: any negative projected balance is refused.
     * - allow: negative down to the named limit is allowed.
     * - approval: negative down to the named limit needs the named
     *   approver; below the limit is refused.
     * A missing limit means zero headroom.
     */
    public function checkNegativeBalance(TreasuryFinancialAccount $account, string $projectedBalance): string
    {
        if (bccomp($projectedBalance, '0', 4) >= 0) {
            return 'allowed';
        }

        $policy = $account->negative_balance_policy ?: TreasuryFinancialAccount::POLICY_BLOCK;
        $limit = $account->negative_balance_limit !== null ? (string) $account->negative_balance_limit : '0.0000';
        $headroom = bccomp(bcadd($projectedBalance, $limit, 4), '0', 4) >= 0;

        if ($policy === TreasuryFinancialAccount::POLICY_ALLOW) {
            return $headroom ? 'allowed' : 'blocked';
        }

        if ($policy === TreasuryFinancialAccount::POLICY_APPROVAL) {
            return $headroom ? 'needs_approval' : 'blocked';
        }

        return 'blocked';
    }

    /**
     * Account statement (plan §10, Phase 0): pre-period balance +
     * running balance. Phase 0 movements are the confirmed opening
     * balances only; Phase 1 adds confirmed treasury entries
     * (receipt inflows) as statement lines, ordered by date.
     *
     * @return array{pre_period_balance: string, lines: array<int, array{date: string, description: string, amount_base: string, running_balance: string}>, closing_balance: string}
     */
    public function statement(TreasuryFinancialAccount $account, ?string $from, ?string $to): array
    {
        $prePeriod = '0.0000';
        $lines = [];

        // Opening balances (Phase 0).
        $balanceQuery = TreasuryOpeningBalance::where('financial_account_id', $account->id)
            ->confirmed()
            ->orderBy('cut_off_date')
            ->orderBy('id');

        if ($from !== null && $from !== '') {
            $preAmounts = (clone $balanceQuery)->where('cut_off_date', '<', $from)->pluck('amount_base');
            foreach ($preAmounts as $amount) {
                $prePeriod = bcadd($prePeriod, (string) $amount, 4);
            }
            $balanceQuery->where('cut_off_date', '>=', $from);
        }
        if ($to !== null && $to !== '') {
            $balanceQuery->where('cut_off_date', '<=', $to);
        }

        $periodLines = [];
        foreach ($balanceQuery->get() as $row) {
            $periodLines[] = [
                'date' => $row->cut_off_date->format('Y-m-d'),
                'description' => 'Opening balance ' . $row->batch_reference,
                'amount_base' => (string) $row->amount_base,
                'sort' => $row->cut_off_date->format('Y-m-d') . '-0-' . $row->id,
            ];
        }

        // Phase 1: confirmed treasury entries (bank movements).
        // 'reversed' operations count too — their entry and their
        // reversal entry net to zero (same reason as postedBalance).
        if (Schema::hasTable('treasury_entries') && Schema::hasTable('treasury_operations')) {
            $entryQuery = DB::table('treasury_entries')
                ->join('treasury_operations', 'treasury_operations.id', '=', 'treasury_entries.operation_id')
                ->where('treasury_entries.financial_account_id', $account->id)
                ->whereIn('treasury_operations.state', ['confirmed', 'reversed'])
                ->select('treasury_operations.posting_date', 'treasury_operations.reference', 'treasury_operations.type', 'treasury_entries.direction', 'treasury_entries.amount_base', 'treasury_entries.id')
                ->orderBy('treasury_operations.posting_date')
                ->orderBy('treasury_entries.id');

            if ($from !== null && $from !== '') {
                $preEntries = (clone $entryQuery)->where('treasury_operations.posting_date', '<', $from)->get();
                foreach ($preEntries as $entry) {
                    $prePeriod = $entry->direction === 'outflow'
                        ? bcsub($prePeriod, (string) $entry->amount_base, 4)
                        : bcadd($prePeriod, (string) $entry->amount_base, 4);
                }
                $entryQuery->where('treasury_operations.posting_date', '>=', $from);
            }
            if ($to !== null && $to !== '') {
                $entryQuery->where('treasury_operations.posting_date', '<=', $to);
            }

            foreach ($entryQuery->get() as $entry) {
                $signed = $entry->direction === 'outflow'
                    ? $this->negate((string) $entry->amount_base)
                    : (string) $entry->amount_base;
                // Phase 4: the line names the operation kind — a
                // transfer leg is not a receipt.
                $kind = match ((string) ($entry->type ?? '')) {
                    'receipt' => 'receipt',
                    'payment' => 'payment',
                    'transfer' => 'transfer',
                    'advance' => 'advance',
                    'cash_movement' => 'cash movement',
                    'adjustment' => 'adjustment',
                    default => (string) ($entry->type ?? 'operation'),
                };
                $periodLines[] = [
                    'date' => (string) $entry->posting_date,
                    'description' => 'Treasury ' . $kind . ' ' . $entry->reference,
                    'amount_base' => $signed,
                    'sort' => (string) $entry->posting_date . '-1-' . $entry->id,
                ];
            }
        }

        usort($periodLines, fn ($a, $b) => strcmp($a['sort'], $b['sort']));

        $running = $prePeriod;
        foreach ($periodLines as $line) {
            $running = bcadd($running, $line['amount_base'], 4);
            $lines[] = [
                'date' => $line['date'],
                'description' => $line['description'],
                'amount_base' => $line['amount_base'],
                'running_balance' => $running,
            ];
        }

        return [
            'pre_period_balance' => $prePeriod,
            'lines' => $lines,
            'closing_balance' => $running,
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public function accountForCompany(int $creatorId, int $accountId): TreasuryFinancialAccount
    {
        $account = TreasuryFinancialAccount::where('created_by', $creatorId)->find($accountId);

        if (! $account) {
            throw new TreasuryException('Account not found for this company.');
        }

        return $account;
    }

    public function balanceForCompany(int $creatorId, int $balanceId): TreasuryOpeningBalance
    {
        $balance = TreasuryOpeningBalance::where('created_by', $creatorId)->find($balanceId);

        if (! $balance) {
            throw new TreasuryException('Opening balance not found for this company.');
        }

        return $balance;
    }

    /**
     * Convert an amount to the company base currency (D2).
     * Same currency: rate 1, base == original. Foreign currency: a
     * rate + source + date are required — a missing rate refuses,
     * never guesses.
     *
     * @return array{0: string|null, 1: string|null, 2: string|null, 3: string} rate, source, date, base amount
     */
    public function convertToBase(int $creatorId, string $currency, string $amount, array $data): array
    {
        $baseCurrency = TreasurySettings::baseCurrency($creatorId);

        if ($currency === $baseCurrency) {
            return ['1.0000000000', null, null, $this->toBase($amount, '1')];
        }

        [$rate, $rateSource, $rateDate] = $this->resolveRate($creatorId, $currency, $data, false);

        return [$rate, $rateSource, $rateDate, $this->toBase($amount, $rate)];
    }

    /**
     * @return array{0: string, 1: string, 2: string} rate, source, date
     */
    protected function resolveRate(int $creatorId, string $currency, array $data, bool $allowBaseCurrencyShortcut): array
    {
        $baseCurrency = TreasurySettings::baseCurrency($creatorId);

        if ($allowBaseCurrencyShortcut && $currency === $baseCurrency) {
            return ['1.0000000000', null, null];
        }

        $rate = trim((string) ($data['exchange_rate'] ?? ''));
        $source = trim((string) ($data['rate_source'] ?? ''));
        $date = trim((string) ($data['rate_date'] ?? ''));

        // A rate without a named source and date is not a rate: the
        // identity rate stored while the currency was the base currency
        // must never be reused as a market rate after a base change.
        if ($rate === '' || ! is_numeric($rate) || (float) $rate <= 0
            || $source === '' || $date === ''
        ) {
            throw new TreasuryException('No exchange rate for ' . $currency . ' on this date. Enter a rate and its source — a rate is never guessed.');
        }

        if (! in_array($source, TreasuryOpeningBalance::RATE_SOURCES, true)) {
            throw new TreasuryException('Unknown exchange-rate source (bank rate, central bank rate or manual entry).');
        }

        if (strtotime($date) === false) {
            throw new TreasuryException('The exchange-rate date is required.');
        }

        return [number_format((float) $rate, 10, '.', ''), $source, $date];
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

    protected function decimalString(mixed $value, string $label): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            throw new TreasuryException($label . ' must be a number.');
        }

        return number_format((float) $value, 4, '.', '');
    }

    protected function nullableDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            throw new TreasuryException('Amount must be a number.');
        }

        return number_format((float) $value, 4, '.', '');
    }

    protected function nullableString(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    protected function resolveBranchId(int $creatorId, mixed $branchId): ?int
    {
        if ($branchId === null || $branchId === '') {
            return null;
        }

        $id = (int) $branchId;

        if (Schema::hasTable('branches')) {
            $ok = Branch::where('id', $id)->where('created_by', $creatorId)->exists();
            if (! $ok) {
                throw new TreasuryException('Branch not found for this company.');
            }
        }

        return $id;
    }

    protected function resolveCompanyUserId(int $creatorId, mixed $userId): ?int
    {
        if ($userId === null || $userId === '') {
            return null;
        }

        $id = (int) $userId;
        $ok = User::where('id', $id)
            ->where(function ($q) use ($creatorId) {
                $q->where('id', $creatorId)->orWhere('created_by', $creatorId);
            })
            ->exists();

        if (! $ok) {
            throw new TreasuryException('User not found for this company.');
        }

        return $id;
    }

    protected function assertBatchReferenceFree(int $creatorId, string $batchReference): void
    {
        $used = TreasuryOpeningBalance::where('created_by', $creatorId)
            ->where('batch_reference', $batchReference)
            ->exists();

        if ($used) {
            throw new TreasuryException('Batch reference ' . $batchReference . ' was already used. Use a new reference for this import.');
        }
    }

    protected function generateBatchReference(int $creatorId): string
    {
        $year = date('Y');
        $count = TreasuryOpeningBalance::where('created_by', $creatorId)
            ->distinct('batch_reference')
            ->count('batch_reference');

        return sprintf('OB-%s-%04d', $year, $count + 1);
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
