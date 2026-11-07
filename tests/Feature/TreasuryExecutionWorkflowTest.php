<?php

namespace Tests\Feature;

use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryPaymentBatch;
use App\Models\TreasuryPaymentRequest;
use App\Models\User;
use App\Services\TreasuryAccountService;
use App\Services\TreasuryPaymentService;
use App\Services\TreasurySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Treasury Phase 3 — execution + batches over HTTP (plan §8, §10).
 */
class TreasuryExecutionWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['view treasury', 'manage treasury accounts', 'create treasury payment requests', 'approve treasury payments', 'execute treasury payments'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

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

        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'code' => 121, 'name' => 'Bank', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 2, 'code' => 421, 'name' => 'Accounts Payable', 'type' => 2, 'sub_type' => 1, 'created_by' => 1],
        ]);

        User::findOrFail(1)->givePermissionTo(['view treasury', 'manage treasury accounts', 'create treasury payment requests', 'approve treasury payments', 'execute treasury payments']);
        User::findOrFail(2)->givePermissionTo(['view treasury', 'create treasury payment requests', 'execute treasury payments']);
        User::findOrFail(3)->givePermissionTo(['view treasury', 'approve treasury payments']);
        User::findOrFail(4)->givePermissionTo(['view treasury', 'manage treasury accounts', 'create treasury payment requests', 'approve treasury payments']);
        User::findOrFail(5)->givePermissionTo(['view treasury', 'approve treasury payments']);

        DB::table('venders')->insert(['id' => 1, 'name' => 'Supplier', 'created_by' => 1]);

        $this->accounts = new TreasuryAccountService();
        $this->service = new TreasuryPaymentService($this->accounts);
    }

    protected function tearDown(): void
    {
        foreach (['treasury_payment_batch_items', 'treasury_payment_batches', 'treasury_payment_attempts', 'treasury_fund_reservations', 'treasury_approval_decisions', 'treasury_approval_steps', 'treasury_payment_requests', 'treasury_settlement_allocations', 'treasury_receipts', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'dimension_audit_events', 'bank_accounts', 'chart_of_accounts', 'bill_payments', 'bill_products', 'bills', 'venders', 'journal_entries', 'journal_items', 'transaction_lines', 'invoices', 'invoice_products', 'invoice_payments', 'credit_notes', 'taxes', 'customers', 'transactions', 'branches', 'branch_user', 'settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Index + create
    // ------------------------------------------------------------------

    public function test_payment_requests_index_happy_path(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $this->fundAccount();
        $this->createRequestViaHttp()->assertRedirect();

        $response = $this->actingAs(User::findOrFail(1))->get(route('treasury-payment-requests.index'));

        $response->assertOk();
        $response->assertSee('Draft');
    }

    public function test_create_payment_request_happy_path(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $this->fundAccount();

        $this->createRequestViaHttp()->assertRedirect();

        $request = TreasuryPaymentRequest::where('created_by', 1)->firstOrFail();
        $this->assertTrue($request->isDraft());
        $this->assertSame('50000.0000', (string) $request->amount_original);
        $this->assertNull($request->operation->reference);

        // The draft show page renders.
        $this->actingAs(User::findOrFail(1))->get(route('treasury-payment-requests.show', $request))->assertOk();
    }

    public function test_create_payment_request_permission_denied(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $this->fundAccount();

        $response = $this->createRequestViaHttp(6);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');
        $this->assertSame(0, TreasuryPaymentRequest::count());
    }

    public function test_payment_requests_index_permission_denied(): void
    {
        $response = $this->actingAs(User::findOrFail(6))->get(route('treasury-payment-requests.index'));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');
    }

    public function test_show_other_company_request_redirects_with_error_not_500(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $this->fundAccount();
        $this->createRequestViaHttp()->assertRedirect();
        $request = TreasuryPaymentRequest::where('created_by', 1)->firstOrFail();

        $response = $this->actingAs(User::findOrFail(4))->get(route('treasury-payment-requests.show', $request));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Payment request not found for this company.');
    }

    // ------------------------------------------------------------------
    // Full workflow over HTTP
    // ------------------------------------------------------------------

    public function test_full_workflow_create_submit_send_approve(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $this->fundAccount();

        $this->createRequestViaHttp()->assertRedirect();
        $request = TreasuryPaymentRequest::where('created_by', 1)->firstOrFail();

        $this->actingAs(User::findOrFail(2))->post(route('treasury-payment-requests.submit', $request))->assertRedirect();
        $this->assertSame('under_review', $request->fresh()->state);
        $this->assertSame('COMP-PAY-2026-0001', $request->fresh()->operation->reference);

        $this->actingAs(User::findOrFail(2))->post(route('treasury-payment-requests.send-for-approval', $request))->assertRedirect();
        $this->assertSame('awaiting_approval', $request->fresh()->state);

        $step = $request->fresh()->steps()->firstOrFail();
        $response = $this->actingAs(User::findOrFail(3))->post(route('treasury-approval-steps.decide', $step), [
            'decision' => 'approve',
        ]);
        $response->assertRedirect(route('treasury-payment-requests.show', $request));

        $request = $request->fresh();
        $this->assertTrue($request->isApproved());
        $this->assertNotNull($request->approved_snapshot);
        $this->assertSame('50000.0000', (string) $request->approved_snapshot['amount_original']);

        // The show page renders the approved state and the step.
        $show = $this->actingAs(User::findOrFail(1))->get(route('treasury-payment-requests.show', $request));
        $show->assertOk();
        $show->assertSee('COMP-PAY-2026-0001');
        $show->assertSee('Approved');
    }

    public function test_reject_path_via_http(): void
    {
        $request = $this->submittedRequest();

        $step = $request->steps()->firstOrFail();
        $response = $this->actingAs(User::findOrFail(3))->post(route('treasury-approval-steps.decide', $step), [
            'decision' => 'reject',
            'reason' => 'Wrong supplier',
        ]);

        $response->assertRedirect(route('treasury-payment-requests.show', $request));
        $this->assertSame('rejected', $request->fresh()->state);
        $this->assertSame('Wrong supplier', $request->fresh()->rejection_reason);
    }

    public function test_self_approval_via_http_is_refused(): void
    {
        $request = $this->submittedRequest();
        $step = $request->steps()->firstOrFail();

        $response = $this->actingAs(User::findOrFail(2))->post(route('treasury-approval-steps.decide', $step), [
            'decision' => 'approve',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame('awaiting_approval', $request->fresh()->state);
    }

    public function test_approval_invalidation_path_via_http(): void
    {
        $request = $this->approvedRequestViaHttp();

        // A protected change invalidates the approval (CA05).
        $response = $this->actingAs(User::findOrFail(2))->put(route('treasury-payment-requests.update', $request), array_merge(
            $this->requestPayload($request->sourceAccount),
            ['amount_original' => '60000']
        ));
        $response->assertRedirect(route('treasury-payment-requests.show', $request));

        $request = $request->fresh();
        $this->assertSame('under_review', $request->state);
        $this->assertNull($request->approved_snapshot);
        $this->assertSame('invalidated', $request->steps()->first()->status);

        // Send for approval again regenerates the steps; approval
        // completes with the new amount.
        $this->actingAs(User::findOrFail(2))->post(route('treasury-payment-requests.send-for-approval', $request))->assertRedirect();
        $step = $request->fresh()->steps()->where('status', 'pending')->firstOrFail();
        $this->actingAs(User::findOrFail(3))->post(route('treasury-approval-steps.decide', $step), [
            'decision' => 'approve',
        ])->assertRedirect();

        $request = $request->fresh();
        $this->assertTrue($request->isApproved());
        $this->assertSame('60000.0000', (string) $request->approved_snapshot['amount_original']);
    }

    public function test_approvals_queue_lists_pending_step_for_approver(): void
    {
        $request = $this->submittedRequest();

        $response = $this->actingAs(User::findOrFail(3))->get(route('treasury-payment-requests.approvals'));
        $response->assertOk();
        $response->assertSee('COMP-PAY-2026-0001');

        // The requester has no approve permission — no queue for them.
        $denied = $this->actingAs(User::findOrFail(2))->get(route('treasury-payment-requests.approvals'));
        $denied->assertRedirect();
        $denied->assertSessionHas('error', 'Permission denied.');
    }

    public function test_reservations_page_shows_reservation_after_approval(): void
    {
        TreasurySettings::save(1, ['treasury_reservation_policy' => 'on']);

        $request = $this->approvedRequestViaHttp();

        $this->assertSame(1, $request->fresh()->reservations()->count());
        $this->assertSame('active', $request->fresh()->reservations()->first()->state);

        $response = $this->actingAs(User::findOrFail(1))->get(route('treasury-payment-requests.reservations'));
        $response->assertOk();
        $response->assertSee('50,000.00');
        $response->assertSee('COMP-PAY-2026-0001');
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

    /** Give the account a 200,000 approved opening balance. */
    protected function fundAccount(): void
    {
        $account = TreasuryFinancialAccount::where('created_by', 1)->firstOrFail();

        DB::table('treasury_opening_balances')->insert([
            'financial_account_id' => $account->id, 'created_by' => 1, 'currency' => 'MZN',
            'amount' => '200000.0000', 'amount_base' => '200000.0000', 'cut_off_date' => '2026-11-01',
            'batch_reference' => 'BATCH-1', 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function requestPayload(TreasuryFinancialAccount $account, array $overrides = []): array
    {
        return array_merge([
            'source_account_id' => $account->id,
            'beneficiary_type' => 'vendor',
            'beneficiary_id' => 1,
            'amount_original' => '50000',
            'currency' => 'MZN',
            'reason' => 'Supplier payment',
        ], $overrides);
    }

    protected function createRequestViaHttp(int $userId = 2, ?TreasuryFinancialAccount $account = null, array $overrides = [])
    {
        $account ??= TreasuryFinancialAccount::where('created_by', 1)->firstOrFail();

        return $this->actingAs(User::findOrFail($userId))
            ->post(route('treasury-payment-requests.store'), $this->requestPayload($account, $overrides));
    }

    protected function submittedRequest(): TreasuryPaymentRequest
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $this->fundAccount();
        $this->createRequestViaHttp()->assertRedirect();
        $request = TreasuryPaymentRequest::where('created_by', 1)->firstOrFail();

        $this->actingAs(User::findOrFail(2))->post(route('treasury-payment-requests.submit', $request))->assertRedirect();
        $this->actingAs(User::findOrFail(2))->post(route('treasury-payment-requests.send-for-approval', $request))->assertRedirect();

        return $request->fresh();
    }

    protected function approvedRequestViaHttp(): TreasuryPaymentRequest
    {
        $request = $this->submittedRequest();
        $step = $request->steps()->firstOrFail();

        $this->actingAs(User::findOrFail(3))->post(route('treasury-approval-steps.decide', $step), [
            'decision' => 'approve',
        ])->assertRedirect();

        $this->assertTrue($request->fresh()->isApproved());

        return $request->fresh();
    }


    // ------------------------------------------------------------------
    // Schema (Phase 0/1 tables copied from TreasuryReceiptWorkflowTest,
    // plus the Phase 2 tables mirrored from migrations 080000–080400)
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
        Schema::create('bills', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('bill_id')->nullable();
            $table->bigInteger('vender_id')->nullable();
            $table->integer('status')->default(0);
            $table->date('bill_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('voided_at')->nullable();
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

        // Phase 2 tables (mirrored from migrations 080000–080400).
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
    // Execution over HTTP
    // ------------------------------------------------------------------

    public function test_execute_form_renders_recheck_results(): void
    {
        $request = $this->approvedRequestViaHttp();

        $response = $this->actingAs(User::findOrFail(2))->get(route('treasury-payment-requests.execute-form', $request));

        $response->assertOk();
        $response->assertSee('Execution check');
        $response->assertSee('Passed');
        $response->assertSee('Confirm execution');
    }

    public function test_execute_happy_path_via_http(): void
    {
        $request = $this->approvedRequestViaHttp();

        $response = $this->actingAs(User::findOrFail(2))->post(route('treasury-payment-requests.execute', $request), [
            'amount_original' => '50000',
            'idempotency_key' => 'http-exec-1',
        ]);

        $response->assertRedirect(route('treasury-payment-requests.show', $request));
        $this->assertTrue($request->fresh()->isPaid());
        $this->assertSame(1, DB::table('journal_entries')->count());
        $this->assertSame(1, DB::table('treasury_entries')->count());
    }

    public function test_execute_partial_via_http(): void
    {
        $request = $this->approvedRequestViaHttp();

        $response = $this->actingAs(User::findOrFail(2))->post(route('treasury-payment-requests.execute', $request), [
            'amount_original' => '20000',
            'idempotency_key' => 'http-exec-2',
        ]);

        $response->assertRedirect(route('treasury-payment-requests.show', $request));
        $request = $request->fresh();
        $this->assertTrue($request->isProcessing());
        $this->assertSame('20000.0000', (string) $request->paid_amount_base);
        $this->assertSame('30000.0000', $request->remainingAmountBase());
    }

    public function test_execute_failure_via_http_keeps_request_approved(): void
    {
        $request = $this->approvedRequestViaHttp();

        $response = $this->actingAs(User::findOrFail(2))->post(route('treasury-payment-requests.execute', $request), [
            'amount_original' => '60000',
            'idempotency_key' => 'http-exec-3',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertTrue($request->fresh()->isApproved());
        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->assertSame(0, DB::table('treasury_payment_attempts')->count());
    }

    public function test_execute_permission_denied(): void
    {
        $request = $this->approvedRequestViaHttp();

        $response = $this->actingAs(User::findOrFail(6))->get(route('treasury-payment-requests.execute-form', $request));
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');

        $response = $this->actingAs(User::findOrFail(6))->post(route('treasury-payment-requests.execute', $request), [
            'amount_original' => '50000',
            'idempotency_key' => 'http-exec-4',
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');
        $this->assertTrue($request->fresh()->isApproved());
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_execute_cross_company_redirects_with_error(): void
    {
        $request = $this->approvedRequestViaHttp();
        User::findOrFail(4)->givePermissionTo('execute treasury payments');

        $response = $this->actingAs(User::findOrFail(4))->get(route('treasury-payment-requests.execute-form', $request));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertTrue($request->fresh()->isApproved());
    }

    // ------------------------------------------------------------------
    // Batches over HTTP
    // ------------------------------------------------------------------

    public function test_batch_happy_path_via_http(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $this->fundAccount();
        $account = TreasuryFinancialAccount::where('created_by', 1)->firstOrFail();

        $first = $this->approvedRequestOnAccount($account);
        $second = $this->approvedRequestOnAccount($account);

        $response = $this->actingAs(User::findOrFail(2))->post(route('treasury-payment-batches.store'), [
            'payment_request_id' => [0 => $first->id, 1 => $second->id],
            'amount_original' => [0 => '50000', 1 => '50000'],
        ]);
        $response->assertRedirect();
        $batch = TreasuryPaymentBatch::where('created_by', 1)->firstOrFail();
        $this->assertTrue($batch->isDraft());

        $response = $this->actingAs(User::findOrFail(2))->post(route('treasury-payment-batches.execute', $batch));
        $response->assertRedirect(route('treasury-payment-batches.show', $batch));

        $this->assertTrue($batch->fresh()->isPaid());
        $this->assertNotNull($batch->fresh()->reference);
        $this->assertTrue($first->fresh()->isPaid());
        $this->assertTrue($second->fresh()->isPaid());
        $this->assertSame(2, DB::table('journal_entries')->count());
    }

    public function test_batch_partial_success_via_http(): void
    {
        // Fund the account with only 60,000: the second 50,000 item fails.
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $account = TreasuryFinancialAccount::where('created_by', 1)->firstOrFail();
        DB::table('treasury_opening_balances')->insert([
            'financial_account_id' => $account->id,
            'created_by' => 1, 'currency' => 'MZN',
            'amount' => '60000.0000', 'amount_base' => '60000.0000', 'cut_off_date' => '2026-11-01',
            'batch_reference' => 'BATCH-1', 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $first = $this->approvedRequestOnAccount($account);
        $second = $this->approvedRequestOnAccount($account);

        $this->actingAs(User::findOrFail(2))->post(route('treasury-payment-batches.store'), [
            'payment_request_id' => [0 => $first->id, 1 => $second->id],
            'amount_original' => [0 => '50000', 1 => '50000'],
        ])->assertRedirect();
        $batch = TreasuryPaymentBatch::where('created_by', 1)->firstOrFail();

        $response = $this->actingAs(User::findOrFail(2))->post(route('treasury-payment-batches.execute', $batch));
        $response->assertRedirect(route('treasury-payment-batches.show', $batch));

        $batch = $batch->fresh();
        $this->assertTrue($batch->isPartiallyPaid());
        $this->assertTrue($first->fresh()->isPaid());
        $this->assertTrue($second->fresh()->isApproved());
        $this->assertSame(1, DB::table('journal_entries')->count());
    }

    /** Approve a request on an already-funded account (no re-funding). */
    protected function approvedRequestOnAccount(TreasuryFinancialAccount $account): TreasuryPaymentRequest
    {
        $this->createRequestViaHttp(2, $account)->assertRedirect();
        $request = TreasuryPaymentRequest::where('created_by', 1)->orderByDesc('id')->firstOrFail();

        $this->actingAs(User::findOrFail(2))->post(route('treasury-payment-requests.submit', $request))->assertRedirect();
        $this->actingAs(User::findOrFail(2))->post(route('treasury-payment-requests.send-for-approval', $request))->assertRedirect();

        $step = $request->fresh()->steps()->firstOrFail();
        $this->actingAs(User::findOrFail(3))->post(route('treasury-approval-steps.decide', $step), [
            'decision' => 'approve',
        ])->assertRedirect();

        $this->assertTrue($request->fresh()->isApproved());

        return $request->fresh();
    }

    public function test_batch_index_and_show_render(): void
    {
        $request = $this->approvedRequestViaHttp();
        $this->actingAs(User::findOrFail(2))->post(route('treasury-payment-batches.store'), [
            'payment_request_id' => [0 => $request->id],
            'amount_original' => [0 => '50000'],
        ])->assertRedirect();
        $batch = TreasuryPaymentBatch::where('created_by', 1)->firstOrFail();

        $this->actingAs(User::findOrFail(1))->get(route('treasury-payment-batches.index'))->assertOk()->assertSee('Payment Batches');
        $this->actingAs(User::findOrFail(1))->get(route('treasury-payment-batches.show', $batch))->assertOk()->assertSee('Draft');
    }
}
