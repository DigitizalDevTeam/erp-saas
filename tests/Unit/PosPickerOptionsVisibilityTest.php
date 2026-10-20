<?php

namespace Tests\Unit;

use App\Models\ProductOptionItem;
use App\Models\ProductService;
use App\Services\ProductOptionService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Every option assigned on the product form must be offered in the POS
 * picker — including option products whose own type is kit/ingredient, or
 * that have their own options enabled. (Regression: Café Latte showed
 * only 3 of its 5 assigned options.)
 */
class PosPickerOptionsVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('product_service_units', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('product_services', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->string('type')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('sale_price', 15, 4)->default(0);
            $table->decimal('purchase_price', 15, 4)->default(0);
            $table->boolean('options_enabled')->default(false);
            $table->boolean('affect_stock')->default(true);
            $table->integer('unit_id')->nullable();
            $table->integer('tax_id')->nullable();
            $table->string('sale_price_type')->nullable();
            $table->integer('created_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('product_variants', function ($table) {
            $table->increments('id');
            $table->integer('product_service_id')->nullable();
            $table->timestamps();
        });
        Schema::create('product_option_items', function ($table) {
            $table->increments('id');
            $table->integer('parent_product_id')->nullable();
            $table->integer('option_product_id')->nullable();
            $table->string('option_group')->nullable();
            $table->boolean('is_required')->default(false);
            $table->decimal('quantity', 15, 4)->default(0);
            $table->integer('sort_order')->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('product_option_items');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('product_services');
        Schema::dropIfExists('product_service_units');

        parent::tearDown();
    }

    private function makeProduct(string $name, string $type, array $extra = []): ProductService
    {
        $p = new ProductService();
        $p->name = $name;
        $p->sku = 'SKU-' . $name;
        $p->type = $type;
        $p->quantity = 100;
        $p->sale_price = 10;
        $p->purchase_price = 5;
        $p->affect_stock = true;
        $p->created_by = 1;
        foreach ($extra as $k => $v) {
            $p->$k = $v;
        }
        $p->save();

        return $p;
    }

    private function assign(ProductService $parent, ProductService $option, float $qty): void
    {
        $item = new ProductOptionItem();
        $item->parent_product_id = $parent->id;
        $item->option_product_id = $option->id;
        $item->quantity = $qty;
        $item->created_by = 1;
        $item->save();
    }

    public function test_all_assigned_options_are_offered_regardless_of_option_product_type_or_flags(): void
    {
        $parent = $this->makeProduct('Cafe Latte', 'product', ['options_enabled' => true]);

        $plain = $this->makeProduct('Leite', 'product');
        $kit = $this->makeProduct('Cafe Grao Bogane', 'kit');
        $ingredient = $this->makeProduct('Cafe Grao Torrie', 'ingredient');
        $nested = $this->makeProduct('Nested Option', 'product', ['options_enabled' => true]);

        $this->assign($parent, $plain, 15);
        $this->assign($parent, $kit, 40);
        $this->assign($parent, $ingredient, 40);
        $this->assign($parent, $nested, 50);

        $extras = ProductOptionService::productJsonExtras($parent);

        $this->assertTrue($extras['options_enabled']);
        $ids = array_column($extras['options'], 'product_id');
        $this->assertEqualsCanonicalizing(
            [$plain->id, $kit->id, $ingredient->id, $nested->id],
            $ids
        );
    }

    public function test_deleted_option_product_is_still_skipped(): void
    {
        $parent = $this->makeProduct('Cafe Latte', 'product', ['options_enabled' => true]);
        $plain = $this->makeProduct('Leite', 'product');
        $this->assign($parent, $plain, 15);

        // Option row pointing at a product that no longer exists.
        $ghost = new ProductOptionItem();
        $ghost->parent_product_id = $parent->id;
        $ghost->option_product_id = 99999;
        $ghost->quantity = 10;
        $ghost->created_by = 1;
        $ghost->save();

        $extras = ProductOptionService::productJsonExtras($parent);

        $this->assertSame([$plain->id], array_column($extras['options'], 'product_id'));
    }
}
