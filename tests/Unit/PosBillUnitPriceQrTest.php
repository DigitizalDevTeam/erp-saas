<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Bill / pay popup unit prices and receipt QR size:
 * - View Bill rows must carry unit_incl_vat (was empty on the bill popup)
 * - the Pay popup (pos.show) lists item rows with a Unit (Incl. VAT) column
 * - receipt QR codes render big (50mm browser, 0.62 width on the agent)
 *   so they scan easily in preview and in print
 */
class PosBillUnitPriceQrTest extends TestCase
{
    public function test_bill_modal_rows_carry_unit_incl_vat(): void
    {
        $ctl = file_get_contents(app_path('Http/Controllers/PosController.php'));
        $billPos = strpos($ctl, 'function billModalContent');
        $this->assertNotFalse($billPos);
        $unitPos = strpos($ctl, "['unit_incl_vat'] = \$user->priceFormat(self::unitInclVatForSessionRow", $billPos);
        $this->assertNotFalse($unitPos, 'billModalContent must fill unit_incl_vat per row');
    }

    public function test_pay_popup_lists_item_rows_with_unit_price(): void
    {
        $view = file_get_contents(resource_path('views/pos/show.blade.php'));
        $this->assertStringContainsString("__('Unit (Incl. VAT)')", $view);
        $this->assertStringContainsString("\$row['unit_incl_vat']", $view);
        $this->assertStringContainsString("\$row['subtotal']", $view);
        $this->assertStringContainsString("\$sales['data']", $view);
    }

    public function test_receipt_qr_sizes_are_big(): void
    {
        $factura = file_get_contents(resource_path('views/pos/standard/factura_shared.blade.php'));
        $this->assertStringContainsString('width:50mm; height:50mm', $factura);

        foreach (['compact1', 'compact2'] as $tpl) {
            $src = file_get_contents(resource_path("views/pos/receipts/{$tpl}.blade.php"));
            $this->assertStringContainsString('width:50mm;height:50mm', $src);
        }

        $compact3 = file_get_contents(resource_path('views/pos/receipts/compact3.blade.php'));
        $this->assertStringContainsString('width: 50mm; height: 50mm', $compact3);

        $printview = file_get_contents(resource_path('views/pos/printview.blade.php'));
        $this->assertStringContainsString('max-width: 50mm', $printview);
    }

    public function test_agent_prints_qr_big(): void
    {
        $cs = file_get_contents(base_path('printer-agent/SlipPrinter.cs'));
        $this->assertStringContainsString('AddImageFromBase64(lines, qrB64, 0.62f)', $cs);
    }

    public function test_bill_title_language_keys(): void
    {
        $en = file_get_contents(resource_path('lang/en/pos.php'));
        $this->assertStringContainsString("'bill' => 'Bill'", $en);

        $pt = file_get_contents(resource_path('lang/pt/pos.php'));
        $this->assertStringContainsString("'bill' => 'Extrato de Conta'", $pt);
    }
}
