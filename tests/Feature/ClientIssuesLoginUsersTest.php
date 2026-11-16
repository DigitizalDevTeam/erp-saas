<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Utility;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Client issues — Issue 9: user-selection list on the ERP login tab.
 *
 * - GET /login/users (public, guest) returns active, login-enabled
 *   company/staff users only — NEVER super admin, NEVER client —
 *   as {id, name, company, email}, capped at 200, ordered by name.
 *   Company name: a company user is its own company; staff resolve
 *   via their active company_user membership (is_default first),
 *   else default_company_id, else created_by — in bulk, no N+1.
 * - The login page renders the searchable list on the ERP tab; a
 *   click fills the email field and focuses the password field.
 *   No auto-login: the existing auth flow / throttling is untouched
 *   (wrong password still fails, right password still logs in).
 * - NOTE: a public user list is user-enumeration by design — the
 *   client explicitly asked for it; passwords are still verified
 *   per user.
 */
class ClientIssuesLoginUsersTest extends TestCase
{
    protected User $companyA;
    protected User $companyB;
    protected User $staff;
    protected User $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        Utility::$rates = null;
        Utility::$data = null;
        self::resetSettingsCache();
        $this->createTables();
        $this->seedBase();
    }

    protected function tearDown(): void
    {
        Utility::$rates = null;
        Utility::$data = null;
        self::resetSettingsCache();

        foreach (['company_user', 'email_template_langs', 'user_email_templates', 'email_templates', 'landing_page_settings', 'plans', 'languages', 'users', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    protected static function resetSettingsCache(): void
    {
        // Utility caches settings + the language list in private
        // statics. $getsettingsid alone is not enough: in a full-suite
        // run an earlier test leaves $getsettings populated with ITS
        // settings rows (created_by=1, no default_language row), and
        // settings() then falls back to its built-in default
        // default_language='pt' — so the login page rendered in
        // Portuguese and the English assertions failed. Reset all
        // three caches so this test reads its own fixture.
        foreach (['getsettingsid' => [], 'getsettings' => null, 'languageSetting' => null] as $prop => $value) {
            $ref = new \ReflectionProperty(Utility::class, $prop);
            $ref->setAccessible(true);
            $ref->setValue(null, $value);
        }
    }

    // ------------------------------------------------------------------
    // Endpoint: scope, shape, company names
    // ------------------------------------------------------------------

    public function test_login_users_endpoint_returns_company_and_staff_without_super_admin_or_client(): void
    {
        // Unauthenticated: the endpoint is public by design.
        $response = $this->get(route('login.users'));
        $response->assertOk();
        $list = $response->json();
        $this->assertCount(5, $list);

        $byEmail = collect($list)->keyBy('email');

        // Company users: company is their own name.
        $this->assertSame('Acme Lda', $byEmail['acme@test.local']['company']);
        $this->assertSame('Acme Lda', $byEmail['acme@test.local']['name']);
        $this->assertSame('Beta SA', $byEmail['beta@test.local']['company']);

        // Staff: active membership wins over default_company_id
        // (membership -> Acme, default -> Beta).
        $this->assertSame('Acme Lda', $byEmail['ana@acme.co.mz']['company']);
        $this->assertSame('Ana Sousa', $byEmail['ana@acme.co.mz']['name']);

        // Accountant without a membership: default_company_id.
        $this->assertSame('Acme Lda', $byEmail['conta@acme.co.mz']['company']);

        // Staff whose `type` is a role name (Cashier, Manager, Waiter,
        // ...) are staff too — the scope is an exclusion of super
        // admin + client, not a three-type whitelist.
        $this->assertSame('Acme Lda', $byEmail['caixa@acme.co.mz']['company']);
        $this->assertSame('Caixa Cashier', $byEmail['caixa@acme.co.mz']['name']);

        // Shape: every entry carries exactly id, name, company, email.
        foreach ($list as $entry) {
            $this->assertSame(['id', 'name', 'company', 'email'], array_keys($entry));
        }

        // NEVER in the list.
        $this->assertArrayNotHasKey('superadmin@test.local', $byEmail);
        $this->assertArrayNotHasKey('client@test.local', $byEmail);
        $this->assertArrayNotHasKey('disabled@test.local', $byEmail);
        $this->assertArrayNotHasKey('nologin@test.local', $byEmail);
    }

    // ------------------------------------------------------------------
    // Login page renders the list + the fill wiring; POS tab unchanged
    // ------------------------------------------------------------------

    public function test_login_page_renders_user_selection_list(): void
    {
        $response = $this->get(route('login'));
        $response->assertOk();

        // ERP tab: search input, list container, email/password ids.
        $response->assertSee('erp-user-select');
        $response->assertSee('erp-user-search');
        $response->assertSee('erp-user-list');
        $response->assertSee('Select User');
        $response->assertSee('Search users');
        $response->assertSee('erp-login-email');
        $response->assertSee('erp-login-password');

        // The list is fetched from the public endpoint and a click
        // fills the email field + focuses the password field.
        // (JS assertions with escaping off: the quotes are literal.)
        $response->assertSee(route('login.users'));
        $response->assertSee('erp-user-item');
        $response->assertSee("erpEmailInput.value = item.getAttribute('data-email')", false);
        $response->assertSee('erpPasswordInput.focus()', false);

        // The email field stays a plain typeable input (no JS needed
        // to type) and the POS tab is untouched.
        $response->assertSee('Email / User');
        $response->assertSee('pos-remembered-user');
    }

    // ------------------------------------------------------------------
    // Auth flow untouched: wrong password fails, right password logs in
    // ------------------------------------------------------------------

    public function test_login_with_wrong_password_does_not_log_in(): void
    {
        $response = $this->post('/login', [
            'email' => 'acme@test.local',
            'password' => 'wrong-password',
            'login_mode' => 'erp',
        ]);

        $response->assertRedirect();
        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_login_with_right_password_logs_in_as_that_user(): void
    {
        $response = $this->post('/login', [
            'email' => 'acme@test.local',
            'password' => 'password',
            'login_mode' => 'erp',
        ]);

        $this->assertAuthenticatedAs($this->companyA);
        $response->assertRedirect('/account-dashboard');
        $response->assertSessionHas('active_company_id', (int) $this->companyA->id);
    }

    // ------------------------------------------------------------------
    // Fixture
    // ------------------------------------------------------------------

    protected function seedBase(): void
    {
        $makeUser = function (array $attrs): User {
            return User::create(array_merge([
                'password' => bcrypt('password'),
                'type' => 'company',
                'created_by' => 1,
                'default_company_id' => null,
                'is_enable_login' => 1,
                'is_active' => 1,
                'is_disable' => 1,
                'delete_status' => 1,
                'lang' => 'en',
                'email_verified_at' => now(),
            ], $attrs));
        };

        $this->companyA = $makeUser([
            'id' => 1, 'name' => 'Acme Lda', 'email' => 'acme@test.local',
            'plan' => 1, 'default_company_id' => 1,
        ]);
        $this->companyB = $makeUser([
            'id' => 2, 'name' => 'Beta SA', 'email' => 'beta@test.local',
            'plan' => 1, 'default_company_id' => 2,
        ]);
        // Staff: membership -> Acme (1), default_company_id -> Beta (2).
        $this->staff = $makeUser([
            'id' => 3, 'name' => 'Ana Sousa', 'email' => 'ana@acme.co.mz',
            'type' => 'Employee', 'created_by' => 1, 'default_company_id' => 2,
        ]);
        // Accountant: no membership, default -> Acme.
        $this->accountant = $makeUser([
            'id' => 4, 'name' => 'Carlos Contas', 'email' => 'conta@acme.co.mz',
            'type' => 'accountant', 'created_by' => 1, 'default_company_id' => 1,
        ]);
        // Cashier: staff whose type is a role name, default -> Acme.
        $makeUser([
            'id' => 9, 'name' => 'Caixa Cashier', 'email' => 'caixa@acme.co.mz',
            'type' => 'Cashier', 'created_by' => 1, 'default_company_id' => 1,
        ]);
        // Never listed.
        $makeUser([
            'id' => 5, 'name' => 'Super Admin', 'email' => 'superadmin@test.local',
            'type' => 'super admin', 'created_by' => 1,
        ]);
        $makeUser([
            'id' => 6, 'name' => 'Client User', 'email' => 'client@test.local',
            'type' => 'client', 'created_by' => 1,
        ]);
        $makeUser([
            'id' => 7, 'name' => 'Disabled Co', 'email' => 'disabled@test.local',
            'type' => 'company', 'created_by' => 1, 'is_active' => 0,
        ]);
        $makeUser([
            'id' => 8, 'name' => 'No Login', 'email' => 'nologin@test.local',
            'type' => 'Employee', 'created_by' => 1, 'is_enable_login' => 0,
        ]);

        DB::table('company_user')->insert([
            ['user_id' => 3, 'company_id' => 1, 'status' => 'active', 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('settings')->insert([
            ['name' => 'recaptcha_module', 'value' => 'off', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'enable_signup', 'value' => 'off', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'default_language', 'value' => 'en', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('plans')->insert([
            ['id' => 1, 'name' => 'Test Plan', 'duration' => 'lifetime', 'storage_limit' => 100, 'max_customers' => -1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('languages')->insert([
            ['id' => 1, 'code' => 'en', 'full_name' => 'English', 'created_at' => now(), 'updated_at' => now()],
        ]);
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
            $table->integer('is_disable')->default(1);
            $table->integer('delete_status')->default(1);
            $table->string('lang')->default('en');
            $table->string('plan')->nullable();
            $table->string('plan_expire_date')->nullable();
            $table->string('trial_expire_date')->nullable();
            $table->integer('storage_limit')->default(0);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('plans', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('duration')->nullable();
            $table->integer('storage_limit')->default(0);
            $table->integer('max_customers')->default(0);
            $table->timestamps();
        });
        Schema::create('languages', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code')->nullable();
            $table->string('full_name')->nullable();
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
        Schema::create('email_template_langs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('parent_id');
            $table->string('lang', 100);
            $table->string('subject');
            $table->text('content');
            $table->timestamps();
        });
        Schema::create('user_email_templates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('template_id');
            $table->integer('user_id');
            $table->integer('is_active')->default(1);
            $table->timestamps();
        });
        Schema::create('landing_page_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('company_user', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id');
            $table->integer('company_id');
            $table->string('status')->default('active');
            $table->integer('is_default')->default(0);
            $table->timestamps();
        });
    }
}
