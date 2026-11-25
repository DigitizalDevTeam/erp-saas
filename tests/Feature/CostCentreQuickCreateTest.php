<?php

namespace Tests\Feature;

use App\Models\CostCentre;
use App\Models\CostCentreBudget;
use App\Models\User;
use App\Services\CostCentreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cost Centres — inline "Create Cost Centre" from the Budgets
 * create form (quick-store endpoint).
 *
 * Covers: happy path (creates a full centre via the same
 * validation and service as the full form, returns JSON the form
 * can select at once), empty-code and empty-name validation,
 * duplicate code and duplicate name (case-insensitive) per
 * company, per-company scoping of both duplicate checks,
 * permission and guest denial, and a budget saved immediately
 * with the newly created centre.
 *
 * Users: 1 = Company (create cost centres, manage cost centre
 * budgets), 2 = Viewer (view cost centres only), 4 = Other
 * Company (create cost centres).
 */
class CostCentreQuickCreateTest extends TestCase
{
    protected CostCentreService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['view cost centres', 'create cost centres', 'manage cost centre budgets'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Viewer', 'email' => 'viewer@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['view cost centres', 'create cost centres', 'manage cost centre budgets']);
        User::findOrFail(2)->givePermissionTo(['view cost centres']);
        User::findOrFail(4)->givePermissionTo(['create cost centres']);

        $this->service = new CostCentreService();
    }

    protected function tearDown(): void
    {
        foreach (['cost_centre_budgets', 'dimension_audit_events', 'chart_of_accounts', 'projects', 'cost_centres', 'branches', 'branch_user', 'settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_quick_store_route_exists(): void
    {
        $this->assertTrue(Route::has('cost-centres.quick-store'));
    }

    public function test_quick_store_creates_a_full_cost_centre_and_returns_json(): void
    {
        $response = $this->actingAs(User::findOrFail(1))->postJson(
            route('cost-centres.quick-store'),
            ['code' => 'ADM', 'name' => 'Administration']
        );

        $response->assertStatus(201);
        $response->assertJsonStructure(['id', 'code', 'name', 'message']);
        $this->assertSame('ADM', $response->json('code'));
        $this->assertSame('Administration', $response->json('name'));

        $centre = CostCentre::where('code', 'ADM')->firstOrFail();
        $this->assertSame(1, (int) $centre->created_by);
        $this->assertSame(1, (int) $centre->company_id);
        $this->assertSame(CostCentre::STATUS_ACTIVE, $centre->status);

        // The new centre is a normal centre: it appears in the
        // company scope every list and dropdown reads from.
        $this->assertTrue(CostCentre::forCompany(1)->where('code', 'ADM')->exists());
    }

    public function test_quick_store_rejects_empty_code(): void
    {
        $response = $this->actingAs(User::findOrFail(1))->postJson(
            route('cost-centres.quick-store'),
            ['code' => '', 'name' => 'Administration']
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('code');
        $this->assertSame(0, CostCentre::count());
    }

    public function test_quick_store_rejects_empty_name(): void
    {
        $response = $this->actingAs(User::findOrFail(1))->postJson(
            route('cost-centres.quick-store'),
            ['code' => 'ADM', 'name' => '']
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
        $this->assertSame(0, CostCentre::count());
    }

    public function test_quick_store_rejects_duplicate_code_in_same_company(): void
    {
        $this->service->create(['code' => 'ADM', 'name' => 'Administration', 'status' => 'active'], 1, 1);

        $response = $this->actingAs(User::findOrFail(1))->postJson(
            route('cost-centres.quick-store'),
            ['code' => 'ADM', 'name' => 'Admin Two']
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('code');
        $this->assertSame(1, CostCentre::count());
    }

    public function test_quick_store_allows_same_code_in_another_company(): void
    {
        $this->service->create(['code' => 'ADM', 'name' => 'Administration', 'status' => 'active'], 1, 1);

        $response = $this->actingAs(User::findOrFail(4))->postJson(
            route('cost-centres.quick-store'),
            ['code' => 'ADM', 'name' => 'Administration']
        );

        $response->assertStatus(201);
        $this->assertSame(2, CostCentre::count());
    }

    public function test_quick_store_rejects_duplicate_name_case_insensitive(): void
    {
        $this->service->create(['code' => 'ADM', 'name' => 'Administration', 'status' => 'active'], 1, 1);

        $response = $this->actingAs(User::findOrFail(1))->postJson(
            route('cost-centres.quick-store'),
            ['code' => 'ADM2', 'name' => 'administration']
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
        $this->assertSame(1, CostCentre::count());
    }

    public function test_quick_store_allows_same_name_in_another_company(): void
    {
        $this->service->create(['code' => 'ADM', 'name' => 'Administration', 'status' => 'active'], 1, 1);

        $response = $this->actingAs(User::findOrFail(4))->postJson(
            route('cost-centres.quick-store'),
            ['code' => 'ADM2', 'name' => 'Administration']
        );

        $response->assertStatus(201);
        $this->assertSame(2, CostCentre::count());
    }

    public function test_quick_store_denies_without_create_permission(): void
    {
        $response = $this->actingAs(User::findOrFail(2))->postJson(
            route('cost-centres.quick-store'),
            ['code' => 'ADM', 'name' => 'Administration']
        );

        $response->assertStatus(403);
        $this->assertSame(0, CostCentre::count());
    }

    public function test_quick_store_denies_guest(): void
    {
        $response = $this->postJson(
            route('cost-centres.quick-store'),
            ['code' => 'ADM', 'name' => 'Administration']
        );

        $response->assertStatus(401);
        $this->assertSame(0, CostCentre::count());
    }

    public function test_budget_can_be_saved_immediately_with_the_new_centre(): void
    {
        $created = $this->actingAs(User::findOrFail(1))->postJson(
            route('cost-centres.quick-store'),
            ['code' => 'ADM', 'name' => 'Administration']
        );
        $created->assertStatus(201);
        $centreId = (int) $created->json('id');

        $response = $this->actingAs(User::findOrFail(1))->post(route('cost-centre-budgets.store'), [
            'cost_centre_id' => $centreId,
            'fiscal_year' => 2026,
            'period' => 3,
            'budget_amount' => '10000.00',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $budget = CostCentreBudget::firstOrFail();
        $this->assertSame($centreId, (int) $budget->cost_centre_id);
        $this->assertSame(CostCentreBudget::STATUS_DRAFT, $budget->status);
    }

    private function createTables(): void
    {
        Schema::create('cost_centres', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('parent_id')->nullable();
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->bigInteger('manager_user_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['created_by', 'code'], 'cc_company_code_uq');
        });
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
            $table->primary(['permission_id', 'role_id']);
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
            $table->bigIncrements('id');
            $table->string('code', 50)->nullable();
            $table->string('name')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('projects', function ($table) {
            $table->bigIncrements('id');
            $table->string('project_name')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('cost_centre_budgets', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->integer('fiscal_year');
            $table->unsignedTinyInteger('period');
            $table->unsignedBigInteger('cost_centre_id');
            $table->unsignedBigInteger('gl_account_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('scope_key', 60);
            $table->decimal('budget_amount', 18, 2)->default(0);
            $table->decimal('approved_amount', 18, 2)->default(0);
            $table->string('currency_code', 10)->nullable();
            $table->string('status', 20)->default('draft');
            $table->integer('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'fiscal_year', 'period', 'cost_centre_id', 'scope_key'], 'ccb_scope_unique');
        });
        Schema::create('dimension_audit_events', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('action');
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->integer('actor_id')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_create_page_with_no_cost_centres_renders_create_option_and_auto_open_script(): void
    {
        // Regression: a company with no cost centres yet has only
        // the "__create__" option, so it is already selected on
        // page load and picking it fires no change event — the
        // page must open the inline panel by itself, or the
        // dropdown looks clickable but does nothing.
        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-budgets.create'));

        $response->assertStatus(200);
        $html = $response->getContent();
        $this->assertStringContainsString('value="__create__"', $html);
        $this->assertStringContainsString('new-cost-centre-panel', $html);
        // The load-time auto-open: the script opens the panel when
        // "__create__" is already the selected value.
        $this->assertStringContainsString("centreSelect.value === '__create__'", $html);
        // The message line must sit OUTSIDE the hidden panel, or
        // the success message is invisible once the panel closes.
        $messagePos = strpos($html, 'id="new-cost-centre-message"');
        $panelPos = strpos($html, 'id="new-cost-centre-panel"');
        $this->assertNotFalse($messagePos);
        $this->assertNotFalse($panelPos);
        $this->assertLessThan($panelPos, $messagePos);
    }

    public function test_confirm_dialog_strings_render_in_portuguese(): void
    {
        // Regression: the global delete-confirm dialog (custom.js)
        // hardcoded its title, text and Yes/No buttons in English,
        // so a Portuguese account saw an English "Are you sure?"
        // dialog. The admin footer now emits the strings translated
        // for the current locale, before custom.js loads. The
        // locale comes from the user's own lang column (XSS
        // middleware), so the test user is Portuguese here.
        DB::table('users')->where('id', 1)->update(['lang' => 'pt']);

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-budgets.create'));

        $response->assertStatus(200);
        $html = $response->getContent();
        // The footer emits the strings as a JS object literal
        // (keys unquoted, values @json-encoded, so non-ASCII
        // appears as \uXXXX escapes — exactly what the browser
        // decodes when it runs the script).
        $this->assertStringContainsString('window.swalConfirmText', $html);
        $this->assertStringContainsString('title: "Voc\u00ea tem certeza?"', $html);
        $this->assertStringContainsString('text: "Esta a\u00e7\u00e3o n\u00e3o pode ser desfeita. Deseja continuar?"', $html);
        $this->assertStringContainsString('confirmButtonText: "Sim"', $html);
        $this->assertStringContainsString('cancelButtonText: "N\u00e3o"', $html);
        // The versioned custom.js URL busts the browser cache so
        // the new handler actually loads.
        $this->assertStringContainsString('custom.js?v=20261126', $html);
    }
}
