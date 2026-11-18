<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AdminRolePermissionBackfill;
use App\Services\SidebarMenuPermissionCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Client follow-up (2026-11-17) — Document Layouts + Plan/Referral
 * access for admin-type roles, verified on the test site as Neyde
 * (Administrador, MMCEMENT) and Danilo (company).
 *
 * 1. Document Layouts: Neyde got 403 PERMISSÃO NEGADA on
 *    /document-layouts and no menu item, while Danilo (company)
 *    saw the page fine. Root cause: the 'manage document layouts'
 *    permission ROW does not exist in databases created before the
 *    seeder change that defines it, so no role can hold it — and
 *    Admin/Administrador roles hold none of the three gate
 *    permissions ('manage document layouts', the menu grant,
 *    'manage print settings').
 * 2. Plan + Referral: Neyde's Settings submenu shows only
 *    "Configurações do sistema" — no Setup Subscription Plan or
 *    Referral Program. Those grants ('manage plan',
 *    'menu.settings.plans', 'menu.settings.referral') live only on
 *    the seeded `company` role.
 *
 * Fix: migration 310009 creates the missing permission row and
 * grants all five permissions to Admin / Administrador / company
 * roles in every company. Migration 310010 (2026-11-18) extends the
 * same backfill to roles named 'Administrator' (temp roles 10 + 194),
 * which 310009's exact-name match had missed. Cashier and other roles
 * are untouched.
 */
class ClientIssuesFollowupPermissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->createTables();
        $this->seedPermissions();
        $this->seedBaseRows();
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['document_layouts', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'settings', 'languages', 'email_templates', 'ch_messages', 'plans', 'branches', 'branch_user'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Backfill grants the layout + plan permissions to admin roles
    // ------------------------------------------------------------------

    public function test_backfill_grants_layout_and_plan_permissions_to_admin_roles(): void
    {
        $admin = Role::create(['name' => 'Administrador', 'guard_name' => 'web']);
        $admin->givePermissionTo(['manage invoice']);

        $otherCompanyAdmin = Role::create(['name' => 'Admin', 'guard_name' => 'web']);

        $company = Role::create(['name' => 'company', 'guard_name' => 'web']);
        $company->givePermissionTo(['manage plan', 'menu.settings.plans', 'menu.settings.referral']);

        AdminRolePermissionBackfill::runLayoutAndPlanBackfill();

        foreach (AdminRolePermissionBackfill::LAYOUT_AND_PLAN_PERMISSIONS as $name) {
            $this->assertTrue($admin->fresh()->hasPermissionTo($name), "Administrador should have {$name}");
            $this->assertTrue($otherCompanyAdmin->fresh()->hasPermissionTo($name), "Admin (other company) should have {$name}");
            $this->assertTrue($company->fresh()->hasPermissionTo($name), "company should have {$name}");
        }

        // No duplicate pivot rows for the role that already had three.
        $this->assertSame(
            count(AdminRolePermissionBackfill::LAYOUT_AND_PLAN_PERMISSIONS),
            DB::table('role_has_permissions')
                ->where('role_id', $company->id)
                ->whereIn('permission_id', Permission::whereIn('name', AdminRolePermissionBackfill::LAYOUT_AND_PLAN_PERMISSIONS)->pluck('id')->all())
                ->count()
        );
    }

    public function test_backfill_creates_the_missing_manage_document_layouts_permission(): void
    {
        // Temp/live reality: the permission ROW itself is missing
        // (defined in UsersTableSeeder only for fresh installs).
        Permission::where('name', 'manage document layouts')->delete();
        $this->assertNull(Permission::where('name', 'manage document layouts')->first());

        $admin = Role::create(['name' => 'Administrador', 'guard_name' => 'web']);

        AdminRolePermissionBackfill::runLayoutAndPlanBackfill();

        $this->assertNotNull(Permission::where('name', 'manage document layouts')->first());
        $this->assertTrue($admin->fresh()->hasPermissionTo('manage document layouts'));
    }

    public function test_backfill_is_idempotent(): void
    {
        $admin = Role::create(['name' => 'Administrador', 'guard_name' => 'web']);
        $admin->givePermissionTo(['manage invoice']);

        AdminRolePermissionBackfill::runLayoutAndPlanBackfill();
        $firstCount = DB::table('role_has_permissions')->where('role_id', $admin->id)->count();

        AdminRolePermissionBackfill::runLayoutAndPlanBackfill();
        $this->assertSame($firstCount, DB::table('role_has_permissions')->where('role_id', $admin->id)->count());
        $this->assertSame(
            1 + count(AdminRolePermissionBackfill::LAYOUT_AND_PLAN_PERMISSIONS),
            $firstCount
        );
    }

    public function test_backfill_does_not_touch_other_roles(): void
    {
        $cashier = Role::create(['name' => 'Cashier', 'guard_name' => 'web']);
        $cashier->givePermissionTo(['manage invoice']);

        $admin = Role::create(['name' => 'Administrador', 'guard_name' => 'web']);

        AdminRolePermissionBackfill::runLayoutAndPlanBackfill();

        foreach (AdminRolePermissionBackfill::LAYOUT_AND_PLAN_PERMISSIONS as $name) {
            $this->assertFalse($cashier->fresh()->hasPermissionTo($name), "Cashier must NOT gain {$name}");
        }
        $this->assertSame(1, DB::table('role_has_permissions')->where('role_id', $cashier->id)->count());
        $this->assertTrue($admin->fresh()->hasPermissionTo('manage document layouts'));
    }

    public function test_backfill_grants_layout_and_plan_permissions_to_administrator_named_role(): void
    {
        // Roles named 'Administrator' (temp roles 10 + 194) were missed
        // by 310009: the backfill matched names exactly and the list
        // had only Admin / Administrador / company. Azmira (Hoya) got
        // 403 on /document-layouts while Neyde worked.
        $administrator = Role::create(['name' => 'Administrator', 'guard_name' => 'web']);

        AdminRolePermissionBackfill::runLayoutAndPlanBackfill();

        foreach (AdminRolePermissionBackfill::LAYOUT_AND_PLAN_PERMISSIONS as $name) {
            $this->assertTrue($administrator->fresh()->hasPermissionTo($name), "Administrator should have {$name}");
        }
    }

    public function test_run_grants_cost_centre_permissions_to_administrator_named_role(): void
    {
        // Same name-matching gap in the cost-centre backfill (310007):
        // 'Administrator' roles missed those permissions too.
        $administrator = Role::create(['name' => 'Administrator', 'guard_name' => 'web']);

        AdminRolePermissionBackfill::run();

        foreach (AdminRolePermissionBackfill::COST_CENTRE_PERMISSIONS as $name) {
            $this->assertTrue($administrator->fresh()->hasPermissionTo($name), "Administrator should have {$name}");
        }
    }

    // ------------------------------------------------------------------
    // New-module backfill (2026-11-19) — Kitchen, Treasury, Fixed Assets
    // ------------------------------------------------------------------

    public function test_new_module_backfill_grants_kitchen_treasury_fixed_asset_permissions_to_admin_roles(): void
    {
        // Neyde (Colosso 'Admin', MMCEMENT 'Administrador') sees only
        // Cost Centres / Contracts / Bank Reconciliation: her roles
        // hold none of the Kitchen, Treasury or Fixed Assets
        // permissions, while the seeded `company` role holds all 89.
        $admin = Role::create(['name' => 'Admin', 'guard_name' => 'web']);
        $administrador = Role::create(['name' => 'Administrador', 'guard_name' => 'web']);
        // Rows the fixture does not seed yet — create them so the
        // "already held some" setup can grant them before the backfill.
        foreach (['manage treasury dimensions', 'manage kitchen production', 'menu.kitchen_production'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $administrador->givePermissionTo(['manage treasury dimensions']);
        $company = Role::create(['name' => 'company', 'guard_name' => 'web']);
        $company->givePermissionTo(['manage kitchen production', 'menu.kitchen_production']);

        AdminRolePermissionBackfill::runNewModuleBackfill();

        foreach (AdminRolePermissionBackfill::NEW_MODULE_PERMISSIONS as $name) {
            $this->assertTrue($admin->fresh()->hasPermissionTo($name), "Admin should have {$name}");
            $this->assertTrue($administrador->fresh()->hasPermissionTo($name), "Administrador should have {$name}");
            $this->assertTrue($company->fresh()->hasPermissionTo($name), "company should have {$name}");
        }

        // No duplicate pivot rows for the roles that already held some.
        $this->assertSame(
            count(AdminRolePermissionBackfill::NEW_MODULE_PERMISSIONS),
            DB::table('role_has_permissions')
                ->where('role_id', $company->id)
                ->whereIn('permission_id', Permission::whereIn('name', AdminRolePermissionBackfill::NEW_MODULE_PERMISSIONS)->pluck('id')->all())
                ->count()
        );
    }

    public function test_new_module_backfill_is_idempotent(): void
    {
        $admin = Role::create(['name' => 'Admin', 'guard_name' => 'web']);

        AdminRolePermissionBackfill::runNewModuleBackfill();
        $firstCount = DB::table('role_has_permissions')->where('role_id', $admin->id)->count();

        AdminRolePermissionBackfill::runNewModuleBackfill();
        $this->assertSame($firstCount, DB::table('role_has_permissions')->where('role_id', $admin->id)->count());
        $this->assertSame(count(AdminRolePermissionBackfill::NEW_MODULE_PERMISSIONS), $firstCount);
    }

    public function test_new_module_backfill_does_not_touch_other_roles(): void
    {
        $cashier = Role::create(['name' => 'Cashier', 'guard_name' => 'web']);
        $cashier->givePermissionTo(['manage invoice']);

        $admin = Role::create(['name' => 'Admin', 'guard_name' => 'web']);

        AdminRolePermissionBackfill::runNewModuleBackfill();

        foreach (AdminRolePermissionBackfill::NEW_MODULE_PERMISSIONS as $name) {
            $this->assertFalse($cashier->fresh()->hasPermissionTo($name), "Cashier must NOT gain {$name}");
        }
        $this->assertSame(1, DB::table('role_has_permissions')->where('role_id', $cashier->id)->count());
        $this->assertTrue($admin->fresh()->hasPermissionTo('manage kitchen production'));
    }

    public function test_menu_nodes_become_visible_to_admin_users_after_new_module_backfill(): void
    {
        $admin = Role::create(['name' => 'Admin', 'guard_name' => 'web']);
        $user = $this->makeAdminUser();
        $user->assignRole($admin);

        $nodes = ['menu.kitchen_production', 'menu.dashboard.accounting.treasury', 'menu.dashboard.accounting.fixed_assets'];
        foreach ($nodes as $menu) {
            $node = SidebarMenuPermissionCatalog::findNode($menu);
            $this->assertNotNull($node, $menu);
            $this->assertFalse(SidebarMenuPermissionCatalog::userCanSeeNode($node, $user->fresh()), "{$menu} must be hidden before the backfill");
        }

        AdminRolePermissionBackfill::runNewModuleBackfill();

        foreach ($nodes as $menu) {
            $node = SidebarMenuPermissionCatalog::findNode($menu);
            $this->assertTrue(SidebarMenuPermissionCatalog::userCanSeeNode($node, $user->fresh()), "{$menu} must be visible after the backfill");
        }
    }

    // ------------------------------------------------------------------
    // Menu nodes become visible to an admin user after the backfill
    // ------------------------------------------------------------------

    public function test_menu_nodes_become_visible_to_admin_users_after_backfill(): void
    {
        $admin = Role::create(['name' => 'Administrador', 'guard_name' => 'web']);
        $user = $this->makeAdminUser();
        $user->assignRole($admin);

        foreach (['menu.accounting_system.layout_management', 'menu.settings.plans', 'menu.settings.referral'] as $menu) {
            $node = SidebarMenuPermissionCatalog::findNode($menu);
            $this->assertNotNull($node, $menu);
            $this->assertFalse(SidebarMenuPermissionCatalog::userCanSeeNode($node, $user->fresh()), "{$menu} must be hidden before the backfill");
        }

        AdminRolePermissionBackfill::runLayoutAndPlanBackfill();

        foreach (['menu.accounting_system.layout_management', 'menu.settings.plans', 'menu.settings.referral'] as $menu) {
            $node = SidebarMenuPermissionCatalog::findNode($menu);
            $this->assertTrue(SidebarMenuPermissionCatalog::userCanSeeNode($node, $user->fresh()), "{$menu} must be visible after the backfill");
        }
    }

    // ------------------------------------------------------------------
    // HTTP: /document-layouts 403s without the permission, 200s after
    // ------------------------------------------------------------------

    public function test_document_layouts_page_denies_admin_without_permission_and_allows_after_backfill(): void
    {
        $admin = Role::create(['name' => 'Administrador', 'guard_name' => 'web']);
        $user = $this->makeAdminUser();
        $user->assignRole($admin);
        $this->actingAs($user);

        $this->get('/document-layouts')->assertStatus(403);

        AdminRolePermissionBackfill::runLayoutAndPlanBackfill();

        $this->get('/document-layouts')->assertStatus(200);
    }

    public function test_document_layouts_page_allows_administrator_role_after_backfill(): void
    {
        $administrator = Role::create(['name' => 'Administrator', 'guard_name' => 'web']);
        $user = $this->makeAdminUser();
        $user->assignRole($administrator);
        $this->actingAs($user);

        $this->get('/document-layouts')->assertStatus(403);

        AdminRolePermissionBackfill::runLayoutAndPlanBackfill();

        $this->get('/document-layouts')->assertStatus(200);
    }

    // ------------------------------------------------------------------
    // Fixture
    // ------------------------------------------------------------------

    protected function makeAdminUser(): User
    {
        return User::create([
            'name' => 'Neyde Bata',
            'email' => 'neyde.bata@test.local',
            'password' => bcrypt('password'),
            'type' => 'Administrador',
            'created_by' => 1,
            'default_company_id' => 1,
            'is_enable_login' => 1,
            'is_active' => 1,
            'lang' => 'pt',
            'email_verified_at' => now(),
        ]);
    }

    protected function seedPermissions(): void
    {
        foreach (array_merge(
            AdminRolePermissionBackfill::LAYOUT_AND_PLAN_PERMISSIONS,
            ['manage invoice', 'show account dashboard']
        ) as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        SidebarMenuPermissionCatalog::ensurePermissionsExist();
    }

    protected function seedBaseRows(): void
    {
        DB::table('languages')->insert([
            ['id' => 1, 'code' => 'en', 'full_name' => 'English', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'code' => 'pt', 'full_name' => 'Portuguese', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('settings')->insert([
            ['name' => 'default_language', 'value' => 'pt', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function createTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
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
            $table->string('plan')->nullable();
            $table->integer('storage_limit')->default(0);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->integer('permission_id');
            $table->string('model_type');
            $table->integer('model_id');
            $table->index(['model_id', 'model_type']);
        });
        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->integer('role_id');
            $table->string('model_type');
            $table->integer('model_id');
            $table->index(['model_id', 'model_type']);
        });
        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->integer('permission_id');
            $table->integer('role_id');
            $table->index(['role_id', 'permission_id']);
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('languages', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code')->nullable();
            $table->string('full_name')->nullable();
            $table->timestamps();
        });
        Schema::create('document_layouts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by');
            $table->string('document_type', 32);
            $table->string('name');
            $table->string('base_template', 64)->nullable();
            $table->string('print_template_key', 64)->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('page_width')->default(794);
            $table->unsignedInteger('page_height')->default(1123);
            $table->json('elements')->nullable();
            $table->timestamps();
        });
        // The admin layout reads these on every page render.
        Schema::create('email_templates', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('from')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('ch_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('from_id')->nullable();
            $table->integer('to_id')->nullable();
            $table->text('body')->nullable();
            $table->timestamps();
        });
        // The admin layout looks up the user's plan.
        Schema::create('plans', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('storage_limit')->default(0);
            $table->integer('max_customers')->default(-1);
            $table->tinyInteger('crm')->default(1);
            $table->tinyInteger('project')->default(1);
            $table->tinyInteger('hrm')->default(1);
            $table->tinyInteger('account')->default(1);
            $table->tinyInteger('pos')->default(1);
            $table->tinyInteger('stock')->default(1);
            $table->timestamps();
        });
        // The admin layout loads the user's branches.
        Schema::create('branches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('default_warehouse_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('branch_user', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('branch_id');
            $table->integer('user_id');
            $table->timestamps();
        });
    }
}
