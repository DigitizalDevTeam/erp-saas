<?php

namespace Tests\Feature;

use App\Exceptions\CostCentreException;
use App\Models\CostCentreBudget;
use App\Models\CostCentreBudgetApproval;
use App\Models\CostCentreCommitment;
use App\Models\User;
use App\Services\BudgetControlService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CostCentreBudgetWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['view cost centres', 'edit cost centres', 'manage cost centre budgets', 'approve cost centre budgets', 'view cost centre reports', 'export cost centre reports'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Manager', 'email' => 'manager@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Controller', 'email' => 'controller@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 5, 'name' => 'Other Employee', 'email' => 'otheremployee@test.local', 'type' => 'employee', 'created_by' => 4, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 7, 'name' => 'Requester', 'email' => 'requester@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        DB::table('cost_centres')->insert([
            ['id' => 1, 'company_id' => 1, 'created_by' => 1, 'code' => 'LOG', 'name' => 'Logistics', 'manager_user_id' => 2, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'company_id' => 1, 'created_by' => 1, 'code' => 'ADM', 'name' => 'Administration', 'manager_user_id' => 3, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('chart_of_accounts')->insert([
            ['id' => 621, 'code' => '621', 'name' => 'Utilities', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['view cost centres', 'edit cost centres', 'manage cost centre budgets', 'approve cost centre budgets', 'view cost centre reports', 'export cost centre reports']);
        User::findOrFail(2)->givePermissionTo(['approve cost centre budgets']);
        User::findOrFail(3)->givePermissionTo(['approve cost centre budgets']);
        User::findOrFail(4)->givePermissionTo(['view cost centres', 'edit cost centres', 'manage cost centre budgets', 'approve cost centre budgets', 'view cost centre reports']);
        User::findOrFail(5)->givePermissionTo(['view cost centres', 'edit cost centres', 'manage cost centre budgets', 'approve cost centre budgets', 'view cost centre reports']);
        // Requester holds the approve permission so the service-level
        // no-self-approval rule (not the route gate) is what stops them.
        User::findOrFail(7)->givePermissionTo(['approve cost centre budgets']);
    }

    protected function tearDown(): void
    {
        foreach (['cost_centre_budget_delegations', 'cost_centre_budget_approvals', 'cost_centre_commitments', 'cost_centre_budgets', 'dimension_audit_events', 'journal_line_dimensions', 'journal_items', 'journal_entries', 'transaction_lines', 'chart_of_accounts', 'projects', 'cost_centres', 'branches', 'branch_user', 'settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_budget_create_and_approve_via_http(): void
    {
        $this->actingAs(User::findOrFail(1))
            ->post(route('cost-centre-budgets.store'), [
                'cost_centre_id' => 1,
                'fiscal_year' => 2026,
                'period' => 3,
                'budget_amount' => '10000.00',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $budget = CostCentreBudget::first();
        $this->assertNotNull($budget);
        $this->assertSame(CostCentreBudget::STATUS_DRAFT, $budget->status);
        $this->assertSame('0.00', $budget->approved_amount);

        // A draft counts as zero: the index shows it, but no approved figure.
        $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-budgets.index', ['fiscal_year' => 2026]))
            ->assertOk()
            ->assertSee('LOG')
            ->assertSee('Draft');

        $this->actingAs(User::findOrFail(1))
            ->post(route('cost-centre-budgets.approve', $budget))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(CostCentreBudget::STATUS_APPROVED, $budget->fresh()->status);
        $this->assertSame('10000.00', $budget->fresh()->approved_amount);
    }

    public function test_settings_save_threshold_and_controller(): void
    {
        $this->actingAs(User::findOrFail(1))
            ->post(route('cost-centres.settings.save'), [
                'budget_policy' => 'approval',
                'budget_approval_threshold' => '5000.00',
                'budget_finance_controller_user_id' => 3,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('approval', DB::table('settings')->where('created_by', 1)->where('name', 'budget_policy')->value('value'));
        $this->assertSame('5000.00', DB::table('settings')->where('created_by', 1)->where('name', 'budget_approval_threshold')->value('value'));
        $this->assertSame('3', DB::table('settings')->where('created_by', 1)->where('name', 'budget_finance_controller_user_id')->value('value'));
    }

    public function test_budget_vs_actual_report_with_real_gl_actuals(): void
    {
        $service = new BudgetControlService();
        $budget = $service->createBudget(1, [
            'cost_centre_id' => 1, 'fiscal_year' => 2026, 'period' => 3, 'budget_amount' => '10000.00',
        ], 1);
        $service->approveBudget($budget, 1, 1);

        // A real dimensioned GL expense: journal item 2000 on account
        // 621, dated March, dimensioned to LOG.
        DB::table('journal_entries')->insert([
            'id' => 1, 'date' => '2026-03-15', 'reference' => 'JE-1', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('journal_items')->insert([
            'id' => 1, 'journal' => 1, 'account' => 621, 'description' => 'Utilities', 'debit' => '2000.00', 'credit' => '0.00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('journal_line_dimensions')->insert([
            'company_id' => 1, 'created_by' => 1, 'line_type' => 'journal_item', 'line_id' => 1, 'journal_id' => 1,
            'cost_centre_id' => 1, 'allocated_amount' => '2000.00', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-reports.budget', ['fiscal_year' => 2026, 'period' => 3]))
            ->assertOk()
            ->assertSee('LOG')
            ->assertSee('10,000.00')
            ->assertSee('2,000.00')
            ->assertSee('8,000.00');

        $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-reports.budget', ['fiscal_year' => 2026, 'period' => 3, 'export' => 'csv']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_over_budget_commitment_blocked_under_block_policy(): void
    {
        $this->setSetting('budget_policy', 'block');

        $service = new BudgetControlService();
        $budget = $service->createBudget(1, [
            'cost_centre_id' => 1, 'fiscal_year' => 2026, 'period' => 3, 'budget_amount' => '1000.00',
        ], 1);
        $service->approveBudget($budget, 1, 1);

        $this->expectException(CostCentreException::class);
        $service->reserveCommitment(1, 1, '1500.00', 'purchase', 91, 7, Carbon::create(2026, 3, 10));

        $this->assertSame(0, CostCentreCommitment::count());
    }

    public function test_approval_flow_via_http(): void
    {
        $this->setSetting('budget_policy', 'approval');
        $this->setSetting('budget_finance_controller_user_id', '3');

        $service = new BudgetControlService();
        $budget = $service->createBudget(1, [
            'cost_centre_id' => 1, 'fiscal_year' => 2026, 'period' => 3, 'budget_amount' => '1000.00',
        ], 1);
        $service->approveBudget($budget, 1, 1);

        // Requester 7 reserves 1500 against a 1000 budget: pending
        // commitment + pending approval.
        $commitment = $service->reserveCommitment(1, 1, '1500.00', 'purchase', 92, 7, Carbon::create(2026, 3, 10));
        $approval = CostCentreBudgetApproval::findOrFail($commitment->approval_id);
        $this->assertSame(CostCentreBudgetApproval::STATUS_PENDING, $approval->status);

        // The requester cannot decide their own request, even though
        // they hold the approve permission (service rule, not the gate).
        $this->actingAs(User::findOrFail(7))
            ->post(route('cost-centre-budget-approvals.decide', $approval), ['decision' => 'approved'])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertSame(CostCentreBudgetApproval::STATUS_PENDING, $approval->fresh()->status);

        // Manager (2) approves — still needs the controller (threshold 0).
        $this->actingAs(User::findOrFail(2))
            ->post(route('cost-centre-budget-approvals.decide', $approval), ['decision' => 'approved', 'note' => 'OK'])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame(CostCentreBudgetApproval::STATUS_MANAGER_APPROVED, $approval->fresh()->status);
        $this->assertSame(CostCentreCommitment::STATUS_PENDING_APPROVAL, $commitment->fresh()->status);

        // Controller (3) approves — the commitment activates.
        $this->actingAs(User::findOrFail(3))
            ->post(route('cost-centre-budget-approvals.decide', $approval), ['decision' => 'approved'])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame(CostCentreBudgetApproval::STATUS_APPROVED, $approval->fresh()->status);
        $this->assertSame(CostCentreCommitment::STATUS_ACTIVE, $commitment->fresh()->status);
    }

    public function test_user_without_permissions_is_turned_away(): void
    {
        $this->actingAs(User::findOrFail(6))
            ->get(route('cost-centre-budgets.index'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs(User::findOrFail(6))
            ->post(route('cost-centre-budgets.store'), [
                'cost_centre_id' => 1, 'fiscal_year' => 2026, 'period' => 3, 'budget_amount' => '100.00',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, CostCentreBudget::count());
    }

    public function test_other_company_cannot_see_or_touch_budgets(): void
    {
        $service = new BudgetControlService();
        $budget = $service->createBudget(1, [
            'cost_centre_id' => 1, 'fiscal_year' => 2026, 'period' => 3, 'budget_amount' => '1000.00',
        ], 1);

        // Direct URL to the budget: 403.
        $this->actingAs(User::findOrFail(5))
            ->get(route('cost-centre-budgets.edit', $budget))
            ->assertForbidden();

        // Direct action URL: 403, status unchanged.
        $this->actingAs(User::findOrFail(5))
            ->post(route('cost-centre-budgets.approve', $budget))
            ->assertForbidden();
        $this->assertSame(CostCentreBudget::STATUS_DRAFT, $budget->fresh()->status);

        // The other company's list is empty.
        $this->actingAs(User::findOrFail(5))
            ->get(route('cost-centre-budgets.index', ['fiscal_year' => 2026]))
            ->assertOk()
            ->assertDontSee('LOG');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function setSetting(string $name, string $value): void
    {
        DB::table('settings')->updateOrInsert(
            ['name' => $name, 'created_by' => 1],
            ['value' => $value, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    protected function createTables(): void
    {
        Schema::create('cost_centres', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
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
            $table->integer('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['created_by', 'code'], 'cc_company_code_uq');
        });
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
            $table->primary(['permission_id', 'role_id']);
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
        Schema::create('chart_of_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->string('code', 50)->nullable();
            $table->string('name')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('projects', function ($table) {
            $table->bigIncrements('id');
            $table->string('project_name')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('journal_entries', function ($table) {
            $table->bigIncrements('id');
            $table->date('date')->nullable();
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->integer('journal_id')->default(0);
            $table->integer('status')->default(1);
            $table->integer('accounting_period')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('journal_items', function ($table) {
            $table->bigIncrements('id');
            $table->integer('journal')->default(0);
            $table->integer('account')->default(0);
            $table->text('description')->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('transaction_lines', function ($table) {
            $table->bigIncrements('id');
            $table->integer('account_id')->nullable();
            $table->string('reference', 50)->nullable();
            $table->integer('reference_id')->nullable();
            $table->integer('reference_sub_id')->nullable();
            $table->date('date')->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('journal_line_dimensions', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('line_type', 20);
            $table->unsignedBigInteger('line_id');
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('cost_centre_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->decimal('allocated_amount', 18, 2);
            $table->decimal('percentage', 9, 6)->nullable();
            $table->string('currency_code', 10)->nullable();
            $table->string('source', 20)->default('manual');
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('cost_centre_budgets', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->integer('fiscal_year');
            $table->unsignedTinyInteger('period');
            $table->unsignedBigInteger('cost_centre_id');
            $table->unsignedBigInteger('gl_account_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('scope_key', 60);
            $table->decimal('budget_amount', 18, 2)->default(0);
            $table->decimal('approved_amount', 18, 2)->default(0);
            $table->string('currency_code', 10)->nullable();
            $table->string('status', 20)->default('draft');
            $table->integer('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'fiscal_year', 'period', 'cost_centre_id', 'scope_key'], 'ccb_scope_unique');
        });
        Schema::create('cost_centre_commitments', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->integer('fiscal_year');
            $table->unsignedTinyInteger('period');
            $table->unsignedBigInteger('cost_centre_id');
            $table->unsignedBigInteger('gl_account_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('scope_key', 60);
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id');
            $table->decimal('amount', 18, 2);
            $table->string('currency_code', 10)->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('converted_bill_id')->nullable();
            $table->unsignedBigInteger('approval_id')->nullable();
            $table->integer('requested_by')->nullable();
            $table->integer('released_by')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'source_type', 'source_id', 'cost_centre_id', 'scope_key'], 'ccc_source_unique');
        });
        Schema::create('cost_centre_budget_approvals', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->unsignedBigInteger('cost_centre_id');
            $table->unsignedBigInteger('budget_id')->nullable();
            $table->unsignedBigInteger('commitment_id')->nullable();
            $table->integer('fiscal_year');
            $table->unsignedTinyInteger('period');
            $table->unsignedBigInteger('gl_account_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('scope_key', 60);
            $table->decimal('amount', 18, 2);
            $table->string('currency_code', 10)->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending');
            $table->integer('requested_by');
            $table->integer('manager_user_id')->nullable();
            $table->integer('controller_user_id')->nullable();
            $table->boolean('requires_manager')->default(true);
            $table->boolean('requires_controller')->default(true);
            $table->integer('manager_decided_by')->nullable();
            $table->timestamp('manager_decided_at')->nullable();
            $table->string('manager_decision', 20)->nullable();
            $table->text('manager_note')->nullable();
            $table->integer('controller_decided_by')->nullable();
            $table->timestamp('controller_decided_at')->nullable();
            $table->string('controller_decision', 20)->nullable();
            $table->text('controller_note')->nullable();
            $table->string('auto_note')->nullable();
            $table->timestamps();
        });
        Schema::create('cost_centre_budget_delegations', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->integer('delegator_user_id');
            $table->integer('delegate_user_id');
            $table->string('role', 20);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();
        });
        Schema::create('dimension_audit_events', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('action');
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->integer('actor_id')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });
    }
}
