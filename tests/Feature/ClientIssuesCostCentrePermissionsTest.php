<?php

namespace Tests\Feature;

use App\Services\SidebarMenuPermissionCatalog;
use App\Services\AdminRolePermissionBackfill;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Client issues — Issue 1: Admin/Administrador roles lack the
 * cost-centre ACTION permissions.
 *
 * Root cause: the 13 cost-centre permissions match no
 * `{prefix} {module}` row in RolePermissionUiCatalog, so they never
 * appear on the role form — only the seeded `company` role has them
 * (via UsersTableSeeder). Any custom Admin / Administrador role
 * created through the UI therefore lacks them, and both the Cost
 * Centres menu and the direct URL deny access.
 *
 * Fix: migration 310007 backfills the 13 permissions (and the
 * matching menu.* grants) onto Admin / Administrador / company
 * roles in every company; the seeder gives them to the `accountant`
 * role too. Cashier / Waiter / Manager / Bar / Kitchen roles are
 * untouched.
 */
class ClientIssuesCostCentrePermissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->createTables();
        $this->seedPermissions();
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Backfill grants the cost-centre permissions to admin roles
    // ------------------------------------------------------------------

    public function test_backfill_grants_all_cost_centre_permissions_to_admin_roles(): void
    {
        $admin = Role::create(['name' => 'Administrador', 'guard_name' => 'web']);
        $admin->givePermissionTo(['manage invoice', 'show account dashboard', 'menu.dashboard.accounting.cost_centres']);

        $otherCompanyAdmin = Role::create(['name' => 'Admin', 'guard_name' => 'web']);

        $company = Role::create(['name' => 'company', 'guard_name' => 'web']);
        $company->givePermissionTo(AdminRolePermissionBackfill::COST_CENTRE_PERMISSIONS);

        AdminRolePermissionBackfill::run();

        foreach (AdminRolePermissionBackfill::COST_CENTRE_PERMISSIONS as $name) {
            $this->assertTrue($admin->fresh()->hasPermissionTo($name), "Administrador should have {$name}");
            $this->assertTrue($otherCompanyAdmin->fresh()->hasPermissionTo($name), "Admin (other company) should have {$name}");
            $this->assertTrue($company->fresh()->hasPermissionTo($name), "company should keep {$name}");
        }

        // No duplicate pivot rows for the role that already had them.
        $this->assertSame(
            count(AdminRolePermissionBackfill::COST_CENTRE_PERMISSIONS),
            DB::table('role_has_permissions')
                ->where('role_id', $company->id)
                ->whereIn('permission_id', Permission::whereIn('name', AdminRolePermissionBackfill::COST_CENTRE_PERMISSIONS)->pluck('id')->all())
                ->count()
        );
    }

    public function test_backfill_is_idempotent(): void
    {
        $admin = Role::create(['name' => 'Administrador', 'guard_name' => 'web']);
        $admin->givePermissionTo(['manage invoice']);

        AdminRolePermissionBackfill::run();
        $firstCount = DB::table('role_has_permissions')->where('role_id', $admin->id)->count();

        AdminRolePermissionBackfill::run();
        $this->assertSame($firstCount, DB::table('role_has_permissions')->where('role_id', $admin->id)->count());

        // The total is 1 ('manage invoice') + the 13 cost-centre
        // permissions + the menu.* grants matching 'manage invoice'.
        $this->assertGreaterThan(
            count(AdminRolePermissionBackfill::COST_CENTRE_PERMISSIONS) + 1,
            $firstCount
        );
        foreach (AdminRolePermissionBackfill::COST_CENTRE_PERMISSIONS as $name) {
            $this->assertTrue($admin->fresh()->hasPermissionTo($name));
        }
    }

    public function test_backfill_does_not_touch_other_roles(): void
    {
        $cashier = Role::create(['name' => 'Cashier', 'guard_name' => 'web']);
        $cashier->givePermissionTo(['manage invoice']);

        $admin = Role::create(['name' => 'Administrador', 'guard_name' => 'web']);

        AdminRolePermissionBackfill::run();

        foreach (AdminRolePermissionBackfill::COST_CENTRE_PERMISSIONS as $name) {
            $this->assertFalse($cashier->fresh()->hasPermissionTo($name), "Cashier must NOT gain {$name}");
        }
        $this->assertSame(1, DB::table('role_has_permissions')->where('role_id', $cashier->id)->count());
        $this->assertTrue($admin->fresh()->hasPermissionTo('view cost centres'));
    }

    public function test_backfill_grants_cost_centre_menu_grants(): void
    {
        $admin = Role::create(['name' => 'Administrador', 'guard_name' => 'web']);
        // The brief's premise: the role already HAS the sidebar grant
        // for Cost Centres, but lacks the action permission.
        $admin->givePermissionTo(['manage invoice', 'show account dashboard', 'menu.dashboard.accounting.cost_centres']);

        AdminRolePermissionBackfill::run();
        $admin = $admin->fresh();

        // The sidebar grant it had stays, and the child nodes follow
        // from the newly granted action permissions.
        $this->assertTrue($admin->hasPermissionTo('menu.dashboard.accounting.cost_centres'));
        $this->assertTrue($admin->hasPermissionTo('menu.dashboard.accounting.cost_centres.list'));
        $this->assertTrue($admin->hasPermissionTo('menu.dashboard.accounting.cost_centres.dashboard'));
        // The Accounting section above it follows from 'show account dashboard'.
        $this->assertTrue($admin->hasPermissionTo('menu.dashboard.accounting'));

        // The sidebar node is visible through the catalog exactly as
        // the menu blade checks it — with no user it is not visible.
        $node = SidebarMenuPermissionCatalog::findNode('menu.dashboard.accounting.cost_centres');
        $this->assertNotNull($node);
        $this->assertFalse(SidebarMenuPermissionCatalog::userCanSeeNode($node, null));
    }

    // ------------------------------------------------------------------
    // Fixture
    // ------------------------------------------------------------------

    protected function seedPermissions(): void
    {
        foreach (array_merge(
            AdminRolePermissionBackfill::COST_CENTRE_PERMISSIONS,
            ['manage invoice', 'show account dashboard', 'manage transaction', 'view treasury']
        ) as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        SidebarMenuPermissionCatalog::ensurePermissionsExist();
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
    }
}
