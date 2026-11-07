<?php

namespace Tests\Unit;

use App\Exceptions\TreasuryException;
use App\Models\TreasuryApprovalDecision;
use App\Models\TreasuryApprovalStep;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryPaymentBatch;
use App\Models\TreasuryPaymentRequest;
use App\Services\TreasuryAccountService;
use App\Services\TreasuryExecutionService;
use App\Services\TreasuryPaymentBatchService;
use App\Services\TreasuryPaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Treasury Phase 3 — payment batches (plan §10 exit gate CA07).
 *
 * Users: 1 = company, 2 = requester, 3 = approver, 5 = second
 * approver, 4 = other company.
 */
class TreasuryPaymentBatchServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Requester', 'email' => 'requester@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 5, 'name' => 'Second Approver', 'email' => 'approver2@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Maputo', 'created_by' => 1],
        ]);

        DB::table('permissions')->insert([
            ['id' => 1, 'name' => 'approve treasury payments', 'guard_name' => 'web'],
        ]);
        foreach ([2, 3, 5] as $userId) {
            DB::table('model_has_permissions')->insert([
                'permission_id' => 1, 'model_type' => 'App\\Models\\User', 'model_id' => $userId,
            ]);
        }

        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'code' => 121, 'name' => 'Bank', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 2, 'code' => 421, 'name' => 'Accounts Payable', 'type' => 2, 'sub_type' => 1, 'created_by' => 1],
        ]);

        DB::table('venders')->insert(['id' => 1, 'name' => 'Supplier', 'created_by' => 1, 'balance' => '0.0000']);

        $this->accounts = new TreasuryAccountService();
        $this->service = new TreasuryPaymentService($this->accounts);
        $this->execution = new TreasuryExecutionService($this->accounts, $this->service);
        $this->batches = new TreasuryPaymentBatchService($this->execution, $this->service);
        $this->account = $this->makeAccount('200000.0000');
    }

    protected function tearDown(): void
    {
        foreach (['treasury_payment_batch_items', 'treasury_payment_batches', 'treasury_settlement_allocations', 'treasury_payment_attempts', 'treasury_fund_reservations', 'treasury_approval_decisions', 'treasury_approval_steps', 'treasury_payment_requests', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'dimension_audit_events', 'bank_accounts', 'chart_of_accounts', 'bill_payments', 'bill_products', 'bills', 'venders', 'branches', 'settings', 'users', 'permissions', 'model_has_permissions', 'journal_entries', 'journal_items', 'transaction_lines', 'transactions'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function makeAccount(string $balance, string $code = 'BIM-001'): TreasuryFinancialAccount
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
            'batch_reference' => 'BATCH-1', 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $account->fresh();
    }

    protected function requestData(array $overrides = []): array
    {
        return array_merge([
            'source_account_id' => $this->account->id,
            'beneficiary_type' => 'vendor',
            'beneficiary_id' => 1,
            'amount_original' => '50000',
            'currency' => 'MZN',
            'reason' => 'Supplier payment',
        ], $overrides);
    }

    protected function makeRequest(array $overrides = [], int $requesterId = 2): TreasuryPaymentRequest
    {
        return $this->service->createRequest(1, $this->requestData($overrides), $requesterId);
    }

    protected function approvedRequest(array $overrides = [], int $requesterId = 2): TreasuryPaymentRequest
    {
        $request = $this->makeRequest($overrides, $requesterId);
        $this->service->submitRequest($request, $requesterId);
        $this->service->sendForApproval($request->fresh(), $requesterId);

        return $request->fresh();
    }

    protected function approveStep(TreasuryPaymentRequest $request, int $level, int $actorId): void
    {
        $step = $request->steps()->where('level', $level)->where('status', TreasuryApprovalStep::STATUS_PENDING)->firstOrFail();
        $this->service->decide($step, $actorId, TreasuryApprovalDecision::DECISION_APPROVE);
    }

    protected function approveRequest(TreasuryPaymentRequest $request): TreasuryPaymentRequest
    {
        $request = $request->fresh();
        $levels = $request->steps()->where('status', TreasuryApprovalStep::STATUS_PENDING)->orderBy('level')->get();
        $approvers = [3, 5];
        foreach ($levels as $index => $step) {
            $this->approveStep($request->fresh(), (int) $step->level, $approvers[$index]);
        }

        return $request->fresh();
    }

    protected function makeBill(int $id, string $total, int $venderId = 1): void
    {
        DB::table('bills')->insert([
            'id' => $id, 'bill_id' => $id, 'created_by' => 1, 'vender_id' => $venderId,
            'status' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('bill_products')->insert(['id' => $id, 'bill_id' => $id, 'total' => $total]);
    }

    protected function executeApproved(array $overrides = [], string $amount = '50000', array $options = []): array
    {
        $request = $this->approvedRequest($overrides);
        $request = $this->approveRequest($request);

        return $this->execution->execute(1, $request->id, $amount, 2, array_merge(['idempotency_key' => 'exec-' . $request->id], $options));
    }


    // ------------------------------------------------------------------
    // Schema (Phase 2 tables + the poster/ledger tables Phase 3
    // writes + the Phase 3 execution columns and batch tables)
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

        Schema::create('venders', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->default(0);
            $table->decimal('balance', 20, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('bills', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('bill_id')->nullable();
            $table->bigInteger('vender_id')->nullable();
            $table->integer('status')->default(0);
            $table->timestamp('voided_at')->nullable();
            $table->date('send_date')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('bill_products', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('bill_id');
            $table->decimal('total', 20, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('bill_payments', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('bill_id');
            $table->date('date')->nullable();
            $table->decimal('amount', 20, 4)->default(0);
            $table->integer('account_id')->nullable();
            $table->integer('payment_method')->default(0);
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
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

        Schema::create('treasury_payment_requests', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('operation_id')->nullable();
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('source_account_id');
            $table->string('beneficiary_type', 20);
            $table->bigInteger('beneficiary_id')->nullable();
            $table->string('beneficiary_name', 150)->nullable();
            $table->string('beneficiary_account', 150)->nullable();
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
            $table->integer('executor_user_id')->nullable();
            $table->json('approved_snapshot')->nullable();
            $table->integer('approved_rule_version')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'client_reference'], 'tpr_client_ref_uq');
            $table->index(['created_by', 'state'], 'tpr_company_state_idx');
            $table->index(['source_account_id'], 'tpr_account_idx');
            $table->index(['beneficiary_type', 'beneficiary_id'], 'tpr_beneficiary_idx');
            $table->index(['operation_id'], 'tpr_operation_idx');
            $table->index(['requested_by'], 'tpr_requested_idx');
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
            $table->string('payload_hash', 64);
            $table->decimal('amount_original', 20, 4)->nullable();
            $table->decimal('amount_base', 20, 4)->nullable();
            $table->string('currency', 3)->nullable();
            $table->bigInteger('journal_id')->nullable();
            $table->bigInteger('entry_id')->nullable();
            $table->integer('created_by');
            $table->timestamps();
            $table->unique(['idempotency_key'], 'tpa_key_uq');
            $table->index(['payment_request_id'], 'tpa_request_idx');
            $table->index(['payment_request_id', 'attempt_number'], 'tpa_request_attempt_idx');
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
            $table->index(['payment_request_id'], 'tas_request_idx');
            $table->index(['payment_request_id', 'level'], 'tas_request_level_idx');
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
            $table->index(['payment_request_id'], 'tad_request_idx');
            $table->index(['approval_step_id'], 'tad_step_idx');
            $table->index(['actor_id'], 'tad_actor_idx');
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
            $table->index(['financial_account_id'], 'tfr_account_idx');
            $table->index(['payment_request_id'], 'tfr_request_idx');
            $table->index(['financial_account_id', 'state'], 'tfr_account_state_idx');
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
            $table->unique(['created_by', 'reference'], 'tpb_company_ref_uq');
            $table->index(['created_by', 'state'], 'tpb_company_state_idx');
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
            $table->unique(['batch_id', 'payment_request_id'], 'tpbi_batch_request_uq');
            $table->index(['batch_id'], 'tpbi_batch_idx');
            $table->index(['payment_request_id'], 'tpbi_request_idx');
            $table->index(['attempt_id'], 'tpbi_attempt_idx');
        });
    }

    // ------------------------------------------------------------------
    // Creation
    // ------------------------------------------------------------------

    protected function approvedRequestPair(): array
    {
        $first = $this->approvedRequest(['amount_original' => '50000']);
        $first = $this->approveRequest($first);
        $second = $this->approvedRequest(['amount_original' => '30000']);
        $second = $this->approveRequest($second);

        return [$first, $second];
    }

    public function test_create_batch_defaults_to_remaining_amounts(): void
    {
        [$first, $second] = $this->approvedRequestPair();

        $batch = $this->batches->createBatch(1, [
            'items' => [
                ['payment_request_id' => $first->id],
                ['payment_request_id' => $second->id, 'amount_original' => '10000'],
            ],
        ], 2);

        $this->assertTrue($batch->isDraft());
        $this->assertNull($batch->reference);
        $items = $batch->items()->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertSame('50000.0000', (string) $items[0]->amount_original);
        $this->assertSame('10000.0000', (string) $items[1]->amount_original);
        $this->assertSame('10000.0000', (string) $items[1]->amount_base);
    }

    public function test_create_batch_refuses_unapproved_request(): void
    {
        $draft = $this->makeRequest();

        try {
            $this->batches->createBatch(1, ['items' => [['payment_request_id' => $draft->id]]], 2);
            $this->fail('A draft request was batched.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('not approved', strtolower($e->getMessage()));
        }
    }

    public function test_create_batch_refuses_request_in_another_open_batch(): void
    {
        [$first] = $this->approvedRequestPair();
        $this->batches->createBatch(1, ['items' => [['payment_request_id' => $first->id]]], 2);

        try {
            $this->batches->createBatch(1, ['items' => [['payment_request_id' => $first->id]]], 2);
            $this->fail('A request was put in two open batches.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('already in an open batch', strtolower($e->getMessage()));
        }
    }

    public function test_cancel_draft_batch_skips_pending_items(): void
    {
        [$first, $second] = $this->approvedRequestPair();
        $batch = $this->batches->createBatch(1, [
            'items' => [
                ['payment_request_id' => $first->id],
                ['payment_request_id' => $second->id],
            ],
        ], 2);

        $batch = $this->batches->cancelBatch($batch, 2);

        $this->assertSame('cancelled', $batch->state);
        $this->assertSame(2, $batch->items()->where('state', 'skipped')->count());
    }

    // ------------------------------------------------------------------
    // Execution (CA07)
    // ------------------------------------------------------------------

    public function test_execute_batch_settles_all_items_and_allocates_reference(): void
    {
        [$first, $second] = $this->approvedRequestPair();
        $batch = $this->batches->createBatch(1, [
            'items' => [
                ['payment_request_id' => $first->id],
                ['payment_request_id' => $second->id],
            ],
        ], 2);

        $batch = $this->batches->executeBatch(1, $batch->id, 2);

        $this->assertTrue($batch->isPaid());
        $this->assertSame('COMP-BCH-2026-0001', $batch->reference);
        $this->assertSame(2, $batch->items()->where('state', 'paid')->count());
        $this->assertTrue($first->fresh()->isPaid());
        $this->assertTrue($second->fresh()->isPaid());
        $this->assertSame('120000.0000', $this->accounts->postedBalance($this->account->fresh()));
        $this->assertSame(2, DB::table('journal_entries')->count());
    }

    public function test_execute_batch_partial_success_keeps_failed_item_for_review(): void
    {
        // The account funds only the first item: the second item
        // fails its availability recheck, the first stays settled.
        $small = $this->makeAccount('60000.0000', 'BIM-002');
        $first = $this->approvedRequest(['amount_original' => '50000', 'source_account_id' => $small->id]);
        $first = $this->approveRequest($first);
        $second = $this->approvedRequest(['amount_original' => '50000', 'source_account_id' => $small->id]);
        $second = $this->approveRequest($second);

        $batch = $this->batches->createBatch(1, [
            'items' => [
                ['payment_request_id' => $first->id],
                ['payment_request_id' => $second->id],
            ],
        ], 2);

        $batch = $this->batches->executeBatch(1, $batch->id, 2);

        $this->assertTrue($batch->isPartiallyPaid());
        $items = $batch->items()->orderBy('id')->get();
        $this->assertSame('paid', $items[0]->state);
        $this->assertSame('failed', $items[1]->state);
        $this->assertNotNull($items[1]->failure_reason);
        $this->assertStringContainsString('available balance', strtolower($items[1]->failure_reason));
        $this->assertTrue($first->fresh()->isPaid());
        $this->assertTrue($second->fresh()->isApproved());
        $this->assertSame(1, DB::table('journal_entries')->count());
        $this->assertSame('10000.0000', $this->accounts->postedBalance($small->fresh()));

        // Fund the account and re-run: the failed item settles and
        // the batch completes.
        DB::table('treasury_opening_balances')->insert([
            'financial_account_id' => $small->id, 'created_by' => 1, 'currency' => 'MZN',
            'amount' => '50000.0000', 'amount_base' => '50000.0000', 'cut_off_date' => '2026-11-02',
            'batch_reference' => 'BATCH-2', 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $batch = $this->batches->executeBatch(1, $batch->id, 2);

        $this->assertTrue($batch->isPaid());
        $this->assertSame(2, $batch->items()->where('state', 'paid')->count());
        $this->assertTrue($second->fresh()->isPaid());
        $this->assertSame(2, DB::table('journal_entries')->count());
    }

    public function test_execute_batch_where_every_item_fails_marks_batch_failed(): void
    {
        $small = $this->makeAccount('10000.0000', 'BIM-003');
        $first = $this->approvedRequest(['amount_original' => '50000', 'source_account_id' => $small->id]);
        $first = $this->approveRequest($first);

        $batch = $this->batches->createBatch(1, ['items' => [['payment_request_id' => $first->id]]], 2);
        $batch = $this->batches->executeBatch(1, $batch->id, 2);

        $this->assertSame('failed', $batch->state);
        $this->assertSame('failed', $batch->items()->first()->state);
        $this->assertTrue($first->fresh()->isApproved());
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_execute_batch_twice_does_not_double_pay(): void
    {
        [$first, $second] = $this->approvedRequestPair();
        $batch = $this->batches->createBatch(1, [
            'items' => [
                ['payment_request_id' => $first->id],
                ['payment_request_id' => $second->id],
            ],
        ], 2);

        $this->batches->executeBatch(1, $batch->id, 2);

        try {
            $this->batches->executeBatch(1, $batch->id, 2);
            $this->fail('A paid batch was executed again.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('cannot be executed', strtolower($e->getMessage()));
        }

        $this->assertSame(2, DB::table('journal_entries')->count());
        $this->assertSame(2, DB::table('treasury_payment_attempts')->count());
    }

    public function test_batch_for_other_company_is_rejected(): void
    {
        [$first] = $this->approvedRequestPair();
        $batch = $this->batches->createBatch(1, ['items' => [['payment_request_id' => $first->id]]], 2);

        try {
            $this->batches->executeBatch(4, $batch->id, 2);
            $this->fail('Cross-company batch execution was allowed.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('not found for this company', strtolower($e->getMessage()));
        }
    }
}
