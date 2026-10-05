<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * View Bill (before payment) must be titled "Bill"
 * (pt: "Extrato de Conta"), never "Cash Sale" or "Account Statement".
 * Paid receipts keep the POS document type title. Covers every layout.
 */
class PosBillDocumentTitleTest extends TestCase
{
    public function test_standard_layouts_override_title_for_bill(): void
    {
        $src = file_get_contents(resource_path('views/pos/standard/_data.php'));
        $this->assertStringContainsString("if (! empty(\$isBillDocument))", $src);
        $this->assertStringContainsString("\$paymentDocumentTitle = __('pos.bill');", $src);
    }

    public function test_compact_layouts_override_title_for_bill(): void
    {
        $src = file_get_contents(resource_path('views/pos/receipts/_prepare.php'));
        $this->assertStringContainsString("if (! empty(\$isBillDocument))", $src);
        $this->assertStringContainsString("\$paymentDocumentTitle = __('pos.bill');", $src);
    }

    public function test_compact2_badge_uses_resolved_title(): void
    {
        $src = file_get_contents(resource_path('views/pos/receipts/compact2.blade.php'));
        $this->assertStringNotContainsString("? __('Bill') :", $src);
        $this->assertStringContainsString('{{ $paymentDocumentTitle }}', $src);
    }

    public function test_bill_flows_flag_the_document(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/PosController.php'));
        $this->assertStringContainsString("\$isBillDocument = true;", $src);
        $this->assertStringContainsString("'bill' => 1", $src);
        $this->assertStringContainsString("\$isBillDocument = \$request->boolean('bill');", $src);
    }

    public function test_bill_number_uses_vad_prefix_not_pdv(): void
    {
        $this->assertSame('VAD00008', \App\Models\Pos::billDocumentNumber('#PDV00008', []));
        $this->assertSame('VAD00008', \App\Models\Pos::billDocumentNumber('VAD00008', []));
        $this->assertSame('VAD', \App\Models\Pos::billDocumentPrefix([]));

        foreach (['views/pos/standard/_data.php', 'views/pos/receipts/_prepare.php'] as $f) {
            $src = file_get_contents(resource_path($f));
            $this->assertStringContainsString('Pos::billDocumentNumber($docNumber', $src);
        }
    }

    public function test_popup_title_is_bill(): void
    {
        $src = file_get_contents(resource_path('views/pos/index-restaurant.blade.php'));
        $this->assertStringContainsString('__("pos.bill")', $src);
    }
}
