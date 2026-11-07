<?php

namespace Tests\Feature;

use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryTransfer;
use App\Models\User;
use App\Services\TreasuryAccountService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Treasury Phase 4 — transfers + FX over HTTP (plan §8, §10).
 */
class TreasuryTransferWorkflowTest extends TestCase
{
    protected TreasuryAccountService $accounts;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['view treasury', 'manage treasury accounts', 'manage treasury transfers'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Operator', 'email' => 'operator@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Maputo', 'created_by' => 1],
        ]);

        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'code' => 121, 'name' => 'Bank source', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 2, 'code' => 122, 'name' => 'Bank destination', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 3, 'code' => 123, 'name' => 'Transit', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 4, 'code' => 61, 'name' => 'Bank fees', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 5, 'code' => 694, 'name' => 'FX losses', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 6, 'code' => 784, 'name' => 'FX gains', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
        ]);

        User::findOrFail(1)->givePermissionTo(['view treasury', 'manage treasury accounts', 'manage treasury transfers']);
        User::findOrFail(2)->givePermissionTo(['view treasury', 'manage treasury transfers']);
        User::findOrFail(4)->givePermissionTo(['view treasury', 'manage treasury accounts', 'manage treasury transfers']);

        $this->accounts = new TreasuryAccountService();
    }

    protected function tearDown(): void
    {
        foreach (['treasury_transfers', 'treasury_payment_batch_items', 'treasury_payment_batches', 'treasury_settlement_allocations', 'treasury_receipts', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'dimension_audit_events', 'bank_accounts', 'chart_of_accounts', 'bill_payments', 'bill_products', 'bills', 'venders', 'journal_entries', 'journal_items', 'transaction_lines', 'invoices', 'invoice_products', 'invoice_payments', 'credit_notes', 'taxes', 'customers', 'transactions', 'branches', 'branch_user', 'settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
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

    /** Give an account an approved opening balance. */
    protected function fundAccount(TreasuryFinancialAccount $account, string $amount, string $baseAmount): void
    {
        DB::table('treasury_opening_balances')->insert([
            'financial_account_id' => $account->id, 'created_by' => 1, 'currency' => $account->currency,
            'amount' => $amount, 'amount_base' => $baseAmount, 'cut_off_date' => '2026-11-01',
            'batch_reference' => 'BATCH-' . $account->code, 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Two MZN bank accounts + one MZN transit account, source funded with 200,000. */
    protected function seedAccounts(): array
    {
        $this->createAccountViaHttp(1, ['code' => 'BIM-001', 'chart_account_id' => 1])->assertRedirect(route('treasury-accounts.index'));
        $this->createAccountViaHttp(1, ['code' => 'BIM-002', 'chart_account_id' => 2, 'account_number' => '987654321'])->assertRedirect(route('treasury-accounts.index'));
        $this->createAccountViaHttp(1, ['code' => 'TRF-001', 'type' => 'funds_in_transit', 'chart_account_id' => 3, 'account_number' => 'TRF'])->assertRedirect(route('treasury-accounts.index'));

        $source = TreasuryFinancialAccount::where('code', 'BIM-001')->firstOrFail();
        $destination = TreasuryFinancialAccount::where('code', 'BIM-002')->firstOrFail();
        $transit = TreasuryFinancialAccount::where('code', 'TRF-001')->firstOrFail();

        $this->fundAccount($source, '200000.0000', '200000.0000');

        return [$source, $destination, $transit];
    }

    protected function transferPayload(TreasuryFinancialAccount $source, TreasuryFinancialAccount $destination, array $overrides = []): array
    {
        return array_merge([
            'source_account_id' => $source->id,
            'destination_account_id' => $destination->id,
            'amount_debited' => '50000',
        ], $overrides);
    }

    protected function createTransferViaHttp(int $userId, TreasuryFinancialAccount $source, TreasuryFinancialAccount $destination, array $overrides = [])
    {
        return $this->actingAs(User::findOrFail($userId))
            ->post(route('treasury-transfers.store'), $this->transferPayload($source, $destination, $overrides));
    }

    // ------------------------------------------------------------------
    // Index + create
    // ------------------------------------------------------------------

    public function test_transfers_index_happy_path(): void
    {
        [$source, $destination] = $this->seedAccounts();
        $this->createTransferViaHttp(2, $source, $destination)->assertRedirect();

        $response = $this->actingAs(User::findOrFail(1))->get(route('treasury-transfers.index'));

        $response->assertOk();
        $response->assertSee('Draft');
    }

    public function test_create_transfer_happy_path(): void
    {
        [$source, $destination] = $this->seedAccounts();

        $this->createTransferViaHttp(2, $source, $destination)->assertRedirect();

        $transfer = TreasuryTransfer::where('created_by', 1)->firstOrFail();
        $this->assertTrue($transfer->isDraft());
        $this->assertSame('50000.0000', (string) $transfer->amount_debited);
        $this->assertNull($transfer->operation->reference);

        // The draft show page renders.
        $this->actingAs(User::findOrFail(1))->get(route('treasury-transfers.show', $transfer))->assertOk();
    }

    public function test_create_transfer_permission_denied(): void
    {
        [$source, $destination] = $this->seedAccounts();

        $response = $this->createTransferViaHttp(6, $source, $destination);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');
        $this->assertSame(0, TreasuryTransfer::count());
    }

    public function test_transfers_index_permission_denied(): void
    {
        $response = $this->actingAs(User::findOrFail(6))->get(route('treasury-transfers.index'));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');
    }

    public function test_show_other_company_transfer_redirects_with_error_not_500(): void
    {
        [$source, $destination] = $this->seedAccounts();
        $this->createTransferViaHttp(2, $source, $destination)->assertRedirect();
        $transfer = TreasuryTransfer::where('created_by', 1)->firstOrFail();

        $response = $this->actingAs(User::findOrFail(4))->get(route('treasury-transfers.show', $transfer));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Transfer not found for this company.');
    }

    public function test_create_same_account_is_refused_over_http(): void
    {
        [$source] = $this->seedAccounts();

        $response = $this->createTransferViaHttp(2, $source, $source);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Source and destination accounts must be different.');
        $this->assertSame(0, TreasuryTransfer::count());
    }

    // ------------------------------------------------------------------
    // Confirm / clear / return over HTTP
    // ------------------------------------------------------------------

    public function test_confirm_form_renders_recheck_results(): void
    {
        [$source, $destination] = $this->seedAccounts();
        $this->createTransferViaHttp(2, $source, $destination)->assertRedirect();
        $transfer = TreasuryTransfer::where('created_by', 1)->firstOrFail();

        $response = $this->actingAs(User::findOrFail(1))->get(route('treasury-transfers.confirm-form', $transfer));

        $response->assertOk();
        $response->assertSee('Checks Before Confirm');
    }

    public function test_confirm_happy_path_via_http(): void
    {
        [$source, $destination] = $this->seedAccounts();
        $this->createTransferViaHttp(2, $source, $destination)->assertRedirect();
        $transfer = TreasuryTransfer::where('created_by', 1)->firstOrFail();

        $this->actingAs(User::findOrFail(2))->post(route('treasury-transfers.confirm', $transfer))->assertRedirect();

        $transfer = $transfer->fresh();
        $this->assertTrue($transfer->isConfirmed());
        $this->assertSame('COMP-TRF-2026-0001', (string) $transfer->operation->reference);
        $this->assertSame('150000.0000', $this->accounts->postedBalance($source->fresh()));
        $this->assertSame('50000.0000', $this->accounts->postedBalance($destination->fresh()));
    }

    public function test_confirm_permission_denied(): void
    {
        [$source, $destination] = $this->seedAccounts();
        $this->createTransferViaHttp(2, $source, $destination)->assertRedirect();
        $transfer = TreasuryTransfer::where('created_by', 1)->firstOrFail();

        $response = $this->actingAs(User::findOrFail(6))->post(route('treasury-transfers.confirm', $transfer));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');
        $this->assertTrue($transfer->fresh()->isDraft());
    }

    public function test_confirm_insufficient_funds_via_http_keeps_draft(): void
    {
        [$source, $destination] = $this->seedAccounts();
        $this->createTransferViaHttp(2, $source, $destination, ['amount_debited' => '250000'])->assertRedirect();
        $transfer = TreasuryTransfer::where('created_by', 1)->firstOrFail();

        $response = $this->actingAs(User::findOrFail(2))->post(route('treasury-transfers.confirm', $transfer));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Insufficient available balance on account BIM-001: 200000.0000 available for 250000.0000 required.');
        $this->assertTrue($transfer->fresh()->isDraft());
    }

    public function test_delayed_transfer_clear_via_http(): void
    {
        [$source, $destination, $transit] = $this->seedAccounts();
        $this->createTransferViaHttp(2, $source, $destination, [
            'timing' => 'delayed',
            'transit_account_id' => $transit->id,
        ])->assertRedirect();
        $transfer = TreasuryTransfer::where('created_by', 1)->firstOrFail();

        $this->actingAs(User::findOrFail(2))->post(route('treasury-transfers.confirm', $transfer))->assertRedirect();
        $this->assertTrue($transfer->fresh()->isInTransit());
        $this->assertSame('50000.0000', $this->accounts->postedBalance($transit->fresh()));

        $this->actingAs(User::findOrFail(2))->post(route('treasury-transfers.clear', $transfer))->assertRedirect();

        $this->assertTrue($transfer->fresh()->isConfirmed());
        $this->assertSame('0.0000', $this->accounts->postedBalance($transit->fresh()));
        $this->assertSame('50000.0000', $this->accounts->postedBalance($destination->fresh()));
    }

    public function test_delayed_transfer_return_via_http(): void
    {
        [$source, $destination, $transit] = $this->seedAccounts();
        $this->createTransferViaHttp(2, $source, $destination, [
            'timing' => 'delayed',
            'transit_account_id' => $transit->id,
        ])->assertRedirect();
        $transfer = TreasuryTransfer::where('created_by', 1)->firstOrFail();

        $this->actingAs(User::findOrFail(2))->post(route('treasury-transfers.confirm', $transfer))->assertRedirect();
        $this->actingAs(User::findOrFail(2))->post(route('treasury-transfers.return', $transfer), [
            'reason' => 'Destination rejected the transfer',
        ])->assertRedirect();

        $transfer = $transfer->fresh();
        $this->assertTrue($transfer->isReturned());
        $this->assertSame('Destination rejected the transfer', $transfer->return_reason);
        $this->assertSame('200000.0000', $this->accounts->postedBalance($source->fresh()));
        $this->assertSame('0.0000', $this->accounts->postedBalance($transit->fresh()));
    }

    public function test_cancel_draft_via_http(): void
    {
        [$source, $destination] = $this->seedAccounts();
        $this->createTransferViaHttp(2, $source, $destination)->assertRedirect();
        $transfer = TreasuryTransfer::where('created_by', 1)->firstOrFail();

        $this->actingAs(User::findOrFail(2))->post(route('treasury-transfers.cancel', $transfer))->assertRedirect();

        $this->assertTrue($transfer->fresh()->isCancelled());
    }

    public function test_confirm_cross_company_redirects_with_error(): void
    {
        [$source, $destination] = $this->seedAccounts();
        $this->createTransferViaHttp(2, $source, $destination)->assertRedirect();
        $transfer = TreasuryTransfer::where('created_by', 1)->firstOrFail();

        $response = $this->actingAs(User::findOrFail(4))->post(route('treasury-transfers.confirm', $transfer));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Transfer not found for this company.');
        $this->assertTrue($transfer->fresh()->isDraft());
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
    }
}
