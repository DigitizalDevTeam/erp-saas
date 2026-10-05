<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The pre-payment Bill prints exactly like the invoice (same method),
 * keeping its own content/layout, and agent slips show the unit column:
 * - bill popup view data carries the branch, so the receipt templates
 *   enable the Print Agent for bills (not only invoices)
 * - the native agent payload carries unit_incl_vat per row + label
 * - the C# agent prints that value in the price column
 */
class PosBillPrintLikeInvoiceTest extends TestCase
{
    public function test_bill_modal_sets_branch_print_context(): void
    {
        $ctl = file_get_contents(app_path('Http/Controllers/PosController.php'));
        $billPos = strpos($ctl, 'function billModalContent');
        $this->assertNotFalse($billPos);
        $branchPos = strpos($ctl, "\$details['branch'] = [", $billPos);
        $this->assertNotFalse($branchPos, 'billModalContent must set $details[branch] so bill printing can use the Print Agent');
        $snippet = substr($ctl, $billPos, $branchPos - $billPos);
        $this->assertStringContainsString("value('branch_id')", $snippet);
    }

    public function test_agent_payload_rows_carry_unit_incl_vat(): void
    {
        $svc = file_get_contents(app_path('Services/PosPrinterAgentService.php'));
        $this->assertStringContainsString("'unit_incl_vat' => round((float) (\$amounts['unit'] ?? 0), 2)", $svc);
        $this->assertStringContainsString("'unit_incl_vat' => __('Unit (Incl. VAT)')", $svc);
    }

    public function test_agent_slip_prints_unit_incl_vat_column(): void
    {
        $cs = file_get_contents(base_path('printer-agent/SlipPrinter.cs'));
        $this->assertStringContainsString('GetDecimal(row, "unit_incl_vat")', $cs);
        $this->assertStringContainsString('Label(labels, "unit_incl_vat", "UNIT (INCL. VAT)")', $cs);
        $this->assertStringContainsString('priceLabel.Length > 7 ? "UNIT" : priceLabel', $cs);
        // VAT rate column right after Qty, fed by the payload vat_rate.
        $this->assertStringContainsString("'vat_rate' => round((float) \$taxRate, 2)", $svc);
        $this->assertStringContainsString('Label(labels, "vat", "VAT")', $cs);
        $this->assertStringContainsString('GetDecimal(row, "vat_rate")', $cs);
        $this->assertStringContainsString('PadColumns(nameChunks[0], qtyText, vatText, priceText, totalText)', $cs);
        $this->assertStringContainsString('WrapText(name, 15)', $cs);
    }
}
