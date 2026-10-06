<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * POS print-settings agent template preview must show the VAT-inclusive
 * unit price so Qty x Unit = Total on screen (2 x 199,99 = 399,99),
 * matching what the Print Agent actually prints (unit_incl_vat).
 * The mock previously hardcoded the ex-VAT 172,41 next to the 399,99 total.
 */
class AgentTemplatePreviewUnitInclVatTest extends TestCase
{
    public function test_preview_uses_incl_vat_unit_in_all_templates(): void
    {
        $blade = file_get_contents(resource_path('views/pos/agent-template-preview.blade.php'));
        $this->assertStringNotContainsString('172,41', $blade);
        $this->assertSame(3, substr_count($blade, '199,99'), 'compact, classic and elegant previews must all use the incl-VAT unit');
    }

    public function test_preview_headers_name_the_incl_vat_unit(): void
    {
        $blade = file_get_contents(resource_path('views/pos/agent-template-preview.blade.php'));
        $this->assertGreaterThanOrEqual(2, substr_count($blade, "{{ __('Unit (Incl. VAT)') }}"));
    }
}
