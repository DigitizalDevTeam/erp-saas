<?php

namespace Tests\Unit;

use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Services\PosPrintRoutingService;
use Tests\TestCase;

class PosPrintRoutingServiceTest extends TestCase
{
    public function test_resolves_category_print_zones_for_mixed_order(): void
    {
        $barCat = new ProductServiceCategory(['name' => 'Drinks', 'print_zone' => 'bar']);
        $foodCat = new ProductServiceCategory(['name' => 'Food', 'print_zone' => 'kitchen']);

        $coke = new ProductService(['name' => 'Coke']);
        $coke->setRelation('category', $barCat);

        $burger = new ProductService(['name' => 'Burger']);
        $burger->setRelation('category', $foodCat);

        $this->assertSame('bar', PosPrintRoutingService::resolvePrintZoneForProduct($coke));
        $this->assertSame('kitchen', PosPrintRoutingService::resolvePrintZoneForProduct($burger));

        $jobs = PosPrintRoutingService::buildProductionPrintJobs(1, 1, [
            [
                'name' => 'Coke',
                'qty' => 1,
                'note' => '',
                'print_zone' => PosPrintRoutingService::resolvePrintZoneForProduct($coke),
                'pos_printer_id' => null,
            ],
            [
                'name' => 'Burger',
                'qty' => 1,
                'note' => '',
                'print_zone' => PosPrintRoutingService::resolvePrintZoneForProduct($burger),
                'pos_printer_id' => null,
            ],
        ]);

        $this->assertCount(2, $jobs);
        $types = array_column($jobs, 'printer_type');
        $this->assertContains('bar', $types);
        $this->assertContains('kitchen', $types);
    }

    public function test_infers_bar_from_category_name_when_zone_not_set(): void
    {
        $cat = new ProductServiceCategory(['name' => 'Soft Drinks']);
        $product = new ProductService(['name' => 'Sprite']);
        $product->setRelation('category', $cat);

        $this->assertSame('bar', PosPrintRoutingService::resolvePrintZoneForProduct($product));
    }
}
