<?php

namespace Tests\Feature;

use App\Models\KitchenRecipe;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class KitchenRecipeWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('product_service_units', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('dimension_mode')->nullable();
            $table->string('length_unit')->nullable();
            $table->decimal('volumetric_divisor', 10, 2)->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('product_services', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('type', 20)->nullable();
            $table->decimal('sale_price', 15, 4)->default(0);
            $table->string('sale_price_type', 20)->nullable();
            $table->decimal('purchase_price', 15, 4)->default(0);
            $table->integer('tax_id')->nullable();
            $table->integer('unit_id')->nullable();
            $table->integer('show_on_pos')->default(1);
            $table->integer('created_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('kitchen_recipes', function ($table) {
            $table->increments('id');
            $table->string('code', 50);
            $table->string('name');
            $table->integer('product_id');
            $table->integer('category_id')->nullable();
            $table->integer('version')->default(1);
            $table->string('status', 20)->default('draft');
            $table->decimal('expected_yield_quantity', 12, 4)->nullable();
            $table->integer('expected_yield_unit_id')->nullable();
            $table->decimal('standard_portion_quantity', 12, 4)->nullable();
            $table->integer('standard_portion_unit_id')->nullable();
            $table->integer('preparation_time_minutes')->nullable();
            $table->text('equipment')->nullable();
            $table->text('instructions')->nullable();
            $table->text('storage_conditions')->nullable();
            $table->text('allergens')->nullable();
            $table->string('photo')->nullable();
            $table->decimal('loss_percent', 5, 2)->default(0);
            $table->integer('cost_centre_id')->nullable();
            $table->integer('branch_id')->nullable();
            $table->integer('created_by');
            $table->integer('submitted_by')->nullable();
            $table->integer('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->timestamps();

            $table->unique(['created_by', 'code', 'version']);
            $table->unique(['created_by', 'product_id', 'version']);
        });

        Schema::create('kitchen_unit_conversions', function ($table) {
            $table->increments('id');
            $table->integer('from_unit_id');
            $table->integer('to_unit_id');
            $table->decimal('factor', 18, 6);
            $table->integer('product_id')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('product_recipes', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('kitchen_recipe_id')->nullable();
            $table->integer('version')->default(1);
            $table->integer('ingredient_product_id');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('loss_percent', 5, 2)->default(0);
            $table->string('unit')->nullable();
            $table->integer('unit_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('product_recipe_alternatives', function ($table) {
            $table->increments('id');
            $table->integer('product_recipe_id');
            $table->integer('alternative_product_id');
            $table->decimal('quantity', 12, 4)->nullable();
            $table->integer('sort_order')->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('branches', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('branch_user', function ($table) {
            $table->integer('user_id');
            $table->integer('branch_id');
            $table->timestamps();
        });

        Schema::create('ch_messages', function ($table) {
            $table->increments('id');
            $table->integer('to_id')->nullable();
            $table->integer('seen')->default(0);
            $table->timestamps();
        });

        Schema::create('languages', function ($table) {
            $table->increments('id');
            $table->string('code')->nullable();
            $table->string('full_name')->nullable();
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

        Schema::create('plans', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
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

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['manage kitchen production', 'create kitchen recipes', 'approve kitchen recipes'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('product_service_units')->insert([
            ['id' => 1, 'name' => 'kg', 'created_by' => 1],
            ['id' => 2, 'name' => 'g', 'created_by' => 1],
        ]);
        DB::table('kitchen_unit_conversions')->insert([
            ['from_unit_id' => 1, 'to_unit_id' => 2, 'factor' => 1000, 'product_id' => null, 'created_by' => 1],
        ]);
        DB::table('product_services')->insert([
            ['id' => 10, 'name' => 'Coconut Rice', 'type' => 'product', 'sale_price' => 50, 'sale_price_type' => 'exclusive', 'purchase_price' => 0, 'unit_id' => 2, 'created_by' => 1],
            ['id' => 11, 'name' => 'Uncooked Rice', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 100, 'unit_id' => 1, 'created_by' => 1],
        ]);

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Chef', 'email' => 'chef@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Manager', 'email' => 'manager@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 5, 'name' => 'Other Chef', 'email' => 'otherchef@test.local', 'type' => 'employee', 'created_by' => 4, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['manage kitchen production', 'create kitchen recipes']);
        User::findOrFail(2)->givePermissionTo(['manage kitchen production', 'create kitchen recipes']);
        User::findOrFail(3)->givePermissionTo(['manage kitchen production', 'approve kitchen recipes']);
        User::findOrFail(4)->givePermissionTo(['manage kitchen production', 'create kitchen recipes']);
        User::findOrFail(5)->givePermissionTo(['manage kitchen production', 'create kitchen recipes']);
    }

    protected function tearDown(): void
    {
        foreach (['role_has_permissions', 'model_has_roles', 'model_has_permissions', 'permissions', 'roles', 'plans', 'email_templates', 'languages', 'ch_messages', 'branches', 'branch_user', 'settings', 'users', 'product_recipe_alternatives', 'product_recipes', 'kitchen_unit_conversions', 'kitchen_recipes', 'product_services', 'product_service_units'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    private function recipePayload(string $code): array
    {
        return [
            'code' => $code,
            'name' => 'Coconut Rice',
            'product_id' => 10,
            'expected_yield_quantity' => 3,
            'expected_yield_unit_id' => 1,
            'standard_portion_quantity' => 150,
            'standard_portion_unit_id' => 2,
            'loss_percent' => 0,
            'lines' => [
                ['ingredient_product_id' => 11, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0, 'alternatives' => []],
            ],
        ];
    }

    public function test_create_submit_and_approve_happy_path(): void
    {
        // Create as the company user.
        $this->actingAs(User::findOrFail(1))
            ->post(route('kitchen.recipes.store'), $this->recipePayload('KR-2026-0001'))
            ->assertRedirect();

        $recipe = KitchenRecipe::where('code', 'KR-2026-0001')->firstOrFail();
        $this->assertSame(KitchenRecipe::STATUS_DRAFT, $recipe->status);
        $this->assertSame(1, $recipe->lines()->count());

        // Submit as the chef.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.recipes.submit', $recipe->id))
            ->assertRedirect(route('kitchen.recipes.show', $recipe->id));

        $this->assertSame(KitchenRecipe::STATUS_PENDING_APPROVAL, $recipe->fresh()->status);
        $this->assertSame(2, (int) $recipe->fresh()->submitted_by);

        // The submitter cannot approve their own recipe.
        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.recipes.approve', $recipe->id))
            ->assertRedirect();
        $this->assertSame(KitchenRecipe::STATUS_PENDING_APPROVAL, $recipe->fresh()->status);

        // A different user approves.
        $this->actingAs(User::findOrFail(3))
            ->post(route('kitchen.recipes.approve', $recipe->id))
            ->assertRedirect(route('kitchen.recipes.show', $recipe->id));

        $approved = $recipe->fresh();
        $this->assertSame(KitchenRecipe::STATUS_APPROVED, $approved->status);
        $this->assertSame(3, (int) $approved->approved_by);
        $this->assertNotNull($approved->approved_at);

        // The show page renders with the cost preview.
        $this->actingAs(User::findOrFail(3))
            ->get(route('kitchen.recipes.show', $recipe->id))
            ->assertOk()
            ->assertSee('Coconut Rice')
            ->assertSee('Cost Preview')
            ->assertSee('Excellent');

        // Archive closes the lifecycle; an archived recipe cannot be edited.
        $this->actingAs(User::findOrFail(3))
            ->post(route('kitchen.recipes.archive', $recipe->id))
            ->assertRedirect(route('kitchen.recipes.show', $recipe->id));
        $this->assertSame(KitchenRecipe::STATUS_ARCHIVED, $recipe->fresh()->status);

        $this->actingAs(User::findOrFail(1))
            ->get(route('kitchen.recipes.edit', $recipe->id))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_create_and_edit_pages_render(): void
    {
        $this->actingAs(User::findOrFail(1))
            ->get(route('kitchen.recipes.create'))
            ->assertOk()
            ->assertSee('Ingredients');

        $this->actingAs(User::findOrFail(1))
            ->post(route('kitchen.recipes.store'), $this->recipePayload('KR-2026-0007'))
            ->assertRedirect();

        $recipe = KitchenRecipe::where('code', 'KR-2026-0007')->firstOrFail();

        $this->actingAs(User::findOrFail(1))
            ->get(route('kitchen.recipes.edit', $recipe->id))
            ->assertOk()
            ->assertSee('Coconut Rice');
    }

    public function test_ingredient_type_dish_is_hidden_from_pos(): void
    {
        DB::table('product_services')->insert([
            'id' => 14, 'name' => 'Beef Stock Base', 'type' => 'ingredient',
            'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 0,
            'unit_id' => 1, 'show_on_pos' => 1, 'created_by' => 1,
        ]);

        $payload = $this->recipePayload('KR-2026-0008');
        $payload['product_id'] = 14;

        $this->actingAs(User::findOrFail(1))
            ->post(route('kitchen.recipes.store'), $payload)
            ->assertRedirect();

        $this->assertSame(0, (int) DB::table('product_services')->where('id', 14)->value('show_on_pos'));
    }

    public function test_edit_of_approved_recipe_versions_through_http(): void
    {
        $this->actingAs(User::findOrFail(1))
            ->post(route('kitchen.recipes.store'), $this->recipePayload('KR-2026-0009'))
            ->assertRedirect();

        $recipe = KitchenRecipe::where('code', 'KR-2026-0009')->firstOrFail();

        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.recipes.submit', $recipe->id))
            ->assertRedirect();
        $this->actingAs(User::findOrFail(3))
            ->post(route('kitchen.recipes.approve', $recipe->id))
            ->assertRedirect();

        // Opening edit on the approved recipe creates v2 and redirects to it.
        $editResponse = $this->actingAs(User::findOrFail(1))
            ->get(route('kitchen.recipes.edit', $recipe->id))
            ->assertRedirect();

        $copy = KitchenRecipe::where('code', 'KR-2026-0009')->where('version', 2)->firstOrFail();
        $editResponse->assertRedirect(route('kitchen.recipes.edit', $copy->id));

        // Saving v2 with the same code must pass validation (code is
        // unique per company AND version) and leave v1 untouched.
        $payload = $this->recipePayload('KR-2026-0009');
        $payload['name'] = 'Coconut Rice v2';
        $this->actingAs(User::findOrFail(1))
            ->put(route('kitchen.recipes.update', $copy->id), $payload)
            ->assertRedirect(route('kitchen.recipes.show', $copy->id));

        $this->assertSame('Coconut Rice v2', $copy->fresh()->name);
        $this->assertSame(KitchenRecipe::STATUS_DRAFT, $copy->fresh()->status);
        $this->assertSame('Coconut Rice', $recipe->fresh()->name);
        $this->assertSame(KitchenRecipe::STATUS_APPROVED, $recipe->fresh()->status);
    }

    public function test_reject_returns_recipe_to_draft_with_note(): void
    {
        $this->actingAs(User::findOrFail(1))
            ->post(route('kitchen.recipes.store'), $this->recipePayload('KR-2026-0002'))
            ->assertRedirect();

        $recipe = KitchenRecipe::where('code', 'KR-2026-0002')->firstOrFail();

        $this->actingAs(User::findOrFail(2))
            ->post(route('kitchen.recipes.submit', $recipe->id))
            ->assertRedirect();

        $this->actingAs(User::findOrFail(3))
            ->post(route('kitchen.recipes.reject', $recipe->id), ['approval_note' => 'Check the rice quantity.'])
            ->assertRedirect(route('kitchen.recipes.show', $recipe->id));

        $rejected = $recipe->fresh();
        $this->assertSame(KitchenRecipe::STATUS_DRAFT, $rejected->status);
        $this->assertSame('Check the rice quantity.', $rejected->approval_note);
        $this->assertNull($rejected->submitted_by);
    }

    public function test_user_without_permissions_is_turned_away(): void
    {
        $this->actingAs(User::findOrFail(6))
            ->get(route('kitchen.recipes.index'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs(User::findOrFail(6))
            ->post(route('kitchen.recipes.store'), $this->recipePayload('KR-2026-0003'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, KitchenRecipe::count());
    }

    public function test_other_company_cannot_see_or_touch_company_recipes(): void
    {
        $this->actingAs(User::findOrFail(1))
            ->post(route('kitchen.recipes.store'), $this->recipePayload('KR-2026-0004'))
            ->assertRedirect();

        $recipe = KitchenRecipe::where('code', 'KR-2026-0004')->firstOrFail();

        // Direct URL to the recipe: 403.
        $this->actingAs(User::findOrFail(5))
            ->get(route('kitchen.recipes.show', $recipe->id))
            ->assertForbidden();

        // Direct action URL: 403, status unchanged.
        $this->actingAs(User::findOrFail(5))
            ->post(route('kitchen.recipes.submit', $recipe->id))
            ->assertForbidden();
        $this->assertSame(KitchenRecipe::STATUS_DRAFT, $recipe->fresh()->status);

        // The other company's list is empty: recipes are scoped per company.
        $this->actingAs(User::findOrFail(5))
            ->get(route('kitchen.recipes.index'))
            ->assertOk()
            ->assertDontSee('KR-2026-0004');
    }

    public function test_draft_recipe_cannot_be_versioned(): void
    {
        $this->actingAs(User::findOrFail(1))
            ->post(route('kitchen.recipes.store'), $this->recipePayload('KR-2026-0005'))
            ->assertRedirect();

        $recipe = KitchenRecipe::where('code', 'KR-2026-0005')->firstOrFail();

        $this->actingAs(User::findOrFail(1))
            ->post(route('kitchen.recipes.new-version', $recipe->id))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(1, KitchenRecipe::where('code', 'KR-2026-0005')->count());

        // A draft can still be deleted outright.
        $this->actingAs(User::findOrFail(1))
            ->delete(route('kitchen.recipes.destroy', $recipe->id))
            ->assertRedirect(route('kitchen.recipes.index'));
        $this->assertSame(0, KitchenRecipe::where('code', 'KR-2026-0005')->count());
    }

    public function test_service_type_product_cannot_be_a_recipe_dish(): void
    {
        DB::table('product_services')->insert([
            'id' => 13, 'name' => 'Delivery Service', 'type' => 'service',
            'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 0,
            'unit_id' => null, 'created_by' => 1,
        ]);

        $payload = $this->recipePayload('KR-2026-0006');
        $payload['product_id'] = 13;

        $this->actingAs(User::findOrFail(1))
            ->post(route('kitchen.recipes.store'), $payload)
            ->assertSessionHasErrors('product_id');

        $this->assertSame(0, KitchenRecipe::count());
    }
}
