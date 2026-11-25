<?php

namespace Tests\Feature\FixedAssets;

use App\Models\FixedAssetCategory;
use App\Models\User;
use App\Services\FixedAssetService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Fixed Assets — inline "Create Category" from the asset form
 * (quick-store endpoint).
 *
 * Covers: happy path (creates the category, derives a short code,
 * returns JSON the form can select at once), empty-name and
 * duplicate-name validation, code suffix on a code clash,
 * per-company scoping of the duplicate check, permission denial
 * and guest denial.
 *
 * Users: 1 = Company (manage fixed asset categories), 2 =
 * Operator (no category permission), 4 = Other Company (manage
 * fixed asset categories).
 */
class FixedAssetCategoryQuickCreateTest extends TestCase
{
    protected FixedAssetService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::create(['name' => 'manage fixed asset categories', 'guard_name' => 'web']);
        Permission::create(['name' => 'manage transaction', 'guard_name' => 'web']);

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Hoya Hoya', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Operator', 'email' => 'operator@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Uthara', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['manage fixed asset categories']);
        User::findOrFail(2)->givePermissionTo(['manage transaction']);
        User::findOrFail(4)->givePermissionTo(['manage fixed asset categories']);

        $this->service = new FixedAssetService();
    }

    protected function tearDown(): void
    {
        foreach (['fixed_asset_audit_events', 'fixed_asset_categories', 'chart_of_accounts', 'cost_centres', 'email_templates', 'ch_messages', 'languages', 'plans', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'permissions', 'roles', 'users', 'settings', 'branch_user', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_quick_store_route_exists(): void
    {
        $this->assertTrue(Route::has('fixed-asset-categories.quick-store'));
    }

    public function test_quick_store_creates_category_and_returns_json(): void
    {
        $response = $this->actingAs(User::findOrFail(1))->postJson(
            route('fixed-asset-categories.quick-store'),
            ['name' => 'Office Furniture']
        );

        $response->assertStatus(201);
        $response->assertJsonStructure(['id', 'code', 'name', 'default_useful_life', 'default_life_unit', 'message']);
        $this->assertSame('OFFI', $response->json('code'));
        $this->assertSame('Office Furniture', $response->json('name'));

        $category = FixedAssetCategory::where('code', 'OFFI')->firstOrFail();
        $this->assertSame(1, (int) $category->created_by);
        $this->assertSame(1, (int) $category->company_id);
        $this->assertTrue((bool) $category->is_active);
        $this->assertSame(FixedAssetCategory::CLASSIFICATION_TANGIBLE, $category->classification);
        $this->assertSame(FixedAssetCategory::METHOD_STRAIGHT_LINE, $category->depreciation_method);

        // The creation is audited, like the full category form.
        $this->assertDatabaseHas('fixed_asset_audit_events', [
            'entity' => 'fixed_asset_category',
            'entity_id' => $category->id,
            'action' => 'created',
        ]);
    }

    public function test_quick_store_rejects_empty_name(): void
    {
        $response = $this->actingAs(User::findOrFail(1))->postJson(
            route('fixed-asset-categories.quick-store'),
            ['name' => '']
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
        $this->assertSame(0, FixedAssetCategory::count());
    }

    public function test_quick_store_rejects_duplicate_name_case_insensitive(): void
    {
        $this->service->createCategory([
            'code' => 'OFFI', 'name' => 'Office Furniture', 'classification' => 'tangible',
            'default_life_unit' => 'year', 'depreciation_method' => 'straight_line',
            'residual_value_type' => 'none',
        ], 1, 1);

        $response = $this->actingAs(User::findOrFail(1))->postJson(
            route('fixed-asset-categories.quick-store'),
            ['name' => 'office furniture']
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
        $this->assertSame(1, FixedAssetCategory::count());
    }

    public function test_quick_store_same_name_in_another_company_is_allowed(): void
    {
        $this->service->createCategory([
            'code' => 'OFFI', 'name' => 'Office Furniture', 'classification' => 'tangible',
            'default_life_unit' => 'year', 'depreciation_method' => 'straight_line',
            'residual_value_type' => 'none',
        ], 1, 1);

        $response = $this->actingAs(User::findOrFail(4))->postJson(
            route('fixed-asset-categories.quick-store'),
            ['name' => 'Office Furniture']
        );

        $response->assertStatus(201);
        $this->assertSame('OFFI', $response->json('code'));
        $this->assertSame(2, FixedAssetCategory::count());
        $this->assertSame(1, FixedAssetCategory::where('created_by', 4)->count());
    }

    public function test_quick_store_suffixes_code_on_clash(): void
    {
        $this->service->createCategory([
            'code' => 'OFFI', 'name' => 'Office Tools', 'classification' => 'tangible',
            'default_life_unit' => 'year', 'depreciation_method' => 'straight_line',
            'residual_value_type' => 'none',
        ], 1, 1);

        $response = $this->actingAs(User::findOrFail(1))->postJson(
            route('fixed-asset-categories.quick-store'),
            ['name' => 'Office Furniture']
        );

        $response->assertStatus(201);
        $this->assertSame('OFFI1', $response->json('code'));
    }

    public function test_quick_store_denies_without_permission(): void
    {
        $response = $this->actingAs(User::findOrFail(2))->postJson(
            route('fixed-asset-categories.quick-store'),
            ['name' => 'Office Furniture']
        );

        $response->assertStatus(403);
        $this->assertSame(0, FixedAssetCategory::count());
    }

    public function test_quick_store_guest_is_unauthorized(): void
    {
        $response = $this->postJson(
            route('fixed-asset-categories.quick-store'),
            ['name' => 'Office Furniture']
        );

        $response->assertStatus(401);
        $this->assertSame(0, FixedAssetCategory::count());
    }

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
