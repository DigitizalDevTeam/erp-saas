<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Restaurant POS floor designer ("Layout") — UI-only page.
 *
 * Covers: the designer route renders for an authenticated company user,
 * the page contains the canvas + palette + properties panel, the sidebar
 * shows the Layout link (gated by the same menu permission as POS Tables),
 * and guests are redirected to login.
 *
 * NOTE: pos.restaurant.layout (GET pos/restaurant-layout) is the live POS
 * table-data JSON API used by pos/index-restaurant.blade.php, so the
 * designer deliberately lives at pos/restaurant/layout-designer with the
 * route name pos.restaurant.layout.designer.
 */
class PosRestaurantLayoutTest extends TestCase
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

        // The admin header's branch switcher calls
        // User::branchesForSelection() on every authenticated page render.
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

        // Tables the admin layout / view composers touch when a Blade
        // page renders.
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

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::create(['name' => 'manage pos', 'guard_name' => 'web']);

        // The sidebar's POS System group only renders when the user's plan
        // has pos = 1. Plan::getPlan() memoizes the first plan it loads in
        // a private static, so reset it here — another test file running
        // earlier in the same process must not decide our menu visibility.
        $prop = new \ReflectionProperty(\App\Models\Plan::class, 'getplans');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        DB::table('plans')->insert([
            ['id' => 1, 'name' => 'Test Plan', 'pos' => 1],
        ]);

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'plan' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'plan' => null, 'email_verified_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['manage pos']);
    }

    protected function tearDown(): void
    {
        foreach (['email_templates', 'ch_messages', 'languages', 'plans', 'branch_user', 'branches', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'permissions', 'roles', 'settings', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_company_user_can_open_layout_designer(): void
    {
        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('pos.restaurant.layout.designer'));

        $response->assertOk();
        $response->assertSee('posLayoutCanvas', false);
        $response->assertSee('posLayoutPalette', false);
        $response->assertSee('posLayoutProps', false);
        $response->assertSee('Round 4', false);
        $response->assertSee('Bar Counter', false);
        // Sidebar menu contains the Layout link.
        $response->assertSee(route('pos.restaurant.layout.designer'), false);
        // The live POS table-data API route is untouched by this feature.
        $this->assertNotSame(
            route('pos.restaurant.layout'),
            route('pos.restaurant.layout.designer')
        );
    }

    public function test_layout_menu_link_hidden_without_pos_permission(): void
    {
        $response = $this->actingAs(User::findOrFail(2))
            ->get(route('pos.restaurant.layout.designer'));

        $response->assertOk();
        $response->assertDontSee(route('pos.restaurant.layout.designer'), false);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('pos.restaurant.layout.designer'))
            ->assertRedirect(route('login'));
    }
}
