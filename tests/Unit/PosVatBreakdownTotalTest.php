<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * POS bill & invoice VAT breakdown must end with a Total column
 * (net + tax per VAT rate) — Riz request, red-boxed on his slip:
 * VAT | Net Amount | Tax | Total.
 */
class PosVatBreakdownTotalTest extends TestCase
{
    public function test_invoice_and_bill_vat_breakdown_has_total_column(): void
    {
        $factura = file_get_contents(resource_path('views/pos/standard/factura_shared.blade.php'));
        $this->assertStringContainsString("<th>{{ __('Net Amount') }}</th>", $factura);
        $this->assertStringContainsString("<th>{{ __('Tax') }}</th>\n                            <th>{{ __('Total') }}</th>", $factura);
        $this->assertStringContainsString("\$row['amount'] ?? ((\$row['net'] ?? 0) + (\$row['vat'] ?? 0))", $factura);
        $this->assertStringContainsString('colspan="4"', $factura);
    }

    public function test_receipt_vat_breakdowns_show_total(): void
    {
        $compact3 = file_get_contents(resource_path('views/pos/receipts/compact3.blade.php'));
        $this->assertStringContainsString("<th>{{ __('Total') }}</th>", $compact3);
        $this->assertStringContainsString("\$row['amount'] ?? ((\$row['net'] ?? 0) + (\$row['vat'] ?? 0))", $compact3);

        // compact1/compact2 already had the column; it is now labelled Total.
        foreach (['compact1', 'compact2'] as $tpl) {
            $view = file_get_contents(resource_path("views/pos/receipts/{$tpl}.blade.php"));
            $this->assertStringContainsString("<th class=\"num\">{{ __('Total') }}</th>", $view, $tpl);
            $this->assertStringContainsString("{{ \$fmtMoney(\$row['amount']) }}", $view, $tpl);
        }
    }

    public function test_print_agent_payload_and_renderer_carry_total(): void
    {
        $service = file_get_contents(app_path('Services/PosPrinterAgentService.php'));
        $this->assertStringContainsString("'total' => round((float) \$row['net'] + (float) \$row['tax'], 2),", $service);

        $agent = file_get_contents(base_path('printer-agent/SlipPrinter.cs'));
        $this->assertStringContainsString('GetDecimal(vb, "total")', $agent);
        $this->assertStringContainsString('Label(labels, "total", "Total")', $agent);
        $this->assertStringContainsString('PadBreakdown(', $agent);
    }
}
