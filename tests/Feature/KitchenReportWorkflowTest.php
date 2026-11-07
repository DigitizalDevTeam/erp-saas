<?php

namespace Tests\Feature;

use App\Models\KitchenProductionBatch;
use App\Models\KitchenProductionOrder;
use App\Models\KitchenRecipe;
use App\Models\User;
use App\Services\KitchenProductionOrderService;
use App\Services\KitchenRecipeCostService;
use App\Services\KitchenReportService;
use App\Services\KitchenUnitConversionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Kitchen Production Phase 7 — reports + dashboard + hardening.
 *
 * Happy path: every report renders for a company user with real data
 * (a completed burger order, its batch, a posted loss, a requisition
 * and a POS consumption), the dashboard shows today at a glance, and
 * every report exports to CSV / Excel / PDF with the same data.
 * Also covers the permission gate, the cross-company guard, the
 * traceability reconciliation flag, and unknown report/format 404s.
 */
class KitchenReportWorkflowTest extends TestCase
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

        Schema::create('kitchen_cost_alerts', function ($table) {
            $table->increments('id');
            $table->string('alert_type', 30);
            $table->integer('product_id')->nullable();
            $table->integer('kitchen_recipe_id')->nullable();
            $table->integer('kitchen_production_order_id')->nullable();
            $table->decimal('food_cost_percent', 5, 2)->nullable();
            $table->string('band', 20)->nullable();
            $table->decimal('planned_food_cost_percent', 5, 2)->nullable();
            $table->string('planned_food_cost_band', 20)->nullable();
            $table->string('status', 20)->default('open');
            $table->integer('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('note')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('kitchen_cost_snapshots', function ($table) {
            $table->increments('id');
            $table->integer('kitchen_recipe_id');
            $table->integer('version')->default(1);
            $table->integer('kitchen_production_order_id')->nullable();
            $table->string('source', 30);
            $table->decimal('recipe_cost', 15, 2)->nullable();
            $table->decimal('cost_per_portion', 15, 2)->nullable();
            $table->decimal('selling_price_ex_vat', 15, 2)->nullable();
            $table->decimal('food_cost_percent', 5, 2)->nullable();
            $table->string('band', 20)->nullable();
            $table->date('snapshot_date')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('kitchen_losses', function ($table) {
            $table->id();
            $table->string('loss_number', 30);
            $table->string('category', 30);
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 12, 4);
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->decimal('total_cost', 15, 2);
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('destination_warehouse_id')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('status', 20)->default('recorded');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_note')->nullable();
            $table->unsignedBigInteger('posted_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->boolean('reuse_flag')->default(false);
            $table->text('food_safety_note')->nullable();
            $table->string('reuse_destination', 30)->nullable();
            $table->unsignedBigInteger('kitchen_production_order_id')->nullable();
            $table->unsignedBigInteger('pos_id')->nullable();
            $table->unsignedBigInteger('pos_product_id')->nullable();
            $table->unsignedBigInteger('kitchen_production_batch_id')->nullable();
            $table->unsignedBigInteger('warehouse_loss_id')->nullable();
            $table->boolean('stock_already_deducted')->default(false);
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('kitchen_requisitions', function ($table) {
            $table->id();
            $table->string('requisition_number', 30);
            $table->unsignedBigInteger('kitchen_production_plan_id')->nullable();
            $table->unsignedBigInteger('kitchen_production_order_id')->nullable();
            $table->unsignedBigInteger('source_warehouse_id');
            $table->unsignedBigInteger('kitchen_warehouse_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('status', 20)->default('requested');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('issued_by')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_note')->nullable();
            $table->text('notes')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('kitchen_requisition_lines', function ($table) {
            $table->id();
            $table->unsignedBigInteger('kitchen_requisition_id');
            $table->unsignedBigInteger('ingredient_product_id');
            $table->decimal('required_quantity', 12, 4);
            $table->decimal('available_quantity', 12, 4)->default(0);
            $table->decimal('shortage_quantity', 12, 4)->default(0);
            $table->decimal('issued_quantity', 12, 4)->default(0);
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('kitchen_production_plans', function ($table) {
            $table->id();
            $table->string('plan_number', 30);
            $table->date('plan_date');
            $table->string('meal_service', 20);
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->string('section', 30)->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('approval_step', 20)->nullable();
            $table->json('occupancy_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->unsignedBigInteger('head_chef_approved_by')->nullable();
            $table->timestamp('head_chef_approved_at')->nullable();
            $table->unsignedBigInteger('fb_manager_approved_by')->nullable();
            $table->timestamp('fb_manager_approved_at')->nullable();
            $table->unsignedBigInteger('gm_approved_by')->nullable();
            $table->timestamp('gm_approved_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_note')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('kitchen_pos_batch_consumptions', function ($table) {
            $table->increments('id');
            $table->integer('pos_product_id');
            $table->integer('kitchen_production_batch_id');
            $table->integer('product_id');
            $table->decimal('quantity', 12, 4);
            $table->decimal('quantity_restored', 12, 4)->default(0);
            $table->integer('warehouse_id');
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
        Permission::create(['name' => 'view kitchen reports', 'guard_name' => 'web']);

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
            ['id' => 12, 'name' => 'Beef Mince', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 800, 'unit_id' => 1, 'quantity' => 50, 'created_by' => 1],
            ['id' => 14, 'name' => 'Beef Burger', 'type' => 'product', 'sale_price' => 500, 'sale_price_type' => 'exclusive', 'purchase_price' => 0, 'unit_id' => 3, 'quantity' => 0, 'created_by' => 1],
            ['id' => 17, 'name' => 'Luxury Soup', 'type' => 'product', 'sale_price' => 100, 'sale_price_type' => 'exclusive', 'purchase_price' => 0, 'unit_id' => 3, 'quantity' => 0, 'created_by' => 1],
            ['id' => 20, 'name' => 'Other Burger', 'type' => 'product', 'sale_price' => 500, 'sale_price_type' => 'exclusive', 'purchase_price' => 0, 'unit_id' => 3, 'quantity' => 0, 'created_by' => 4],
        ]);
        DB::table('warehouses')->insert([
            ['id' => 1, 'name' => 'COZINHA', 'created_by' => 1],
            ['id' => 2, 'name' => 'OTHER KITCHEN', 'created_by' => 4],
        ]);
        DB::table('warehouse_products')->insert([
            ['warehouse_id' => 1, 'product_id' => 11, 'quantity' => 5, 'created_by' => 1],
            ['warehouse_id' => 1, 'product_id' => 12, 'quantity' => 50, 'created_by' => 1],
        ]);

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Chef', 'email' => 'chef@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Manager', 'email' => 'manager@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['view kitchen reports']);
        User::findOrFail(2)->givePermissionTo(['view kitchen reports']);
        User::findOrFail(3)->givePermissionTo(['view kitchen reports']);
        User::findOrFail(4)->givePermissionTo(['view kitchen reports']);

        // Approved recipes: burger (16% excellent), luxury soup (80% very bad).
        $this->makeRecipe('KR-2026-0001', 'Coconut Rice', 10, 3, 1, 150, 2, [
            ['ingredient_product_id' => 11, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0],
        ]);
        $burger = $this->makeRecipe('KR-2026-0002', 'Beef Burger', 14, 10, 3, 1, 3, [
            ['ingredient_product_id' => 12, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0],
        ]);
        $this->makeRecipe('KR-2026-0003', 'Luxury Soup', 17, 10, 3, 1, 3, [
            ['ingredient_product_id' => 12, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0],
        ]);

        // One completed burger order that blew the plan: planned 1 kg
        // mince (16% excellent), actual 3 kg (48% very bad). 10 portions
        // produced at 240.00 each, batch expires in 5 days.
        $service = $this->orderService();
        $order = $service->create([
            'kitchen_recipe_id' => $burger->id,
            'production_date' => date('Y-m-d'),
            'section' => 'hot_kitchen',
            'branch_id' => null,
            'warehouse_id' => 1,
            'planned_quantity' => 10,
            'planned_unit_id' => 3,
            'responsible_user_id' => 2,
            'notes' => null,
            'cost_centre_id' => null,
        ], User::findOrFail(1));
        $order = $service->approve($order, User::findOrFail(3));
        $order = $service->start($order, User::findOrFail(2));
        $line = $order->lines->firstOrFail();
        $order = $service->complete($order, User::findOrFail(2), [(int) $line->id => 3.0], 10, 3, date('Y-m-d', strtotime('+5 days')));

        $batch = $order->batch()->firstOrFail();

        // 5 portions sold through the POS, 2 portions lost (posted) — so
        // produced 10 - sold 5 - lost 2 = 3 remaining. The batch row is
        // set to 3 to match, so the traceability check balances.
        DB::table('kitchen_pos_batch_consumptions')->insert([
            'pos_product_id' => 9001, 'kitchen_production_batch_id' => $batch->id, 'product_id' => 14,
            'quantity' => 5, 'quantity_restored' => 0, 'warehouse_id' => 1, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $batch->update(['quantity_remaining' => 3]);

        DB::table('kitchen_losses')->insert([
            'loss_number' => 'KL-2026-0001', 'category' => 'burnt_spoiled', 'product_id' => 14,
            'quantity' => 2, 'unit_id' => 3, 'unit_cost' => 240, 'total_cost' => 480,
            'warehouse_id' => 1, 'recorded_by' => 2, 'status' => 'posted',
            'approved_by' => 3, 'approved_at' => now(), 'posted_by' => 3, 'posted_at' => now(),
            'kitchen_production_order_id' => $order->id, 'kitchen_production_batch_id' => $batch->id,
            'stock_already_deducted' => false, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('kitchen_losses')->insert([
            'loss_number' => 'KL-2026-0002', 'category' => 'staff_meal', 'product_id' => 14,
            'quantity' => 1, 'unit_id' => 3, 'unit_cost' => 240, 'total_cost' => 240,
            'warehouse_id' => 1, 'recorded_by' => 2, 'status' => 'recorded',
            'kitchen_production_order_id' => $order->id, 'kitchen_production_batch_id' => $batch->id,
            'stock_already_deducted' => false, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $requisitionId = DB::table('kitchen_requisitions')->insertGetId([
            'requisition_number' => 'RQ-2026-0001', 'source_warehouse_id' => 1, 'kitchen_warehouse_id' => 1,
            'status' => 'issued', 'requested_by' => 2, 'approved_by' => 3, 'approved_at' => now(),
            'issued_by' => 3, 'issued_at' => now(), 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('kitchen_requisition_lines')->insert([
            'kitchen_requisition_id' => $requisitionId, 'ingredient_product_id' => 12,
            'required_quantity' => 5, 'available_quantity' => 2, 'shortage_quantity' => 3,
            'issued_quantity' => 3, 'unit_id' => 1, 'unit_cost' => 800, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('kitchen_production_plans')->insert([
            'plan_number' => 'PL-2026-0001', 'plan_date' => date('Y-m-d'), 'meal_service' => 'breakfast',
            'warehouse_id' => 1, 'status' => 'pending_approval', 'submitted_by' => 2, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Second company's own rows — they must never appear in
        // company 1's reports, and vice versa.
        $otherRecipe = KitchenRecipe::create([
            'code' => 'KR-2026-0004', 'name' => 'Other Burger', 'product_id' => 20,
            'version' => 1, 'status' => KitchenRecipe::STATUS_APPROVED,
            'expected_yield_quantity' => 10, 'expected_yield_unit_id' => 3,
            'standard_portion_quantity' => 1, 'standard_portion_unit_id' => 3,
            'loss_percent' => 0, 'created_by' => 4,
        ]);
        $otherOrder = KitchenProductionOrder::create([
            'order_number' => 'KP-2026-9001', 'kitchen_recipe_id' => $otherRecipe->id,
            'recipe_version' => 1, 'production_date' => date('Y-m-d'), 'section' => 'hot_kitchen',
            'warehouse_id' => 2, 'planned_quantity' => 5, 'planned_unit_id' => 3,
            'actual_yield_quantity' => 5, 'actual_yield_unit_id' => 3, 'status' => KitchenProductionOrder::STATUS_COMPLETED,
            'planned_cost' => 500, 'actual_cost' => 500, 'cost_per_portion' => 100,
            'food_cost_percent' => 20, 'food_cost_band' => 'excellent',
            'completed_at' => now(), 'created_by' => 4,
        ]);
        KitchenProductionBatch::create([
            'batch_number' => 'KP-2026-9001-B1', 'kitchen_production_order_id' => $otherOrder->id,
            'product_id' => 20, 'quantity_produced' => 5, 'quantity_remaining' => 5,
            'unit_id' => 3, 'warehouse_id' => 2, 'expiry_date' => date('Y-m-d', strtotime('+5 days')),
            'unit_cost' => 100, 'total_cost' => 500, 'created_by' => 4,
        ]);
        DB::table('kitchen_losses')->insert([
            'loss_number' => 'KL-2026-9001', 'category' => 'burnt_spoiled', 'product_id' => 20,
            'quantity' => 1, 'unit_id' => 3, 'unit_cost' => 100, 'total_cost' => 100,
            'warehouse_id' => 2, 'recorded_by' => 4, 'status' => 'posted',
            'approved_by' => 4, 'approved_at' => now(), 'posted_by' => 4, 'posted_at' => now(),
            'stock_already_deducted' => false, 'created_by' => 4,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['role_has_permissions', 'model_has_roles', 'model_has_permissions', 'permissions', 'roles', 'plans', 'email_templates', 'languages', 'ch_messages', 'branches', 'branch_user', 'settings', 'users', 'kitchen_pos_batch_consumptions', 'kitchen_production_plans', 'kitchen_requisition_lines', 'kitchen_requisitions', 'kitchen_losses', 'kitchen_cost_snapshots', 'kitchen_cost_alerts', 'kitchen_production_order_lot_usages', 'kitchen_production_batches', 'kitchen_production_order_lines', 'kitchen_production_orders', 'product_recipe_alternatives', 'product_recipes', 'kitchen_unit_conversions', 'kitchen_recipes', 'stock_reports', 'warehouse_products', 'warehouses', 'product_expiry_lots', 'product_services', 'product_service_units'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function orderService(): KitchenProductionOrderService
    {
        $conversions = new KitchenUnitConversionService();

        return new KitchenProductionOrderService($conversions, new KitchenRecipeCostService($conversions));
    }

    private function makeRecipe(string $code, string $name, int $productId, float $yieldQty, int $yieldUnit, float $portionQty, int $portionUnit, array $lines): KitchenRecipe
    {
        $recipe = KitchenRecipe::create([
            'code' => $code,
            'name' => $name,
            'product_id' => $productId,
            'version' => 1,
            'status' => KitchenRecipe::STATUS_APPROVED,
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

    private function orderNumber(): string
    {
        return KitchenProductionOrder::where('created_by', 1)->orderByDesc('id')->firstOrFail()->order_number;
    }

    private function batchNumber(): string
    {
        return KitchenProductionBatch::where('created_by', 1)->orderByDesc('id')->firstOrFail()->batch_number;
    }

    public function test_reports_hub_renders(): void
    {
        $response = $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.reports.index'));

        $response->assertOk();
        $response->assertSee('Production summary');
        $response->assertSee('Ingredient usage');
        $response->assertSee('Losses');
        $response->assertSee('Food cost');
        $response->assertSee('Stock valuation');
        $response->assertSee('Waste');
        $response->assertSee('Requisitions');
        $response->assertSee('Traceability');
        $response->assertSee('Kitchen Dashboard');
    }

    public function test_dashboard_renders_today(): void
    {
        $response = $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.reports.dashboard'));

        $response->assertOk();
        $response->assertSee($this->orderNumber());
        $response->assertSee('PL-2026-0001');
        $response->assertSee($this->batchNumber());
        $response->assertSee('Beef Burger');
        $response->assertSee('Luxury Soup');
    }

    public function test_production_report_renders(): void
    {
        $response = $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.reports.show', 'production'));

        $response->assertOk();
        $response->assertSee($this->orderNumber());
        $response->assertSee('Beef Burger');
        $response->assertSee('48.00%');
        $response->assertSee('Very bad');
        $response->assertSee('2,400.00');
    }

    public function test_usage_report_renders(): void
    {
        $response = $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.reports.show', 'usage'));

        $response->assertOk();
        $response->assertSee('Beef Mince');
        $response->assertSee('1.00');
        $response->assertSee('3.00');
        $response->assertSee('2,400.00');
    }

    public function test_losses_report_renders(): void
    {
        $response = $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.reports.show', 'losses'));

        $response->assertOk();
        $response->assertSee('KL-2026-0001');
        $response->assertSee('Burnt / spoiled');
        $response->assertSee('480.00');
        $response->assertSee('Staff meal');
    }

    public function test_food_cost_report_renders(): void
    {
        $response = $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.reports.show', 'food-cost'));

        $response->assertOk();
        $response->assertSee('Beef Burger');
        $response->assertSee('16.00%');
        $response->assertSee('48.00%');
        $response->assertSee('Luxury Soup');
        $response->assertSee('80.00%');
    }

    public function test_stock_report_values_batches_and_raw_ingredients(): void
    {
        $response = $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.reports.show', 'stock'));

        $response->assertOk();
        $response->assertSee($this->batchNumber());
        // Batch: 3 portions left at 240.00 = 720.00.
        $response->assertSee('720.00');
        // Raw ingredients in COZINHA: rice 5 kg at 100 = 500.00,
        // mince 47 kg at 800 = 37,600.00 (3 kg were deducted).
        $response->assertSee('Uncooked Rice');
        $response->assertSee('500.00');
        $response->assertSee('37,600.00');
        // Grand total: 720 + 500 + 37,600 = 38,820.00. The produced
        // burger must be valued once, through its batch — so its name
        // appears exactly once on the page (the batch row), never as a
        // raw ingredient row.
        $response->assertSee('38,820.00');
        $this->assertSame(1, substr_count($response->getContent(), 'Beef Burger'));
    }

    public function test_waste_report_renders(): void
    {
        $response = $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.reports.show', 'waste'));

        $response->assertOk();
        $response->assertSee('Beef Burger');
        // Produced 10, sold 5, lost 2 -> waste 20%.
        $response->assertSee('20.00%');
        $response->assertSee('480.00');
    }

    public function test_requisitions_report_renders(): void
    {
        $response = $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.reports.show', 'requisitions'));

        $response->assertOk();
        $response->assertSee('RQ-2026-0001');
        $response->assertSee('Beef Mince');
        $response->assertSee('5.00');
        $response->assertSee('3.00');
    }

    public function test_traceability_report_balances_and_flags_tampering(): void
    {
        $batch = KitchenProductionBatch::where('created_by', 1)->firstOrFail();

        $response = $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.reports.show', 'traceability'));

        $response->assertOk();
        $response->assertSee($batch->batch_number);
        $response->assertSee('10.00');
        $response->assertSee('5.00');
        $response->assertSee('2.00');
        $response->assertSee('3.00');
        $response->assertSee('Yes');

        // Tamper with the remaining quantity: 10 - 5 - 2 = 3, not 4.
        $batch->update(['quantity_remaining' => 4]);

        $tampered = $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.reports.show', 'traceability'));

        $tampered->assertOk();
        $tampered->assertSee('No');
        $tampered->assertSee('-1.00');
    }

    public function test_traceability_detail_shows_lots_and_pos_sales(): void
    {
        $batch = KitchenProductionBatch::where('created_by', 1)->firstOrFail();

        $response = $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.reports.show', ['report' => 'traceability', 'batch_id' => $batch->id]));

        $response->assertOk();
        $response->assertSee('Batch detail');
        $response->assertSee('POS sales from this batch');
        $response->assertSee('#9001');
        $response->assertSee('5.00');
    }

    public function test_every_report_exports_csv(): void
    {
        foreach (array_keys(KitchenReportService::REPORTS) as $key) {
            $response = $this->actingAs(User::findOrFail(2))
                ->get(route('kitchen.reports.export', ['report' => $key, 'format' => 'csv']));

            $response->assertOk();
            $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
            $this->assertStringContainsString('kitchen-'.$key, $response->headers->get('content-disposition'));
        }

        // The production CSV carries the order row.
        $csv = $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.reports.export', ['report' => 'production', 'format' => 'csv']));
        $this->assertStringContainsString($this->orderNumber(), $csv->streamedContent());
        $this->assertStringContainsString('Beef Burger', $csv->streamedContent());

        // The losses CSV carries the loss row and its category label.
        $lossCsv = $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.reports.export', ['report' => 'losses', 'format' => 'csv']));
        $this->assertStringContainsString('KL-2026-0001', $lossCsv->streamedContent());
        $this->assertStringContainsString('Burnt / spoiled', $lossCsv->streamedContent());
    }

    public function test_excel_and_pdf_exports(): void
    {
        $xlsx = $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.reports.export', ['report' => 'production', 'format' => 'xlsx']));

        $xlsx->assertOk();
        $this->assertStringContainsString('spreadsheetml', $xlsx->headers->get('content-type'));

        $pdf = $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.reports.export', ['report' => 'production', 'format' => 'pdf']));

        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', $pdf->headers->get('content-type'));
    }

    public function test_permission_gate_blocks_without_view_kitchen_reports(): void
    {
        $this->actingAs(User::findOrFail(6))
            ->get(route('kitchen.reports.index'))
            ->assertRedirect()
            ->assertSessionHas('error', 'Permission denied.');

        $this->actingAs(User::findOrFail(6))
            ->get(route('kitchen.reports.show', 'production'))
            ->assertRedirect()
            ->assertSessionHas('error', 'Permission denied.');

        $this->actingAs(User::findOrFail(6))
            ->get(route('kitchen.reports.dashboard'))
            ->assertRedirect()
            ->assertSessionHas('error', 'Permission denied.');

        $this->actingAs(User::findOrFail(6))
            ->get(route('kitchen.reports.export', ['report' => 'production', 'format' => 'csv']))
            ->assertRedirect()
            ->assertSessionHas('error', 'Permission denied.');
    }

    public function test_other_company_sees_only_its_own_rows(): void
    {
        $response = $this->actingAs(User::findOrFail(4))
            ->get(route('kitchen.reports.show', 'production'));

        $response->assertOk();
        $response->assertSee('KP-2026-9001');
        $response->assertDontSee($this->orderNumber());
        $response->assertDontSee('Beef Burger');

        $losses = $this->actingAs(User::findOrFail(4))
            ->get(route('kitchen.reports.show', 'losses'));

        $losses->assertOk();
        $losses->assertSee('KL-2026-9001');
        $losses->assertDontSee('KL-2026-0001');

        $stock = $this->actingAs(User::findOrFail(4))
            ->get(route('kitchen.reports.show', 'stock'));

        $stock->assertOk();
        $stock->assertSee('KP-2026-9001-B1');
        $stock->assertDontSee($this->batchNumber());

        $dashboard = $this->actingAs(User::findOrFail(4))
            ->get(route('kitchen.reports.dashboard'));

        $dashboard->assertOk();
        $dashboard->assertSee('KP-2026-9001');
        $dashboard->assertDontSee($this->orderNumber());
    }

    public function test_unknown_report_and_format_return_404(): void
    {
        $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.reports.show', 'nope'))
            ->assertNotFound();

        $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.reports.export', ['report' => 'production', 'format' => 'docx']))
            ->assertNotFound();

        $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen.reports.export', ['report' => 'nope', 'format' => 'csv']))
            ->assertNotFound();
    }
}
