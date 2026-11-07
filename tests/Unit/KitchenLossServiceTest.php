<?php

namespace Tests\Unit;

use App\Models\KitchenLoss;
use App\Models\KitchenProductionBatch;
use App\Models\KitchenProductionOrder;
use App\Models\KitchenRecipe;
use App\Models\Pos;
use App\Models\PosProduct;
use App\Models\ProductService;
use App\Models\User;
use App\Models\WarehouseLoss;
use App\Models\WarehouseTransfer;
use App\Services\KitchenLossService;
use App\Services\KitchenProductionOrderService;
use App\Services\KitchenRecipeCostService;
use App\Services\KitchenUnitConversionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Kitchen Production Phase 5 — losses + internal consumption.
 *
 * Covers: recording by category, approval where the category needs
 * it (and direct recording where it does not), no self-approval,
 * rejection, the reuse flag + food-safety note + destination, unit
 * conversion, the batch quantity guard, the WarehouseLoss write-off
 * link, transfers between kitchens, and the batch reconciliation
 * produced - sold - lost = remaining.
 */
class KitchenLossServiceTest extends TestCase
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
            ['id' => 20, 'name' => 'Other Company Dish', 'type' => 'product', 'sale_price' => 100, 'sale_price_type' => 'exclusive', 'purchase_price' => 0, 'unit_id' => 3, 'quantity' => 0, 'created_by' => 4],
        ]);
        DB::table('warehouses')->insert([
            ['id' => 1, 'name' => 'COZINHA', 'created_by' => 1],
            ['id' => 2, 'name' => 'COLD ROOM', 'created_by' => 1],
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

        // Approved burger recipe: 10 portions yield, 1 portion each,
        // 1 kg beef mince per batch (100 g per burger).
        $this->makeRecipe('KR-2026-0002', 'Beef Burger', 14, 10, 3, 1, 3, [
            ['ingredient_product_id' => 12, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0],
        ]);

        // Utility::addProductStock / warehouse_quantity read the
        // authenticated user, so the company user logs in here.
        Auth::login(User::findOrFail(1));
    }

    protected function tearDown(): void
    {
        foreach (['pos_products', 'pos', 'kitchen_pos_batch_consumptions', 'warehouse_transfers', 'warehouse_losses', 'kitchen_losses', 'users', 'branches', 'warehouses', 'product_expiry_lots', 'stock_reports', 'warehouse_products', 'kitchen_production_order_lot_usages', 'kitchen_production_batches', 'kitchen_production_order_lines', 'kitchen_production_orders', 'product_recipe_alternatives', 'product_recipes', 'kitchen_unit_conversions', 'kitchen_recipes', 'product_services', 'product_service_units'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function service(): KitchenLossService
    {
        $conversions = new KitchenUnitConversionService();

        return new KitchenLossService(
            $conversions,
            new KitchenProductionOrderService($conversions, new KitchenRecipeCostService($conversions))
        );
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

    /** A completed burger order: a batch of 10 portions, unit cost 80. */
    private function completedBurgerBatch(): KitchenProductionBatch
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0002')->firstOrFail();
        $order = $this->orderService()->create([
            'kitchen_recipe_id' => $recipe->id,
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
        $order = $this->orderService()->approve($order, User::findOrFail(3));
        $order = $this->orderService()->start($order, User::findOrFail(2));
        $line = $order->lines->firstOrFail();
        $this->orderService()->complete(
            $order,
            User::findOrFail(2),
            [(int) $line->id => 1.0],
            10,
            3,
            date('Y-m-d', strtotime('+5 days'))
        );

        return KitchenProductionBatch::where('kitchen_production_order_id', (int) $order->id)->firstOrFail();
    }

    private function lossData(array $overrides = []): array
    {
        return array_merge([
            'category' => KitchenLoss::CATEGORY_PREPARATION,
            'product_id' => 12,
            'quantity' => 1,
            'unit_id' => 1,
            'unit_cost' => null,
            'warehouse_id' => 1,
            'reason' => 'Spilled during prep',
        ], $overrides);
    }

    private function warehouseQty(int $productId, int $warehouseId = 1): float
    {
        return (float) DB::table('warehouse_products')
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->sum('quantity');
    }

    // -----------------------------------------------------------------
    // Recording
    // -----------------------------------------------------------------

    public function test_preparation_loss_is_recorded_directly_as_approved(): void
    {
        $loss = $this->service()->record($this->lossData(), User::findOrFail(2));

        $this->assertSame(KitchenLoss::STATUS_APPROVED, $loss->status);
        $this->assertSame('KL-'.date('Y').'-0001', $loss->loss_number);
        $this->assertSame(1.0, (float) $loss->quantity);
        $this->assertSame(800.0, (float) $loss->unit_cost);
        $this->assertSame(800.0, (float) $loss->total_cost);
        $this->assertSame(1, (int) $loss->created_by);
        $this->assertSame(2, (int) $loss->recorded_by);
    }

    public function test_staff_meal_needs_manager_approval(): void
    {
        $loss = $this->service()->record($this->lossData(['category' => KitchenLoss::CATEGORY_STAFF_MEAL]), User::findOrFail(2));

        $this->assertTrue($loss->isRecorded());
        $this->assertTrue($loss->needsApproval());

        $approved = $this->service()->approve($loss, User::findOrFail(3));

        $this->assertTrue($approved->isApproved());
        $this->assertSame(3, (int) $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
    }

    public function test_complimentary_and_tasting_need_approval_but_burnt_spoiled_does_not(): void
    {
        $this->assertTrue(KitchenLoss::categoryNeedsApproval(KitchenLoss::CATEGORY_COMPLIMENTARY));
        $this->assertTrue(KitchenLoss::categoryNeedsApproval(KitchenLoss::CATEGORY_TASTING));
        $this->assertFalse(KitchenLoss::categoryNeedsApproval(KitchenLoss::CATEGORY_BURNT_SPOILED));
        $this->assertFalse(KitchenLoss::categoryNeedsApproval(KitchenLoss::CATEGORY_CUSTOMER_RETURN));
        $this->assertFalse(KitchenLoss::categoryNeedsApproval(KitchenLoss::CATEGORY_BUFFET_LEFTOVER));
        $this->assertFalse(KitchenLoss::categoryNeedsApproval(KitchenLoss::CATEGORY_TRANSFER));
    }

    public function test_no_self_approval(): void
    {
        $loss = $this->service()->record($this->lossData(['category' => KitchenLoss::CATEGORY_STAFF_MEAL]), User::findOrFail(2));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot approve a loss you recorded yourself');

        $this->service()->approve($loss, User::findOrFail(2));
    }

    public function test_no_self_rejection(): void
    {
        $loss = $this->service()->record($this->lossData(['category' => KitchenLoss::CATEGORY_COMPLIMENTARY]), User::findOrFail(2));

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->reject($loss, User::findOrFail(2), 'No');
    }

    public function test_rejected_loss_cannot_be_posted(): void
    {
        $loss = $this->service()->record($this->lossData(['category' => KitchenLoss::CATEGORY_TASTING]), User::findOrFail(2));
        $rejected = $this->service()->reject($loss, User::findOrFail(3), 'Not a tasting — it was a sale');

        $this->assertTrue($rejected->isRejected());
        $this->assertSame('Not a tasting — it was a sale', $rejected->rejection_note);

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->post($rejected->fresh(), User::findOrFail(2));
    }

    public function test_approve_is_blocked_for_direct_categories(): void
    {
        $loss = $this->service()->record($this->lossData(), User::findOrFail(2));

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->approve($loss, User::findOrFail(3));
    }

    public function test_reuse_flag_needs_food_safety_note_and_destination(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('food-safety note');

        $this->service()->record($this->lossData(['reuse_flag' => true]), User::findOrFail(2));
    }

    public function test_reuse_flag_without_destination_is_blocked(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('where the reused food goes');

        $this->service()->record($this->lossData([
            'reuse_flag' => true,
            'food_safety_note' => 'Kept below 5°C, sealed, within 2 hours',
        ]), User::findOrFail(2));
    }

    public function test_reuse_flag_with_note_and_destination_is_recorded(): void
    {
        $loss = $this->service()->record($this->lossData([
            'category' => KitchenLoss::CATEGORY_CUSTOMER_RETURN,
            'reuse_flag' => true,
            'food_safety_note' => 'Untouched plate, returned within 5 minutes, kept hot',
            'reuse_destination' => KitchenLoss::REUSE_STAFF_MEAL,
        ]), User::findOrFail(2));

        $this->assertTrue((bool) $loss->reuse_flag);
        $this->assertSame(KitchenLoss::REUSE_STAFF_MEAL, $loss->reuse_destination);
        $this->assertSame('Untouched plate, returned within 5 minutes, kept hot', $loss->food_safety_note);
    }

    public function test_record_converts_grams_to_stock_unit_kg(): void
    {
        // Beef mince is stocked in kg; 1000 g entered must store as 1 kg.
        $loss = $this->service()->record($this->lossData(['quantity' => 1000, 'unit_id' => 2]), User::findOrFail(2));

        $this->assertSame(1.0, (float) $loss->quantity);
        $this->assertSame(1, (int) $loss->unit_id);
        $this->assertSame(800.0, (float) $loss->total_cost);
    }

    public function test_record_blocked_when_batch_quantity_exceeded(): void
    {
        $batch = $this->completedBurgerBatch();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('only holds');

        $this->service()->record($this->lossData([
            'category' => KitchenLoss::CATEGORY_BURNT_SPOILED,
            'product_id' => 14,
            'quantity' => 11,
            'unit_id' => 3,
            'kitchen_production_batch_id' => (int) $batch->id,
        ]), User::findOrFail(2));
    }

    public function test_cross_company_product_is_blocked(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->record($this->lossData(['product_id' => 20]), User::findOrFail(1));
    }

    public function test_loss_numbers_increment_per_company_per_year(): void
    {
        $first = $this->service()->record($this->lossData(), User::findOrFail(2));
        $second = $this->service()->record($this->lossData(['quantity' => 0.5]), User::findOrFail(2));

        $this->assertSame('KL-'.date('Y').'-0001', $first->loss_number);
        $this->assertSame('KL-'.date('Y').'-0002', $second->loss_number);
    }

    // -----------------------------------------------------------------
    // Internal consumption
    // -----------------------------------------------------------------

    public function test_internal_consumption_requires_a_batch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('taken from a prepared batch');

        $this->service()->recordInternalConsumption($this->lossData(['category' => KitchenLoss::CATEGORY_STAFF_MEAL]), User::findOrFail(2));
    }

    public function test_internal_consumption_rejects_waste_categories(): void
    {
        $batch = $this->completedBurgerBatch();

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->recordInternalConsumption($this->lossData([
            'category' => KitchenLoss::CATEGORY_PREPARATION,
            'product_id' => 14,
            'quantity' => 1,
            'unit_id' => 3,
            'kitchen_production_batch_id' => (int) $batch->id,
        ]), User::findOrFail(2));
    }

    public function test_internal_consumption_from_a_batch_needs_approval(): void
    {
        $batch = $this->completedBurgerBatch();

        $loss = $this->service()->recordInternalConsumption($this->lossData([
            'category' => KitchenLoss::CATEGORY_STAFF_MEAL,
            'product_id' => 14,
            'quantity' => 1,
            'unit_id' => 3,
            'kitchen_production_batch_id' => (int) $batch->id,
        ]), User::findOrFail(2));

        $this->assertTrue($loss->isRecorded());
        $this->assertSame((int) $batch->id, (int) $loss->kitchen_production_batch_id);
        $this->assertSame(80.0, (float) $loss->unit_cost);
    }

    // -----------------------------------------------------------------
    // Posting
    // -----------------------------------------------------------------

    public function test_post_batch_loss_consumes_batch_and_writes_off(): void
    {
        $batch = $this->completedBurgerBatch();
        $this->assertSame(10.0, (float) $batch->quantity_remaining);
        $this->assertSame(10.0, $this->warehouseQty(14));

        $loss = $this->service()->record($this->lossData([
            'category' => KitchenLoss::CATEGORY_BURNT_SPOILED,
            'product_id' => 14,
            'quantity' => 2,
            'unit_id' => 3,
            'kitchen_production_batch_id' => (int) $batch->id,
        ]), User::findOrFail(2));

        $posted = $this->service()->post($loss, User::findOrFail(2));

        $this->assertTrue($posted->isPosted());
        $this->assertSame(8.0, (float) $batch->fresh()->quantity_remaining);
        $this->assertSame(8.0, $this->warehouseQty(14));
        $this->assertNotNull($posted->warehouse_loss_id);

        $writeOff = WarehouseLoss::findOrFail((int) $posted->warehouse_loss_id);
        $this->assertSame('WO-000001', $writeOff->write_off_no);
        $this->assertSame(14, (int) $writeOff->product_id);
        $this->assertSame(2.0, (float) $writeOff->quantity);
        $this->assertSame(80.0, (float) $writeOff->unit_cost);
        $this->assertSame(160.0, (float) $writeOff->cost_of_goods);
        $this->assertStringContainsString($posted->loss_number, (string) $writeOff->reason);
    }

    public function test_post_is_idempotent(): void
    {
        $loss = $this->service()->record($this->lossData(), User::findOrFail(2));
        $this->service()->post($loss, User::findOrFail(2));

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->post($loss->fresh(), User::findOrFail(2));
    }

    public function test_post_blocked_on_warehouse_shortage(): void
    {
        // 100 kg of beef mince, but the warehouse only holds 5 kg.
        $loss = $this->service()->record($this->lossData(['quantity' => 100]), User::findOrFail(2));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('only holds');

        $this->service()->post($loss, User::findOrFail(2));
    }

    public function test_post_transfer_moves_stock_between_kitchens(): void
    {
        $loss = $this->service()->record($this->lossData([
            'category' => KitchenLoss::CATEGORY_TRANSFER,
            'quantity' => 1,
            'destination_warehouse_id' => 2,
        ]), User::findOrFail(2));

        $this->assertTrue($loss->isApproved());

        $posted = $this->service()->post($loss, User::findOrFail(2));

        $this->assertTrue($posted->isPosted());
        $this->assertSame(4.0, $this->warehouseQty(12, 1));
        $this->assertSame(1.0, $this->warehouseQty(12, 2));
        $this->assertNull($posted->warehouse_loss_id);
        $this->assertSame(0, WarehouseLoss::count());

        $transfer = WarehouseTransfer::firstOrFail();
        $this->assertSame(1, (int) $transfer->from_warehouse);
        $this->assertSame(2, (int) $transfer->to_warehouse);
        $this->assertSame(12, (int) $transfer->product_id);
        $this->assertSame(1.0, (float) $transfer->quantity);
    }

    public function test_transfer_needs_a_different_destination_warehouse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->record($this->lossData([
            'category' => KitchenLoss::CATEGORY_TRANSFER,
            'destination_warehouse_id' => 1,
        ]), User::findOrFail(2));
    }

    public function test_update_draft_recomputes_cost_and_blocks_posted_losses(): void
    {
        $loss = $this->service()->record($this->lossData(), User::findOrFail(2));

        $updated = $this->service()->updateDraft($loss, $this->lossData(['quantity' => 2]), User::findOrFail(2));

        $this->assertSame(2.0, (float) $updated->quantity);
        $this->assertSame(1600.0, (float) $updated->total_cost);

        $this->service()->post($updated, User::findOrFail(2));

        $this->expectException(\InvalidArgumentException::class);

        $this->service()->updateDraft($updated->fresh(), $this->lossData(['quantity' => 3]), User::findOrFail(2));
    }

    // -----------------------------------------------------------------
    // Reconciliation (plan §9)
    // -----------------------------------------------------------------

    public function test_reconciliation_produced_minus_sold_minus_lost_equals_remaining(): void
    {
        $batch = $this->completedBurgerBatch();

        // A POS sale of 3 portions: the batch and the warehouse both fall.
        $batch->consume(3);
        DB::table('warehouse_products')->where('product_id', 14)->where('warehouse_id', 1)->update(['quantity' => 7]);
        DB::table('kitchen_pos_batch_consumptions')->insert([
            'pos_product_id' => null,
            'kitchen_production_batch_id' => (int) $batch->id,
            'product_id' => 14,
            'quantity' => 3,
            'quantity_restored' => 0,
            'warehouse_id' => 1,
            'created_by' => 1,
        ]);

        // A posted loss of 2 portions from the same batch.
        $loss = $this->service()->record($this->lossData([
            'category' => KitchenLoss::CATEGORY_BURNT_SPOILED,
            'product_id' => 14,
            'quantity' => 2,
            'unit_id' => 3,
            'kitchen_production_batch_id' => (int) $batch->id,
        ]), User::findOrFail(2));
        $this->service()->post($loss, User::findOrFail(2));

        $recon = $this->service()->reconciliation($batch->fresh(), 1);

        $this->assertSame(10.0, $recon['produced']);
        $this->assertSame(3.0, $recon['sold']);
        $this->assertSame(2.0, $recon['lost']);
        $this->assertSame(5.0, $recon['remaining']);
        $this->assertTrue($recon['balanced']);
        $this->assertSame(0.0, $recon['difference']);
    }

    public function test_reconciliation_flags_a_batch_that_does_not_reconcile(): void
    {
        $batch = $this->completedBurgerBatch();
        $batch->update(['quantity_remaining' => 4]);

        $recon = $this->service()->reconciliation($batch->fresh(), 1);

        $this->assertFalse($recon['balanced']);
        $this->assertSame(6.0, $recon['difference']);
    }

    // -----------------------------------------------------------------
    // POS cancellation link (Phase 2 reuse)
    // -----------------------------------------------------------------

    public function test_pos_cancellation_loss_links_to_kitchen_loss(): void
    {
        $batch = $this->completedBurgerBatch();

        // A POS sale of 2 portions, then the line is cancelled after
        // preparation: the food is wasted, the stock stays deducted.
        $batch->consume(2);
        DB::table('warehouse_products')->where('product_id', 14)->where('warehouse_id', 1)->update(['quantity' => 8]);

        $pos = Pos::create(['pos_id' => 1, 'warehouse_id' => 1, 'created_by' => 1]);
        $line = PosProduct::create([
            'pos_id' => (int) $pos->id,
            'product_id' => 14,
            'warehouse_id' => 1,
            'quantity' => 2,
            'price' => 500,
            'stock_deducted_at' => now(),
        ]);
        DB::table('kitchen_pos_batch_consumptions')->insert([
            'pos_product_id' => (int) $line->id,
            'kitchen_production_batch_id' => (int) $batch->id,
            'product_id' => 14,
            'quantity' => 2,
            'quantity_restored' => 0,
            'warehouse_id' => 1,
            'created_by' => 1,
        ]);
        $writeOff = WarehouseLoss::create([
            'write_off_no' => 'WO-000001',
            'warehouse_id' => 1,
            'product_id' => 14,
            'quantity' => 2,
            'unit_cost' => 80,
            'cost_of_goods' => 160,
            'date' => date('Y-m-d'),
            'reason' => 'Kitchen loss after preparation',
            'created_by' => 1,
        ]);

        $loss = $this->service()->linkPosCancellationLoss($writeOff, $pos, $line, 1, 'disposal', User::findOrFail(3));

        $this->assertNotNull($loss);
        $this->assertSame(KitchenLoss::CATEGORY_BURNT_SPOILED, $loss->category);
        $this->assertTrue($loss->isPosted());
        $this->assertTrue((bool) $loss->stock_already_deducted);
        $this->assertSame(2.0, (float) $loss->quantity);
        $this->assertSame((int) $batch->id, (int) $loss->kitchen_production_batch_id);
        $this->assertSame((int) $pos->id, (int) $loss->pos_id);
        $this->assertSame((int) $line->id, (int) $loss->pos_product_id);
        $this->assertSame((int) $writeOff->id, (int) $loss->warehouse_loss_id);

        // The cancellation loss is already inside "sold" (the line
        // consumed the batch), so it must not double-count as "lost".
        $recon = $this->service()->reconciliation($batch->fresh(), 1);
        $this->assertSame(10.0, $recon['produced']);
        $this->assertSame(2.0, $recon['sold']);
        $this->assertSame(0.0, $recon['lost']);
        $this->assertSame(8.0, $recon['remaining']);
        $this->assertTrue($recon['balanced']);
    }

    public function test_pos_cancellation_staff_meal_maps_to_staff_meal_category(): void
    {
        $batch = $this->completedBurgerBatch();
        $pos = Pos::create(['pos_id' => 2, 'warehouse_id' => 1, 'created_by' => 1]);
        $line = PosProduct::create([
            'pos_id' => (int) $pos->id,
            'product_id' => 14,
            'warehouse_id' => 1,
            'quantity' => 1,
            'price' => 500,
            'stock_deducted_at' => now(),
        ]);
        DB::table('kitchen_pos_batch_consumptions')->insert([
            'pos_product_id' => (int) $line->id,
            'kitchen_production_batch_id' => (int) $batch->id,
            'product_id' => 14,
            'quantity' => 1,
            'quantity_restored' => 0,
            'warehouse_id' => 1,
            'created_by' => 1,
        ]);
        $writeOff = WarehouseLoss::create([
            'write_off_no' => 'WO-000001',
            'warehouse_id' => 1,
            'product_id' => 14,
            'quantity' => 1,
            'unit_cost' => 80,
            'cost_of_goods' => 80,
            'date' => date('Y-m-d'),
            'reason' => 'Staff meal',
            'created_by' => 1,
        ]);

        $loss = $this->service()->linkPosCancellationLoss($writeOff, $pos, $line, 1, 'staff_meal', User::findOrFail(3));

        $this->assertNotNull($loss);
        $this->assertSame(KitchenLoss::CATEGORY_STAFF_MEAL, $loss->category);
        $this->assertTrue((bool) $loss->reuse_flag);
        $this->assertSame('staff_meal', $loss->reuse_destination);
    }
}
