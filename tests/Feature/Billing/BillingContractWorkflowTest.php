<?php

namespace Tests\Feature\Billing;

use App\Models\BillingContract;
use App\Models\BillingContractOccurrence;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Contract Management Phase 1 — billing contracts, HTTP level.
 *
 * Covers: index/create/store/show/edit/update, preview JSON,
 * submit/approve/reject/suspend/resume/end/amend, occurrences
 * list, permission denial and cross-company denial.
 *
 * Users: 1 = Company (both billing permissions), 2 = Operator
 * (manage only), 3 = Approver (approve only), 4 = Other Company
 * (both permissions), 6 = No Billing Permission. "Today" is
 * frozen at 2027-01-10.
 */
class BillingContractWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2027-01-10 09:00:00'));
        $this->createTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['manage transaction', 'manage billing contracts', 'approve billing contracts'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Operator', 'email' => 'operator@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['manage transaction', 'manage billing contracts', 'approve billing contracts']);
        User::findOrFail(2)->givePermissionTo(['manage transaction', 'manage billing contracts']);
        User::findOrFail(3)->givePermissionTo(['manage transaction', 'approve billing contracts']);
        User::findOrFail(4)->givePermissionTo(['manage transaction', 'manage billing contracts', 'approve billing contracts']);
        User::findOrFail(6)->givePermissionTo(['manage transaction']);

        DB::table('customers')->insert([
            ['id' => 1, 'customer_id' => 1, 'name' => 'Hoya Hoya Lda', 'email' => 'hoya@test.local', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'customer_id' => 2, 'name' => 'Other Customer', 'email' => 'other@test.local', 'created_by' => 4, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('taxes')->insert([
            ['id' => 1, 'name' => 'IVA 16%', 'rate' => 16, 'created_by' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach (['billing_contract_audit_events', 'billing_contract_revisions', 'billing_contract_occurrences', 'billing_contract_lines', 'billing_contracts', 'taxes', 'product_services', 'customers', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates', 'settings', 'branches', 'branch_user'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // List / create / preview
    // ------------------------------------------------------------------

    public function test_index_renders(): void
    {
        $response = $this->actingAs(User::findOrFail(1))->get(route('billing-contracts.index'));

        $response->assertOk();
        $response->assertSee('Billing Contracts');
    }

    public function test_store_creates_draft_and_redirects_to_show(): void
    {
        $response = $this->actingAs(User::findOrFail(1))->post(route('billing-contracts.store'), $this->formData());

        $contract = BillingContract::where('created_by', 1)->firstOrFail();
        $response->assertRedirect(route('billing-contracts.show', $contract->id));
        $this->assertSame('CNT-2027-0001', $contract->reference);
        $this->assertSame('draft', $contract->state);
        $this->assertCount(1, $contract->lines);
    }

    public function test_store_with_no_lines_is_refused(): void
    {
        $data = $this->formData();
        $data['lines'] = [];

        $this->actingAs(User::findOrFail(1))->post(route('billing-contracts.store'), $data)
            ->assertRedirect();

        $this->assertSame(0, BillingContract::count());
    }

    public function test_preview_returns_first_three_occurrences_as_json(): void
    {
        $response = $this->actingAs(User::findOrFail(1))->postJson(route('billing-contracts.preview'), $this->formData());

        $response->assertOk();
        $occurrences = $response->json('occurrences');
        $this->assertCount(3, $occurrences);
        $this->assertSame('2027-01-01', $occurrences[0]['period_start']);
        $this->assertSame('2027-01-31', $occurrences[0]['period_end']);
        $this->assertSame('2027-01-01', $occurrences[0]['billing_date']);
        $this->assertSame('2027-01-16', $occurrences[0]['due_date']);
        $this->assertSame('5000.0000', $occurrences[0]['amount']);
        $this->assertSame(0, BillingContract::count());
    }

    public function test_preview_with_bad_data_returns_422(): void
    {
        $data = $this->formData();
        $data['anchor_day'] = 32;

        $this->actingAs(User::findOrFail(1))->postJson(route('billing-contracts.preview'), $data)
            ->assertStatus(422)
            ->assertJsonPath('error', 'The anchor day must be between 1 and 31.');
    }

    // ------------------------------------------------------------------
    // Approval lifecycle over HTTP
    // ------------------------------------------------------------------

    public function test_full_approval_flow_generates_36_occurrences(): void
    {
        $contract = $this->createDraft();

        $this->actingAs(User::findOrFail(1))->post(route('billing-contracts.submit', $contract->id))
            ->assertRedirect(route('billing-contracts.show', $contract->id));
        $this->assertSame('pending_approval', $contract->fresh()->state);
        $this->assertSame(0, BillingContractOccurrence::where('contract_id', $contract->id)->count());

        // The creator cannot approve their own contract.
        $this->actingAs(User::findOrFail(1))->post(route('billing-contracts.approve', $contract->id))
            ->assertRedirect();
        $this->assertSame('pending_approval', $contract->fresh()->state);

        // The approver can.
        $this->actingAs(User::findOrFail(3))->post(route('billing-contracts.approve', $contract->id))
            ->assertRedirect(route('billing-contracts.show', $contract->id));
        $this->assertSame('active', $contract->fresh()->state);
        $this->assertSame(36, BillingContractOccurrence::where('contract_id', $contract->id)->count());

        $response = $this->actingAs(User::findOrFail(1))->get(route('billing-contracts.show', $contract->id));
        $response->assertOk();
        $response->assertSee('CNT-2027-0001');
        $response->assertSee('2027-01-01');
    }

    public function test_reject_returns_to_draft(): void
    {
        $contract = $this->createDraft();
        $this->actingAs(User::findOrFail(1))->post(route('billing-contracts.submit', $contract->id));

        $this->actingAs(User::findOrFail(3))->post(route('billing-contracts.reject', $contract->id), ['reason' => 'Price is wrong.'])
            ->assertRedirect(route('billing-contracts.show', $contract->id));

        $this->assertSame('draft', $contract->fresh()->state);
        $this->assertSame('Price is wrong.', $contract->fresh()->rejected_reason);
    }

    public function test_automatic_mode_activates_on_submit(): void
    {
        $data = $this->formData(['approval_mode' => 'automatic']);
        $this->actingAs(User::findOrFail(1))->post(route('billing-contracts.store'), $data);
        $contract = BillingContract::where('created_by', 1)->firstOrFail();

        $this->actingAs(User::findOrFail(1))->post(route('billing-contracts.submit', $contract->id));

        $this->assertSame('active', $contract->fresh()->state);
        $this->assertSame(36, BillingContractOccurrence::where('contract_id', $contract->id)->count());
    }

    public function test_occurrences_page_lists_and_filters(): void
    {
        $contract = $this->createActive();

        $response = $this->actingAs(User::findOrFail(1))->get(route('billing-contracts.occurrences', $contract->id));
        $response->assertOk();
        $response->assertSee('2027-01-01');
        $response->assertSee('2029-01-01');

        // 36 occurrences, 25 per page — December 2029 is on page 2.
        $response = $this->actingAs(User::findOrFail(1))->get(route('billing-contracts.occurrences', $contract->id) . '?page=2');
        $response->assertOk();
        $response->assertSee('2029-12-01');

        $response = $this->actingAs(User::findOrFail(1))->get(route('billing-contracts.occurrences', $contract->id) . '?state=cancelled');
        $response->assertOk();
        $response->assertDontSee('2027-01-01');
    }

    // ------------------------------------------------------------------
    // Suspend / resume / end / amend over HTTP
    // ------------------------------------------------------------------

    public function test_suspend_and_resume_skip_over_http(): void
    {
        $contract = $this->createActive();

        Carbon::setTestNow(Carbon::parse('2027-02-15 09:00:00'));
        $this->actingAs(User::findOrFail(2))->post(route('billing-contracts.suspend', $contract->id), ['reason' => 'Pause.'])
            ->assertRedirect(route('billing-contracts.show', $contract->id));
        $this->assertSame('suspended', $contract->fresh()->state);

        Carbon::setTestNow(Carbon::parse('2027-03-20 09:00:00'));
        $this->actingAs(User::findOrFail(2))->post(route('billing-contracts.resume', $contract->id), ['missed_decision' => 'skip'])
            ->assertRedirect(route('billing-contracts.show', $contract->id));

        $this->assertSame('active', $contract->fresh()->state);
        $this->assertSame('skipped', BillingContractOccurrence::where('contract_id', $contract->id)->where('period_key', '2027-03')->firstOrFail()->state);
    }

    public function test_end_form_shows_affected_periods_and_end_cancels_them(): void
    {
        $contract = $this->createActive();

        $response = $this->actingAs(User::findOrFail(1))->get(route('billing-contracts.end-form', $contract->id) . '?effective_date=2027-03-15');
        $response->assertOk();
        $response->assertSee('2027-04-01');
        $response->assertSee('Unissued periods affected');

        $this->actingAs(User::findOrFail(1))->post(route('billing-contracts.end', $contract->id), ['effective_date' => '2027-03-15', 'reason' => 'Closed.'])
            ->assertRedirect(route('billing-contracts.show', $contract->id));

        $this->assertSame('ended', $contract->fresh()->state);
        $this->assertSame('cancelled', BillingContractOccurrence::where('contract_id', $contract->id)->where('period_key', '2027-04')->firstOrFail()->state);
    }

    public function test_amend_regenerates_future_occurrences_at_new_price(): void
    {
        $contract = $this->createActive();

        $data = $this->formData(['lines' => [
            ['description' => 'Monthly support', 'quantity' => '1', 'unit_price' => '6000', 'discount_amount' => '0'],
        ]]);
        $data['effective_date'] = '2027-04-01';
        $data['reason'] = 'Price increase.';

        $this->actingAs(User::findOrFail(2))->post(route('billing-contracts.amend', $contract->id), $data)
            ->assertRedirect(route('billing-contracts.show', $contract->id));

        $this->assertSame('6000.0000', number_format((float) BillingContractOccurrence::where('contract_id', $contract->id)->where('period_key', '2027-04')->firstOrFail()->amount, 4, '.', ''));
        $this->assertSame('5000.0000', number_format((float) BillingContractOccurrence::where('contract_id', $contract->id)->where('period_key', '2027-03')->firstOrFail()->amount, 4, '.', ''));
    }

    public function test_edit_of_active_contract_is_refused(): void
    {
        $contract = $this->createActive();

        $response = $this->actingAs(User::findOrFail(2))->get(route('billing-contracts.edit', $contract->id));
        $response->assertRedirect(route('billing-contracts.show', $contract->id));
    }

    // ------------------------------------------------------------------
    // Permissions + tenant isolation
    // ------------------------------------------------------------------

    public function test_user_without_billing_permission_is_denied(): void
    {
        $this->actingAs(User::findOrFail(6))->get(route('billing-contracts.index'))
            ->assertRedirect();
        $this->assertSame(0, BillingContract::count());

        $this->actingAs(User::findOrFail(6))->post(route('billing-contracts.store'), $this->formData())
            ->assertRedirect();
        $this->assertSame(0, BillingContract::count());
    }

    public function test_operator_without_approve_permission_cannot_approve(): void
    {
        $contract = $this->createDraft();
        $this->actingAs(User::findOrFail(1))->post(route('billing-contracts.submit', $contract->id));

        $this->actingAs(User::findOrFail(2))->post(route('billing-contracts.approve', $contract->id))
            ->assertRedirect();

        $this->assertSame('pending_approval', $contract->fresh()->state);
    }

    public function test_other_company_cannot_open_the_contract(): void
    {
        $contract = $this->createActive();

        $response = $this->actingAs(User::findOrFail(4))->get(route('billing-contracts.show', $contract->id));
        $response->assertRedirect(route('billing-contracts.index'));

        $response = $this->actingAs(User::findOrFail(4))->get(route('billing-contracts.occurrences', $contract->id));
        $response->assertRedirect(route('billing-contracts.index'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function createDraft(): BillingContract
    {
        $this->actingAs(User::findOrFail(1))->post(route('billing-contracts.store'), $this->formData());

        return BillingContract::where('created_by', 1)->firstOrFail();
    }

    protected function createActive(): BillingContract
    {
        $contract = $this->createDraft();
        $this->actingAs(User::findOrFail(1))->post(route('billing-contracts.submit', $contract->id));
        $this->actingAs(User::findOrFail(3))->post(route('billing-contracts.approve', $contract->id));

        return $contract->fresh();
    }

    protected function formData(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => 1,
            'title' => 'Monthly support',
            'description' => '',
            'start_date' => '2027-01-01',
            'end_date' => '2029-12-31',
            'currency' => 'MZN',
            'exchange_rate' => '1',
            'billing_frequency' => 'monthly',
            'custom_interval' => '',
            'custom_unit' => '',
            'anchor_day' => 1,
            'billing_mode' => 'advance',
            'payment_terms_days' => 15,
            'approval_mode' => 'approval',
            'partial_period_rule' => 'full',
            'renewal_notice_days' => 30,
            'lines' => [
                ['description' => 'Monthly support', 'quantity' => '1', 'unit_price' => '5000', 'discount_amount' => '0', 'product_id' => '', 'tax_id' => ''],
            ],
        ], $overrides);
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
        Schema::create('customers', function ($table) {
            $table->bigIncrements('id');
            $table->integer('customer_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
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
        Schema::create('product_services', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('billing_contracts', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->integer('branch_id')->nullable();
            $table->bigInteger('customer_id');
            $table->string('reference', 30);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->default('1.0000000000');
            $table->string('billing_frequency', 20)->default('monthly');
            $table->integer('custom_interval')->nullable();
            $table->string('custom_unit', 10)->nullable();
            $table->integer('anchor_day');
            $table->string('billing_mode', 10)->default('advance');
            $table->integer('payment_terms_days')->default(15);
            $table->string('approval_mode', 10)->default('approval');
            $table->boolean('proration_enabled')->default(false);
            $table->string('partial_period_rule', 10)->default('full');
            $table->string('state', 20)->default('draft');
            $table->bigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->text('suspended_reason')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->integer('renewal_notice_days')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->boolean('delivery_auto_email')->default(false);
            $table->text('delivery_to')->nullable();
            $table->text('delivery_cc')->nullable();
            $table->text('notes')->nullable();
            $table->bigInteger('current_revision_id')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'reference'], 'bc_company_ref_uq');
        });

        Schema::create('billing_contract_lines', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('contract_id');
            $table->bigInteger('product_id')->nullable();
            $table->string('description', 250);
            $table->decimal('quantity', 20, 4)->default('1.0000');
            $table->decimal('unit_price', 20, 4)->default('0.0000');
            $table->decimal('discount_amount', 20, 4)->default('0.0000');
            $table->bigInteger('tax_id')->nullable();
            $table->bigInteger('chart_account_id')->nullable();
            $table->bigInteger('cost_centre_id')->nullable();
            $table->decimal('amount', 20, 4)->default('0.0000');
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('billing_contract_occurrences', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('contract_id');
            $table->integer('created_by');
            $table->string('occurrence_key', 80);
            $table->string('period_key', 20);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('billing_date');
            $table->date('due_date');
            $table->decimal('amount', 20, 4)->default('0.0000');
            $table->string('currency', 3)->default('MZN');
            $table->string('state', 20)->default('pending');
            $table->string('purpose', 20)->default('invoice');
            $table->bigInteger('invoice_id')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->text('skip_reason')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'contract_id', 'occurrence_key'], 'bco_occ_key_uq');
        });

        Schema::create('billing_contract_revisions', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('contract_id');
            $table->integer('revision_number');
            $table->date('effective_date');
            $table->text('reason')->nullable();
            $table->json('snapshot')->nullable();
            $table->integer('created_by');
            $table->timestamps();
            $table->unique(['contract_id', 'revision_number'], 'bcr_contract_rev_uq');
        });

        Schema::create('billing_contract_audit_events', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('contract_id');
            $table->bigInteger('occurrence_id')->nullable();
            $table->bigInteger('actor_id')->nullable();
            $table->string('action', 50);
            $table->text('reason')->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
    }
}
