<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Models\Utility;
use App\Services\HotelPermissionCatalog;
use App\Services\SidebarMenuPermissionCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Client issues — Role Management update issue (Riz 2026-11-17).
 *
 * ROOT CAUSE (proven on the temp DB, NOT a cache problem): the role edit
 * form only renders the tabs the EDITOR's plan allows (CRM/Project/HRM/
 * Account/POS are gated on $userPlan flags; the super admin's plan is
 * null, so every gated tab hides for them). RoleController::update() used
 * to preserve only permissions outside assignableNames() — but CRM /
 * Project / HRM / Account / POS / Hotel permissions ARE assignable, so
 * every save silently deleted them from the role. On the temp DB the
 * MMCEMENT 'Administrador' role already holds ZERO CRM/Project
 * permissions (stripped by earlier edits) while 'RH/ Contabilidade'
 * still holds 89 that the next edit would wipe.
 *
 * FIX: update() only syncs within the permissions the form actually
 * controls for this editor (RolePermissionUiCatalog::formControlledNames,
 * mirroring the blade's tab conditions); everything else is preserved.
 *
 * Tests: a Top ++ editor (crm=0, project=0) keeps hidden CRM/Project
 * permissions while rendered-tab changes still apply; a super admin
 * (null plan) keeps every gated-module permission; a user holding the
 * role keeps access after the edit; a forged hidden permission id is
 * ignored; a stock-only plan keeps POS specials + POS module permissions.
 */
class ClientIssuesRoleHiddenTabPreserveTest extends TestCase
{
    protected User $topEditor;
    protected User $superEditor;
    protected Role $targetRole;

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

        foreach (['settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates', 'migrations'] as $table) {
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
    // Top ++ editor: hidden CRM/Project permissions survive the save
    // ------------------------------------------------------------------

    public function test_top_plus_plus_editor_keeps_hidden_crm_and_project_permissions(): void
    {
        $this->actingAs($this->topEditor);

        $keep = $this->permissionIds([
            'manage client', 'manage invoice', 'manage employee',
            'manage warehouse', 'give pos credit', 'reject invoice authorization',
            'manage hotel room', 'manage language',
            'menu.dashboard.accounting',
        ]);
        // Deliberately NOT submitted: 'manage role' (staff, rendered) and
        // 'menu.dashboard.accounting.cost_centres' (sidebar, rendered).
        $this->put(route('roles.update', $this->targetRole), [
            'name' => 'Administrador',
            'permissions' => $keep,
        ])->assertRedirect(route('roles.index'));

        $role = $this->targetRole->fresh();

        // Hidden tabs: untouched, even though never submitted.
        $this->assertTrue($role->hasPermissionTo('manage lead'));
        $this->assertTrue($role->hasPermissionTo('create deal'));
        $this->assertTrue($role->hasPermissionTo('manage project'));
        $this->assertTrue($role->hasPermissionTo('create milestone'));
        // Non-catalog grant (Issue 1/2 backfill): never rendered, preserved.
        $this->assertTrue($role->hasPermissionTo('view cost centres'));

        // Rendered tabs: the form still controls them.
        $this->assertTrue($role->hasPermissionTo('manage client'));
        $this->assertTrue($role->hasPermissionTo('manage invoice'));
        $this->assertTrue($role->hasPermissionTo('manage employee'));
        $this->assertTrue($role->hasPermissionTo('manage warehouse'));
        $this->assertTrue($role->hasPermissionTo('give pos credit'));
        $this->assertTrue($role->hasPermissionTo('reject invoice authorization'));
        $this->assertTrue($role->hasPermissionTo('manage hotel room'));
        $this->assertFalse($role->hasPermissionTo('manage role'));
        $this->assertTrue($role->hasPermissionTo('menu.dashboard.accounting'));
        $this->assertFalse($role->hasPermissionTo('menu.dashboard.accounting.cost_centres'));
    }

    // ------------------------------------------------------------------
    // Super admin (plan null): every gated tab is hidden, all preserved
    // ------------------------------------------------------------------

    public function test_super_admin_with_null_plan_keeps_all_gated_module_permissions(): void
    {
        $this->markAllMigrationsExecuted();
        $this->actingAs($this->superEditor);

        // Submit ONLY staff + one sidebar permission, as the form would
        // render for a null-plan editor — plus 'manage hotel room', which
        // DOES render (the editor's map holds hotel permissions).
        $this->put(route('roles.update', $this->targetRole), [
            'name' => 'Administrador',
            'permissions' => $this->permissionIds([
                'manage client', 'menu.dashboard.accounting', 'manage hotel room',
            ]),
        ])->assertRedirect(route('roles.index'));

        $role = $this->targetRole->fresh();

        foreach (['manage lead', 'create deal', 'manage project', 'create milestone',
            'manage employee', 'manage invoice', 'manage warehouse', 'manage pos',
            'give pos credit', 'reject invoice authorization', 'manage hotel room',
            'view cost centres'] as $name) {
            $this->assertTrue($role->hasPermissionTo($name), "expected {$name} to survive a super-admin edit");
        }
        // 'manage language' renders only for type company, not super admin.
        $this->assertTrue($role->hasPermissionTo('manage language'));
        // Rendered staff tab: the form still controls it.
        $this->assertTrue($role->hasPermissionTo('manage client'));
        $this->assertFalse($role->hasPermissionTo('manage role'));
    }

    // ------------------------------------------------------------------
    // A real user holding the role keeps access after the edit
    // ------------------------------------------------------------------

    public function test_role_user_keeps_access_after_hidden_tab_edit(): void
    {
        $member = User::create([
            'name' => 'Role Member', 'email' => 'member@test.local',
            'password' => bcrypt('password'), 'type' => 'Employee',
            'created_by' => $this->topEditor->id, 'default_company_id' => $this->topEditor->id,
            'is_enable_login' => 1, 'lang' => 'en', 'email_verified_at' => now(),
        ]);
        $member->assignRole($this->targetRole);

        $this->actingAs($this->topEditor);
        $this->put(route('roles.update', $this->targetRole), [
            'name' => 'Administrador',
            'permissions' => $this->permissionIds(['manage client', 'manage invoice']),
        ])->assertRedirect(route('roles.index'));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $member = User::find($member->id);

        $this->assertTrue($member->can('manage lead'));
        $this->assertTrue($member->can('manage project'));
        $this->assertFalse($member->can('manage role'));
    }

    // ------------------------------------------------------------------
    // A forged hidden-tab permission id cannot grant what the form hides
    // ------------------------------------------------------------------

    public function test_forged_hidden_permission_id_is_ignored(): void
    {
        $bareRole = Role::create(['name' => 'Bare', 'guard_name' => 'web']);
        $bareRole->givePermissionTo(['manage client']);
        $this->assertFalse($bareRole->hasPermissionTo('manage lead'));

        $this->actingAs($this->topEditor);
        $this->put(route('roles.update', $bareRole), [
            'name' => 'Bare',
            'permissions' => $this->permissionIds(['manage client', 'manage lead']),
        ])->assertRedirect(route('roles.index'));

        $this->assertFalse($bareRole->fresh()->hasPermissionTo('manage lead'));
        $this->assertTrue($bareRole->fresh()->hasPermissionTo('manage client'));
    }

    // ------------------------------------------------------------------
    // Stock-only plan (pos=0, stock=1): POS specials + POS modules kept
    // ------------------------------------------------------------------

    public function test_stock_only_plan_keeps_pos_specials_and_pos_module_permissions(): void
    {
        DB::table('plans')->insert([
            'id' => 4, 'name' => 'Stock Only', 'account' => 1, 'crm' => 0,
            'project' => 0, 'hrm' => 1, 'pos' => 0, 'stock' => 1,
            'storage_limit' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $stockEditor = User::create([
            'name' => 'Stock Editor', 'email' => 'stock@test.local',
            'password' => bcrypt('password'), 'type' => 'company',
            'created_by' => 1, 'default_company_id' => 1,
            'is_enable_login' => 1, 'lang' => 'en', 'email_verified_at' => now(),
        ]);
        $stockEditor->plan = 4;
        $stockEditor->storage_limit = 100;
        $stockEditor->save();
        $stockEditor->givePermissionTo('edit role');

        $this->actingAs($stockEditor);
        $this->put(route('roles.update', $this->targetRole), [
            'name' => 'Administrador',
            'permissions' => $this->permissionIds([
                'manage client', 'manage warehouse', 'manage invoice',
            ]),
        ])->assertRedirect(route('roles.index'));

        $role = $this->targetRole->fresh();

        // POS tab renders only the warehouse row; the specials and the
        // other POS modules are not rendered, so they must be preserved.
        $this->assertTrue($role->hasPermissionTo('give pos credit'));
        $this->assertTrue($role->hasPermissionTo('manage pos'));
        $this->assertTrue($role->hasPermissionTo('manage warehouse'));
        $this->assertTrue($role->hasPermissionTo('manage invoice'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function permissionIds(array $names): array
    {
        return Permission::query()->whereIn('name', $names)->pluck('id')
            ->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The XSS middleware redirects a super admin when it counts pending
     * migrations — mark every migration file as executed so the request
     * reaches the controller (same pattern as ClientIssuesDateValidationTest).
     */
    protected function markAllMigrationsExecuted(): void
    {
        $files = array_merge(
            glob(database_path('migrations/*.php')) ?: [],
            glob(base_path('Modules/LandingPage/Database/Migrations/*.php')) ?: []
        );
        foreach ($files as $file) {
            DB::table('migrations')->insert([
                'migration' => basename($file, '.php'), 'batch' => 1,
            ]);
        }
    }

    protected function seedBase(): void
    {
        DB::table('settings')->insert([
            ['name' => 'site_currency', 'value' => 'MZN', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'currency_symbol', 'value' => 'MZN', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        // Plan 3 = Top ++ (CRM + Project off); plan 2 = Best ++ (all on).
        DB::table('plans')->insert([
            ['id' => 2, 'name' => 'Best ++', 'account' => 1, 'crm' => 1, 'project' => 1,
                'hrm' => 1, 'pos' => 1, 'stock' => 1, 'storage_limit' => 100,
                'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'name' => 'Top ++', 'account' => 1, 'crm' => 0, 'project' => 0,
                'hrm' => 1, 'pos' => 1, 'stock' => 1, 'storage_limit' => 100,
                'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('languages')->insert([
            ['id' => 1, 'code' => 'en', 'full_name' => 'English', 'created_at' => now(), 'updated_at' => now()],
        ]);

        foreach ([
            'manage client', 'manage role', 'edit role', 'manage language',
            'manage lead', 'create deal',
            'manage project', 'create milestone',
            'manage employee',
            'manage invoice', 'reject invoice authorization',
            'manage warehouse', 'manage pos', 'give pos credit',
            'view cost centres',
        ] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }
        SidebarMenuPermissionCatalog::ensurePermissionsExist();
        HotelPermissionCatalog::ensurePermissionsExist();

        $this->topEditor = User::create([
            'name' => 'Top Editor', 'email' => 'top@test.local',
            'password' => bcrypt('password'), 'type' => 'company',
            'created_by' => 1, 'default_company_id' => 1,
            'is_enable_login' => 1, 'lang' => 'en', 'email_verified_at' => now(),
        ]);
        $this->topEditor->plan = 3;
        $this->topEditor->storage_limit = 100;
        $this->topEditor->save();
        $this->topEditor->givePermissionTo('edit role');

        $this->superEditor = User::create([
            'name' => 'Super Admin', 'email' => 'super@test.local',
            'password' => bcrypt('password'), 'type' => 'super admin',
            'created_by' => 1, 'default_company_id' => 1,
            'is_enable_login' => 1, 'lang' => 'en', 'email_verified_at' => now(),
        ]);
        $this->superEditor->storage_limit = 100;
        $this->superEditor->save();
        $this->superEditor->givePermissionTo('edit role');

        $this->targetRole = Role::create(['name' => 'Administrador', 'guard_name' => 'web']);
        $this->targetRole->givePermissionTo([
            'manage client', 'manage role', 'manage language',
            'manage lead', 'create deal',
            'manage project', 'create milestone',
            'manage employee',
            'manage invoice', 'reject invoice authorization',
            'manage warehouse', 'manage pos', 'give pos credit',
            'view cost centres',
            'menu.dashboard.accounting', 'menu.dashboard.accounting.cost_centres',
        ]);
        $this->targetRole->givePermissionTo(
            Permission::query()->where('name', 'manage hotel room')->firstOrFail()
        );
    }

    protected function createTables(): void
    {
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
            $table->integer('crm')->default(0);
            $table->integer('project')->default(0);
            $table->integer('hrm')->default(0);
            $table->integer('pos')->default(0);
            $table->integer('stock')->default(0);
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
        // The XSS middleware counts pending migrations for super
        // admins; without this table every super-admin request 500s.
        Schema::create('migrations', function (Blueprint $table) {
            $table->string('migration');
            $table->integer('batch');
        });
    }
}
