<?php

namespace Tests\Unit;

use App\Exceptions\KitchenConversionMissingException;
use App\Models\KitchenRecipe;
use App\Models\ProductRecipe;
use App\Models\ProductService;
use App\Models\User;
use App\Services\KitchenRecipeApprovalService;
use App\Services\KitchenRecipeCostService;
use App\Services\KitchenUnitConversionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KitchenRecipeCostTest extends TestCase
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
            $table->timestamps();
        });

        // Units: 1 = kg, 2 = g, 4 = L, 5 = ml.
        DB::table('product_service_units')->insert([
            ['id' => 1, 'name' => 'kg', 'created_by' => 1],
            ['id' => 2, 'name' => 'g', 'created_by' => 1],
            ['id' => 4, 'name' => 'L', 'created_by' => 1],
            ['id' => 5, 'name' => 'ml', 'created_by' => 1],
        ]);
        DB::table('kitchen_unit_conversions')->insert([
            ['from_unit_id' => 1, 'to_unit_id' => 2, 'factor' => 1000, 'product_id' => null, 'created_by' => 1],
            ['from_unit_id' => 4, 'to_unit_id' => 5, 'factor' => 1000, 'product_id' => null, 'created_by' => 1],
        ]);

        // Dish: coconut rice, sells at 50 ex-VAT per portion.
        DB::table('product_services')->insert([
            'id' => 10, 'name' => 'Coconut Rice', 'type' => 'product',
            'sale_price' => 50, 'sale_price_type' => 'exclusive', 'purchase_price' => 0,
            'unit_id' => 2, 'created_by' => 1,
        ]);
        // Ingredients, stocked in kg / L.
        DB::table('product_services')->insert([
            ['id' => 11, 'name' => 'Uncooked Rice', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 100, 'unit_id' => 1, 'created_by' => 1],
            ['id' => 12, 'name' => 'Coconut Milk', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 200, 'unit_id' => 4, 'created_by' => 1],
        ]);

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1],
            ['id' => 2, 'name' => 'Chef', 'email' => 'chef@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1],
            ['id' => 3, 'name' => 'Manager', 'email' => 'manager@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('product_recipe_alternatives');
        Schema::dropIfExists('product_recipes');
        Schema::dropIfExists('kitchen_unit_conversions');
        Schema::dropIfExists('kitchen_recipes');
        Schema::dropIfExists('product_services');
        Schema::dropIfExists('product_service_units');

        parent::tearDown();
    }

    private function makeRecipe(array $overrides = []): KitchenRecipe
    {
        return KitchenRecipe::create(array_merge([
            'code' => 'KR-2026-0001',
            'name' => 'Coconut Rice',
            'product_id' => 10,
            'version' => 1,
            'status' => KitchenRecipe::STATUS_DRAFT,
            'expected_yield_quantity' => 3,
            'expected_yield_unit_id' => 1,
            'standard_portion_quantity' => 150,
            'standard_portion_unit_id' => 2,
            'loss_percent' => 0,
            'created_by' => 1,
        ], $overrides));
    }

    private function addLine(KitchenRecipe $recipe, int $ingredientId, float $quantity, ?int $unitId): void
    {
        ProductRecipe::create([
            'product_id' => 10,
            'kitchen_recipe_id' => $recipe->id,
            'version' => $recipe->version,
            'ingredient_product_id' => $ingredientId,
            'quantity' => $quantity,
            'unit_id' => $unitId,
            'created_by' => 1,
        ]);
    }

    private function costService(): KitchenRecipeCostService
    {
        return new KitchenRecipeCostService(new KitchenUnitConversionService());
    }

    public function test_coconut_rice_cost_and_food_cost_band(): void
    {
        $recipe = $this->makeRecipe();
        // 1 kg rice @ 100/kg = 100; 0.8 L milk @ 200/L = 160. Base cost 260.
        $this->addLine($recipe, 11, 1, 1);
        $this->addLine($recipe, 12, 0.8, 4);

        $result = $this->costService()->calculate($recipe);

        $this->assertSame(260.0, $result['recipe_cost']);
        // 3 kg yield = 3000 g / 150 g portion = 20 portions.
        $this->assertSame(20.0, $result['portions']);
        $this->assertSame(13.0, $result['cost_per_portion']);
        $this->assertSame(50.0, $result['selling_price_ex_vat']);
        // 13 / 50 = 26% -> excellent (25-28).
        $this->assertSame(26.0, $result['food_cost_percent']);
        $this->assertSame(KitchenRecipeCostService::BAND_EXCELLENT, $result['band']);
    }

    public function test_preparation_loss_grosses_up_recipe_cost(): void
    {
        $recipe = $this->makeRecipe(['loss_percent' => 50]);
        $this->addLine($recipe, 11, 1, 1);

        $result = $this->costService()->calculate($recipe);

        // Base 100, 50% loss -> 100 / (1 - 0.5) = 200.
        $this->assertSame(200.0, $result['recipe_cost']);
    }

    public function test_food_cost_bands_match_danilo_bands(): void
    {
        $band = KitchenRecipeCostService::bandForFoodCostPercent(...);

        $this->assertSame('excellent', $band(10.0));
        $this->assertSame('excellent', $band(24.99));
        $this->assertSame('excellent', $band(25.0));
        $this->assertSame('excellent', $band(28.0));
        $this->assertSame('good', $band(29.0));
        $this->assertSame('good', $band(32.0));
        $this->assertSame('good-', $band(33.0));
        $this->assertSame('good-', $band(36.0));
        $this->assertSame('bad', $band(37.0));
        $this->assertSame('bad', $band(41.0));
        $this->assertSame('very_bad', $band(41.01));
        $this->assertSame('very_bad', $band(60.0));
        $this->assertNull($band(null));
    }

    public function test_missing_conversion_blocks_approval(): void
    {
        $recipe = $this->makeRecipe();
        // Rice line in grams (unit 2) while rice is stocked in kg (unit 1)
        // would convert fine; use a unit with no path instead: unit 9.
        DB::table('product_service_units')->insert(['id' => 9, 'name' => 'cup', 'created_by' => 1]);
        $this->addLine($recipe, 11, 2, 9);

        $service = new KitchenRecipeApprovalService(new KitchenUnitConversionService());

        $this->expectException(KitchenConversionMissingException::class);
        $service->validateApprovable($recipe);
    }

    public function test_edit_of_approved_recipe_creates_new_version(): void
    {
        $recipe = $this->makeRecipe(['status' => KitchenRecipe::STATUS_APPROVED, 'submitted_by' => 2, 'approved_by' => 3]);
        $this->addLine($recipe, 11, 1, 1);

        $service = new KitchenRecipeApprovalService(new KitchenUnitConversionService());
        $copy = $service->createNewVersion($recipe, User::findOrFail(2));

        $this->assertSame(2, (int) $copy->version);
        $this->assertSame(KitchenRecipe::STATUS_DRAFT, $copy->status);
        $this->assertNull($copy->approved_by);
        $this->assertSame(1, $copy->lines()->count());
        $this->assertSame(2, (int) $copy->lines()->first()->version);

        // Old version is untouched.
        $old = KitchenRecipe::findOrFail($recipe->id);
        $this->assertSame(1, (int) $old->version);
        $this->assertSame(KitchenRecipe::STATUS_APPROVED, $old->status);
        $this->assertSame(1, $old->lines()->count());
    }

    public function test_draft_recipe_cannot_be_versioned(): void
    {
        $recipe = $this->makeRecipe(['status' => KitchenRecipe::STATUS_DRAFT]);

        $service = new KitchenRecipeApprovalService(new KitchenUnitConversionService());

        $this->expectExceptionMessage('Only an approved recipe can be versioned');
        $service->createNewVersion($recipe, User::findOrFail(2));
    }

    public function test_self_approval_is_blocked(): void
    {
        $recipe = $this->makeRecipe(['status' => KitchenRecipe::STATUS_PENDING_APPROVAL, 'submitted_by' => 2]);
        $this->addLine($recipe, 11, 1, 1);

        $service = new KitchenRecipeApprovalService(new KitchenUnitConversionService());

        $this->expectExceptionMessage('cannot approve a recipe you submitted yourself');
        $service->approve($recipe, User::findOrFail(2));
    }

    public function test_different_user_can_approve(): void
    {
        $recipe = $this->makeRecipe(['status' => KitchenRecipe::STATUS_PENDING_APPROVAL, 'submitted_by' => 2]);
        $this->addLine($recipe, 11, 1, 1);

        $service = new KitchenRecipeApprovalService(new KitchenUnitConversionService());
        $approved = $service->approve($recipe, User::findOrFail(3));

        $this->assertSame(KitchenRecipe::STATUS_APPROVED, $approved->status);
        $this->assertSame(3, (int) $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
    }
}
