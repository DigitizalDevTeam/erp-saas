<?php

namespace Tests\Unit;

use App\Http\Controllers\ProductServiceController;
use Tests\TestCase;

class PosProductTypeTagTest extends TestCase
{
    public function test_product_tag(): void
    {
        $html = ProductServiceController::posProductTypeTagHtml('product');
        $this->assertStringContainsString('pos-product-type-tag', $html);
        $this->assertStringContainsString('bg-primary', $html);
        $this->assertStringContainsString('Product', $html);
    }

    public function test_kit_and_service_and_ingredient_tags(): void
    {
        $this->assertStringContainsString('Kit', ProductServiceController::posProductTypeTagHtml('kit'));
        $this->assertStringContainsString('Service', ProductServiceController::posProductTypeTagHtml('service'));
        $this->assertStringContainsString('Ingredient', ProductServiceController::posProductTypeTagHtml('ingredient'));
    }

    public function test_empty_type_defaults_to_product(): void
    {
        $this->assertStringContainsString('Product', ProductServiceController::posProductTypeTagHtml(null));
        $this->assertStringContainsString('Product', ProductServiceController::posProductTypeTagHtml(''));
    }

    public function test_no_stock_tag_shown_when_does_not_affect_stock(): void
    {
        $html = ProductServiceController::posDoesNotAffectStockTagHtml('product', false);
        $this->assertStringContainsString('pos-product-no-stock-tag', $html);
        $this->assertStringContainsString('Does not affect stock', $html);
    }

    public function test_no_stock_tag_hidden_when_stock_affected(): void
    {
        $this->assertSame('', ProductServiceController::posDoesNotAffectStockTagHtml('product', true));
    }

    public function test_kit_always_shows_no_stock_tag(): void
    {
        $this->assertStringContainsString('Does not affect stock', ProductServiceController::posDoesNotAffectStockTagHtml('kit', true));
    }
}
