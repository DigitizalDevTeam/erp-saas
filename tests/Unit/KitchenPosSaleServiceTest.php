<?php

namespace Tests\Unit;

use App\Models\KitchenPosBatchConsumption;
use App\Models\KitchenProductionBatch;
use App\Models\KitchenProductionOrder;
use App\Models\KitchenRecipe;
use App\Models\Pos;
use App\Models\PosProduct;
use App\Models\ProductRecipe;
use App\Models\ProductService;
use App\Models\StockReport;
use App\Models\User;
use App\Models\WarehouseLoss;
use App\Models\WarehouseProduct;
use App\Services\KitchenPosSaleService;
use App\Services\KitchenProductionOrderService;
use App\Services\KitchenRecipeCostService;
use App\Services\KitchenUnitConversionService;
use App\Services\PosLineStockDeductionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Kitchen Production Phase 2 — POS integration (plan §5).
 *
 * The one rule: a dish sale deducts the PREPARED batch only. The raw
 * ingredients were already deducted once, at production (Phase 1);
 * they must never be deducted a second time at sale.
 *
 * These tests run service-level through deductLine / cancelLine with
 * real Pos / PosProduct models (no existing test posts to
 * sendToProduction — the HTTP setup is heavy — so the controller
 * wiring is covered by the shortage message and the cancel outcome
 * here, and the controller itself is verified in the browser test).
 */
class KitchenPosSaleServiceTest extends TestCase
{
    private int $warehouseId = 1;

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
            $table->boolean('options_enabled')->default(false);
            $table->boolean('variants_enabled')->default(false);
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

        Schema::create('kitchen_pos_batch_consumptions', function ($table) {
            $table->increments('id');
            $table->integer('pos_product_id')->nullable();
            $table->integer('kitchen_production_batch_id');
            $table->integer('product_id');
            $table->decimal('quantity', 12, 4);
            $table->decimal('quantity_restored', 12, 4)->default(0);
            $table->integer('warehouse_id');
            $table->integer('created_by');
            $table->timestamps();

            $table->index(['pos_product_id', 'product_id'], 'kpbc_line_idx');
            $table->index(['kitchen_production_batch_id', 'product_id'], 'kpbc_batch_idx');
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

        Schema::create('pos', function ($table) {
            $table->increments('id');
            $table->integer('pos_id')->nullable();
            $table->string('document_type')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->integer('branch_id')->nullable();
            $table->integer('shift_id')->nullable();
            $table->integer('table_id')->nullable();
            $table->integer('customer_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_products', function ($table) {
            $table->increments('id');
            $table->integer('pos_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('price', 15, 4)->default(0);
            $table->decimal('unit_subtotal', 15, 4)->nullable();
            $table->string('tax')->nullable();
            $table->decimal('discount', 15, 4)->default(0);
            $table->text('description')->nullable();
            $table->text('identifiers')->nullable();
            $table->text('selected_options_json')->nullable();
            $table->string('status')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('stock_deducted_at')->nullable();
            $table->timestamp('stock_restored_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamp('removed_from_cart_at')->nullable();
            $table->integer('negative_stock_authorized_by')->nullable();
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

        DB::table('product_service_units')->insert([
            ['id' => 1, 'name' => 'kg', 'created_by' => 1],
            ['id' => 2, 'name' => 'g', 'created_by' => 1],
            ['id' => 3, 'name' => 'portion', 'created_by' => 1],
        ]);
        DB::table('kitchen_unit_conversions')->insert([
            ['from_unit_id' => 1, 'to_unit_id' => 2, 'factor' => 1000, 'product_id' => null, 'created_by' => 1],
        ]);
        DB::table('product_services')->insert([
            ['id' => 10, 'name' => 'Coconut Rice', 'type' => 'product', 'sale_price' => 50, 'sale_price_type' => 'exclusive', 'purchase_price' => 0, 'unit_id' => 1, 'quantity' => 0, 'options_enabled' => false, 'created_by' => 1],
            ['id' => 11, 'name' => 'Uncooked Rice', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 100, 'unit_id' => 1, 'quantity' => 10, 'options_enabled' => false, 'created_by' => 1],
            ['id' => 12, 'name' => 'Beef Mince', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 800, 'unit_id' => 1, 'quantity' => 5, 'options_enabled' => false, 'created_by' => 1],
            ['id' => 13, 'name' => 'Chicken Mince', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 700, 'unit_id' => 1, 'quantity' => 5, 'options_enabled' => false, 'created_by' => 1],
            ['id' => 14, 'name' => 'Beef Burger', 'type' => 'product', 'sale_price' => 500, 'sale_price_type' => 'exclusive', 'purchase_price' => 0, 'unit_id' => 3, 'quantity' => 0, 'options_enabled' => true, 'created_by' => 1],
            ['id' => 17, 'name' => 'Grilled Chicken', 'type' => 'product', 'sale_price' => 400, 'sale_price_type' => 'exclusive', 'purchase_price' => 0, 'unit_id' => 3, 'quantity' => 0, 'options_enabled' => false, 'created_by' => 1],
            ['id' => 18, 'name' => 'Cooked Rice', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 0, 'unit_id' => 1, 'quantity' => 0, 'options_enabled' => false, 'created_by' => 1],
            ['id' => 19, 'name' => 'Chicken Breast', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 300, 'unit_id' => 1, 'quantity' => 5, 'options_enabled' => false, 'created_by' => 1],
            ['id' => 20, 'name' => 'Custom Burger', 'type' => 'product', 'sale_price' => 500, 'sale_price_type' => 'exclusive', 'purchase_price' => 0, 'unit_id' => 3, 'quantity' => 0, 'options_enabled' => true, 'created_by' => 1],
        ]);
        DB::table('warehouses')->insert([
            ['id' => 1, 'name' => 'COZINHA', 'created_by' => 1],
        ]);
        DB::table('warehouse_products')->insert([
            ['warehouse_id' => 1, 'product_id' => 11, 'quantity' => 5, 'created_by' => 1],
            ['warehouse_id' => 1, 'product_id' => 12, 'quantity' => 5, 'created_by' => 1],
            ['warehouse_id' => 1, 'product_id' => 13, 'quantity' => 5, 'created_by' => 1],
            ['warehouse_id' => 1, 'product_id' => 19, 'quantity' => 5, 'created_by' => 1],
        ]);
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Chef', 'email' => 'chef@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Manager', 'email' => 'manager@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        // Approved coconut rice recipe: 3 kg yield, 150 g portions,
        // 1 kg uncooked rice per batch.
        $this->makeRecipe('KR-2026-0001', 'Coconut Rice', 10, 3, 1, 150, 2, [
            ['ingredient_product_id' => 11, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0],
        ]);
        // Approved burger recipe: 10 portions yield, 1 portion each,
        // 1 kg beef mince per batch (100 g per burger). Chicken mince
        // is a defined alternative of the beef line (100 g per burger).
        $burger = $this->makeRecipe('KR-2026-0002', 'Beef Burger', 14, 10, 3, 1, 3, [
            ['ingredient_product_id' => 12, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0],
        ]);
        $burgerLine = ProductRecipe::where('kitchen_recipe_id', $burger->id)->firstOrFail();
        DB::table('product_recipe_alternatives')->insert([
            'product_recipe_id' => $burgerLine->id, 'alternative_product_id' => 13, 'quantity' => 1, 'sort_order' => 0, 'created_by' => 1,
        ]);
        // Approved cooked rice recipe (a prepared INGREDIENT): 3 kg
        // yield from 1 kg uncooked rice.
        $this->makeRecipe('KR-2026-0003', 'Cooked Rice', 18, 3, 1, 1, 1, [
            ['ingredient_product_id' => 11, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0],
        ]);
        // Grilled chicken, made to order: 0.15 kg cooked rice (a
        // prepared ingredient) + 0.2 kg chicken breast per portion.
        DB::table('product_recipes')->insert([
            ['product_id' => 17, 'kitchen_recipe_id' => null, 'version' => 1, 'ingredient_product_id' => 18, 'quantity' => 0.15, 'unit_id' => 1, 'loss_percent' => 0, 'created_by' => 1],
            ['product_id' => 17, 'kitchen_recipe_id' => null, 'version' => 1, 'ingredient_product_id' => 19, 'quantity' => 0.2, 'unit_id' => 1, 'loss_percent' => 0, 'created_by' => 1],
        ]);
        // Custom burger, made to order: 0.1 kg beef mince per burger.
        // Chicken mince is a defined alternative of the beef line.
        DB::table('product_recipes')->insert([
            ['product_id' => 20, 'kitchen_recipe_id' => null, 'version' => 1, 'ingredient_product_id' => 12, 'quantity' => 0.1, 'unit_id' => 1, 'loss_percent' => 0, 'created_by' => 1],
        ]);
        $customLine = ProductRecipe::where('product_id', 20)->firstOrFail();
        DB::table('product_recipe_alternatives')->insert([
            'product_recipe_id' => $customLine->id, 'alternative_product_id' => 13, 'quantity' => 0.1, 'sort_order' => 0, 'created_by' => 1,
        ]);

        // Stock movements go through Utility::addProductStock, which
        // reads the logged-in user — log the company user in.
        Auth::login(User::findOrFail(1));
    }

    protected function tearDown(): void
    {
        foreach (['warehouse_losses', 'pos_products', 'pos', 'users', 'branches', 'warehouses', 'product_expiry_lots', 'stock_reports', 'warehouse_products', 'kitchen_pos_batch_consumptions', 'kitchen_production_order_lot_usages', 'kitchen_production_batches', 'kitchen_production_order_lines', 'kitchen_production_orders', 'product_recipe_alternatives', 'product_recipes', 'kitchen_unit_conversions', 'kitchen_recipes', 'product_services', 'product_service_units'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function service(): KitchenPosSaleService
    {
        $conversions = new KitchenUnitConversionService();

        return new KitchenPosSaleService($conversions, new KitchenRecipeCostService($conversions));
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

    /** Produce a batch: create -> approve -> start -> complete. */
    private function produce(KitchenRecipe $recipe, float $plannedQty, int $plannedUnitId, float $actualQty, string $expiry): KitchenProductionBatch
    {
        $order = $this->orderService()->create([
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
        ], User::findOrFail(1));
        $order = $this->orderService()->approve($order, User::findOrFail(3));
        $order = $this->orderService()->start($order, User::findOrFail(2));

        $actuals = [];
        foreach ($order->lines as $line) {
            $actuals[(int) $line->id] = (float) $line->planned_quantity;
        }
        $this->orderService()->complete($order, User::findOrFail(2), $actuals, $actualQty, $plannedUnitId, $expiry);

        return KitchenProductionBatch::where('kitchen_production_order_id', $order->id)->firstOrFail();
    }

    private function makePos(): Pos
    {
        $pos = new Pos();
        $pos->pos_id = 77;
        $pos->document_type = 'cash_sale';
        $pos->warehouse_id = $this->warehouseId;
        $pos->created_by = 1;
        $pos->save();

        return $pos;
    }

    private function makeLine(Pos $pos, int $productId, float $qty, array $extra = []): PosProduct
    {
        $line = new PosProduct();
        $line->pos_id = $pos->id;
        $line->product_id = $productId;
        $line->quantity = $qty;
        $line->price = 100;
        $line->status = $extra['status'] ?? PosProduct::STATUS_PENDING;
        $line->sent_at = now();
        $line->selected_options_json = $extra['selected_options_json'] ?? null;
        $line->warehouse_id = $extra['warehouse_id'] ?? null;
        $line->save();

        return $line;
    }

    private function warehouseQty(int $productId): float
    {
        return (float) (WarehouseProduct::where('product_id', $productId)->where('warehouse_id', $this->warehouseId)->value('quantity') ?? 0);
    }

    private function globalQty(int $productId): float
    {
        return (float) ProductService::findOrFail($productId)->quantity;
    }

    private function batchRemaining(int $productId): float
    {
        return $this->service()->batchRemaining($productId, $this->warehouseId, 1);
    }

    public function test_direct_sale_deducts_batch_only(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $batch = $this->produce($recipe, 3, 1, 3, date('Y-m-d', strtotime('+5 days')));

        // After production: raw rice 5 -> 4 kg warehouse, 10 -> 9 global.
        $this->assertSame(4.0, $this->warehouseQty(11));
        $this->assertSame(9.0, $this->globalQty(11));

        $pos = $this->makePos();
        $line = $this->makeLine($pos, 10, 1);

        $this->assertTrue(PosLineStockDeductionService::deductLine($pos, $line, 1, 'POS-77'));

        // Batch 3 -> 2 kg; the prepared product's own stock falls too.
        $this->assertSame(2.0, $this->batchRemaining(10));
        $this->assertSame(2.0, $this->warehouseQty(10));
        $this->assertSame(2.0, $this->globalQty(10));

        // Raw rice untouched by the sale.
        $this->assertSame(4.0, $this->warehouseQty(11));
        $this->assertSame(9.0, $this->globalQty(11));

        // One consumption row links the line to the batch.
        $consumption = KitchenPosBatchConsumption::where('pos_product_id', $line->id)->firstOrFail();
        $this->assertSame($batch->id, (int) $consumption->kitchen_production_batch_id);
        $this->assertSame(1.0, (float) $consumption->quantity);
    }

    public function test_no_double_deduction_coconut_rice(): void
    {
        // The Phase 2 exit gate: produce then sell. The raw ingredient
        // is deducted exactly once (at production), never again at sale.
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $this->produce($recipe, 3, 1, 3, date('Y-m-d', strtotime('+5 days')));

        $pos = $this->makePos();
        $line = $this->makeLine($pos, 10, 2);
        $this->assertTrue(PosLineStockDeductionService::deductLine($pos, $line, 1, 'POS-77'));

        // Raw rice: exactly one stock ledger deduction (production).
        $this->assertSame(4.0, $this->warehouseQty(11));
        $this->assertSame(9.0, $this->globalQty(11));
        $this->assertSame(1, StockReport::where('product_id', 11)->where('type', 'kitchen_production')->count());

        // Batch 3 -> 1 kg; coconut rice stock 3 -> 1.
        $this->assertSame(1.0, $this->batchRemaining(10));
        $this->assertSame(1.0, $this->warehouseQty(10));
    }

    public function test_batch_consumption_is_fefo(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $early = $this->produce($recipe, 3, 1, 3, date('Y-m-d', strtotime('+2 days')));
        $late = $this->produce($recipe, 3, 1, 3, date('Y-m-d', strtotime('+10 days')));

        $pos = $this->makePos();
        $line = $this->makeLine($pos, 10, 1);
        $this->assertTrue(PosLineStockDeductionService::deductLine($pos, $line, 1, 'POS-77'));

        // The batch expiring sooner is consumed first.
        $this->assertSame(2.0, (float) $early->fresh()->quantity_remaining);
        $this->assertSame(3.0, (float) $late->fresh()->quantity_remaining);
    }

    public function test_prepared_ingredient_in_dish_consumes_its_batch(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0003')->firstOrFail();
        $this->produce($recipe, 3, 1, 3, date('Y-m-d', strtotime('+5 days')));

        // Cooked rice received: 3 kg in the warehouse and in a batch.
        $this->assertSame(3.0, $this->warehouseQty(18));
        $this->assertSame(3.0, $this->batchRemaining(18));

        $pos = $this->makePos();
        $line = $this->makeLine($pos, 17, 2);
        $this->assertTrue(PosLineStockDeductionService::deductLine($pos, $line, 1, 'POS-77'));

        // 2 portions x 0.15 kg cooked rice: batch 3 -> 2.7, and the
        // warehouse stock falls by the same amount.
        $this->assertSame(2.7, $this->batchRemaining(18));
        $this->assertSame(2.7, $this->warehouseQty(18));
        // The raw chicken breast is deducted as usual: 5 -> 4.6.
        $this->assertSame(4.6, $this->warehouseQty(19));
        // The uncooked rice was deducted once at production (5 -> 4),
        // never again for the sale.
        $this->assertSame(4.0, $this->warehouseQty(11));
    }

    public function test_option_swap_changes_composition(): void
    {
        // Custom burger, made to order: 0.1 kg beef mince per burger.
        // The customer picks the chicken mince option (a defined
        // alternative of the beef line), so the beef is not deducted.
        $pos = $this->makePos();
        $line = $this->makeLine($pos, 20, 2, [
            'selected_options_json' => json_encode([['product_id' => 13, 'quantity' => 0.1]]),
        ]);
        $this->assertTrue(PosLineStockDeductionService::deductLine($pos, $line, 1, 'POS-77'));

        // Beef mince untouched; chicken mince 5 -> 4.8 (2 x 0.1).
        $this->assertSame(5.0, $this->warehouseQty(12));
        $this->assertSame(4.8, $this->warehouseQty(13));
    }

    public function test_option_swap_changes_cost(): void
    {
        // The approved burger recipe costs beef mince (800/kg). With
        // the chicken mince option picked, the beef line costs the
        // alternative instead (700/kg).
        $recipe = KitchenRecipe::where('code', 'KR-2026-0002')->firstOrFail();
        $plain = $this->service()->effectiveRecipeCost($recipe, [], 1);
        $this->assertSame(800.0, $plain['recipe_cost']);

        $swapped = $this->service()->effectiveRecipeCost($recipe, [['product_id' => 13, 'quantity' => 0.1]], 1);
        $this->assertSame(700.0, $swapped['recipe_cost']);
        $this->assertSame(13, $swapped['lines'][0]['swapped_to']);

        // And the waste cost of a made-to-order line follows the swap:
        // 2 custom burgers x 0.1 kg x 700 = 140 (not 160 with beef).
        $this->assertSame(140.0, $this->service()->dishLineFoodCost(20, 2, [['product_id' => 13, 'quantity' => 0.1]], 1));
        $this->assertSame(160.0, $this->service()->dishLineFoodCost(20, 2, [], 1));
    }

    public function test_cancel_before_preparation_restores(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $this->produce($recipe, 3, 1, 3, date('Y-m-d', strtotime('+5 days')));

        $pos = $this->makePos();
        $line = $this->makeLine($pos, 10, 1);
        $this->assertTrue(PosLineStockDeductionService::deductLine($pos, $line, 1, 'POS-77'));
        $this->assertSame(2.0, $this->batchRemaining(10));

        $outcome = $this->service()->cancelLine($pos, $line->fresh(), 1, 'POS-77', null, User::findOrFail(1));
        $this->assertSame('restored', $outcome);

        // Batch and stock go back; no loss recorded.
        $this->assertSame(3.0, $this->batchRemaining(10));
        $this->assertSame(3.0, $this->warehouseQty(10));
        $this->assertSame(0, WarehouseLoss::count());

        // Idempotent: a second cancel restores nothing.
        $this->assertSame('nothing', $this->service()->cancelLine($pos, $line->fresh(), 1, 'POS-77', null, User::findOrFail(1)));
        $this->assertSame(3.0, $this->batchRemaining(10));
    }

    public function test_cancel_after_preparation_records_loss(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $this->produce($recipe, 3, 1, 3, date('Y-m-d', strtotime('+5 days')));

        $pos = $this->makePos();
        $line = $this->makeLine($pos, 10, 1);
        $this->assertTrue(PosLineStockDeductionService::deductLine($pos, $line, 1, 'POS-77'));

        $line->status = PosProduct::STATUS_IN_PREPARATION;
        $line->save();

        $outcome = $this->service()->cancelLine($pos, $line->fresh(), 1, 'POS-77', 'staff_meal', User::findOrFail(1));
        $this->assertSame('loss', $outcome);

        // Nothing is restored: the batch stays consumed.
        $this->assertSame(2.0, $this->batchRemaining(10));
        $this->assertSame(2.0, $this->warehouseQty(10));
        // And the loss row is a waste log only — it moves no stock.
        $this->assertSame(2.0, $this->warehouseQty(10));

        $loss = WarehouseLoss::firstOrFail();
        $this->assertSame(10, (int) $loss->product_id);
        $this->assertSame(1.0, (float) $loss->quantity);
        $this->assertSame('staff_meal', $loss->delivery_destination);
        $this->assertGreaterThan(0, (float) $loss->cost_of_goods);
        $this->assertStringStartsWith('WO-', (string) $loss->write_off_no);
    }

    public function test_cancel_after_preparation_returned_to_stock(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $this->produce($recipe, 3, 1, 3, date('Y-m-d', strtotime('+5 days')));

        $pos = $this->makePos();
        $line = $this->makeLine($pos, 10, 1);
        $this->assertTrue(PosLineStockDeductionService::deductLine($pos, $line, 1, 'POS-77'));

        $line->status = PosProduct::STATUS_READY;
        $line->save();

        $outcome = $this->service()->cancelLine($pos, $line->fresh(), 1, 'POS-77', 'returned_to_stock', User::findOrFail(1));
        $this->assertSame('returned', $outcome);

        // The prepared food goes back into its batch; no loss row.
        $this->assertSame(3.0, $this->batchRemaining(10));
        $this->assertSame(3.0, $this->warehouseQty(10));
        $this->assertSame(0, WarehouseLoss::count());
        // The raw rice stays deducted — it was already cooked.
        $this->assertSame(4.0, $this->warehouseQty(11));
    }

    public function test_shortage_shown_before_send(): void
    {
        // No batches at all: the dish and the batch are named.
        $shortages = $this->service()->checkShortages([
            ['id' => 10, 'quantity' => 1],
        ], $this->warehouseId, 1);
        $this->assertCount(1, $shortages);
        $this->assertSame('Coconut Rice', $shortages[0]['dish_name']);
        $this->assertSame('Coconut Rice', $shortages[0]['prepared_product_name']);
        $this->assertSame(1.0, $shortages[0]['needed']);
        $this->assertSame(0.0, $shortages[0]['available']);

        // A batch of 3 kg, but 5 kg ordered: short by 2.
        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $this->produce($recipe, 3, 1, 3, date('Y-m-d', strtotime('+5 days')));
        $shortages = $this->service()->checkShortages([
            ['id' => 10, 'quantity' => 5],
        ], $this->warehouseId, 1);
        $this->assertCount(1, $shortages);
        $this->assertSame(5.0, $shortages[0]['needed']);
        $this->assertSame(3.0, $shortages[0]['available']);

        // Covered: no shortage.
        $this->assertSame([], $this->service()->checkShortages([
            ['id' => 10, 'quantity' => 3],
        ], $this->warehouseId, 1));

        // A manager-authorized line is never blocked.
        $this->assertSame([], $this->service()->checkShortages([
            ['id' => 10, 'quantity' => 99, 'negative_stock_authorized_by' => 3],
        ], $this->warehouseId, 1));
    }

    public function test_short_batch_falls_back_to_legacy_raw_deduction(): void
    {
        // Rejection path, documented: the send-time shortage check is
        // the enforcement point. At payment time, when no batch covers
        // the dish, the legacy raw deduction runs instead of failing
        // the sale — the dish is still sold, from its raw ingredients.
        $pos = $this->makePos();
        $line = $this->makeLine($pos, 10, 1);
        $this->assertTrue(PosLineStockDeductionService::deductLine($pos, $line, 1, 'POS-77'));

        // Raw rice deducted as a normal recipe sale: 5 -> 4.
        $this->assertSame(4.0, $this->warehouseQty(11));
        // No batch was consumed (none existed).
        $this->assertSame(0, KitchenPosBatchConsumption::count());
    }
}
