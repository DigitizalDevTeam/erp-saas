<?php

namespace Tests\Unit;

use App\Models\ProductService;
use PHPUnit\Framework\TestCase;

class ProductServiceSalePriceTypeTest extends TestCase
{
    public function test_exclusive_price_unchanged(): void
    {
        $product = new ProductService([
            'sale_price' => 100,
            'sale_price_type' => 'exclusive',
            'tax_id' => '',
        ]);

        $this->assertFalse($product->isSalePriceInclusive());
        $this->assertSame(100.0, $product->exclusiveSaleUnitPrice());
    }

    public function test_inclusive_without_tax_unchanged(): void
    {
        $product = new ProductService([
            'sale_price' => 116,
            'sale_price_type' => 'inclusive',
            'tax_id' => '',
        ]);

        $this->assertTrue($product->isSalePriceInclusive());
        $this->assertSame(116.0, $product->exclusiveSaleUnitPrice());
    }

    public function test_inclusive_strips_given_price_with_manual_rate(): void
    {
        $product = new class extends ProductService {
            public function taxRate($taxes)
            {
                return 16;
            }
        };
        $product->sale_price = 116;
        $product->sale_price_type = 'inclusive';
        $product->tax_id = '1';

        $this->assertSame(100.0, $product->exclusiveSaleUnitPrice());
        $this->assertSame(200.0, $product->exclusiveSaleUnitPrice(232));
    }

    public function test_inclusive_purchase_strips_with_same_flag(): void
    {
        $product = new class extends ProductService {
            public function taxRate($taxes)
            {
                return 16;
            }
        };
        $product->purchase_price = 638;
        $product->sale_price_type = 'inclusive';
        $product->tax_id = '1';

        $this->assertSame(550.0, $product->exclusivePurchaseUnitPrice());
        $this->assertSame(550.0, $product->exclusivePurchaseUnitPrice(638));
    }

    public function test_exclusive_purchase_unchanged(): void
    {
        $product = new ProductService([
            'purchase_price' => 550,
            'sale_price_type' => 'exclusive',
            'tax_id' => '',
        ]);

        $this->assertSame(550.0, $product->exclusivePurchaseUnitPrice());
    }
}
