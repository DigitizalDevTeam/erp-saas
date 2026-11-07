<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryReceipt;
use App\Models\User;
use App\Services\TreasuryAccountService;
use App\Services\TreasuryReceiptService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Treasury Phase 1 — receipts workflow over HTTP (plan §3, §10).
 */
class TreasuryReceiptWorkflowTest extends TestCase
{
    protected TreasuryReceiptService $service;
    protected TreasuryAccountService $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['view treasury', 'manage treasury accounts', 'reverse treasury operations', 'create treasury receipts', 'confirm treasury receipts', 'allocate treasury receipts'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Viewer', 'email' => 'viewer@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 5, 'name' => 'Other Employee', 'email' => 'otheremployee@test.local', 'type' => 'employee', 'created_by' => 4, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Maputo', 'created_by' => 1],
        ]);

        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'code' => 121, 'name' => 'Bank', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 2, 'code' => 411, 'name' => 'Customers', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 3, 'code' => 211, 'name' => 'Customer credit', 'type' => 2, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 4, 'code' => 611, 'name' => 'Bank fees and discounts', 'type' => 6, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 5, 'code' => 241, 'name' => 'Withholding tax', 'type' => 2, 'sub_type' => 1, 'created_by' => 1],
        ]);

        User::findOrFail(1)->givePermissionTo(['view treasury', 'manage treasury accounts', 'reverse treasury operations', 'create treasury receipts', 'confirm treasury receipts', 'allocate treasury receipts']);
        User::findOrFail(2)->givePermissionTo(['view treasury']);
        User::findOrFail(4)->givePermissionTo(['view treasury', 'manage treasury accounts', 'reverse treasury operations', 'create treasury receipts', 'confirm treasury receipts', 'allocate treasury receipts']);
        User::findOrFail(5)->givePermissionTo(['view treasury', 'manage treasury accounts', 'reverse treasury operations', 'create treasury receipts', 'confirm treasury receipts', 'allocate treasury receipts']);

        $this->accounts = new TreasuryAccountService();
        $this->service = new TreasuryReceiptService($this->accounts);
    }

    protected function tearDown(): void
    {
        foreach (['treasury_settlement_allocations', 'treasury_receipts', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'dimension_audit_events', 'bank_accounts', 'chart_of_accounts', 'journal_entries', 'journal_items', 'transaction_lines', 'invoices', 'invoice_products', 'invoice_payments', 'credit_notes', 'taxes', 'customers', 'transactions', 'branches', 'branch_user', 'settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

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
        Schema::create('treasury_receipts', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('operation_id');
            $table->bigInteger('customer_id')->nullable();
            $table->string('method', 20)->default('transfer');
            $table->string('external_transaction_id', 100)->nullable();
            $table->date('value_date')->nullable();
            $table->string('cheque_bank', 100)->nullable();
            $table->string('cheque_number', 50)->nullable();
            $table->decimal('fees', 20, 4)->default(0);
            $table->decimal('withholding', 20, 4)->default(0);
            $table->decimal('discount', 20, 4)->default(0);
            $table->string('state', 20)->default('draft');
            $table->bigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('receipt_number', 60)->nullable();
            $table->string('classification', 50)->nullable();
            $table->text('classification_reason')->nullable();
            $table->text('evidence')->nullable();
            $table->bigInteger('reversal_of_id')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('treasury_settlement_allocations', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('receipt_id')->nullable();
            $table->bigInteger('payment_id')->nullable();
            $table->string('target_type', 20)->default('invoice');
            $table->bigInteger('target_id');
            $table->decimal('allocated_amount', 20, 4)->default(0);
            $table->decimal('allocated_amount_base', 20, 4)->default(0);
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->string('state', 20)->default('active');
            $table->string('source', 20)->default('receipt');
            $table->bigInteger('approved_by')->nullable();
            $table->bigInteger('invoice_payment_id')->nullable();
            $table->bigInteger('journal_id')->nullable();
            $table->integer('created_by');
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
        Schema::create('invoices', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('customer_id');
            $table->date('issue_date');
            $table->date('due_date');
            $table->date('send_date')->nullable();
            $table->integer('category_id');
            $table->text('ref_number')->nullable();
            $table->integer('status')->default(0);
            $table->integer('shipping_display')->default(1);
            $table->integer('discount_apply')->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('invoice_products', function ($table) {
            $table->bigIncrements('id');
            $table->integer('invoice_id');
            $table->integer('product_id');
            $table->integer('quantity');
            $table->string('tax', 50)->nullable();
            $table->float('discount')->default('0.00');
            $table->decimal('price', 16, 2)->default('0.0');
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('invoice_payments', function ($table) {
            $table->bigIncrements('id');
            $table->integer('invoice_id');
            $table->date('date');
            $table->decimal('amount', 16, 2)->default('0.0');
            $table->integer('account_id')->default(0);
            $table->integer('payment_method')->default(0);
            $table->string('order_id')->nullable();
            $table->string('currency')->nullable();
            $table->string('txn_id')->nullable();
            $table->string('payment_type')->default('Manually');
            $table->string('receipt')->nullable();
            $table->string('add_receipt')->nullable();
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->integer('user_id')->nullable();
            $table->string('user_type')->nullable();
            $table->string('type')->nullable();
            $table->integer('payment_id')->nullable();
            $table->string('category')->nullable();
            $table->integer('account')->nullable();
            $table->timestamps();
        });
        Schema::create('credit_notes', function ($table) {
            $table->bigIncrements('id');
            $table->integer('invoice')->default(0);
            $table->integer('customer')->default(0);
            $table->decimal('amount', 15, 2)->default('0.00');
            $table->date('date');
            $table->text('description')->nullable();
            $table->integer('is_debit')->default(0);
            $table->timestamps();
        });
        Schema::create('taxes', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->float('rate')->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('customers', function ($table) {
            $table->bigIncrements('id');
            $table->integer('customer_id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('contact')->nullable();
            $table->string('avatar', 100)->default('');
            $table->integer('created_by')->default(0);
            $table->integer('is_active')->default(1);
            $table->timestamp('email_verified_at')->nullable();
            $table->decimal('balance', 15, 2)->default('0.00');
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('transactions', function ($table) {
            $table->bigIncrements('id');
            $table->integer('user_id');
            $table->string('user_type');
            $table->integer('account');
            $table->string('type')->nullable();
            $table->decimal('amount', 16, 2)->default('0.0');
            $table->text('description')->nullable();
            $table->date('date');
            $table->integer('created_by')->default(0);
            $table->integer('payment_id')->default(0);
            $table->string('category');
            $table->timestamps();
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function createAccountViaHttp(int $userId = 1, array $overrides = [])
    {
        return $this->actingAs(User::findOrFail($userId))
            ->post(route('treasury-accounts.store'), array_merge([
                'code' => 'BIM-001',
                'type' => 'bank',
                'currency' => 'MZN',
                'account_holder' => 'Hoya Hoya Lda',
                'institution' => 'BIM',
                'account_number' => '123456789',
                'chart_account_id' => 1,
                'branch_id' => 1,
                'owner_user_id' => 2,
            ], $overrides));
    }

    protected function createCustomer(int $id = 1): void
    {
        DB::table('customers')->insert([
            'id' => $id,
            'customer_id' => $id,
            'name' => 'Customer ' . $id,
            'email' => 'customer' . $id . '@test.local',
            'created_by' => 1,
            'balance' => '0.00',
        ]);
    }

    protected function createInvoice(int $customerId, string $total, int $invoiceNumber = 1): Invoice
    {
        $invoice = Invoice::create([
            'invoice_id' => $invoiceNumber,
            'customer_id' => $customerId,
            'issue_date' => '2026-11-01',
            'due_date' => '2026-11-30',
            'category_id' => 0,
            'status' => Invoice::STATUS_UNPAID,
            'created_by' => 1,
        ]);

        DB::table('invoice_products')->insert([
            'invoice_id' => $invoice->id,
            'product_id' => 1,
            'quantity' => 1,
            'tax' => '',
            'discount' => 0,
            'price' => $total,
        ]);

        return $invoice->fresh();
    }

    protected function receiptPayload(TreasuryFinancialAccount $account, array $overrides = []): array
    {
        return array_merge([
            'financial_account_id' => $account->id,
            'customer_id' => 1,
            'amount' => '10000',
            'currency' => 'MZN',
            'method' => 'transfer',
            'posting_date' => '2026-11-07',
            'effective_date' => '2026-11-07',
            'value_date' => '2026-11-07',
        ], $overrides);
    }

    protected function createReceiptViaHttp(int $userId = 1, ?TreasuryFinancialAccount $account = null, array $overrides = [])
    {
        $account ??= TreasuryFinancialAccount::where('created_by', 1)->firstOrFail();

        return $this->actingAs(User::findOrFail($userId))
            ->post(route('treasury-receipts.store'), $this->receiptPayload($account, $overrides));
    }

    protected function confirmedReceiptViaHttp(TreasuryFinancialAccount $account, array $overrides = []): TreasuryReceipt
    {
        $this->createReceiptViaHttp(1, $account, $overrides)->assertRedirect();
        $receipt = TreasuryReceipt::where('created_by', 1)->latest('id')->firstOrFail();

        $this->actingAs(User::findOrFail(1))->post(route('treasury-receipts.submit', $receipt))->assertRedirect();
        $this->actingAs(User::findOrFail(1))->post(route('treasury-receipts.confirm', $receipt))->assertRedirect();

        return $receipt->fresh();
    }

    // ------------------------------------------------------------------
    // Index + create
    // ------------------------------------------------------------------

    public function test_receipts_index_happy_path(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $this->createCustomer();
        $this->createReceiptViaHttp()->assertRedirect();

        $response = $this->actingAs(User::findOrFail(1))->get(route('treasury-receipts.index'));

        $response->assertOk();
        $response->assertSee('Draft');
        $response->assertSee('Customer 1');
    }

    public function test_create_receipt_happy_path(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $this->createCustomer();

        $this->createReceiptViaHttp()->assertRedirect();

        $receipt = TreasuryReceipt::where('created_by', 1)->firstOrFail();
        $this->assertTrue($receipt->isDraft());
        $this->assertSame('10000.0000', (string) $receipt->operation->amount_original);
        $this->assertNull($receipt->operation->reference);

        // The draft show page renders (no open invoices yet, no allocations).
        $this->actingAs(User::findOrFail(1))->get(route('treasury-receipts.show', $receipt))->assertOk();
    }

    public function test_create_receipt_permission_denied(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $this->createCustomer();

        $response = $this->createReceiptViaHttp(6);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');
        $this->assertSame(0, TreasuryReceipt::count());
    }

    public function test_receipts_index_permission_denied(): void
    {
        $response = $this->actingAs(User::findOrFail(6))->get(route('treasury-receipts.index'));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');
    }

    public function test_show_other_company_receipt_redirects_with_error_not_500(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $this->createCustomer();
        $this->createReceiptViaHttp()->assertRedirect();
        $receipt = TreasuryReceipt::where('created_by', 1)->firstOrFail();

        $response = $this->actingAs(User::findOrFail(4))->get(route('treasury-receipts.show', $receipt));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Receipt not found for this company.');
    }

    // ------------------------------------------------------------------
    // Full workflow over HTTP (CA01)
    // ------------------------------------------------------------------

    public function test_full_workflow_create_submit_confirm_allocate(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $account = TreasuryFinancialAccount::where('created_by', 1)->firstOrFail();
        $this->createCustomer();
        $invoice1 = $this->createInvoice(1, '6000.00', 1);
        $invoice2 = $this->createInvoice(1, '3000.00', 2);

        $receipt = $this->confirmedReceiptViaHttp($account);

        $this->assertTrue($receipt->isConfirmed());
        $this->assertSame('COMP-RCT-2026-0001', $receipt->receipt_number);
        $this->assertSame('10000.0000', $this->accounts->postedBalance($account->fresh()));

        $response = $this->actingAs(User::findOrFail(1))->post(route('treasury-receipts.allocate', $receipt), [
            'allocations' => [
                ['invoice_id' => $invoice1->id, 'amount' => '6000'],
                ['invoice_id' => $invoice2->id, 'amount' => '3000'],
            ],
        ]);

        $response->assertRedirect(route('treasury-receipts.show', $receipt));
        $response->assertSessionHas('success');

        $this->assertSame(Invoice::STATUS_PAID, (int) $invoice1->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, (int) $invoice2->fresh()->status);
        $this->assertSame('1000.0000', $this->service->remainingUnapplied($receipt->fresh()));

        // The show page renders the allocations and the unapplied credit.
        $show = $this->actingAs(User::findOrFail(1))->get(route('treasury-receipts.show', $receipt));
        $show->assertOk();
        $show->assertSee('COMP-RCT-2026-0001');
        $show->assertSee('1,000.00');
    }

    public function test_allocate_over_invoice_debt_redirects_with_error(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $account = TreasuryFinancialAccount::where('created_by', 1)->firstOrFail();
        $this->createCustomer();
        $invoice = $this->createInvoice(1, '5000.00');

        $receipt = $this->confirmedReceiptViaHttp($account);

        $response = $this->actingAs(User::findOrFail(1))->post(route('treasury-receipts.allocate', $receipt), [
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => '6000'],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(Invoice::STATUS_UNPAID, (int) $invoice->fresh()->status);
    }

    public function test_reject_path_via_http_posts_nothing(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $account = TreasuryFinancialAccount::where('created_by', 1)->firstOrFail();
        $this->createCustomer();

        $this->createReceiptViaHttp()->assertRedirect();
        $receipt = TreasuryReceipt::where('created_by', 1)->firstOrFail();

        $this->actingAs(User::findOrFail(1))->post(route('treasury-receipts.submit', $receipt))->assertRedirect();

        $response = $this->actingAs(User::findOrFail(1))->post(route('treasury-receipts.reject', $receipt), [
            'reason' => 'Bank statement does not match',
        ]);

        $response->assertRedirect(route('treasury-receipts.show', $receipt));
        $this->assertSame('rejected', $receipt->fresh()->state);
        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->assertSame('0.0000', $this->accounts->postedBalance($account->fresh()));
    }

    public function test_reverse_via_http_reopens_invoice(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $account = TreasuryFinancialAccount::where('created_by', 1)->firstOrFail();
        $this->createCustomer();
        $invoice = $this->createInvoice(1, '6000.00');

        $receipt = $this->confirmedReceiptViaHttp($account);

        $this->actingAs(User::findOrFail(1))->post(route('treasury-receipts.allocate', $receipt), [
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => '6000'],
            ],
        ])->assertRedirect();
        $this->assertSame(Invoice::STATUS_PAID, (int) $invoice->fresh()->status);

        $response = $this->actingAs(User::findOrFail(1))->post(route('treasury-receipts.reverse', $receipt), [
            'reason' => 'Bank returned the transfer',
        ]);

        // The reverse lands on the NEW reversal receipt's page.
        $reversal = TreasuryReceipt::where('reversal_of_id', $receipt->id)->firstOrFail();
        $response->assertRedirect(route('treasury-receipts.show', $reversal));
        $this->assertSame('reversed', $receipt->fresh()->state);
        $this->assertSame(Invoice::STATUS_UNPAID, (int) $invoice->fresh()->status);
        $this->assertSame('0.0000', $this->accounts->postedBalance($account->fresh()));
    }

    public function test_receipt_pdf_downloads_for_confirmed_receipt(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $account = TreasuryFinancialAccount::where('created_by', 1)->firstOrFail();
        $this->createCustomer();

        $receipt = $this->confirmedReceiptViaHttp($account);

        $response = $this->actingAs(User::findOrFail(1))->get(route('treasury-receipts.pdf', $receipt));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    public function test_apply_unapplied_credit_via_http(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $account = TreasuryFinancialAccount::where('created_by', 1)->firstOrFail();
        $this->createCustomer();
        $invoiceA = $this->createInvoice(1, '6000.00', 1);
        $invoiceB = $this->createInvoice(1, '4000.00', 2);

        $receipt = $this->confirmedReceiptViaHttp($account);

        $this->actingAs(User::findOrFail(1))->post(route('treasury-receipts.allocate', $receipt), [
            'allocations' => [
                ['invoice_id' => $invoiceA->id, 'amount' => '6000'],
            ],
        ])->assertRedirect();

        $response = $this->actingAs(User::findOrFail(1))->post(route('treasury-receipts.apply-credit', $receipt), [
            'invoice_id' => $invoiceB->id,
            'amount' => '4000',
        ]);

        $response->assertRedirect(route('treasury-receipts.show', $receipt));
        $this->assertSame(Invoice::STATUS_PAID, (int) $invoiceB->fresh()->status);
        $this->assertSame('10000.0000', $this->accounts->postedBalance($account->fresh()));
        $this->assertSame(1, DB::table('treasury_entries')->count());
    }
}
