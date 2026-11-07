<?php

namespace Tests\Feature;

use App\Models\KitchenLoss;
use App\Models\User;
use App\Models\WarehouseLoss;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Kitchen Production Phase 5 — losses HTTP workflow.
 *
 * Happy path: a preparation loss is recorded directly as approved and
 * posted (stock written off through WarehouseLoss). Rejection path: a
 * staff meal waits for a manager, the recorder cannot approve it, and
 * a manager can reject it. Also covers the permission gate and the
 * cross-company guard.
 */
class KitchenLossWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('product_service_units', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('dimension_mode')->nullable();
            $table->string('length_unit')->nullable();
            $table->decimal('volumetric_divisor', 10, 2)->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('product_services', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('type', 20)->nullable();
            $table->decimal('sale_price', 15, 4)->default(0);
            $table->string('sale_price_type', 20)->nullable();
            $table->decimal('purchase_price', 15, 4)->default(0);
            $table->integer('tax_id')->nullable();
            $table->integer('unit_id')->nullable();
            $table->integer('show_on_pos')->default(1);
            $table->integer('created_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('warehouses', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('branch_id')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('city_zip')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('warehouse_products', function ($table) {
            $table->increments('id');
            $table->integer('warehouse_id')->default(0);
            $table->integer('product_id')->default(0);
            $table->integer('product_variant_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->string('row_no')->nullable();
            $table->string('shelf_no')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('stock_reports', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->decimal('quantity', 15, 4)->default(0);
            $table->string('type')->nullable();
            $table->integer('type_id')->nullable();
            $table->integer('warehouse_loss_id')->nullable();
            $table->text('description')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('kitchen_losses', function ($table) {
            $table->increments('id');
            $table->string('loss_number', 30);
            $table->string('category', 30);
            $table->integer('product_id');
            $table->decimal('quantity', 12, 4);
            $table->integer('unit_id')->nullable();
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->integer('warehouse_id');
            $table->integer('destination_warehouse_id')->nullable();
            $table->text('reason')->nullable();
            $table->integer('recorded_by')->nullable();
            $table->string('status', 20)->default('recorded');
            $table->integer('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->integer('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_note')->nullable();
            $table->integer('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->boolean('reuse_flag')->default(false);
            $table->text('food_safety_note')->nullable();
            $table->string('reuse_destination', 30)->nullable();
            $table->integer('kitchen_production_order_id')->nullable();
            $table->integer('pos_id')->nullable();
            $table->integer('pos_product_id')->nullable();
            $table->integer('kitchen_production_batch_id')->nullable();
            $table->integer('warehouse_loss_id')->nullable();
            $table->boolean('stock_already_deducted')->default(false);
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('warehouse_losses', function ($table) {
            $table->increments('id');
            $table->string('write_off_no', 50)->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->integer('product_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('cost_of_goods', 15, 2)->default(0);
            $table->date('date')->nullable();
            $table->text('reason')->nullable();
            $table->string('issuer_name')->nullable();
            $table->string('authorized_by')->nullable();
            $table->string('witness_name')->nullable();
            $table->boolean('delivery_note_required')->default(false);
            $table->string('delivery_destination')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('warehouse_transfers', function ($table) {
            $table->increments('id');
            $table->integer('transfer_batch_id')->nullable();
            $table->integer('from_warehouse')->nullable();
            $table->integer('to_warehouse')->nullable();
            $table->integer('product_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->date('date')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('kitchen_production_batches', function ($table) {
            $table->increments('id');
            $table->string('batch_number', 50);
            $table->integer('kitchen_production_order_id');
            $table->integer('product_id');
            $table->decimal('quantity_produced', 12, 4);
            $table->decimal('quantity_remaining', 12, 4);
            $table->integer('unit_id')->nullable();
            $table->integer('warehouse_id');
            $table->date('expiry_date')->nullable();
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->decimal('total_cost', 15, 2)->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('kitchen_production_orders', function ($table) {
            $table->increments('id');
            $table->string('order_number', 30)->nullable();
            $table->integer('kitchen_recipe_id');
            $table->integer('recipe_version')->default(1);
            $table->date('production_date')->nullable();
            $table->string('section', 30)->nullable();
            $table->integer('branch_id')->nullable();
            $table->integer('warehouse_id');
            $table->decimal('planned_quantity', 12, 4);
            $table->integer('planned_unit_id')->nullable();
            $table->string('status', 20)->default('draft');
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('branches', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('branch_user', function ($table) {
            $table->integer('user_id');
            $table->integer('branch_id');
            $table->timestamps();
        });

        Schema::create('ch_messages', function ($table) {
            $table->increments('id');
            $table->integer('to_id')->nullable();
            $table->integer('seen')->default(0);
            $table->timestamps();
        });

        Schema::create('languages', function ($table) {
            $table->increments('id');
            $table->string('code')->nullable();
            $table->string('full_name')->nullable();
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

        Schema::create('plans', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
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

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['manage kitchen losses', 'approve kitchen losses'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('product_service_units')->insert([
            ['id' => 1, 'name' => 'kg', 'created_by' => 1],
            ['id' => 2, 'name' => 'g', 'created_by' => 1],
        ]);
        DB::table('product_services')->insert([
            ['id' => 12, 'name' => 'Beef Mince', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 800, 'unit_id' => 1, 'created_by' => 1],
        ]);
        DB::table('warehouses')->insert([
            ['id' => 1, 'name' => 'COZINHA', 'created_by' => 1],
        ]);
        DB::table('warehouse_products')->insert([
            ['warehouse_id' => 1, 'product_id' => 12, 'quantity' => 5, 'created_by' => 1],
        ]);

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Chef', 'email' => 'chef@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Manager', 'email' => 'manager@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['manage kitchen losses', 'approve kitchen losses']);
        User::findOrFail(2)->givePermissionTo(['manage kitchen losses']);
        User::findOrFail(3)->givePermissionTo(['manage kitchen losses', 'approve kitchen losses']);
        User::findOrFail(4)->givePermissionTo(['manage kitchen losses']);
    }

    protected function tearDown(): void
    {
        foreach (['role_has_permissions', 'model_has_roles', 'model_has_permissions', 'permissions', 'roles', 'plans', 'email_templates', 'languages', 'ch_messages', 'branches', 'branch_user', 'settings', 'users', 'kitchen_production_orders', 'kitchen_production_batches', 'warehouse_transfers', 'warehouse_losses', 'kitchen_losses', 'stock_reports', 'warehouse_products', 'warehouses', 'product_services', 'product_service_units'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function lossPayload(array $overrides = []): array
    {
        return array_merge([
            'category' => KitchenLoss::CATEGORY_PREPARATION,
            'product_id' => 12,
            'quantity' => 1,
            'unit_id' => 1,
            'unit_cost' => '',
            'warehouse_id' => 1,
            'destination_warehouse_id' => '',
            'reason' => 'Spilled during prep',
            'reuse_flag' => '0',
            'food_safety_note' => '',
            'reuse_destination' => '',
            'kitchen_production_order_id' => '',
            'kitchen_production_batch_id' => '',
        ], $overrides);
    }

    public function test_create_and_post_direct_loss_happy_path(): void
    {
        // The chef records a preparation loss — no approval needed.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.losses.store'), $this->lossPayload())
            ->assertRedirect();

        $loss = KitchenLoss::firstOrFail();
        $this->assertSame(KitchenLoss::STATUS_APPROVED, $loss->status);
        $this->assertSame('KL-'.date('Y').'-0001', $loss->loss_number);
        $this->assertSame(800.0, (float) $loss->total_cost);

        // The show page renders the loss.
        $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.losses.show', $loss->id))
            ->assertOk()
            ->assertSee($loss->loss_number)
            ->assertSee('Beef Mince')
            ->assertSee('Preparation loss');

        // The chef posts it: the warehouse stock falls and a
        // WarehouseLoss write-off is created.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.losses.post', $loss->id))
            ->assertRedirect(route('kitchen.losses.show', $loss->id));

        $posted = $loss->fresh();
        $this->assertTrue($posted->isPosted());
        $this->assertNotNull($posted->warehouse_loss_id);
        $this->assertSame(4.0, (float) DB::table('warehouse_products')->where('product_id', 12)->where('warehouse_id', 1)->sum('quantity'));

        $writeOff = WarehouseLoss::findOrFail((int) $posted->warehouse_loss_id);
        $this->assertSame('WO-000001', $writeOff->write_off_no);
        $this->assertSame(12, (int) $writeOff->product_id);
        $this->assertSame(1.0, (float) $writeOff->quantity);
        $this->assertSame(800.0, (float) $writeOff->cost_of_goods);
        $this->assertStringContainsString($posted->loss_number, (string) $writeOff->reason);
    }

    public function test_staff_meal_approval_and_rejection_paths(): void
    {
        // The chef records a staff meal — it waits for a manager.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.losses.store'), $this->lossPayload(['category' => KitchenLoss::CATEGORY_STAFF_MEAL]))
            ->assertRedirect();

        $loss = KitchenLoss::firstOrFail();
        $this->assertTrue($loss->isRecorded());

        // The recorder cannot approve their own loss.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.losses.approve', $loss->id))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertTrue($loss->fresh()->isRecorded());

        // The manager rejects it instead, with a note.
        $this->actingAs(User::findOrFail(3))
            ->post(route('kitchen.losses.reject', $loss->id), ['rejection_note' => 'Staff meals come from the staff menu, not from prep stock'])
            ->assertRedirect(route('kitchen.losses.show', $loss->id));

        $rejected = $loss->fresh();
        $this->assertTrue($rejected->isRejected());
        $this->assertSame('Staff meals come from the staff menu, not from prep stock', $rejected->rejection_note);

        $this->actingAs(User::findOrFail(3))
            ->get(route('kitchen.losses.show', $loss->id))
            ->assertOk()
            ->assertSee('Staff meals come from the staff menu');

        // A rejected loss cannot be posted.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.losses.post', $loss->id))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertTrue($loss->fresh()->isRejected());

        // A second staff meal is approved by the manager, then posted.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.losses.store'), $this->lossPayload(['category' => KitchenLoss::CATEGORY_STAFF_MEAL]))
            ->assertRedirect();

        $second = KitchenLoss::orderByDesc('id')->firstOrFail();
        $this->actingAs(User::findOrFail(3))
            ->post(route('kitchen.losses.approve', $second->id))
            ->assertRedirect(route('kitchen.losses.show', $second->id));
        $this->assertTrue($second->fresh()->isApproved());

        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.losses.post', $second->id))
            ->assertRedirect(route('kitchen.losses.show', $second->id));
        $this->assertTrue($second->fresh()->isPosted());
    }

    public function test_create_page_renders(): void
    {
        $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.losses.create'))
            ->assertOk()
            ->assertSee('Record Loss')
            ->assertSee('Category')
            ->assertSee('still safe and can be reused');
    }

    public function test_index_lists_losses(): void
    {
        KitchenLoss::create([
            'loss_number' => 'KL-'.date('Y').'-0001',
            'category' => KitchenLoss::CATEGORY_PREPARATION,
            'product_id' => 12,
            'quantity' => 1,
            'unit_id' => 1,
            'unit_cost' => 800,
            'total_cost' => 800,
            'warehouse_id' => 1,
            'recorded_by' => 2,
            'status' => KitchenLoss::STATUS_APPROVED,
            'created_by' => 1,
        ]);

        $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.losses.index'))
            ->assertOk()
            ->assertSee('KL-'.date('Y').'-0001')
            ->assertSee('Beef Mince');
    }

    public function test_user_without_permission_cannot_open_losses(): void
    {
        $this->actingAs(User::findOrFail(6))
            ->get(route('kitchen.losses.index'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_other_company_cannot_view_a_loss(): void
    {
        KitchenLoss::create([
            'loss_number' => 'KL-'.date('Y').'-0001',
            'category' => KitchenLoss::CATEGORY_PREPARATION,
            'product_id' => 12,
            'quantity' => 1,
            'unit_id' => 1,
            'unit_cost' => 800,
            'total_cost' => 800,
            'warehouse_id' => 1,
            'recorded_by' => 2,
            'status' => KitchenLoss::STATUS_APPROVED,
            'created_by' => 1,
        ]);
        $loss = KitchenLoss::firstOrFail();

        $this->actingAs(User::findOrFail(4))
            ->get(route('kitchen.losses.show', $loss->id))
            ->assertNotFound();
    }
}
