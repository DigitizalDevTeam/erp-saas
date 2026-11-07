<?php

namespace Tests\Feature;

use App\Models\CostCentre;
use App\Models\User;
use App\Services\CostCentreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class CostCentreWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['view cost centres', 'create cost centres', 'edit cost centres', 'deactivate cost centres'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Viewer', 'email' => 'viewer@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 5, 'name' => 'Other Employee', 'email' => 'otheremployee@test.local', 'type' => 'employee', 'created_by' => 4, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Maputo', 'created_by' => 1],
        ]);

        User::findOrFail(1)->givePermissionTo(['view cost centres', 'create cost centres', 'edit cost centres', 'deactivate cost centres']);
        User::findOrFail(2)->givePermissionTo(['view cost centres']);
        User::findOrFail(4)->givePermissionTo(['view cost centres', 'create cost centres', 'edit cost centres', 'deactivate cost centres']);
        User::findOrFail(5)->givePermissionTo(['view cost centres', 'create cost centres', 'edit cost centres', 'deactivate cost centres']);
    }

    protected function tearDown(): void
    {
        foreach (['cost_centres', 'branches', 'branch_user', 'settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function service(): CostCentreService
    {
        return new CostCentreService();
    }

    private function createPayload(string $code, array $overrides = []): array
    {
        return array_merge([
            'code' => $code,
            'name' => 'Centre '.$code,
            'description' => '',
            'parent_id' => '',
            'branch_id' => '',
            'manager_user_id' => '',
            'status' => 'active',
            'effective_from' => '',
            'effective_to' => '',
        ], $overrides);
    }

    public function test_list_tree_happy_path(): void
    {
        $parent = $this->service()->create(['code' => 'OPS', 'name' => 'Operations'], 1, 1);
        $child = $this->service()->create(['code' => 'LOG', 'name' => 'Logistics', 'parent_id' => $parent->id], 1, 1);

        $response = $this->actingAs(User::findOrFail(1))->get(route('cost-centres.index'));

        $response->assertOk();
        $response->assertSee('OPS');
        $response->assertSee('Operations');
        $response->assertSee('LOG');
        $response->assertSee('Logistics');
        // Parent renders before its child in the tree.
        $this->assertLessThan(
            strpos($response->getContent(), 'LOG'),
            strpos($response->getContent(), 'OPS')
        );
    }

    public function test_create_edit_deactivate_via_http(): void
    {
        $this->actingAs(User::findOrFail(1))
            ->post(route('cost-centres.store'), $this->createPayload('ADM', ['branch_id' => 1]))
            ->assertRedirect(route('cost-centres.index'));

        $centre = CostCentre::where('code', 'ADM')->firstOrFail();
        $this->assertSame(1, (int) $centre->created_by);
        $this->assertSame(1, (int) $centre->branch_id);

        // Edit.
        $this->actingAs(User::findOrFail(1))
            ->put(route('cost-centres.update', $centre->id), $this->createPayload('ADM', ['name' => 'Administration']))
            ->assertRedirect(route('cost-centres.index'));
        $this->assertSame('Administration', $centre->fresh()->name);

        // Deactivate (not delete): still in the DB, inactive.
        $this->actingAs(User::findOrFail(1))
            ->post(route('cost-centres.deactivate', $centre->id))
            ->assertRedirect(route('cost-centres.index'));
        $this->assertSame('inactive', $centre->fresh()->status);
        $this->assertNotSoftDeleted('cost_centres', ['id' => $centre->id]);
    }

    public function test_duplicate_code_rejected_via_http(): void
    {
        $this->service()->create(['code' => 'DUP', 'name' => 'First'], 1, 1);

        $this->actingAs(User::findOrFail(1))
            ->post(route('cost-centres.store'), $this->createPayload('DUP'))
            ->assertSessionHasErrors('code');

        $this->assertSame(1, CostCentre::where('code', 'DUP')->count());
    }

    public function test_settings_save(): void
    {
        $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centres.settings'))
            ->assertOk()
            ->assertSee('Budget policy');

        $this->actingAs(User::findOrFail(1))
            ->post(route('cost-centres.settings.save'), [
                'require_cost_centre_for_expenses' => '1',
                'allow_splits' => '1',
                'cost_centre_parents_summary_only' => '1',
                'budget_policy' => 'block',
                'dimension_on_cash_transactions' => 'required',
                'period_lock_policy' => 'warn',
                'rounding_account' => '699',
                'default_allocation_basis' => 'fixed_amount',
            ])
            ->assertRedirect(route('cost-centres.settings'));

        $this->assertSame('1', DB::table('settings')->where('created_by', 1)->where('name', 'require_cost_centre_for_expenses')->value('value'));
        $this->assertSame('block', DB::table('settings')->where('created_by', 1)->where('name', 'budget_policy')->value('value'));
        $this->assertSame('699', DB::table('settings')->where('created_by', 1)->where('name', 'rounding_account')->value('value'));
        $this->assertSame('fixed_amount', DB::table('settings')->where('created_by', 1)->where('name', 'default_allocation_basis')->value('value'));
        // D2 recorded as a company setting.
        $this->assertSame('1', DB::table('settings')->where('created_by', 1)->where('name', 'cost_centre_parents_summary_only')->value('value'));
    }

    public function test_user_without_permissions_is_turned_away(): void
    {
        $this->actingAs(User::findOrFail(6))
            ->get(route('cost-centres.index'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs(User::findOrFail(6))
            ->post(route('cost-centres.store'), $this->createPayload('NOP'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, CostCentre::count());
    }

    public function test_view_only_user_cannot_create_or_edit(): void
    {
        $centre = $this->service()->create(['code' => 'VIEW', 'name' => 'View Only'], 1, 1);

        $this->actingAs(User::findOrFail(2))
            ->get(route('cost-centres.index'))
            ->assertOk()
            ->assertSee('VIEW');

        $this->actingAs(User::findOrFail(2))
            ->post(route('cost-centres.store'), $this->createPayload('NOPE'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs(User::findOrFail(2))
            ->get(route('cost-centres.edit', $centre->id))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_other_company_cannot_see_or_touch_company_centres(): void
    {
        $centre = $this->service()->create(['code' => 'CC-001', 'name' => 'Secret'], 1, 1);

        // Direct URL to the centre: 403.
        $this->actingAs(User::findOrFail(5))
            ->get(route('cost-centres.edit', $centre->id))
            ->assertForbidden();

        // Direct action URL: 403, status unchanged.
        $this->actingAs(User::findOrFail(5))
            ->post(route('cost-centres.deactivate', $centre->id))
            ->assertForbidden();
        $this->assertSame('active', $centre->fresh()->status);

        // The other company's list is empty: centres are scoped per company.
        $this->actingAs(User::findOrFail(5))
            ->get(route('cost-centres.index'))
            ->assertOk()
            ->assertDontSee('CC-001');
    }

    public function test_delete_blocked_with_children_and_allowed_when_leaf(): void
    {
        $parent = $this->service()->create(['code' => 'PAR', 'name' => 'Parent'], 1, 1);
        $child = $this->service()->create(['code' => 'LEAF', 'name' => 'Leaf', 'parent_id' => $parent->id], 1, 1);

        $this->actingAs(User::findOrFail(1))
            ->delete(route('cost-centres.destroy', $parent->id))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertNotSoftDeleted('cost_centres', ['id' => $parent->id]);

        $this->actingAs(User::findOrFail(1))
            ->delete(route('cost-centres.destroy', $child->id))
            ->assertRedirect(route('cost-centres.index'));
        $this->assertSoftDeleted('cost_centres', ['id' => $child->id]);
    }
}
