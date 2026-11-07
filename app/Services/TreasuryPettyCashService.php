<?php

namespace App\Services;

use App\Exceptions\TreasuryException;
use App\Models\CostCentre;
use App\Models\DimensionAuditEvent;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\TreasuryEntry;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryOperation;
use App\Models\TreasuryPettyCashFund;
use App\Models\User;
use App\Services\Accounting\ChartAccountResolver;
use App\Services\Accounting\JournalEntryPoster;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Treasury Phase 5 — petty cash (plan §5, §10).
 *
 * A fund is a small cash float held by a custodian on one financial
 * account. Expenses post through the EXISTING Expense model
 * (PENDING → APPROVED → EXECUTED) with the fund and the cost centre
 * written on the line — no second expense flow, no second table.
 *
 * Each expense is recognised once: executing it posts one journal
 * (Dr expense account / Cr fund account) and one treasury entry
 * (outflow on the fund account), linked to one cash-movement
 * operation. Unspent money handed back is a return: one journal
 * (Dr fund / Cr expense account) and one inflow entry, and the
 * expense's returned amount grows — the same money is never counted
 * twice.
 *
 * Replenishment is always the spent amount: replenishment due =
 * limit − posted balance of the fund account, floored at zero. The
 * top-up itself is a normal Phase 4 transfer into the fund account.
 *
 * Segregation: the expense creator can never approve their own
 * expense; the approver must hold the manage treasury cash sessions
 * permission. A payment in a closed period is blocked, or routed as
 * an authorised adjustment to an open period (CA17).
 *
 * Posting goes through JournalEntryPoster only. The poster is never
 * touched here (known pre-existing legacy-balance issue — separate
 * decision).
 */
class TreasuryPettyCashService
{
    public const AUDIT_ENTITY = 'treasury_petty_cash_fund';
    public const AUDIT_EXPENSE_ENTITY = 'treasury_petty_cash_expense';

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

    public function fundForCompany(int $creatorId, int $fundId): TreasuryPettyCashFund
    {
        $fund = TreasuryPettyCashFund::where('created_by', $creatorId)->find($fundId);

        if (! $fund) {
            throw new TreasuryException('Petty-cash fund not found for this company.');
        }

        return $fund;
    }

    public function expenseForCompany(int $creatorId, int $expenseId): Expense
    {
        $expense = Expense::where('created_by', $creatorId)->find($expenseId);

        if (! $expense) {
            throw new TreasuryException('Expense not found for this company.');
        }

        return $expense;
    }

    // ------------------------------------------------------------------
    // Funds
    // ------------------------------------------------------------------

    /**
     * @param array{custodian_user_id: int, financial_account_id: int, branch_id?: int|null, limit_amount?: string|null, replenishment_frequency?: string} $data
     */
    public function createFund(int $creatorId, array $data, int $actorId): TreasuryPettyCashFund
    {
        return DB::transaction(function () use ($creatorId, $data, $actorId) {
            $accountId = (int) ($data['financial_account_id'] ?? 0);
            if ($accountId <= 0) {
                throw new TreasuryException('A financial account is required for the fund.');
            }
            $account = $this->accounts->accountForCompany($creatorId, $accountId);
            if (! $account->isActive()) {
                throw new TreasuryException('The financial account ' . $account->code . ' is inactive.');
            }

            $custodian = User::find((int) ($data['custodian_user_id'] ?? 0));
            if (! $custodian) {
                throw new TreasuryException('The fund custodian is required.');
            }
            if ((int) $custodian->id !== $creatorId && (int) $custodian->created_by !== $creatorId) {
                throw new TreasuryException('The fund custodian does not belong to this company.');
            }

            // One fund per account — two funds on the same account
            // would make the balance meaningless.
            $existing = TreasuryPettyCashFund::where('created_by', $creatorId)
                ->where('financial_account_id', $account->id)
                ->exists();
            if ($existing) {
                throw new TreasuryException('Account ' . $account->code . ' already has a petty-cash fund — one fund per account.');
            }

            $limit = null;
            if (isset($data['limit_amount']) && trim((string) $data['limit_amount']) !== '') {
                $limit = $this->decimalString($data['limit_amount'], 'Limit');
                if (bccomp($limit, '0', 4) < 0) {
                    throw new TreasuryException('The fund limit cannot be negative.');
                }
            }

            $frequency = (string) ($data['replenishment_frequency'] ?? TreasuryPettyCashFund::FREQUENCY_ON_DEMAND);
            if (! in_array($frequency, TreasuryPettyCashFund::FREQUENCIES, true)) {
                throw new TreasuryException('Replenishment frequency must be weekly, monthly or on demand.');
            }

            $fund = TreasuryPettyCashFund::create([
                'created_by' => $creatorId,
                'custodian_user_id' => (int) $custodian->id,
                'financial_account_id' => $account->id,
                'branch_id' => isset($data['branch_id']) && (int) $data['branch_id'] > 0 ? (int) $data['branch_id'] : null,
                'limit_amount' => $limit,
                'replenishment_frequency' => $frequency,
                'status' => TreasuryPettyCashFund::STATUS_ACTIVE,
            ]);

            $this->audit($creatorId, self::AUDIT_ENTITY, $fund->id, 'created', null, $fund->fresh()->toArray(), $actorId);

            return $fund->fresh();
        });
    }

    /** Edit a fund's custodian / limit / frequency (never its account). */
    public function updateFund(TreasuryPettyCashFund $fund, array $data, int $actorId): TreasuryPettyCashFund
    {
        return DB::transaction(function () use ($fund, $data, $actorId) {
            $before = $fund->fresh()->toArray();
            $creatorId = (int) $fund->created_by;

            $updates = [];
            if (isset($data['custodian_user_id'])) {
                $custodian = User::find((int) $data['custodian_user_id']);
                if (! $custodian) {
                    throw new TreasuryException('The fund custodian is required.');
                }
                if ((int) $custodian->id !== $creatorId && (int) $custodian->created_by !== $creatorId) {
                    throw new TreasuryException('The fund custodian does not belong to this company.');
                }
                $updates['custodian_user_id'] = (int) $custodian->id;
            }
            if (array_key_exists('limit_amount', $data)) {
                if ($data['limit_amount'] === null || trim((string) $data['limit_amount']) === '') {
                    $updates['limit_amount'] = null;
                } else {
                    $limit = $this->decimalString($data['limit_amount'], 'Limit');
                    if (bccomp($limit, '0', 4) < 0) {
                        throw new TreasuryException('The fund limit cannot be negative.');
                    }
                    $updates['limit_amount'] = $limit;
                }
            }
            if (isset($data['replenishment_frequency'])) {
                $frequency = (string) $data['replenishment_frequency'];
                if (! in_array($frequency, TreasuryPettyCashFund::FREQUENCIES, true)) {
                    throw new TreasuryException('Replenishment frequency must be weekly, monthly or on demand.');
                }
                $updates['replenishment_frequency'] = $frequency;
            }
            if (isset($data['branch_id'])) {
                $updates['branch_id'] = (int) $data['branch_id'] > 0 ? (int) $data['branch_id'] : null;
            }

            $fund->update($updates);
            $this->audit($creatorId, self::AUDIT_ENTITY, $fund->id, 'updated', $before, $fund->fresh()->toArray(), $actorId);

            return $fund->fresh();
        });
    }

    /** Deactivate a fund — refused while it has open (pending/approved) expenses. */
    public function deactivateFund(TreasuryPettyCashFund $fund, int $actorId): TreasuryPettyCashFund
    {
        if (! $fund->isActive()) {
            throw new TreasuryException('The fund is already inactive.');
        }

        $open = Expense::where('treasury_petty_cash_fund_id', $fund->id)
            ->whereIn('status', [Expense::STATUS_PENDING, Expense::STATUS_APPROVED])
            ->count();
        if ($open > 0) {
            throw new TreasuryException('The fund cannot be deactivated while it has ' . $open . ' open expense(s) — approve/reject or execute them first.');
        }

        return DB::transaction(function () use ($fund, $actorId) {
            $before = $fund->fresh()->toArray();
            $fund->status = TreasuryPettyCashFund::STATUS_INACTIVE;
            $fund->save();

            $this->audit((int) $fund->created_by, self::AUDIT_ENTITY, $fund->id, 'deactivated', $before, $fund->fresh()->toArray(), $actorId);

            return $fund->fresh();
        });
    }

    public function reactivateFund(TreasuryPettyCashFund $fund, int $actorId): TreasuryPettyCashFund
    {
        if ($fund->isActive()) {
            throw new TreasuryException('The fund is already active.');
        }

        $account = $this->accounts->accountForCompany((int) $fund->created_by, (int) $fund->financial_account_id);
        if (! $account->isActive()) {
            throw new TreasuryException('The fund account ' . $account->code . ' is inactive — reactivate the account first.');
        }

        return DB::transaction(function () use ($fund, $actorId) {
            $before = $fund->fresh()->toArray();
            $fund->status = TreasuryPettyCashFund::STATUS_ACTIVE;
            $fund->save();

            $this->audit((int) $fund->created_by, self::AUDIT_ENTITY, $fund->id, 'reactivated', $before, $fund->fresh()->toArray(), $actorId);

            return $fund->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Expenses (through the existing Expense model)
    // ------------------------------------------------------------------

    /**
     * Record a petty-cash expense (PENDING). The fund and the cost
     * centre are written on the line; the payment account is the
     * fund's bank account.
     *
     * @param array{name: string, date: string, description?: string|null, amount: string, expense_category_id: int, chart_account_id: int, cost_centre_id?: int|null, branch_id?: int|null} $data
     */
    public function recordExpense(int $creatorId, int $fundId, array $data, int $actorId): Expense
    {
        $fund = $this->fundForCompany($creatorId, $fundId);

        if (! $fund->isActive()) {
            throw new TreasuryException('The petty-cash fund is inactive.');
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new TreasuryException('The expense name is required.');
        }
        $date = $this->nullableDate($data['date'] ?? null, 'Expense date');
        if ($date === null) {
            throw new TreasuryException('The expense date is required.');
        }
        $amount = $this->decimalString($data['amount'] ?? null, 'Amount');
        if (bccomp($amount, '0', 4) <= 0) {
            throw new TreasuryException('The expense amount must be greater than zero.');
        }

        $categoryId = (int) ($data['expense_category_id'] ?? 0);
        if ($categoryId <= 0) {
            throw new TreasuryException('An expense category is required.');
        }
        $category = ExpenseCategory::where('created_by', $creatorId)->find($categoryId);
        if (! $category) {
            throw new TreasuryException('Expense category not found for this company.');
        }

        $chartAccountId = (int) ($data['chart_account_id'] ?? 0);
        if ($chartAccountId <= 0) {
            throw new TreasuryException('The expense chart account is required.');
        }
        $chart = \App\Models\ChartOfAccount::where('created_by', $creatorId)->find($chartAccountId);
        if (! $chart) {
            throw new TreasuryException('Chart account not found for this company.');
        }

        $costCentreId = null;
        if (! empty($data['cost_centre_id'])) {
            $costCentreId = (int) $data['cost_centre_id'];
            if (Schema::hasTable('cost_centres')) {
                $centre = CostCentre::where('created_by', $creatorId)->find($costCentreId);
                if (! $centre) {
                    throw new TreasuryException('Cost centre not found for this company.');
                }
            }
        }

        return DB::transaction(function () use ($fund, $creatorId, $data, $actorId, $name, $date, $amount, $categoryId, $chartAccountId, $costCentreId) {
            $account = $this->accounts->accountForCompany($creatorId, (int) $fund->financial_account_id);

            $expense = Expense::create([
                'name' => $name,
                'date' => $date,
                'description' => isset($data['description']) && trim((string) $data['description']) !== '' ? trim((string) $data['description']) : null,
                'amount' => number_format((float) $amount, 2, '.', ''),
                'expense_category_id' => $categoryId,
                'branch_id' => isset($data['branch_id']) && (int) $data['branch_id'] > 0 ? (int) $data['branch_id'] : (int) ($fund->branch_id ?? 0),
                'payment_account_id' => $account->bank_account_id,
                'chart_account_id' => $chartAccountId,
                'status' => Expense::STATUS_PENDING,
                'user_id' => $actorId,
                'created_by' => $creatorId,
                'treasury_petty_cash_fund_id' => $fund->id,
                'cost_centre_id' => $costCentreId,
                'treasury_petty_cash_returned_amount' => '0.0000',
            ]);

            $this->audit($creatorId, self::AUDIT_EXPENSE_ENTITY, $expense->id, 'recorded', null, $expense->fresh()->toArray(), $actorId);

            return $expense->fresh();
        });
    }

    /** Approve a pending expense. The creator can never approve their own expense. */
    public function approveExpense(int $creatorId, int $expenseId, int $actorId): Expense
    {
        $expense = $this->expenseForCompany($creatorId, $expenseId);

        if ((int) $expense->status !== Expense::STATUS_PENDING) {
            throw new TreasuryException('Only a pending expense can be approved (status: ' . $expense->status . ').');
        }
        if ($actorId === (int) $expense->user_id) {
            throw new TreasuryException('You cannot approve your own expense — a different user must approve it.');
        }
        if (! $this->userHasPermission($creatorId, $actorId, 'manage treasury cash sessions')) {
            throw new TreasuryException('Approving a petty-cash expense needs the manage treasury cash sessions permission.');
        }

        return DB::transaction(function () use ($expense, $actorId) {
            $before = $expense->fresh()->toArray();
            $expense->status = Expense::STATUS_APPROVED;
            $expense->save();

            $this->audit((int) $expense->created_by, self::AUDIT_EXPENSE_ENTITY, $expense->id, 'approved', $before, $expense->fresh()->toArray(), $actorId);

            return $expense->fresh();
        });
    }

    /** Reject a pending expense. */
    public function rejectExpense(int $creatorId, int $expenseId, int $actorId, ?string $reason = null): Expense
    {
        $expense = $this->expenseForCompany($creatorId, $expenseId);

        if ((int) $expense->status !== Expense::STATUS_PENDING) {
            throw new TreasuryException('Only a pending expense can be rejected (status: ' . $expense->status . ').');
        }
        if ($actorId === (int) $expense->user_id) {
            throw new TreasuryException('You cannot reject your own expense — a different user must decide it.');
        }

        return DB::transaction(function () use ($expense, $actorId, $reason) {
            $before = $expense->fresh()->toArray();
            $expense->status = Expense::STATUS_REJECTED;
            if ($reason !== null && trim($reason) !== '') {
                $expense->description = trim((string) ($expense->description ?? '') . "\nRejected: " . trim($reason));
            }
            $expense->save();

            $this->audit((int) $expense->created_by, self::AUDIT_EXPENSE_ENTITY, $expense->id, 'rejected', $before, $expense->fresh()->toArray(), $actorId);

            return $expense->fresh();
        });
    }

    /**
     * Execute an approved expense: one journal (Dr expense account /
     * Cr fund account) and one treasury entry (outflow on the fund
     * account), linked to one cash-movement operation. The expense
     * is recognised once — executing it twice is refused.
     *
     * Options: posting_date (default today), adjustment (CA17),
     * exchange_rate / rate_source (required when the fund account
     * does not use the base currency — never guess a rate, D2).
     */
    public function executeExpense(int $creatorId, int $expenseId, int $actorId, array $options = []): Expense
    {
        $options['executor_user_id'] = (int) ($options['executor_user_id'] ?? $actorId);
        $expense = $this->expenseForCompany($creatorId, $expenseId);

        return DB::transaction(function () use ($creatorId, $expense, $actorId, $options) {
            $locked = Expense::lockForUpdate()->findOrFail($expense->id);

            if ((int) $locked->status !== Expense::STATUS_APPROVED) {
                throw new TreasuryException('Only an approved expense can be executed (status: ' . $locked->status . ').');
            }
            $fund = $this->fundForCompany($creatorId, (int) $locked->treasury_petty_cash_fund_id);
            if (! $fund->isActive()) {
                throw new TreasuryException('The petty-cash fund is inactive.');
            }
            $account = TreasuryFinancialAccount::lockForUpdate()->findOrFail((int) $fund->financial_account_id);
            if (! $account->isActive()) {
                throw new TreasuryException('The fund account ' . $account->code . ' is inactive.');
            }

            $amount = $this->decimalString($locked->amount, 'Amount');
            $baseCurrency = TreasurySettings::baseCurrency($creatorId);
            $rate = '1.0000000000';
            $rateSource = null;
            $rateDate = null;
            if ((string) $account->currency !== $baseCurrency) {
                $rateInput = $options['exchange_rate'] ?? null;
                if ($rateInput === null || trim((string) $rateInput) === '') {
                    throw new TreasuryException('An exchange rate is required when the fund account does not use the base currency (' . $baseCurrency . ') — never guess a rate (D2).');
                }
                $rate = $this->rateString($rateInput, 'Exchange rate');
                if (bccomp($rate, '0', 10) <= 0) {
                    throw new TreasuryException('An exchange rate is required when the fund account does not use the base currency (' . $baseCurrency . ') — never guess a rate (D2).');
                }
                $rateSource = trim((string) ($options['rate_source'] ?? ''));
                if ($rateSource === '') {
                    throw new TreasuryException('The exchange-rate source is required (bank rate, central bank, or manual entry).');
                }
                $rateSource = substr($rateSource, 0, 20);
                $rateDate = $this->nullableDate($options['rate_date'] ?? null, 'Rate date') ?? date('Y-m-d');
            }
            $amountBase = bcmul($amount, $rate, 4);

            $posting = $this->resolvePostingDate($fund, $options);

            // Recheck under lock: availability, then the period.
            $available = $this->accounts->availableBalance($account);
            if (bccomp($amountBase, $available, 4) > 0) {
                throw new TreasuryException('Insufficient available balance on the fund account ' . $account->code . ': ' . $available . ' available for ' . $amountBase . ' required.');
            }
            $postingDate = Carbon::parse($posting['posting_date']);
            if (! $this->isPeriodOpen($creatorId, $postingDate) && $posting['adjustment'] === null) {
                throw new TreasuryException('Fiscal year ' . $postingDate->year . ' is closed. Treasury postings into a closed period are blocked — an authorised adjustment to an open period is required (CA17).');
            }

            $fundChartId = $this->chartAccountId($account);
            $expenseChartId = (int) $locked->chart_account_id;
            if ($expenseChartId <= 0) {
                throw new TreasuryException('The expense has no chart account.');
            }

            $amount2 = $this->round2($amountBase);
            if (bccomp($amount2, '0', 2) <= 0) {
                throw new TreasuryException('The expense amount is too small to post (it rounds to zero).');
            }

            $reference = $this->nextReference($creatorId, TreasuryOperation::TYPE_CASH_MOVEMENT, $posting['posting_date']);

            $journal = $this->poster->post($creatorId, $posting['posting_date'], $reference, 'Petty-cash expense — ' . $locked->name, [
                [
                    'account' => $expenseChartId,
                    'debit' => (float) $amount2,
                    'description' => 'Petty-cash expense — ' . $locked->name,
                ],
                [
                    'account' => $fundChartId,
                    'credit' => (float) $amount2,
                    'description' => 'Petty-cash expense — fund ' . $account->code,
                ],
            ]);

            $operation = TreasuryOperation::create([
                'reference' => $reference,
                'type' => TreasuryOperation::TYPE_CASH_MOVEMENT,
                'company_id' => $creatorId,
                'branch_id' => $fund->branch_id,
                'financial_account_id' => $account->id,
                'counterparty_type' => 'entity',
                'counterparty_id' => null,
                'currency' => (string) $account->currency,
                'amount_original' => $amount,
                'exchange_rate' => $rate,
                'rate_source' => $rateSource,
                'rate_date' => $rateDate,
                'amount_base' => $amountBase,
                'posting_date' => $posting['posting_date'],
                'effective_date' => $posting['posting_date'],
                'value_date' => $posting['posting_date'],
                'state' => TreasuryOperation::STATE_CONFIRMED,
                'source_type' => 'treasury_petty_cash_expense',
                'source_id' => $locked->id,
                'created_by' => $creatorId,
            ]);

            TreasuryEntry::create([
                'operation_id' => $operation->id,
                'financial_account_id' => $account->id,
                'direction' => TreasuryEntry::DIRECTION_OUTFLOW,
                'amount_original' => $amount,
                'amount_base' => $amountBase,
                'exchange_rate' => $rate,
                'confirmed_at' => now(),
                'journal_id' => $journal->id,
                'created_by' => $creatorId,
            ]);

            $before = $locked->fresh()->toArray();
            $locked->status = Expense::STATUS_EXECUTED;
            $locked->executed_at = now();
            $locked->executed_by = $actorId;
            $locked->save();

            $this->audit($creatorId, self::AUDIT_EXPENSE_ENTITY, $locked->id, 'executed', $before, $locked->fresh()->toArray(), $actorId);

            return $locked->fresh();
        });
    }

    /**
     * Hand unspent money back to the fund: one journal (Dr fund /
     * Cr expense account) and one inflow entry. The expense's
     * returned amount grows, so the same money is never counted
     * twice. The return can never exceed the unreturned remainder.
     *
     * Options: posting_date (default today), adjustment (CA17).
     */
    public function returnUnspent(int $creatorId, int $expenseId, string $amount, int $actorId, ?string $reason = null, array $options = []): Expense
    {
        $options['executor_user_id'] = (int) ($options['executor_user_id'] ?? $actorId);
        $expense = $this->expenseForCompany($creatorId, $expenseId);
        $amount = $this->decimalString($amount, 'Return amount');
        if (bccomp($amount, '0', 4) <= 0) {
            throw new TreasuryException('The return amount must be greater than zero.');
        }

        return DB::transaction(function () use ($creatorId, $expense, $amount, $actorId, $reason, $options) {
            $locked = Expense::lockForUpdate()->findOrFail($expense->id);

            if ((int) $locked->status !== Expense::STATUS_EXECUTED) {
                throw new TreasuryException('Only an executed expense can have money returned (status: ' . $locked->status . ').');
            }
            $alreadyReturned = (string) ($locked->treasury_petty_cash_returned_amount ?? '0');
            $remaining = bcsub($this->decimalString($locked->amount, 'Amount'), $alreadyReturned, 4);
            if (bccomp($amount, $remaining, 4) > 0) {
                throw new TreasuryException('The return of ' . $amount . ' exceeds the unreturned remainder of ' . $remaining . ' on this expense.');
            }

            $fund = $this->fundForCompany($creatorId, (int) $locked->treasury_petty_cash_fund_id);
            if (! $fund->isActive()) {
                throw new TreasuryException('The petty-cash fund is inactive.');
            }
            $account = TreasuryFinancialAccount::lockForUpdate()->findOrFail((int) $fund->financial_account_id);
            if (! $account->isActive()) {
                throw new TreasuryException('The fund account ' . $account->code . ' is inactive.');
            }

            $baseCurrency = TreasurySettings::baseCurrency($creatorId);
            $rate = '1.0000000000';
            $rateSource = null;
            $rateDate = null;
            if ((string) $account->currency !== $baseCurrency) {
                $rateInput = $options['exchange_rate'] ?? null;
                if ($rateInput === null || trim((string) $rateInput) === '') {
                    throw new TreasuryException('An exchange rate is required when the fund account does not use the base currency (' . $baseCurrency . ') — never guess a rate (D2).');
                }
                $rate = $this->rateString($rateInput, 'Exchange rate');
                if (bccomp($rate, '0', 10) <= 0) {
                    throw new TreasuryException('An exchange rate is required when the fund account does not use the base currency (' . $baseCurrency . ') — never guess a rate (D2).');
                }
                $rateSource = trim((string) ($options['rate_source'] ?? ''));
                if ($rateSource === '') {
                    throw new TreasuryException('The exchange-rate source is required (bank rate, central bank, or manual entry).');
                }
                $rateSource = substr($rateSource, 0, 20);
                $rateDate = $this->nullableDate($options['rate_date'] ?? null, 'Rate date') ?? date('Y-m-d');
            }
            $amountBase = bcmul($amount, $rate, 4);

            $posting = $this->resolvePostingDate($fund, $options);
            $postingDate = Carbon::parse($posting['posting_date']);
            if (! $this->isPeriodOpen($creatorId, $postingDate) && $posting['adjustment'] === null) {
                throw new TreasuryException('Fiscal year ' . $postingDate->year . ' is closed. Treasury postings into a closed period are blocked — an authorised adjustment to an open period is required (CA17).');
            }

            $fundChartId = $this->chartAccountId($account);
            $expenseChartId = (int) $locked->chart_account_id;
            if ($expenseChartId <= 0) {
                throw new TreasuryException('The expense has no chart account.');
            }

            $amount2 = $this->round2($amountBase);
            if (bccomp($amount2, '0', 2) <= 0) {
                throw new TreasuryException('The return amount is too small to post (it rounds to zero).');
            }

            $reference = $this->nextReference($creatorId, TreasuryOperation::TYPE_CASH_MOVEMENT, $posting['posting_date']);

            $journal = $this->poster->post($creatorId, $posting['posting_date'], $reference, 'Petty-cash return — ' . $locked->name, [
                [
                    'account' => $fundChartId,
                    'debit' => (float) $amount2,
                    'description' => 'Petty-cash return — fund ' . $account->code . ($reason !== null && trim($reason) !== '' ? ' (' . trim($reason) . ')' : ''),
                ],
                [
                    'account' => $expenseChartId,
                    'credit' => (float) $amount2,
                    'description' => 'Petty-cash return — ' . $locked->name,
                ],
            ]);

            $operation = TreasuryOperation::create([
                'reference' => $reference,
                'type' => TreasuryOperation::TYPE_CASH_MOVEMENT,
                'company_id' => $creatorId,
                'branch_id' => $fund->branch_id,
                'financial_account_id' => $account->id,
                'counterparty_type' => 'entity',
                'counterparty_id' => null,
                'currency' => (string) $account->currency,
                'amount_original' => $amount,
                'exchange_rate' => $rate,
                'rate_source' => $rateSource,
                'rate_date' => $rateDate,
                'amount_base' => $amountBase,
                'posting_date' => $posting['posting_date'],
                'effective_date' => $posting['posting_date'],
                'value_date' => $posting['posting_date'],
                'state' => TreasuryOperation::STATE_CONFIRMED,
                'source_type' => 'treasury_petty_cash_return',
                'source_id' => $locked->id,
                'created_by' => $creatorId,
            ]);

            TreasuryEntry::create([
                'operation_id' => $operation->id,
                'financial_account_id' => $account->id,
                'direction' => TreasuryEntry::DIRECTION_INFLOW,
                'amount_original' => $amount,
                'amount_base' => $amountBase,
                'exchange_rate' => $rate,
                'confirmed_at' => now(),
                'journal_id' => $journal->id,
                'created_by' => $creatorId,
            ]);

            $before = $locked->fresh()->toArray();
            $locked->treasury_petty_cash_returned_amount = bcadd($alreadyReturned, $amount, 4);
            $locked->save();

            $this->audit($creatorId, self::AUDIT_EXPENSE_ENTITY, $locked->id, 'returned', $before, $locked->fresh()->toArray(), $actorId);

            return $locked->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Balances
    // ------------------------------------------------------------------

    /**
     * Replenishment due: limit − posted balance of the fund account,
     * floored at zero. No limit means nothing is due. The top-up
     * itself is a normal Phase 4 transfer into the fund account.
     */
    public function replenishmentDue(TreasuryPettyCashFund $fund): string
    {
        if ($fund->limit_amount === null) {
            return '0.0000';
        }

        $account = $this->accounts->accountForCompany((int) $fund->created_by, (int) $fund->financial_account_id);
        $due = bcsub((string) $fund->limit_amount, $this->accounts->postedBalance($account), 4);

        return bccomp($due, '0', 4) > 0 ? $due : '0.0000';
    }

    /**
     * @return array{fund: TreasuryPettyCashFund, posted_balance: string, limit: string|null, replenishment_due: string, expenses: array{pending: int, approved: int, executed: int, rejected: int}, spent: string, returned: string}
     */
    public function fundSummary(TreasuryPettyCashFund $fund): array
    {
        $account = $this->accounts->accountForCompany((int) $fund->created_by, (int) $fund->financial_account_id);
        $expenses = Expense::where('treasury_petty_cash_fund_id', $fund->id)->get();

        $counts = ['pending' => 0, 'approved' => 0, 'executed' => 0, 'rejected' => 0];
        $spent = '0.0000';
        $returned = '0.0000';
        foreach ($expenses as $expense) {
            $status = (int) $expense->status;
            if ($status === Expense::STATUS_PENDING) {
                $counts['pending']++;
            } elseif ($status === Expense::STATUS_APPROVED) {
                $counts['approved']++;
            } elseif ($status === Expense::STATUS_EXECUTED) {
                $counts['executed']++;
                $spent = bcadd($spent, $this->decimalString($expense->amount, 'Amount'), 4);
                $returned = bcadd($returned, (string) ($expense->treasury_petty_cash_returned_amount ?? '0'), 4);
            } elseif ($status === Expense::STATUS_REJECTED) {
                $counts['rejected']++;
            }
        }

        return [
            'fund' => $fund,
            'posted_balance' => $this->accounts->postedBalance($account),
            'limit' => $fund->limit_amount !== null ? (string) $fund->limit_amount : null,
            'replenishment_due' => $this->replenishmentDue($fund),
            'expenses' => $counts,
            'spent' => $spent,
            'returned' => $returned,
        ];
    }

    // ------------------------------------------------------------------
    // References, posting accounts, periods
    // ------------------------------------------------------------------

    /** Next reference for the company + type + year, serialised (Phase 1/2/4 pattern). */
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

    protected function chartAccountId(?TreasuryFinancialAccount $account): int
    {
        $id = $account && $account->bankAccount ? (int) $account->bankAccount->chart_account_id : 0;
        if ($id <= 0) {
            throw new TreasuryException('The financial account has no chart account. Set one on the account before executing.');
        }

        return $id;
    }

    /**
     * @return array{posting_date: string, adjustment: array|null}
     */
    protected function resolvePostingDate(TreasuryPettyCashFund $fund, array $options): array
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
        // executor, and must hold the cash-session permission.
        $executorId = (int) ($options['executor_user_id'] ?? 0);
        if ($executorId > 0 && $authorisedBy === $executorId) {
            throw new TreasuryException('The adjustment must be authorised by a different user than the executor.');
        }
        if (! $this->userHasPermission((int) $fund->created_by, $authorisedBy, 'manage treasury cash sessions')) {
            throw new TreasuryException('The adjustment authoriser does not hold the manage treasury cash sessions permission.');
        }

        // The adjustment posts in an open period: today, when
        // today is open. (The caller still blocks the posting
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

    /** Same FY-CLOSE rule as the receipt/execution/transfer services (CA17). */
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

    /** Direct + role permission check (Phase 2/3/4 pattern). */
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
