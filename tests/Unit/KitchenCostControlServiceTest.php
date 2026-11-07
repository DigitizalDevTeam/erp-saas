<?php

namespace Tests\Unit;

use App\Models\KitchenCostAlert;
use App\Models\KitchenCostSnapshot;
use App\Models\KitchenProductionOrder;
use App\Models\KitchenRecipe;
use App\Models\User;
use App\Services\KitchenCostControlService;
use App\Services\KitchenProductionOrderService;
use App\Services\KitchenRecipeApprovalService;
use App\Services\KitchenRecipeCostService;
use App\Services\KitchenUnitConversionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Kitchen Production Phase 6 — cost control + food-cost bands.
 *
 * Bands (Danilo, decided 2026-11-06): below 25% and 25–28% excellent,
 * 29–32% good, 33–36% good-, 37–41% bad, over 41% very bad.
 * Expected vs actual cost per dish, variance vs plan, alerts when a
 * band is breached, and cost snapshots (recipe approval + order
 * completion) so the trend survives later price changes.
 */
class KitchenCostControlServiceTest extends TestCase
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
            ['id' => 15, 'name' => 'Rice Pudding', 'type' => 'product', 'sale_price' => 60, 'sale_price_type' => 'exclusive', 'purchase_price' => 0, 'unit_id' => 1, 'quantity' => 0, 'created_by' => 1],
            ['id' => 16, 'name' => 'Beef Stew', 'type' => 'product', 'sale_price' => 400, 'sale_price_type' => 'exclusive', 'purchase_price' => 0, 'unit_id' => 3, 'quantity' => 0, 'created_by' => 1],
            ['id' => 17, 'name' => 'Luxury Soup', 'type' => 'product', 'sale_price' => 100, 'sale_price_type' => 'exclusive', 'purchase_price' => 0, 'unit_id' => 3, 'quantity' => 0, 'created_by' => 1],
            ['id' => 18, 'name' => 'Free Soup', 'type' => 'product', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 0, 'unit_id' => 3, 'quantity' => 0, 'created_by' => 1],
        ]);
        DB::table('warehouses')->insert([
            ['id' => 1, 'name' => 'COZINHA', 'created_by' => 1],
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
        ]);

        // Approved coconut rice recipe: 3 kg yield, 150 g portions,
        // 1 kg uncooked rice per batch. Cost/portion 5, sale 50 -> 10%.
        $this->makeRecipe('KR-2026-0001', 'Coconut Rice', 10, 3, 1, 150, 2, [
            ['ingredient_product_id' => 11, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0],
        ]);
        // Approved burger recipe: 10 portions yield, 1 portion each,
        // 1 kg beef mince per batch (100 g per burger).
        // Cost/portion 80, sale 500 -> 16%.
        $this->makeRecipe('KR-2026-0002', 'Beef Burger', 14, 10, 3, 1, 3, [
            ['ingredient_product_id' => 12, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0],
        ]);
        // Approved luxury soup: 10 portions, 1 kg beef mince per batch.
        // Cost/portion 80, sale 100 -> 80% = very bad.
        $this->makeRecipe('KR-2026-0003', 'Luxury Soup', 17, 10, 3, 1, 3, [
            ['ingredient_product_id' => 12, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['kitchen_cost_snapshots', 'kitchen_cost_alerts', 'users', 'branches', 'warehouses', 'product_expiry_lots', 'stock_reports', 'warehouse_products', 'kitchen_production_order_lot_usages', 'kitchen_production_batches', 'kitchen_production_order_lines', 'kitchen_production_orders', 'product_recipe_alternatives', 'product_recipes', 'kitchen_unit_conversions', 'kitchen_recipes', 'product_services', 'product_service_units'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function service(): KitchenCostControlService
    {
        $conversions = new KitchenUnitConversionService();

        return new KitchenCostControlService($conversions, new KitchenRecipeCostService($conversions));
    }

    private function orderService(): KitchenProductionOrderService
    {
        $conversions = new KitchenUnitConversionService();

        return new KitchenProductionOrderService($conversions, new KitchenRecipeCostService($conversions));
    }

    private function approvalService(): KitchenRecipeApprovalService
    {
        return new KitchenRecipeApprovalService(new KitchenUnitConversionService());
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

    private function orderData(KitchenRecipe $recipe, float $plannedQty = 3, ?int $plannedUnitId = 1): array
    {
        return [
            'kitchen_recipe_id' => $recipe->id,
            'production_date' => date('Y-m-d'),
            'section' => 'hot_kitchen',
            'branch_id' => null,
            'warehouse_id' => 1,
            'planned_quantity' => $plannedQty,
            'planned_unit_id' => $plannedUnitId,
            'responsible_user_id' => 2,
            'notes' => null,
            'cost_centre_id' => null,
        ];
    }

    private function inPreparationOrder(KitchenRecipe $recipe, float $plannedQty = 3, ?int $plannedUnitId = 1): KitchenProductionOrder
    {
        $order = $this->orderService()->create($this->orderData($recipe, $plannedQty, $plannedUnitId), User::findOrFail(1));
        $order = $this->orderService()->approve($order, User::findOrFail(3));

        return $this->orderService()->start($order, User::findOrFail(2));
    }

    private function completeOrder(KitchenProductionOrder $order, array $actuals, float $yieldQty, ?int $yieldUnitId): KitchenProductionOrder
    {
        return $this->orderService()->complete($order, User::findOrFail(2), $actuals, $yieldQty, $yieldUnitId, date('Y-m-d', strtotime('+5 days')));
    }

    public function test_band_boundaries_match_danilo_bands(): void
    {
        $band = KitchenRecipeCostService::bandForFoodCostPercent(...);

        // Below 25% is excellent (D4 — a very low food cost is good).
        $this->assertSame('excellent', $band(0.0));
        $this->assertSame('excellent', $band(24.9));
        $this->assertSame('excellent', $band(25.0));
        $this->assertSame('excellent', $band(28.0));
        // The edge between excellent and good.
        $this->assertSame('good', $band(28.1));
        $this->assertSame('good', $band(29.0));
        $this->assertSame('good', $band(32.0));
        // The edge between good and good-.
        $this->assertSame('good-', $band(32.1));
        $this->assertSame('good-', $band(33.0));
        $this->assertSame('good-', $band(36.0));
        // The edge between good- and bad.
        $this->assertSame('bad', $band(36.1));
        $this->assertSame('bad', $band(37.0));
        $this->assertSame('bad', $band(41.0));
        // The edge between bad and very bad.
        $this->assertSame('very_bad', $band(41.1));
        $this->assertSame('very_bad', $band(80.0));
        $this->assertNull($band(null));

        // Rank: the higher the worse; only bad / very bad fire alerts.
        $this->assertSame(0, KitchenCostControlService::bandRank('excellent'));
        $this->assertSame(1, KitchenCostControlService::bandRank('good'));
        $this->assertSame(2, KitchenCostControlService::bandRank('good-'));
        $this->assertSame(3, KitchenCostControlService::bandRank('bad'));
        $this->assertSame(4, KitchenCostControlService::bandRank('very_bad'));
        $this->assertNull(KitchenCostControlService::bandRank(null));
        $this->assertFalse(KitchenCostControlService::bandIsAlert('excellent'));
        $this->assertFalse(KitchenCostControlService::bandIsAlert('good-'));
        $this->assertTrue(KitchenCostControlService::bandIsAlert('bad'));
        $this->assertTrue(KitchenCostControlService::bandIsAlert('very_bad'));
    }

    public function test_planned_food_cost_is_snapshotted_on_order_creation(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0002')->firstOrFail();

        // 10 burgers: planned cost 800, 10 portions, 80 per portion,
        // sale 500 -> 16% = excellent.
        $order = $this->orderService()->create($this->orderData($recipe, 10, 3), User::findOrFail(1));

        $this->assertSame(800.0, (float) $order->planned_cost);
        $this->assertSame(16.0, (float) $order->planned_food_cost_percent);
        $this->assertSame('excellent', $order->planned_food_cost_band);

        // Coconut rice: 3 kg planned, 100 planned cost, 20 portions,
        // 5 per portion, sale 50 -> 10% = excellent.
        $rice = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $riceOrder = $this->orderService()->create($this->orderData($rice, 3, 1), User::findOrFail(1));
        $this->assertSame(10.0, (float) $riceOrder->planned_food_cost_percent);
        $this->assertSame('excellent', $riceOrder->planned_food_cost_band);
    }

    public function test_planned_food_cost_is_null_without_a_selling_price(): void
    {
        // Free Soup has no selling price, so no food-cost % exists.
        $recipe = $this->makeRecipe('KR-2026-0004', 'Free Soup', 18, 10, 3, 1, 3, [
            ['ingredient_product_id' => 11, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0],
        ]);

        $order = $this->orderService()->create($this->orderData($recipe, 10, 3), User::findOrFail(1));

        $this->assertNull($order->planned_food_cost_percent);
        $this->assertNull($order->planned_food_cost_band);
    }

    public function test_dish_costs_expected_vs_no_actual_yet(): void
    {
        $rows = $this->service()->dishCosts(1);
        $this->assertCount(3, $rows);

        $byDish = [];
        foreach ($rows as $row) {
            $byDish[$row['product']->name] = $row;
        }

        $rice = $byDish['Coconut Rice'];
        $this->assertSame(5.0, $rice['expected']['cost_per_portion']);
        $this->assertSame(10.0, $rice['expected']['food_cost_percent']);
        $this->assertSame('excellent', $rice['expected']['band']);
        $this->assertNull($rice['latest_order']);
        $this->assertNull($rice['actual_food_cost_percent']);
        $this->assertFalse($rice['alert']);

        $burger = $byDish['Beef Burger'];
        $this->assertSame(80.0, $burger['expected']['cost_per_portion']);
        $this->assertSame(16.0, $burger['expected']['food_cost_percent']);
        $this->assertSame('excellent', $burger['expected']['band']);
        $this->assertFalse($burger['alert']);

        // Luxury Soup costs 80 per portion against a 100 sale price:
        // 80% = very bad, and the dish is flagged.
        $soup = $byDish['Luxury Soup'];
        $this->assertSame(80.0, $soup['expected']['cost_per_portion']);
        $this->assertSame(80.0, $soup['expected']['food_cost_percent']);
        $this->assertSame('very_bad', $soup['expected']['band']);
        $this->assertTrue($soup['alert']);
    }

    public function test_dish_costs_actual_from_latest_completed_order(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $order = $this->inPreparationOrder($recipe, 3, 1);
        $line = $order->lines->firstOrFail();
        $this->completeOrder($order, [(int) $line->id => 1.0], 3, 1);

        $rows = $this->service()->dishCosts(1);
        $byDish = [];
        foreach ($rows as $row) {
            $byDish[$row['product']->name] = $row;
        }

        $rice = $byDish['Coconut Rice'];
        $this->assertNotNull($rice['latest_order']);
        $this->assertSame(5.0, $rice['actual_cost_per_portion']);
        $this->assertSame(10.0, $rice['actual_food_cost_percent']);
        $this->assertSame('excellent', $rice['actual_band']);
        $this->assertSame(1, $rice['completed_orders_in_period']);
        $this->assertSame(10.0, $rice['average_food_cost_percent']);
    }

    public function test_dish_costs_period_filter_excludes_old_orders(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $order = $this->inPreparationOrder($recipe, 3, 1);
        $line = $order->lines->firstOrFail();
        $this->completeOrder($order, [(int) $line->id => 1.0], 3, 1);

        // The order completed today: a period from last year sees no
        // actual cost at all.
        $rows = $this->service()->dishCosts(1, ['from' => '2025-01-01', 'to' => '2025-12-31']);
        $byDish = [];
        foreach ($rows as $row) {
            $byDish[$row['product']->name] = $row;
        }

        $this->assertNull($byDish['Coconut Rice']['latest_order']);
        $this->assertSame(0, $byDish['Coconut Rice']['completed_orders_in_period']);
        $this->assertNull($byDish['Coconut Rice']['average_food_cost_percent']);
        // The expected cost (recipe at today's prices) is unaffected
        // by the period filter.
        $this->assertSame(10.0, $byDish['Coconut Rice']['expected']['food_cost_percent']);
    }

    public function test_order_variance_flags_worse_than_planned(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0002')->firstOrFail();
        $order = $this->inPreparationOrder($recipe, 10, 3);
        $line = $order->lines->firstOrFail();

        // Planned: 1 kg mince = 800. Actual: 3 kg mince = 2400, same
        // 10 portions -> 240 per portion, sale 500 -> 48% = very bad.
        $completed = $this->completeOrder($order, [(int) $line->id => 3.0], 10, 3);

        $variance = $this->service()->orderVariance($completed);

        $this->assertSame(800.0, $variance['planned_cost']);
        $this->assertSame(2400.0, $variance['actual_cost']);
        $this->assertSame(1600.0, $variance['variance']);
        $this->assertSame(200.0, $variance['variance_percent']);
        $this->assertSame(16.0, $variance['planned_food_cost_percent']);
        $this->assertSame('excellent', $variance['planned_band']);
        $this->assertSame(48.0, $variance['actual_food_cost_percent']);
        $this->assertSame('very_bad', $variance['actual_band']);
        $this->assertTrue($variance['worse_than_planned']);
        $this->assertTrue($variance['alert']);
    }

    public function test_order_variance_no_flag_when_actual_matches_plan(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $order = $this->inPreparationOrder($recipe, 3, 1);
        $line = $order->lines->firstOrFail();
        $completed = $this->completeOrder($order, [(int) $line->id => 1.0], 3, 1);

        $variance = $this->service()->orderVariance($completed);

        $this->assertSame(100.0, $variance['planned_cost']);
        $this->assertSame(100.0, $variance['actual_cost']);
        $this->assertSame(0.0, $variance['variance']);
        $this->assertSame(0.0, $variance['variance_percent']);
        $this->assertSame('excellent', $variance['planned_band']);
        $this->assertSame('excellent', $variance['actual_band']);
        $this->assertFalse($variance['worse_than_planned']);
        $this->assertFalse($variance['alert']);
    }

    public function test_order_variance_without_planned_band_never_worse(): void
    {
        // An order from before Phase 6 has no planned band: it can
        // still alert on a bad actual band, but never "worse than
        // planned" — there is nothing to compare against.
        $recipe = KitchenRecipe::where('code', 'KR-2026-0002')->firstOrFail();
        $order = $this->inPreparationOrder($recipe, 10, 3);
        $line = $order->lines->firstOrFail();
        $completed = $this->completeOrder($order, [(int) $line->id => 3.0], 10, 3);

        KitchenProductionOrder::where('id', $completed->id)->update([
            'planned_food_cost_percent' => null,
            'planned_food_cost_band' => null,
        ]);

        $variance = $this->service()->orderVariance($completed->fresh());

        $this->assertFalse($variance['worse_than_planned']);
        $this->assertTrue($variance['alert']);
    }

    public function test_completion_fires_alerts_and_does_not_duplicate(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0002')->firstOrFail();
        $order = $this->inPreparationOrder($recipe, 10, 3);
        $line = $order->lines->firstOrFail();

        // Completing the order fires both alerts (very bad band, and
        // worse than the excellent plan) via the completion hook.
        $completed = $this->completeOrder($order, [(int) $line->id => 3.0], 10, 3);

        $alerts = KitchenCostAlert::where('kitchen_production_order_id', $completed->id)->orderBy('alert_type')->get();
        $this->assertCount(2, $alerts);
        $this->assertSame(KitchenCostAlert::TYPE_BAND_VERY_BAD, $alerts[0]->alert_type);
        $this->assertSame(KitchenCostAlert::TYPE_WORSE_THAN_PLANNED, $alerts[1]->alert_type);
        $this->assertSame(48.0, (float) $alerts[0]->food_cost_percent);
        $this->assertSame('very_bad', $alerts[0]->band);
        $this->assertSame('excellent', $alerts[1]->planned_food_cost_band);
        $this->assertTrue($alerts[0]->isOpen());

        // Checking the same order again creates nothing new.
        $created = $this->service()->checkOrder($completed->fresh());
        $this->assertCount(0, $created);
        $this->assertSame(2, KitchenCostAlert::where('kitchen_production_order_id', $completed->id)->count());
    }

    public function test_completion_with_good_band_fires_no_alert(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $order = $this->inPreparationOrder($recipe, 3, 1);
        $line = $order->lines->firstOrFail();
        $completed = $this->completeOrder($order, [(int) $line->id => 1.0], 3, 1);

        $this->assertSame(0, KitchenCostAlert::where('kitchen_production_order_id', $completed->id)->count());
    }

    public function test_acknowledge_alert_and_reject_second_acknowledge(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0002')->firstOrFail();
        $order = $this->inPreparationOrder($recipe, 10, 3);
        $line = $order->lines->firstOrFail();
        $completed = $this->completeOrder($order, [(int) $line->id => 3.0], 10, 3);

        $alert = KitchenCostAlert::where('kitchen_production_order_id', $completed->id)
            ->where('alert_type', KitchenCostAlert::TYPE_BAND_VERY_BAD)
            ->firstOrFail();

        $acknowledged = $this->service()->acknowledgeAlert($alert, User::findOrFail(3));
        $this->assertTrue($acknowledged->isAcknowledged());
        $this->assertSame(3, (int) $acknowledged->acknowledged_by);
        $this->assertNotNull($acknowledged->acknowledged_at);

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->acknowledgeAlert($acknowledged, User::findOrFail(3));
    }

    public function test_acknowledge_alert_other_company_blocked(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0002')->firstOrFail();
        $order = $this->inPreparationOrder($recipe, 10, 3);
        $line = $order->lines->firstOrFail();
        $completed = $this->completeOrder($order, [(int) $line->id => 3.0], 10, 3);

        $alert = KitchenCostAlert::where('kitchen_production_order_id', $completed->id)->firstOrFail();

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->acknowledgeAlert($alert, User::findOrFail(4));
    }

    public function test_refresh_dish_alerts_raises_very_bad_dish_once(): void
    {
        // Luxury Soup's expected band is very bad at today's prices.
        $created = $this->service()->refreshDishAlerts(1);
        $this->assertCount(1, $created);
        $this->assertSame(KitchenCostAlert::TYPE_DISH_BAND_VERY_BAD, $created[0]->alert_type);
        $this->assertSame(17, (int) $created[0]->product_id);
        $this->assertNull($created[0]->kitchen_production_order_id);
        $this->assertSame(80.0, (float) $created[0]->food_cost_percent);

        // A second refresh raises nothing new while it stays open.
        $this->assertCount(0, $this->service()->refreshDishAlerts(1));
    }

    public function test_recipe_approval_writes_a_cost_snapshot(): void
    {
        $recipe = $this->makeRecipe('KR-2026-0004', 'Rice Pudding', 15, 3, 1, 150, 2, [
            ['ingredient_product_id' => 11, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0],
        ], KitchenRecipe::STATUS_DRAFT);

        $approval = $this->approvalService();
        $approval->submit($recipe, User::findOrFail(2));
        $approved = $approval->approve($recipe->fresh(), User::findOrFail(3));

        $this->assertSame(KitchenRecipe::STATUS_APPROVED, $approved->status);

        $snapshot = KitchenCostSnapshot::where('kitchen_recipe_id', $recipe->id)
            ->where('source', KitchenCostSnapshot::SOURCE_RECIPE_APPROVED)
            ->firstOrFail();
        $this->assertSame(1, (int) $snapshot->version);
        // 1 kg rice @ 100 = 100; 20 portions -> 5 per portion; sale 60
        // -> 8.33% = excellent.
        $this->assertSame(100.0, (float) $snapshot->recipe_cost);
        $this->assertSame(5.0, (float) $snapshot->cost_per_portion);
        $this->assertSame(8.33, (float) $snapshot->food_cost_percent);
        $this->assertSame('excellent', $snapshot->band);

        // Recording the same snapshot again returns the same row.
        $again = $this->service()->recordRecipeSnapshot($approved->fresh());
        $this->assertSame((int) $snapshot->id, (int) $again->id);
        $this->assertSame(1, KitchenCostSnapshot::where('kitchen_recipe_id', $recipe->id)->count());
    }

    public function test_order_completion_writes_a_cost_snapshot_and_history(): void
    {
        // Recipe approval snapshot first (burger, 16% excellent).
        $recipe = $this->makeRecipe('KR-2026-0005', 'Beef Stew', 16, 10, 3, 1, 3, [
            ['ingredient_product_id' => 12, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0],
        ], KitchenRecipe::STATUS_DRAFT);
        $approval = $this->approvalService();
        $approval->submit($recipe, User::findOrFail(2));
        $approval->approve($recipe->fresh(), User::findOrFail(3));

        // Then complete an order with 3 kg mince: 240 per portion
        // against a 400 sale price = 60% = very bad.
        $order = $this->inPreparationOrder($recipe->fresh(), 10, 3);
        $line = $order->lines->firstOrFail();
        $completed = $this->completeOrder($order, [(int) $line->id => 3.0], 10, 3);

        $snapshot = KitchenCostSnapshot::where('kitchen_production_order_id', $completed->id)
            ->where('source', KitchenCostSnapshot::SOURCE_ORDER_COMPLETED)
            ->firstOrFail();
        $this->assertSame(2400.0, (float) $snapshot->recipe_cost);
        $this->assertSame(240.0, (float) $snapshot->cost_per_portion);
        $this->assertSame(60.0, (float) $snapshot->food_cost_percent);
        $this->assertSame('very_bad', $snapshot->band);

        // A later price change rewrites neither snapshot.
        DB::table('product_services')->where('id', 12)->update(['purchase_price' => 1600]);
        $this->assertSame(2400.0, (float) $snapshot->fresh()->recipe_cost);

        // History: newest first — the order snapshot, then the
        // recipe-approval snapshot.
        $history = $this->service()->costHistory(1, 16);
        $this->assertCount(2, $history);
        $this->assertSame(KitchenCostSnapshot::SOURCE_ORDER_COMPLETED, $history[0]['source']);
        $this->assertSame(60.0, $history[0]['food_cost_percent']);
        $this->assertSame(KitchenCostSnapshot::SOURCE_RECIPE_APPROVED, $history[1]['source']);
        $this->assertSame(20.0, $history[1]['food_cost_percent']);
    }

    public function test_period_summary_groups_by_month_and_week(): void
    {
        $rice = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $riceOrder = $this->inPreparationOrder($rice, 3, 1);
        $riceLine = $riceOrder->lines->firstOrFail();
        $this->completeOrder($riceOrder, [(int) $riceLine->id => 1.0], 3, 1);

        $burger = KitchenRecipe::where('code', 'KR-2026-0002')->firstOrFail();
        $burgerOrder = $this->inPreparationOrder($burger, 10, 3);
        $burgerLine = $burgerOrder->lines->firstOrFail();
        $this->completeOrder($burgerOrder, [(int) $burgerLine->id => 3.0], 10, 3);

        $from = date('Y-m-01');
        $to = date('Y-m-t');

        $byMonth = $this->service()->periodSummary(1, $from, $to, 'month');
        $this->assertCount(1, $byMonth);
        $this->assertSame(2, $byMonth[0]['orders']);
        $this->assertSame(900.0, $byMonth[0]['planned_cost']);
        $this->assertSame(2500.0, $byMonth[0]['actual_cost']);
        $this->assertSame(1600.0, $byMonth[0]['variance']);
        // Average of 10% (rice) and 48% (burger).
        $this->assertSame(29.0, $byMonth[0]['average_food_cost_percent']);
        $this->assertSame(1, $byMonth[0]['bad_dishes']);

        $byWeek = $this->service()->periodSummary(1, $from, $to, 'week');
        $this->assertCount(1, $byWeek);
        $this->assertSame(2, $byWeek[0]['orders']);

        // A period with no orders summarises to nothing.
        $this->assertSame([], $this->service()->periodSummary(1, '2025-01-01', '2025-01-31', 'month'));
    }

    public function test_open_alerts_lists_only_open(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0002')->firstOrFail();
        $order = $this->inPreparationOrder($recipe, 10, 3);
        $line = $order->lines->firstOrFail();
        $completed = $this->completeOrder($order, [(int) $line->id => 3.0], 10, 3);

        $this->assertCount(2, $this->service()->openAlerts(1));

        $first = KitchenCostAlert::where('kitchen_production_order_id', $completed->id)->firstOrFail();
        $this->service()->acknowledgeAlert($first, User::findOrFail(3));

        $this->assertCount(1, $this->service()->openAlerts(1));
    }
}
