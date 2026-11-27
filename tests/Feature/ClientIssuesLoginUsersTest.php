<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Utility;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Client issues - Issue 9: user-selection list on the ERP login tab.
 *
 * REVERSED 2026-11-27 (Riz): the public ERP user list is GONE. The
 * login page must not list users to the public: GET /login/users no
 * longer exists, and the ERP tab renders no user selector at all -
 * the email field is typed by hand. A user list appears ONLY on the
 * POS tab, and only for the company saved in the browser's
 * localStorage (pos_remembered_company), listing only that
 * company's POS users (see PosLoginRememberedCompanyTest and
 * PosUsersRouteTest). The auth flow / throttling is untouched
 * (wrong password still fails, right password still logs in).
 *
 * (History: the list was added 2026-11-17 at the client's request -
 * staff only, never super admin/client/company accounts, capped at
 * 200. The client then reported the public list as wrong: it must
 * not be public, only the remembered company's users.)
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
    // No public user list: the endpoint is gone and the page has no
    // selector. The only user list left is the POS remembered-company
    // one (localStorage company -> only that company's POS users).
    // ------------------------------------------------------------------

    public function test_login_users_url_returns_no_user_list(): void
    {
        // /login/users has no route any more. The URL now falls
        // through to /login/{lang} and renders the login page - it
        // must never return a JSON user list or leak user emails.
        $response = $this->get('/login/users');
        $response->assertOk();
        $response->assertDontSee('ana@acme.co.mz');
        $response->assertDontSee('conta@acme.co.mz');
        $response->assertDontSee('caixa@acme.co.mz');
        $response->assertDontSee('erp-user-select');
        $this->assertStringNotContainsString('application/json', $response->headers->get('Content-Type', ''));
    }

    public function test_login_page_has_no_public_user_selection_list(): void
    {
        $response = $this->get(route('login'));
        $response->assertOk();

        // ERP tab: no selector at all - the email field is typed.
        $response->assertDontSee('erp-user-select');
        $response->assertDontSee('erp-user-search');
        $response->assertDontSee('erp-user-list');
        // NOTE: 'Select User' still appears on the page, but only as
        // the POS remembered-company selector's label/placeholder —
        // that is the kept, company-scoped list, not the public one.
        $response->assertDontSee('Search users');
        $response->assertDontSee('erp-user-item');
        $response->assertSee('erp-login-email');
        $response->assertSee('erp-login-password');
        $response->assertSee('Email / User');

        // The POS remembered-company selector is untouched.
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
        // Company owner whose type is a role name, not "company":
        // default_company_id = own id, so it IS its own company
        // (live Digitizal owner: type Cashier). Must stay out of
        // the list even though the type filter alone would let a
        // Cashier through. NOTE: explicit 'id' is ignored on
        // create (mass-assignment), so point the default at the
        // real id after the insert.
        $selfCompany = $makeUser([
            'name' => 'Digitizal', 'email' => 'digitizal@test.local',
            'type' => 'Cashier', 'created_by' => 1, 'default_company_id' => 1,
        ]);
        $selfCompany->default_company_id = $selfCompany->id;
        $selfCompany->save();
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
