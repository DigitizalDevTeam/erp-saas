<?php

namespace Tests\Unit;

use App\Models\KitchenProductionOrder;
use App\Models\KitchenProductionOrderLine;
use App\Models\KitchenProductionPlan;
use App\Models\KitchenRecipe;
use App\Models\StockReport;
use App\Models\User;
use App\Models\WarehouseProduct;
use App\Models\WarehouseTransfer;
use App\Services\KitchenOccupancyService;
use App\Services\KitchenProductionOrderService;
use App\Services\KitchenProductionPlanService;
use App\Services\KitchenRecipeCostService;
use App\Services\KitchenRequisitionService;
use App\Services\KitchenUnitConversionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KitchenRequisitionServiceTest extends TestCase
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
            ['id' => 11, 'name' => 'Uncooked Rice', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 100, 'unit_id' => 1, 'quantity' => 10, 'created_by' => 1],
            ['id' => 12, 'name' => 'Beef Mince', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 800, 'unit_id' => 1, 'quantity' => 5, 'created_by' => 1],
            ['id' => 14, 'name' => 'Beef Burger', 'type' => 'product', 'sale_price' => 500, 'sale_price_type' => 'exclusive', 'purchase_price' => 0, 'unit_id' => 3, 'quantity' => 0, 'created_by' => 1],
        ]);
        DB::table('warehouses')->insert([
            ['id' => 1, 'name' => 'COZINHA', 'created_by' => 1],
            ['id' => 2, 'name' => 'ARMAZEM GERAL', 'created_by' => 1],
        ]);
        DB::table('warehouse_products')->insert([
            ['warehouse_id' => 1, 'product_id' => 12, 'quantity' => 1, 'created_by' => 1],
            ['warehouse_id' => 2, 'product_id' => 12, 'quantity' => 10, 'created_by' => 1],
        ]);
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Chef', 'email' => 'chef@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Manager', 'email' => 'manager@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);
        DB::table('hotel_reservations')->insert([
            'id' => 1, 'reservation_no' => 'RES-1', 'branch_id' => null,
            'check_in_date' => '2026-11-09', 'check_out_date' => '2026-11-12',
            'adults' => 2, 'extra_adults' => 0, 'children' => 1, 'infants' => 0,
            'meal_plan' => 'half_board', 'status' => 'confirmed', 'created_by' => 1,
        ]);

        $this->makeRecipe('KR-2026-0002', 'Beef Burger', 14, 10, 3, 1, 3, [
            ['ingredient_product_id' => 12, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['users', 'hotel_reservations', 'branches', 'warehouses', 'stock_reports', 'warehouse_products', 'warehouse_transfers', 'kitchen_requisition_lines', 'kitchen_requisitions', 'kitchen_production_order_lines', 'kitchen_production_orders', 'kitchen_production_plan_lines', 'kitchen_production_plans', 'product_recipe_alternatives', 'product_recipes', 'kitchen_unit_conversions', 'kitchen_recipes', 'product_services', 'product_service_units'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function service(): KitchenRequisitionService
    {
        $conversions = new KitchenUnitConversionService();
        $orders = new KitchenProductionOrderService($conversions, new KitchenRecipeCostService($conversions));
        $plans = new KitchenProductionPlanService(new KitchenOccupancyService(), $orders);

        return new KitchenRequisitionService($plans, $orders);
    }

    private function planService(): KitchenProductionPlanService
    {
        $conversions = new KitchenUnitConversionService();
        $orders = new KitchenProductionOrderService($conversions, new KitchenRecipeCostService($conversions));

        return new KitchenProductionPlanService(new KitchenOccupancyService(), $orders);
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

    private function requisitionData(): array
    {
        return [
            'source_warehouse_id' => 2,
            'kitchen_warehouse_id' => 1,
            'branch_id' => null,
            'notes' => null,
        ];
    }

    private function requestedRequisition(): \App\Models\KitchenRequisition
    {
        return $this->service()->create($this->requisitionData(), [
            ['ingredient_product_id' => 12, 'required_quantity' => 5, 'unit_id' => 1, 'unit_cost' => 800],
        ], User::findOrFail(2));
    }

    private function approvedRequisition(): \App\Models\KitchenRequisition
    {
        $requisition = $this->requestedRequisition();

        return $this->service()->approve($requisition, User::findOrFail(3));
    }

    private function kitchenQty(int $productId, int $warehouseId): float
    {
        return (float) WarehouseProduct::where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->sum('quantity');
    }

    // --- Creation ------------------------------------------------------

    public function test_create_allocates_number_and_computes_availability_and_shortage(): void
    {
        $requisition = $this->requestedRequisition();

        $this->assertMatchesRegularExpression('/^RQ-\d{4}-0001$/', $requisition->requisition_number);
        $this->assertTrue($requisition->isRequested());
        $this->assertSame(2, (int) $requisition->requested_by);

        // Kitchen holds 1 kg of beef; 5 kg requested -> 4 kg short.
        $line = $requisition->lines->firstOrFail();
        $this->assertSame(5.0, (float) $line->required_quantity);
        $this->assertSame(1.0, (float) $line->available_quantity);
        $this->assertSame(4.0, (float) $line->shortage_quantity);
        $this->assertSame(0.0, (float) $line->issued_quantity);
    }

    public function test_create_rejects_same_source_and_kitchen_warehouse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->create(array_merge($this->requisitionData(), [
            'source_warehouse_id' => 1,
        ]), [
            ['ingredient_product_id' => 12, 'required_quantity' => 5, 'unit_id' => 1],
        ], User::findOrFail(2));
    }

    public function test_create_without_lines_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->create($this->requisitionData(), [], User::findOrFail(2));
    }

    // --- Approval / rejection -------------------------------------------

    public function test_requester_cannot_approve_their_own_requisition(): void
    {
        $requisition = $this->requestedRequisition();

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->approve($requisition, User::findOrFail(2));
    }

    public function test_approve_by_another_user_marks_it_approved(): void
    {
        $requisition = $this->approvedRequisition();

        $this->assertTrue($requisition->isApproved());
        $this->assertSame(3, (int) $requisition->approved_by);
        $this->assertNotNull($requisition->approved_at);
    }

    public function test_requester_cannot_reject_their_own_requisition(): void
    {
        $requisition = $this->requestedRequisition();

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->reject($requisition, User::findOrFail(2), 'No.');
    }

    public function test_reject_by_another_user_marks_it_rejected(): void
    {
        $requisition = $this->requestedRequisition();
        $requisition = $this->service()->reject($requisition, User::findOrFail(3), 'Out of season.');

        $this->assertTrue($requisition->isRejected());
        $this->assertSame('Out of season.', $requisition->rejection_note);
    }

    // --- Issue ----------------------------------------------------------

    public function test_issue_in_full_moves_stock_and_writes_the_transfer_ledger(): void
    {
        $requisition = $this->approvedRequisition();
        $line = $requisition->lines->firstOrFail();

        $requisition = $this->service()->issue($requisition, User::findOrFail(3), [
            (int) $line->id => 4.0,
        ]);

        $this->assertTrue($requisition->isIssued());
        $this->assertSame(3, (int) $requisition->issued_by);
        $this->assertSame(4.0, (float) $requisition->lines->firstOrFail()->issued_quantity);

        // Source 10 -> 6, kitchen 1 -> 5.
        $this->assertSame(6.0, $this->kitchenQty(12, 2));
        $this->assertSame(5.0, $this->kitchenQty(12, 1));

        // One WarehouseTransfer row and both ledger rows.
        $this->assertSame(1, WarehouseTransfer::where('product_id', 12)->count());
        $transfer = WarehouseTransfer::where('product_id', 12)->firstOrFail();
        $this->assertSame(2, (int) $transfer->from_warehouse);
        $this->assertSame(1, (int) $transfer->to_warehouse);
        $this->assertSame(4.0, (float) $transfer->quantity);
        $this->assertSame(1, StockReport::where('product_id', 12)->where('type', 'transfer_out')->count());
        $this->assertSame(1, StockReport::where('product_id', 12)->where('type', 'transfer_in')->count());
    }

    public function test_issue_in_parts_then_the_remainder_completes_it(): void
    {
        $requisition = $this->approvedRequisition();
        $line = $requisition->lines->firstOrFail();

        $requisition = $this->service()->issue($requisition, User::findOrFail(3), [
            (int) $line->id => 2.0,
        ]);
        $this->assertTrue($requisition->isPartiallyIssued());
        $this->assertSame(2.0, (float) $requisition->lines->firstOrFail()->issued_quantity);
        $this->assertSame(3.0, $this->kitchenQty(12, 1));

        $requisition = $this->service()->issue($requisition->fresh(), User::findOrFail(3), [
            (int) $line->id => 2.0,
        ]);
        $this->assertTrue($requisition->isIssued());
        $this->assertSame(5.0, $this->kitchenQty(12, 1));
    }

    public function test_issue_more_than_remaining_throws_and_moves_nothing(): void
    {
        $requisition = $this->approvedRequisition();
        $line = $requisition->lines->firstOrFail();

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->issue($requisition, User::findOrFail(3), [
            (int) $line->id => 5.0,
        ]);

        $this->assertSame(10.0, $this->kitchenQty(12, 2));
        $this->assertSame(1.0, $this->kitchenQty(12, 1));
    }

    public function test_issue_more_than_source_holds_throws(): void
    {
        // Kitchen needs 4 kg, but the source only holds 2 kg.
        DB::table('warehouse_products')
            ->where('warehouse_id', 2)->where('product_id', 12)
            ->update(['quantity' => 2]);

        $requisition = $this->approvedRequisition();
        $line = $requisition->lines->firstOrFail();

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->issue($requisition, User::findOrFail(3), [
            (int) $line->id => 4.0,
        ]);
    }

    public function test_issue_with_no_quantities_throws(): void
    {
        $requisition = $this->approvedRequisition();

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->issue($requisition, User::findOrFail(3), []);
    }

    public function test_issue_of_a_requested_requisition_throws(): void
    {
        $requisition = $this->requestedRequisition();
        $line = $requisition->lines->firstOrFail();

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->issue($requisition, User::findOrFail(3), [
            (int) $line->id => 1.0,
        ]);
    }

    // --- From plan / from order ------------------------------------------

    public function test_create_from_plan_builds_lines_from_shortages_only(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0002')->firstOrFail();
        $plan = $this->planService()->create([
            'plan_date' => '2026-11-10',
            'meal_service' => 'dinner',
            'branch_id' => null,
            'warehouse_id' => 1,
            'section' => 'hot_kitchen',
            'notes' => null,
            'lines' => [
                ['kitchen_recipe_id' => $recipe->id, 'planned_quantity' => 20, 'planned_unit_id' => 3, 'source' => 'occupancy', 'notes' => null],
            ],
        ], User::findOrFail(2));

        // 20 burgers need 2 kg of beef; the kitchen holds 1 kg -> 1 kg short.
        $requisition = $this->service()->createFromPlan($plan, 2, User::findOrFail(2));

        $this->assertSame((int) $plan->id, (int) $requisition->kitchen_production_plan_id);
        $this->assertCount(1, $requisition->lines);
        $line = $requisition->lines->firstOrFail();
        $this->assertSame(12, (int) $line->ingredient_product_id);
        $this->assertSame(2.0, (float) $line->required_quantity);
        $this->assertSame(1.0, (float) $line->available_quantity);
        $this->assertSame(1.0, (float) $line->shortage_quantity);
    }

    public function test_create_from_plan_with_no_shortages_throws(): void
    {
        $recipe = KitchenRecipe::where('code', 'KR-2026-0002')->firstOrFail();
        $plan = $this->planService()->create([
            'plan_date' => '2026-11-10',
            'meal_service' => 'dinner',
            'branch_id' => null,
            'warehouse_id' => 1,
            'section' => 'hot_kitchen',
            'notes' => null,
            'lines' => [
                ['kitchen_recipe_id' => $recipe->id, 'planned_quantity' => 5, 'planned_unit_id' => 3, 'source' => 'occupancy', 'notes' => null],
            ],
        ], User::findOrFail(2));

        // 5 burgers need 0.5 kg; the kitchen holds 1 kg -> nothing short.
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->createFromPlan($plan, 2, User::findOrFail(2));
    }

    public function test_create_from_order_builds_lines_from_order_shortages(): void
    {
        $order = KitchenProductionOrder::create([
            'kitchen_recipe_id' => KitchenRecipe::where('code', 'KR-2026-0002')->firstOrFail()->id,
            'recipe_version' => 1,
            'production_date' => '2026-11-10',
            'warehouse_id' => 1,
            'planned_quantity' => 20,
            'planned_unit_id' => 3,
            'status' => KitchenProductionOrder::STATUS_DRAFT,
            'submitted_by' => 2,
            'created_by' => 1,
        ]);
        KitchenProductionOrderLine::create([
            'kitchen_production_order_id' => (int) $order->id,
            'ingredient_product_id' => 12,
            'planned_quantity' => 2,
            'planned_unit_id' => 1,
            'planned_unit_cost' => 800,
            'planned_cost' => 1600,
            'loss_percent' => 0,
            'created_by' => 1,
        ]);

        // Order needs 2 kg of beef; the kitchen holds 1 kg -> 1 kg short.
        $requisition = $this->service()->createFromOrder($order, 2, User::findOrFail(2));

        $this->assertSame((int) $order->id, (int) $requisition->kitchen_production_order_id);
        $this->assertCount(1, $requisition->lines);
        $line = $requisition->lines->firstOrFail();
        $this->assertSame(2.0, (float) $line->required_quantity);
        $this->assertSame(1.0, (float) $line->available_quantity);
        $this->assertSame(1.0, (float) $line->shortage_quantity);
    }

    // --- Edit ------------------------------------------------------------

    public function test_update_draft_recomputes_shortage(): void
    {
        $requisition = $this->requestedRequisition();

        $requisition = $this->service()->updateDraft($requisition, array_merge($this->requisitionData(), [
            'notes' => 'Updated',
        ]), [
            ['ingredient_product_id' => 12, 'required_quantity' => 2, 'unit_id' => 1, 'unit_cost' => 800],
        ], User::findOrFail(2));

        // Kitchen holds 1 kg; 2 kg requested -> 1 kg short.
        $line = $requisition->lines->firstOrFail();
        $this->assertSame(2.0, (float) $line->required_quantity);
        $this->assertSame(1.0, (float) $line->shortage_quantity);
        $this->assertSame('Updated', $requisition->notes);
    }
}
