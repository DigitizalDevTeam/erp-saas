<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Client issues — Issue 3: White Label / Brand Settings.
 *
 * The Brand Settings card (logos, favicon, theme customizer) must be
 * visible and editable ONLY to the Main Super Admin. A normal company
 * user must not see the card on either settings page, and a crafted
 * POST of brand fields must change nothing — the server strips brand
 * fields for non-super-admins in both saveBusinessSettings (company
 * logos) and store (global logos).
 */
class ClientIssuesBrandSettingsTest extends TestCase
{
    protected User $user;

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

        foreach (['admin_payment_settings', 'backups', 'company_payment_settings', 'webhook_settings', 'noc_certificates', 'experience_certificates', 'joining_letters', 'generate_offer_letters', 'ip_restricts', 'email_templates', 'ch_messages', 'settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'migrations', 'chart_of_accounts', 'chart_of_account_types', 'warehouses', 'branches', 'branch_user'] as $table) {
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
    // Company settings page (GET /settings)
    // ------------------------------------------------------------------

    public function test_company_user_settings_page_hides_brand_card(): void
    {
        $response = $this->get(route('settings'));

        $response->assertOk();
        $response->assertDontSee('brand-settings');
        // The rest of the page still works for the company user.
        $response->assertSee('System Settings');
    }

    public function test_super_admin_settings_page_shows_brand_card(): void
    {
        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin)->get(route('settings'));

        $response->assertOk();
        $response->assertSee('brand-settings');
    }

    // ------------------------------------------------------------------
    // Company brand save (POST business.setting)
    // ------------------------------------------------------------------

    public function test_company_user_post_brand_fields_changes_nothing(): void
    {
        $response = $this->post(route('business.setting'), [
            'color' => 'theme-5',
            'color_flag' => 'false',
            'custom_color' => '#ff0000',
            'cust_theme_bg' => 'on',
            'cust_darklayout' => 'on',
            'SITE_RTL' => 'on',
            'company_logo_dark' => UploadedFile::fake()->image('dark.png'),
        ]);

        $response->assertRedirect();
        // Pre-seeded brand values are untouched…
        $this->assertSame('theme-3', $this->settingValue('color', 1));
        $this->assertSame('old-logo.png', $this->settingValue('company_logo_dark', 1));
        $this->assertNull($this->settingValue('cust_theme_bg', 1));
        // …and no logo file was written to disk.
        $this->assertFileDoesNotExist(storage_path('uploads/logo/1-logo-dark.png'));
    }

    public function test_super_admin_post_brand_fields_saves(): void
    {
        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin)->post(route('business.setting'), [
            'color' => 'theme-5',
            'color_flag' => 'false',
            'cust_theme_bg' => 'on',
        ]);

        $response->assertRedirect();
        $this->assertSame('theme-5', $this->settingValue('color', $admin->id));
        $this->assertSame('on', $this->settingValue('cust_theme_bg', $admin->id));
    }

    public function test_super_admin_logo_upload_saves(): void
    {
        $admin = $this->makeSuperAdmin();
        $target = storage_path('uploads/logo/' . $admin->id . '-logo-dark.png');
        // The target name may collide with a shipped default logo —
        // back it up so the test never destroys a repo file.
        $backup = file_exists($target) ? file_get_contents($target) : null;
        $fake = UploadedFile::fake()->image('dark.png');
        $fakeMd5 = md5_file($fake->getPathname());

        try {
            $response = $this->actingAs($admin)->post(route('business.setting'), [
                'company_logo_dark' => $fake,
            ]);

            $response->assertRedirect();
            $this->assertSame($admin->id . '-logo-dark.png', $this->settingValue('company_logo_dark', $admin->id));
            // The uploaded bytes — not just a pre-existing file.
            $this->assertSame($fakeMd5, md5_file($target));
        } finally {
            if ($backup !== null) {
                file_put_contents($target, $backup);
            } elseif (file_exists($target)) {
                unlink($target);
            }
        }
    }

    // ------------------------------------------------------------------
    // Global brand save (POST systems) — defense in depth
    // ------------------------------------------------------------------

    public function test_non_super_admin_with_system_permission_cannot_save_global_brand(): void
    {
        // A company user who was granted 'manage system settings'
        // (e.g. via a custom role) still may not change the brand:
        // store() strips brand fields for anyone but a super admin.
        $this->user->givePermissionTo(['manage system settings']);

        // logo-dark.png is a shipped default file: the guard must
        // leave its bytes untouched (a successful upload would
        // overwrite it with the fake PNG).
        $target = storage_path('uploads/logo/logo-dark.png');
        $beforeMd5 = md5_file($target);

        $response = $this->post(route('systems.store'), [
            'color' => 'theme-5',
            'color_flag' => 'false',
            'cust_theme_bg' => 'on',
            'logo_dark' => UploadedFile::fake()->image('dark.png'),
        ]);

        $response->assertRedirect();
        $this->assertSame('theme-3', $this->settingValue('color', 1));
        $this->assertNull($this->settingValue('cust_theme_bg', 1));
        $this->assertSame($beforeMd5, md5_file($target));
    }

    public function test_super_admin_systems_page_shows_brand_card(): void
    {
        $admin = $this->makeSuperAdmin();

        $response = $this->actingAs($admin)->get(route('systems.index'));

        $response->assertOk();
        $response->assertSee('brand-settings');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function settingValue(string $name, int $createdBy): ?string
    {
        self::resetSettingsCache();
        $row = DB::table('settings')->where('name', $name)->where('created_by', $createdBy)->first();

        return $row ? (string) $row->value : null;
    }

    /** Super admins hit the XSS middleware's pending-migrations redirect — mark every migration file as executed. */
    protected function makeSuperAdmin(): User
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

        $admin = User::create([
            'name' => 'Super Admin', 'email' => 'admin@test.local',
            'password' => bcrypt('password'), 'type' => 'super admin',
            'created_by' => 1, 'is_enable_login' => 1, 'lang' => 'en',
            'email_verified_at' => now(),
        ]);
        $admin->givePermissionTo(['manage company settings', 'manage business settings', 'manage system settings']);

        return $admin;
    }

    protected function seedBase(): void
    {
        $this->user = User::create([
            'name' => 'Company Owner',
            'email' => 'owner@test.local',
            'password' => bcrypt('password'),
            'type' => 'company',
            'created_by' => 1,
            'default_company_id' => 1,
            'is_enable_login' => 1,
            'lang' => 'en',
            'email_verified_at' => now(),
        ]);
        $this->user->plan = 1;
        $this->user->storage_limit = 100;
        $this->user->save();
        $this->actingAs($this->user);

        DB::table('settings')->insert([
            ['name' => 'color', 'value' => 'theme-3', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'company_logo_dark', 'value' => 'old-logo.png', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'currency_symbol', 'value' => 'MZN', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'site_currency', 'value' => 'MZN', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('plans')->insert([
            ['id' => 1, 'name' => 'Test Plan', 'storage_limit' => 100, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('languages')->insert([
            ['id' => 1, 'code' => 'en', 'full_name' => 'English', 'created_at' => now(), 'updated_at' => now()],
        ]);

        foreach (['manage company settings', 'manage business settings', 'manage system settings'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }
        $this->user->givePermissionTo(['manage company settings', 'manage business settings']);
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
        Schema::create('ip_restricts', function (Blueprint $table) {
            $table->id();
            $table->string('ip');
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('generate_offer_letters', function (Blueprint $table) {
            $table->id();
            $table->string('lang', 100);
            $table->text('content')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('joining_letters', function (Blueprint $table) {
            $table->id();
            $table->string('lang', 100);
            $table->text('content')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('experience_certificates', function (Blueprint $table) {
            $table->id();
            $table->string('lang', 255);
            $table->longtext('content');
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('noc_certificates', function (Blueprint $table) {
            $table->id();
            $table->string('lang', 255);
            $table->longtext('content');
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('webhook_settings', function (Blueprint $table) {
            $table->id();
            $table->string('module')->nullable();
            $table->string('url')->nullable();
            $table->string('method')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('company_payment_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('value');
            $table->integer('created_by');
            $table->timestamps();
            $table->unique(['name', 'created_by']);
        });
        Schema::create('admin_payment_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('value');
            $table->integer('created_by');
            $table->timestamps();
            $table->unique(['name', 'created_by']);
        });
        Schema::create('chart_of_accounts', function (Blueprint $table) {
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
        Schema::create('chart_of_account_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('name_pt_br')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('branches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('branch_user', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('branch_id');
            $table->integer('user_id');
            $table->timestamps();
        });
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('city_zip')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('backups', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('file_name')->nullable();
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
