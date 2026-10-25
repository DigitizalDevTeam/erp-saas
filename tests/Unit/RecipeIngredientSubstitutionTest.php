<?php

namespace Tests\Unit;

use App\Models\Pos;
use App\Models\PosProduct;
use App\Models\ProductRecipe;
use App\Models\ProductRecipeAlternative;
use App\Models\ProductService;
use App\Models\PosLineIngredientSubstitution;
use App\Models\StockReport;
use App\Models\User;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use App\Services\RecipeIngredientSubstitutionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Manual substitute at cooking time: the cook picks a defined alternative
 * for a missing recipe ingredient, and stock is deducted from the
 * alternative actually used — never from the missing one.
 */
class RecipeIngredientSubstitutionTest extends TestCase
{
    private RecipeIngredientSubstitutionService $service;
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
            $table->integer('affect_stock')->default(1);
            $table->integer('allow_negative_stock')->default(0);
            $table->integer('batch_tracking')->default(0);
            $table->integer('identifier_tracking')->default(0);
            $table->integer('created_by');
            $table->timestamps();
            $table->softDeletes();
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

        $this->service = new RecipeIngredientSubstitutionService();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('pos_products');
        Schema::dropIfExists('pos');
        Schema::dropIfExists('stock_reports');
        Schema::dropIfExists('pos_line_ingredient_substitutions');
        Schema::dropIfExists('product_recipe_alternatives');
        Schema::dropIfExists('product_recipes');
        Schema::dropIfExists('warehouse_products');
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

    /** @return array{Pos, PosProduct, ProductRecipe} */
    private function setupLine(float $lineQty = 2, float $recipeQty = 0.5, ?string $status = PosProduct::STATUS_PENDING, bool $sent = true): array
    {
        $parent = $this->product('product', 0);
        $wine = $this->product('ingredient', 0);
        $vinegar = $this->product('ingredient', 10);

        $recipe = new ProductRecipe();
        $recipe->product_id = $parent->id;
        $recipe->ingredient_product_id = $wine->id;
        $recipe->quantity = $recipeQty;
        $recipe->created_by = 1;
        $recipe->save();

        $alt = new ProductRecipeAlternative();
        $alt->product_recipe_id = $recipe->id;
        $alt->alternative_product_id = $vinegar->id;
        $alt->sort_order = 0;
        $alt->created_by = 1;
        $alt->save();

        $pos = new Pos();
        $pos->pos_id = 'PDV00001';
        $pos->warehouse_id = $this->warehouseId;
        $pos->created_by = 1;
        $pos->save();

        $line = new PosProduct();
        $line->pos_id = $pos->id;
        $line->product_id = $parent->id;
        $line->warehouse_id = $this->warehouseId;
        $line->quantity = $lineQty;
        $line->status = $status;
        $line->sent_at = $sent ? now() : null;
        $line->save();

        return [$pos, $line, $recipe, $wine, $vinegar];
    }

    public function test_substitute_deducts_alternative_and_records_it(): void
    {
        [$pos, $line, $recipe, $wine, $vinegar] = $this->setupLine();

        $result = $this->service->substitute($line, $recipe->id, $vinegar->id, 1);

        // 0.5 per unit x 2 units = 1 deducted from vinegar only.
        $this->assertSame(1.0, $result['quantity']);
        $this->assertSame(9.0, $this->warehouseQty($vinegar->id));
        $this->assertSame(9.0, (float) $vinegar->fresh()->quantity);
        $this->assertSame(0.0, $this->warehouseQty($wine->id));
        $sub = PosLineIngredientSubstitution::where('pos_product_id', $line->id)->first();
        $this->assertNotNull($sub);
        $this->assertSame($recipe->id, (int) $sub->product_recipe_id);
        $this->assertSame($wine->id, (int) $sub->main_ingredient_id);
        $this->assertSame($vinegar->id, (int) $sub->alternative_id);
        $this->assertSame(1.0, (float) $sub->quantity);
        $report = StockReport::where('product_id', $vinegar->id)->first();
        $this->assertNotNull($report);
        $this->assertStringContainsString('substituted for', (string) $report->description);
    }

    public function test_main_in_stock_is_rejected(): void
    {
        [$pos, $line, $recipe, $wine, $vinegar] = $this->setupLine();
        WarehouseProduct::where('product_id', $wine->id)->update(['quantity' => 5]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->substitute($line, $recipe->id, $vinegar->id, 1);
    }

    public function test_second_substitute_for_same_ingredient_is_rejected(): void
    {
        [$pos, $line, $recipe, $wine, $vinegar] = $this->setupLine();
        $this->service->substitute($line, $recipe->id, $vinegar->id, 1);

        $this->expectException(InvalidArgumentException::class);
        $this->service->substitute($line, $recipe->id, $vinegar->id, 1);
    }

    public function test_undefined_alternative_is_rejected(): void
    {
        [$pos, $line, $recipe, $wine, $vinegar] = $this->setupLine();
        $other = $this->product('ingredient', 10);

        $this->expectException(InvalidArgumentException::class);
        $this->service->substitute($line, $recipe->id, $other->id, 1);
    }

    public function test_unsent_line_is_rejected(): void
    {
        [$pos, $line, $recipe, $wine, $vinegar] = $this->setupLine(sent: false);

        $this->expectException(InvalidArgumentException::class);
        $this->service->substitute($line, $recipe->id, $vinegar->id, 1);
    }

    public function test_served_line_is_rejected(): void
    {
        [$pos, $line, $recipe, $wine, $vinegar] = $this->setupLine(status: PosProduct::STATUS_SERVED);

        $this->expectException(InvalidArgumentException::class);
        $this->service->substitute($line, $recipe->id, $vinegar->id, 1);
    }

    public function test_migration_creates_both_tables(): void
    {
        Schema::dropIfExists('pos_line_ingredient_substitutions');
        Schema::dropIfExists('product_recipe_alternatives');

        $migration = require base_path('database/migrations/2026_10_26_000000_create_recipe_alternatives_and_substitutions_tables.php');
        $migration->up();

        $this->assertTrue(Schema::hasTable('product_recipe_alternatives'));
        $this->assertTrue(Schema::hasTable('pos_line_ingredient_substitutions'));
    }
}
