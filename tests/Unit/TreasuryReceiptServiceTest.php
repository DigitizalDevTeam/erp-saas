<?php

namespace Tests\Unit;

use App\Exceptions\TreasuryException;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryReceipt;
use App\Services\TreasuryAccountService;
use App\Services\TreasuryReceiptService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Treasury Phase 1 — receipts + allocations (plan §10 exit gates).
 *
 * CA01 receive 10,000 / apply 6,000 + 3,000 / balance +10,000 /
 * unapplied credit 1,000. CA02 concurrent settlements recheck.
 * CA04 pending evidence changes nothing. CA15 reversal reopens.
 * CA19 historical rates preserved.
 */
class TreasuryReceiptServiceTest extends TestCase
{
    protected TreasuryReceiptService $service;
    protected TreasuryAccountService $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Manager', 'email' => 'manager@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
        ]);

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Maputo', 'created_by' => 1],
        ]);

        // Chart accounts (integer codes, as in the real migration):
        // 121 bank, 411 receivables, 211 customer credit, 611 fees/discount, 241 withholding.
        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'code' => 121, 'name' => 'Bank', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 2, 'code' => 411, 'name' => 'Customers', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 3, 'code' => 211, 'name' => 'Customer credit', 'type' => 2, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 4, 'code' => 611, 'name' => 'Bank fees and discounts', 'type' => 6, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 5, 'code' => 241, 'name' => 'Withholding tax', 'type' => 2, 'sub_type' => 1, 'created_by' => 1],
        ]);

        $this->accounts = new TreasuryAccountService();
        $this->service = new TreasuryReceiptService($this->accounts);
    }

    protected function tearDown(): void
    {
        foreach (['treasury_settlement_allocations', 'treasury_receipts', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'dimension_audit_events', 'bank_accounts', 'chart_of_accounts', 'journal_entries', 'journal_items', 'transaction_lines', 'invoices', 'invoice_products', 'invoice_payments', 'credit_notes', 'taxes', 'customers', 'transactions', 'branches', 'settings', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

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

    protected function createAccount(int $creatorId = 1, array $overrides = []): TreasuryFinancialAccount
    {
        return $this->accounts->createAccount($creatorId, array_merge([
            'code' => 'BIM-001',
            'type' => 'bank',
            'currency' => 'MZN',
            'account_holder' => 'Hoya Hoya Lda',
            'institution' => 'BIM',
            'account_number' => '123456789',
            'chart_account_id' => 1,
            'branch_id' => 1,
            'owner_user_id' => 2,
        ], $overrides), 1);
    }

    protected function createCustomer(int $creatorId = 1, int $id = 1): Customer
    {
        return Customer::create([
            'id' => $id,
            'customer_id' => $id,
            'name' => 'Customer ' . $id,
            'email' => 'customer' . $id . '@test.local',
            'created_by' => $creatorId,
            'balance' => '0.00',
        ]);
    }

    protected function createInvoice(int $customerId, string $total, int $invoiceNumber = 1, int $creatorId = 1): Invoice
    {
        $invoice = Invoice::create([
            'invoice_id' => $invoiceNumber,
            'customer_id' => $customerId,
            'issue_date' => '2026-11-01',
            'due_date' => '2026-11-30',
            'category_id' => 0,
            'status' => Invoice::STATUS_UNPAID,
            'created_by' => $creatorId,
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

    protected function receiptData(TreasuryFinancialAccount $account, array $overrides = []): array
    {
        return array_merge([
            'financial_account_id' => $account->id,
            'customer_id' => 1,
            'amount' => '10000',
            'currency' => $account->currency,
            'method' => 'transfer',
            'posting_date' => '2026-11-07',
            'effective_date' => '2026-11-07',
            'value_date' => '2026-11-07',
        ], $overrides);
    }

    protected function confirmedReceipt(TreasuryFinancialAccount $account, array $overrides = [], int $actorId = 1): TreasuryReceipt
    {
        $receipt = $this->service->createReceipt(1, $this->receiptData($account, $overrides), $actorId);
        $receipt = $this->service->submitReceipt($receipt, $actorId);

        return $this->service->confirmReceipt($receipt, $actorId);
    }

    protected function journalItemsForAccount(int $journalId, int $accountId): array
    {
        return DB::table('journal_items')
            ->where('journal', $journalId)
            ->where('account', $accountId)
            ->get()
            ->all();
    }

    // ------------------------------------------------------------------
    // References + workflow
    // ------------------------------------------------------------------

    public function test_draft_has_no_reference_and_submit_allocates_one(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();

        $receipt = $this->service->createReceipt(1, $this->receiptData($account), 1);

        $this->assertTrue($receipt->isDraft());
        $this->assertNull($receipt->operation->reference);
        $this->assertNull($receipt->receipt_number);

        $submitted = $this->service->submitReceipt($receipt, 1);

        $this->assertTrue($submitted->isPending());
        $this->assertSame('COMP-RCT-2026-0001', $submitted->operation->reference);
    }

    public function test_cancelled_draft_never_consumes_a_reference_number(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();

        $first = $this->service->createReceipt(1, $this->receiptData($account), 1);
        $this->service->cancelReceipt($first, 'Customer changed mind', 1);

        $second = $this->service->createReceipt(1, $this->receiptData($account), 1);
        $second = $this->service->submitReceipt($second, 1);

        // The cancelled draft took no number: the next submit is -0001.
        $this->assertSame('COMP-RCT-2026-0001', $second->operation->reference);

        $third = $this->service->createReceipt(1, $this->receiptData($account), 1);
        $third = $this->service->submitReceipt($third, 1);
        $this->assertSame('COMP-RCT-2026-0002', $third->operation->reference);
    }

    public function test_receipt_without_customer_needs_classification_and_reason(): void
    {
        $account = $this->createAccount();

        $this->expectException(TreasuryException::class);
        $this->service->createReceipt(1, $this->receiptData($account, [
            'customer_id' => null,
        ]), 1);
    }

    public function test_receipt_without_customer_with_classification_is_allowed(): void
    {
        $account = $this->createAccount();

        $receipt = $this->service->createReceipt(1, $this->receiptData($account, [
            'customer_id' => null,
            'classification' => 'other_income',
            'classification_reason' => 'Found cash in the safe',
        ]), 1);

        $this->assertTrue($receipt->isDraft());
        $this->assertSame('other_income', $receipt->classification);
    }

    public function test_cheque_receipt_needs_bank_and_number(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();

        $this->expectException(TreasuryException::class);
        $this->service->createReceipt(1, $this->receiptData($account, ['method' => 'cheque']), 1);
    }

    public function test_receipt_currency_must_match_account_currency(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();

        $this->expectException(TreasuryException::class);
        $this->service->createReceipt(1, $this->receiptData($account, ['currency' => 'USD']), 1);
    }

    public function test_confirm_only_from_pending_and_receipt_number_issued_on_confirm(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();

        $receipt = $this->service->createReceipt(1, $this->receiptData($account), 1);

        $this->expectException(TreasuryException::class);
        $this->service->confirmReceipt($receipt, 1);
    }

    public function test_confirm_issues_receipt_number_and_creates_entry_and_journal(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();

        $receipt = $this->confirmedReceipt($account);

        $this->assertTrue($receipt->isConfirmed());
        $this->assertSame('COMP-RCT-2026-0001', $receipt->receipt_number);
        $this->assertSame(1, (int) $receipt->confirmed_by);
        $this->assertNotNull($receipt->confirmed_at);

        $entry = DB::table('treasury_entries')->where('operation_id', $receipt->operation_id)->first();
        $this->assertNotNull($entry);
        $this->assertSame('inflow', $entry->direction);
        $this->assertSame('10000.0000', number_format((float) $entry->amount_base, 4, '.', ''));
        $this->assertNotNull($entry->journal_id);

        $this->assertSame('10000.0000', $this->accounts->postedBalance($account->fresh()));
        $this->assertSame('10000.0000', $this->accounts->availableBalance($account->fresh()));
    }

    public function test_confirm_requires_chart_account_on_financial_account(): void
    {
        $account = $this->createAccount(1, ['chart_account_id' => 0]);
        $this->createCustomer();

        $receipt = $this->service->createReceipt(1, $this->receiptData($account), 1);
        $receipt = $this->service->submitReceipt($receipt, 1);

        $this->expectException(TreasuryException::class);
        $this->service->confirmReceipt($receipt, 1);
    }

    public function test_confirm_with_fees_posts_separate_fee_lines_and_net_cash(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();

        $receipt = $this->confirmedReceipt($account, [
            'fees' => '200',
            'withholding' => '100',
            'discount' => '50',
        ]);

        $entry = DB::table('treasury_entries')->where('operation_id', $receipt->operation_id)->first();
        $journalId = (int) $entry->journal_id;

        // Bank credited with net cash 10,000 - 200 - 100 = 9,700.
        $bank = $this->journalItemsForAccount($journalId, 1);
        $this->assertCount(1, $bank);
        $this->assertEquals(9700, (float) $bank[0]->debit);
        $this->assertEquals(0, (float) $bank[0]->credit);

        // Fee + discount share chart 611 but are separate journal lines.
        $feeLines = $this->journalItemsForAccount($journalId, 4);
        $this->assertCount(2, $feeLines);
        $debits = array_map(fn ($row) => (float) $row->debit, $feeLines);
        sort($debits);
        $this->assertSame([50.0, 200.0], $debits);

        $withholding = $this->journalItemsForAccount($journalId, 5);
        $this->assertCount(1, $withholding);
        $this->assertEquals(100, (float) $withholding[0]->debit);

        // Customer credit liability: gross + discount = 10,050.
        $credit = $this->journalItemsForAccount($journalId, 3);
        $this->assertCount(1, $credit);
        $this->assertEquals(10050, (float) $credit[0]->credit);

        // The entry (and therefore the posted balance) is net cash.
        $this->assertSame('9700.0000', number_format((float) $entry->amount_base, 4, '.', ''));
        $this->assertSame('9700.0000', $this->accounts->postedBalance($account->fresh()));

        // Settlement value is gross + discount, so the full 10,050 is applicable.
        $this->assertSame('10050.0000', $this->service->remainingUnapplied($receipt->fresh()));
    }

    public function test_reject_needs_reason_and_posts_nothing(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();

        $receipt = $this->service->createReceipt(1, $this->receiptData($account), 1);
        $receipt = $this->service->submitReceipt($receipt, 1);

        $this->expectException(TreasuryException::class);
        $this->service->rejectReceipt($receipt, '', 1);
    }

    public function test_reject_path_keeps_money_untouched(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();
        $invoice = $this->createInvoice(1, '6000.00');

        $receipt = $this->service->createReceipt(1, $this->receiptData($account), 1);
        $receipt = $this->service->submitReceipt($receipt, 1);
        $receipt = $this->service->rejectReceipt($receipt, 'Bank statement does not match', 1);

        $this->assertSame('rejected', $receipt->state);
        $this->assertSame('0.0000', $this->accounts->postedBalance($account->fresh()));
        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->assertSame('6000.0000', $this->service->invoiceDue($invoice->fresh()));
    }

    public function test_update_only_drafts(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();

        $receipt = $this->confirmedReceipt($account);

        $this->expectException(TreasuryException::class);
        $this->service->updateReceipt($receipt, ['amount' => '5000'], 1);
    }

    // ------------------------------------------------------------------
    // CA04 — evidence on a pending receipt changes nothing
    // ------------------------------------------------------------------

    public function test_ca04_evidence_on_pending_receipt_changes_neither_cash_nor_debt(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();
        $invoice = $this->createInvoice(1, '6000.00');

        $receipt = $this->service->createReceipt(1, $this->receiptData($account), 1);
        $receipt = $this->service->submitReceipt($receipt, 1);
        $receipt = $this->service->attachEvidence($receipt, 'Bank slip #4412 scanned', 1);

        $this->assertSame('Bank slip #4412 scanned', $receipt->evidence);
        $this->assertSame('0.0000', $this->accounts->postedBalance($account->fresh()));
        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->assertSame(0, DB::table('treasury_entries')->count());
        $this->assertSame('6000.0000', $this->service->invoiceDue($invoice->fresh()));
    }

    // ------------------------------------------------------------------
    // CA01 — the exit-gate example
    // ------------------------------------------------------------------

    public function test_ca01_receive_10000_apply_6000_and_3000_balance_10000_unapplied_1000(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();
        $invoice1 = $this->createInvoice(1, '6000.00', 1);
        $invoice2 = $this->createInvoice(1, '3000.00', 2);

        $receipt = $this->confirmedReceipt($account);

        $allocations = $this->service->allocate($receipt, [
            ['invoice_id' => $invoice1->id, 'amount' => '6000'],
            ['invoice_id' => $invoice2->id, 'amount' => '3000'],
        ], 1);

        $this->assertCount(2, $allocations);
        $this->assertSame('10000.0000', $this->accounts->postedBalance($account->fresh()));
        $this->assertSame('1000.0000', $this->service->remainingUnapplied($receipt->fresh()));
        $this->assertSame('1000.0000', $this->service->unappliedCredit(1, 1, 'MZN'));

        $this->assertSame(Invoice::STATUS_PAID, (int) $invoice1->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, (int) $invoice2->fresh()->status);
        $this->assertSame('0.0000', $this->service->invoiceDue($invoice1->fresh()));
        $this->assertSame('0.0000', $this->service->invoiceDue($invoice2->fresh()));

        // Allocation journal: credit liability -> AR (411).
        $allocation = $allocations[0];
        $items = DB::table('journal_items')->where('journal', $allocation->journal_id)->get();
        $this->assertCount(2, $items);
        $byAccount = [];
        foreach ($items as $item) {
            $byAccount[(int) $item->account] = $item;
        }
        $this->assertEquals(6000, (float) $byAccount[3]->debit); // customer credit
        $this->assertEquals(6000, (float) $byAccount[2]->credit); // AR 411
    }

    public function test_allocation_cannot_exceed_invoice_debt(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();
        $invoice = $this->createInvoice(1, '5000.00');

        $receipt = $this->confirmedReceipt($account);

        $this->expectException(TreasuryException::class);
        $this->service->allocate($receipt, [
            ['invoice_id' => $invoice->id, 'amount' => '6000'],
        ], 1);
    }

    public function test_allocation_cannot_exceed_receipt_credit(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();
        $invoice = $this->createInvoice(1, '20000.00');

        $receipt = $this->confirmedReceipt($account, ['amount' => '10000']);

        $this->expectException(TreasuryException::class);
        $this->service->allocate($receipt, [
            ['invoice_id' => $invoice->id, 'amount' => '10001'],
        ], 1);
    }

    public function test_allocation_to_other_customers_invoice_is_refused(): void
    {
        $account = $this->createAccount();
        $this->createCustomer(1, 1);
        $this->createCustomer(1, 2);
        $otherInvoice = $this->createInvoice(2, '1000.00');

        $receipt = $this->confirmedReceipt($account);

        $this->expectException(TreasuryException::class);
        $this->service->allocate($receipt, [
            ['invoice_id' => $otherInvoice->id, 'amount' => '1000'],
        ], 1);
    }

    public function test_partial_allocation_marks_invoice_partially_paid(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();
        $invoice = $this->createInvoice(1, '10000.00');

        $receipt = $this->confirmedReceipt($account);
        $this->service->allocate($receipt, [
            ['invoice_id' => $invoice->id, 'amount' => '4000'],
        ], 1);

        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, (int) $invoice->fresh()->status);
        $this->assertSame('6000.0000', $this->service->invoiceDue($invoice->fresh()));
        $this->assertSame('6000.0000', $this->service->remainingUnapplied($receipt->fresh()));
        $this->assertSame(1, DB::table('invoice_payments')->where('invoice_id', $invoice->id)->count());
    }

    // ------------------------------------------------------------------
    // CA02 — concurrent settlements recheck the balance
    // ------------------------------------------------------------------

    public function test_ca02_second_settlement_rechecks_invoice_balance(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();
        $invoice = $this->createInvoice(1, '10000.00');

        $receiptA = $this->confirmedReceipt($account);
        $receiptB = $this->confirmedReceipt($account);

        $this->service->allocate($receiptA, [
            ['invoice_id' => $invoice->id, 'amount' => '6000'],
        ], 1);

        // The invoice now owes 4,000; a 5,000 allocation from the second
        // receipt must be refused even though that receipt has 10,000.
        $this->expectException(TreasuryException::class);
        $this->service->allocate($receiptB, [
            ['invoice_id' => $invoice->id, 'amount' => '5000'],
        ], 1);
    }

    public function test_ca02_same_receipt_cannot_over_allocate_in_two_calls(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();
        $invoice = $this->createInvoice(1, '10000.00');

        $receipt = $this->confirmedReceipt($account);

        $this->service->allocate($receipt, [
            ['invoice_id' => $invoice->id, 'amount' => '6000'],
        ], 1);

        $this->expectException(TreasuryException::class);
        $this->service->allocate($receipt, [
            ['invoice_id' => $invoice->id, 'amount' => '5000'],
        ], 1);
    }

    // ------------------------------------------------------------------
    // Unapplied credit (no new cash inflow)
    // ------------------------------------------------------------------

    public function test_apply_unapplied_credit_creates_no_new_cash_inflow(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();
        $invoiceA = $this->createInvoice(1, '6000.00', 1);
        $invoiceB = $this->createInvoice(1, '4000.00', 2);

        $receipt = $this->confirmedReceipt($account);
        $this->service->allocate($receipt, [
            ['invoice_id' => $invoiceA->id, 'amount' => '6000'],
        ], 1);

        $operationsBefore = DB::table('treasury_operations')->count();
        $entriesBefore = DB::table('treasury_entries')->count();
        $journalsBefore = DB::table('journal_entries')->count();

        $allocations = $this->service->applyUnappliedCredit(1, 1, $invoiceB->id, '4000', 'MZN', 1);

        $this->assertCount(1, $allocations);
        $this->assertSame('unapplied_credit', $allocations[0]->source);
        $this->assertSame(Invoice::STATUS_PAID, (int) $invoiceB->fresh()->status);

        // No new operation, no new entry, no new bank movement — only
        // the allocation journal (credit liability -> AR).
        $this->assertSame($operationsBefore, DB::table('treasury_operations')->count());
        $this->assertSame($entriesBefore, DB::table('treasury_entries')->count());
        $this->assertSame($journalsBefore + 1, DB::table('journal_entries')->count());
        $this->assertSame('10000.0000', $this->accounts->postedBalance($account->fresh()));
        $this->assertSame('0.0000', $this->service->remainingUnapplied($receipt->fresh()));
    }

    public function test_apply_unapplied_credit_is_fifo_oldest_receipt_first(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();
        $invoice = $this->createInvoice(1, '7000.00');

        $first = $this->confirmedReceipt($account, ['amount' => '5000']);
        $second = $this->confirmedReceipt($account, ['amount' => '5000']);

        $allocations = $this->service->applyUnappliedCredit(1, 1, $invoice->id, '7000', 'MZN', 1);

        $this->assertCount(2, $allocations);
        $this->assertSame((int) $first->id, (int) $allocations[0]->receipt_id);
        $this->assertSame('5000.0000', (string) $allocations[0]->allocated_amount);
        $this->assertSame((int) $second->id, (int) $allocations[1]->receipt_id);
        $this->assertSame('2000.0000', (string) $allocations[1]->allocated_amount);
        $this->assertSame(Invoice::STATUS_PAID, (int) $invoice->fresh()->status);
    }

    public function test_apply_unapplied_credit_cannot_exceed_available_credit(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();
        $invoice = $this->createInvoice(1, '2000.00');

        $this->confirmedReceipt($account, ['amount' => '1000']);

        $this->expectException(TreasuryException::class);
        $this->service->applyUnappliedCredit(1, 1, $invoice->id, '2000', 'MZN', 1);
    }

    // ------------------------------------------------------------------
    // CA19 — historical rates preserved
    // ------------------------------------------------------------------

    public function test_ca19_historical_rate_preserved_and_used_for_allocation(): void
    {
        $account = $this->createAccount(1, ['code' => 'USD-001', 'currency' => 'USD']);
        $this->createCustomer();
        $invoice = $this->createInvoice(1, '6350.00');

        $receipt = $this->confirmedReceipt($account, [
            'amount' => '100',
            'currency' => 'USD',
            'exchange_rate' => '63.5',
            'rate_source' => 'manual',
            'rate_date' => '2026-11-01',
        ]);

        $this->assertSame('63.5000000000', (string) $receipt->operation->exchange_rate);
        $this->assertSame('6350.0000', (string) $receipt->operation->amount_base);

        $allocations = $this->service->allocate($receipt, [
            ['invoice_id' => $invoice->id, 'amount' => '100'],
        ], 1, 2);

        $this->assertSame('63.5000000000', (string) $allocations[0]->exchange_rate);
        $this->assertSame('6350.0000', (string) $allocations[0]->allocated_amount_base);
        $this->assertSame(Invoice::STATUS_PAID, (int) $invoice->fresh()->status);
        $this->assertSame('6350.0000', $this->accounts->postedBalance($account->fresh()));
    }

    public function test_cross_currency_allocation_blocked_without_approver(): void
    {
        $account = $this->createAccount(1, ['code' => 'USD-001', 'currency' => 'USD']);
        $this->createCustomer();
        $invoice = $this->createInvoice(1, '6350.00');

        $receipt = $this->confirmedReceipt($account, [
            'amount' => '100',
            'currency' => 'USD',
            'exchange_rate' => '63.5',
            'rate_source' => 'manual',
            'rate_date' => '2026-11-01',
        ]);

        $this->expectException(TreasuryException::class);
        $this->service->allocate($receipt, [
            ['invoice_id' => $invoice->id, 'amount' => '100'],
        ], 1);
    }

    public function test_cross_currency_allocation_blocked_when_approver_is_the_actor(): void
    {
        $account = $this->createAccount(1, ['code' => 'USD-001', 'currency' => 'USD']);
        $this->createCustomer();
        $invoice = $this->createInvoice(1, '6350.00');

        $receipt = $this->confirmedReceipt($account, [
            'amount' => '100',
            'currency' => 'USD',
            'exchange_rate' => '63.5',
            'rate_source' => 'manual',
            'rate_date' => '2026-11-01',
        ]);

        $this->expectException(TreasuryException::class);
        $this->service->allocate($receipt, [
            ['invoice_id' => $invoice->id, 'amount' => '100'],
        ], 1, 1);
    }

    public function test_foreign_receipt_without_rate_is_refused_at_creation(): void
    {
        $account = $this->createAccount(1, ['code' => 'USD-001', 'currency' => 'USD']);
        $this->createCustomer();

        $this->expectException(TreasuryException::class);
        $this->service->createReceipt(1, $this->receiptData($account, [
            'amount' => '100',
            'currency' => 'USD',
        ]), 1);
    }

    // ------------------------------------------------------------------
    // CA15 — reversal reopens allocations
    // ------------------------------------------------------------------

    public function test_ca15_reversal_reopens_allocations_and_restores_balance(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();
        $invoice = $this->createInvoice(1, '6000.00');

        $receipt = $this->confirmedReceipt($account);
        $this->service->allocate($receipt, [
            ['invoice_id' => $invoice->id, 'amount' => '6000'],
        ], 1);

        $this->assertSame(Invoice::STATUS_PAID, (int) $invoice->fresh()->status);

        $reversal = $this->service->reverseReceipt($receipt, 'Bank returned the transfer', 1);

        $this->assertSame('reversed', $receipt->fresh()->state);
        $this->assertSame('confirmed', $reversal->state);
        $this->assertSame((int) $receipt->id, (int) $reversal->reversal_of_id);
        $this->assertSame((int) $receipt->operation_id, (int) $reversal->operation->reversal_of_id);
        $this->assertNotSame($receipt->operation->reference, $reversal->operation->reference);

        // Balance nets to zero, invoice reopened, payment removed.
        $this->assertSame('0.0000', $this->accounts->postedBalance($account->fresh()));
        $this->assertSame(Invoice::STATUS_UNPAID, (int) $invoice->fresh()->status);
        $this->assertSame('6000.0000', $this->service->invoiceDue($invoice->fresh()));
        $this->assertSame(0, DB::table('invoice_payments')->where('invoice_id', $invoice->id)->count());
        $this->assertSame(0, DB::table('transactions')->where('payment_id', '>', 0)->count());

        $allocation = DB::table('treasury_settlement_allocations')->where('receipt_id', $receipt->id)->first();
        $this->assertSame('reversed', $allocation->state);

        // Customer balance restored (the settlement credit is undone).
        $this->assertEquals(0, (float) Customer::find(1)->balance);
    }

    public function test_ca15_reversal_with_fees_nets_posted_balance_to_zero(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();

        $receipt = $this->confirmedReceipt($account, ['fees' => '200', 'withholding' => '100']);
        $this->assertSame('9700.0000', $this->accounts->postedBalance($account->fresh()));

        $this->service->reverseReceipt($receipt, 'Duplicate receipt', 1);

        $this->assertSame('0.0000', $this->accounts->postedBalance($account->fresh()));
    }

    public function test_reversal_needs_reason_and_only_confirmed_receipts(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();

        $receipt = $this->service->createReceipt(1, $this->receiptData($account), 1);

        $this->expectException(TreasuryException::class);
        $this->service->reverseReceipt($receipt, 'No reason', 1);
    }

    // ------------------------------------------------------------------
    // Closed period (CA17) + cross-company isolation
    // ------------------------------------------------------------------

    public function test_closed_fiscal_year_blocks_confirmation(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();

        DB::table('journal_entries')->insert([
            'date' => '2026-12-31',
            'reference' => 'FY-2026-CLOSE-1',
            'description' => 'Year-end close',
            'journal_id' => 99,
            'status' => 1,
            'created_by' => 1,
        ]);

        $receipt = $this->service->createReceipt(1, $this->receiptData($account), 1);
        $receipt = $this->service->submitReceipt($receipt, 1);

        $this->expectException(TreasuryException::class);
        $this->service->confirmReceipt($receipt, 1);
    }

    public function test_receipt_for_other_company_is_not_visible(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();

        $receipt = $this->confirmedReceipt($account);

        $this->expectException(TreasuryException::class);
        $this->service->receiptForCompany(4, $receipt->id);
    }

    public function test_account_from_other_company_cannot_be_used(): void
    {
        $this->createAccount(4, ['code' => 'OTHER-001', 'branch_id' => null, 'owner_user_id' => null]);
        $this->createCustomer();

        $otherAccount = TreasuryFinancialAccount::where('created_by', 4)->firstOrFail();

        $this->expectException(TreasuryException::class);
        $this->service->createReceipt(1, $this->receiptData($otherAccount), 1);
    }

    // ------------------------------------------------------------------
    // Balance formula + statement
    // ------------------------------------------------------------------

    public function test_posted_balance_adds_opening_balance_and_receipt(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();

        $balance = $this->accounts->createOpeningBalance(1, $account->id, [
            'amount' => '5000.0000',
            'cut_off_date' => '2026-01-01',
        ], 1);
        $this->accounts->approveOpeningBalance($balance, 1);

        $this->assertSame('5000.0000', $this->accounts->postedBalance($account->fresh()));

        $this->confirmedReceipt($account);

        $this->assertSame('15000.0000', $this->accounts->postedBalance($account->fresh()));
    }

    public function test_statement_includes_confirmed_receipt_as_running_balance_line(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();

        $this->confirmedReceipt($account);

        $statement = $this->accounts->statement($account->fresh(), null, null);

        $this->assertSame('10000.0000', $statement['closing_balance']);
        $this->assertCount(1, $statement['lines']);
        $this->assertSame('10000.0000', number_format((float) $statement['lines'][0]['amount_base'], 4, '.', ''));
        $this->assertSame('10000.0000', number_format((float) $statement['lines'][0]['running_balance'], 4, '.', ''));
    }

    public function test_discount_increases_settlement_value_without_new_cash(): void
    {
        $account = $this->createAccount();
        $this->createCustomer();
        $invoice = $this->createInvoice(1, '10500.00');

        $receipt = $this->confirmedReceipt($account, ['discount' => '500']);

        $this->assertSame('10500.0000', $this->service->remainingUnapplied($receipt->fresh()));
        $this->assertSame('10000.0000', $this->accounts->postedBalance($account->fresh()));

        $this->service->allocate($receipt, [
            ['invoice_id' => $invoice->id, 'amount' => '10500'],
        ], 1);

        $this->assertSame(Invoice::STATUS_PAID, (int) $invoice->fresh()->status);
        $this->assertSame('0.0000', $this->service->remainingUnapplied($receipt->fresh()));
    }
}
