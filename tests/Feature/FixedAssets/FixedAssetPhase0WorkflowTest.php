<?php

namespace Tests\Feature\FixedAssets;

use App\Models\FixedAsset;
use App\Models\FixedAssetCategory;
use App\Models\FixedAssetLocation;
use App\Models\User;
use App\Services\FixedAssetNumberingService;
use App\Services\FixedAssetService;
use App\Services\SidebarMenuPermissionCatalog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Fixed Assets Phase 0 — foundation, HTTP level (plan §5, §10).
 *
 * Covers: category + location screens (create/store/edit/
 * deactivate), the read-only asset register (index/show/label
 * with QR), the settings screen, permission denial, cross-
 * company denial, route resolution and the menu catalog node.
 *
 * Users: 1 = Company (all fixed-asset permissions), 2 =
 * Operator (manage fixed assets only), 4 = Other Company
 * (all fixed-asset permissions), 6 = No Permission.
 * "Today" is frozen at 2026-11-07.
 */
class FixedAssetPhase0WorkflowTest extends TestCase
{
    protected FixedAssetService $service;
    protected FixedAssetNumberingService $numbering;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-07 09:00:00'));
        $this->createTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['manage transaction', 'manage fixed assets', 'create fixed assets', 'edit fixed assets', 'delete fixed assets', 'approve fixed asset acquisitions', 'approve fixed asset transfers', 'approve fixed asset disposals', 'post fixed asset depreciation', 'manage fixed asset categories', 'manage fixed asset settings', 'view fixed asset audit trail'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Hoya Hoya', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Operator', 'email' => 'operator@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Uthara', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        $all = ['manage transaction', 'manage fixed assets', 'create fixed assets', 'edit fixed assets', 'delete fixed assets', 'approve fixed asset acquisitions', 'approve fixed asset transfers', 'approve fixed asset disposals', 'post fixed asset depreciation', 'manage fixed asset categories', 'manage fixed asset settings', 'view fixed asset audit trail'];
        User::findOrFail(1)->givePermissionTo($all);
        User::findOrFail(2)->givePermissionTo(['manage transaction', 'manage fixed assets']);
        User::findOrFail(4)->givePermissionTo($all);
        User::findOrFail(6)->givePermissionTo(['manage transaction']);

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Main Branch', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'name' => 'Tangible fixed assets', 'code' => 321, 'type' => 3, 'sub_type' => 2, 'is_enabled' => 1, 'created_by' => 1],
            ['id' => 2, 'name' => 'Accumulated depreciation', 'code' => 328, 'type' => 3, 'sub_type' => 2, 'is_enabled' => 1, 'created_by' => 1],
        ]);
        DB::table('cost_centres')->insert([
            ['id' => 1, 'company_id' => 1, 'created_by' => 1, 'code' => 'LOG', 'name' => 'Logistics', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->service = new FixedAssetService();
        $this->numbering = new FixedAssetNumberingService();

        $this->service->createCategory([
            'code' => 'IT', 'name' => 'IT Equipment', 'classification' => 'tangible',
            'default_useful_life' => 5, 'default_life_unit' => 'year',
            'depreciation_method' => 'straight_line', 'residual_value_type' => 'none',
            'asset_account_id' => 1, 'accum_depreciation_account_id' => 2,
        ], 1, 1);
        $this->service->createLocation(['code' => 'WH1', 'name' => 'Warehouse 1', 'branch_id' => 1], 1, 1);

        $this->numbering->allocateAndCreate(1, 'IT', [
            'category_id' => 1,
            'location_id' => 1,
            'cost_centre_id' => 1,
            'name' => 'Laptop Dell XPS',
            'serial_number' => 'SN-001',
            'purchase_cost' => '25000.0000',
            'currency' => 'MZN',
            'exchange_rate' => '1.0000000000',
            'amount_base' => '25000.0000',
            'capitalized_cost' => '25000.0000',
            'useful_life' => 5,
            'life_unit' => 'year',
            'status' => FixedAsset::STATUS_AVAILABLE,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        \App\Models\Utility::$rates = null;
        \App\Models\Utility::$data = null;

        foreach (['fixed_asset_audit_events', 'fixed_asset_status_history', 'fixed_asset_approvals', 'fixed_asset_documents', 'fixed_assets', 'fixed_asset_locations', 'fixed_asset_categories', 'cost_centres', 'chart_of_accounts', 'email_templates', 'ch_messages', 'languages', 'plans', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'permissions', 'roles', 'users', 'settings', 'branch_user', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Routes + menu
    // ------------------------------------------------------------------

    public function test_all_phase0_routes_resolve(): void
    {
        foreach ([
            'fixed-assets.index', 'fixed-assets.show', 'fixed-assets.label',
            'fixed-assets.settings', 'fixed-assets.settings.save',
            'fixed-asset-categories.index', 'fixed-asset-categories.create',
            'fixed-asset-categories.store', 'fixed-asset-categories.edit',
            'fixed-asset-categories.update', 'fixed-asset-categories.deactivate',
            'fixed-asset-locations.index', 'fixed-asset-locations.create',
            'fixed-asset-locations.store', 'fixed-asset-locations.edit',
            'fixed-asset-locations.update', 'fixed-asset-locations.deactivate',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Missing route: {$name}");
        }
    }

    public function test_menu_catalog_has_the_fixed_assets_node(): void
    {
        $tree = SidebarMenuPermissionCatalog::tree();

        $accounting = null;
        foreach ($tree[0]['children'] as $child) {
            if ($child['permission'] === 'menu.dashboard.accounting') {
                $accounting = $child;
            }
        }
        $this->assertNotNull($accounting);

        $fixedAssets = null;
        foreach ($accounting['children'] as $child) {
            if ($child['permission'] === 'menu.dashboard.accounting.fixed_assets') {
                $fixedAssets = $child;
            }
        }
        $this->assertNotNull($fixedAssets);
        $this->assertContains('manage fixed assets', $fixedAssets['legacy']);
        $this->assertContains('manage fixed asset categories', $fixedAssets['legacy']);

        $labels = array_column($fixedAssets['children'], 'label');
        $this->assertContains('Asset Register', $labels);
        $this->assertContains('Categories', $labels);
        $this->assertContains('Locations', $labels);
        $this->assertContains('Settings', $labels);
        $this->assertContains('Audit Trail', $labels);
    }

    // ------------------------------------------------------------------
    // Categories
    // ------------------------------------------------------------------

    public function test_categories_index_renders(): void
    {
        $response = $this->actingAs(User::find(1))->get(route('fixed-asset-categories.index'));

        $response->assertOk();
        $response->assertSee('IT');
        $response->assertSee('IT Equipment');
    }

    public function test_categories_create_and_store(): void
    {
        $user = User::find(1);

        $this->actingAs($user)->get(route('fixed-asset-categories.create'))->assertOk();

        $response = $this->actingAs($user)->post(route('fixed-asset-categories.store'), [
            'code' => 'VEH',
            'name' => 'Vehicles',
            'classification' => 'tangible',
            'default_useful_life' => 8,
            'default_life_unit' => 'year',
            'depreciation_method' => 'straight_line',
            'residual_value_type' => 'percentage',
            'residual_value_percent' => '10',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('fixed-asset-categories.index'));
        $this->assertSame('VEH', FixedAssetCategory::where('code', 'VEH')->first()->code);
        // SQLite returns DECIMAL columns without scale ('10' not
        // '10.0000'), so compare with bccomp.
        $this->assertSame(0, bccomp((string) FixedAssetCategory::where('code', 'VEH')->first()->residual_value_percent, '10.0000', 4));
    }

    public function test_categories_store_duplicate_code_is_refused(): void
    {
        $response = $this->actingAs(User::find(1))->from(route('fixed-asset-categories.index'))
            ->post(route('fixed-asset-categories.store'), [
                'code' => 'IT',
                'name' => 'Duplicate',
                'classification' => 'tangible',
                'default_life_unit' => 'year',
                'depreciation_method' => 'straight_line',
                'residual_value_type' => 'none',
            ]);

        $response->assertRedirect(route('fixed-asset-categories.index'));
        $response->assertSessionHas('error');
        $this->assertSame(1, FixedAssetCategory::where('code', 'IT')->count());
    }

    public function test_categories_edit_and_update(): void
    {
        $category = FixedAssetCategory::where('code', 'IT')->first();
        $user = User::find(1);

        $this->actingAs($user)->get(route('fixed-asset-categories.edit', $category->id))->assertOk();

        $response = $this->actingAs($user)->put(route('fixed-asset-categories.update', $category->id), [
            'code' => 'IT',
            'name' => 'IT & Office Equipment',
            'classification' => 'tangible',
            'default_useful_life' => 4,
            'default_life_unit' => 'year',
            'depreciation_method' => 'straight_line',
            'residual_value_type' => 'none',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('fixed-asset-categories.index'));
        $this->assertSame('IT & Office Equipment', $category->fresh()->name);
        $this->assertSame(4, (int) $category->fresh()->default_useful_life);
    }

    public function test_categories_deactivate_soft_deletes(): void
    {
        $category = FixedAssetCategory::where('code', 'IT')->first();

        $response = $this->actingAs(User::find(1))->post(route('fixed-asset-categories.deactivate', $category->id));

        $response->assertRedirect(route('fixed-asset-categories.index'));
        $this->assertSoftDeleted('fixed_asset_categories', ['id' => $category->id]);
    }

    // ------------------------------------------------------------------
    // Locations
    // ------------------------------------------------------------------

    public function test_locations_index_and_store(): void
    {
        $user = User::find(1);

        $this->actingAs($user)->get(route('fixed-asset-locations.index'))->assertOk()->assertSee('WH1');

        $response = $this->actingAs($user)->post(route('fixed-asset-locations.store'), [
            'code' => 'OFF1',
            'name' => 'Office 1',
            'branch_id' => 1,
            'room' => 'Room 3',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('fixed-asset-locations.index'));
        $this->assertDatabaseHas('fixed_asset_locations', ['code' => 'OFF1', 'room' => 'Room 3']);
    }

    public function test_locations_edit_update_and_deactivate(): void
    {
        $location = FixedAssetLocation::where('code', 'WH1')->first();
        $user = User::find(1);

        $this->actingAs($user)->get(route('fixed-asset-locations.edit', $location->id))->assertOk();

        $this->actingAs($user)->put(route('fixed-asset-locations.update', $location->id), [
            'code' => 'WH1', 'name' => 'Warehouse One', 'branch_id' => 1, 'room' => 'A1', 'is_active' => '1',
        ])->assertRedirect(route('fixed-asset-locations.index'));
        $this->assertSame('Warehouse One', $location->fresh()->name);

        // The seeded asset uses this location, so deactivation is refused.
        $response = $this->actingAs($user)->from(route('fixed-asset-locations.index'))
            ->post(route('fixed-asset-locations.deactivate', $location->id));
        $response->assertRedirect(route('fixed-asset-locations.index'));
        $response->assertSessionHas('error');
        $this->assertNotSoftDeleted('fixed_asset_locations', ['id' => $location->id]);
    }

    // ------------------------------------------------------------------
    // Register, profile, label
    // ------------------------------------------------------------------

    public function test_register_index_renders_with_the_asset(): void
    {
        $response = $this->actingAs(User::find(1))->get(route('fixed-assets.index'));

        $response->assertOk();
        $response->assertSee('HOYA-IT-2026-0001');
        $response->assertSee('Laptop Dell XPS');
        $response->assertSee('Logistics');
    }

    public function test_register_show_renders_profile_with_qr(): void
    {
        $asset = FixedAsset::where('code', 'HOYA-IT-2026-0001')->first();

        $response = $this->actingAs(User::find(1))->get(route('fixed-assets.show', $asset->id));

        $response->assertOk();
        $response->assertSee('HOYA-IT-2026-0001');
        $response->assertSee('Laptop Dell XPS');
        $response->assertSee('SN-001');
        $response->assertSee('25,000.00');
    }

    public function test_label_renders_with_code_company_and_category(): void
    {
        $asset = FixedAsset::where('code', 'HOYA-IT-2026-0001')->first();

        $response = $this->actingAs(User::find(1))->get(route('fixed-assets.label', $asset->id));

        $response->assertOk();
        $response->assertSee('HOYA-IT-2026-0001');
        $response->assertSee('Laptop Dell XPS');
        $response->assertSee('Hoya Hoya');
        $response->assertSee('IT — IT Equipment');
        $response->assertSee('Warehouse 1');
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    public function test_settings_screen_and_save(): void
    {
        $user = User::find(1);

        $this->actingAs($user)->get(route('fixed-assets.settings'))->assertOk()->assertSee('Base Currency');

        $response = $this->actingAs($user)->post(route('fixed-assets.settings.save'), [
            'fixed_asset_base_currency' => 'MZN',
            'fixed_asset_reference_prefix' => 'HOYA',
            'fixed_asset_clearing_account_code' => '471',
            'fixed_asset_label_size' => 'large',
            'fixed_asset_approval_threshold_base' => '250000',
        ]);

        $response->assertRedirect(route('fixed-assets.settings'));
        $this->assertDatabaseHas('settings', ['name' => 'fixed_asset_clearing_account_code', 'value' => '471', 'created_by' => 1]);
        $this->assertDatabaseHas('settings', ['name' => 'fixed_asset_label_size', 'value' => 'large', 'created_by' => 1]);
        $this->assertDatabaseHas('settings', ['name' => 'fixed_asset_approval_threshold_base', 'value' => '250000.00', 'created_by' => 1]);
    }

    // ------------------------------------------------------------------
    // Permissions + tenant isolation
    // ------------------------------------------------------------------

    public function test_user_without_permission_is_denied(): void
    {
        $response = $this->actingAs(User::find(6))->from(route('dashboard'))
            ->get(route('fixed-asset-categories.index'));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');
    }

    public function test_cross_company_asset_is_denied(): void
    {
        $other = $this->numbering->allocateAndCreate(4, 'IT', [
            'category_id' => 1, 'name' => 'Other Laptop', 'status' => FixedAsset::STATUS_DRAFT,
        ]);

        $response = $this->actingAs(User::find(1))->from(route('fixed-assets.index'))
            ->get(route('fixed-assets.show', $other->id));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');
    }

    public function test_cross_company_category_edit_is_denied(): void
    {
        $other = $this->service->createCategory(['code' => 'IT', 'name' => 'IT Equipment'], 4, 4);

        $response = $this->actingAs(User::find(1))->from(route('fixed-asset-categories.index'))
            ->get(route('fixed-asset-categories.edit', $other->id));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');
    }

    public function test_operator_can_view_register_but_not_categories(): void
    {
        // Operator holds 'manage fixed assets' only.
        $this->actingAs(User::find(2))->get(route('fixed-assets.index'))->assertOk();

        $response = $this->actingAs(User::find(2))->from(route('fixed-assets.index'))
            ->get(route('fixed-asset-categories.index'));
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');
    }

    // ------------------------------------------------------------------
    // Tables
    // ------------------------------------------------------------------

    protected function createTables(): void
    {
        Schema::create('branches', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('branch_user', function ($table) {
            $table->bigIncrements('id');
            $table->integer('branch_id');
            $table->integer('user_id');
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
            $table->index(['role_id', 'permission_id']);
        });
        Schema::create('plans', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
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
        Schema::create('chart_of_accounts', function ($table) {
            $table->id();
            $table->string('name');
            $table->integer('code')->default(0);
            $table->integer('type')->default(0);
            $table->integer('sub_type')->default(0);
            $table->integer('is_enabled')->default(1);
            $table->text('description')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        // Minimal Cost Centre master (the real module's table).
        Schema::create('cost_centres', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('code', 50);
            $table->string('name');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('fixed_asset_categories', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('classification', 20)->default('tangible');
            $table->unsignedInteger('default_useful_life')->nullable();
            $table->string('default_life_unit', 10)->default('year');
            $table->string('depreciation_method', 30)->default('straight_line');
            $table->decimal('depreciation_rate', 10, 4)->nullable();
            $table->string('residual_value_type', 20)->default('none');
            $table->decimal('residual_value_percent', 10, 4)->nullable();
            $table->decimal('residual_value', 20, 4)->nullable();
            $table->decimal('capitalization_threshold', 20, 4)->nullable();
            $table->unsignedBigInteger('asset_account_id')->nullable();
            $table->unsignedBigInteger('accum_depreciation_account_id')->nullable();
            $table->unsignedBigInteger('depreciation_expense_account_id')->nullable();
            $table->unsignedBigInteger('impairment_account_id')->nullable();
            $table->unsignedBigInteger('disposal_gain_account_id')->nullable();
            $table->unsignedBigInteger('disposal_loss_account_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['created_by', 'code']);
        });
        Schema::create('fixed_asset_locations', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('code', 50);
            $table->string('name');
            $table->string('room', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['created_by', 'code']);
        });
        Schema::create('fixed_assets', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('cost_centre_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('custodian_id')->nullable();
            $table->unsignedBigInteger('parent_asset_id')->nullable();
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('brand', 100)->nullable();
            $table->string('barcode_value', 255)->nullable();
            $table->boolean('is_component')->default(false);
            $table->string('acquisition_method', 30)->default('purchase');
            $table->string('supplier', 255)->nullable();
            $table->string('po_reference', 100)->nullable();
            $table->string('invoice_reference', 100)->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('receipt_date')->nullable();
            $table->decimal('purchase_cost', 20, 4)->default(0);
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->default(1);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->decimal('capitalized_cost', 20, 4)->default(0);
            $table->date('warranty_start')->nullable();
            $table->date('warranty_end')->nullable();
            $table->date('capitalization_date')->nullable();
            $table->date('depreciation_start_date')->nullable();
            $table->unsignedInteger('useful_life')->nullable();
            $table->string('life_unit', 10)->default('year');
            $table->string('depreciation_method', 30)->default('straight_line');
            $table->decimal('depreciation_rate', 10, 4)->nullable();
            $table->decimal('residual_value', 20, 4)->default(0);
            $table->unsignedBigInteger('asset_account_id')->nullable();
            $table->unsignedBigInteger('accum_depreciation_account_id')->nullable();
            $table->unsignedBigInteger('depreciation_expense_account_id')->nullable();
            $table->decimal('opening_accumulated_depreciation', 20, 4)->default(0);
            $table->string('status', 30)->default('draft');
            $table->decimal('current_book_value', 20, 4)->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['created_by', 'code']);
        });
        Schema::create('fixed_asset_documents', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            $table->string('type', 30)->default('other');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->integer('uploaded_by');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('fixed_asset_approvals', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            $table->string('transaction_type', 30);
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedInteger('level')->default(1);
            $table->integer('requested_by');
            $table->integer('approver_id')->nullable();
            $table->string('decision', 20)->default('pending');
            $table->text('comment')->nullable();
            $table->decimal('amount_base', 20, 4)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
        Schema::create('fixed_asset_status_history', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->integer('changed_by');
            $table->text('reason')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('fixed_asset_audit_events', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->string('entity', 60);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('action', 60);
            $table->integer('actor_id');
            $table->text('reason')->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->timestamps();
        });
    }
}
