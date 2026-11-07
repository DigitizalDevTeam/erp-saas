<?php

namespace Tests\Feature;

use App\Models\KitchenProductionOrder;
use App\Models\KitchenProductionPlan;
use App\Models\KitchenRecipe;
use App\Models\KitchenRequisition;
use App\Models\User;
use App\Models\WarehouseProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class KitchenProductionPlanWorkflowTest extends TestCase
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
            $table->integer('kitchen_production_plan_id')->nullable();
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
            $table->decimal('planned_food_cost_percent', 5, 2)->nullable();
            $table->string('planned_food_cost_band', 20)->nullable();
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

        Schema::create('kitchen_production_plans', function ($table) {
            $table->increments('id');
            $table->string('plan_number', 30);
            $table->date('plan_date');
            $table->string('meal_service', 20);
            $table->integer('branch_id')->nullable();
            $table->integer('warehouse_id');
            $table->string('section', 30)->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('approval_step', 20)->nullable();
            $table->json('occupancy_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->integer('submitted_by')->nullable();
            $table->integer('head_chef_approved_by')->nullable();
            $table->timestamp('head_chef_approved_at')->nullable();
            $table->integer('fb_manager_approved_by')->nullable();
            $table->timestamp('fb_manager_approved_at')->nullable();
            $table->integer('gm_approved_by')->nullable();
            $table->timestamp('gm_approved_at')->nullable();
            $table->integer('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_note')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('kitchen_production_plan_lines', function ($table) {
            $table->increments('id');
            $table->integer('kitchen_production_plan_id');
            $table->integer('kitchen_recipe_id');
            $table->integer('recipe_version')->default(1);
            $table->decimal('planned_quantity', 12, 4);
            $table->integer('planned_unit_id')->nullable();
            $table->string('source', 30)->default('manual');
            $table->text('notes')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('kitchen_requisitions', function ($table) {
            $table->increments('id');
            $table->string('requisition_number', 30);
            $table->integer('kitchen_production_plan_id')->nullable();
            $table->integer('kitchen_production_order_id')->nullable();
            $table->integer('source_warehouse_id');
            $table->integer('kitchen_warehouse_id');
            $table->integer('branch_id')->nullable();
            $table->string('status', 20)->default('requested');
            $table->integer('requested_by')->nullable();
            $table->integer('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->integer('issued_by')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->integer('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_note')->nullable();
            $table->text('notes')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('kitchen_requisition_lines', function ($table) {
            $table->increments('id');
            $table->integer('kitchen_requisition_id');
            $table->integer('ingredient_product_id');
            $table->decimal('required_quantity', 12, 4);
            $table->decimal('available_quantity', 12, 4)->default(0);
            $table->decimal('shortage_quantity', 12, 4)->default(0);
            $table->decimal('issued_quantity', 12, 4)->default(0);
            $table->integer('unit_id')->nullable();
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('warehouse_transfers', function ($table) {
            $table->increments('id');
            $table->string('transfer_batch_id')->nullable();
            $table->integer('from_warehouse');
            $table->integer('to_warehouse');
            $table->integer('product_id');
            $table->decimal('quantity', 15, 4)->default(0);
            $table->date('date')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('hotel_reservations', function ($table) {
            $table->increments('id');
            $table->string('reservation_no')->nullable();
            $table->integer('hotel_guest_id')->nullable();
            $table->integer('hotel_room_type_id')->nullable();
            $table->integer('hotel_room_id')->nullable();
            $table->integer('branch_id')->nullable();
            $table->date('check_in_date')->nullable();
            $table->date('check_out_date')->nullable();
            $table->integer('adults')->default(1);
            $table->integer('extra_adults')->default(0);
            $table->integer('children')->default(0);
            $table->integer('infants')->default(0);
            $table->string('meal_plan', 20)->nullable();
            $table->string('status', 20)->default('pending');
            $table->integer('created_by')->default(0);
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
        foreach (['manage kitchen production', 'create kitchen recipes', 'approve kitchen recipes', 'manage kitchen production orders', 'approve kitchen production orders', 'approve kitchen production plans', 'manage kitchen requisitions', 'approve kitchen requisitions'] as $name) {
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
            ['id' => 2, 'name' => 'ARMAZEM GERAL', 'created_by' => 1],
        ]);
        // Kitchen holds only 1 kg of rice; the general warehouse holds 5 kg.
        DB::table('warehouse_products')->insert([
            ['warehouse_id' => 1, 'product_id' => 11, 'quantity' => 1, 'created_by' => 1],
            ['warehouse_id' => 2, 'product_id' => 11, 'quantity' => 5, 'created_by' => 1],
        ]);

        // One reservation in-house today: 2 adults + 1 child, half board.
        DB::table('hotel_reservations')->insert([
            'id' => 1, 'reservation_no' => 'RES-1', 'branch_id' => null,
            'check_in_date' => date('Y-m-d', strtotime('-1 day')), 'check_out_date' => date('Y-m-d', strtotime('+2 days')),
            'adults' => 2, 'extra_adults' => 0, 'children' => 1, 'infants' => 0,
            'meal_plan' => 'half_board', 'status' => 'confirmed', 'created_by' => 1,
        ]);

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Chef', 'email' => 'chef@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Manager', 'email' => 'manager@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 5, 'name' => 'Other Chef', 'email' => 'otherchef@test.local', 'type' => 'employee', 'created_by' => 4, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['manage kitchen production', 'create kitchen recipes', 'manage kitchen production orders', 'approve kitchen production plans', 'manage kitchen requisitions', 'approve kitchen requisitions']);
        User::findOrFail(2)->givePermissionTo(['manage kitchen production', 'create kitchen recipes', 'manage kitchen production orders', 'manage kitchen requisitions']);
        User::findOrFail(3)->givePermissionTo(['manage kitchen production', 'approve kitchen recipes', 'manage kitchen production orders', 'approve kitchen production orders', 'approve kitchen production plans', 'approve kitchen requisitions']);
        User::findOrFail(4)->givePermissionTo(['manage kitchen production', 'create kitchen recipes', 'manage kitchen production orders']);
        User::findOrFail(5)->givePermissionTo(['manage kitchen production', 'create kitchen recipes', 'manage kitchen production orders', 'approve kitchen production orders']);
    }

    protected function tearDown(): void
    {
        foreach (['role_has_permissions', 'model_has_roles', 'model_has_permissions', 'permissions', 'roles', 'plans', 'email_templates', 'languages', 'ch_messages', 'branches', 'branch_user', 'settings', 'users', 'hotel_reservations', 'warehouse_transfers', 'kitchen_requisition_lines', 'kitchen_requisitions', 'kitchen_production_plan_lines', 'kitchen_production_plans', 'warehouses', 'product_expiry_lots', 'stock_reports', 'warehouse_products', 'kitchen_production_order_lot_usages', 'kitchen_production_batches', 'kitchen_production_order_lines', 'kitchen_production_orders', 'product_recipe_alternatives', 'product_recipes', 'kitchen_unit_conversions', 'kitchen_recipes', 'product_services', 'product_service_units'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function approvedRecipe(string $code = 'KR-2026-0001'): KitchenRecipe
    {
        // Create the recipe through HTTP, then approve it through the
        // normal workflow, so the plan tests start from a real approval.
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

    private function planPayload(int $recipeId, float $portions = 40): array
    {
        return [
            'plan_date' => date('Y-m-d'),
            'meal_service' => 'dinner',
            'branch_id' => null,
            'warehouse_id' => 1,
            'section' => 'hot_kitchen',
            'notes' => null,
            // 40 portions x 150 g = 6 kg yield = 2 batches = 2 kg rice.
            // The kitchen holds 1 kg -> 1 kg short. planned_unit_id 2 is
            // the recipe's portion unit (g), so 40 means 40 portions.
            'lines' => [
                [
                    'kitchen_recipe_id' => $recipeId,
                    'planned_quantity' => $portions,
                    'planned_unit_id' => 2,
                    'source' => 'occupancy',
                    'notes' => null,
                ],
            ],
        ];
    }

    private function approvedPlan(int $recipeId): KitchenProductionPlan
    {
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.production-plans.store'), $this->planPayload($recipeId))
            ->assertRedirect();

        $plan = KitchenProductionPlan::latest('id')->firstOrFail();

        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.production-plans.submit', $plan->id))
            ->assertRedirect();
        $this->actingAs(User::findOrFail(3))
            ->post(route('kitchen.production-plans.approve', $plan->id))
            ->assertRedirect();
        $this->actingAs(User::findOrFail(1))
            ->post(route('kitchen.production-plans.approve', $plan->id))
            ->assertRedirect();

        $this->assertTrue($plan->fresh()->isApproved());

        return $plan->fresh();
    }

    public function test_plan_happy_path_from_occupancy_to_issued_requisition(): void
    {
        $recipe = $this->approvedRecipe();

        // Chef creates the plan from occupancy.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.production-plans.store'), $this->planPayload($recipe->id))
            ->assertRedirect();

        $plan = KitchenProductionPlan::latest('id')->firstOrFail();
        $this->assertTrue($plan->isDraft());
        $this->assertMatchesRegularExpression('/^PL-\d{4}-0001$/', $plan->plan_number);

        // The plan show page names the reservation behind the numbers.
        $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.production-plans.show', $plan->id))
            ->assertOk()
            ->assertSee('RES-1')
            ->assertSee('Uncooked Rice');

        // Submit -> Head Chef approves -> GM approves (no F&B role here).
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.production-plans.submit', $plan->id))
            ->assertRedirect();
        $this->assertSame(KitchenProductionPlan::STEP_HEAD_CHEF, $plan->fresh()->approval_step);

        $this->actingAs(User::findOrFail(3))
            ->post(route('kitchen.production-plans.approve', $plan->id))
            ->assertRedirect();
        $this->assertSame(KitchenProductionPlan::STEP_GENERAL_MANAGER, $plan->fresh()->approval_step);

        // The submitter cannot approve their own plan.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.production-plans.approve', $plan->id))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs(User::findOrFail(1))
            ->post(route('kitchen.production-plans.approve', $plan->id))
            ->assertRedirect();
        $this->assertTrue($plan->fresh()->isApproved());

        // The approved plan turns into production orders.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.production-plans.create-orders', $plan->id))
            ->assertRedirect();
        $this->assertTrue($plan->fresh()->isInProduction());
        $orders = KitchenProductionOrder::where('kitchen_production_plan_id', $plan->id)->get();
        $this->assertCount(1, $orders);
        $this->assertTrue($orders->first()->isDraft());

        // The 1 kg rice shortage becomes a requisition.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.requisitions.from-plan', $plan->id), ['source_warehouse_id' => 2])
            ->assertRedirect();

        $requisition = KitchenRequisition::latest('id')->firstOrFail();
        $this->assertTrue($requisition->isRequested());
        $this->assertSame((int) $plan->id, (int) $requisition->kitchen_production_plan_id);
        $line = $requisition->lines->firstOrFail();
        $this->assertSame(2.0, (float) $line->required_quantity);
        $this->assertSame(1.0, (float) $line->shortage_quantity);

        // The requester cannot approve their own requisition.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.requisitions.approve', $requisition->id))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs(User::findOrFail(3))
            ->post(route('kitchen.requisitions.approve', $requisition->id))
            ->assertRedirect();
        $this->assertTrue($requisition->fresh()->isApproved());

        // Issue the 1 kg: source 5 -> 4, kitchen 1 -> 2.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.requisitions.issue.store', $requisition->id), [
                'quantities' => [(int) $line->id => 1.0],
            ])
            ->assertRedirect();
        $this->assertTrue($requisition->fresh()->isIssued());
        $this->assertSame(2.0, (float) WarehouseProduct::where('warehouse_id', 1)->where('product_id', 11)->sum('quantity'));
        $this->assertSame(4.0, (float) WarehouseProduct::where('warehouse_id', 2)->where('product_id', 11)->sum('quantity'));
    }

    public function test_plan_rejection_returns_to_draft_and_can_be_resubmitted(): void
    {
        $recipe = $this->approvedRecipe();

        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.production-plans.store'), $this->planPayload($recipe->id))
            ->assertRedirect();
        $plan = KitchenProductionPlan::latest('id')->firstOrFail();

        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.production-plans.submit', $plan->id))
            ->assertRedirect();

        // Head Chef rejects with a reason.
        $this->actingAs(User::findOrFail(3))
            ->post(route('kitchen.production-plans.reject', $plan->id), ['rejection_note' => 'Too much rice for 3 covers.'])
            ->assertRedirect();
        $plan = $plan->fresh();
        $this->assertTrue($plan->isRejected());
        $this->assertSame('Too much rice for 3 covers.', $plan->rejection_note);

        // The chef edits the rejected plan; it returns to draft.
        $this->actingAs(User::findOrFail(2))
            ->put(route('kitchen.production-plans.update', $plan->id), $this->planPayload($recipe->id, 20))
            ->assertRedirect();
        $plan = $plan->fresh();
        $this->assertTrue($plan->isDraft());
        $this->assertNull($plan->rejection_note);

        // Resubmit and approve through the full chain.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.production-plans.submit', $plan->id))
            ->assertRedirect();
        $this->actingAs(User::findOrFail(3))
            ->post(route('kitchen.production-plans.approve', $plan->id))
            ->assertRedirect();
        $this->actingAs(User::findOrFail(1))
            ->post(route('kitchen.production-plans.approve', $plan->id))
            ->assertRedirect();
        $this->assertTrue($plan->fresh()->isApproved());
    }

    public function test_plan_and_requisition_pages_deny_users_without_permission(): void
    {
        $this->actingAs(User::findOrFail(6))
            ->get(route('kitchen.production-plans.index'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs(User::findOrFail(6))
            ->get(route('kitchen.requisitions.index'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_plan_index_lists_plans_and_filters_by_status(): void
    {
        $recipe = $this->approvedRecipe();
        $this->approvedPlan($recipe->id);

        $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.production-plans.index'))
            ->assertOk()
            ->assertSee('PL-')
            ->assertSee('Approved');

        $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.production-plans.index', ['status' => 'draft']))
            ->assertOk()
            ->assertDontSee('PL-');
    }

    public function test_plan_and_requisition_create_and_edit_forms_render(): void
    {
        $recipe = $this->approvedRecipe();

        // Plan create form.
        $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.production-plans.create'))
            ->assertOk()
            ->assertSee('Planned Dishes')
            ->assertSee('Add Dish');

        // Draft plan edit form.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.production-plans.store'), $this->planPayload($recipe->id))
            ->assertRedirect();
        $plan = KitchenProductionPlan::latest('id')->firstOrFail();

        $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.production-plans.edit', $plan->id))
            ->assertOk()
            ->assertSee('Planned Dishes');

        // Requisition create form, prefilled from the plan's shortages.
        $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.requisitions.create', ['plan_id' => $plan->id]))
            ->assertOk()
            ->assertSee('Requested Ingredients')
            ->assertSee('Add Ingredient');

        // Requisition edit form for a requested requisition.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.requisitions.from-plan', $plan->id), ['source_warehouse_id' => 2])
            ->assertRedirect();
        $requisition = KitchenRequisition::latest('id')->firstOrFail();

        $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.requisitions.edit', $requisition->id))
            ->assertOk()
            ->assertSee('Requested Ingredients');

        // Issue form for an approved requisition.
        $this->actingAs(User::findOrFail(3))
            ->post(route('kitchen.requisitions.approve', $requisition->id))
            ->assertRedirect();
        $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.requisitions.issue', $requisition->id))
            ->assertOk()
            ->assertSee('Issue Now');
    }
}
