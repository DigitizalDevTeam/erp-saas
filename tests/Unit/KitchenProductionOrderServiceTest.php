<?php

namespace Tests\Unit;

use App\Models\KitchenProductionBatch;
use App\Models\KitchenProductionOrder;
use App\Models\KitchenRecipe;
use App\Models\ProductExpiryLot;
use App\Models\ProductService;
use App\Models\StockReport;
use App\Models\User;
use App\Models\WarehouseProduct;
use App\Services\KitchenProductionOrderService;
use App\Services\KitchenRecipeCostService;
use App\Services\KitchenUnitConversionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KitchenProductionOrderServiceTest extends TestCase
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
            ['id' => 16, 'name' => 'Beef Stew', 'type' => 'product', 'sale_price' => 400, 'sale_price_type' => 'exclusive', 'purchase_price' => 0, 'unit_id' => 3, 'quantity' => 0, 'created_by' => 1],
        ]);
        DB::table('warehouses')->insert([
            ['id' => 1, 'name' => 'COZINHA', 'created_by' => 1],
        ]);
        DB::table('warehouse_products')->insert([
            ['warehouse_id' => 1, 'product_id' => 11, 'quantity' => 5, 'created_by' => 1],
            ['warehouse_id' => 1, 'product_id' => 12, 'quantity' => 5, 'created_by' => 1],
        ]);
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Chef', 'email' => 'chef@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Manager', 'email' => 'manager@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
        ]);

        // Approved coconut rice recipe: 3 kg yield, 150 g portions,
        // 1 kg uncooked rice per batch.
        $this->makeRecipe('KR-2026-0001', 'Coconut Rice', 10, 3, 1, 150, 2, [
            ['ingredient_product_id' => 11, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0],
        ]);
        // Approved burger recipe: 10 portions yield, 1 portion each,
        // 1 kg beef mince per batch (100 g per burger).
        $this->makeRecipe('KR-2026-0002', 'Beef Burger', 14, 10, 3, 1, 3, [
            ['ingredient_product_id' => 12, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['users', 'branches', 'warehouses', 'product_expiry_lots', 'stock_reports', 'warehouse_products', 'kitchen_production_order_lot_usages', 'kitchen_production_batches', 'kitchen_production_order_lines', 'kitchen_production_orders', 'product_recipe_alternatives', 'product_recipes', 'kitchen_unit_conversions', 'kitchen_recipes', 'product_services', 'product_service_units'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function service(): KitchenProductionOrderService
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

    private function approvedOrder(KitchenRecipe $recipe, float $plannedQty = 3, ?int $plannedUnitId = 1): KitchenProductionOrder
    {
        $order = $this->service()->create($this->orderData($recipe, $plannedQty, $plannedUnitId), User::findOrFail(1));

        return $this->service()->approve($order, User::findOrFail(3));
    }

    private function inPreparationOrder(KitchenRecipe $recipe, float $plannedQty = 3, ?int $plannedUnitId = 1): KitchenProductionOrder
    {
        $order = $this->approvedOrder($recipe, $plannedQty, $plannedUnitId);

        return $this->service()->start($order, User::findOrFail(2));
    }

    public function test_plan_lines_scale_recipe_to_portions_burger_example(): void
    {
        // Danilo's example: 10 burgers use 1 kg of meat (100 g each).
        // Planning 20 burgers must need 2 kg of beef mince.
        $recipe = KitchenRecipe::where('code', 'KR-2026-0002')->firstOrFail();

        $planned = $this->service()->planLines($recipe, 20, 3);

        $this->assertSame(2.0, $planned['scale']);
        $this->assertCount(1, $planned['lines']);
        $this->assertSame(12, $planned['lines'][0]['ingredient_product_id']);
        $this->assertSame(2.0, $planned['lines'][0]['planned_quantity']);
        $this->assertSame(1, $planned['lines'][0]['planned_unit_id']);
        $this->assertSame(1600.0, $planned['planned_cost']);
    }

    public function test_plan_lines_convert_grams_to_stock_unit_kg(): void
    {
        // Recipe line in grams, ingredient stocked in kg: 1000 g -> 1 kg.
        $recipe = $this->makeRecipe('KR-2026-0003', 'Rice Pudding', 15, 3, 1, 150, 2, [
            ['ingredient_product_id' => 11, 'quantity' => 1000, 'unit_id' => 2, 'loss_percent' => 0],
        ]);

        $planned = $this->service()->planLines($recipe, 3, 1);

        $this->assertSame(1.0, $planned['lines'][0]['planned_quantity']);
        $this->assertSame(100.0, $planned['planned_cost']);
    }

    public function test_plan_lines_require_approved_recipe(): void
    {
        $recipe = $this->makeRecipe('KR-2026-0004', 'Draft Dish', 15, 3, 1, 150, 2, [
            ['ingredient_product_id' => 11, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0],
        ], KitchenRecipe::STATUS_DRAFT);

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->planLines($recipe, 3, 1);
    }

    public function test_plan_lines_throw_when_conversion_missing(): void
    {
        DB::table('kitchen_unit_conversions')->delete();

        // Yield and portion share one unit, so only the LINE needs a
        // conversion (1000 g of beef, stocked in kg) — and there is none.
        $recipe = $this->makeRecipe('KR-2026-0005', 'Beef Stew', 16, 10, 3, 1, 3, [
            ['ingredient_product_id' => 12, 'quantity' => 1000, 'unit_id' => 2, 'loss_percent' => 0],
        ]);

        $this->expectException(\App\Exceptions\KitchenConversionMissingException::class);
        $this->service()->planLines($recipe, 10, 3);
    }

    public function test_create_draft_has_no_number_and_planned_lines(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();

        $order = $this->service()->create($this->orderData($recipe), User::findOrFail(1));

        $this->assertSame(KitchenProductionOrder::STATUS_DRAFT, $order->status);
        $this->assertNull($order->order_number);
        $this->assertSame(100.0, (float) $order->planned_cost);
        $this->assertCount(1, $order->lines);
        $this->assertSame(1.0, (float) $order->lines->first()->planned_quantity);
    }

    public function test_approve_allocates_number_and_blocks_self_approval(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $order = $this->service()->create($this->orderData($recipe), User::findOrFail(1));

        // The creator cannot approve their own order.
        try {
            $this->service()->approve($order, User::findOrFail(1));
            $this->fail('Self-approval should throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(KitchenProductionOrder::STATUS_DRAFT, $order->fresh()->status);
        }

        $approved = $this->service()->approve($order, User::findOrFail(3));
        $this->assertSame('KP-'.date('Y').'-0001', $approved->order_number);
        $this->assertSame(KitchenProductionOrder::STATUS_APPROVED, $approved->status);
        $this->assertSame(3, (int) $approved->approved_by);

        $second = $this->service()->create($this->orderData($recipe), User::findOrFail(1));
        $secondApproved = $this->service()->approve($second, User::findOrFail(3));
        $this->assertSame('KP-'.date('Y').'-0002', $secondApproved->order_number);
    }

    public function test_start_requires_approved_order(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $order = $this->service()->create($this->orderData($recipe), User::findOrFail(1));

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->start($order, User::findOrFail(2));
    }

    public function test_update_draft_replans_lines_when_quantity_changes(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0002')->firstOrFail();
        $order = $this->service()->create($this->orderData($recipe, 20, 3), User::findOrFail(1));
        $this->assertSame(2.0, (float) $order->lines->first()->planned_quantity);

        $updated = $this->service()->updateDraft($order, $this->orderData($recipe, 40, 3), User::findOrFail(1));

        $this->assertSame(4.0, (float) $updated->lines->first()->planned_quantity);
        $this->assertSame(3200.0, (float) $updated->planned_cost);
    }

    /**
     * Phase 1 exit gate — the coconut-rice no-double-deduction case:
     * producing coconut rice deducts the raw rice ONCE (at production)
     * and receives the prepared batch into stock. The raw ingredient
     * must never be deducted a second time for the same order.
     */
    public function test_complete_deducts_ingredients_once_and_receives_batch(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $order = $this->inPreparationOrder($recipe, 3, 1);
        $line = $order->lines->firstOrFail();

        $completed = $this->service()->complete(
            $order,
            User::findOrFail(2),
            [(int) $line->id => 1.0],
            3,
            1,
            date('Y-m-d', strtotime('+5 days'))
        );

        $this->assertSame(KitchenProductionOrder::STATUS_COMPLETED, $completed->status);
        $this->assertNotNull($completed->stock_deducted_at);
        $this->assertNotNull($completed->stock_received_at);
        $this->assertSame(100.0, (float) $completed->actual_cost);

        // Raw rice: warehouse 5 kg -> 4 kg, global 10 -> 9, exactly ONE
        // stock ledger deduction for this order.
        $this->assertSame(4.0, (float) WarehouseProduct::where('product_id', 11)->where('warehouse_id', 1)->value('quantity'));
        $this->assertSame(9.0, (float) ProductService::findOrFail(11)->quantity);
        $this->assertSame(1, StockReport::where('product_id', 11)->where('type', 'kitchen_production')->where('type_id', $order->id)->count());

        // Coconut rice: warehouse 0 -> 3 kg, global 0 -> 3, one receipt.
        $this->assertSame(3.0, (float) WarehouseProduct::where('product_id', 10)->where('warehouse_id', 1)->value('quantity'));
        $this->assertSame(3.0, (float) ProductService::findOrFail(10)->quantity);
        $this->assertSame(1, StockReport::where('product_id', 10)->where('type', 'kitchen_production')->where('type_id', $order->id)->count());

        // One batch, produced = remaining = 3 kg, expiry set.
        $batch = KitchenProductionBatch::where('kitchen_production_order_id', $order->id)->firstOrFail();
        $this->assertSame($order->order_number.'-B1', $batch->batch_number);
        $this->assertSame(3.0, (float) $batch->quantity_produced);
        $this->assertSame(3.0, (float) $batch->quantity_remaining);
        $this->assertSame(100.0, (float) $batch->total_cost);
    }

    public function test_complete_twice_is_blocked(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $order = $this->inPreparationOrder($recipe, 3, 1);
        $line = $order->lines->firstOrFail();

        $this->service()->complete($order, User::findOrFail(2), [(int) $line->id => 1.0], 3, 1, date('Y-m-d', strtotime('+5 days')));

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->complete($order->fresh(), User::findOrFail(2), [(int) $line->id => 1.0], 3, 1, date('Y-m-d', strtotime('+5 days')));
    }

    public function test_complete_blocked_on_insufficient_stock(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $order = $this->inPreparationOrder($recipe, 3, 1);
        $line = $order->lines->firstOrFail();

        // Only 0.5 kg of rice in the warehouse, the order needs 1 kg.
        WarehouseProduct::where('product_id', 11)->update(['quantity' => 0.5]);

        try {
            $this->service()->complete($order, User::findOrFail(2), [(int) $line->id => 1.0], 3, 1, date('Y-m-d', strtotime('+5 days')));
            $this->fail('Completion with a shortage should throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Uncooked Rice', $e->getMessage());
        }

        // Nothing moved: the order is still in preparation, stock untouched.
        $this->assertSame(KitchenProductionOrder::STATUS_IN_PREPARATION, $order->fresh()->status);
        $this->assertSame(0.5, (float) WarehouseProduct::where('product_id', 11)->where('warehouse_id', 1)->value('quantity'));
        $this->assertSame(0, KitchenProductionBatch::count());
    }

    public function test_complete_uses_fefo_lots_and_records_usage(): void
    {
        DB::table('product_services')->where('id', 11)->update(['batch_tracking' => 1]);
        DB::table('product_expiry_lots')->insert([
            ['product_id' => 11, 'warehouse_id' => 1, 'source' => 'purchase', 'batch_no' => 'LOT-A', 'expiry_date' => '2026-12-01', 'quantity_in' => 0.6, 'quantity_remaining' => 0.6, 'created_by' => 1],
            ['product_id' => 11, 'warehouse_id' => 1, 'source' => 'purchase', 'batch_no' => 'LOT-B', 'expiry_date' => '2027-06-01', 'quantity_in' => 0.6, 'quantity_remaining' => 0.6, 'created_by' => 1],
        ]);

        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $order = $this->inPreparationOrder($recipe, 3, 1);
        $line = $order->lines->firstOrFail();

        $this->service()->complete($order, User::findOrFail(2), [(int) $line->id => 1.0], 3, 1, date('Y-m-d', strtotime('+5 days')));

        // FEFO: the lot expiring first is used first.
        $this->assertSame(0.0, (float) ProductExpiryLot::where('batch_no', 'LOT-A')->value('quantity_remaining'));
        $this->assertSame(0.2, (float) ProductExpiryLot::where('batch_no', 'LOT-B')->value('quantity_remaining'));

        $usages = DB::table('kitchen_production_order_lot_usages')->where('kitchen_production_order_line_id', $line->id)->orderBy('id')->get();
        $this->assertCount(2, $usages);
        $this->assertSame(0.6, (float) $usages[0]->quantity);
        $this->assertSame(0.4, (float) $usages[1]->quantity);
    }

    public function test_actual_cost_is_snapshotted_at_completion(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $order = $this->inPreparationOrder($recipe, 3, 1);
        $line = $order->lines->firstOrFail();

        $completed = $this->service()->complete($order, User::findOrFail(2), [(int) $line->id => 1.0], 3, 1, date('Y-m-d', strtotime('+5 days')));
        $this->assertSame(100.0, (float) $completed->actual_cost);

        // A later price change must never rewrite the completed order.
        DB::table('product_services')->where('id', 11)->update(['purchase_price' => 200]);
        $this->assertSame(100.0, (float) $completed->fresh()->actual_cost);
    }

    public function test_cost_per_portion_and_food_cost_band_on_completion(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $order = $this->inPreparationOrder($recipe, 3, 1);
        $line = $order->lines->firstOrFail();

        $completed = $this->service()->complete($order, User::findOrFail(2), [(int) $line->id => 1.0], 3, 1, date('Y-m-d', strtotime('+5 days')));

        // 3 kg yield / 150 g portions = 20 portions; cost 100 / 20 = 5
        // per portion; selling price ex-VAT 50 -> food cost 10% = excellent.
        $this->assertSame(5.0, (float) $completed->cost_per_portion);
        $this->assertSame(10.0, (float) $completed->food_cost_percent);
        $this->assertSame('excellent', $completed->food_cost_band);
    }

    public function test_batch_consume_decrements_and_blocks_overconsume(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $order = $this->inPreparationOrder($recipe, 3, 1);
        $line = $order->lines->firstOrFail();
        $this->service()->complete($order, User::findOrFail(2), [(int) $line->id => 1.0], 3, 1, date('Y-m-d', strtotime('+5 days')));

        $batch = KitchenProductionBatch::where('kitchen_production_order_id', $order->id)->firstOrFail();
        $batch->consume(1.5);
        $this->assertSame(1.5, (float) $batch->fresh()->quantity_remaining);

        $this->expectException(\InvalidArgumentException::class);
        $batch->fresh()->consume(2.0);
    }

    public function test_validate_requires_completed_and_different_user(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $order = $this->inPreparationOrder($recipe, 3, 1);
        $line = $order->lines->firstOrFail();
        $completed = $this->service()->complete($order, User::findOrFail(2), [(int) $line->id => 1.0], 3, 1, date('Y-m-d', strtotime('+5 days')));

        // The completer cannot validate their own order.
        try {
            $this->service()->validateOrder($completed, User::findOrFail(2));
            $this->fail('Self-validation should throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(KitchenProductionOrder::STATUS_COMPLETED, $completed->fresh()->status);
        }

        $validated = $this->service()->validateOrder($completed, User::findOrFail(3));
        $this->assertSame(KitchenProductionOrder::STATUS_VALIDATED, $validated->status);
        $this->assertSame(3, (int) $validated->validated_by);
    }

    public function test_other_company_cannot_create_orders(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->create($this->orderData($recipe), User::findOrFail(4));
    }
}
