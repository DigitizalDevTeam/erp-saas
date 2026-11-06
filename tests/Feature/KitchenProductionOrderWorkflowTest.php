<?php

namespace Tests\Feature;

use App\Models\KitchenProductionBatch;
use App\Models\KitchenProductionOrder;
use App\Models\KitchenRecipe;
use App\Models\User;
use App\Models\WarehouseProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class KitchenProductionOrderWorkflowTest extends TestCase
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
            $table->float('quantity')->default(0);
            $table->boolean('batch_tracking')->default(false);
            $table->string('batch_consumption_method', 20)->nullable();
            $table->boolean('allow_negative_stock')->default(false);
            $table->boolean('affect_stock')->default(true);
            $table->integer('low_stock_threshold')->nullable();
            $table->integer('created_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('kitchen_recipes', function ($table) {
            $table->increments('id');
            $table->string('code', 50);
            $table->string('name');
            $table->integer('product_id');
            $table->integer('category_id')->nullable();
            $table->integer('version')->default(1);
            $table->string('status', 20)->default('draft');
            $table->decimal('expected_yield_quantity', 12, 4)->nullable();
            $table->integer('expected_yield_unit_id')->nullable();
            $table->decimal('standard_portion_quantity', 12, 4)->nullable();
            $table->integer('standard_portion_unit_id')->nullable();
            $table->integer('preparation_time_minutes')->nullable();
            $table->text('equipment')->nullable();
            $table->text('instructions')->nullable();
            $table->text('storage_conditions')->nullable();
            $table->text('allergens')->nullable();
            $table->string('photo')->nullable();
            $table->decimal('loss_percent', 5, 2)->default(0);
            $table->integer('cost_centre_id')->nullable();
            $table->integer('branch_id')->nullable();
            $table->integer('created_by');
            $table->integer('submitted_by')->nullable();
            $table->integer('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->timestamps();

            $table->unique(['created_by', 'code', 'version']);
            $table->unique(['created_by', 'product_id', 'version']);
        });

        Schema::create('kitchen_unit_conversions', function ($table) {
            $table->increments('id');
            $table->integer('from_unit_id');
            $table->integer('to_unit_id');
            $table->decimal('factor', 18, 6);
            $table->integer('product_id')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('product_recipes', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('kitchen_recipe_id')->nullable();
            $table->integer('version')->default(1);
            $table->integer('ingredient_product_id');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('loss_percent', 5, 2)->default(0);
            $table->string('unit')->nullable();
            $table->integer('unit_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('product_recipe_alternatives', function ($table) {
            $table->increments('id');
            $table->integer('product_recipe_id');
            $table->integer('alternative_product_id');
            $table->decimal('quantity', 12, 4)->nullable();
            $table->integer('sort_order')->default(0);
            $table->integer('created_by')->nullable();
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
            $table->decimal('actual_yield_quantity', 12, 4)->nullable();
            $table->integer('actual_yield_unit_id')->nullable();
            $table->string('status', 20)->default('draft');
            $table->integer('responsible_user_id')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('planned_cost', 15, 2)->default(0);
            $table->decimal('actual_cost', 15, 2)->nullable();
            $table->decimal('cost_per_portion', 15, 2)->nullable();
            $table->decimal('food_cost_percent', 5, 2)->nullable();
            $table->string('food_cost_band', 20)->nullable();
            $table->date('batch_expiry_date')->nullable();
            $table->integer('submitted_by')->nullable();
            $table->integer('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->integer('started_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->integer('completed_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('validated_by')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('stock_deducted_at')->nullable();
            $table->timestamp('stock_received_at')->nullable();
            $table->integer('cost_centre_id')->nullable();
            $table->integer('created_by');
            $table->timestamps();

            $table->unique(['created_by', 'order_number']);
        });

        Schema::create('kitchen_production_order_lines', function ($table) {
            $table->increments('id');
            $table->integer('kitchen_production_order_id');
            $table->integer('ingredient_product_id');
            $table->decimal('planned_quantity', 12, 4);
            $table->integer('planned_unit_id')->nullable();
            $table->decimal('planned_unit_cost', 15, 4)->default(0);
            $table->decimal('planned_cost', 15, 2)->default(0);
            $table->decimal('actual_quantity', 12, 4)->nullable();
            $table->decimal('actual_unit_cost', 15, 4)->nullable();
            $table->decimal('actual_cost', 15, 2)->nullable();
            $table->decimal('loss_percent', 5, 2)->default(0);
            $table->integer('created_by');
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

        Schema::create('kitchen_production_order_lot_usages', function ($table) {
            $table->increments('id');
            $table->integer('kitchen_production_order_line_id');
            $table->integer('product_expiry_lot_id');
            $table->decimal('quantity', 12, 4);
            $table->integer('created_by');
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

        Schema::create('product_expiry_lots', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('warehouse_id')->nullable();
            $table->integer('purchase_id')->nullable();
            $table->integer('purchase_product_id')->nullable();
            $table->string('source', 20)->default('purchase');
            $table->string('batch_no')->nullable();
            $table->date('mfg_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity_in', 16, 4)->default(0);
            $table->decimal('quantity_remaining', 16, 4)->default(0);
            $table->integer('created_by')->default(0);
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
        foreach (['manage kitchen production', 'create kitchen recipes', 'approve kitchen recipes', 'manage kitchen production orders', 'approve kitchen production orders'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('product_service_units')->insert([
            ['id' => 1, 'name' => 'kg', 'created_by' => 1],
            ['id' => 2, 'name' => 'g', 'created_by' => 1],
        ]);
        DB::table('kitchen_unit_conversions')->insert([
            ['from_unit_id' => 1, 'to_unit_id' => 2, 'factor' => 1000, 'product_id' => null, 'created_by' => 1],
        ]);
        DB::table('product_services')->insert([
            ['id' => 10, 'name' => 'Coconut Rice', 'type' => 'product', 'sale_price' => 50, 'sale_price_type' => 'exclusive', 'purchase_price' => 0, 'unit_id' => 1, 'quantity' => 0, 'created_by' => 1],
            ['id' => 11, 'name' => 'Uncooked Rice', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 100, 'unit_id' => 1, 'quantity' => 10, 'created_by' => 1],
        ]);
        DB::table('warehouses')->insert([
            ['id' => 1, 'name' => 'COZINHA', 'created_by' => 1],
        ]);
        DB::table('warehouse_products')->insert([
            ['warehouse_id' => 1, 'product_id' => 11, 'quantity' => 5, 'created_by' => 1],
        ]);

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Chef', 'email' => 'chef@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Manager', 'email' => 'manager@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 5, 'name' => 'Other Chef', 'email' => 'otherchef@test.local', 'type' => 'employee', 'created_by' => 4, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['manage kitchen production', 'create kitchen recipes', 'manage kitchen production orders']);
        User::findOrFail(2)->givePermissionTo(['manage kitchen production', 'create kitchen recipes', 'manage kitchen production orders']);
        User::findOrFail(3)->givePermissionTo(['manage kitchen production', 'approve kitchen recipes', 'manage kitchen production orders', 'approve kitchen production orders']);
        User::findOrFail(4)->givePermissionTo(['manage kitchen production', 'create kitchen recipes', 'manage kitchen production orders']);
        User::findOrFail(5)->givePermissionTo(['manage kitchen production', 'create kitchen recipes', 'manage kitchen production orders', 'approve kitchen production orders']);
    }

    protected function tearDown(): void
    {
        foreach (['role_has_permissions', 'model_has_roles', 'model_has_permissions', 'permissions', 'roles', 'plans', 'email_templates', 'languages', 'ch_messages', 'branches', 'branch_user', 'settings', 'users', 'warehouses', 'product_expiry_lots', 'stock_reports', 'warehouse_products', 'kitchen_production_order_lot_usages', 'kitchen_production_batches', 'kitchen_production_order_lines', 'kitchen_production_orders', 'product_recipe_alternatives', 'product_recipes', 'kitchen_unit_conversions', 'kitchen_recipes', 'product_services', 'product_service_units'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function approvedRecipe(string $code = 'KR-2026-0001'): KitchenRecipe
    {
        // Create the recipe through HTTP, then approve it through the
        // normal workflow, so the order tests start from a real approval.
        $this->actingAs(User::findOrFail(1))
            ->post(route('kitchen.recipes.store'), [
                'code' => $code,
                'name' => 'Coconut Rice',
                'product_id' => 10,
                'expected_yield_quantity' => 3,
                'expected_yield_unit_id' => 1,
                'standard_portion_quantity' => 150,
                'standard_portion_unit_id' => 2,
                'loss_percent' => 0,
                'lines' => [
                    ['ingredient_product_id' => 11, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0, 'alternatives' => []],
                ],
            ])
            ->assertRedirect();

        $recipe = KitchenRecipe::where('code', $code)->firstOrFail();

        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.recipes.submit', $recipe->id))
            ->assertRedirect();
        $this->actingAs(User::findOrFail(3))
            ->post(route('kitchen.recipes.approve', $recipe->id))
            ->assertRedirect();

        $this->assertSame(KitchenRecipe::STATUS_APPROVED, $recipe->fresh()->status);

        return $recipe->fresh();
    }

    private function orderPayload(int $recipeId): array
    {
        return [
            'kitchen_recipe_id' => $recipeId,
            'production_date' => date('Y-m-d'),
            'section' => 'hot_kitchen',
            'warehouse_id' => 1,
            'planned_quantity' => 3,
            'planned_unit_id' => 1,
            'responsible_user_id' => 2,
        ];
    }

    private function inPreparationOrder(int $recipeId): KitchenProductionOrder
    {
        $this->actingAs(User::findOrFail(1))
            ->post(route('kitchen.production-orders.store'), $this->orderPayload($recipeId))
            ->assertRedirect();

        $order = KitchenProductionOrder::latest('id')->firstOrFail();

        $this->actingAs(User::findOrFail(3))
            ->post(route('kitchen.production-orders.approve', $order->id))
            ->assertRedirect();

        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.production-orders.start', $order->id))
            ->assertRedirect();

        return $order->fresh();
    }

    public function test_full_workflow_happy_path(): void
    {
        $recipe = $this->approvedRecipe();

        // Create a draft order.
        $this->actingAs(User::findOrFail(1))
            ->post(route('kitchen.production-orders.store'), $this->orderPayload($recipe->id))
            ->assertRedirect();

        $order = KitchenProductionOrder::latest('id')->firstOrFail();
        $this->assertSame(KitchenProductionOrder::STATUS_DRAFT, $order->status);
        $this->assertNull($order->order_number);
        $this->assertSame(100.0, (float) $order->planned_cost);
        $this->assertCount(1, $order->lines);
        $this->assertSame(1.0, (float) $order->lines->first()->planned_quantity);

        // The creator cannot approve their own order.
        $this->actingAs(User::findOrFail(1))
            ->post(route('kitchen.production-orders.approve', $order->id))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertSame(KitchenProductionOrder::STATUS_DRAFT, $order->fresh()->status);

        // A different user approves: the number is allocated.
        $this->actingAs(User::findOrFail(3))
            ->post(route('kitchen.production-orders.approve', $order->id))
            ->assertRedirect(route('kitchen.production-orders.show', $order->id));
        $approved = $order->fresh();
        $this->assertSame('KP-'.date('Y').'-0001', $approved->order_number);
        $this->assertSame(KitchenProductionOrder::STATUS_APPROVED, $approved->status);

        // Start preparation.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.production-orders.start', $order->id))
            ->assertRedirect(route('kitchen.production-orders.show', $order->id));
        $this->assertSame(KitchenProductionOrder::STATUS_IN_PREPARATION, $order->fresh()->status);

        // The completion form renders with the planned lines.
        $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.production-orders.complete', $order->id))
            ->assertOk()
            ->assertSee('Uncooked Rice')
            ->assertSee('Complete & Receive Batch');

        // Complete: 1 kg rice used, 3 kg coconut rice produced.
        $line = $order->lines->firstOrFail();
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.production-orders.complete.store', $order->id), [
                'actuals' => [(string) $line->id => 1],
                'actual_yield_quantity' => 3,
                'actual_yield_unit_id' => 1,
                'expiry_date' => date('Y-m-d', strtotime('+5 days')),
            ])
            ->assertRedirect(route('kitchen.production-orders.show', $order->id));

        $completed = $order->fresh();
        $this->assertSame(KitchenProductionOrder::STATUS_COMPLETED, $completed->status);
        $this->assertSame(100.0, (float) $completed->actual_cost);
        $this->assertSame(5.0, (float) $completed->cost_per_portion);
        $this->assertSame(10.0, (float) $completed->food_cost_percent);
        $this->assertSame('excellent', $completed->food_cost_band);

        // Stock moved once: rice 5 -> 4 kg, coconut rice 0 -> 3 kg.
        $this->assertSame(4.0, (float) WarehouseProduct::where('product_id', 11)->where('warehouse_id', 1)->value('quantity'));
        $this->assertSame(3.0, (float) WarehouseProduct::where('product_id', 10)->where('warehouse_id', 1)->value('quantity'));

        // The batch is in Kitchen Stock.
        $batch = KitchenProductionBatch::where('kitchen_production_order_id', $order->id)->firstOrFail();
        $this->assertSame($order->fresh()->order_number.'-B1', $batch->batch_number);
        $this->assertSame(3.0, (float) $batch->quantity_remaining);

        $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.stock.index'))
            ->assertOk()
            ->assertSee($batch->batch_number);

        // The show page renders the cost summary and the batch.
        $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.production-orders.show', $order->id))
            ->assertOk()
            ->assertSee('Coconut Rice')
            ->assertSee($batch->batch_number)
            ->assertSee('Excellent');

        // The completer cannot validate; another approver can.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.production-orders.validate', $order->id))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertSame(KitchenProductionOrder::STATUS_COMPLETED, $order->fresh()->status);

        $this->actingAs(User::findOrFail(3))
            ->post(route('kitchen.production-orders.validate', $order->id))
            ->assertRedirect(route('kitchen.production-orders.show', $order->id));
        $this->assertSame(KitchenProductionOrder::STATUS_VALIDATED, $order->fresh()->status);

        // A completed/validated order can no longer be edited or deleted.
        $this->actingAs(User::findOrFail(1))
            ->get(route('kitchen.production-orders.edit', $order->id))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_create_and_edit_pages_render(): void
    {
        $recipe = $this->approvedRecipe();

        $this->actingAs(User::findOrFail(1))
            ->get(route('kitchen.production-orders.create'))
            ->assertOk()
            ->assertSee('Coconut Rice')
            ->assertSee('Kitchen Warehouse');

        $this->actingAs(User::findOrFail(1))
            ->post(route('kitchen.production-orders.store'), $this->orderPayload($recipe->id))
            ->assertRedirect();

        $order = KitchenProductionOrder::latest('id')->firstOrFail();

        $this->actingAs(User::findOrFail(1))
            ->get(route('kitchen.production-orders.edit', $order->id))
            ->assertOk()
            ->assertSee('Coconut Rice');
    }

    public function test_create_with_draft_recipe_fails_validation(): void
    {
        $this->actingAs(User::findOrFail(1))
            ->post(route('kitchen.recipes.store'), [
                'code' => 'KR-2026-0007',
                'name' => 'Coconut Rice',
                'product_id' => 10,
                'expected_yield_quantity' => 3,
                'expected_yield_unit_id' => 1,
                'standard_portion_quantity' => 150,
                'standard_portion_unit_id' => 2,
                'loss_percent' => 0,
                'lines' => [
                    ['ingredient_product_id' => 11, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0, 'alternatives' => []],
                ],
            ])
            ->assertRedirect();

        $draft = KitchenRecipe::where('code', 'KR-2026-0007')->firstOrFail();
        $this->assertSame(KitchenRecipe::STATUS_DRAFT, $draft->status);

        $this->actingAs(User::findOrFail(1))
            ->post(route('kitchen.production-orders.store'), $this->orderPayload($draft->id))
            ->assertSessionHasErrors('kitchen_recipe_id');

        $this->assertSame(0, KitchenProductionOrder::count());
    }

    public function test_complete_blocked_on_shortage(): void
    {
        $recipe = $this->approvedRecipe();
        $order = $this->inPreparationOrder($recipe->id);
        $line = $order->lines->firstOrFail();

        // Only 0.5 kg of rice in the warehouse; the order needs 1 kg.
        WarehouseProduct::where('product_id', 11)->update(['quantity' => 0.5]);

        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.production-orders.complete.store', $order->id), [
                'actuals' => [(string) $line->id => 1],
                'actual_yield_quantity' => 3,
                'actual_yield_unit_id' => 1,
                'expiry_date' => date('Y-m-d', strtotime('+5 days')),
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(KitchenProductionOrder::STATUS_IN_PREPARATION, $order->fresh()->status);
        $this->assertSame(0, KitchenProductionBatch::count());
    }

    public function test_user_without_permissions_is_turned_away(): void
    {
        $recipe = $this->approvedRecipe();

        $this->actingAs(User::findOrFail(6))
            ->get(route('kitchen.production-orders.index'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs(User::findOrFail(6))
            ->post(route('kitchen.production-orders.store'), $this->orderPayload($recipe->id))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, KitchenProductionOrder::count());
    }

    public function test_other_company_cannot_see_or_touch_orders(): void
    {
        $recipe = $this->approvedRecipe();
        $order = $this->inPreparationOrder($recipe->id);

        // Direct URL to the order: 403.
        $this->actingAs(User::findOrFail(5))
            ->get(route('kitchen.production-orders.show', $order->id))
            ->assertForbidden();

        // Direct action URL: 403, status unchanged.
        $this->actingAs(User::findOrFail(5))
            ->post(route('kitchen.production-orders.approve', $order->id))
            ->assertForbidden();
        $this->assertSame(KitchenProductionOrder::STATUS_IN_PREPARATION, $order->fresh()->status);

        // The other company's list is empty.
        $this->actingAs(User::findOrFail(5))
            ->get(route('kitchen.production-orders.index'))
            ->assertOk()
            ->assertDontSee('KP-'.date('Y').'-0001');
    }
}
