<?php

namespace Tests\Unit;

use App\Http\Controllers\ProductServiceController;
use App\Models\ProductService;
use App\Models\ProductOptionItem;
use App\Services\ProductOptionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Product-type tags (Riz 2026-10-21): every assigned option carries its type
 * in the picker payloads, and the shared badge helper renders the same
 * label/colour used on POS cards — Product (blue), Service (info),
 * Kit (warning), Ingredient (grey).
 */
class PosOptionTypeTagsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('product_option_items');
        Schema::dropIfExists('product_services');
        Schema::dropIfExists('product_service_units');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('taxes');

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_service_id')->nullable();
            $table->timestamps();
        });

        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->decimal('rate', 10, 4)->default(0);
            $table->timestamps();
        });
        Schema::create('product_service_units', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('abbreviation')->nullable();
            $table->timestamps();
        });
        Schema::create('product_services', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->string('type')->default('product');
            $table->decimal('sale_price', 16, 4)->default(0);
            $table->decimal('purchase_price', 16, 4)->default(0);
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->unsignedBigInteger('tax_id')->nullable();
            $table->boolean('options_enabled')->default(false);
            $table->boolean('does_not_affect_stock')->default(false);
            $table->boolean('can_be_sold')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('product_option_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('parent_product_id');
            $table->unsignedBigInteger('option_product_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('option_group')->nullable();
            $table->boolean('is_required')->default(false);
            $table->decimal('quantity', 16, 4)->default(1);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    private function makeProduct(array $attrs): ProductService
    {
        $p = new ProductService();
        foreach (array_merge([
            'name' => 'P', 'sku' => null, 'type' => 'product',
            'sale_price' => 100, 'purchase_price' => 0,
            'options_enabled' => false, 'does_not_affect_stock' => false,
            'can_be_sold' => true, 'created_by' => 1,
        ], $attrs) as $k => $v) {
            $p->{$k} = $v;
        }
        $p->save();

        return $p;
    }

    public function test_badge_helper_labels_and_colours(): void
    {
        $this->assertStringContainsString('>Product<', ProductServiceController::posProductTypeBadgeHtml('product'));
        $this->assertStringContainsString('bg-primary', ProductServiceController::posProductTypeBadgeHtml('product'));
        $this->assertStringContainsString('>Service<', ProductServiceController::posProductTypeBadgeHtml('service'));
        $this->assertStringContainsString('bg-info', ProductServiceController::posProductTypeBadgeHtml('service'));
        $this->assertStringContainsString('>Kit<', ProductServiceController::posProductTypeBadgeHtml('kit'));
        $this->assertStringContainsString('bg-warning', ProductServiceController::posProductTypeBadgeHtml('kit'));
        $this->assertStringContainsString('>Ingredient<', ProductServiceController::posProductTypeBadgeHtml('ingredient'));
        $this->assertStringContainsString('bg-secondary', ProductServiceController::posProductTypeBadgeHtml('ingredient'));
        // Null/empty fall back to Product; the POS card tag keeps working too.
        $this->assertStringContainsString('>Product<', ProductServiceController::posProductTypeBadgeHtml(null));
        $this->assertStringContainsString('pos-product-type-tag', ProductServiceController::posProductTypeTagHtml('kit'));
        $this->assertStringContainsString('>Kit<', ProductServiceController::posProductTypeTagHtml('kit'));
    }

    public function test_picker_payload_carries_each_options_type(): void
    {
        $parent = $this->makeProduct(['name' => 'Cafe Latte', 'options_enabled' => true]);
        $kit = $this->makeProduct(['name' => 'Bogane', 'type' => 'kit']);
        $ing = $this->makeProduct(['name' => 'Torrie', 'type' => 'ingredient']);
        $plain = $this->makeProduct(['name' => 'Leite', 'type' => 'product']);

        foreach ([$kit, $ing, $plain] as $i => $opt) {
            $row = new ProductOptionItem();
            $row->parent_product_id = $parent->id;
            $row->option_product_id = $opt->id;
            $row->quantity = 10;
            $row->sort_order = $i;
            $row->created_by = 1;
            $row->save();
        }

        $extras = ProductOptionService::productJsonExtras($parent->fresh());
        $options = collect($extras['options'] ?? [])->keyBy('product_id');

        $this->assertSame('kit', $options[$kit->id]['type'] ?? null);
        $this->assertSame('ingredient', $options[$ing->id]['type'] ?? null);
        $this->assertSame('product', $options[$plain->id]['type'] ?? null);
    }
}
