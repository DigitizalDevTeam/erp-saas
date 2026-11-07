<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\CostCentre;
use App\Models\CostCentreAllocation;
use App\Models\DimensionAuditEvent;
use App\Models\User;
use App\Services\CostCentreAllocationService;
use App\Services\CostCentreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CostCentreAllocationWorkflowTest extends TestCase
{
    protected CostCentreAllocationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['manage cost centre allocations', 'approve cost centre allocations', 'edit bill', 'create bill'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['manage cost centre allocations', 'approve cost centre allocations', 'edit bill', 'create bill']);
        User::findOrFail(2)->givePermissionTo(['approve cost centre allocations']);

        $this->service = new CostCentreAllocationService();
    }

    protected function tearDown(): void
    {
        foreach (['dimension_audit_events', 'cost_centre_allocation_templates', 'cost_centre_allocation_lines', 'cost_centre_allocations', 'journal_entries', 'stock_reports', 'transaction_lines', 'chart_of_accounts', 'product_services', 'bill_accounts', 'bill_products', 'bills', 'cost_centres', 'branches', 'settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Schema (minimal, in-memory — mirrors the real migrations)
    // ------------------------------------------------------------------

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

        Schema::create('bills', function ($table) {
            $table->bigIncrements('id');
            $table->integer('bill_id')->default(0);
            $table->integer('vender_id')->default(0);
            $table->date('bill_date')->nullable();
            $table->date('due_date')->nullable();
            $table->integer('status')->default(0);
            $table->string('type', 20)->default('Bill');
            $table->string('user_type', 20)->nullable();
            $table->integer('category_id')->default(0);
            $table->integer('order_number')->default(0);
            $table->integer('purchase_id')->nullable();
            $table->integer('proforma_id')->nullable();
            $table->boolean('suppress_inventory')->default(0);
            $table->boolean('stock_on_delivery')->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('bill_products', function ($table) {
            $table->bigIncrements('id');
            $table->integer('bill_id');
            $table->integer('product_id');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('tax')->nullable();
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('price', 18, 2)->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('bill_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->integer('chart_account_id')->nullable();
            $table->decimal('price', 18, 2)->default(0);
            $table->text('description')->nullable();
            $table->string('type', 20)->nullable();
            $table->integer('ref_id');
            $table->timestamps();
        });
        Schema::create('product_services', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->string('type', 50)->default('service');
            $table->integer('expense_chartaccount_id')->nullable();
            $table->decimal('quantity', 10, 2)->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('chart_of_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->string('code', 50)->nullable();
            $table->string('name')->nullable();
            $table->integer('created_by')->default(0);
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
        Schema::create('stock_reports', function ($table) {
            $table->bigIncrements('id');
            $table->integer('product_id')->nullable();
            $table->decimal('quantity', 10, 2)->default(0);
            $table->string('type', 50)->nullable();
            $table->integer('type_id')->nullable();
            $table->text('description')->nullable();
            $table->integer('warehouse_loss_id')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('journal_entries', function ($table) {
            $table->bigIncrements('id');
            $table->string('reference')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        // Tables the admin layout / view composers touch when a Blade
        // page renders (same set Phase 0's workflow test creates).
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

        Schema::create('cost_centre_allocations', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id');
            $table->string('source_line_type', 20);
            $table->unsignedBigInteger('source_line_id');
            $table->string('basis', 20)->default('percentage');
            $table->decimal('source_amount', 18, 2);
            $table->string('currency_code', 10)->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('rule_snapshot')->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->unsignedBigInteger('replaces_allocation_id')->nullable();
            $table->integer('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->integer('reversed_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('cost_centre_allocation_lines', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('allocation_id');
            $table->unsignedBigInteger('cost_centre_id');
            $table->decimal('percentage', 9, 6)->nullable();
            $table->decimal('fixed_amount', 18, 2)->nullable();
            $table->decimal('allocated_amount', 18, 2);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('cost_centre_allocation_templates', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('name', 100);
            $table->string('basis', 20)->default('percentage');
            $table->json('lines');
            $table->timestamps();
        });
        Schema::create('dimension_audit_events', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 30);
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->integer('actor_id')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeCentres(): array
    {
        $service = new CostCentreService();
        $adm = $service->create(['code' => 'ADM', 'name' => 'Administration'], 1, 1);
        $ops = $service->create(['code' => 'OPS', 'name' => 'Operations'], 1, 1);
        $tec = $service->create(['code' => 'TEC', 'name' => 'Technology'], 1, 1);

        return [$adm, $ops, $tec];
    }

    private function makeBill(int $amount = 60000, string $type = 'Bill'): Bill
    {
        $billId = DB::table('bills')->insertGetId([
            'bill_id' => 1, 'vender_id' => 0, 'bill_date' => '2026-11-07', 'due_date' => '2026-11-07',
            'status' => 0, 'type' => $type, 'user_type' => 'vendor', 'category_id' => 0,
            'order_number' => 0, 'suppress_inventory' => 1, 'stock_on_delivery' => 0,
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_services')->insert([
            'id' => 1, 'name' => 'Utilities', 'type' => 'service',
            'expense_chartaccount_id' => 1, 'quantity' => 0, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('chart_of_accounts')->insert([
            'id' => 1, 'code' => '621', 'name' => 'Utilities', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('chart_of_accounts')->insert([
            'id' => 2, 'code' => '421', 'name' => 'Suppliers', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('bill_products')->insert([
            'id' => 1, 'bill_id' => $billId, 'product_id' => 1, 'quantity' => 1,
            'tax' => null, 'discount' => 0, 'price' => $amount, 'description' => 'Utilities',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Bill::findOrFail($billId);
    }

    private function split(array $centres, array $percentages): array
    {
        $lines = [];
        foreach ($centres as $i => $centre) {
            $lines[] = ['cost_centre_id' => (int) $centre->id, 'percentage' => $percentages[$i], 'fixed_amount' => null, 'sort_order' => $i];
        }

        return $lines;
    }

    // ------------------------------------------------------------------
    // Happy path + rejections (service + HTTP)
    // ------------------------------------------------------------------

    public function test_post_allocation_on_bill_line_happy_path(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        $bill = $this->makeBill(60000);

        $allocation = $this->service->createPosted(
            'bill', $bill->id, 'bill_product', 1, 'percentage',
            $this->split([$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']),
            1, 1
        );

        $this->assertSame('posted', $allocation->status);
        $this->assertSame('60000.00', (string) $allocation->source_amount);
        $this->assertSame(['24000.00', '21000.00', '15000.00'],
            $allocation->lines->map(fn ($l) => (string) $l->allocated_amount)->all());
        // Rule snapshot persisted with the posted amounts.
        $this->assertSame('percentage', $allocation->rule_snapshot['basis']);
        $this->assertSame('60000.00', $allocation->rule_snapshot['source_amount']);
        // Audit trail written.
        $this->assertSame(1, DimensionAuditEvent::where('entity_type', 'cost_centre_allocation')
            ->where('entity_id', $allocation->id)->where('action', 'posted')->count());
    }

    public function test_inactive_centre_is_rejected(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        (new CostCentreService())->deactivate($tec->fresh(), 1, 1);
        $bill = $this->makeBill(60000);

        $this->expectException(\App\Exceptions\CostCentreException::class);
        $this->service->createPosted(
            'bill', $bill->id, 'bill_product', 1, 'percentage',
            $this->split([$adm, $ops, $tec->fresh()], ['40.000000', '35.000000', '25.000000']),
            1, 1
        );
    }

    public function test_summary_only_parent_is_rejected(): void
    {
        $parent = (new CostCentreService())->create(['code' => 'PAR', 'name' => 'Parent'], 1, 1);
        $child = (new CostCentreService())->create(['code' => 'LEAF', 'name' => 'Leaf', 'parent_id' => $parent->id], 1, 1);
        $bill = $this->makeBill(1000);

        $this->expectException(\App\Exceptions\CostCentreException::class);
        $this->service->createPosted(
            'bill', $bill->id, 'bill_product', 1, 'percentage',
            [['cost_centre_id' => (int) $parent->id, 'percentage' => '100.000000', 'fixed_amount' => null, 'sort_order' => 0]],
            1, 1
        );

        // The leaf child is fine.
        $ok = $this->service->createPosted(
            'bill', $bill->id, 'bill_product', 1, 'percentage',
            [['cost_centre_id' => (int) $child->id, 'percentage' => '100.000000', 'fixed_amount' => null, 'sort_order' => 0]],
            1, 1
        );
        $this->assertSame('posted', $ok->status);
    }

    public function test_reverse_and_repost_never_edits_posted_split(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        $bill = $this->makeBill(60000);

        $first = $this->service->createPosted(
            'bill', $bill->id, 'bill_product', 1, 'percentage',
            $this->split([$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']),
            1, 1
        );

        // Reverse with a reason.
        $reversed = $this->service->reverse($first, 1, 1, 'Wrong split, redoing.');
        $this->assertSame('reversed', $reversed->status);
        $this->assertSame('Wrong split, redoing.', $reversed->reversal_reason);
        $this->assertSame(1, (int) $reversed->reversed_by);

        // The reversed allocation cannot be reversed again, and its
        // persisted amounts are untouched (never edited in place).
        $this->assertSame(['24000.00', '21000.00', '15000.00'],
            $reversed->lines->map(fn ($l) => (string) $l->allocated_amount)->all());
        $this->expectException(\App\Exceptions\CostCentreException::class);
        $this->service->reverse($reversed->fresh(), 1, 1, 'Again.');
    }

    public function test_repost_after_reversal_links_to_original(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        $bill = $this->makeBill(60000);

        $first = $this->service->createPosted(
            'bill', $bill->id, 'bill_product', 1, 'percentage',
            $this->split([$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']),
            1, 1
        );
        $this->service->reverse($first, 1, 1, 'Redo.');

        $second = $this->service->createPosted(
            'bill', $bill->id, 'bill_product', 1, 'percentage',
            $this->split([$adm, $ops], ['60.000000', '40.000000']),
            1, 1, null, (int) $first->id
        );

        $this->assertSame((int) $first->id, (int) $second->replaces_allocation_id);
        $this->assertSame(['36000.00', '24000.00'],
            $second->lines->map(fn ($l) => (string) $l->allocated_amount)->all());
        // Full traceability: created/posted + reversed + posted events.
        $this->assertSame(3, DimensionAuditEvent::where('entity_type', 'cost_centre_allocation')->count());
    }

    public function test_no_self_approval(): void
    {
        [$adm, $ops] = $this->makeCentres();
        $bill = $this->makeBill(1000);

        $allocation = $this->service->createPosted(
            'bill', $bill->id, 'bill_product', 1, 'percentage',
            $this->split([$adm, $ops], ['60.000000', '40.000000']),
            1, 1
        );

        // The user who posted it (user 1) cannot approve it.
        try {
            $this->service->approve($allocation, 1, 1);
            $this->fail('Self-approval should have been rejected.');
        } catch (\App\Exceptions\CostCentreException $e) {
            $this->assertStringContainsString('cannot approve', strtolower($e->getMessage()));
        }

        // A different user with the approve permission can.
        $approved = $this->service->approve($allocation->fresh(), 1, 2);
        $this->assertSame(2, (int) $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
    }

    public function test_closed_period_blocks_posting(): void
    {
        [$adm] = $this->makeCentres();
        $bill = $this->makeBill(1000);
        DB::table('journal_entries')->insert([
            'reference' => 'FY-2026-CLOSE-1', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(\App\Exceptions\CostCentreException::class);
        $this->service->createPosted(
            'bill', $bill->id, 'bill_product', 1, 'percentage',
            [['cost_centre_id' => (int) $adm->id, 'percentage' => '100.000000', 'fixed_amount' => null, 'sort_order' => 0]],
            1, 1
        );
    }

    // ------------------------------------------------------------------
    // HTTP: validation endpoint, store, templates, permissions
    // ------------------------------------------------------------------

    public function test_validate_endpoint_returns_computed_split_without_posting(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();

        $response = $this->actingAs(User::findOrFail(1))->postJson(route('cost-centre-allocations.validate'), [
            'source_amount' => '60000.00',
            'basis' => 'percentage',
            'lines' => $this->split([$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']),
        ]);

        $response->assertOk()->assertJson([
            'ok' => true,
            'total_allocated' => '60000.00',
            'rounding_difference' => '0.00',
        ]);
        $this->assertSame(['24000.00', '21000.00', '15000.00'],
            array_column($response->json('lines'), 'allocated_amount'));
        $this->assertSame(0, CostCentreAllocation::count());
    }

    public function test_validate_endpoint_rejects_ninety_nine_percent(): void
    {
        [$adm, $ops] = $this->makeCentres();

        $response = $this->actingAs(User::findOrFail(1))->postJson(route('cost-centre-allocations.validate'), [
            'source_amount' => '1000.00',
            'basis' => 'percentage',
            'lines' => $this->split([$adm, $ops], ['50.000000', '49.000000']),
        ]);

        $response->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertSame(0, CostCentreAllocation::count());
    }

    public function test_store_endpoint_posts_allocation_for_bill_line(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        $bill = $this->makeBill(60000);

        $response = $this->actingAs(User::findOrFail(1))->postJson(route('cost-centre-allocations.store'), [
            'source_type' => 'bill',
            'source_id' => $bill->id,
            'source_line_type' => 'bill_product',
            'source_line_id' => 1,
            'basis' => 'percentage',
            'lines' => $this->split([$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']),
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $allocation = CostCentreAllocation::firstOrFail();
        $this->assertSame('posted', $allocation->status);
        $this->assertSame(['24000.00', '21000.00', '15000.00'],
            $allocation->lines()->orderBy('sort_order')->pluck('allocated_amount')
                ->map(fn ($v) => (string) $v)->all());
    }

    public function test_store_endpoint_rejects_wrong_company_centre(): void
    {
        [$adm, $ops] = $this->makeCentres();
        $foreign = (new CostCentreService())->create(['code' => 'FOR', 'name' => 'Foreign'], 4, 4);
        $bill = $this->makeBill(1000);

        $response = $this->actingAs(User::findOrFail(1))->postJson(route('cost-centre-allocations.store'), [
            'source_type' => 'bill',
            'source_id' => $bill->id,
            'source_line_type' => 'bill_product',
            'source_line_id' => 1,
            'basis' => 'percentage',
            'lines' => [
                ['cost_centre_id' => (int) $adm->id, 'percentage' => '50.000000', 'fixed_amount' => null, 'sort_order' => 0],
                ['cost_centre_id' => (int) $foreign->id, 'percentage' => '50.000000', 'fixed_amount' => null, 'sort_order' => 1],
            ],
        ]);

        $response->assertStatus(422)->assertJson(['ok' => false]);
        $this->assertSame(0, CostCentreAllocation::count());
    }

    public function test_user_without_permission_is_denied(): void
    {
        [$adm, $ops] = $this->makeCentres();
        $bill = $this->makeBill(1000);

        $this->actingAs(User::findOrFail(6))->postJson(route('cost-centre-allocations.store'), [
            'source_type' => 'bill',
            'source_id' => $bill->id,
            'source_line_type' => 'bill_product',
            'source_line_id' => 1,
            'basis' => 'percentage',
            'lines' => $this->split([$adm, $ops], ['50.000000', '50.000000']),
        ])->assertForbidden();

        $this->actingAs(User::findOrFail(6))->postJson(route('cost-centre-allocations.validate'), [
            'source_amount' => '1000.00', 'basis' => 'percentage',
            'lines' => $this->split([$adm, $ops], ['50.000000', '50.000000']),
        ])->assertForbidden();

        $this->assertSame(0, CostCentreAllocation::count());
    }

    public function test_templates_create_and_list(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();

        $response = $this->actingAs(User::findOrFail(1))->postJson(route('cost-centre-allocation-templates.store'), [
            'name' => 'Utilities 40/35/25',
            'basis' => 'percentage',
            'lines' => $this->split([$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']),
        ]);
        $response->assertOk()->assertJson(['ok' => true]);

        $list = $this->actingAs(User::findOrFail(1))->getJson(route('cost-centre-allocation-templates.index'));
        $list->assertOk()->assertJsonCount(1, 'templates');
        $this->assertSame('Utilities 40/35/25', $list->json('templates')[0]['name']);

        // A template whose percentages do not sum to 100% is rejected.
        $this->actingAs(User::findOrFail(1))->postJson(route('cost-centre-allocation-templates.store'), [
            'name' => 'Bad template',
            'basis' => 'percentage',
            'lines' => $this->split([$adm, $ops], ['50.000000', '49.000000']),
        ])->assertStatus(422);
    }

    public function test_reverse_and_approve_via_http(): void
    {
        [$adm, $ops] = $this->makeCentres();
        $bill = $this->makeBill(1000);
        $allocation = $this->service->createPosted(
            'bill', $bill->id, 'bill_product', 1, 'percentage',
            $this->split([$adm, $ops], ['60.000000', '40.000000']),
            1, 1
        );

        // Self-approval is refused over HTTP too.
        $this->actingAs(User::findOrFail(1))->postJson(
            route('cost-centre-allocations.approve', $allocation->id)
        )->assertStatus(422);

        // Another user approves.
        $this->actingAs(User::findOrFail(2))->postJson(
            route('cost-centre-allocations.approve', $allocation->id)
        )->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(2, (int) $allocation->fresh()->approved_by);

        // Reverse needs a reason.
        $this->actingAs(User::findOrFail(1))->postJson(
            route('cost-centre-allocations.reverse', $allocation->id),
            ['reason' => '']
        )->assertStatus(422);

        $this->actingAs(User::findOrFail(1))->postJson(
            route('cost-centre-allocations.reverse', $allocation->id),
            ['reason' => 'No longer needed.']
        )->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('reversed', $allocation->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Lifecycle: bill edit (plan §11 risk 2)
    // ------------------------------------------------------------------

    public function test_bill_edit_with_new_payload_replaces_old_allocation(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        $bill = $this->makeBill(60000);

        $old = $this->service->createPosted(
            'bill', $bill->id, 'bill_product', 1, 'percentage',
            $this->split([$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']),
            1, 1
        );

        $response = $this->actingAs(User::findOrFail(1))->put(route('bill.update', $bill->id), [
            'vender_id' => 0,
            'bill_date' => '2026-11-07',
            'due_date' => '2026-11-07',
            'category_id' => 0,
            'order_number' => 0,
            'items' => [[
                'id' => 1, 'items' => 1, 'quantity' => 1, 'price' => 60000,
                'discount' => 0, 'tax' => '', 'description' => 'Utilities',
                'chart_account_id' => '', 'account_id' => '', 'amount' => '',
            ]],
            'allocations' => [[
                'source_line_type' => 'bill_product',
                'source_line_id' => 1,
                'basis' => 'percentage',
                'lines' => $this->split([$adm, $ops], ['60.000000', '40.000000']),
            ]],
        ]);

        $response->assertRedirect(route('bill.index'));

        // Old allocation is gone as a posted split (reversed, kept for audit).
        $this->assertSame('reversed', $old->fresh()->status);
        $this->assertSame(1, CostCentreAllocation::where('status', 'reversed')->count());

        // New allocation posted for the same line, linked to the old one.
        $new = CostCentreAllocation::where('status', 'posted')->firstOrFail();
        $this->assertSame((int) $old->id, (int) $new->replaces_allocation_id);
        $this->assertSame(['36000.00', '24000.00'],
            $new->lines()->orderBy('sort_order')->pluck('allocated_amount')
                ->map(fn ($v) => (string) $v)->all());
    }

    public function test_bill_edit_without_payload_rebases_percentage_split_on_amount_change(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        $bill = $this->makeBill(60000);

        $old = $this->service->createPosted(
            'bill', $bill->id, 'bill_product', 1, 'percentage',
            $this->split([$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']),
            1, 1
        );

        // Edit the bill: same line, new price 120,000, no allocations payload.
        $response = $this->actingAs(User::findOrFail(1))->put(route('bill.update', $bill->id), [
            'vender_id' => 0,
            'bill_date' => '2026-11-07',
            'due_date' => '2026-11-07',
            'category_id' => 0,
            'order_number' => 0,
            'items' => [[
                'id' => 1, 'items' => 1, 'quantity' => 1, 'price' => 120000,
                'discount' => 0, 'tax' => '', 'description' => 'Utilities',
                'chart_account_id' => '', 'account_id' => '', 'amount' => '',
            ]],
        ]);

        $response->assertRedirect(route('bill.index'));

        // Old split reversed; same percentages re-posted on 120,000.
        $this->assertSame('reversed', $old->fresh()->status);
        $new = CostCentreAllocation::where('status', 'posted')->firstOrFail();
        $this->assertSame('120000.00', (string) $new->source_amount);
        $this->assertSame(['48000.00', '42000.00', '30000.00'],
            $new->lines()->orderBy('sort_order')->pluck('allocated_amount')
                ->map(fn ($v) => (string) $v)->all());
        // Reports still agree with the GL: posted allocations sum to the line.
        $this->assertSame('120000.00', number_format((float) $new->lines()->sum('allocated_amount'), 2, '.', ''));
    }

    public function test_bill_line_delete_reverses_its_allocation(): void
    {
        [$adm, $ops] = $this->makeCentres();
        $bill = $this->makeBill(1000);

        $allocation = $this->service->createPosted(
            'bill', $bill->id, 'bill_product', 1, 'percentage',
            $this->split([$adm, $ops], ['60.000000', '40.000000']),
            1, 1
        );

        $this->service->reverseForLine('bill', $bill->id, 'bill_product', 1, 1, 1, 'Line deleted.');

        $this->assertSame('reversed', $allocation->fresh()->status);
        $this->assertSame(0, CostCentreAllocation::where('status', 'posted')->count());
    }

    public function test_expense_lifecycle_rebases_on_edit(): void
    {
        [$adm, $ops] = $this->makeCentres();
        $expense = $this->makeBill(5000, 'Expense');

        $old = $this->service->createPosted(
            'expense', $expense->id, 'bill_product', 1, 'percentage',
            $this->split([$adm, $ops], ['70.000000', '30.000000']),
            1, 1
        );

        // Simulate the expense edit: line amount changes to 10,000.
        DB::table('bill_products')->where('id', 1)->update(['price' => 10000]);
        $this->service->afterBillUpdated($expense->fresh(), null, 1, 1);

        $this->assertSame('reversed', $old->fresh()->status);
        $new = CostCentreAllocation::where('status', 'posted')->firstOrFail();
        $this->assertSame(['7000.00', '3000.00'],
            $new->lines()->orderBy('sort_order')->pluck('allocated_amount')
                ->map(fn ($v) => (string) $v)->all());
    }

    public function test_fixed_split_cannot_rebase_and_stays_reversed(): void
    {
        [$adm, $ops] = $this->makeCentres();
        $bill = $this->makeBill(1000);

        $old = $this->service->createPosted(
            'bill', $bill->id, 'bill_product', 1, 'fixed_amount',
            [
                ['cost_centre_id' => (int) $adm->id, 'percentage' => null, 'fixed_amount' => '600.00', 'sort_order' => 0],
                ['cost_centre_id' => (int) $ops->id, 'percentage' => null, 'fixed_amount' => '400.00', 'sort_order' => 1],
            ],
            1, 1
        );

        DB::table('bill_products')->where('id', 1)->update(['price' => 2000]);
        $this->service->afterBillUpdated($bill->fresh(), null, 1, 1);

        // Fixed amounts no longer add up to the new amount: reversed, not re-posted.
        $this->assertSame('reversed', $old->fresh()->status);
        $this->assertSame(0, CostCentreAllocation::where('status', 'posted')->count());
    }

    public function test_allocations_index_requires_permission(): void
    {
        $this->actingAs(User::findOrFail(6))->get(route('cost-centre-allocations.index'))
            ->assertRedirect()->assertSessionHas('error');

        [$adm] = $this->makeCentres();
        $bill = $this->makeBill(1000);
        $this->service->createPosted(
            'bill', $bill->id, 'bill_product', 1, 'percentage',
            [['cost_centre_id' => (int) $adm->id, 'percentage' => '100.000000', 'fixed_amount' => null, 'sort_order' => 0]],
            1, 1
        );

        $this->actingAs(User::findOrFail(1))->get(route('cost-centre-allocations.index'))
            ->assertOk()->assertSee('ADM');
    }
}
