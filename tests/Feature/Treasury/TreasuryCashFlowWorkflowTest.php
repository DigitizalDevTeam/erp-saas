<?php

namespace Tests\Feature\Treasury;

use App\Models\TreasuryCashFlowAlert;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryForecastItem;
use App\Models\TreasuryRecurringOccurrence;
use App\Models\TreasuryRecurringRule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Treasury Phase 8 — cash flow + recurring commitments, HTTP level.
 *
 * Covers: the cash-flow calendar renders (forecast vs actual,
 * threshold flag), manual projections CRUD, contract projections
 * replaced by their invoice on page load (CA13), recurring rules
 * CRUD + pause/resume/end, occurrence confirm/paid/skip, alerts
 * list + acknowledge, the scheduled jobs (CA14), permission
 * denial and cross-company denial.
 *
 * Users: 1 = Company (both Phase 8 permissions), 2 = Operator
 * (both permissions), 4 = Other Company (both permissions),
 * 6 = No Treasury Permission. "Today" is frozen at 2026-11-07.
 */
class TreasuryCashFlowWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-07 09:00:00'));
        $this->createTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['manage transaction', 'view treasury', 'manage treasury cash flow', 'manage treasury recurring'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Operator', 'email' => 'operator@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['manage transaction', 'view treasury', 'manage treasury cash flow', 'manage treasury recurring']);
        User::findOrFail(2)->givePermissionTo(['manage transaction', 'view treasury', 'manage treasury cash flow', 'manage treasury recurring']);
        User::findOrFail(4)->givePermissionTo(['manage transaction', 'view treasury', 'manage treasury cash flow', 'manage treasury recurring']);
        User::findOrFail(6)->givePermissionTo(['manage transaction']);

        DB::table('bank_accounts')->insert([
            'id' => 1, 'holder_name' => 'Hoya Hoya Lda', 'bank_name' => 'BIM',
            'account_number' => '123456789', 'chart_account_id' => 1,
            'opening_balance' => 0, 'contact_number' => '', 'bank_address' => '',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        TreasuryFinancialAccount::create([
            'bank_account_id' => 1, 'created_by' => 1, 'code' => 'BIM-001',
            'type' => 'bank', 'currency' => 'MZN', 'status' => 'active',
            'negative_balance_policy' => 'block',
        ]);
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

        foreach (['treasury_cash_flow_alerts', 'treasury_statement_sync_cursors', 'treasury_advances', 'treasury_transfers', 'treasury_payment_requests', 'treasury_recurring_occurrences', 'treasury_recurring_rules', 'treasury_forecast_items', 'pay_slips', 'credit_notes', 'invoice_payments', 'invoice_products', 'invoices', 'customers', 'taxes', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'dimension_audit_events', 'bank_accounts', 'chart_of_accounts', 'branches', 'branch_user', 'settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Cash-flow calendar
    // ------------------------------------------------------------------

    public function test_cash_flow_index_renders_opening_balance_and_no_threshold_warning(): void
    {
        $response = $this->actingAs(User::findOrFail(1))->get(route('treasury-cash-flow.index'));

        $response->assertOk();
        $response->assertSee('Treasury Cash Flow');
        $response->assertSee('200,000.00');
        $response->assertDontSee('falls below the threshold');
    }

    public function test_cash_flow_index_flags_first_day_below_threshold(): void
    {
        DB::table('settings')->insert([
            'name' => 'treasury_cashflow_threshold_base', 'value' => '190000', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::findOrFail(1))->post(route('treasury-cash-flow.projections.store'), [
            'source_type' => 'manual', 'direction' => 'outflow', 'amount' => '25000',
            'expected_date' => '2026-11-10', 'note' => 'Rent',
        ])->assertRedirect(route('treasury-cash-flow.projections'));

        $response = $this->actingAs(User::findOrFail(1))->get(route('treasury-cash-flow.index'));

        $response->assertOk();
        $response->assertSee('falls below the threshold');
        $response->assertSee('2026-11-10');
        $response->assertSee('175,000.00');
    }

    // ------------------------------------------------------------------
    // Projections
    // ------------------------------------------------------------------

    public function test_projection_create_list_delete(): void
    {
        $this->actingAs(User::findOrFail(2))->post(route('treasury-cash-flow.projections.store'), [
            'source_type' => 'manual', 'direction' => 'inflow', 'amount' => '5000',
            'expected_date' => '2026-11-15', 'note' => 'Expected refund',
        ])->assertRedirect(route('treasury-cash-flow.projections'));

        $item = TreasuryForecastItem::where('created_by', 1)->where('source_type', 'manual')->firstOrFail();
        $this->assertSame('Expected refund', $item->description);

        $response = $this->actingAs(User::findOrFail(2))->get(route('treasury-cash-flow.projections'));
        $response->assertOk();
        $response->assertSee('Expected refund');

        $this->actingAs(User::findOrFail(2))
            ->delete(route('treasury-cash-flow.projections.destroy', $item->id))
            ->assertRedirect(route('treasury-cash-flow.projections'));
        $this->assertSame(0, TreasuryForecastItem::where('created_by', 1)->where('source_type', 'manual')->count());
    }

    public function test_ca13_contract_projection_replaced_when_invoice_issued(): void
    {
        $this->actingAs(User::findOrFail(1))->post(route('treasury-cash-flow.projections.store'), [
            'source_type' => 'contract', 'reference' => 'CTR-2027-001', 'direction' => 'inflow',
            'amount' => '5000', 'expected_date' => '2026-12-01', 'note' => 'Monthly fee',
        ])->assertRedirect(route('treasury-cash-flow.projections'));

        $this->makeInvoice(2, '2026-12-01', [['price' => '5000', 'quantity' => 1]], 'CTR-2027-001');

        // Loading the cash-flow page refreshes the forecast — the
        // projection is replaced and the invoice carries the amount.
        $response = $this->actingAs(User::findOrFail(1))->get(route('treasury-cash-flow.index'));
        $response->assertOk();

        $projection = TreasuryForecastItem::where('created_by', 1)->where('source_type', 'contract')->firstOrFail();
        $this->assertSame('CTR-2027-001', $projection->replacement_reference);
        $this->assertSame(1, TreasuryForecastItem::where('created_by', 1)->where('source_type', 'invoice')->count());
    }

    // ------------------------------------------------------------------
    // Recurring rules + occurrences
    // ------------------------------------------------------------------

    public function test_rule_create_show_pause_resume_end(): void
    {
        $response = $this->actingAs(User::findOrFail(1))->post(route('treasury-recurring-rules.store'), $this->ruleData());
        $rule = TreasuryRecurringRule::where('created_by', 1)->firstOrFail();
        $response->assertRedirect(route('treasury-recurring-rules.show', $rule->id));

        $this->actingAs(User::findOrFail(1))->get(route('treasury-recurring-rules.index'))->assertOk()->assertSee('Landlord Lda');
        $this->actingAs(User::findOrFail(1))->get(route('treasury-recurring-rules.show', $rule->id))->assertOk()->assertSee('Landlord Lda');

        $this->actingAs(User::findOrFail(1))->post(route('treasury-recurring-rules.pause', $rule->id))->assertRedirect();
        $this->assertSame('paused', $rule->fresh()->state);

        $this->actingAs(User::findOrFail(1))->post(route('treasury-recurring-rules.resume', $rule->id))->assertRedirect();
        $this->assertSame('active', $rule->fresh()->state);

        $this->actingAs(User::findOrFail(1))->post(route('treasury-recurring-rules.end', $rule->id))->assertRedirect();
        $this->assertSame('ended', $rule->fresh()->state);
    }

    public function test_occurrences_generated_by_job_and_confirmed_paid_skipped(): void
    {
        $this->actingAs(User::findOrFail(1))->post(route('treasury-recurring-rules.store'), $this->ruleData());
        $rule = TreasuryRecurringRule::where('created_by', 1)->firstOrFail();

        // CA14 at the command level: two runs, one occurrence per period.
        Artisan::call('treasury:recurring-generate', ['--company_id' => 1]);
        Artisan::call('treasury:recurring-generate', ['--company_id' => 1]);
        $this->assertSame(4, TreasuryRecurringOccurrence::where('rule_id', $rule->id)->count());

        $occurrences = TreasuryRecurringOccurrence::where('rule_id', $rule->id)->orderBy('scheduled_date')->get();
        $this->actingAs(User::findOrFail(1))->get(route('treasury-recurring-occurrences.index'))->assertOk()->assertSee('2026-11-01');

        $this->actingAs(User::findOrFail(2))->post(route('treasury-recurring-occurrences.confirm', $occurrences[0]->id))->assertRedirect();
        $this->assertSame('confirmed', $occurrences[0]->fresh()->state);

        $this->actingAs(User::findOrFail(2))->post(route('treasury-recurring-occurrences.paid', $occurrences[0]->id))->assertRedirect();
        $this->assertSame('paid', $occurrences[0]->fresh()->state);

        $this->actingAs(User::findOrFail(2))->post(route('treasury-recurring-occurrences.skip', $occurrences[1]->id), ['reason' => 'Not needed'])
            ->assertRedirect();
        $this->assertSame('skipped', $occurrences[1]->fresh()->state);
    }

    public function test_forecast_refresh_command_runs(): void
    {
        $exit = Artisan::call('treasury:forecast-refresh', ['--company_id' => 1]);
        $this->assertSame(0, $exit);
        $this->assertSame(1, TreasuryForecastItem::where('created_by', 1)->where('source_type', 'balance')->count());
    }

    // ------------------------------------------------------------------
    // Alerts
    // ------------------------------------------------------------------

    public function test_alerts_list_and_acknowledge(): void
    {
        $rule = TreasuryRecurringRule::create([
            'company_id' => 1, 'created_by' => 1, 'beneficiary' => 'Landlord Lda',
            'direction' => 'outflow', 'currency' => 'MZN', 'amount' => '10000.0000',
            'amount_base' => '10000.0000', 'start_date' => '2026-11-01',
            'frequency' => 'monthly', 'state' => 'active', 'version' => 1,
        ]);
        TreasuryRecurringOccurrence::create([
            'rule_id' => $rule->id, 'period_key' => '2026-11', 'scheduled_date' => '2026-11-01',
            'amount' => '10000.0000', 'amount_base' => '10000.0000', 'currency' => 'MZN',
            'direction' => 'outflow', 'state' => 'generated', 'generated_at' => now(), 'created_by' => 1,
        ]);

        $response = $this->actingAs(User::findOrFail(1))->get(route('treasury-cash-flow-alerts.index'));
        $response->assertOk();
        $response->assertSee('Overdue recurring commitment');

        $alert = TreasuryCashFlowAlert::where('created_by', 1)->firstOrFail();
        $this->actingAs(User::findOrFail(1))
            ->post(route('treasury-cash-flow-alerts.acknowledge', $alert->id))
            ->assertRedirect();
        $this->assertSame('acknowledged', $alert->fresh()->state);
    }

    // ------------------------------------------------------------------
    // Permission denial + cross-company
    // ------------------------------------------------------------------

    public function test_permission_denial_for_cash_flow_and_recurring(): void
    {
        $response = $this->actingAs(User::findOrFail(6))->get(route('treasury-cash-flow.index'));
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');

        $response = $this->actingAs(User::findOrFail(6))->get(route('treasury-recurring-rules.index'));
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');

        $response = $this->actingAs(User::findOrFail(6))->post(route('treasury-cash-flow.projections.store'), [
            'source_type' => 'manual', 'direction' => 'inflow', 'amount' => '100',
            'expected_date' => '2026-11-10',
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');
    }

    public function test_cross_company_rule_show_denied(): void
    {
        $this->actingAs(User::findOrFail(1))->post(route('treasury-recurring-rules.store'), $this->ruleData());
        $rule = TreasuryRecurringRule::where('created_by', 1)->firstOrFail();

        $response = $this->actingAs(User::findOrFail(4))->get(route('treasury-recurring-rules.show', $rule->id));
        $response->assertRedirect(route('treasury-recurring-rules.index'));
        $response->assertSessionHas('error');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function ruleData(array $overrides = []): array
    {
        return array_merge([
            'beneficiary' => 'Landlord Lda',
            'description' => 'Monthly office rent',
            'category' => 'Rent',
            'direction' => 'outflow',
            'amount' => '10000',
            'amount_type' => 'fixed',
            'start_date' => '2026-11-01',
            'end_date' => '',
            'frequency' => 'monthly',
            'custom_interval' => '',
            'custom_unit' => '',
            'due_day' => '',
            'planned_account_id' => '',
            'approval_required' => '0',
        ], $overrides);
    }

    protected function makeInvoice(int $status, string $dueDate, array $lines, ?string $refNumber = null): void
    {
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'Cliente', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $invoiceId = DB::table('invoices')->insertGetId([
            'customer_id' => $customerId, 'invoice_id' => 2001, 'issue_date' => '2026-11-01',
            'due_date' => $dueDate, 'status' => $status, 'ref_number' => $refNumber,
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ($lines as $line) {
            DB::table('invoice_products')->insert([
                'invoice_id' => $invoiceId, 'product_id' => 0, 'price' => $line['price'],
                'quantity' => $line['quantity'], 'tax' => '', 'discount' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
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
            $table->string('code')->nullable();
            $table->string('name')->nullable();
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
            $table->bigInteger('financial_account_id')->nullable();
            $table->string('currency', 3)->default('MZN');
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->date('posting_date')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('value_date')->nullable();
            $table->string('state', 20)->default('draft');
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
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->integer('created_by');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('customers', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->default(0);
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
            $table->bigInteger('source_account_id');
            $table->string('beneficiary_type', 20);
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->string('currency', 3)->default('MZN');
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->string('state', 20)->default('draft');
            $table->integer('requested_by');
            $table->timestamps();
        });
        Schema::create('treasury_transfers', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('operation_id')->nullable();
            $table->integer('created_by');
            $table->bigInteger('source_account_id');
            $table->bigInteger('destination_account_id');
            $table->string('source_currency', 3)->default('MZN');
            $table->string('destination_currency', 3)->default('MZN');
            $table->string('timing', 20)->default('immediate');
            $table->decimal('amount_debited', 20, 4)->default(0);
            $table->string('state', 20)->default('draft');
            $table->timestamps();
        });
        Schema::create('treasury_advances', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('source_account_id');
            $table->string('recipient_type', 20);
            $table->bigInteger('recipient_id');
            $table->string('recipient_name', 150)->nullable();
            $table->string('currency', 3)->default('MZN');
            $table->decimal('amount_requested', 20, 4);
            $table->decimal('amount_requested_base', 20, 4);
            $table->decimal('amount_outstanding', 20, 4)->default(0);
            $table->date('due_date')->nullable();
            $table->string('state', 20)->default('draft');
            $table->bigInteger('requested_by');
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
    }
}
