<?php

namespace Tests\Unit;

use App\Exceptions\TreasuryException;
use App\Models\Expense;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryPettyCashFund;
use App\Services\TreasuryAccountService;
use App\Services\TreasuryPettyCashService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Treasury Phase 5 — petty cash (plan §5, §10).
 *
 * Users: 1 = company, 2 = custodian (records expenses), 3 = approver
 * (holds 'manage treasury cash sessions'), 4 = other company. The
 * fund account is funded with 5,000 MZN; chart accounts are 121
 * (fund bank) and 61 (expense).
 */
class TreasuryPettyCashServiceTest extends TestCase
{
    protected TreasuryAccountService $accounts;
    protected TreasuryPettyCashService $service;
    protected TreasuryFinancialAccount $fundAccount;
    protected TreasuryPettyCashFund $fund;
    protected int $categoryId;
    protected int $centreId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Custodian', 'email' => 'custodian@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
        ]);

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Maputo', 'created_by' => 1],
        ]);

        DB::table('permissions')->insert([
            ['id' => 1, 'name' => 'manage treasury cash sessions', 'guard_name' => 'web'],
        ]);
        DB::table('model_has_permissions')->insert([
            ['permission_id' => 1, 'model_type' => 'App\\Models\\User', 'model_id' => 1],
            ['permission_id' => 1, 'model_type' => 'App\\Models\\User', 'model_id' => 3],
        ]);

        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'code' => 121, 'name' => 'Bank fund', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 2, 'code' => 61, 'name' => 'Expenses', 'type' => 6, 'sub_type' => 1, 'created_by' => 1],
        ]);

        $this->categoryId = DB::table('expense_categories')->insertGetId([
            'name' => 'Transport', 'user_id' => 1, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->centreId = DB::table('cost_centres')->insertGetId([
            'company_id' => 1, 'created_by' => 1, 'branch_id' => null, 'parent_id' => null,
            'code' => 'LOG', 'name' => 'Logistics', 'description' => null,
            'manager_user_id' => 2, 'status' => 'active',
            'effective_from' => null, 'effective_to' => null, 'updated_by' => null,
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
        ]);

        $this->accounts = new TreasuryAccountService();
        $this->service = new TreasuryPettyCashService($this->accounts);
        $this->fundAccount = $this->makeAccount('5000.0000', 'PC-001', 'MZN', 'bank', 1);
        $this->fund = $this->service->createFund(1, [
            'custodian_user_id' => 2,
            'financial_account_id' => $this->fundAccount->id,
            'limit_amount' => '5000',
            'replenishment_frequency' => 'weekly',
        ], 1);
    }

    protected function tearDown(): void
    {
        foreach (['treasury_petty_cash_funds', 'expenses', 'expense_categories', 'cost_centres', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'dimension_audit_events', 'bank_accounts', 'chart_of_accounts', 'branches', 'settings', 'users', 'permissions', 'model_has_permissions', 'journal_entries', 'journal_items', 'transaction_lines'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function makeAccount(string $balance, string $code, string $currency = 'MZN', string $type = 'bank', int $chartId = 1): TreasuryFinancialAccount
    {
        $bankId = DB::table('bank_accounts')->insertGetId([
            'holder_name' => 'Hoya Hoya Lda', 'bank_name' => 'BIM', 'account_number' => '123456789',
            'chart_account_id' => $chartId, 'opening_balance' => 0, 'contact_number' => '', 'bank_address' => '',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $account = TreasuryFinancialAccount::create([
            'bank_account_id' => $bankId, 'created_by' => 1, 'code' => $code,
            'type' => $type, 'currency' => $currency, 'status' => 'active',
            'negative_balance_policy' => 'block',
        ]);

        DB::table('treasury_opening_balances')->insert([
            'financial_account_id' => $account->id, 'created_by' => 1, 'currency' => $currency,
            'amount' => $balance, 'amount_base' => $balance, 'cut_off_date' => '2026-11-01',
            'batch_reference' => 'BATCH-' . $code, 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $account->fresh();
    }

    protected function expenseData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Taxi to the market',
            'date' => date('Y-m-d'),
            'amount' => '1200',
            'expense_category_id' => $this->categoryId,
            'chart_account_id' => 2,
            'cost_centre_id' => $this->centreId,
        ], $overrides);
    }

    protected function recordExpense(array $overrides = [], int $actorId = 2): Expense
    {
        return $this->service->recordExpense(1, $this->fund->id, $this->expenseData($overrides), $actorId);
    }

    protected function approvedExpense(array $overrides = []): Expense
    {
        $expense = $this->recordExpense($overrides);

        return $this->service->approveExpense(1, $expense->id, 3);
    }

    protected function executedExpense(array $overrides = []): Expense
    {
        $expense = $this->approvedExpense($overrides);

        return $this->service->executeExpense(1, $expense->id, 3);
    }

    protected function journalItems(int $journalId)
    {
        return DB::table('journal_items')->where('journal', $journalId)->orderBy('id')->get();
    }

    // ------------------------------------------------------------------
    // Funds
    // ------------------------------------------------------------------

    public function test_create_fund_happy_path(): void
    {
        $this->assertTrue($this->fund->isActive());
        $this->assertSame(2, (int) $this->fund->custodian_user_id);
        $this->assertSame($this->fundAccount->id, (int) $this->fund->financial_account_id);
        $this->assertSame('5000.0000', (string) $this->fund->limit_amount);
        $this->assertSame('weekly', $this->fund->replenishment_frequency);
    }

    public function test_second_fund_on_same_account_is_refused(): void
    {
        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('already has a petty-cash fund');

        $this->service->createFund(1, [
            'custodian_user_id' => 3,
            'financial_account_id' => $this->fundAccount->id,
        ], 1);
    }

    public function test_create_fund_with_custodian_from_another_company_is_refused(): void
    {
        $other = $this->makeAccount('0.0000', 'PC-002', 'MZN', 'bank', 1);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('does not belong to this company');

        $this->service->createFund(1, [
            'custodian_user_id' => 4,
            'financial_account_id' => $other->id,
        ], 1);
    }

    public function test_create_fund_with_bad_frequency_is_refused(): void
    {
        $other = $this->makeAccount('0.0000', 'PC-002', 'MZN', 'bank', 1);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('weekly, monthly or on demand');

        $this->service->createFund(1, [
            'custodian_user_id' => 2,
            'financial_account_id' => $other->id,
            'replenishment_frequency' => 'daily',
        ], 1);
    }

    public function test_update_fund_never_changes_its_account(): void
    {
        $other = $this->makeAccount('0.0000', 'PC-002', 'MZN', 'bank', 1);

        $updated = $this->service->updateFund($this->fund, [
            'financial_account_id' => $other->id,
            'custodian_user_id' => 3,
            'limit_amount' => '4000',
        ], 1);

        $this->assertSame($this->fundAccount->id, (int) $updated->financial_account_id);
        $this->assertSame(3, (int) $updated->custodian_user_id);
        $this->assertSame('4000.0000', (string) $updated->limit_amount);
    }

    // ------------------------------------------------------------------
    // Record / approve / reject
    // ------------------------------------------------------------------

    public function test_record_expense_creates_pending_expense_on_the_fund(): void
    {
        $expense = $this->recordExpense();

        $this->assertSame(Expense::STATUS_PENDING, (int) $expense->status);
        $this->assertSame($this->fund->id, (int) $expense->treasury_petty_cash_fund_id);
        $this->assertSame($this->centreId, (int) $expense->cost_centre_id);
        $this->assertSame($this->categoryId, (int) $expense->expense_category_id);
        $this->assertSame(2, (int) $expense->chart_account_id);
        $this->assertSame((int) $this->fundAccount->bank_account_id, (int) $expense->payment_account_id);
        $this->assertSame('1200.00', (string) $expense->amount);
        // Recording an expense moves no money yet.
        $this->assertSame('5000.0000', $this->accounts->postedBalance($this->fundAccount->fresh()));
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_record_expense_with_unknown_cost_centre_is_refused(): void
    {
        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('Cost centre not found');

        $this->recordExpense(['cost_centre_id' => 999]);
    }

    public function test_creator_cannot_approve_own_expense(): void
    {
        $expense = $this->recordExpense();

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('cannot approve your own expense');

        $this->service->approveExpense(1, $expense->id, 2);
    }

    public function test_approve_without_permission_is_refused(): void
    {
        $expense = $this->recordExpense();

        // User 2 is the creator; use a fresh employee without the
        // permission — create one inline.
        DB::table('users')->insert([
            'id' => 5, 'name' => 'No Permission', 'email' => 'noperm@test.local',
            'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1,
            'email_verified_at' => now(),
        ]);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('needs the manage treasury cash sessions permission');

        $this->service->approveExpense(1, $expense->id, 5);
    }

    public function test_approve_then_reject_states(): void
    {
        $expense = $this->recordExpense();
        $approved = $this->service->approveExpense(1, $expense->id, 3);
        $this->assertSame(Expense::STATUS_APPROVED, (int) $approved->status);

        $other = $this->recordExpense(['name' => 'Stationery']);
        $rejected = $this->service->rejectExpense(1, $other->id, 3, 'No receipt attached.');
        $this->assertSame(Expense::STATUS_REJECTED, (int) $rejected->status);
        $this->assertStringContainsString('No receipt attached.', $rejected->description);
    }

    // ------------------------------------------------------------------
    // Execute — journal + entry, once
    // ------------------------------------------------------------------

    public function test_execute_expense_posts_journal_and_moves_fund_balance(): void
    {
        $expense = $this->approvedExpense();

        $executed = $this->service->executeExpense(1, $expense->id, 3);

        $this->assertSame(Expense::STATUS_EXECUTED, (int) $executed->status);
        $this->assertNotNull($executed->executed_at);
        $this->assertSame(3, (int) $executed->executed_by);

        $this->assertSame(1, DB::table('journal_entries')->count());
        $journal = DB::table('journal_entries')->first();
        $items = $this->journalItems((int) $journal->id);
        $this->assertCount(2, $items);
        // Dr expense chart 61 / Cr fund bank chart 121.
        $this->assertSame(2, (int) $items[0]->account);
        $this->assertSame(1200.0, (float) $items[0]->debit);
        $this->assertSame(1, (int) $items[1]->account);
        $this->assertSame(1200.0, (float) $items[1]->credit);

        // One outflow entry on the fund account; the fund is down 1,200.
        $this->assertSame(1, DB::table('treasury_entries')->where('financial_account_id', $this->fundAccount->id)->where('direction', 'outflow')->count());
        $this->assertSame('3800.0000', $this->accounts->postedBalance($this->fundAccount->fresh()));
    }

    public function test_execute_expense_twice_is_refused(): void
    {
        $expense = $this->executedExpense();

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('Only an approved expense can be executed');

        $this->service->executeExpense(1, $expense->id, 3);
    }

    public function test_execute_pending_expense_is_refused(): void
    {
        $expense = $this->recordExpense();

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('Only an approved expense can be executed');

        $this->service->executeExpense(1, $expense->id, 3);
    }

    public function test_execute_expense_beyond_fund_balance_is_refused(): void
    {
        $expense = $this->approvedExpense(['amount' => '6000']);

        $this->expectException(TreasuryException::class);

        $this->service->executeExpense(1, $expense->id, 3);
    }

    // ------------------------------------------------------------------
    // Return unspent
    // ------------------------------------------------------------------

    public function test_return_unspent_posts_reverse_journal_and_grows_returned_amount(): void
    {
        $expense = $this->executedExpense();

        $returned = $this->service->returnUnspent(1, $expense->id, '300', 2);

        $this->assertSame(0, bccomp((string) $returned->treasury_petty_cash_returned_amount, '300.0000', 4));
        $this->assertSame(Expense::STATUS_EXECUTED, (int) $returned->status);

        $this->assertSame(2, DB::table('journal_entries')->count());
        $journal = DB::table('journal_entries')->orderBy('id', 'desc')->first();
        $items = $this->journalItems((int) $journal->id);
        $this->assertCount(2, $items);
        // Return: Dr fund bank 121 / Cr expense chart 61.
        $this->assertSame(1, (int) $items[0]->account);
        $this->assertSame(300.0, (float) $items[0]->debit);
        $this->assertSame(2, (int) $items[1]->account);
        $this->assertSame(300.0, (float) $items[1]->credit);

        $this->assertSame('4100.0000', $this->accounts->postedBalance($this->fundAccount->fresh()));
    }

    public function test_return_more_than_unreturned_is_refused(): void
    {
        $expense = $this->executedExpense();
        $this->service->returnUnspent(1, $expense->id, '300', 2);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('exceeds the unreturned remainder');

        $this->service->returnUnspent(1, $expense->id, '1000', 2);
    }

    public function test_return_on_pending_expense_is_refused(): void
    {
        $expense = $this->recordExpense();

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('Only an executed expense');

        $this->service->returnUnspent(1, $expense->id, '100', 2);
    }

    // ------------------------------------------------------------------
    // Replenishment + summary
    // ------------------------------------------------------------------

    public function test_replenishment_due_is_limit_minus_posted_balance(): void
    {
        $this->assertSame('0.0000', $this->service->replenishmentDue($this->fund));

        $this->executedExpense();

        $this->assertSame('1200.0000', $this->service->replenishmentDue($this->fund));
    }

    public function test_replenishment_due_is_zero_without_a_limit(): void
    {
        $other = $this->makeAccount('1000.0000', 'PC-002', 'MZN', 'bank', 1);
        $fund = $this->service->createFund(1, [
            'custodian_user_id' => 2,
            'financial_account_id' => $other->id,
        ], 1);

        $this->assertSame('0.0000', $this->service->replenishmentDue($fund));
    }

    public function test_fund_summary_counts_expenses_and_tracks_spent_and_returned(): void
    {
        $this->executedExpense();
        $this->service->returnUnspent(1, Expense::where('treasury_petty_cash_fund_id', $this->fund->id)->first()->id, '200', 2);
        $this->recordExpense(['name' => 'Still pending']);

        $summary = $this->service->fundSummary($this->fund->fresh());

        $this->assertSame('4000.0000', $summary['posted_balance']);
        $this->assertSame('5000.0000', $summary['limit']);
        $this->assertSame('1000.0000', $summary['replenishment_due']);
        $this->assertSame(['pending' => 1, 'approved' => 0, 'executed' => 1, 'rejected' => 0], $summary['expenses']);
        $this->assertSame('1200.0000', $summary['spent']);
        $this->assertSame('200.0000', $summary['returned']);
    }

    // ------------------------------------------------------------------
    // Deactivate / reactivate
    // ------------------------------------------------------------------

    public function test_deactivate_with_open_expenses_is_refused(): void
    {
        $this->recordExpense();

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('open expense');

        $this->service->deactivateFund($this->fund, 1);
    }

    public function test_deactivate_and_reactivate(): void
    {
        $this->executedExpense();

        $deactivated = $this->service->deactivateFund($this->fund->fresh(), 1);
        $this->assertFalse($deactivated->isActive());

        $reactivated = $this->service->reactivateFund($this->fund->fresh(), 1);
        $this->assertTrue($reactivated->isActive());
    }

    public function test_record_expense_on_inactive_fund_is_refused(): void
    {
        $this->service->deactivateFund($this->fund->fresh(), 1);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('inactive');

        $this->recordExpense();
    }

    // ------------------------------------------------------------------
    // Company scope (CA16)
    // ------------------------------------------------------------------

    public function test_cross_company_access_is_denied(): void
    {
        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('not found for this company');

        $this->service->fundForCompany(4, $this->fund->id);
    }

    public function test_expense_for_other_company_is_denied(): void
    {
        $expense = $this->recordExpense();

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('not found for this company');

        $this->service->expenseForCompany(4, $expense->id);
    }

    // ------------------------------------------------------------------
    // Schema (mirrors migrations 110100–110200; trimmed)
    // ------------------------------------------------------------------

    protected function createTables(): void
    {
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('type', 100)->nullable();
            $table->integer('created_by')->default(0);
            $table->integer('default_company_id')->nullable();
            $table->integer('is_enable_login')->default(1);
            $table->integer('is_active')->default(1);
            $table->integer('delete_status')->default(1);
            $table->string('lang')->default('en');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('branches', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('permissions', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });

        Schema::create('model_has_permissions', function ($table) {
            $table->integer('permission_id');
            $table->string('model_type');
            $table->integer('model_id');
            $table->index(['model_id', 'model_type']);
        });

        Schema::create('bank_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->string('holder_name');
            $table->string('bank_name');
            $table->string('account_number');
            $table->integer('chart_account_id')->default(0);
            $table->float('opening_balance', 15, 2)->default(0);
            $table->string('contact_number');
            $table->text('bank_address');
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('chart_of_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->integer('code')->default(0);
            $table->integer('type')->default(0);
            $table->integer('sub_type')->default(0);
            $table->integer('is_enabled')->default(1);
            $table->text('description')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('dimension_audit_events', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 30);
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->integer('actor_id')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('journal_entries', function ($table) {
            $table->id();
            $table->date('date');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->integer('journal_id')->default(0);
            $table->unsignedTinyInteger('status')->default(0);
            $table->integer('accounting_period')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('journal_items', function ($table) {
            $table->id();
            $table->integer('journal')->default(0);
            $table->integer('account')->default(0);
            $table->text('description')->nullable();
            $table->float('debit', 15, 2)->default(0.0);
            $table->float('credit', 15, 2)->default(0.0);
            $table->timestamps();
        });

        Schema::create('transaction_lines', function ($table) {
            $table->id();
            $table->integer('account_id');
            $table->string('reference');
            $table->integer('reference_id');
            $table->integer('reference_sub_id');
            $table->date('date');
            $table->decimal('credit', 15, 2)->default('0.00');
            $table->decimal('debit', 15, 2)->default('0.00');
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('treasury_financial_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('bank_account_id');
            $table->integer('created_by');
            $table->string('code', 50);
            $table->string('type', 20)->default('bank');
            $table->string('currency', 3)->default('MZN');
            $table->boolean('currency_locked')->default(false);
            $table->bigInteger('branch_id')->nullable();
            $table->string('nib_or_iban', 50)->nullable();
            $table->bigInteger('owner_user_id')->nullable();
            $table->decimal('cash_limit', 20, 4)->nullable();
            $table->decimal('minimum_balance', 20, 4)->nullable();
            $table->string('negative_balance_policy', 20)->default('block');
            $table->decimal('negative_balance_limit', 20, 4)->nullable();
            $table->bigInteger('negative_balance_approver_user_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['bank_account_id'], 'tfa_bank_account_uq');
            $table->unique(['created_by', 'code'], 'tfa_company_code_uq');
        });

        Schema::create('treasury_opening_balances', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('financial_account_id');
            $table->integer('created_by');
            $table->string('currency', 3)->default('MZN');
            $table->decimal('amount', 20, 4)->default(0);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->date('cut_off_date');
            $table->string('batch_reference', 100);
            $table->bigInteger('operator_user_id')->nullable();
            $table->text('attachments')->nullable();
            $table->string('status', 20)->default('draft');
            $table->boolean('includes_liabilities')->default(false);
            $table->boolean('includes_receivables')->default(false);
            $table->bigInteger('reversal_of_id')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('treasury_operations', function ($table) {
            $table->bigIncrements('id');
            $table->string('reference', 60)->nullable();
            $table->string('type', 20);
            $table->integer('company_id');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('financial_account_id')->nullable();
            $table->string('counterparty_type', 20)->nullable();
            $table->bigInteger('counterparty_id')->nullable();
            $table->string('currency', 3)->default('MZN');
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->date('posting_date')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('value_date')->nullable();
            $table->string('state', 20)->default('draft');
            $table->string('source_type', 20)->nullable();
            $table->bigInteger('source_id')->nullable();
            $table->bigInteger('reversal_of_id')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->integer('created_by');
            $table->timestamps();
            $table->unique(['created_by', 'reference'], 'top_company_ref_uq');
        });

        Schema::create('treasury_entries', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('operation_id');
            $table->bigInteger('financial_account_id');
            $table->string('direction', 10);
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->bigInteger('reversal_of_id')->nullable();
            $table->bigInteger('journal_id')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('treasury_petty_cash_funds', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('custodian_user_id');
            $table->bigInteger('financial_account_id');
            $table->decimal('limit_amount', 20, 4)->nullable();
            $table->string('replenishment_frequency', 20)->default('on_demand');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['financial_account_id'], 'tpcf_account_uq');
            $table->index(['created_by', 'status'], 'tpcf_company_idx');
            $table->index(['custodian_user_id'], 'tpcf_custodian_idx');
        });

        Schema::create('expense_categories', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->bigInteger('user_id')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('cost_centres', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id')->nullable();
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('parent_id')->nullable();
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->bigInteger('manager_user_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->bigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('expenses', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->date('date');
            $table->text('description')->nullable();
            $table->decimal('amount', 20, 4)->default(0);
            $table->string('attachment')->nullable();
            $table->integer('status')->default(0);
            $table->timestamp('executed_at')->nullable();
            $table->bigInteger('executed_by')->nullable();
            $table->bigInteger('user_id')->nullable();
            $table->bigInteger('expense_category_id')->nullable();
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('pos_shift_id')->nullable();
            $table->bigInteger('payment_account_id')->nullable();
            $table->bigInteger('chart_account_id')->nullable();
            $table->integer('created_by')->default(0);
            $table->bigInteger('treasury_petty_cash_fund_id')->nullable();
            $table->bigInteger('cost_centre_id')->nullable();
            $table->decimal('treasury_petty_cash_returned_amount', 20, 4)->default(0);
            $table->timestamps();
            $table->index(['treasury_petty_cash_fund_id'], 'exp_petty_fund_idx');
            $table->index(['cost_centre_id'], 'exp_cost_centre_idx');
        });
    }
}
