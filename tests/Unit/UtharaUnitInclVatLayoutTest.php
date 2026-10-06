<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * UTHARA print layouts: the Unit column next to Qty must print the
 * VAT-inclusive unit price, so Qty x Unit = Total on paper.
 * - Factura A4/A5 (print.uthara.factura): header "Unit (Incl. VAT)" and
 *   the cell reads the incl-VAT unit computed in UtharaPrintRenderer
 *   (previously the raw ex-VAT price, so Qty x Unit != Total).
 * - Talao 80mm (print.uthara.talao_80mm): gains the Unit (Incl. VAT)
 *   column between VAT and Total (previously no unit column at all).
 */
class UtharaUnitInclVatLayoutTest extends TestCase
{
    public function test_factura_payload_computes_unit_incl_vat_from_line_totals(): void
    {
        $svc = file_get_contents(app_path('Services/UtharaPrintRenderer.php'));
        $this->assertStringContainsString("'unit_incl_vat' =>", $svc);
        $this->assertStringContainsString('($lineNet + $lineTax) / $qty', $svc);
        $this->assertStringContainsString("'unit_incl_vat_raw' =>", $svc);
    }

    public function test_factura_blade_prints_unit_incl_vat_header_and_cell(): void
    {
        $blade = file_get_contents(resource_path('views/print/uthara/factura.blade.php'));
        $this->assertStringContainsString("{{ __('Unit (Incl. VAT)') }}", $blade);
        $this->assertStringContainsString("{{ \$line['unit_incl_vat'] ?? \$line['price'] }}", $blade);
    }

    public function test_talao_has_unit_incl_vat_column_after_vat(): void
    {
        $blade = file_get_contents(resource_path('views/print/uthara/talao_80mm.blade.php'));
        $header = "<th class=\"unit\">{{ __('Unit (Incl. VAT)') }}</th>";
        $this->assertStringContainsString($header, $blade);
        $this->assertStringContainsString("{{ \$value['unit_incl_vat'] ?? '' }}", $blade);
        $vatPos = strpos($blade, '<th class="vat">');
        $unitPos = strpos($blade, '<th class="unit">');
        $totalPos = strpos($blade, '<th class="total">');
        $this->assertNotFalse($vatPos);
        $this->assertNotFalse($unitPos);
        $this->assertNotFalse($totalPos);
        $this->assertTrue($vatPos < $unitPos && $unitPos < $totalPos, 'column order must be Qty | VAT | Unit | Total');
    }

    public function test_unit_incl_vat_math_matches_line_total(): void
    {
        // Arroz line on the demo factura: qty 2, price 258.62 ex-VAT, 16% VAT
        $price = 258.62;
        $qty = 2.0;
        $taxRate = 16.0;
        $lineNet = ($price * $qty) - 0.0;
        $lineTax = $lineNet * ($taxRate / 100);
        $unitInclVat = ($lineNet + $lineTax) / $qty;
        $this->assertEqualsWithDelta(300.00, $unitInclVat, 0.01);
        $this->assertEqualsWithDelta(600.00, $qty * $unitInclVat, 0.01);
    }
}
