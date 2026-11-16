<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Plan;
use App\Models\User;
use App\Models\Utility;
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
 * Client issues — Issue 2: admin role permissions (sidebar vs module
 * mismatch) + the backend-gate half of Issue 1.
 *
 * Investigation result: the split is BY DESIGN. Module action
 * permissions ('manage invoice', 'view cost centres', ...) gate the
 * backend; `menu.*` permissions gate sidebar visibility, and
 * menu_can() falls back to legacy action permissions. The two sets
 * are separate checkbox tabs on the role form, so a role can hold
 * full module permissions with only partial sidebar grants. The fix
 * is DATA, not a redesign: the same backfill as Issue 1 grants the
 * matching menu.* permission for every menu node whose legacy action
 * permissions the role already holds — and never removes menu grants
 * on revoke.
 *
 * Tests: the menu rule itself, the never-remove rule, and the
 * end-to-end gate — an Admin-role user is denied on the direct
 * /cost-centres URL before the backfill and gets a 200 after it,
 * while a user without the permission is still denied.
 */
class ClientIssuesRoleMenuSyncTest extends TestCase
{
    protected User $adminUser;
    protected Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Utility::$rates = null;
        Utility::$data = null;
        self::resetSettingsCache();
        self::resetPlanCache();
        $this->createTables();
        $this->seedBase();
    }

    protected function tearDown(): void
    {
        Utility::$rates = null;
        Utility::$data = null;
        self::resetSettingsCache();
        self::resetPlanCache();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['cost_centres', 'branches', 'settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    protected static function resetSettingsCache(): void
    {
        $prop = new \ReflectionProperty(Utility::class, 'getsettingsid');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    protected static function resetPlanCache(): void
    {
        $prop = new \ReflectionProperty(Plan::class, 'getplans');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    // ------------------------------------------------------------------
    // Menu grants follow the action permissions the role holds
    // ------------------------------------------------------------------

    public function test_menu_grants_follow_held_action_permissions(): void
    {
        $role = Role::create(['name' => 'Admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['manage invoice', 'show account dashboard', 'view cost centres']);

        AdminRolePermissionBackfill::run();
        $role = $role->fresh();

        $this->assertTrue($role->hasPermissionTo('menu.dashboard.accounting'));
        $this->assertTrue($role->hasPermissionTo('menu.dashboard.accounting.cost_centres'));
        $this->assertTrue($role->hasPermissionTo('menu.dashboard.accounting.cost_centres.list'));
        $this->assertTrue($role->hasPermissionTo('menu.dashboard.accounting.cost_centres.dashboard'));

        // No treasury action permission held -> no treasury menu grant.
        $this->assertFalse($role->hasPermissionTo('menu.dashboard.accounting.treasury'));
        $this->assertFalse($role->hasPermissionTo('menu.dashboard.accounting.treasury.dashboard'));
    }

    public function test_menu_grants_are_never_removed(): void
    {
        $role = Role::create(['name' => 'Admin', 'guard_name' => 'web']);
        // Sidebar grant for a module the role has NO action permission for.
        $role->givePermissionTo(['menu.dashboard.accounting.treasury']);

        AdminRolePermissionBackfill::run();

        $this->assertTrue($role->fresh()->hasPermissionTo('menu.dashboard.accounting.treasury'));
    }

    // ------------------------------------------------------------------
    // Backend gates still enforce, before and after the backfill
    // ------------------------------------------------------------------

    public function test_admin_without_permission_is_denied_on_direct_url(): void
    {
        $this->actingAs($this->adminUser);

        $this->get(route('cost-centres.index'))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_admin_can_open_cost_centres_after_backfill(): void
    {
        AdminRolePermissionBackfill::run();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::find($this->adminUser->id);
        $this->actingAs($user);

        $this->get(route('cost-centres.index'))->assertOk();

        // The sidebar node is visible to this user through the catalog,
        // exactly as the menu blade checks it.
        $node = SidebarMenuPermissionCatalog::findNode('menu.dashboard.accounting.cost_centres');
        $this->assertNotNull($node);
        $this->assertTrue(SidebarMenuPermissionCatalog::userCanSeeNode($node, $user));
    }

    public function test_cost_centre_node_hidden_before_backfill(): void
    {
        $node = SidebarMenuPermissionCatalog::findNode('menu.dashboard.accounting.cost_centres');
        $this->assertNotNull($node);
        $this->assertFalse(SidebarMenuPermissionCatalog::userCanSeeNode($node, $this->adminUser));
    }

    // ------------------------------------------------------------------
    // Fixture: company user on the Administrador role WITHOUT any
    // cost-centre permission; plan with Accounting enabled.
    // ------------------------------------------------------------------

    protected function seedBase(): void
    {
        $this->adminUser = User::create([
            'name' => 'Matola Admin',
            'email' => 'matola-admin@test.local',
            'password' => bcrypt('password'),
            'type' => 'company',
            'created_by' => 1,
            'default_company_id' => 1,
            'is_enable_login' => 1,
            'lang' => 'en',
            'email_verified_at' => now(),
        ]);
        $this->adminUser->plan = 1;
        $this->adminUser->storage_limit = 100;
        $this->adminUser->save();

        DB::table('settings')->insert([
            ['name' => 'site_currency', 'value' => 'MZN', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'currency_symbol', 'value' => 'MZN', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'invoice_prefix', 'value' => '#Fatura', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('plans')->insert([
            ['id' => 1, 'name' => 'Test Plan', 'account' => 1, 'storage_limit' => 100, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('languages')->insert([
            ['id' => 1, 'code' => 'en', 'full_name' => 'English', 'created_at' => now(), 'updated_at' => now()],
        ]);

        Branch::create(['name' => 'Main Branch', 'created_by' => 1]);

        foreach (array_merge(
            AdminRolePermissionBackfill::COST_CENTRE_PERMISSIONS,
            ['manage invoice', 'show account dashboard', 'manage transaction', 'view treasury']
        ) as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }
        SidebarMenuPermissionCatalog::ensurePermissionsExist();

        $this->adminRole = Role::create(['name' => 'Administrador', 'guard_name' => 'web']);
        $this->adminRole->givePermissionTo(['manage invoice', 'show account dashboard']);
        $this->adminUser->assignRole($this->adminRole);
    }

    protected function createTables(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('default_warehouse_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('cost_centres', function (Blueprint $table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('manager_user_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['created_by', 'code'], 'cc_company_code_uq');
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
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
        Schema::create('plans', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('account')->default(0);
            $table->integer('storage_limit')->default(0);
            $table->timestamps();
        });
        Schema::create('languages', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code')->nullable();
            $table->string('full_name')->nullable();
            $table->timestamps();
        });
        Schema::create('ch_messages', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('to_id')->nullable();
            $table->integer('seen')->default(0);
            $table->timestamps();
        });
        Schema::create('email_templates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('from')->nullable();
            $table->string('slug')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
    }
}
