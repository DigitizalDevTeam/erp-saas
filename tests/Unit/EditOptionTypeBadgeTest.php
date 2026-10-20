<?php

namespace Tests\Unit;

use App\Http\Controllers\ProductServiceController;
use App\Models\ProductOptionItem;
use App\Models\ProductService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression (Riz 2026-10-21): the Edit Product page showed "Product" for
 * every assigned option while the POS popup showed the real types
 * (Bogane/Torrie = Ingredient). Cause: ProductServiceController::edit()
 * eager-loaded optionItems.optionProduct with a column list that omitted
 * `type`, so the badge helper received null and fell back to "Product".
 */
class EditOptionTypeBadgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('product_option_items');
        Schema::dropIfExists('product_services');

        Schema::create('product_services', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->string('type')->default('product');
            $table->decimal('sale_price', 16, 4)->default(0);
            $table->decimal('purchase_price', 16, 4)->default(0);
            $table->boolean('options_enabled')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('product_option_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_product_id');
            $table->unsignedBigInteger('option_product_id');
            $table->string('option_group')->nullable();
            $table->boolean('is_required')->default(false);
            $table->decimal('quantity', 16, 4)->default(1);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    private function makeProduct(array $attrs): ProductService
    {
        $p = new ProductService();
        foreach (array_merge([
            'name' => 'P', 'sku' => null, 'type' => 'product',
            'sale_price' => 100, 'purchase_price' => 0,
            'options_enabled' => false, 'created_by' => 1,
        ], $attrs) as $k => $v) {
            $p->{$k} = $v;
        }
        $p->save();

        return $p;
    }

    public function test_edit_page_option_badge_uses_the_real_type(): void
    {
        $parent = $this->makeProduct(['name' => 'Cafe Latte', 'options_enabled' => true]);
        $ingredient = $this->makeProduct(['name' => 'Bogane', 'type' => 'ingredient']);

        $item = new ProductOptionItem();
        $item->parent_product_id = $parent->id;
        $item->option_product_id = $ingredient->id;
        $item->quantity = 40;
        $item->created_by = 1;
        $item->save();

        // The exact eager load the edit action performs.
        $parent->load(['optionItems.optionProduct:id,name,sku,type,sale_price,purchase_price']);
        $loaded = $parent->optionItems->first()->optionProduct;

        $this->assertSame('ingredient', $loaded->type);
        $badge = ProductServiceController::posProductTypeBadgeHtml($loaded->type ?? null);
        $this->assertStringContainsString('>Ingredient<', $badge);
    }

    public function test_edit_action_loads_the_type_column(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/ProductServiceController.php'));
        $this->assertStringContainsString(
            'optionItems.optionProduct:id,name,sku,type,sale_price,purchase_price',
            $src,
            'edit() must include `type` in the optionProduct column list or the Edit page badge falls back to Product'
        );
    }
}
