<?php

namespace Tests\Unit;

use App\Exceptions\TreasuryException;
use App\Models\BankReconciliationSession;
use App\Models\DimensionAuditEvent;
use App\Models\JournalEntry;
use App\Models\TreasuryEntry;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryOperation;
use App\Models\TreasuryReceipt;
use App\Services\TreasuryAccountService;
use App\Services\TreasuryReceiptService;
use App\Services\TreasuryReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Treasury Phase 9 — dashboard, reports, audit trail (plan §9).
 *
 * Covers: filter normalisation, every dashboard KPI, each of the
 * eight reports (rows + totals + filters), the audit trail
 * (filters + company isolation) and the CA03 hardening gate —
 * a repeated confirmation event creates no second inflow.
 *
 * CA coverage (whole module): CA01/02/04/15/19
 * TreasuryReceiptServiceTest, CA05/06/20 TreasuryPaymentServiceTest
 * + TreasuryExecutionServiceTest, CA07 TreasuryExecutionServiceTest
 * + TreasuryPaymentBatchServiceTest, CA08 TreasuryTransferServiceTest,
 * CA09 TreasuryCashSessionServiceTest, CA10 TreasuryAdvanceServiceTest,
 * CA11/12 TreasuryReconciliationServiceTest (+ feature), CA13/14
 * TreasuryForecastServiceTest + TreasuryRecurringServiceTest (+
 * feature), CA16 TreasuryCashSessionServiceTest +
 * TreasuryPettyCashServiceTest (+ this file, reports scope), CA17
 * TreasuryExecutionServiceTest + TreasuryReceiptServiceTest +
 * TreasuryTransferServiceTest, CA18 TreasuryExecutionServiceTest.
 * CA03 had NO test before Phase 9 — this file adds it.
 *
 * Users: 1 = Company, 2 = Operator, 4 = Other Company.
 * "Today" is frozen at 2026-11-07. Opening balance 200,000 MZN.
 */
class TreasuryReportServiceTest extends TestCase
{
    protected TreasuryReportService $service;
    protected TreasuryReceiptService $receipts;
    protected TreasuryAccountService $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-07 09:00:00'));
        $this->createTables();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Operator', 'email' => 'operator@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
        ]);
        DB::table('branches')->insert([['id' => 1, 'name' => 'Maputo', 'created_by' => 1]]);
        DB::table('bank_accounts')->insert([
            'id' => 1, 'holder_name' => 'Hoya Hoya Lda', 'bank_name' => 'BIM',
            'account_number' => '123456789', 'chart_account_id' => 1,
            'opening_balance' => 0, 'contact_number' => '', 'bank_address' => '',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'code' => 121, 'name' => 'Bank', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 2, 'code' => 411, 'name' => 'Customers', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 3, 'code' => 211, 'name' => 'Customer credit', 'type' => 2, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 4, 'code' => 611, 'name' => 'Bank fees and discounts', 'type' => 6, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 5, 'code' => 241, 'name' => 'Withholding tax', 'type' => 2, 'sub_type' => 1, 'created_by' => 1],
        ]);

        $this->accounts = new TreasuryAccountService();
        $this->receipts = new TreasuryReceiptService($this->accounts);
        $this->service = new TreasuryReportService($this->accounts);

        // The standard account: BIM-001, approved opening 200,000.
        $this->accounts->createAccount(1, [
            'code' => 'BIM-001', 'type' => 'bank', 'currency' => 'MZN',
            'account_holder' => 'Hoya Hoya Lda', 'institution' => 'BIM',
            'account_number' => '123456789', 'chart_account_id' => 1,
            'branch_id' => 1, 'owner_user_id' => 2,
        ], 1);
        DB::table('treasury_opening_balances')->insert([
            'financial_account_id' => 1, 'created_by' => 1, 'currency' => 'MZN',
            'amount' => '200000.0000', 'amount_base' => '200000.0000',
            'cut_off_date' => '2026-11-01', 'batch_reference' => 'BATCH-1',
            'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach (['treasury_report_exports', 'treasury_statement_sync_cursors', 'treasury_missing_movements', 'bank_reconciliation_matches', 'bank_statement_lines', 'bank_reconciliation_sessions', 'treasury_cash_movements', 'treasury_cash_counts', 'treasury_cash_sessions', 'treasury_petty_cash_funds', 'treasury_advance_expense_lines', 'treasury_advances', 'treasury_transfers', 'treasury_fund_reservations', 'treasury_approval_decisions', 'treasury_approval_steps', 'treasury_payment_attempts', 'treasury_payment_batch_items', 'treasury_payment_batches', 'treasury_payment_requests', 'treasury_cash_flow_alerts', 'treasury_recurring_occurrences', 'treasury_recurring_rules', 'treasury_forecast_items', 'treasury_settlement_allocations', 'treasury_receipts', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'dimension_audit_events', 'journal_items', 'journal_entries', 'transaction_lines', 'transactions', 'pay_slips', 'credit_notes', 'invoice_payments', 'invoice_products', 'invoices', 'customers', 'taxes', 'chart_of_accounts', 'bank_accounts', 'branches', 'settings', 'users'] as $table) {
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

        Schema::create('customers', function ($table) {
            $table->bigIncrements('id');
            $table->integer('customer_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('contact')->nullable();
            $table->string('avatar', 100)->default('');
            $table->integer('created_by')->default(0);
            $table->integer('is_active')->default(1);
            $table->timestamp('email_verified_at')->nullable();
            $table->decimal('balance', 15, 2)->default('0.00');
            $table->timestamps();
        });
        Schema::create('taxes', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->decimal('rate', 10, 2)->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('invoices', function ($table) {
            $table->bigIncrements('id');
            $table->integer('customer_id')->nullable();
            $table->bigInteger('invoice_id')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('ref_number')->nullable();
            $table->integer('status')->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('invoice_products', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('invoice_id');
            $table->integer('product_id')->default(0);
            $table->decimal('price', 16, 2)->default(0);
            $table->decimal('quantity', 16, 2)->default(0);
            $table->string('tax')->nullable();
            $table->decimal('discount', 16, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('invoice_payments', function ($table) {
            $table->bigIncrements('id');
            $table->integer('invoice_id');
            $table->date('date')->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->integer('account_id')->default(0);
            $table->integer('payment_method')->default(0);
            $table->string('payment_type')->default('Manually');
            $table->timestamps();
        });
        Schema::create('credit_notes', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('invoice')->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->integer('is_debit')->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('pay_slips', function ($table) {
            $table->bigIncrements('id');
            $table->integer('employee_id');
            $table->integer('net_payble');
            $table->string('salary_month');
            $table->integer('status');
            $table->integer('basic_salary');
            $table->text('allowance');
            $table->text('commission');
            $table->text('loan');
            $table->text('saturation_deduction');
            $table->text('other_payment');
            $table->text('overtime');
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('treasury_forecast_items', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('source_type', 20);
            $table->bigInteger('source_id')->nullable();
            $table->string('direction', 10);
            $table->decimal('amount', 20, 4);
            $table->decimal('amount_base', 20, 4);
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->date('expected_date');
            $table->string('scenario', 20)->default('baseline');
            $table->decimal('probability', 5, 2)->nullable();
            $table->string('document', 150)->nullable();
            $table->string('replacement_reference', 60)->nullable();
            $table->string('description', 200)->nullable();
            $table->string('state', 20)->default('forecast');
            $table->timestamps();
        });
        Schema::create('treasury_recurring_rules', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('beneficiary', 150);
            $table->string('description', 250)->nullable();
            $table->string('category', 100)->nullable();
            $table->string('direction', 10)->default('outflow');
            $table->string('currency', 3)->default('MZN');
            $table->decimal('amount', 20, 4);
            $table->decimal('amount_base', 20, 4);
            $table->string('amount_type', 20)->default('fixed');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('frequency', 20);
            $table->integer('custom_interval')->nullable();
            $table->string('custom_unit', 20)->nullable();
            $table->integer('due_day')->nullable();
            $table->bigInteger('planned_account_id')->nullable();
            $table->boolean('approval_required')->default(false);
            $table->string('state', 20)->default('active');
            $table->integer('version')->default(1);
            $table->timestamps();
        });
        Schema::create('treasury_recurring_occurrences', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('rule_id');
            $table->string('period_key', 20);
            $table->date('scheduled_date');
            $table->decimal('amount', 20, 4);
            $table->decimal('amount_base', 20, 4);
            $table->string('currency', 3)->default('MZN');
            $table->string('direction', 10)->default('outflow');
            $table->string('state', 20)->default('generated');
            $table->timestamp('generated_at')->nullable();
            $table->bigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->string('skip_reason', 500)->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('treasury_cash_flow_alerts', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('type', 30);
            $table->string('severity', 20)->default('warning');
            $table->string('title', 200);
            $table->text('detail')->nullable();
            $table->string('source_type', 30)->nullable();
            $table->bigInteger('source_id')->nullable();
            $table->string('state', 20)->default('active');
            $table->timestamp('occurred_at')->nullable();
            $table->bigInteger('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });
        Schema::create('treasury_payment_requests', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('operation_id')->nullable();
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('source_account_id');
            $table->string('beneficiary_type', 20);
            $table->bigInteger('beneficiary_id')->nullable();
            $table->string('beneficiary_name', 150)->nullable();
            $table->string('beneficiary_account', 100)->nullable();
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->decimal('paid_amount_original', 20, 4)->default(0);
            $table->decimal('paid_amount_base', 20, 4)->default(0);
            $table->date('due_date')->nullable();
            $table->date('planned_date')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->text('reason')->nullable();
            $table->bigInteger('cost_centre_id')->nullable();
            $table->bigInteger('project_id')->nullable();
            $table->json('source_documents')->nullable();
            $table->string('client_reference', 100)->nullable();
            $table->json('similar_flags')->nullable();
            $table->string('state', 20)->default('draft');
            $table->integer('requested_by');
            $table->bigInteger('executor_user_id')->nullable();
            $table->json('approved_snapshot')->nullable();
            $table->integer('approved_rule_version')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('treasury_payment_attempts', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('payment_request_id');
            $table->integer('attempt_number')->default(1);
            $table->string('idempotency_key', 100);
            $table->string('provider', 50)->nullable();
            $table->string('provider_reference', 100)->nullable();
            $table->string('state', 20)->default('processing');
            $table->text('result')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('treasury_approval_steps', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('payment_request_id');
            $table->integer('rule_version')->default(1);
            $table->integer('level')->default(1);
            $table->string('permission', 100)->default('approve treasury payments');
            $table->integer('required_votes')->default(1);
            $table->json('eligible_user_ids')->nullable();
            $table->integer('substitute_user_id')->nullable();
            $table->timestamp('substitute_expiry')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
        Schema::create('treasury_approval_decisions', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('payment_request_id');
            $table->bigInteger('approval_step_id');
            $table->integer('actor_id');
            $table->string('decision', 20);
            $table->text('reason')->nullable();
            $table->integer('substitute_user_id')->nullable();
            $table->timestamp('substitute_expiry')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
        Schema::create('treasury_fund_reservations', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('financial_account_id');
            $table->bigInteger('payment_request_id');
            $table->decimal('amount', 20, 4)->default(0);
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->string('currency', 3)->default('MZN');
            $table->timestamp('expires_at')->nullable();
            $table->decimal('consumed_amount', 20, 4)->default(0);
            $table->decimal('released_amount', 20, 4)->default(0);
            $table->string('state', 20)->default('active');
            $table->text('reason')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('treasury_transfers', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('operation_id')->nullable();
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('source_account_id');
            $table->bigInteger('destination_account_id');
            $table->bigInteger('transit_account_id')->nullable();
            $table->string('source_currency', 3)->default('MZN');
            $table->string('destination_currency', 3)->default('MZN');
            $table->string('timing', 20)->default('immediate');
            $table->decimal('amount_debited', 20, 4)->default(0);
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->decimal('base_exchange_rate', 20, 10)->nullable();
            $table->decimal('amount_credited', 20, 4)->default(0);
            $table->decimal('amount_debited_base', 20, 4)->default(0);
            $table->decimal('amount_credited_base', 20, 4)->default(0);
            $table->decimal('fee_amount', 20, 4)->default(0);
            $table->decimal('fee_base', 20, 4)->default(0);
            $table->bigInteger('fee_account_id')->nullable();
            $table->decimal('exchange_difference_base', 20, 4)->default(0);
            $table->bigInteger('exchange_difference_account_id')->nullable();
            $table->string('state', 20)->default('draft');
            $table->text('evidence')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('treasury_advances', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('operation_id')->nullable();
            $table->bigInteger('source_account_id');
            $table->string('recipient_type', 20);
            $table->bigInteger('recipient_id');
            $table->string('recipient_name', 150)->nullable();
            $table->string('purpose', 250)->nullable();
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->decimal('amount_requested', 20, 4)->default(0);
            $table->decimal('amount_requested_base', 20, 4)->default(0);
            $table->decimal('amount_approved', 20, 4)->default(0);
            $table->decimal('amount_approved_base', 20, 4)->default(0);
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
            $table->date('due_date')->nullable();
            $table->string('state', 20)->default('draft');
            $table->bigInteger('requested_by');
            $table->bigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->bigInteger('disbursement_journal_id')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->bigInteger('closed_by')->nullable();
            $table->timestamps();
        });
        Schema::create('treasury_advance_expense_lines', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('advance_id');
            $table->integer('created_by');
            $table->date('expense_date');
            $table->string('counterparty', 150)->nullable();
            $table->string('category', 100)->nullable();
            $table->string('document', 150)->nullable();
            $table->decimal('amount', 20, 4)->default(0);
            $table->bigInteger('cost_centre_id')->nullable();
            $table->text('evidence')->nullable();
            $table->string('state', 20)->default('pending');
            $table->timestamps();
        });
        Schema::create('treasury_cash_sessions', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('register_id');
            $table->bigInteger('operator_user_id');
            $table->bigInteger('shift_id')->nullable();
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_float', 20, 4)->default(0);
            $table->decimal('opening_float_difference', 20, 4)->default(0);
            $table->decimal('expected_balance', 20, 4)->default(0);
            $table->decimal('counted_balance', 20, 4)->nullable();
            $table->decimal('variance_amount', 20, 4)->nullable();
            $table->decimal('variance_posted_amount', 20, 4)->default(0);
            $table->text('variance_reason')->nullable();
            $table->bigInteger('variance_approved_by')->nullable();
            $table->timestamp('variance_approved_at')->nullable();
            $table->bigInteger('variance_operation_id')->nullable();
            $table->bigInteger('variance_journal_id')->nullable();
            $table->decimal('card_total', 20, 4)->default(0);
            $table->decimal('mobile_total', 20, 4)->default(0);
            $table->decimal('shift_cash_total', 20, 4)->nullable();
            $table->decimal('shift_card_total', 20, 4)->nullable();
            $table->decimal('shift_credit_total', 20, 4)->nullable();
            $table->decimal('shift_difference', 20, 4)->nullable();
            $table->decimal('shift_closing_balance', 20, 4)->nullable();
            $table->bigInteger('operation_id')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->bigInteger('reopened_by')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->text('lock_reason')->nullable();
            $table->bigInteger('locked_by')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('state', 20)->default('open');
            $table->timestamps();
        });
        Schema::create('treasury_cash_counts', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('cash_session_id');
            $table->integer('created_by');
            $table->decimal('denomination', 20, 4);
            $table->integer('count')->default(0);
            $table->decimal('amount', 20, 4)->default(0);
            $table->timestamps();
        });
        Schema::create('treasury_cash_movements', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('cash_session_id');
            $table->integer('created_by');
            $table->bigInteger('recorded_by')->nullable();
            $table->string('type', 20);
            $table->string('method', 20)->default('cash');
            $table->decimal('amount', 20, 4)->default(0);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->string('reference', 100)->nullable();
            $table->text('reason')->nullable();
            $table->bigInteger('transfer_id')->nullable();
            $table->bigInteger('entry_id')->nullable();
            $table->timestamps();
        });
        Schema::create('treasury_petty_cash_funds', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('custodian_user_id');
            $table->bigInteger('financial_account_id');
            $table->bigInteger('branch_id')->nullable();
            $table->decimal('limit_amount', 20, 4)->nullable();
            $table->string('replenishment_frequency', 20)->default('on_demand');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
        Schema::create('treasury_payment_batches', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->string('reference', 60)->nullable();
            $table->string('provider', 50)->nullable();
            $table->string('state', 20)->default('draft');
            $table->text('note')->nullable();
            $table->bigInteger('executed_by')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('treasury_payment_batch_items', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('batch_id');
            $table->bigInteger('payment_request_id');
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->string('currency', 3)->default('MZN');
            $table->string('state', 20)->default('pending');
            $table->bigInteger('attempt_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('bank_reconciliation_sessions', function ($table) {
            $table->id();
            $table->unsignedBigInteger('bank_account_id');
            $table->string('preset_key', 64)->default('custom');
            $table->string('status', 32)->default('draft');
            $table->string('original_filename')->nullable();
            $table->string('stored_path')->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->unsignedSmallInteger('date_tolerance_days')->default(3);
            $table->json('column_mapping')->nullable();
            $table->decimal('statement_balance', 20, 4)->nullable();
            $table->decimal('book_balance', 20, 4)->nullable();
            $table->decimal('outstanding_items', 20, 4)->nullable();
            $table->decimal('explained_differences', 20, 4)->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->dateTime('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->unsignedInteger('created_by');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });
        Schema::create('bank_statement_lines', function ($table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedInteger('row_index');
            $table->string('external_id', 128)->nullable();
            $table->date('txn_date')->nullable();
            $table->text('description')->nullable();
            $table->decimal('amount', 18, 4)->nullable();
            $table->decimal('balance', 18, 4)->nullable();
            $table->text('parse_error')->nullable();
            $table->json('raw_row')->nullable();
            $table->timestamps();
        });
        Schema::create('bank_reconciliation_matches', function ($table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('statement_line_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('treasury_operation_id')->nullable();
            $table->decimal('matched_amount', 20, 4)->nullable();
            $table->string('match_type', 24)->default('auto');
            $table->string('match_note', 255)->nullable();
            $table->timestamps();
        });
        Schema::create('treasury_missing_movements', function ($table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('statement_line_id');
            $table->string('category', 32);
            $table->unsignedBigInteger('offset_account_id');
            $table->string('currency', 8)->default('MZN');
            $table->decimal('amount_original', 20, 4);
            $table->decimal('exchange_rate', 20, 10)->default('1.0000000000');
            $table->decimal('amount_base', 20, 4);
            $table->string('state', 24)->default('proposed');
            $table->unsignedBigInteger('operation_id')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->unsignedBigInteger('reversal_operation_id')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('treasury_statement_sync_cursors', function ($table) {
            $table->id();
            $table->unsignedBigInteger('bank_account_id');
            $table->string('source_key', 64);
            $table->string('cursor_value', 255)->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
        Schema::create('treasury_report_exports', function ($table) {
            $table->id();
            $table->integer('created_by');
            $table->string('report', 40);
            $table->string('format', 10);
            $table->json('filters')->nullable();
            $table->string('file_path', 255)->nullable();
            $table->string('token', 64);
            $table->string('status', 20)->default('queued');
            $table->text('error_message')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function filters(array $overrides = []): array
    {
        return $this->service->normalizeFilters($overrides);
    }

    protected function seedInvoice(int $id, string $dueDate, string $total, int $status = 2, int $creatorId = 1, ?string $ref = null): void
    {
        DB::table('invoices')->insert([
            'id' => $id, 'customer_id' => 1, 'invoice_id' => $id,
            'issue_date' => '2026-11-01', 'due_date' => $dueDate,
            'ref_number' => $ref, 'status' => $status, 'created_by' => $creatorId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('invoice_products')->insert([
            'invoice_id' => $id, 'product_id' => 1, 'price' => $total,
            'quantity' => 1, 'tax' => '', 'discount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** One confirmed operation + its entry, posted directly (no journal — the reports read entries, not journals). */
    protected function seedOperation(array $overrides = []): int
    {
        $entryDirection = $overrides['entry_direction'] ?? 'inflow';
        $entryAmount = $overrides['entry_amount'] ?? '10000.0000';
        unset($overrides['entry_direction'], $overrides['entry_amount']);

        $id = DB::table('treasury_operations')->insertGetId(array_merge([
            'reference' => 'COMP-OP-' . random_int(1000, 9999),
            'type' => 'receipt', 'company_id' => 1, 'financial_account_id' => 1,
            'currency' => 'MZN', 'amount_original' => '10000.0000', 'amount_base' => '10000.0000',
            'posting_date' => '2026-11-07', 'effective_date' => '2026-11-07', 'value_date' => '2026-11-07',
            'state' => 'confirmed', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));

        DB::table('treasury_entries')->insert([
            'operation_id' => $id, 'financial_account_id' => 1, 'direction' => $entryDirection,
            'amount_original' => $entryAmount, 'amount_base' => $entryAmount,
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    protected function confirmedReceipt(array $overrides = []): TreasuryReceipt
    {
        DB::table('customers')->insert([
            'id' => 1, 'customer_id' => 1, 'name' => 'Customer One',
            'email' => 'customer@test.local', 'created_by' => 1, 'balance' => '0.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $account = TreasuryFinancialAccount::findOrFail(1);
        $receipt = $this->receipts->createReceipt(1, array_merge([
            'financial_account_id' => $account->id,
            'customer_id' => 1,
            'amount' => '10000',
            'currency' => 'MZN',
            'method' => 'transfer',
            'posting_date' => '2026-11-07',
            'effective_date' => '2026-11-07',
            'value_date' => '2026-11-07',
        ], $overrides), 1);
        $receipt = $this->receipts->submitReceipt($receipt, 1);

        return $this->receipts->confirmReceipt($receipt, 1);
    }

    // ------------------------------------------------------------------
    // Shared reconciliation fixture: a confirmed 10,000 receipt + a
    // draft session whose statement (209,950) = book (210,000) +
    // outstanding (−50) + explained (0) → difference 0 (CA11/CA12).
    // ------------------------------------------------------------------

    protected function seedReconciliationSessionA(): TreasuryReceipt
    {
        $receipt = $this->confirmedReceipt();

        // The Treasury-linked bank is the one createAccount made for
        // BIM-001 (bank id 2 in this fixture — the seeded bank row 1 is
        // not Treasury-linked, so the book default would fall back to
        // the legacy opening balance, drifted by the poster).
        $bankId = (int) TreasuryFinancialAccount::findOrFail(1)->bank_account_id;

        $sessionId = DB::table('bank_reconciliation_sessions')->insertGetId([
            'bank_account_id' => $bankId, 'preset_key' => 'custom', 'status' => 'draft',
            'period_from' => '2026-11-01', 'period_to' => '2026-11-07',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $line1 = DB::table('bank_statement_lines')->insertGetId([
            'session_id' => $sessionId, 'row_index' => 1, 'txn_date' => '2026-11-05',
            'description' => 'Receipt 10,000', 'amount' => '10000.0000', 'balance' => '210000.0000',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $line2 = DB::table('bank_statement_lines')->insertGetId([
            'session_id' => $sessionId, 'row_index' => 2, 'txn_date' => '2026-11-06',
            'description' => 'Bank fee', 'amount' => '-50.0000', 'balance' => '209950.0000',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('bank_reconciliation_matches')->insert([
            'session_id' => $sessionId, 'statement_line_id' => $line1,
            'treasury_operation_id' => $receipt->operation_id,
            'matched_amount' => '10000.0000', 'match_type' => 'auto',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_missing_movements')->insert([
            'session_id' => $sessionId, 'statement_line_id' => $line2,
            'category' => 'fee', 'offset_account_id' => 611, 'currency' => 'MZN',
            'amount_original' => '-50.0000', 'exchange_rate' => '1.0000000000',
            'amount_base' => '-50.0000', 'state' => 'proposed',
            'reason' => 'Bank fee', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $receipt;
    }

    // ------------------------------------------------------------------
    // normalizeFilters
    // ------------------------------------------------------------------

    public function test_normalize_filters_drops_invalid_dates_and_clamps_days(): void
    {
        $filters = $this->filters([
            'from' => 'not-a-date',
            'to' => '2026-11-01',
            'days' => '500',
            'group' => 'year',
            'scenario' => 'bogus',
            'currency' => 'usd',
        ]);

        $this->assertNull($filters['from']);
        $this->assertSame('2026-11-01', $filters['to']);
        $this->assertSame(365, $filters['days']);
        $this->assertSame('day', $filters['group']);
        $this->assertSame('baseline', $filters['scenario']);
        $this->assertSame('USD', $filters['currency']);
    }

    public function test_normalize_filters_swaps_reversed_date_range(): void
    {
        $filters = $this->filters(['from' => '2026-11-20', 'to' => '2026-11-01', 'days' => '0']);

        $this->assertSame('2026-11-01', $filters['from']);
        $this->assertSame('2026-11-20', $filters['to']);
        $this->assertSame(1, $filters['days']);
    }

    public function test_normalize_filters_rejects_bad_currency(): void
    {
        $this->assertNull($this->filters(['currency' => 'xxxx'])['currency']);
        $this->assertSame('MZN', $this->filters(['currency' => ' mzn '])['currency']);
    }

    // ------------------------------------------------------------------
    // Dashboard
    // ------------------------------------------------------------------

    public function test_dashboard_kpis(): void
    {
        $this->seedInvoice(1, '2026-11-17', '5000.00', 2);
        $this->seedInvoice(2, '2026-11-01', '10000.00', 2);

        DB::table('treasury_payment_requests')->insert([
            'created_by' => 1, 'branch_id' => 1, 'source_account_id' => 1,
            'beneficiary_type' => 'vendor', 'beneficiary_name' => 'Fornecedor X',
            'amount_original' => '50000.0000', 'currency' => 'MZN',
            'amount_base' => '50000.0000', 'paid_amount_original' => '0.0000',
            'paid_amount_base' => '0.0000', 'due_date' => '2026-11-01',
            'state' => 'approved', 'requested_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_payment_requests')->insert([
            'created_by' => 1, 'branch_id' => 1, 'source_account_id' => 1,
            'beneficiary_type' => 'vendor', 'beneficiary_name' => 'Fornecedor Y',
            'amount_original' => '30000.0000', 'currency' => 'MZN',
            'amount_base' => '30000.0000', 'paid_amount_original' => '0.0000',
            'paid_amount_base' => '0.0000', 'due_date' => '2026-11-20',
            'state' => 'awaiting_approval', 'requested_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_advances')->insert([
            'created_by' => 1, 'branch_id' => 1, 'source_account_id' => 1,
            'recipient_type' => 'employee', 'recipient_id' => 2, 'recipient_name' => 'Ana',
            'amount_requested' => '5000.0000', 'amount_requested_base' => '5000.0000',
            'state' => 'pending_approval', 'requested_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_advances')->insert([
            'created_by' => 1, 'branch_id' => 1, 'source_account_id' => 1,
            'recipient_type' => 'employee', 'recipient_id' => 2, 'recipient_name' => 'Ana',
            'amount_requested' => '3000.0000', 'amount_requested_base' => '3000.0000',
            'amount_paid' => '3000.0000', 'amount_paid_base' => '3000.0000',
            'amount_outstanding' => '3000.0000', 'amount_outstanding_base' => '3000.0000',
            'due_date' => '2026-11-20', 'state' => 'disbursed', 'requested_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_advances')->insert([
            'created_by' => 1, 'branch_id' => 1, 'source_account_id' => 1,
            'recipient_type' => 'employee', 'recipient_id' => 2, 'recipient_name' => 'Bruno',
            'amount_requested' => '1000.0000', 'amount_requested_base' => '1000.0000',
            'amount_paid' => '1000.0000', 'amount_paid_base' => '1000.0000',
            'amount_outstanding' => '1000.0000', 'amount_outstanding_base' => '1000.0000',
            'due_date' => '2026-11-01', 'state' => 'disbursed', 'requested_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_cash_flow_alerts')->insert([
            'company_id' => 1, 'created_by' => 1, 'type' => 'insufficient_funds',
            'severity' => 'critical', 'title' => 'Low balance', 'state' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_cash_flow_alerts')->insert([
            'company_id' => 1, 'created_by' => 1, 'type' => 'overdue_occurrence',
            'severity' => 'warning', 'title' => 'Overdue rule', 'state' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_transfers')->insert([
            'created_by' => 1, 'branch_id' => 1, 'source_account_id' => 1,
            'destination_account_id' => 1, 'source_currency' => 'MZN',
            'destination_currency' => 'MZN', 'timing' => 'delayed',
            'amount_debited' => '50000.0000', 'amount_debited_base' => '50000.0000',
            'amount_credited' => '0.0000', 'amount_credited_base' => '0.0000',
            'state' => 'in_transit', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_cash_sessions')->insert([
            'created_by' => 1, 'register_id' => 1, 'operator_user_id' => 2,
            'currency' => 'MZN', 'opening_float' => '10000.0000', 'state' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->seedReconciliationSessionA();

        $dashboard = $this->service->dashboard(1);

        $this->assertSame('MZN', $dashboard['base_currency']);
        $this->assertCount(1, $dashboard['accounts']);
        $this->assertSame('210000.0000', $dashboard['accounts'][0]['posted']);
        $this->assertSame('210000.0000', $dashboard['accounts'][0]['available']);
        $this->assertSame('0.0000', $dashboard['accounts'][0]['reserved']);
        $this->assertSame('210000.0000', $dashboard['total_posted_base']);
        $this->assertSame('210000.0000', $dashboard['total_available_base']);

        // Overdue invoice (due 2026-11-01) lands in the first forecast
        // period; the 2026-11-17 invoice lands in the 15/30-day windows.
        $this->assertSame('10000.0000', $dashboard['expected'][7]['inflow']);
        $this->assertSame('15000.0000', $dashboard['expected'][15]['inflow']);
        $this->assertSame('15000.0000', $dashboard['expected'][30]['inflow']);
        // Approved request (50,000, due 2026-11-01) is an expected payment.
        $this->assertSame('50000.0000', $dashboard['expected'][7]['outflow']);
        $this->assertSame('50000.0000', $dashboard['expected'][15]['outflow']);
        $this->assertSame('50000.0000', $dashboard['expected'][30]['outflow']);

        $this->assertSame(1, $dashboard['overdue_receipts']['count']);
        $this->assertSame('10000.0000', $dashboard['overdue_receipts']['amount_base']);
        $this->assertSame(1, $dashboard['overdue_payments']['count']);
        $this->assertSame('50000.0000', $dashboard['overdue_payments']['amount_base']);

        $this->assertSame(1, $dashboard['pending_approvals']['payment_requests']['count']);
        $this->assertSame('30000.0000', $dashboard['pending_approvals']['payment_requests']['amount_base']);
        $this->assertSame(1, $dashboard['pending_approvals']['advances']['count']);
        $this->assertSame('5000.0000', $dashboard['pending_approvals']['advances']['amount_base']);
        $this->assertSame(0, $dashboard['pending_approvals']['cash_variances']);

        $this->assertCount(1, $dashboard['reconciliation']);
        $this->assertSame('Hoya Hoya Lda', $dashboard['reconciliation'][0]['bank']);
        $this->assertSame(50.0, $dashboard['reconciliation'][0]['matched_pct']);
        $this->assertSame('0.0000', $dashboard['reconciliation'][0]['difference']);
        $this->assertSame('draft', $dashboard['reconciliation'][0]['status']);

        $this->assertSame('210000.0000', $dashboard['forecast_30']['opening_balance']);
        $this->assertSame('15000.0000', $dashboard['forecast_30']['inflow']);
        $this->assertSame('0.0000', $dashboard['forecast_30']['outflow']);
        $this->assertSame('225000.0000', $dashboard['forecast_30']['closing']);

        $this->assertSame('4000.0000', $dashboard['advances']['total_base']);
        $this->assertSame(1, $dashboard['advances']['overdue_count']);
        $ana = collect($dashboard['advances']['by_recipient'])->firstWhere('recipient', 'Ana');
        $this->assertNotNull($ana);
        $this->assertSame('3000.0000', $ana['outstanding_base']);

        $this->assertSame(2, $dashboard['alerts']['total']);
        $this->assertSame(1, $dashboard['alerts']['critical']);
        $this->assertSame(1, $dashboard['alerts']['by_type']['insufficient_funds']);

        $this->assertSame(1, $dashboard['funds_in_transit']['count']);
        $this->assertSame('50000.0000', $dashboard['funds_in_transit']['amount_base']);
        $this->assertSame(1, $dashboard['cash_sessions_open']);
    }

    // ------------------------------------------------------------------
    // Operations report
    // ------------------------------------------------------------------

    public function test_operations_report_rows_totals_and_filters(): void
    {
        $this->seedOperation(['reference' => 'COMP-OP-1001']);
        $this->seedOperation([
            'reference' => 'COMP-OP-1002', 'type' => 'payment',
            'amount_original' => '5000.0000', 'amount_base' => '5000.0000',
            'entry_direction' => 'outflow', 'entry_amount' => '5000.0000',
        ]);

        $report = $this->service->report(1, 'operations', $this->filters());

        $this->assertCount(2, $report['rows']);
        $this->assertSame('10,000.00', $report['totals']['Inflows (base)']);
        $this->assertSame('5,000.00', $report['totals']['Outflows (base)']);
        $this->assertSame('5,000.00', $report['totals']['Net (base)']);
        $this->assertSame('2', $report['totals']['Movements']);
        $this->assertSame('COMP-OP-1001', $report['rows'][0][1]);
        $this->assertSame('outflow', $report['rows'][1][5]);
        $this->assertSame('5,000.00', $report['rows'][1][7]);

        $filtered = $this->service->report(1, 'operations', $this->filters(['type' => 'payment']));
        $this->assertCount(1, $filtered['rows']);
        $this->assertSame('COMP-OP-1002', $filtered['rows'][0][1]);

        $searched = $this->service->report(1, 'operations', $this->filters(['search' => 'OP-1001']));
        $this->assertCount(1, $searched['rows']);

        $none = $this->service->report(1, 'operations', $this->filters(['state' => 'draft']));
        $this->assertCount(0, $none['rows']);
    }

    // ------------------------------------------------------------------
    // Receipts report (CA01 / CA02 / CA15 / CA19)
    // ------------------------------------------------------------------

    public function test_receipts_report_allocations_and_totals(): void
    {
        $this->seedInvoice(1, '2026-11-17', '10000.00', 2);
        $receipt = $this->confirmedReceipt(['fees' => '200']);

        DB::table('treasury_settlement_allocations')->insert([
            'receipt_id' => $receipt->id, 'target_type' => 'invoice', 'target_id' => 1,
            'allocated_amount' => '6000.0000', 'allocated_amount_base' => '6000.0000',
            'currency' => 'MZN', 'exchange_rate' => '1.0000000000', 'state' => 'active',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $report = $this->service->report(1, 'receipts', $this->filters());

        $this->assertCount(1, $report['rows']);
        $row = $report['rows'][0];
        $this->assertSame('10,000.00 MZN', $row[4]);
        $this->assertSame('200.00', $row[5]);
        $this->assertSame('0.00', $row[6]);
        $this->assertSame('9,800.00', $row[8]);
        $this->assertSame('6,000.00', $row[9]);
        $this->assertSame('4,000.00', $row[10]);
        $this->assertSame('confirmed', $row[11]);

        $this->assertSame('10,000.00', $report['totals']['Gross']);
        $this->assertSame('200.00', $report['totals']['Fees']);
        $this->assertSame('9,800.00', $report['totals']['Net cash']);
        $this->assertSame('6,000.00', $report['totals']['Allocated (base)']);
        $this->assertSame('4,000.00', $report['totals']['Unapplied (base)']);
    }

    // ------------------------------------------------------------------
    // Payments report (CA05 / CA06 / CA20)
    // ------------------------------------------------------------------

    public function test_payments_report_attempts_and_remaining(): void
    {
        $requestId = DB::table('treasury_payment_requests')->insertGetId([
            'created_by' => 1, 'branch_id' => 1, 'source_account_id' => 1,
            'beneficiary_type' => 'vendor', 'beneficiary_name' => 'Fornecedor X',
            'amount_original' => '50000.0000', 'currency' => 'MZN',
            'amount_base' => '50000.0000', 'paid_amount_original' => '20000.0000',
            'paid_amount_base' => '20000.0000', 'due_date' => '2026-11-20',
            'client_reference' => 'INV-99', 'state' => 'approved', 'requested_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_payment_attempts')->insert([
            'payment_request_id' => $requestId, 'attempt_number' => 1,
            'idempotency_key' => 'KEY-001', 'provider' => 'manual',
            'state' => 'processing', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_payment_attempts')->insert([
            'payment_request_id' => $requestId, 'attempt_number' => 2,
            'idempotency_key' => 'KEY-002', 'provider' => 'manual',
            'state' => 'paid', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $report = $this->service->report(1, 'payments', $this->filters());

        $this->assertCount(1, $report['rows']);
        $row = $report['rows'][0];
        $this->assertSame('INV-99', $row[1]);
        $this->assertSame('Fornecedor X (vendor)', $row[2]);
        $this->assertSame('50,000.00', $row[4]);
        $this->assertSame('20,000.00', $row[5]);
        $this->assertSame('30,000.00', $row[6]);
        $this->assertSame('approved', $row[7]);
        $this->assertSame('2', $row[8]);
        $this->assertSame('paid', $row[9]);
        $this->assertSame('KEY-002', $row[10]);

        $this->assertSame('50,000.00', $report['totals']['Requested (base)']);
        $this->assertSame('20,000.00', $report['totals']['Paid (base)']);
        $this->assertSame('30,000.00', $report['totals']['Remaining (base)']);
    }

    // ------------------------------------------------------------------
    // Advances report (CA10)
    // ------------------------------------------------------------------

    public function test_advances_report_outstanding_and_reimbursements(): void
    {
        DB::table('treasury_advances')->insert([
            'created_by' => 1, 'branch_id' => 1, 'source_account_id' => 1,
            'recipient_type' => 'employee', 'recipient_id' => 2, 'recipient_name' => 'Ana',
            'purpose' => 'Travel', 'currency' => 'MZN',
            'amount_requested' => '5000.0000', 'amount_requested_base' => '5000.0000',
            'amount_approved' => '5000.0000', 'amount_approved_base' => '5000.0000',
            'amount_paid' => '5000.0000', 'amount_paid_base' => '5000.0000',
            'amount_substantiated' => '4200.0000', 'amount_substantiated_base' => '4200.0000',
            'amount_returned' => '800.0000', 'amount_returned_base' => '800.0000',
            'amount_outstanding' => '0.0000', 'amount_outstanding_base' => '0.0000',
            'reimbursement_amount' => '300.0000', 'reimbursement_amount_base' => '300.0000',
            'reimbursement_state' => 'pending', 'due_date' => '2026-11-20',
            'state' => 'regularised', 'requested_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $report = $this->service->report(1, 'advances', $this->filters());

        $this->assertCount(1, $report['rows']);
        $row = $report['rows'][0];
        $this->assertSame('Ana (employee)', $row[0]);
        $this->assertSame('Travel', $row[1]);
        $this->assertSame('5,000.00', $row[2]);
        $this->assertSame('5,000.00', $row[3]);
        $this->assertSame('4,200.00', $row[4]);
        $this->assertSame('800.00', $row[5]);
        $this->assertSame('0.00', $row[6]);
        $this->assertSame('300.00 (pending)', $row[7]);
        $this->assertSame('regularised', $row[10]);

        $this->assertSame('5,000.00', $report['totals']['Requested (base)']);
        $this->assertSame('4,200.00', $report['totals']['Substantiated (base)']);
        $this->assertSame('800.00', $report['totals']['Returned (base)']);
        $this->assertSame('0.00', $report['totals']['Outstanding (base)']);
        $this->assertSame('300.00', $report['totals']['Reimbursements pending (base)']);
    }

    // ------------------------------------------------------------------
    // Transfers + FX reports (CA08 / CA19)
    // ------------------------------------------------------------------

    protected function seedTransfers(): void
    {
        $usd = $this->accounts->createAccount(1, [
            'code' => 'BIM-USD', 'type' => 'bank', 'currency' => 'USD',
            'chart_account_id' => 1, 'account_holder' => 'Hoya Hoya Lda',
            'institution' => 'BIM', 'account_number' => 'USD-001',
        ], 1);

        DB::table('treasury_transfers')->insert([
            'created_by' => 1, 'branch_id' => 1, 'source_account_id' => 1,
            'destination_account_id' => $usd->id, 'source_currency' => 'MZN',
            'destination_currency' => 'USD', 'timing' => 'immediate',
            'amount_debited' => '2.0000', 'exchange_rate' => '0.0157480315',
            'rate_source' => 'manual', 'rate_date' => '2026-11-07',
            'base_exchange_rate' => '1.0000000000',
            'amount_credited' => '0.0314', 'amount_debited_base' => '2.0000',
            'amount_credited_base' => '1.9938', 'fee_amount' => '0.0000',
            'fee_base' => '0.0000', 'exchange_difference_base' => '0.0062',
            'state' => 'confirmed', 'confirmed_at' => '2026-11-07 10:00:00',
            'created_at' => '2026-11-07 10:00:00', 'updated_at' => now(),
        ]);
        DB::table('treasury_transfers')->insert([
            'created_by' => 1, 'branch_id' => 1, 'source_account_id' => 1,
            'destination_account_id' => $usd->id, 'source_currency' => 'MZN',
            'destination_currency' => 'MZN', 'timing' => 'immediate',
            'amount_debited' => '1000.0000', 'exchange_rate' => '1.0000000000',
            'base_exchange_rate' => '1.0000000000',
            'amount_credited' => '1000.0000', 'amount_debited_base' => '1000.0000',
            'amount_credited_base' => '1000.0000', 'fee_amount' => '0.0000',
            'fee_base' => '0.0000', 'exchange_difference_base' => '0.0000',
            'state' => 'confirmed', 'confirmed_at' => '2026-11-07 11:00:00',
            'created_at' => '2026-11-07 11:00:00', 'updated_at' => now(),
        ]);
    }

    public function test_transfers_report_fx_difference_and_totals(): void
    {
        $this->seedTransfers();

        $report = $this->service->report(1, 'transfers', $this->filters());

        $this->assertCount(2, $report['rows']);
        $row = collect($report['rows'])->firstWhere(3, 'MZN → USD');
        $this->assertNotNull($row);
        $this->assertSame('2.00 MZN', $row[4]);
        $this->assertSame('0.03 USD', $row[5]);
        $this->assertSame('0.0157480315', $row[6]);
        $this->assertSame('0.01', $row[8]);
        $this->assertSame('loss', $row[9]);
        $this->assertSame('confirmed', $row[11]);

        $this->assertSame('1,002.00', $report['totals']['Debited (base)']);
        $this->assertSame('1,001.99', $report['totals']['Credited (base)']);
        $this->assertSame('0.01', $report['totals']['Exchange losses (base)']);
        $this->assertSame('0.00', $report['totals']['Exchange gains (base)']);
        $this->assertSame('0.01', $report['totals']['Net difference (base)']);
    }

    public function test_fx_report_only_shows_exchange_difference_rows(): void
    {
        $this->seedTransfers();

        $report = $this->service->report(1, 'fx', $this->filters());

        $this->assertCount(1, $report['rows']);
        $row = $report['rows'][0];
        $this->assertSame('MZN → USD', $row[2]);
        $this->assertSame('0.0157480315', $row[3]);
        $this->assertSame('0.01', $row[8]);
        $this->assertSame('loss', $row[9]);

        $this->assertSame('0.01', $report['totals']['Exchange losses (base)']);
        $this->assertSame('0.00', $report['totals']['Exchange gains (base)']);
        $this->assertSame('0.01', $report['totals']['Net difference (base)']);
    }

    // ------------------------------------------------------------------
    // Cash-flow report (CA13 / CA14)
    // ------------------------------------------------------------------

    public function test_cash_flow_report_forecast_vs_actual(): void
    {
        $this->seedInvoice(1, '2026-11-17', '5000.00', 2);
        $this->seedOperation();

        $report = $this->service->report(1, 'cash-flow', $this->filters(['days' => 30, 'group' => 'day']));

        $this->assertSame('210,000.00', $report['totals']['Opening balance']);
        $this->assertSame('5,000.00', $report['totals']['Forecast inflow']);
        $this->assertSame('0.00', $report['totals']['Forecast outflow']);
        $this->assertSame('215,000.00', $report['totals']['Forecast closing']);
        $this->assertSame('10,000.00', $report['totals']['Actual inflow']);
        $this->assertSame('0.00', $report['totals']['Actual outflow']);

        $today = collect($report['rows'])->firstWhere(0, 'Sat 7 Nov 2026');
        $this->assertNotNull($today);
        $this->assertSame('10,000.00', $today[4]);
        $this->assertSame('0.00', $today[5]);

        $invoiceDay = collect($report['rows'])->firstWhere(0, 'Tue 17 Nov 2026');
        $this->assertNotNull($invoiceDay);
        $this->assertSame('5,000.00', $invoiceDay[1]);
    }

    // ------------------------------------------------------------------
    // Reconciliation report (CA11 / CA12)
    // ------------------------------------------------------------------

    public function test_reconciliation_report_counts_and_close_figures(): void
    {
        $receipt = $this->seedReconciliationSessionA();

        $bankId = (int) TreasuryFinancialAccount::findOrFail(1)->bank_account_id;

        $sessionB = DB::table('bank_reconciliation_sessions')->insertGetId([
            'bank_account_id' => $bankId, 'preset_key' => 'custom', 'status' => 'closed',
            'period_from' => '2026-10-01', 'period_to' => '2026-10-31',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $lineB = DB::table('bank_statement_lines')->insertGetId([
            'session_id' => $sessionB, 'row_index' => 1, 'txn_date' => '2026-10-15',
            'description' => 'Bank fee', 'amount' => '-25.0000', 'balance' => '205000.0000',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_missing_movements')->insert([
            'session_id' => $sessionB, 'statement_line_id' => $lineB,
            'category' => 'fee', 'offset_account_id' => 611, 'currency' => 'MZN',
            'amount_original' => '-25.0000', 'exchange_rate' => '1.0000000000',
            'amount_base' => '-25.0000', 'state' => 'confirmed',
            'operation_id' => $receipt->operation_id,
            'reason' => 'Bank fee', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $report = $this->service->report(1, 'reconciliation', $this->filters());

        $this->assertCount(2, $report['rows']);
        $rowA = collect($report['rows'])->firstWhere(1, '2026-11-01 → 2026-11-07');
        $this->assertNotNull($rowA);
        $this->assertSame('Hoya Hoya Lda', $rowA[0]);
        $this->assertSame('draft', $rowA[2]);
        $this->assertSame('209,950.00', $rowA[3]);
        $this->assertSame('210,000.00', $rowA[4]);
        $this->assertSame('-50.00', $rowA[5]);
        $this->assertSame('0.00', $rowA[6]);
        $this->assertSame('0.00', $rowA[7]);
        $this->assertSame('2', $rowA[8]);
        $this->assertSame('1', $rowA[9]);
        $this->assertSame('1', $rowA[10]);
        $this->assertSame('1', $rowA[11]);
        $this->assertSame('0', $rowA[12]);

        $rowB = collect($report['rows'])->firstWhere(1, '2026-10-01 → 2026-10-31');
        $this->assertNotNull($rowB);
        $this->assertSame('1', $rowB[8]);
        $this->assertSame('0', $rowB[9]);
        $this->assertSame('1', $rowB[10]);
        $this->assertSame('0', $rowB[11]);
        $this->assertSame('1', $rowB[12]);

        $this->assertSame('2', $report['totals']['Sessions']);
    }

    // ------------------------------------------------------------------
    // Audit trail (CA16)
    // ------------------------------------------------------------------

    public function test_audit_trail_filters_and_company_isolation(): void
    {
        // setUp already writes Treasury audit rows (account creation),
        // so assert relative to that baseline.
        $baseline = $this->service->auditTrail(1, $this->filters())['paginator']->total();
        $baselineActor2 = $this->service->auditTrail(1, $this->filters(['actor_id' => 2]))['paginator']->total();

        DimensionAuditEvent::create([
            'company_id' => 1, 'entity_type' => 'treasury_receipt', 'entity_id' => 1,
            'action' => 'confirmed', 'actor_id' => 2, 'occurred_at' => '2026-11-07 10:00:00',
        ]);
        DimensionAuditEvent::create([
            'company_id' => 1, 'entity_type' => 'treasury_transfer', 'entity_id' => 1,
            'action' => 'confirmed', 'actor_id' => 1, 'occurred_at' => '2026-11-07 09:00:00',
        ]);
        DimensionAuditEvent::create([
            'company_id' => 1, 'entity_type' => 'cost_centre', 'entity_id' => 1,
            'action' => 'created', 'actor_id' => 1, 'occurred_at' => '2026-11-07 11:00:00',
        ]);
        DimensionAuditEvent::create([
            'company_id' => 4, 'entity_type' => 'treasury_receipt', 'entity_id' => 1,
            'action' => 'confirmed', 'actor_id' => 2, 'occurred_at' => '2026-11-07 10:00:00',
        ]);

        $result = $this->service->auditTrail(1, $this->filters());
        $this->assertSame($baseline + 2, $result['paginator']->total());
        $this->assertSame('treasury_receipt', $result['paginator'][0]->entity_type);
        $this->assertSame('treasury_transfer', $result['paginator'][1]->entity_type);

        $byEntity = $this->service->auditTrail(1, $this->filters(['type' => 'treasury_transfer']));
        $this->assertSame(1, $byEntity['paginator']->total());

        $byAction = $this->service->auditTrail(1, $this->filters(['state' => 'confirmed']));
        $this->assertSame(2, $byAction['paginator']->total());

        $byActor = $this->service->auditTrail(1, $this->filters(['actor_id' => 2]));
        $this->assertSame($baselineActor2 + 1, $byActor['paginator']->total());

        $byDate = $this->service->auditTrail(1, $this->filters(['from' => '2026-11-08']));
        $this->assertSame(0, $byDate['paginator']->total());

        $otherCompany = $this->service->auditTrail(4, $this->filters());
        $this->assertSame(1, $otherCompany['paginator']->total());
    }

    // ------------------------------------------------------------------
    // CA03 — the one acceptance gate with no earlier test
    // ------------------------------------------------------------------

    public function test_ca03_double_confirm_creates_one_inflow(): void
    {
        $receipt = $this->confirmedReceipt();

        $this->assertSame(1, TreasuryOperation::count());
        $this->assertSame(1, TreasuryEntry::count());
        $this->assertSame(1, JournalEntry::count());

        try {
            $this->receipts->confirmReceipt($receipt->fresh(), 1);
            $this->fail('A second confirm must throw.');
        } catch (TreasuryException $e) {
            $this->assertSame('Only a pending receipt can be confirmed.', $e->getMessage());
        }

        // The failed second confirm posted nothing.
        $this->assertSame(1, TreasuryOperation::count());
        $this->assertSame(1, TreasuryEntry::count());
        $this->assertSame(1, JournalEntry::count());
    }
}
