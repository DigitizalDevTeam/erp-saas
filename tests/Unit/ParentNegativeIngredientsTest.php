<?php

namespace Tests\Unit;

use App\Models\ProductService;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use App\Services\PosWarehouseAvailabilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A parent sold on POS must not be blocked when every ingredient it
 * consumes may go negative — recipe ingredients and selected options.
 * The parent's own stock is not deducted for recipe products, so it
 * must not limit the sale either in that case. Any single unflagged
 * short component still blocks, and a plain product's own stock still
 * limits it.
 */
class ParentNegativeIngredientsTest extends TestCase
{
    private int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('warehouses', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('branch_id')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('city_zip')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('product_services', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->string('type')->nullable();
            $table->decimal('quantity', 12, 4)->default(0);
            $table->integer('category_id')->nullable();
            $table->integer('low_stock_threshold')->nullable();
            $table->integer('max_stock_threshold')->nullable();
            $table->integer('affect_stock')->default(1);
            $table->integer('allow_negative_stock')->default(0);
            $table->integer('batch_tracking')->default(0);
            $table->integer('identifier_tracking')->default(0);
            $table->integer('variants_enabled')->default(0);
            $table->integer('options_enabled')->default(0);
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
            $table->string('row_no')->nullable();
            $table->string('shelf_no')->nullable();
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

        $w = new warehouse();
        $w->name = 'BAR';
        $w->created_by = 1;
        $w->save();
        $this->warehouseId = (int) $w->id;
    }

    private function product(string $type, int $allowNegative, float $warehouseQty = 0, int $optionsEnabled = 0): ProductService
    {
        $p = new ProductService();
        $p->name = 'Item ' . uniqid();
        $p->sku = 'SKU-' . uniqid();
        $p->type = $type;
        $p->quantity = $warehouseQty;
        $p->affect_stock = 1;
        $p->allow_negative_stock = $allowNegative;
        $p->options_enabled = $optionsEnabled;
        $p->created_by = 1;
        $p->save();
        WarehouseProduct::create([
            'warehouse_id' => $this->warehouseId,
            'product_id' => $p->id,
            'quantity' => $warehouseQty,
            'created_by' => 1,
        ]);

        return $p;
    }

    private function recipe(int $parentId, int $ingredientId, float $qty): void
    {
        DB::table('product_recipes')->insert([
            'product_id' => $parentId,
            'ingredient_product_id' => $ingredientId,
            'quantity' => $qty,
            'created_by' => 1,
        ]);
    }

    private function sellable(ProductService $product, array $selectedOptions = []): int
    {
        return app(PosWarehouseAvailabilityService::class)->maxSellableQty(
            $product, $this->warehouseId, null, $selectedOptions, 1
        );
    }

    public function test_recipe_parent_with_all_ingredients_flagged_is_not_blocked_by_zero_own_stock(): void
    {
        $parent = $this->product('product', 0, 0);
        $ingA = $this->product('ingredient', 1, 0);
        $ingB = $this->product('ingredient', 1, 0);
        $this->recipe($parent->id, $ingA->id, 2);
        $this->recipe($parent->id, $ingB->id, 1);

        // No options selected: before the fix this returned 0 (own stock).
        $this->assertSame(999999, $this->sellable($parent));
    }

    public function test_recipe_parent_with_one_unflagged_short_ingredient_still_blocks(): void
    {
        $parent = $this->product('product', 0, 0);
        $ingA = $this->product('ingredient', 1, 0);
        $ingB = $this->product('ingredient', 0, 0);
        $this->recipe($parent->id, $ingA->id, 2);
        $this->recipe($parent->id, $ingB->id, 1);

        $this->assertSame(0, $this->sellable($parent));
    }

    public function test_recipe_parent_with_unflagged_ingredient_stock_limits_by_it(): void
    {
        $parent = $this->product('product', 0, 0);
        $ingA = $this->product('ingredient', 1, 0);
        $ingB = $this->product('ingredient', 0, 10);
        $this->recipe($parent->id, $ingA->id, 2);
        $this->recipe($parent->id, $ingB->id, 2);

        $this->assertSame(5, $this->sellable($parent));
    }

    public function test_parent_with_all_flagged_options_and_ingredients_is_not_blocked(): void
    {
        $parent = $this->product('product', 0, 0, 1);
        $opt = $this->product('product', 1, 0);
        $ing = $this->product('ingredient', 1, 0);
        $this->recipe($parent->id, $ing->id, 1);

        $this->assertSame(999999, $this->sellable($parent, [
            ['product_id' => $opt->id, 'quantity' => 1],
        ]));
    }

    public function test_parent_with_one_unflagged_short_option_still_blocks(): void
    {
        $parent = $this->product('product', 0, 0, 1);
        $opt = $this->product('product', 0, 0);

        $this->assertSame(0, $this->sellable($parent, [
            ['product_id' => $opt->id, 'quantity' => 1],
        ]));
    }

    public function test_plain_parent_without_recipe_is_still_limited_by_own_stock(): void
    {
        $parent = $this->product('product', 0, 0);

        $this->assertSame(0, $this->sellable($parent));
    }

    public function test_flagged_plain_parent_is_not_blocked(): void
    {
        $parent = $this->product('product', 1, 0);

        $this->assertSame(999999, $this->sellable($parent));
    }
}
