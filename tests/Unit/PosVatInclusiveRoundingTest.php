<?php

namespace Tests\Unit;

use App\Http\Controllers\PosController;
use App\Models\ProductService;
use PHPUnit\Framework\TestCase;

/**
 * VAT-inclusive shelf price must survive every POS calculation.
 * 500 (VAT incl. 16%) x 2 = 1000.00, never 999.98 (2 x 499.99 from
 * rebuilding the unit out of the rounded net 431.03).
 */
class PosVatInclusiveRoundingTest extends TestCase
{
    private function inclusiveProduct(float $salePrice): ProductService
    {
        $p = new ProductService();
        $p->sale_price = $salePrice;
        $p->sale_price_type = 'inclusive';

        return $p;
    }

    public function test_drifted_stored_unit_snaps_back_to_shelf_price(): void
    {
        $unit = PosController::resolveRestaurantInclusiveUnit(431.03, 16, 499.99, $this->inclusiveProduct(500));
        $this->assertSame(500.0, $unit);
        $this->assertSame(1000.0, round($unit * 2, 2));
    }

    public function test_missing_stored_unit_uses_shelf_price_not_rounded_net(): void
    {
        $unit = PosController::resolveRestaurantInclusiveUnit(431.03, 16, null, $this->inclusiveProduct(500));
        $this->assertSame(500.0, $unit);
    }

    public function test_exclusive_priced_product_keeps_stored_unit(): void
    {
        $p = new ProductService();
        $p->sale_price = 100;
        $p->sale_price_type = 'exclusive';

        $unit = PosController::resolveRestaurantInclusiveUnit(100, 16, 116.00, $p);
        $this->assertSame(116.0, $unit);
        $this->assertSame(348.0, round($unit * 3, 2));
    }

    public function test_stale_catalog_does_not_rewrite_line_price(): void
    {
        // Product re-priced 500 -> 600 after the line was added: line keeps 500.
        $unit = PosController::resolveRestaurantInclusiveUnit(431.03, 16, 500.00, $this->inclusiveProduct(600));
        $this->assertSame(500.0, $unit);
    }

    public function test_amounts_from_inclusive_total_split_net_and_vat(): void
    {
        $a = PosController::amountsFromInclusiveTotal(1000, 16);
        $this->assertSame(1000.0, $a['line_total']);
        $this->assertSame(862.07, $a['net']);
        $this->assertSame(137.93, $a['tax']);
    }
}
