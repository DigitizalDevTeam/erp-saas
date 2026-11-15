<?php

namespace Tests\Feature;

use App\Models\PosArea;
use App\Models\PosTable;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Restaurant POS floor designer v2 — per-area, server-saved layouts.
 *
 * The floor design lives ON the area: pos_areas.layout (JSON column added
 * by 2026_11_15_310000). Covers: save/load API (auth, company scoping,
 * table-belongs-to-area validation, malformed payloads, deleted-table
 * tolerance), creating a real PosTable from the designer, the designer page
 * loading per area, and the POS layout API carrying each area's saved
 * layout (null when none) with the existing waiter-only table filtering.
 */
class PosAreaLayoutTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('settings', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('branches', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('is_active')->default(1);
            $table->string('pos_type')->nullable();
            $table->timestamps();
        });
        Schema::create('branch_user', function ($table) {
            $table->bigIncrements('id');
            $table->integer('branch_id');
            $table->integer('user_id');
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
            $table->integer('plan')->nullable();
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

        Schema::create('plans', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('pos')->default(0);
            $table->timestamps();
        });
        Schema::create('languages', function ($table) {
            $table->increments('id');
            $table->string('code')->nullable();
            $table->string('full_name')->nullable();
            $table->timestamps();
        });
        Schema::create('ch_messages', function ($table) {
            $table->increments('id');
            $table->integer('to_id')->nullable();
            $table->integer('seen')->default(0);
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

        // POS restaurant tables (post-migration state: pos_areas carries
        // the layout JSON column).
        Schema::create('pos_areas', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('branch_id');
            $table->string('name');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->default(0);
            $table->json('layout')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_tables', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('area_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('name');
            $table->unsignedTinyInteger('capacity')->default(4);
            $table->string('status', 20)->default('available');
            $table->unsignedInteger('current_guests')->nullable();
            $table->string('customer_name')->nullable();
            $table->unsignedBigInteger('waiter_user_id')->nullable();
            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('pos_shifts', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('user_id');
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('pos_table_assignments', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('shift_id');
            $table->unsignedBigInteger('table_id');
            $table->unsignedBigInteger('user_id');
            $table->dateTime('assigned_at');
            $table->dateTime('unassigned_at')->nullable();
            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamps();
        });
        // Empty stubs: the POS layout API's cart-total / sent-to-production
        // queries touch these, but with no POS rows they return zeros.
        Schema::create('pos', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('table_id')->nullable();
            $table->unsignedBigInteger('created_by')->default(0);
            $table->dateTime('cancelled_at')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_payments', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('pos_id')->default(0);
            $table->timestamps();
        });
        Schema::create('pos_products', function ($table) {
            $table->bigIncrements('id');
            $table->integer('pos_id')->default(0);
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->dateTime('removed_from_cart_at')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::create(['name' => 'manage pos', 'guard_name' => 'web']);
        Permission::create(['name' => 'pos restaurant waiter', 'guard_name' => 'web']);

        $prop = new \ReflectionProperty(\App\Models\Plan::class, 'getplans');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        DB::table('plans')->insert([
            ['id' => 1, 'name' => 'Test Plan', 'pos' => 1],
        ]);

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Main Branch', 'created_by' => 1, 'is_active' => 1, 'pos_type' => 'restaurant'],
            ['id' => 2, 'name' => 'Bar Branch', 'created_by' => 1, 'is_active' => 1, 'pos_type' => 'restaurant'],
        ]);

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'plan' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'plan' => null, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Waiter', 'email' => 'waiter@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'plan' => null, 'email_verified_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['manage pos']);
        User::findOrFail(3)->givePermissionTo(['pos restaurant waiter']);

        DB::table('pos_areas')->insert([
            ['id' => 1, 'branch_id' => 1, 'name' => 'Restaurant', 'sort_order' => 0, 'created_by' => 1],
            ['id' => 2, 'branch_id' => 1, 'name' => 'Bar', 'sort_order' => 1, 'created_by' => 1],
            ['id' => 3, 'branch_id' => 1, 'name' => 'Other Company', 'sort_order' => 0, 'created_by' => 99],
        ]);
        DB::table('pos_tables')->insert([
            ['id' => 1, 'area_id' => 1, 'branch_id' => 1, 'name' => 'T1', 'capacity' => 4, 'status' => 'available', 'created_by' => 1],
            ['id' => 2, 'area_id' => 1, 'branch_id' => 1, 'name' => 'T2', 'capacity' => 2, 'status' => 'occupied', 'created_by' => 1],
            ['id' => 3, 'area_id' => 2, 'branch_id' => 1, 'name' => 'T3', 'capacity' => 6, 'status' => 'available', 'created_by' => 1],
            ['id' => 4, 'area_id' => 3, 'branch_id' => 1, 'name' => 'T4', 'capacity' => 4, 'status' => 'available', 'created_by' => 99],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['pos_products', 'pos_payments', 'pos', 'pos_table_assignments', 'pos_shifts', 'pos_tables', 'pos_areas', 'email_templates', 'ch_messages', 'languages', 'plans', 'branch_user', 'branches', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'permissions', 'roles', 'settings', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function layoutPayload(array $elements): array
    {
        return ['layout' => ['version' => 1, 'elements' => $elements]];
    }

    public function test_company_user_can_save_and_load_area_layout(): void
    {
        // The designer sends w/h for every element (tables included).
        $payload = $this->layoutPayload([
            ['type' => 'table-round-4', 'table_id' => 1, 'x' => 100, 'y' => 120, 'w' => 120, 'h' => 120, 'rotation' => 0],
            ['type' => 'wall-h', 'x' => 0, 'y' => 0, 'w' => 500, 'h' => 10, 'rotation' => 0],
        ]);

        $save = $this->actingAs(User::findOrFail(1))
            ->postJson(route('pos.restaurant.layout.designer.area', ['area' => 1]), $payload);
        $save->assertOk();
        $save->assertJsonPath('saved', true);

        $this->assertSame(1, PosArea::findOrFail(1)->layout['version']);
        $this->assertCount(2, PosArea::findOrFail(1)->layout['elements']);

        $load = $this->actingAs(User::findOrFail(1))
            ->getJson(route('pos.restaurant.layout.designer.area', ['area' => 1]));
        $load->assertOk();
        $load->assertJsonPath('area.name', 'Restaurant');
        $load->assertJsonPath('layout.elements.0.type', 'table-round-4');
        $load->assertJsonPath('tables.0.name', 'T1');
        $load->assertJsonPath('tables.1.name', 'T2');
    }

    public function test_save_rejects_table_from_another_area(): void
    {
        // T3 belongs to the Bar area, not Restaurant.
        $response = $this->actingAs(User::findOrFail(1))
            ->postJson(route('pos.restaurant.layout.designer.area', ['area' => 1]), $this->layoutPayload([
                ['type' => 'table-square-4', 'table_id' => 3, 'x' => 10, 'y' => 10, 'w' => 120, 'h' => 120, 'rotation' => 0],
            ]));

        $response->assertStatus(422);
        $this->assertNull(PosArea::findOrFail(1)->layout);
    }

    public function test_save_rejects_table_from_another_company(): void
    {
        // T4 belongs to another company's area.
        $response = $this->actingAs(User::findOrFail(1))
            ->postJson(route('pos.restaurant.layout.designer.area', ['area' => 1]), $this->layoutPayload([
                ['type' => 'table-round-2', 'table_id' => 4, 'x' => 10, 'y' => 10, 'w' => 100, 'h' => 100, 'rotation' => 0],
            ]));

        $response->assertStatus(422);
        $this->assertNull(PosArea::findOrFail(1)->layout);
    }

    public function test_other_company_area_is_not_found(): void
    {
        $this->actingAs(User::findOrFail(1))
            ->getJson(route('pos.restaurant.layout.designer.area', ['area' => 3]))
            ->assertNotFound();

        $this->actingAs(User::findOrFail(1))
            ->postJson(route('pos.restaurant.layout.designer.area', ['area' => 3]), $this->layoutPayload([]))
            ->assertNotFound();
    }

    public function test_user_without_manage_pos_gets_403(): void
    {
        $this->actingAs(User::findOrFail(2))
            ->getJson(route('pos.restaurant.layout.designer.area', ['area' => 1]))
            ->assertForbidden();

        $this->actingAs(User::findOrFail(2))
            ->postJson(route('pos.restaurant.layout.designer.area', ['area' => 1]), $this->layoutPayload([]))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->getJson(route('pos.restaurant.layout.designer.area', ['area' => 1]))
            ->assertUnauthorized();
    }

    public function test_save_rejects_malformed_layout(): void
    {
        $this->actingAs(User::findOrFail(1))
            ->postJson(route('pos.restaurant.layout.designer.area', ['area' => 1]), ['layout' => ['version' => 1, 'elements' => 'nope']])
            ->assertStatus(422);

        $this->actingAs(User::findOrFail(1))
            ->postJson(route('pos.restaurant.layout.designer.area', ['area' => 1]), $this->layoutPayload([
                ['type' => 'not-a-real-type', 'x' => 10, 'y' => 10, 'w' => 50, 'h' => 50, 'rotation' => 0],
            ]))
            ->assertStatus(422);

        $this->assertNull(PosArea::findOrFail(1)->layout);
    }

    public function test_load_tolerates_deleted_table(): void
    {
        $this->actingAs(User::findOrFail(1))
            ->postJson(route('pos.restaurant.layout.designer.area', ['area' => 1]), $this->layoutPayload([
                ['type' => 'table-round-4', 'table_id' => 1, 'x' => 100, 'y' => 120, 'w' => 120, 'h' => 120, 'rotation' => 0],
            ]))
            ->assertOk();

        // The table row is deleted; the saved design must still load, with
        // the orphaned element dropped (never a crash, never a stale table).
        PosTable::findOrFail(1)->delete();

        $load = $this->actingAs(User::findOrFail(1))
            ->getJson(route('pos.restaurant.layout.designer.area', ['area' => 1]));
        $load->assertOk();
        $load->assertJsonPath('layout.elements', []);
        // The live tables list no longer contains the deleted table.
        $load->assertJsonCount(1, 'tables');
        $load->assertJsonPath('tables.0.name', 'T2');
    }

    public function test_create_table_from_designer_creates_real_pos_table(): void
    {
        // The designer sends the seat count as capacity (derived from the
        // palette type it was dragged from); the API answers flat fields.
        $response = $this->actingAs(User::findOrFail(1))
            ->postJson(route('pos.restaurant.layout.designer.tables.store'), [
                'area_id' => 1,
                'capacity' => 4,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('capacity', 4);
        $response->assertJsonPath('status', 'available');
        // T1 + T2 already exist in the area, so the new table is Table 3.
        $response->assertJsonPath('name', 'Table 3');

        $this->assertDatabaseHas('pos_tables', [
            'id' => $response->json('id'),
            'area_id' => 1,
            'branch_id' => 1,
            'name' => 'Table 3',
            'status' => 'available',
        ]);
    }

    public function test_create_table_rejects_bad_capacity_and_other_company_area(): void
    {
        $this->actingAs(User::findOrFail(1))
            ->postJson(route('pos.restaurant.layout.designer.tables.store'), [
                'area_id' => 1,
                'capacity' => 0,
            ])
            ->assertStatus(422);

        $this->actingAs(User::findOrFail(1))
            ->postJson(route('pos.restaurant.layout.designer.tables.store'), [
                'area_id' => 3,
                'capacity' => 4,
            ])
            ->assertNotFound();
    }

    public function test_create_table_from_designer_accepts_custom_name(): void
    {
        // The designer's "New table" prompt sends a name + seats; the
        // real PosTable is created with that name (no auto "Table N").
        $response = $this->actingAs(User::findOrFail(1))
            ->postJson(route('pos.restaurant.layout.designer.tables.store'), [
                'area_id' => 1,
                'capacity' => 6,
                'name' => 'Corner Booth',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('name', 'Corner Booth');
        $response->assertJsonPath('capacity', 6);
        $this->assertDatabaseHas('pos_tables', [
            'id' => $response->json('id'),
            'area_id' => 1,
            'name' => 'Corner Booth',
        ]);
    }

    public function test_update_table_from_designer_updates_name_and_capacity(): void
    {
        // The Properties panel edits a table's name / seats in place and
        // PATCHes the real PosTable; the floor keeps the same table id.
        $response = $this->actingAs(User::findOrFail(1))
            ->patchJson(route('pos.restaurant.layout.designer.tables.update', ['table' => 1]), [
                'name' => 'T1 Renamed',
                'capacity' => 6,
            ]);

        $response->assertOk();
        $response->assertJsonPath('name', 'T1 Renamed');
        $response->assertJsonPath('capacity', 6);
        $this->assertDatabaseHas('pos_tables', [
            'id' => 1,
            'area_id' => 1,
            'branch_id' => 1,
            'name' => 'T1 Renamed',
            'capacity' => 6,
        ]);
    }

    public function test_update_table_rejects_bad_input_and_other_company_table(): void
    {
        // Empty name / out-of-range seats are refused, and the row is
        // untouched.
        $this->actingAs(User::findOrFail(1))
            ->patchJson(route('pos.restaurant.layout.designer.tables.update', ['table' => 1]), [
                'name' => 'T1',
                'capacity' => 0,
            ])
            ->assertStatus(422);
        $this->assertSame('T1', PosTable::findOrFail(1)->name);

        // A table from another company is not updatable (403, not 404 —
        // the row exists, it just is not yours).
        $this->actingAs(User::findOrFail(1))
            ->patchJson(route('pos.restaurant.layout.designer.tables.update', ['table' => 4]), [
                'name' => 'Taken',
                'capacity' => 4,
            ])
            ->assertForbidden();

        // A user without the POS permission cannot update any table.
        $this->actingAs(User::findOrFail(2))
            ->patchJson(route('pos.restaurant.layout.designer.tables.update', ['table' => 1]), [
                'name' => 'Taken',
                'capacity' => 4,
            ])
            ->assertForbidden();
    }

    public function test_create_area_from_designer_creates_real_pos_area(): void
    {
        // The designer's "New area" control creates a real PosArea (a new
        // floor) on one of the company's restaurant branches.
        $response = $this->actingAs(User::findOrFail(1))
            ->postJson(route('pos.restaurant.layout.designer.areas.store'), [
                'name' => 'Terrace',
                'branch_id' => 1,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('name', 'Terrace');
        $response->assertJsonPath('branch_id', 1);
        $response->assertJsonPath('branch_name', 'Main Branch');
        $this->assertDatabaseHas('pos_areas', [
            'id' => $response->json('id'),
            'branch_id' => 1,
            'name' => 'Terrace',
            'created_by' => 1,
        ]);

        // A branch from another company is refused.
        DB::table('branches')->insert([
            ['id' => 9, 'name' => 'Foreign Branch', 'created_by' => 99, 'is_active' => 1, 'pos_type' => 'restaurant'],
        ]);
        $this->actingAs(User::findOrFail(1))
            ->postJson(route('pos.restaurant.layout.designer.areas.store'), [
                'name' => 'Nope',
                'branch_id' => 9,
            ])
            ->assertNotFound();

        // A user without the POS permission cannot create an area.
        $this->actingAs(User::findOrFail(2))
            ->postJson(route('pos.restaurant.layout.designer.areas.store'), [
                'name' => 'Nope',
                'branch_id' => 1,
            ])
            ->assertForbidden();
    }

    public function test_designer_page_has_ux_gap_controls(): void
    {
        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('pos.restaurant.layout.designer'));

        $response->assertOk();
        // Undo / Redo (toolbar + keyboard), table search, named-table and
        // new-area controls, the cross-link to the POS screen, the inline
        // confirm/prompt dialog, and the status legend.
        $response->assertSee('posLayoutUndo', false);
        $response->assertSee('posLayoutRedo', false);
        $response->assertSee('posLayoutAreaSearch', false);
        $response->assertSee('posLayoutNewTable', false);
        $response->assertSee('posLayoutNewArea', false);
        $response->assertSee('posLayoutModal', false);
        $response->assertSee('pos-layout-legend', false);
        $response->assertSee('View on POS', false);
        // The redundant Save button is gone — autosave is the only path.
        $response->assertDontSee('id="posLayoutSave"', false);
        // No native confirm/alert calls remain in the designer.
        $blade = file_get_contents(resource_path('views/pos/restaurant-layout.blade.php'));
        $this->assertStringNotContainsString('window.confirm(', $blade);
        $this->assertStringNotContainsString('window.alert(', $blade);
        // Bigger floor text: table name + seats on the canvas are easy
        // to read at fit-zoom (was 14px / 11px).
        $this->assertStringContainsString('.pos-layout-table-num { font-weight: 700; font-size: 20px;', $blade);
        $this->assertStringContainsString('.pos-layout-table-cap { font-size: 15px;', $blade);
    }

    public function test_designer_page_loads_per_area(): void
    {
        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('pos.restaurant.layout.designer', ['area' => 2]));

        $response->assertOk();
        $response->assertSee('Floor design', false);
        $response->assertSee('Bar', false);
        $response->assertSee('posLayoutCanvas', false);
        $response->assertSee('posLayoutAreaSelect', false);
        // The area picker lists the company's areas.
        $response->assertSee('Restaurant', false);
    }

    public function test_designer_page_defaults_to_first_area(): void
    {
        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('pos.restaurant.layout.designer'));

        $response->assertOk();
        $response->assertSee('Restaurant', false);
    }

    public function test_pos_layout_api_includes_saved_layout(): void
    {
        $this->actingAs(User::findOrFail(1))
            ->postJson(route('pos.restaurant.layout.designer.area', ['area' => 1]), $this->layoutPayload([
                ['type' => 'table-round-4', 'table_id' => 1, 'x' => 100, 'y' => 120, 'w' => 120, 'h' => 120, 'rotation' => 0],
            ]))
            ->assertOk();

        $response = $this->actingAs(User::findOrFail(1))
            ->getJson(route('pos.restaurant.layout', ['branch_id' => 1]));

        $response->assertOk();
        $areas = $response->json('areas');
        $this->assertCount(2, $areas);
        $this->assertSame('Restaurant', $areas[0]['name']);
        $this->assertSame('table-round-4', $areas[0]['layout']['elements'][0]['type']);
        $this->assertSame(1, $areas[0]['layout']['elements'][0]['table_id']);
        // Bar has no saved layout yet: the POS page falls back to buttons.
        $this->assertSame('Bar', $areas[1]['name']);
        $this->assertNull($areas[1]['layout']);
        // Live table data still travels with the layout.
        $this->assertSame('T1', $areas[0]['tables'][0]['name']);
        $this->assertSame('available', $areas[0]['tables'][0]['status']);
    }

    public function test_pos_layout_api_waiter_sees_only_assigned_tables(): void
    {
        DB::table('pos_shifts')->insert([
            'id' => 1, 'branch_id' => 1, 'user_id' => 3,
            'opened_at' => now(), 'status' => 'open', 'created_by' => 1,
        ]);
        DB::table('pos_table_assignments')->insert([
            'shift_id' => 1, 'table_id' => 1, 'user_id' => 3,
            'assigned_at' => now(), 'created_by' => 1,
        ]);

        // The waiter signs in with a PIN; the company user stays logged in.
        $response = $this->actingAs(User::findOrFail(1))
            ->withSession(['pos_pin_user_id' => 3])
            ->getJson(route('pos.restaurant.layout', ['branch_id' => 1]));

        $response->assertOk();
        $areas = $response->json('areas');
        $this->assertSame(['T1'], array_column($areas[0]['tables'], 'name'));
        $this->assertSame([], $areas[1]['tables']);
    }

    public function test_pos_restaurant_blade_wires_the_floor_renderer(): void
    {
        $blade = file_get_contents(resource_path('views/pos/index-restaurant.blade.php'));

        $this->assertStringContainsString('renderRestaurantFloor', $blade);
        $this->assertStringContainsString('pos-floor', $blade);
        $this->assertStringContainsString('area.layout', $blade);
        // Regression: the floor rendered as a ~55px strip because the area
        // wrapper is a flex item of #pos-table-grid (d-flex) and
        // shrink-to-fit around the small area label — the floor (all
        // children absolutely positioned) contributed no width. The wrapper
        // must be full-width and the floor itself 100% wide.
        $this->assertStringContainsString('<div class="mb-2 w-100" data-pos-floor-area-wrap="', $blade);
        $this->assertStringContainsString('.pos-floor { position: relative; width: 100%;', $blade);
        // The table panel starts hidden, so the grid width reads 0 on first
        // load — the scale must fall back to the visible panel column.
        $this->assertStringContainsString("$('#pos-restaurant-table-grid-wrap').parent().width()", $blade);
        // The button-grid fallback is still there for areas without a layout.
        $this->assertStringContainsString('renderRestaurantTableButton', $blade);
        // Canvas floor (like the designer canvas): the floor is drawn at
        // natural size inside a viewport; the user zooms (wheel or the
        // + / - buttons) and drags the empty floor to pan. Table buttons
        // keep working exactly as before.
        $this->assertStringContainsString('pos-floor-canvas', $blade);
        $this->assertStringContainsString('data-pos-floor-zoom', $blade);
        $this->assertStringContainsString('posFloorFit', $blade);
        $this->assertStringContainsString('posFloorInit', $blade);
        // Regression: the grid-card class min-width: 9rem (144px) clamped
        // the inline scaled width up, so a big floor rendered as flat
        // overlapping ovals (measured 144 x 29 on Hoya's 41-table floor).
        // The floor's own size is the inline width/height.
        $this->assertStringContainsString('.pos-floor .pos-floor-table { min-width: 0', $blade);
        // Full-screen floor popup: "Open Table" opens the floor 100% wide
        // and 100% tall (one area at a time, picked from the dropdown)
        // instead of rendering the areas in the page.
        $this->assertStringContainsString('pos-floor-modal', $blade);
        $this->assertStringContainsString('posFloorModalBuildAreaSelect', $blade);
        $this->assertStringContainsString('posFloorModalApplyArea', $blade);
        $this->assertStringContainsString('pos-floor-modal-area', $blade);
        // Popup polish: opening the popup refreshes the tables for the
        // current branch (first load can pre-select the branch without a
        // change event firing, leaving "Select branch first" behind);
        // the default area prefers one named Restaurant (the API orders
        // by sort_order then name, so "Bar" can sort first); wheel zoom
        // is throttled so one trackpad gesture cannot jump to the 15% floor.
        $this->assertStringContainsString('loadRestaurantTables(openBranchId)', $blade);
        $this->assertStringContainsString('/restaurant/i', $blade);
        $this->assertStringContainsString('posFloorWheelLast', $blade);
        // UX gaps: in-use floor tables show the bill amount + guest count
        // (when the table is big enough) and always a tooltip with
        // guest / waiter / amount / guests; available tables stay
        // name + seats only.
        $this->assertStringContainsString('pos-floor-meta', $blade);
        $this->assertStringContainsString('cart_total_formatted', $blade);
        $this->assertStringContainsString('tipParts', $blade);
        // UX gaps: dine-in table tools hide until a table is picked
        // (delivery / takeaway behaviour is untouched).
        $this->assertStringContainsString('posRestaurantToggleTableTools', $blade);
        // UX gaps: the warehouse hint says "Loading..." while the
        // warehouses-by-branch request is in flight.
        $this->assertStringContainsString("pos-restaurant-warehouse-hint').text('{{ __(\"Loading...\") }}')", $blade);
        // UX gaps: the no-shift overlays say what is blocked, and so do
        // the visible no-opening-balance banners (the overlays only show
        // for staff who cannot open a shift; owners see the banner).
        $this->assertStringContainsString('no_shift_blocks_orders', $blade);
        $this->assertStringContainsString('pos-restaurant-no-opening-balance', $blade);
        // UX gaps: Manage Tables rows open their table, have a search
        // box, show area + seats, and explain empty waiter dropdowns.
        $this->assertStringContainsString('pos-manage-table-row', $blade);
        $this->assertStringContainsString('pos-manage-tables-search', $blade);
        $this->assertStringContainsString('no_waiter_shifts_open', $blade);
        // Follow-up fix: the row must NOT use Bootstrap d-flex — its
        // !important display beats jQuery .toggle(), so the search could
        // never hide a row. Inline display:flex keeps the layout and
        // lets .toggle() work.
        $this->assertStringNotContainsString('list-group-item d-flex justify-content-between align-items-center pos-manage-table-row', $blade);
        $this->assertStringContainsString("style=\"cursor:pointer;display:flex;\"", $blade);
        // Follow-up fix: tapping an available row opens the same
        // waiter/guests dialog as tapping the table on the floor (the
        // dialog also enforces the open-shift rule); occupied rows
        // select the table directly.
        $this->assertStringContainsString("if (rowStatus === 'available')", $blade);
        $this->assertStringContainsString("$('#posOpenTableModal').modal('show')", $blade);
        // Bigger floor text on the POS floor + popup: seats + amount /
        // guests on the canvas (was 11px / 10px), and the table name +
        // detail rows on the button cards (was 0.82rem / 0.68rem).
        $this->assertStringContainsString('.pos-floor-cap { font-size: 15px;', $blade);
        $this->assertStringContainsString('.pos-floor-meta { font-size: 13px;', $blade);
        $posCss = file_get_contents(public_path('css/pos.css'));
        $this->assertMatchesRegularExpression('/\.pos-table-card__name\s*\{[^}]*font-size:\s*1\.15rem;/s', $posCss);
        $this->assertMatchesRegularExpression('/\.pos-table-card__row\s*\{[^}]*font-size:\s*0\.8rem;/s', $posCss);
        // One-row popup header: the zoom controls moved out of the floor
        // toolbar into the header, the area-name row is gone, and the
        // floor is taller to use the freed space.
        $this->assertStringContainsString('calc(100vh - 70px)', $blade);
        $this->assertStringContainsString('pos-floor-modal-zoom-label', $blade);
        $this->assertStringNotContainsString('data-pos-floor-zoom="out" data-pos-floor-area=', $blade);
        $this->assertStringNotContainsString('text-muted d-block mb-1', $blade);
    }

    public function test_restaurant_blade_has_fullscreen_floor_popup(): void
    {
        $blade = file_get_contents(resource_path('views/pos/restaurant.blade.php'));

        // The table picker wrap is the full-screen popup: header with the
        // area dropdown + close button, body holding the table grid.
        $this->assertStringContainsString('id="pos-restaurant-table-grid-wrap" class="pos-floor-modal d-none"', $blade);
        $this->assertStringContainsString('id="pos-floor-modal-area"', $blade);
        $this->assertStringContainsString('id="pos-floor-modal-close"', $blade);
        // UX gaps: the popup header has a status legend (Available /
        // Occupied / Reserved) and a link back to the layout designer.
        $this->assertStringContainsString('pos-floor-legend', $blade);
        $this->assertStringContainsString("pos.edit_layout", $blade);
        $this->assertStringContainsString('pos.restaurant.layout.designer', $blade);
        // UX gaps: the selected-table readout is a button that opens the
        // same popup as "Open Table".
        $this->assertStringContainsString('<button type="button" id="pos-selected-table"', $blade);
        // One-row popup header: the zoom controls (− % + Fit) sit in the
        // header row with everything else, and the header never wraps —
        // it scrolls sideways on a narrow screen instead.
        $this->assertStringContainsString('id="pos-floor-modal-zoom"', $blade);
        $this->assertStringContainsString('id="pos-floor-modal-zoom-label"', $blade);
        $this->assertStringContainsString('pos-floor-modal-header d-flex flex-nowrap overflow-auto', $blade);
    }
}
