<?php

namespace Tests\Unit;

use App\Http\Controllers\PosController;
use Tests\TestCase;

/**
 * Receipts/bills show a "Unit (Incl. VAT)" column between Qty and VAT%,
 * using the VAT-inclusive unit price the POS already calculates.
 */
class ReceiptUnitInclVatColumnTest extends TestCase
{
    public function test_unit_derivation_for_session_rows(): void
    {
        // stored inclusive unit wins
        $this->assertSame(500.0, PosController::unitInclVatForSessionRow([
            'quantity' => 2, 'price' => 431.03, 'tax' => 16, 'unit_subtotal' => 500.0, 'subtotal' => 1000.0,
        ]));
        // inclusive subtotal / qty
        $this->assertSame(250.0, PosController::unitInclVatForSessionRow([
            'quantity' => 4, 'price' => 215.52, 'tax' => 16, 'price_type' => 'inclusive', 'subtotal' => 1000.0,
        ]));
        // exclusive price + VAT
        $this->assertSame(116.0, PosController::unitInclVatForSessionRow([
            'quantity' => 3, 'price' => 100.0, 'tax' => 16, 'price_type' => 'exclusive',
        ]));
        // no tax
        $this->assertSame(80.0, PosController::unitInclVatForSessionRow([
            'quantity' => 1, 'price' => 80.0, 'tax' => 0,
        ]));
    }

    public function test_views_have_unit_incl_vat_column(): void
    {
        foreach ([
            'views/pos/standard/factura_shared.blade.php',
            'views/pos/receipts/compact1.blade.php',
            'views/pos/receipts/compact2.blade.php',
            'views/pos/receipts/compact3.blade.php',
        ] as $view) {
            $html = file_get_contents(resource_path($view));
            $this->assertStringContainsString("Unit (Incl. VAT)", $html, $view);
            $this->assertStringContainsString('unit_incl_vat', $html, $view);
        }
    }

    public function test_row_builders_provide_unit_incl_vat(): void
    {
        $ctl = file_get_contents(app_path('Http/Controllers/PosController.php'));
        $this->assertStringContainsString('unitInclVatForSessionRow', $ctl);
        $this->assertStringContainsString("'unit_incl_vat' => \$user->priceFormat(\$amounts['unit'])", $ctl);

        $svc = file_get_contents(app_path('Services/PosPrinterAgentService.php'));
        $this->assertStringContainsString("'unit_incl_vat' => \$user->priceFormat(\$amounts['unit'])", $svc);
    }
}
