<?php

namespace Tests\Unit;

use App\Models\Pos;
use App\Models\PosProduct;
use App\Models\ProductOptionItem;
use App\Models\ProductRecipe;
use App\Models\ProductRecipeAlternative;
use App\Models\ProductService;
use App\Models\User;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use App\Services\PosLineStockRestoreService;
use App\Services\ProductOptionService;
use App\Services\RecipeIngredientSubstitutionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 7: option ingredients in the restaurant POS popup, with radio
 * alternatives (one per ingredient) and per-alternative quantities.
 * Covers the payload, the normalize round-trip, the send deduction
 * (alternative qty, not the main's), the void restore, the kitchen
 * labels, and the Task 6 cook pick using the same quantity rule.
 */
class OptionIngredientsPopupTest extends TestCase
{
    private int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('type')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('default_company_id')->nullable();
            $table->timestamps();
        });
        Schema::create('warehouses', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('branch_id')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('product_services', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->string('type')->nullable();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('sale_price', 12, 4)->nullable();
            $table->decimal('purchase_price', 12, 4)->nullable();
            $table->string('sale_price_type')->nullable();
            $table->integer('affect_stock')->default(1);
            $table->integer('allow_negative_stock')->default(0);
            $table->integer('batch_tracking')->default(0);
            $table->integer('identifier_tracking')->default(0);
            $table->integer('options_enabled')->default(0);
            $table->integer('created_by');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('product_service_units', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('product_variants', function ($table) {
            $table->bigIncrements('id');
            $table->integer('product_service_id')->nullable();
            $table->timestamps();
        });
        Schema::create('warehouse_products', function ($table) {
            $table->bigIncrements('id');
            $table->integer('warehouse_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('product_option_items', function ($table) {
            $table->bigIncrements('id');
            $table->integer('parent_product_id');
            $table->integer('option_product_id');
            $table->string('option_group', 100)->nullable();
            $table->boolean('is_required')->default(false);
            $table->decimal('quantity', 15, 3)->default(1);
            $table->string('unit', 16)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
        Schema::create('product_recipes', function ($table) {
            $table->bigIncrements('id');
            $table->integer('product_id')->nullable();
            $table->integer('ingredient_product_id')->nullable();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->string('unit')->nullable();
            $table->integer('unit_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('product_recipe_alternatives', function ($table) {
            $table->bigIncrements('id');
            $table->integer('product_recipe_id');
            $table->integer('alternative_product_id');
            $table->integer('sort_order')->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        // The Task 7 migration itself adds the quantity column.
        $migration = require base_path('database/migrations/2026_10_26_010000_add_quantity_to_product_recipe_alternatives.php');
        $migration->up();
        Schema::create('stock_reports', function ($table) {
            $table->bigIncrements('id');
            $table->integer('product_id');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->string('type')->nullable();
            $table->integer('type_id')->nullable();
            $table->integer('warehouse_loss_id')->nullable();
            $table->string('description')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('pos', function ($table) {
            $table->bigIncrements('id');
            $table->string('pos_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_products', function ($table) {
            $table->bigIncrements('id');
            $table->integer('pos_id')->nullable();
            $table->integer('product_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->decimal('quantity', 12, 4)->default(1);
            $table->string('status')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamp('removed_from_cart_at')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_line_ingredient_substitutions', function ($table) {
            $table->bigIncrements('id');
            $table->integer('pos_product_id');
            $table->integer('product_recipe_id');
            $table->integer('main_ingredient_id');
            $table->integer('alternative_id');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->integer('warehouse_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        $user = new User();
        $user->name = 'Company';
        $user->email = 'company@example.com';
        $user->password = bcrypt('secret');
        $user->type = 'company';
        $user->save();
        Auth::login($user);

        $w = new warehouse();
        $w->name = 'BAR';
        $w->created_by = 1;
        $w->save();
        $this->warehouseId = (int) $w->id;
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('pos_line_ingredient_substitutions');
        Schema::dropIfExists('pos_products');
        Schema::dropIfExists('pos');
        Schema::dropIfExists('stock_reports');
        Schema::dropIfExists('product_recipe_alternatives');
        Schema::dropIfExists('product_recipes');
        Schema::dropIfExists('product_option_items');
        Schema::dropIfExists('warehouse_products');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_service_units');
        Schema::dropIfExists('product_services');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    private function product(string $type, float $qty, int $affect = 1): ProductService
    {
        $p = new ProductService();
        $p->name = 'Item ' . uniqid();
        $p->sku = 'SKU-' . uniqid();
        $p->type = $type;
        $p->quantity = $qty;
        $p->affect_stock = $affect;
        $p->created_by = 1;
        $p->save();
        WarehouseProduct::create([
            'warehouse_id' => $this->warehouseId,
            'product_id' => $p->id,
            'quantity' => $qty,
            'created_by' => 1,
        ]);

        return $p;
    }

    private function warehouseQty(int $productId): float
    {
        return (float) (WarehouseProduct::where('warehouse_id', $this->warehouseId)->where('product_id', $productId)->value('quantity') ?? 0);
    }

    /** @return array{ProductService, ProductService, ProductService, ProductService, ProductRecipe} */
    private function setupOptionRecipe(float $altQty = 1.5): array
    {
        $parent = $this->product('product', 0);
        $parent->options_enabled = 1;
        $parent->save();
        $option = $this->product('product', 50);
        $wine = $this->product('ingredient', 100);
        $vinegar = $this->product('ingredient', 100);

        ProductOptionItem::create([
            'parent_product_id' => $parent->id,
            'option_product_id' => $option->id,
            'sort_order' => 0,
            'created_by' => 1,
            'quantity' => 2,
        ]);

        $recipe = new ProductRecipe();
        $recipe->product_id = $option->id;
        $recipe->ingredient_product_id = $wine->id;
        $recipe->quantity = 0.5;
        $recipe->created_by = 1;
        $recipe->save();

        $alt = new ProductRecipeAlternative();
        $alt->product_recipe_id = $recipe->id;
        $alt->alternative_product_id = $vinegar->id;
        $alt->quantity = $altQty;
        $alt->sort_order = 0;
        $alt->created_by = 1;
        $alt->save();

        return [$parent, $option, $wine, $vinegar, $recipe];
    }

    public function test_migration_adds_quantity_column(): void
    {
        $this->assertTrue(Schema::hasColumn('product_recipe_alternatives', 'quantity'));

        [$parent, $option, $wine, $vinegar, $recipe] = $this->setupOptionRecipe(2.0);
        $alt = ProductRecipeAlternative::where('product_recipe_id', $recipe->id)->first();
        $this->assertNotNull($alt);
        $this->assertSame(2.0, (float) $alt->quantity);
        $this->assertSame(2.0, $alt->effectiveQuantity(0.5));
    }

    public function test_normalize_keeps_ingredient_substitutes(): void
    {
        $normalized = ProductOptionService::normalizeSelectedOptions([
            [
                'product_id' => 7,
                'quantity' => 2,
                'name' => 'Sauce',
                'ingredient_substitutes' => [
                    ['recipe_id' => 10, 'alternative_product_id' => 20],
                    ['recipe_id' => 10, 'alternative_product_id' => 21],
                    ['recipe_id' => 0, 'alternative_product_id' => 22],
                    ['recipe_id' => 11, 'alternative_product_id' => 0],
                ],
            ],
        ]);

        $this->assertSame([['recipe_id' => 10, 'alternative_product_id' => 20]], $normalized[0]['ingredient_substitutes']);
        // Round-trip through encode keeps the picks.
        $again = ProductOptionService::normalizeSelectedOptions(ProductOptionService::encodeOptions($normalized));
        $this->assertSame([['recipe_id' => 10, 'alternative_product_id' => 20]], $again[0]['ingredient_substitutes']);
    }

    public function test_payload_includes_option_ingredients_with_alternative_quantities(): void
    {
        [$parent, $option, $wine, $vinegar, $recipe] = $this->setupOptionRecipe(1.5);

        $plain = ProductOptionService::productJsonExtras($parent->fresh());
        $this->assertArrayNotHasKey('ingredients', $plain['options'][0]);

        $with = ProductOptionService::productJsonExtras($parent->fresh(), null, null, true);
        $ingredients = $with['options'][0]['ingredients'];
        $this->assertCount(1, $ingredients);
        $this->assertSame($recipe->id, $ingredients[0]['recipe_id']);
        $this->assertSame($wine->name, $ingredients[0]['ingredient_name']);
        $this->assertSame(0.5, $ingredients[0]['quantity']);
        $this->assertTrue($ingredients[0]['affects_stock']);
        $this->assertCount(1, $ingredients[0]['alternatives']);
        $this->assertSame($vinegar->id, $ingredients[0]['alternatives'][0]['id']);
        $this->assertSame(1.5, (float) $ingredients[0]['alternatives'][0]['quantity']);
    }

    public function test_payload_alternative_without_quantity_falls_back_to_main_quantity(): void
    {
        [$parent, $option, $wine, $vinegar, $recipe] = $this->setupOptionRecipe();
        $lemon = $this->product('ingredient', 50);
        $alt = new ProductRecipeAlternative();
        $alt->product_recipe_id = $recipe->id;
        $alt->alternative_product_id = $lemon->id;
        $alt->quantity = null;
        $alt->sort_order = 1;
        $alt->created_by = 1;
        $alt->save();

        $with = ProductOptionService::productJsonExtras($parent->fresh(), null, null, true);
        $alts = $with['options'][0]['ingredients'][0]['alternatives'];
        $this->assertCount(2, $alts);
        $this->assertSame(0.5, (float) $alts[1]['quantity']);
    }

    public function test_movements_use_alternative_quantity_when_picked(): void
    {
        [$parent, $option, $wine, $vinegar, $recipe] = $this->setupOptionRecipe(1.5);

        // Option qty 2 x line qty 3 x alternative qty 1.5 = 9 of vinegar.
        $moves = ProductOptionService::optionRecipeMovements([
            ['product_id' => $option->id, 'quantity' => 2, 'ingredient_substitutes' => [
                ['recipe_id' => $recipe->id, 'alternative_product_id' => $vinegar->id],
            ]],
        ], 3, 1);

        $this->assertCount(1, $moves);
        $this->assertSame($vinegar->id, $moves[0]['product_id']);
        $this->assertSame(9.0, $moves[0]['quantity']);
    }

    public function test_movements_use_main_quantity_when_nothing_picked(): void
    {
        [$parent, $option, $wine, $vinegar, $recipe] = $this->setupOptionRecipe(1.5);

        // Option qty 2 x line qty 3 x main qty 0.5 = 3 of wine.
        $moves = ProductOptionService::optionRecipeMovements([
            ['product_id' => $option->id, 'quantity' => 2],
        ], 3, 1);

        $this->assertCount(1, $moves);
        $this->assertSame($wine->id, $moves[0]['product_id']);
        $this->assertSame(3.0, $moves[0]['quantity']);
    }

    public function test_movements_fall_back_to_main_for_undefined_pick(): void
    {
        [$parent, $option, $wine, $vinegar, $recipe] = $this->setupOptionRecipe(1.5);
        $stranger = $this->product('ingredient', 10);

        $moves = ProductOptionService::optionRecipeMovements([
            ['product_id' => $option->id, 'quantity' => 1, 'ingredient_substitutes' => [
                ['recipe_id' => $recipe->id, 'alternative_product_id' => $stranger->id],
            ]],
        ], 1, 1);

        $this->assertCount(1, $moves);
        $this->assertSame($wine->id, $moves[0]['product_id']);
        $this->assertSame(0.5, $moves[0]['quantity']);
    }

    public function test_movements_skip_no_affect_main(): void
    {
        [$parent, $option, $wine, $vinegar, $recipe] = $this->setupOptionRecipe(1.5);
        $wine->affect_stock = 0;
        $wine->save();

        $moves = ProductOptionService::optionRecipeMovements([
            ['product_id' => $option->id, 'quantity' => 1, 'ingredient_substitutes' => [
                ['recipe_id' => $recipe->id, 'alternative_product_id' => $vinegar->id],
            ]],
        ], 1, 1);

        $this->assertSame([], $moves);
    }

    public function test_deduct_option_recipes_and_restore_return_stock(): void
    {
        [$parent, $option, $wine, $vinegar, $recipe] = $this->setupOptionRecipe(1.5);

        ProductOptionService::deductOptionRecipesForSale([
            ['product_id' => $option->id, 'quantity' => 2, 'ingredient_substitutes' => [
                ['recipe_id' => $recipe->id, 'alternative_product_id' => $vinegar->id],
            ]],
        ], 3, $this->warehouseId, 1, 'PDV00001', 1);

        // 1.5 x 2 x 3 = 9 of vinegar only.
        $this->assertSame(91.0, $this->warehouseQty($vinegar->id));
        $this->assertSame(91.0, (float) $vinegar->fresh()->quantity);
        $this->assertSame(100.0, $this->warehouseQty($wine->id));

        $restore = new \ReflectionMethod(PosLineStockRestoreService::class, 'restoreOptionRecipes');
        $restore->setAccessible(true);
        $restore->invoke(null, [
            ['product_id' => $option->id, 'quantity' => 2, 'ingredient_substitutes' => [
                ['recipe_id' => $recipe->id, 'alternative_product_id' => $vinegar->id],
            ]],
        ], 3, $this->warehouseId, 1, 'PDV00001', 1);

        $this->assertSame(100.0, $this->warehouseQty($vinegar->id));
        $this->assertSame(100.0, (float) $vinegar->fresh()->quantity);
    }

    public function test_cook_substitution_uses_alternative_quantity(): void
    {
        [$parent, $option, $wine, $vinegar, $recipe] = $this->setupOptionRecipe(1.5);
        // Cooking-time substitution only triggers when the main is missing.
        WarehouseProduct::where('warehouse_id', $this->warehouseId)->where('product_id', $wine->id)->update(['quantity' => 0]);

        $pos = new Pos();
        $pos->pos_id = 'PDV00001';
        $pos->warehouse_id = $this->warehouseId;
        $pos->created_by = 1;
        $pos->save();

        $line = new PosProduct();
        $line->pos_id = $pos->id;
        $line->product_id = $option->id;
        $line->warehouse_id = $this->warehouseId;
        $line->quantity = 2;
        $line->status = PosProduct::STATUS_PENDING;
        $line->sent_at = now();
        $line->save();

        $service = new RecipeIngredientSubstitutionService();
        $result = $service->substitute($line, $recipe->id, $vinegar->id, 1);

        // Alternative qty 1.5 x line qty 2 = 3, not the main's 0.5 x 2 = 1.
        $this->assertSame(3.0, $result['quantity']);
        $this->assertSame(97.0, $this->warehouseQty($vinegar->id));
        $this->assertSame(0.0, $this->warehouseQty($wine->id));
    }

    public function test_display_labels_show_picks(): void
    {
        [$parent, $option, $wine, $vinegar, $recipe] = $this->setupOptionRecipe(1.5);

        $lines = ProductOptionService::optionDisplayLines([
            ['product_id' => $option->id, 'name' => $option->name, 'quantity' => 2, 'ingredient_substitutes' => [
                ['recipe_id' => $recipe->id, 'alternative_product_id' => $vinegar->id],
            ]],
        ]);

        $this->assertCount(1, $lines);
        $this->assertStringContainsString($option->name . ' ×2', $lines[0]);
        $this->assertStringContainsString($vinegar->name . ' 1.5 instead of ' . $wine->name . ' 0.5', $lines[0]);
    }
}
