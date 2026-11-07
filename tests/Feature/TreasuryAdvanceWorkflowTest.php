<?php

namespace Tests\Feature;

use App\Models\TreasuryAdvance;
use App\Models\TreasuryAdvanceExpenseLine;
use App\Models\TreasuryFinancialAccount;
use App\Models\User;
use App\Services\TreasuryAccountService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Treasury Phase 6 — advances over HTTP (plan §5, §6, §10, CA10).
 *
 * Users: 1 = company, 2 = requester, 3 = approver, 4 = other
 * company, 6 = no permission. The source account is funded with
 * 200,000 MZN; chart accounts are 121 (source bank), 4512
 * (employee advance asset), 429 (supplier advance asset), 61 and
 * 62 (expense accounts).
 */
class TreasuryAdvanceWorkflowTest extends TestCase
{
    protected TreasuryAccountService $accounts;
    protected TreasuryFinancialAccount $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['view treasury', 'manage treasury advances'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Requester', 'email' => 'requester@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Maputo', 'created_by' => 1],
        ]);

        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'code' => 121, 'name' => 'Bank source', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 2, 'code' => 4512, 'name' => 'Advances to employees', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 3, 'code' => 429, 'name' => 'Advances to suppliers', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 4, 'code' => 61, 'name' => 'Travel expenses', 'type' => 6, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 5, 'code' => 62, 'name' => 'Meals', 'type' => 6, 'sub_type' => 1, 'created_by' => 1],
        ]);

        DB::table('employees')->insert([
            ['id' => 1, 'name' => 'Ana', 'created_by' => 1],
        ]);
        DB::table('venders')->insert([
            ['id' => 1, 'name' => 'Fornecedor X', 'created_by' => 1],
        ]);
        DB::table('cost_centres')->insert([
            ['id' => 1, 'company_id' => 1, 'created_by' => 1, 'code' => 'CC-OPS', 'name' => 'Operations', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['view treasury', 'manage treasury advances']);
        User::findOrFail(2)->givePermissionTo(['view treasury', 'manage treasury advances']);
        User::findOrFail(3)->givePermissionTo(['view treasury', 'manage treasury advances']);
        User::findOrFail(4)->givePermissionTo(['view treasury', 'manage treasury advances']);

        $this->accounts = new TreasuryAccountService();
        $this->source = $this->makeAccount('200000.0000', 'BIM-001');
    }

    protected function tearDown(): void
    {
        foreach (['treasury_advance_expense_lines', 'treasury_advances', 'treasury_line_dimensions', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'dimension_audit_events', 'bank_accounts', 'chart_of_accounts', 'cost_centres', 'venders', 'employees', 'branches', 'branch_user', 'settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates', 'journal_entries', 'journal_items', 'transaction_lines', 'transactions'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function makeAccount(string $balance, string $code): TreasuryFinancialAccount
    {
        $bankId = DB::table('bank_accounts')->insertGetId([
            'holder_name' => 'Hoya Hoya Lda', 'bank_name' => 'BIM', 'account_number' => '123456789',
            'chart_account_id' => 1, 'opening_balance' => 0, 'contact_number' => '', 'bank_address' => '',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $account = TreasuryFinancialAccount::create([
            'bank_account_id' => $bankId, 'created_by' => 1, 'code' => $code,
            'type' => 'bank', 'currency' => 'MZN', 'status' => 'active',
            'negative_balance_policy' => 'block',
        ]);

        DB::table('treasury_opening_balances')->insert([
            'financial_account_id' => $account->id, 'created_by' => 1, 'currency' => 'MZN',
            'amount' => $balance, 'amount_base' => $balance, 'cut_off_date' => '2026-11-01',
            'batch_reference' => 'BATCH-' . $code, 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $account->fresh();
    }

    protected function createAdvanceViaHttp(int $userId = 2, array $overrides = [])
    {
        return $this->actingAs(User::findOrFail($userId))
            ->post(route('treasury-advances.store'), array_merge([
                'source_account_id' => $this->source->id,
                'recipient_type' => 'employee',
                'employee_id' => 1,
                'purpose' => 'Travel to Beira',
                'amount_requested' => '5000',
                'due_date' => date('Y-m-d', strtotime('+14 days')),
            ], $overrides));
    }

    /** Drive an advance to disbursed over HTTP. Returns the advance. */
    protected function makeDisbursedAdvance(): TreasuryAdvance
    {
        $this->createAdvanceViaHttp(2)->assertRedirect();
        $advance = TreasuryAdvance::firstOrFail();

        $this->actingAs(User::findOrFail(2))->post(route('treasury-advances.submit', $advance))->assertRedirect();
        $this->actingAs(User::findOrFail(3))->post(route('treasury-advances.approve', $advance))->assertRedirect();
        $this->actingAs(User::findOrFail(3))->post(route('treasury-advances.disburse', $advance), [
            'posting_date' => date('Y-m-d'),
        ])->assertRedirect();

        return $advance->fresh();
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function test_full_advance_lifecycle_over_http(): void
    {
        // Request (draft) → submit → approve → disburse.
        $this->createAdvanceViaHttp(2)->assertRedirect();
        $advance = TreasuryAdvance::firstOrFail();
        $this->assertSame('draft', $advance->state);
        $this->assertSame('Travel to Beira', $advance->purpose);

        $this->actingAs(User::findOrFail(2))
            ->post(route('treasury-advances.submit', $advance))
            ->assertRedirect();
        $this->assertSame('pending_approval', $advance->fresh()->state);

        $this->actingAs(User::findOrFail(3))
            ->post(route('treasury-advances.approve', $advance))
            ->assertRedirect();
        $this->assertSame('approved', $advance->fresh()->state);

        $this->actingAs(User::findOrFail(3))
            ->get(route('treasury-advances.disburse-form', $advance))
            ->assertOk();
        $this->actingAs(User::findOrFail(3))
            ->post(route('treasury-advances.disburse', $advance), ['posting_date' => date('Y-m-d')])
            ->assertRedirect();
        $advance = $advance->fresh();
        $this->assertSame('disbursed', $advance->state);
        $this->assertSame('COMP-ADV-2026-0001', $advance->operation->reference);
        $this->assertSame('5000.0000', (string) $advance->amount_outstanding);

        // Expense line → approve (Dr 61 / Cr 4512, 4,200).
        $this->actingAs(User::findOrFail(2))
            ->post(route('treasury-advances.add-line', $advance), [
                'expense_date' => date('Y-m-d'),
                'counterparty' => 'Hotel Beira',
                'category' => 'Travel',
                'document' => 'INV-001',
                'amount' => '4200',
                'chart_account_id' => 4,
                'cost_centre_id' => 1,
                'evidence' => 'Receipt attached',
            ])->assertRedirect();
        $line = TreasuryAdvanceExpenseLine::firstOrFail();
        $this->assertSame('pending', $line->state);

        $this->actingAs(User::findOrFail(3))
            ->post(route('treasury-advances.review-line', $line), ['decision' => 'approve'])
            ->assertRedirect();
        $this->assertSame('approved', $line->fresh()->state);
        $advance = $advance->fresh();
        $this->assertSame('4200.0000', (string) $advance->amount_substantiated);
        $this->assertSame('800.0000', (string) $advance->amount_outstanding);

        // CA10: return the 800 → regularised, then close.
        $this->actingAs(User::findOrFail(2))
            ->post(route('treasury-advances.return-unspent', $advance), [
                'amount' => '800',
                'reason' => 'Unspent travel money',
                'posting_date' => date('Y-m-d'),
            ])->assertRedirect();
        $advance = $advance->fresh();
        $this->assertSame('0.0000', (string) $advance->amount_outstanding);
        $this->assertSame('regularised', $advance->state);

        $this->actingAs(User::findOrFail(3))
            ->post(route('treasury-advances.close', $advance))
            ->assertRedirect();
        $this->assertSame('closed', $advance->fresh()->state);

        // Source balance: 200,000 − 5,000 + 800 = 195,800.
        $this->assertSame('195800.0000', $this->accounts->postedBalance($this->source->fresh()));
    }

    public function test_index_and_show_pages_render(): void
    {
        $advance = $this->makeDisbursedAdvance();

        $this->actingAs(User::findOrFail(1))
            ->get(route('treasury-advances.index'))
            ->assertOk()
            ->assertSee('COMP-ADV-2026-0001');

        $this->actingAs(User::findOrFail(1))
            ->get(route('treasury-advances.show', $advance))
            ->assertOk()
            ->assertSee('COMP-ADV-2026-0001')
            ->assertSee('Ana');
    }

    public function test_permission_denial(): void
    {
        $this->actingAs(User::findOrFail(6))
            ->get(route('treasury-advances.index'))
            ->assertRedirect()
            ->assertSessionHas('error', 'Permission denied.');

        $this->createAdvanceViaHttp(2)->assertRedirect();
        $advance = TreasuryAdvance::firstOrFail();

        $this->actingAs(User::findOrFail(6))
            ->post(route('treasury-advances.submit', $advance))
            ->assertRedirect()
            ->assertSessionHas('error', 'Permission denied.');
        $this->assertSame('draft', $advance->fresh()->state);
    }

    public function test_cross_company_access_is_denied(): void
    {
        $advance = $this->makeDisbursedAdvance();

        $this->actingAs(User::findOrFail(4))
            ->get(route('treasury-advances.show', $advance))
            ->assertRedirect()
            ->assertSessionHas('error', 'Advance not found for this company.');
    }

    public function test_reject_and_return_to_draft_over_http(): void
    {
        $this->createAdvanceViaHttp(2)->assertRedirect();
        $advance = TreasuryAdvance::firstOrFail();
        $this->actingAs(User::findOrFail(2))->post(route('treasury-advances.submit', $advance))->assertRedirect();

        $this->actingAs(User::findOrFail(3))
            ->post(route('treasury-advances.reject', $advance), ['reason' => 'Purpose is unclear.'])
            ->assertRedirect();
        $advance = $advance->fresh();
        $this->assertSame('rejected', $advance->state);
        $this->assertSame('Purpose is unclear.', $advance->rejection_reason);

        $this->actingAs(User::findOrFail(2))
            ->post(route('treasury-advances.return-to-draft', $advance))
            ->assertRedirect();
        $advance = $advance->fresh();
        $this->assertSame('draft', $advance->state);
        $this->assertNull($advance->rejection_reason);
    }

    public function test_self_approval_over_http_is_refused(): void
    {
        $this->createAdvanceViaHttp(2)->assertRedirect();
        $advance = TreasuryAdvance::firstOrFail();
        $this->actingAs(User::findOrFail(2))->post(route('treasury-advances.submit', $advance))->assertRedirect();

        $this->actingAs(User::findOrFail(2))
            ->post(route('treasury-advances.approve', $advance))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertSame('pending_approval', $advance->fresh()->state);
    }

    public function test_line_return_edit_resubmit_over_http(): void
    {
        $advance = $this->makeDisbursedAdvance();

        $this->actingAs(User::findOrFail(2))
            ->post(route('treasury-advances.add-line', $advance), [
                'expense_date' => date('Y-m-d'),
                'counterparty' => 'Hotel Beira',
                'amount' => '1000',
                'chart_account_id' => 4,
            ])->assertRedirect();
        $line = TreasuryAdvanceExpenseLine::firstOrFail();

        $this->actingAs(User::findOrFail(3))
            ->post(route('treasury-advances.review-line', $line), ['decision' => 'return', 'reason' => 'Fix the amount.'])
            ->assertRedirect();
        $this->assertSame('returned', $line->fresh()->state);

        $this->actingAs(User::findOrFail(2))
            ->get(route('treasury-advances.line-form', $line))
            ->assertOk();
        $this->actingAs(User::findOrFail(2))
            ->put(route('treasury-advances.update-line', $line), [
                'expense_date' => date('Y-m-d'),
                'counterparty' => 'Hotel Beira',
                'amount' => '1500',
                'chart_account_id' => 4,
            ])->assertRedirect();
        $this->assertSame('1500.0000', (string) $line->fresh()->amount);

        $this->actingAs(User::findOrFail(2))
            ->post(route('treasury-advances.resubmit-line', $line))
            ->assertRedirect();
        $this->assertSame('pending', $line->fresh()->state);

        $this->actingAs(User::findOrFail(3))
            ->post(route('treasury-advances.review-line', $line), ['decision' => 'approve'])
            ->assertRedirect();
        $this->assertSame('approved', $line->fresh()->state);
        $this->assertSame('1500.0000', (string) $advance->fresh()->amount_substantiated);
    }

    // ------------------------------------------------------------------
    // Tables
    // ------------------------------------------------------------------

    protected function createTables(): void
    {
        Schema::create('branches', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('branch_user', function ($table) {
            $table->bigIncrements('id');
            $table->integer('branch_id');
            $table->integer('user_id');
            $table->timestamps();
        });
        Schema::create('settings', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
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
        Schema::create('roles', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
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
        Schema::create('model_has_roles', function ($table) {
            $table->integer('role_id');
            $table->string('model_type');
            $table->integer('model_id');
            $table->index(['model_id', 'model_type']);
        });
        Schema::create('role_has_permissions', function ($table) {
            $table->integer('permission_id');
            $table->integer('role_id');
            $table->index(['role_id', 'permission_id']);
        });
        Schema::create('plans', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('languages', function ($table) {
            $table->increments('id');
            $table->string('code')->nullable();
            $table->string('full_name')->nullable();
            $table->timestamps();
        });
        Schema::create('ch_messages', function ($table) {
            $table->increments('id');
            $table->integer('to_id')->nullable();
            $table->integer('seen')->default(0);
            $table->timestamps();
        });
        Schema::create('email_templates', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('from')->nullable();
            $table->string('slug')->nullable();
            $table->integer('created_by');
            $table->timestamps();
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
        Schema::create('venders', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->default(0);
            $table->decimal('balance', 20, 4)->default(0);
            $table->timestamps();
        });
        Schema::create('employees', function ($table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->default(0);
            $table->string('name')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('cost_centres', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('code', 50);
            $table->string('name');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('treasury_line_dimensions', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('payment_type', 20);
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->unsignedBigInteger('cost_centre_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->decimal('allocated_amount', 18, 2);
            $table->decimal('percentage', 9, 6)->nullable();
            $table->string('source', 20)->default('inherited');
            $table->string('status', 20)->default('active');
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
        Schema::create('transactions', function ($table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->nullable();
            $table->string('user_type')->nullable();
            $table->integer('account')->nullable();
            $table->string('type')->nullable();
            $table->decimal('amount', 16, 2)->default('0.0');
            $table->text('description')->nullable();
            $table->date('date')->nullable();
            $table->integer('created_by')->default(0);
            $table->integer('payment_id')->default(0);
            $table->string('category')->nullable();
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
        Schema::create('treasury_advances', function ($table) {
            $table->id();
            $table->bigInteger('operation_id')->nullable();
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('source_account_id');
            $table->string('recipient_type', 20);
            $table->bigInteger('recipient_id');
            $table->string('recipient_name', 150)->nullable();
            $table->text('purpose')->nullable();
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->decimal('amount_requested', 20, 4)->default(0);
            $table->decimal('amount_requested_base', 20, 4)->default(0);
            $table->decimal('amount_approved', 20, 4)->nullable();
            $table->decimal('amount_approved_base', 20, 4)->nullable();
            $table->decimal('amount_paid', 20, 4)->default(0);
            $table->decimal('amount_paid_base', 20, 4)->default(0);
            $table->decimal('amount_substantiated', 20, 4)->default(0);
            $table->decimal('amount_substantiated_base', 20, 4)->default(0);
            $table->decimal('amount_returned', 20, 4)->default(0);
            $table->decimal('amount_returned_base', 20, 4)->default(0);
            $table->decimal('amount_outstanding', 20, 4)->default(0);
            $table->decimal('amount_outstanding_base', 20, 4)->default(0);
            $table->decimal('reimbursement_amount', 20, 4)->default(0);
            $table->decimal('reimbursement_amount_base', 20, 4)->default(0);
            $table->string('reimbursement_state', 20)->default('none');
            $table->bigInteger('reimbursement_approved_by')->nullable();
            $table->timestamp('reimbursement_approved_at')->nullable();
            $table->timestamp('reimbursement_paid_at')->nullable();
            $table->bigInteger('reimbursement_journal_id')->nullable();
            $table->bigInteger('reimbursement_operation_id')->nullable();
            $table->text('reimbursement_reason')->nullable();
            $table->date('request_date')->nullable();
            $table->date('due_date')->nullable();
            $table->integer('requested_by');
            $table->integer('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->bigInteger('disbursement_journal_id')->nullable();
            $table->string('state', 20)->default('draft');
            $table->text('close_reason')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->integer('closed_by')->nullable();
            $table->timestamps();
            $table->index(['created_by', 'state'], 'ta_company_state_idx');
            $table->index(['source_account_id'], 'ta_source_idx');
            $table->index(['recipient_type', 'recipient_id'], 'ta_recipient_idx');
            $table->index(['operation_id'], 'ta_operation_idx');
        });
        Schema::create('treasury_advance_expense_lines', function ($table) {
            $table->id();
            $table->bigInteger('advance_id');
            $table->date('expense_date');
            $table->string('counterparty', 150)->nullable();
            $table->string('category', 100)->nullable();
            $table->string('document', 150)->nullable();
            $table->decimal('amount', 20, 4)->default(0);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->bigInteger('chart_account_id');
            $table->bigInteger('cost_centre_id')->nullable();
            $table->text('evidence')->nullable();
            $table->string('state', 20)->default('pending');
            $table->integer('submitted_by');
            $table->integer('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->bigInteger('journal_id')->nullable();
            $table->bigInteger('operation_id')->nullable();
            $table->decimal('reimbursement_amount', 20, 4)->default(0);
            $table->decimal('reimbursement_amount_base', 20, 4)->default(0);
            $table->timestamps();
            $table->index(['advance_id'], 'tael_advance_idx');
            $table->index(['advance_id', 'state'], 'tael_adv_state_idx');
            $table->index(['cost_centre_id'], 'tael_centre_idx');
        });
    }
}
