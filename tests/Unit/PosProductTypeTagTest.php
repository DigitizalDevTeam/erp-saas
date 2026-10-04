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
}
