<?php

namespace Tests\Unit;

use App\Models\KitchenProductionOrder;
use App\Models\KitchenProductionPlan;
use App\Models\KitchenRecipe;
use App\Models\User;
use App\Services\KitchenOccupancyService;
use App\Services\KitchenProductionOrderService;
use App\Services\KitchenProductionPlanService;
use App\Services\KitchenRecipeCostService;
use App\Services\KitchenUnitConversionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KitchenProductionPlanServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // --- Same base seed as the Phase 1 order tests -----------------
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

        // --- Phase 3 tables --------------------------------------------
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

        Schema::create('roles', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('model_has_roles', function ($table) {
            $table->integer('role_id');
            $table->string('model_type');
            $table->integer('model_id');
            $table->index(['model_id', 'model_type']);
        });

        DB::table('product_service_units')->insert([
            ['id' => 1, 'name' => 'kg', 'created_by' => 1],
            ['id' => 2, 'name' => 'g', 'created_by' => 1],
            ['id' => 3, 'name' => 'portion', 'created_by' => 1],
        ]);
        DB::table('kitchen_unit_conversions')->insert([
            ['from_unit_id' => 1, 'to_unit_id' => 2, 'factor' => 1000, 'product_id' => null, 'created_by' => 1],
        ]);
        DB::table('product_services')->insert([
            ['id' => 10, 'name' => 'Coconut Rice', 'type' => 'product', 'sale_price' => 50, 'sale_price_type' => 'exclusive', 'purchase_price' => 0, 'unit_id' => 1, 'quantity' => 0, 'created_by' => 1],
            ['id' => 11, 'name' => 'Uncooked Rice', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 100, 'unit_id' => 1, 'quantity' => 10, 'created_by' => 1],
            ['id' => 12, 'name' => 'Beef Mince', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 800, 'unit_id' => 1, 'quantity' => 5, 'created_by' => 1],
            ['id' => 14, 'name' => 'Beef Burger', 'type' => 'product', 'sale_price' => 500, 'sale_price_type' => 'exclusive', 'purchase_price' => 0, 'unit_id' => 3, 'quantity' => 0, 'created_by' => 1],
            ['id' => 15, 'name' => 'Rice Pudding', 'type' => 'product', 'sale_price' => 60, 'sale_price_type' => 'exclusive', 'purchase_price' => 0, 'unit_id' => 1, 'quantity' => 0, 'created_by' => 1],
        ]);
        DB::table('warehouses')->insert([
            ['id' => 1, 'name' => 'COZINHA', 'created_by' => 1],
            ['id' => 2, 'name' => 'ARMAZEM GERAL', 'created_by' => 1],
        ]);
        DB::table('warehouse_products')->insert([
            ['warehouse_id' => 1, 'product_id' => 11, 'quantity' => 5, 'created_by' => 1],
            ['warehouse_id' => 1, 'product_id' => 12, 'quantity' => 5, 'created_by' => 1],
            ['warehouse_id' => 2, 'product_id' => 12, 'quantity' => 10, 'created_by' => 1],
        ]);
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Chef', 'email' => 'chef@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Head Chef', 'email' => 'headchef@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 5, 'name' => 'FB Manager', 'email' => 'fb@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        // One reservation in-house on the plan date (2026-11-10): 2 adults
        // + 1 child on half board = 3 dinner covers.
        DB::table('hotel_reservations')->insert([
            'id' => 1, 'reservation_no' => 'RES-1', 'branch_id' => null,
            'check_in_date' => '2026-11-09', 'check_out_date' => '2026-11-12',
            'adults' => 2, 'extra_adults' => 0, 'children' => 1, 'infants' => 0,
            'meal_plan' => 'half_board', 'status' => 'confirmed', 'created_by' => 1,
        ]);

        $this->makeRecipe('KR-2026-0001', 'Coconut Rice', 10, 3, 1, 150, 2, [
            ['ingredient_product_id' => 11, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0],
        ]);
        $this->makeRecipe('KR-2026-0002', 'Beef Burger', 14, 10, 3, 1, 3, [
            ['ingredient_product_id' => 12, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['model_has_roles', 'roles', 'hotel_reservations', 'kitchen_production_plan_lines', 'kitchen_production_plans', 'users', 'branches', 'warehouses', 'product_expiry_lots', 'stock_reports', 'warehouse_products', 'kitchen_production_order_lot_usages', 'kitchen_production_batches', 'kitchen_production_order_lines', 'kitchen_production_orders', 'product_recipe_alternatives', 'product_recipes', 'kitchen_unit_conversions', 'kitchen_recipes', 'product_services', 'product_service_units'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function service(): KitchenProductionPlanService
    {
        $conversions = new KitchenUnitConversionService();
        $orders = new KitchenProductionOrderService($conversions, new KitchenRecipeCostService($conversions));

        return new KitchenProductionPlanService(new KitchenOccupancyService(), $orders);
    }

    private function orderService(): KitchenProductionOrderService
    {
        $conversions = new KitchenUnitConversionService();

        return new KitchenProductionOrderService($conversions, new KitchenRecipeCostService($conversions));
    }

    private function makeRecipe(string $code, string $name, int $productId, float $yieldQty, int $yieldUnit, float $portionQty, int $portionUnit, array $lines, string $status = KitchenRecipe::STATUS_APPROVED): KitchenRecipe
    {
        $recipe = KitchenRecipe::create([
            'code' => $code,
            'name' => $name,
            'product_id' => $productId,
            'version' => 1,
            'status' => $status,
            'expected_yield_quantity' => $yieldQty,
            'expected_yield_unit_id' => $yieldUnit,
            'standard_portion_quantity' => $portionQty,
            'standard_portion_unit_id' => $portionUnit,
            'loss_percent' => 0,
            'created_by' => 1,
        ]);

        foreach ($lines as $line) {
            DB::table('product_recipes')->insert(array_merge($line, [
                'product_id' => $productId,
                'kitchen_recipe_id' => $recipe->id,
                'version' => 1,
                'created_by' => 1,
            ]));
        }

        return $recipe->fresh();
    }

    private function planData(?KitchenRecipe $recipe = null, float $qty = 20, ?int $unitId = 3): array
    {
        $recipe ??= KitchenRecipe::where('code', 'KR-2026-0002')->firstOrFail();

        return [
            'plan_date' => '2026-11-10',
            'meal_service' => 'dinner',
            'branch_id' => null,
            'warehouse_id' => 1,
            'section' => 'hot_kitchen',
            'notes' => null,
            'lines' => [
                [
                    'kitchen_recipe_id' => $recipe->id,
                    'planned_quantity' => $qty,
                    'planned_unit_id' => $unitId,
                    'source' => 'occupancy',
                    'notes' => null,
                ],
            ],
        ];
    }

    private function draftPlan(array $overrides = []): KitchenProductionPlan
    {
        return $this->service()->create(array_merge($this->planData(), $overrides), User::findOrFail(2));
    }

    private function approvedPlan(array $overrides = []): KitchenProductionPlan
    {
        $plan = $this->draftPlan($overrides);
        $plan = $this->service()->submit($plan, User::findOrFail(2));
        $plan = $this->service()->approveStep($plan, User::findOrFail(3));
        $plan = $this->service()->approveStep($plan, User::findOrFail(1));

        return $plan;
    }

    private function assignFbManagerRole(): void
    {
        DB::table('roles')->insert(['id' => 1, 'name' => 'F&B Manager', 'guard_name' => 'web', 'created_by' => 1]);
        DB::table('model_has_roles')->insert(['role_id' => 1, 'model_type' => User::class, 'model_id' => 5]);
    }

    // --- Creation ------------------------------------------------------

    public function test_create_allocates_plan_number_and_stores_occupancy_snapshot(): void
    {
        $plan = $this->draftPlan();

        $this->assertMatchesRegularExpression('/^PL-\d{4}-0001$/', $plan->plan_number);
        $this->assertTrue($plan->isDraft());
        $this->assertSame(1, $plan->lines->count());
        $this->assertSame(20.0, (float) $plan->lines->first()->planned_quantity);

        // The snapshot names the reservation behind the numbers.
        $snapshot = $plan->occupancy_snapshot;
        $this->assertSame(1, $snapshot['total_reservations']);
        $this->assertSame(3, $snapshot['total_guests']);
        $this->assertSame(3, $snapshot['dinner_covers']);
        $this->assertSame(['RES-1'], array_column($snapshot['reservations'], 'reservation_no'));
        $this->assertSame(3, $plan->occupancyCovers());
    }

    public function test_create_requires_an_approved_recipe_and_at_least_one_line(): void
    {
        $draft = $this->makeRecipe('KR-2026-0009', 'Draft Dish', 15, 10, 3, 1, 3, [
            ['ingredient_product_id' => 12, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0],
        ], KitchenRecipe::STATUS_DRAFT);

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->create(array_merge($this->planData($draft), []), User::findOrFail(2));
    }

    public function test_create_without_lines_throws(): void
    {
        $data = $this->planData();
        $data['lines'] = [];

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->create($data, User::findOrFail(2));
    }

    // --- Approval chain ------------------------------------------------

    public function test_approval_chain_without_fb_manager_goes_head_chef_then_gm(): void
    {
        $plan = $this->draftPlan();
        $plan = $this->service()->submit($plan, User::findOrFail(2));

        $this->assertTrue($plan->isPendingApproval());
        $this->assertSame(KitchenProductionPlan::STEP_HEAD_CHEF, $plan->approval_step);

        $plan = $this->service()->approveStep($plan, User::findOrFail(3));
        $this->assertTrue($plan->isPendingApproval());
        $this->assertSame(KitchenProductionPlan::STEP_GENERAL_MANAGER, $plan->approval_step);
        $this->assertNotNull($plan->head_chef_approved_at);

        $plan = $this->service()->approveStep($plan, User::findOrFail(1));
        $this->assertTrue($plan->isApproved());
        $this->assertNull($plan->approval_step);
    }

    public function test_approval_chain_with_fb_manager_includes_the_fb_step(): void
    {
        $this->assignFbManagerRole();

        $plan = $this->draftPlan();
        $plan = $this->service()->submit($plan, User::findOrFail(2));

        $plan = $this->service()->approveStep($plan, User::findOrFail(3));
        $this->assertSame(KitchenProductionPlan::STEP_FB_MANAGER, $plan->approval_step);

        // A non-F&B user cannot approve the F&B step.
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->approveStep($plan, User::findOrFail(1));
    }

    public function test_approval_chain_with_fb_manager_full_path(): void
    {
        $this->assignFbManagerRole();

        $plan = $this->draftPlan();
        $plan = $this->service()->submit($plan, User::findOrFail(2));
        $plan = $this->service()->approveStep($plan, User::findOrFail(3));
        $plan = $this->service()->approveStep($plan, User::findOrFail(5));
        $this->assertSame(KitchenProductionPlan::STEP_GENERAL_MANAGER, $plan->approval_step);

        $plan = $this->service()->approveStep($plan, User::findOrFail(1));
        $this->assertTrue($plan->isApproved());
    }

    public function test_fb_step_skipped_when_role_exists_but_nobody_holds_it(): void
    {
        // The role exists but is assigned to nobody: the plan must never
        // strand waiting for an approver who does not exist.
        DB::table('roles')->insert(['id' => 1, 'name' => 'F&B Manager', 'guard_name' => 'web', 'created_by' => 1]);

        $this->assertFalse($this->service()->fbManagerRequired(1));

        $plan = $this->draftPlan();
        $plan = $this->service()->submit($plan, User::findOrFail(2));
        $plan = $this->service()->approveStep($plan, User::findOrFail(3));
        $this->assertSame(KitchenProductionPlan::STEP_GENERAL_MANAGER, $plan->approval_step);
    }

    public function test_submitter_cannot_approve_their_own_plan(): void
    {
        $plan = $this->draftPlan();
        $plan = $this->service()->submit($plan, User::findOrFail(2));

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->approveStep($plan, User::findOrFail(2));
    }

    public function test_one_user_cannot_approve_two_steps(): void
    {
        $plan = $this->draftPlan();
        $plan = $this->service()->submit($plan, User::findOrFail(2));
        $plan = $this->service()->approveStep($plan, User::findOrFail(3));

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->approveStep($plan, User::findOrFail(3));
    }

    // --- Rejection / return --------------------------------------------

    public function test_reject_returns_plan_to_draft_and_edit_resubmits(): void
    {
        $plan = $this->draftPlan();
        $plan = $this->service()->submit($plan, User::findOrFail(2));
        $plan = $this->service()->reject($plan, User::findOrFail(3), 'Too many burgers for 3 covers.');

        $this->assertTrue($plan->isRejected());
        $this->assertSame('Too many burgers for 3 covers.', $plan->rejection_note);

        // Editing a rejected plan returns it to draft and clears the trail.
        $plan = $this->service()->updateDraft($plan, $this->planData(), User::findOrFail(2));
        $this->assertTrue($plan->isDraft());
        $this->assertNull($plan->rejected_by);
        $this->assertNull($plan->rejection_note);

        $plan = $this->service()->submit($plan, User::findOrFail(2));
        $this->assertTrue($plan->isPendingApproval());
    }

    public function test_submitter_cannot_reject_their_own_plan(): void
    {
        $plan = $this->draftPlan();
        $plan = $this->service()->submit($plan, User::findOrFail(2));

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->reject($plan, User::findOrFail(2), 'No.');
    }

    // --- Requirements + shortages ---------------------------------------

    public function test_requirements_scale_recipes_and_report_no_shortage_when_covered(): void
    {
        $plan = $this->draftPlan();

        // 20 burgers need 2 kg of beef; the kitchen holds 5 kg.
        $requirements = $this->service()->requirements($plan);

        $this->assertCount(1, $requirements['lines']);
        $row = $requirements['lines'][0];
        $this->assertSame(12, $row['ingredient_id']);
        $this->assertSame(2.0, $row['needed']);
        $this->assertSame(5.0, $row['available']);
        $this->assertSame(0.0, $row['shortage']);
        $this->assertSame(1600.0, $requirements['total_cost']);
    }

    public function test_requirements_list_shortages_against_kitchen_stock(): void
    {
        $plan = $this->draftPlan();

        // Drop the kitchen beef to 1 kg: 20 burgers need 2 kg.
        DB::table('warehouse_products')
            ->where('warehouse_id', 1)->where('product_id', 12)
            ->update(['quantity' => 1]);

        $requirements = $this->service()->requirements($plan);
        $row = $requirements['lines'][0];

        $this->assertSame(2.0, $row['needed']);
        $this->assertSame(1.0, $row['available']);
        $this->assertSame(1.0, $row['shortage']);
    }

    public function test_requirements_aggregate_two_lines_of_the_same_recipe(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0002')->firstOrFail();
        $data = $this->planData($recipe, 20, 3);
        $data['lines'][] = [
            'kitchen_recipe_id' => $recipe->id,
            'planned_quantity' => 10,
            'planned_unit_id' => 3,
            'source' => 'special_order',
            'notes' => null,
        ];
        $plan = $this->service()->create($data, User::findOrFail(2));

        // 20 + 10 burgers need 3 kg of beef.
        $requirements = $this->service()->requirements($plan);
        $this->assertCount(1, $requirements['lines']);
        $this->assertSame(3.0, $requirements['lines'][0]['needed']);
    }

    // --- Plan -> orders -> complete --------------------------------------

    public function test_create_orders_from_plan_creates_one_draft_order_per_line(): void
    {
        $plan = $this->approvedPlan();

        $orders = $this->service()->createOrdersFromPlan($plan, User::findOrFail(2));

        $this->assertCount(1, $orders);
        $this->assertSame((int) $plan->id, (int) $orders[0]->kitchen_production_plan_id);
        $this->assertTrue($orders[0]->isDraft());
        $this->assertSame('2026-11-10', $orders[0]->production_date->format('Y-m-d'));
        $this->assertTrue($plan->fresh()->isInProduction());

        // A second conversion is impossible: the plan is no longer approved.
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->createOrdersFromPlan($plan->fresh(), User::findOrFail(2));
    }

    public function test_complete_blocked_while_orders_open_then_succeeds(): void
    {
        $plan = $this->approvedPlan();
        $orders = $this->service()->createOrdersFromPlan($plan, User::findOrFail(2));
        $order = $orders[0];

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->complete($plan->fresh(), User::findOrFail(2));
    }

    public function test_complete_succeeds_when_all_orders_finished(): void
    {
        $plan = $this->approvedPlan();
        $orders = $this->service()->createOrdersFromPlan($plan, User::findOrFail(2));
        $order = $orders[0];

        $orderService = $this->orderService();
        $order = $orderService->approve($order, User::findOrFail(3));
        $order = $orderService->start($order, User::findOrFail(2));
        $line = $order->lines()->firstOrFail();
        $order = $orderService->complete(
            $order,
            User::findOrFail(2),
            [(int) $line->id => 2.0],
            20,
            3,
            date('Y-m-d', strtotime('+5 days')),
        );
        $this->assertTrue($order->isCompleted());

        $plan = $this->service()->complete($plan->fresh(), User::findOrFail(2));
        $this->assertTrue($plan->isCompleted());
    }

    public function test_refresh_occupancy_recomputes_the_snapshot_while_draft(): void
    {
        $plan = $this->draftPlan();
        $this->assertSame(1, $plan->occupancy_snapshot['total_reservations']);

        DB::table('hotel_reservations')->insert([
            'id' => 2, 'reservation_no' => 'RES-2', 'branch_id' => null,
            'check_in_date' => '2026-11-09', 'check_out_date' => '2026-11-12',
            'adults' => 2, 'extra_adults' => 0, 'children' => 0, 'infants' => 0,
            'meal_plan' => 'full_board', 'status' => 'checked_in', 'created_by' => 1,
        ]);

        $plan = $this->service()->refreshOccupancy($plan);
        $this->assertSame(2, $plan->occupancy_snapshot['total_reservations']);
        $this->assertSame(5, $plan->occupancy_snapshot['total_guests']);
    }
}
