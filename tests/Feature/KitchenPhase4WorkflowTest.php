<?php

namespace Tests\Feature;

use App\Models\KitchenBuffetEvent;
use App\Models\KitchenBuffetEventLine;
use App\Models\KitchenDailySpecial;
use App\Models\KitchenLoss;
use App\Models\KitchenRecipe;
use App\Models\ProductRecipe;
use App\Models\ProductService;
use App\Models\StockReport;
use App\Models\User;
use App\Services\KitchenBuffetService;
use App\Services\KitchenDailySpecialService;
use App\Services\PosRecipeDeductionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Kitchen Production Phase 4 — Buffet events + Daily Specials.
 *
 * (a) Buffet: create from manual headcount and from hotel occupancy,
 *     prepare deducts exactly the planned quantities (FEFO path),
 *     close reconciles prepared = used + leftover + waste, leftovers
 *     return to stock and are recorded as a buffet-leftover loss with
 *     the reuse flag, waste is written off through a kitchen loss.
 * (b) Daily Specials: 2–3 specials for one date, each lives for its
 *     date only, a weekly plan sets Mon–Fri and leaves Sat/Sun empty,
 *     a special is sellable through the POS path that day.
 * (c) On-the-spot special: an ad-hoc dish with ingredients entered at
 *     creation is sellable immediately and its ingredients are
 *     deducted on sale through the POS recipe ladder.
 * (d) Tenant isolation: a second company sees none of the first
 *     company's buffet events or specials.
 * (e) No per-dish POS deduction happens for buffet consumption.
 */
class KitchenPhase4WorkflowTest extends TestCase
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
            $table->string('sku')->nullable();
            $table->string('type', 20)->nullable();
            $table->decimal('sale_price', 15, 4)->default(0);
            $table->string('sale_price_type', 20)->nullable();
            $table->decimal('purchase_price', 15, 4)->default(0);
            $table->decimal('quantity', 15, 4)->default(0);
            $table->integer('tax_id')->nullable();
            $table->integer('category_id')->nullable();
            $table->integer('unit_id')->nullable();
            $table->integer('show_on_pos')->default(1);
            $table->integer('affect_stock')->default(1);
            $table->integer('allow_negative_stock')->default(0);
            $table->integer('batch_tracking')->default(0);
            $table->integer('identifier_tracking')->default(0);
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

        Schema::create('product_expiry_lots', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('warehouse_id')->nullable();
            $table->integer('purchase_id')->nullable();
            $table->integer('purchase_product_id')->nullable();
            $table->string('source', 20)->default('purchase');
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity_in', 16, 4)->default(0);
            $table->decimal('quantity_remaining', 16, 4)->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('kitchen_buffet_events', function ($table) {
            $table->increments('id');
            $table->string('event_number', 30);
            $table->date('event_date');
            $table->string('meal_service', 20)->nullable();
            $table->string('name')->nullable();
            $table->string('event_link')->nullable();
            $table->integer('branch_id')->nullable();
            $table->integer('warehouse_id');
            $table->string('covers_source', 20)->default('manual');
            $table->integer('expected_covers');
            $table->text('occupancy_snapshot')->nullable();
            $table->decimal('price_per_person', 15, 4)->nullable();
            $table->string('status', 20)->default('draft');
            $table->integer('prepared_by')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->integer('closed_by')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('kitchen_buffet_event_lines', function ($table) {
            $table->increments('id');
            $table->integer('kitchen_buffet_event_id');
            $table->integer('product_id');
            $table->integer('kitchen_recipe_id')->nullable();
            $table->integer('unit_id')->nullable();
            $table->decimal('per_cover_quantity', 12, 4)->nullable();
            $table->decimal('planned_quantity', 12, 4);
            $table->decimal('prepared_quantity', 12, 4)->nullable();
            $table->decimal('used_quantity', 12, 4)->nullable();
            $table->decimal('leftover_quantity', 12, 4)->nullable();
            $table->decimal('waste_quantity', 12, 4)->nullable();
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->decimal('total_cost', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('kitchen_daily_specials', function ($table) {
            $table->increments('id');
            $table->date('special_date');
            $table->integer('product_id');
            $table->integer('kitchen_recipe_id')->nullable();
            $table->integer('source_product_id')->nullable();
            $table->decimal('quantity_planned', 12, 4);
            $table->decimal('selling_price', 15, 4);
            $table->string('status', 20)->default('active');
            $table->boolean('is_on_the_spot')->default(false);
            $table->text('notes')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('kitchen_recipes', function ($table) {
            $table->increments('id');
            $table->string('code', 50);
            $table->string('name');
            $table->integer('product_id');
            $table->integer('category_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('draft');
            $table->decimal('expected_yield_quantity', 12, 4)->nullable();
            $table->integer('expected_yield_unit_id')->nullable();
            $table->decimal('standard_portion_quantity', 12, 4)->nullable();
            $table->integer('standard_portion_unit_id')->nullable();
            $table->unsignedInteger('preparation_time_minutes')->nullable();
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
        });

        Schema::create('product_recipes', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('kitchen_recipe_id')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->integer('ingredient_product_id');
            $table->decimal('quantity', 16, 4)->default(1);
            $table->decimal('loss_percent', 5, 2)->default(0);
            $table->string('unit')->nullable();
            $table->integer('unit_id')->nullable();
            $table->integer('created_by')->default(0);
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
            $table->integer('kitchen_buffet_event_id')->nullable();
            $table->integer('kitchen_buffet_event_line_id')->nullable();
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
            $table->string('source')->default('manual');
            $table->string('status')->default('pending');
            $table->string('meal_plan')->nullable();
            $table->decimal('rate_per_night', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
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
        foreach (['manage kitchen buffets', 'manage kitchen daily specials'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('product_service_units')->insert([
            ['id' => 1, 'name' => 'Kg', 'created_by' => 1],
            ['id' => 2, 'name' => 'g', 'created_by' => 1],
        ]);
        // 12 Beef Mince (ingredient, 800/kg) · 13 Leaves (ingredient,
        // 200/kg) · 14 Roast Beef (dish, recipe) · 15 Burger (menu dish)
        // · 16 Fish · 17 Water · 18 Salt (on-the-spot ingredients).
        DB::table('product_services')->insert([
            ['id' => 12, 'name' => 'Beef Mince', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 800, 'quantity' => 10, 'unit_id' => 1, 'show_on_pos' => 0, 'created_by' => 1],
            ['id' => 13, 'name' => 'Leaves', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 200, 'quantity' => 20, 'unit_id' => 1, 'show_on_pos' => 0, 'created_by' => 1],
            ['id' => 14, 'name' => 'Roast Beef', 'type' => 'product', 'sale_price' => 1500, 'sale_price_type' => 'exclusive', 'purchase_price' => 800, 'quantity' => 0, 'unit_id' => 1, 'show_on_pos' => 1, 'created_by' => 1],
            ['id' => 15, 'name' => 'Burger', 'type' => 'product', 'sale_price' => 500, 'sale_price_type' => 'exclusive', 'purchase_price' => 80, 'quantity' => 0, 'unit_id' => 1, 'show_on_pos' => 1, 'created_by' => 1],
            ['id' => 16, 'name' => 'Fish', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 600, 'quantity' => 5, 'unit_id' => 1, 'show_on_pos' => 0, 'created_by' => 1],
            ['id' => 17, 'name' => 'Water', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 10, 'quantity' => 50, 'unit_id' => 1, 'show_on_pos' => 0, 'created_by' => 1],
            ['id' => 18, 'name' => 'Salt', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 50, 'quantity' => 2, 'unit_id' => 1, 'show_on_pos' => 0, 'created_by' => 1],
        ]);
        DB::table('warehouses')->insert([
            ['id' => 1, 'name' => 'COZINHA', 'created_by' => 1],
        ]);
        DB::table('warehouse_products')->insert([
            ['warehouse_id' => 1, 'product_id' => 12, 'quantity' => 10, 'created_by' => 1],
            ['warehouse_id' => 1, 'product_id' => 13, 'quantity' => 20, 'created_by' => 1],
            ['warehouse_id' => 1, 'product_id' => 14, 'quantity' => 0, 'created_by' => 1],
            ['warehouse_id' => 1, 'product_id' => 15, 'quantity' => 0, 'created_by' => 1],
            ['warehouse_id' => 1, 'product_id' => 16, 'quantity' => 5, 'created_by' => 1],
            ['warehouse_id' => 1, 'product_id' => 17, 'quantity' => 50, 'created_by' => 1],
            ['warehouse_id' => 1, 'product_id' => 18, 'quantity' => 2, 'created_by' => 1],
        ]);

        // Approved Roast Beef recipe: one batch yields 10 kg and uses
        // 10 kg Beef Mince, so 1 kg of roast beef costs 1 kg of mince.
        DB::table('kitchen_recipes')->insert([
            ['id' => 1, 'code' => 'KR-2026-0001', 'name' => 'Roast Beef', 'product_id' => 14, 'version' => 1, 'status' => KitchenRecipe::STATUS_APPROVED, 'expected_yield_quantity' => 10, 'expected_yield_unit_id' => 1, 'standard_portion_quantity' => 1, 'standard_portion_unit_id' => 1, 'loss_percent' => 0, 'created_by' => 1],
        ]);
        DB::table('product_recipes')->insert([
            ['id' => 1, 'product_id' => 14, 'kitchen_recipe_id' => 1, 'version' => 1, 'ingredient_product_id' => 12, 'quantity' => 10, 'loss_percent' => 0, 'unit_id' => 1, 'created_by' => 1],
            // Burger: 0.1 kg Beef Mince per burger.
            ['id' => 2, 'product_id' => 15, 'kitchen_recipe_id' => null, 'version' => 1, 'ingredient_product_id' => 12, 'quantity' => 0.1, 'loss_percent' => 0, 'unit_id' => 1, 'created_by' => 1],
        ]);

        // One confirmed full-board reservation in-house for three
        // nights: 30 adults + 10 children = 40 guests, 40 covers per
        // meal; 2 infants eat no cover.
        DB::table('hotel_reservations')->insert([
            ['id' => 1, 'reservation_no' => 'R-1', 'hotel_guest_id' => 1, 'hotel_room_type_id' => 1, 'check_in_date' => date('Y-m-d'), 'check_out_date' => date('Y-m-d', strtotime('+3 days')), 'adults' => 30, 'extra_adults' => 0, 'children' => 10, 'infants' => 2, 'source' => 'manual', 'status' => 'confirmed', 'meal_plan' => 'full_board', 'created_by' => 1],
        ]);

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Chef', 'email' => 'chef@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['manage kitchen buffets', 'manage kitchen daily specials']);
        User::findOrFail(2)->givePermissionTo(['manage kitchen buffets', 'manage kitchen daily specials']);
        User::findOrFail(4)->givePermissionTo(['manage kitchen buffets', 'manage kitchen daily specials']);
    }

    protected function tearDown(): void
    {
        foreach (['role_has_permissions', 'model_has_roles', 'model_has_permissions', 'permissions', 'roles', 'plans', 'email_templates', 'languages', 'ch_messages', 'branches', 'branch_user', 'settings', 'users', 'hotel_reservations', 'warehouse_transfers', 'warehouse_losses', 'kitchen_losses', 'product_recipes', 'kitchen_recipes', 'kitchen_daily_specials', 'kitchen_buffet_event_lines', 'kitchen_buffet_events', 'product_expiry_lots', 'stock_reports', 'warehouse_products', 'warehouses', 'product_services', 'product_service_units'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function warehouseQty(int $productId): float
    {
        return (float) DB::table('warehouse_products')->where('product_id', $productId)->where('warehouse_id', 1)->sum('quantity');
    }

    private function globalQty(int $productId): float
    {
        return (float) ProductService::findOrFail($productId)->quantity;
    }

    private function buffetPayload(array $overrides = []): array
    {
        return array_merge([
            'event_date' => date('Y-m-d'),
            'meal_service' => 'lunch',
            'name' => 'Sunday buffet',
            'event_link' => '',
            'branch_id' => '',
            'warehouse_id' => 1,
            'covers_source' => 'manual',
            'expected_covers' => 50,
            'price_per_person' => 1200,
            'notes' => '',
            'lines' => [
                ['product_id' => 13, 'kitchen_recipe_id' => '', 'unit_id' => 1, 'per_cover_quantity' => 0.1, 'planned_quantity' => '', 'notes' => ''],
            ],
        ], $overrides);
    }

    public function test_buffet_manual_headcount_direct_lines_reconcile_and_return_leftovers(): void
    {
        $today = date('Y-m-d');

        // Create: 50 covers, 0.1 kg leaves per cover = 5 kg planned.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen-buffet-events.store'), $this->buffetPayload())
            ->assertRedirect();

        $event = KitchenBuffetEvent::firstOrFail();
        $this->assertSame('BF-'.date('Y').'-0001', $event->event_number);
        $this->assertTrue($event->isDraft());
        $this->assertSame(50, (int) $event->expected_covers);
        $this->assertSame(5.0, (float) $event->lines()->firstOrFail()->planned_quantity);

        // The show page renders the draft.
        $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen-buffet-events.show', $event->id))
            ->assertOk()
            ->assertSee($event->event_number)
            ->assertSee('Leaves');

        // Prepare: the 5 kg of leaves leave the warehouse.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen-buffet-events.prepare', $event->id))
            ->assertRedirect(route('kitchen-buffet-events.show', $event->id));

        $prepared = $event->fresh();
        $this->assertTrue($prepared->isPrepared());
        $this->assertSame(15.0, $this->warehouseQty(13));
        $this->assertSame(15.0, $this->globalQty(13));
        $line = $prepared->lines()->firstOrFail();
        $this->assertSame(5.0, (float) $line->prepared_quantity);
        $this->assertSame(200.0, (float) $line->unit_cost);
        $this->assertSame(1000.0, (float) $line->total_cost);

        // Close: used 3 + leftover 1.5 + waste 0.5 = prepared 5.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen-buffet-events.close', $event->id), [
                'lines' => [(int) $line->id => ['used_quantity' => 3, 'leftover_quantity' => 1.5, 'waste_quantity' => 0.5]],
                'food_safety_note' => 'Kept chilled at 4C, safe until tomorrow lunch.',
            ])
            ->assertRedirect(route('kitchen-buffet-events.show', $event->id));

        $closed = $event->fresh();
        $this->assertTrue($closed->isCompleted());
        $summary = $closed->summary();
        $this->assertSame(5.0, $summary['prepared']);
        $this->assertSame(3.0, $summary['used']);
        $this->assertSame(1.5, $summary['leftover']);
        $this->assertSame(0.5, $summary['waste']);
        $this->assertTrue($summary['balanced']);

        // The 1.5 kg leftover came back: 15 + 1.5 = 16.5.
        $this->assertSame(16.5, $this->warehouseQty(13));
        $this->assertSame(16.5, $this->globalQty(13));

        // Waste is written off through a kitchen loss (posted).
        $waste = KitchenLoss::where('category', KitchenLoss::CATEGORY_BURNT_SPOILED)->firstOrFail();
        $this->assertSame(0.5, (float) $waste->quantity);
        $this->assertTrue($waste->isPosted());
        $this->assertTrue((bool) $waste->stock_already_deducted);
        $this->assertSame((int) $event->id, (int) $waste->kitchen_buffet_event_id);
        $this->assertSame((int) $line->id, (int) $waste->kitchen_buffet_event_line_id);
        $this->assertNotNull($waste->warehouse_loss_id);

        // Leftovers are recorded as a buffet-leftover loss with the
        // reuse flag — recorded, never posted (nothing was written off).
        $leftover = KitchenLoss::where('category', KitchenLoss::CATEGORY_BUFFET_LEFTOVER)->firstOrFail();
        $this->assertSame(1.5, (float) $leftover->quantity);
        $this->assertTrue((bool) $leftover->reuse_flag);
        $this->assertSame('Kept chilled at 4C, safe until tomorrow lunch.', $leftover->food_safety_note);
        $this->assertSame(KitchenLoss::STATUS_APPROVED, $leftover->status);
        $this->assertNull($leftover->posted_at);
        $this->assertSame((int) $event->id, (int) $leftover->kitchen_buffet_event_id);

        // (e) No per-dish POS deduction: the only stock movements are
        // the buffet's own (issue 5 kg, return 1.5 kg) — nothing typed
        // 'pos', and no other loss exists.
        $this->assertSame(2, StockReport::count());
        $this->assertSame(0, StockReport::where('type', 'pos')->count());
        $this->assertTrue(StockReport::where('type', 'kitchen_buffet')->exists());
        $this->assertSame(2, KitchenLoss::count());

        $this->actingAs(User::findOrFail(2))
            ->get(route('kitchen-buffet-events.show', $event->id))
            ->assertOk()
            ->assertSee('Reconciled');
    }

    public function test_buffet_from_occupancy_with_recipe_line(): void
    {
        $today = date('Y-m-d');
        $chef = User::findOrFail(2);
        $service = app(KitchenBuffetService::class);

        // 40 guests in-house on full board = 40 lunch covers.
        $event = $service->create([
            'event_date' => $today,
            'meal_service' => 'lunch',
            'name' => 'Conference lunch',
            'event_link' => 'CONF-42',
            'warehouse_id' => 1,
            'covers_source' => KitchenBuffetEvent::COVERS_OCCUPANCY,
            'price_per_person' => 1500,
            'lines' => [
                ['product_id' => 14, 'kitchen_recipe_id' => 1, 'unit_id' => 1, 'per_cover_quantity' => 0.1, 'planned_quantity' => null],
            ],
        ], $chef);

        $this->assertSame(40, (int) $event->expected_covers);
        $this->assertSame(40, (int) ($event->occupancy_snapshot['total_guests'] ?? 0));
        $this->assertSame('BF-'.date('Y').'-0001', $event->event_number);
        $line = $event->lines()->firstOrFail();
        $this->assertSame(4.0, (float) $line->planned_quantity);

        // Prepare: 4 kg of roast beef are made from 4 kg of mince.
        $service->prepare($event->fresh(), $chef);
        $this->assertSame(6.0, $this->warehouseQty(12));
        $this->assertSame(6.0, $this->globalQty(12));
        $this->assertSame(4.0, $this->warehouseQty(14));
        $this->assertSame(4.0, $this->globalQty(14));
        $line = $event->fresh()->lines()->firstOrFail();
        $this->assertSame(800.0, (float) $line->unit_cost);
        $this->assertSame(3200.0, (float) $line->total_cost);

        // Close: used 2.5 + leftover 1 + waste 0.5 = prepared 4.
        $service->close($event->fresh(), $chef, [
            (int) $line->id => ['used_quantity' => 2.5, 'leftover_quantity' => 1, 'waste_quantity' => 0.5],
        ], 'Roast beef kept chilled, use by tomorrow dinner.');

        $closed = $event->fresh();
        $this->assertTrue($closed->isCompleted());
        $summary = $closed->summary();
        $this->assertSame(4.0, $summary['prepared']);
        $this->assertSame(2.5, $summary['used']);
        $this->assertSame(1.0, $summary['leftover']);
        $this->assertSame(0.5, $summary['waste']);
        $this->assertTrue($summary['balanced']);

        // Used + waste left the prepared stock (4 - 3 = 1 kg left);
        // the mince stays at 6 kg — ingredients never come back.
        $this->assertSame(1.0, $this->warehouseQty(14));
        $this->assertSame(6.0, $this->warehouseQty(12));

        $waste = KitchenLoss::where('category', KitchenLoss::CATEGORY_BURNT_SPOILED)->firstOrFail();
        $this->assertSame(0.5, (float) $waste->quantity);
        $this->assertTrue($waste->isPosted());

        $leftover = KitchenLoss::where('category', KitchenLoss::CATEGORY_BUFFET_LEFTOVER)->firstOrFail();
        $this->assertSame(1.0, (float) $leftover->quantity);
        $this->assertTrue((bool) $leftover->reuse_flag);
        $this->assertNull($leftover->posted_at);

        // A buffet leftover can never be posted (its stock stays).
        $this->expectException(InvalidArgumentException::class);
        app(\App\Services\KitchenLossService::class)->post($leftover->fresh(), $chef);
    }

    public function test_buffet_close_refuses_unbalanced_lines_and_cancel_only_from_draft(): void
    {
        $chef = User::findOrFail(2);
        $service = app(KitchenBuffetService::class);

        $event = $service->create([
            'event_date' => date('Y-m-d'),
            'meal_service' => 'dinner',
            'warehouse_id' => 1,
            'covers_source' => 'manual',
            'expected_covers' => 50,
            'lines' => [
                ['product_id' => 13, 'unit_id' => 1, 'per_cover_quantity' => 0.1, 'planned_quantity' => null],
            ],
        ], $chef);
        $line = $event->lines()->firstOrFail();

        // Close before prepare is refused.
        try {
            $service->close($event->fresh(), $chef, [(int) $line->id => ['used_quantity' => 3, 'leftover_quantity' => 1.5, 'waste_quantity' => 0.5]], 'Note.');
            $this->fail('Closing a draft event must fail.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('prepared', $e->getMessage());
        }

        $service->prepare($event->fresh(), $chef);

        // 3 + 1 + 0.5 = 4.5, not the prepared 5 — refused, nothing moves.
        try {
            $service->close($event->fresh(), $chef, [(int) $line->id => ['used_quantity' => 3, 'leftover_quantity' => 1, 'waste_quantity' => 0.5]], 'Note.');
            $this->fail('An unbalanced close must fail.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('reconcile', $e->getMessage());
        }
        $this->assertSame(15.0, $this->warehouseQty(13));
        $this->assertSame(0, KitchenLoss::count());

        // A prepared event cannot be cancelled — it must be closed.
        try {
            $service->cancel($event->fresh(), $chef);
            $this->fail('Cancelling a prepared event must fail.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('draft', $e->getMessage());
        }

        // Close properly, then a cancel is refused too.
        $service->close($event->fresh(), $chef, [(int) $line->id => ['used_quantity' => 3, 'leftover_quantity' => 1.5, 'waste_quantity' => 0.5]], 'Kept chilled.');
        $this->assertTrue($event->fresh()->isCompleted());

        try {
            $service->cancel($event->fresh(), $chef);
            $this->fail('Cancelling a completed event must fail.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('draft', $e->getMessage());
        }
    }

    public function test_buffet_prepare_blocked_on_shortage(): void
    {
        $chef = User::findOrFail(2);
        $service = app(KitchenBuffetService::class);

        $event = $service->create([
            'event_date' => date('Y-m-d'),
            'meal_service' => 'lunch',
            'warehouse_id' => 1,
            'covers_source' => 'manual',
            'expected_covers' => 50,
            'lines' => [
                ['product_id' => 13, 'unit_id' => 1, 'per_cover_quantity' => 2, 'planned_quantity' => null],
            ],
        ], $chef);
        $this->assertSame(100.0, (float) $event->lines()->firstOrFail()->planned_quantity);

        try {
            $service->prepare($event->fresh(), $chef);
            $this->fail('Preparing without stock must fail.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Leaves', $e->getMessage());
        }

        // Nothing moved: the warehouse still holds 20 kg of leaves.
        $this->assertSame(20.0, $this->warehouseQty(13));
        $this->assertTrue($event->fresh()->isDraft());
    }

    public function test_daily_special_from_menu_is_sellable_only_on_its_date(): void
    {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $chef = User::findOrFail(2);
        $service = app(KitchenDailySpecialService::class);

        // A Burger special for today: 20 portions at 550.
        $special = $service->createFromDish([
            'special_date' => $today,
            'source_product_id' => 15,
            'quantity_planned' => 20,
            'selling_price' => 550,
        ], $chef);

        $this->assertTrue($special->isActive());
        $this->assertSame(20.0, (float) $special->quantity_planned);
        $this->assertSame(550.0, (float) $special->selling_price);

        $product = ProductService::findOrFail((int) $special->product_id);
        $this->assertSame(0, (int) $product->show_on_pos);
        $this->assertSame(550.0, (float) $product->sale_price);
        $this->assertSame(15, (int) $special->source_product_id);

        // The dish's recipe lines were copied: 0.1 kg mince per burger.
        $lines = ProductRecipe::where('product_id', (int) $product->id)->get();
        $this->assertSame(1, $lines->count());
        $this->assertSame(12, (int) $lines->first()->ingredient_product_id);
        $this->assertSame(0.1, (float) $lines->first()->quantity);

        // Sellable today through the POS path (grid scope + guard).
        $this->assertTrue($service->isSellableToday((int) $product->id, 1, $today));
        $this->assertTrue(ProductService::sellableOnPos()->where('product_services.id', (int) $product->id)->exists());
        $this->assertTrue($service->todaysSpecials(1)->contains('id', (int) $special->id));

        // Not sellable tomorrow — a special lives for its date only.
        $this->assertFalse($service->isSellableToday((int) $product->id, 1, $tomorrow));

        // A special made for tomorrow is not sellable today either.
        $tomorrowSpecial = $service->createFromDish([
            'special_date' => $tomorrow,
            'source_product_id' => 15,
            'quantity_planned' => 10,
            'selling_price' => 550,
        ], $chef);
        $tomorrowProduct = ProductService::findOrFail((int) $tomorrowSpecial->product_id);
        $this->assertFalse($service->isSellableToday((int) $tomorrowProduct->id, 1, $today));
        $this->assertTrue($service->isSellableToday((int) $tomorrowProduct->id, 1, $tomorrow));

        // Once the date passes, the special expires and stops selling.
        $service->markExpired(1, $tomorrow);
        $this->assertSame(KitchenDailySpecial::STATUS_EXPIRED, $special->fresh()->status);
        $this->assertFalse($service->isSellableToday((int) $product->id, 1, $tomorrow));
    }

    public function test_weekly_plan_sets_weekdays_and_leaves_weekends_empty(): void
    {
        $chef = User::findOrFail(2);
        $service = app(KitchenDailySpecialService::class);

        $monday = date('Y-m-d', strtotime('monday this week'));
        $tuesday = date('Y-m-d', strtotime($monday.' +1 day'));
        $saturday = date('Y-m-d', strtotime($monday.' +5 days'));
        $sunday = date('Y-m-d', strtotime($monday.' +6 days'));

        // Monday: 2 specials (the client's 2–3 per day). Tuesday: 1.
        // Wednesday–Sunday: none.
        $created = $service->planWeek([
            'week_start' => $monday,
            'days' => [
                $monday => [
                    ['source_product_id' => 15, 'quantity_planned' => 20, 'selling_price' => 550],
                    ['source_product_id' => 14, 'quantity_planned' => 10, 'selling_price' => 1500],
                ],
                $tuesday => [
                    ['source_product_id' => 15, 'quantity_planned' => 20, 'selling_price' => 550],
                ],
            ],
        ], $chef);

        $this->assertCount(3, $created);
        $this->assertSame(2, KitchenDailySpecial::whereDate('special_date', $monday)->count());
        $this->assertSame(1, KitchenDailySpecial::whereDate('special_date', $tuesday)->count());
        $this->assertSame(0, KitchenDailySpecial::whereDate('special_date', $saturday)->count());
        $this->assertSame(0, KitchenDailySpecial::whereDate('special_date', $sunday)->count());
        $this->assertSame(3, KitchenDailySpecial::count());

        // The plan page renders the week.
        $this->actingAs($chef)
            ->get(route('kitchen-daily-specials.plan', ['week_start' => $monday]))
            ->assertOk()
            ->assertSee('Plan the Week')
            ->assertSee('Monday');
    }

    public function test_on_the_spot_special_is_sellable_immediately_and_deducts_stock_on_sale(): void
    {
        $today = date('Y-m-d');
        $chef = User::findOrFail(2);
        $service = app(KitchenDailySpecialService::class);

        // The client's example: a guest asks for boiled fish — fish,
        // water and salt — and the chef makes it up on the spot.
        $special = $service->createOnTheSpot([
            'special_date' => $today,
            'name' => 'Boiled Fish',
            'quantity_planned' => 5,
            'selling_price' => 1200,
            'unit_id' => 1,
            'ingredients' => [
                ['ingredient_product_id' => 16, 'quantity' => 0.5, 'unit_id' => 1],
                ['ingredient_product_id' => 17, 'quantity' => 0.2, 'unit_id' => 1],
                ['ingredient_product_id' => 18, 'quantity' => 0.02, 'unit_id' => 1],
            ],
        ], $chef);

        $this->assertTrue($special->isActive());
        $this->assertTrue($special->is_on_the_spot);

        $product = ProductService::findOrFail((int) $special->product_id);
        $this->assertSame(0, (int) $product->show_on_pos);
        $this->assertSame(1200.0, (float) $product->sale_price);
        // The product is named after the dish plus its date.
        $this->assertStringContainsString('Boiled Fish', (string) $product->name);

        $lines = ProductRecipe::where('product_id', (int) $product->id)->orderBy('ingredient_product_id')->get();
        $this->assertSame(3, $lines->count());
        $this->assertSame([16, 17, 18], $lines->pluck('ingredient_product_id')->all());
        $this->assertSame(0.5, (float) $lines[0]->quantity);

        // Sellable immediately, through the POS path.
        $this->assertTrue($service->isSellableToday((int) $product->id, 1, $today));
        $this->assertTrue(ProductService::sellableOnPos()->where('product_services.id', (int) $product->id)->exists());

        // Sell 2 portions through the POS recipe ladder — the exact
        // branch the POS takes for a plain product with recipe lines.
        // (Acting as the chef: the POS stock ledger records the
        // logged-in user, as in a real sale.)
        $this->actingAs($chef);
        PosRecipeDeductionService::deductForSale((int) $product->id, 2, 1, 1, '#PDV00001', 1);

        $this->assertSame(4.0, $this->warehouseQty(16));
        $this->assertSame(4.0, $this->globalQty(16));
        $this->assertSame(49.6, $this->warehouseQty(17));
        $this->assertSame(1.96, $this->warehouseQty(18));
        // A recipe dish deducts its ingredients only — its own stock
        // is untouched.
        $this->assertSame(0.0, $this->globalQty((int) $product->id));
        $this->assertTrue(StockReport::where('type', 'pos')->where('product_id', 16)->exists());
    }

    public function test_other_company_sees_no_buffet_events_or_specials(): void
    {
        $chef = User::findOrFail(2);
        $buffets = app(KitchenBuffetService::class);
        $specials = app(KitchenDailySpecialService::class);

        $event = $buffets->create([
            'event_date' => date('Y-m-d'),
            'meal_service' => 'lunch',
            'warehouse_id' => 1,
            'covers_source' => 'manual',
            'expected_covers' => 50,
            'lines' => [
                ['product_id' => 13, 'unit_id' => 1, 'per_cover_quantity' => 0.1, 'planned_quantity' => null],
            ],
        ], $chef);
        $special = $specials->createFromDish([
            'special_date' => date('Y-m-d'),
            'source_product_id' => 15,
            'quantity_planned' => 20,
            'selling_price' => 550,
        ], $chef);

        $other = User::findOrFail(4);

        // The other company's index pages show none of company 1's rows.
        $this->actingAs($other)
            ->get(route('kitchen-buffet-events.index'))
            ->assertOk()
            ->assertDontSee($event->event_number);
        $this->actingAs($other)
            ->get(route('kitchen-daily-specials.index'))
            ->assertOk()
            ->assertDontSee((string) $special->product->name);

        // Cross-company service calls are refused.
        try {
            $buffets->prepare($event->fresh(), $other);
            $this->fail('Another company must not prepare this event.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('another company', $e->getMessage());
        }

        // Today's specials for the other company are empty.
        $this->assertSame(0, $specials->todaysSpecials(4)->count());
        $this->assertFalse($specials->isSellableToday((int) $special->product_id, 4));
    }

    public function test_buffet_and_special_pages_render_and_permission_gate(): void
    {
        $chef = User::findOrFail(2);

        $this->actingAs($chef)
            ->get(route('kitchen-buffet-events.index'))
            ->assertOk()
            ->assertSee('Buffet Events');
        $this->actingAs($chef)
            ->get(route('kitchen-buffet-events.create'))
            ->assertOk()
            ->assertSee('Expected covers')
            ->assertSee('Preparations');
        $this->actingAs($chef)
            ->get(route('kitchen-daily-specials.index'))
            ->assertOk()
            ->assertSee('Daily Specials');
        $this->actingAs($chef)
            ->get(route('kitchen-daily-specials.create'))
            ->assertOk()
            ->assertSee('On-the-spot');

        // A user without the permissions is turned away.
        $this->actingAs(User::findOrFail(6))
            ->get(route('kitchen-buffet-events.index'))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->actingAs(User::findOrFail(6))
            ->get(route('kitchen-daily-specials.index'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }
}
