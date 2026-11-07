<?php

namespace Tests\Unit;

use App\Exceptions\TreasuryException;
use App\Models\TreasuryCashFlowAlert;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryRecurringOccurrence;
use App\Models\TreasuryRecurringRule;
use App\Services\TreasuryAlertService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Treasury Phase 8 — cash-flow alerts (plan §8, §10).
 *
 * Users: 1 = company, 2 = operator, 4 = other company. "Today"
 * is frozen at 2026-11-07 so overdue / upcoming windows are
 * deterministic. The account is funded with 200,000 MZN.
 */
class TreasuryAlertServiceTest extends TestCase
{
    protected TreasuryAlertService $service;

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

        $bankId = DB::table('bank_accounts')->insertGetId([
            'holder_name' => 'Hoya Hoya Lda', 'bank_name' => 'BIM', 'account_number' => '123456789',
            'chart_account_id' => 1, 'opening_balance' => 0, 'contact_number' => '', 'bank_address' => '',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        TreasuryFinancialAccount::create([
            'bank_account_id' => $bankId, 'created_by' => 1, 'code' => 'BIM-001',
            'type' => 'bank', 'currency' => 'MZN', 'status' => 'active',
            'negative_balance_policy' => 'block',
        ]);

        DB::table('treasury_opening_balances')->insert([
            'financial_account_id' => 1, 'created_by' => 1, 'currency' => 'MZN',
            'amount' => '200000.0000', 'amount_base' => '200000.0000',
            'cut_off_date' => '2026-11-01', 'batch_reference' => 'BATCH-1',
            'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->service = new TreasuryAlertService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach (['treasury_cash_flow_alerts', 'treasury_statement_sync_cursors', 'treasury_advances', 'treasury_transfers', 'treasury_payment_requests', 'treasury_recurring_occurrences', 'treasury_recurring_rules', 'treasury_forecast_items', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'dimension_audit_events', 'bank_accounts', 'chart_of_accounts', 'settings', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Occurrence alerts
    // ------------------------------------------------------------------

    public function test_overdue_occurrence_raises_one_warning_and_never_duplicates(): void
    {
        $occurrence = $this->makeOccurrence('2026-11-01');

        $first = $this->service->refreshAlerts(1);
        $second = $this->service->refreshAlerts(1);

        $this->assertSame(1, $first['raised']);
        $this->assertSame(0, $second['raised']);

        $alerts = TreasuryCashFlowAlert::where('created_by', 1)->get();
        $this->assertCount(1, $alerts);
        $this->assertSame('overdue_occurrence', $alerts[0]->type);
        $this->assertSame('warning', $alerts[0]->severity);
        $this->assertSame('active', $alerts[0]->state);
        $this->assertSame('recurring_occurrence', $alerts[0]->source_type);
        $this->assertSame($occurrence->id, (int) $alerts[0]->source_id);
    }

    public function test_upcoming_occurrence_within_seven_days_raises_info_alert(): void
    {
        $this->makeOccurrence('2026-11-10');

        $this->service->refreshAlerts(1);

        $alert = TreasuryCashFlowAlert::where('created_by', 1)->firstOrFail();
        $this->assertSame('upcoming_occurrence', $alert->type);
        $this->assertSame('info', $alert->severity);
    }

    public function test_occurrence_due_after_the_window_raises_nothing(): void
    {
        $this->makeOccurrence('2026-12-01');

        $result = $this->service->refreshAlerts(1);

        $this->assertSame(0, $result['raised']);
        $this->assertSame(0, TreasuryCashFlowAlert::where('created_by', 1)->count());
    }

    public function test_alert_resolves_when_its_condition_clears(): void
    {
        $occurrence = $this->makeOccurrence('2026-11-01');
        $this->service->refreshAlerts(1);
        $this->assertSame(1, TreasuryCashFlowAlert::where('created_by', 1)->where('state', 'active')->count());

        $occurrence->state = 'paid';
        $occurrence->paid_at = now();
        $occurrence->save();

        $result = $this->service->refreshAlerts(1);

        $this->assertSame(1, $result['resolved']);
        $this->assertSame('resolved', TreasuryCashFlowAlert::where('created_by', 1)->firstOrFail()->state);
    }

    public function test_acknowledged_alert_is_kept_not_re_raised(): void
    {
        $this->makeOccurrence('2026-11-01');
        $this->service->refreshAlerts(1);

        $alert = TreasuryCashFlowAlert::where('created_by', 1)->firstOrFail();
        $acknowledged = $this->service->acknowledge(1, $alert->id, 2);
        $this->assertSame('acknowledged', $acknowledged->state);

        $result = $this->service->refreshAlerts(1);
        $this->assertSame(0, $result['raised']);
        $this->assertSame(1, TreasuryCashFlowAlert::where('created_by', 1)->count());
    }

    public function test_acknowledge_requires_an_active_alert(): void
    {
        $this->makeOccurrence('2026-11-01');
        $this->service->refreshAlerts(1);
        $alert = TreasuryCashFlowAlert::where('created_by', 1)->firstOrFail();
        $this->service->acknowledge(1, $alert->id, 2);

        $this->expectException(TreasuryException::class);
        $this->service->acknowledge(1, $alert->id, 2);
    }

    // ------------------------------------------------------------------
    // Insufficient funds
    // ------------------------------------------------------------------

    public function test_insufficient_funds_alert_when_forecast_drops_below_threshold(): void
    {
        DB::table('settings')->insert([
            'name' => 'treasury_cashflow_threshold_base', 'value' => '190000', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_forecast_items')->insert([
            'company_id' => 1, 'created_by' => 1, 'source_type' => 'manual', 'source_id' => null,
            'direction' => 'outflow', 'amount' => '25000.0000', 'amount_base' => '25000.0000',
            'currency' => 'MZN', 'expected_date' => '2026-11-10', 'scenario' => 'baseline',
            'state' => 'forecast', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->service->refreshAlerts(1);

        $alert = TreasuryCashFlowAlert::where('created_by', 1)->where('type', 'insufficient_funds')->firstOrFail();
        $this->assertSame('critical', $alert->severity);
        $this->assertStringContainsString('2026-11-10', $alert->detail);
    }

    public function test_no_insufficient_funds_alert_when_forecast_stays_above_threshold(): void
    {
        $result = $this->service->refreshAlerts(1);
        $this->assertSame(0, $result['raised']);
        $this->assertSame(0, TreasuryCashFlowAlert::where('created_by', 1)->where('type', 'insufficient_funds')->count());
    }

    // ------------------------------------------------------------------
    // Other alert types
    // ------------------------------------------------------------------

    public function test_pending_approval_delayed_transfer_and_overdue_advance_alerts(): void
    {
        DB::table('treasury_payment_requests')->insert([
            'created_by' => 1, 'source_account_id' => 1, 'beneficiary_type' => 'vendor',
            'amount_original' => '50000.0000', 'amount_base' => '50000.0000', 'currency' => 'MZN',
            'state' => 'awaiting_approval', 'requested_by' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('treasury_transfers')->insert([
            'created_by' => 1, 'source_account_id' => 1, 'destination_account_id' => 1,
            'source_currency' => 'MZN', 'destination_currency' => 'MZN',
            'amount_debited' => '10000.0000', 'state' => 'in_transit',
            'created_at' => Carbon::parse('2026-11-01'), 'updated_at' => Carbon::parse('2026-11-01'),
        ]);

        DB::table('treasury_advances')->insert([
            'created_by' => 1, 'source_account_id' => 1, 'recipient_type' => 'employee',
            'recipient_id' => 2, 'recipient_name' => 'Ana', 'currency' => 'MZN',
            'amount_requested' => '5000.0000', 'amount_requested_base' => '5000.0000',
            'amount_outstanding' => '1000.0000', 'due_date' => '2026-11-01',
            'state' => 'disbursed', 'requested_by' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->service->refreshAlerts(1);

        $this->assertSame(3, $result['raised']);
        $types = TreasuryCashFlowAlert::where('created_by', 1)->pluck('type')->sort()->values()->all();
        $this->assertSame(['delayed_transfer', 'overdue_advance', 'pending_approval'], $types);
    }

    public function test_sync_failure_alert_and_resolution_on_next_success(): void
    {
        DB::table('treasury_statement_sync_cursors')->insert([
            'bank_account_id' => 1, 'source_key' => 'bim', 'cursor_value' => 'ext-003',
            'created_by' => 1, 'last_error' => 'Provider timeout', 'last_error_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->service->refreshAlerts(1);
        $alert = TreasuryCashFlowAlert::where('created_by', 1)->where('type', 'sync_failure')->firstOrFail();
        $this->assertSame('critical', $alert->severity);
        $this->assertStringContainsString('Provider timeout', $alert->detail);

        // A later successful import clears the error → the alert resolves.
        DB::table('treasury_statement_sync_cursors')->where('bank_account_id', 1)->update([
            'last_error' => null, 'last_error_at' => null,
        ]);
        $result = $this->service->refreshAlerts(1);
        $this->assertSame(1, $result['resolved']);
        $this->assertSame('resolved', $alert->fresh()->state);
    }

    public function test_other_company_conditions_never_alert_this_company(): void
    {
        $rule = TreasuryRecurringRule::create([
            'company_id' => 4, 'created_by' => 4, 'beneficiary' => 'Other landlord',
            'direction' => 'outflow', 'currency' => 'MZN', 'amount' => '10000.0000',
            'amount_base' => '10000.0000', 'start_date' => '2026-11-01',
            'frequency' => 'monthly', 'state' => 'active', 'version' => 1,
        ]);
        TreasuryRecurringOccurrence::create([
            'rule_id' => $rule->id, 'period_key' => '2026-11', 'scheduled_date' => '2026-11-01',
            'amount' => '10000.0000', 'amount_base' => '10000.0000', 'currency' => 'MZN',
            'direction' => 'outflow', 'state' => 'generated', 'generated_at' => now(), 'created_by' => 4,
        ]);

        $result = $this->service->refreshAlerts(1);
        $this->assertSame(0, $result['raised']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function makeOccurrence(string $scheduledDate): TreasuryRecurringOccurrence
    {
        $rule = TreasuryRecurringRule::create([
            'company_id' => 1, 'created_by' => 1, 'beneficiary' => 'Landlord Lda',
            'direction' => 'outflow', 'currency' => 'MZN', 'amount' => '10000.0000',
            'amount_base' => '10000.0000', 'start_date' => '2026-11-01',
            'frequency' => 'monthly', 'state' => 'active', 'version' => 1,
        ]);

        return TreasuryRecurringOccurrence::create([
            'rule_id' => $rule->id, 'period_key' => '2026-11', 'scheduled_date' => $scheduledDate,
            'amount' => '10000.0000', 'amount_base' => '10000.0000', 'currency' => 'MZN',
            'direction' => 'outflow', 'state' => 'generated', 'generated_at' => now(), 'created_by' => 1,
        ]);
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
            $table->string('beneficiary_account', 150)->nullable();
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->decimal('amount_base', 20, 4)->default(0);
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
            $table->text('purpose')->nullable();
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->decimal('amount_requested', 20, 4);
            $table->decimal('amount_requested_base', 20, 4);
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
