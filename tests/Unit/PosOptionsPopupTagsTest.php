<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Product options pop-up: option rows are products themselves, so the picker
 * shows a "Does not affect stock" tag (dark badge, same as the POS card tag)
 * when the option product has affect_stock off, plus a Variants: N tag when
 * the option product has variants.
 */
class PosOptionsPopupTagsTest extends TestCase
{
    public function test_options_payload_carries_stock_and_variant_flags(): void
    {
        $svc = file_get_contents(app_path('Services/ProductOptionService.php'));
        $this->assertStringContainsString("'does_not_affect_stock' => ! (bool) (\$op->affect_stock ?? true)", $svc);
        $this->assertStringContainsString("'variants_count' => (int) (\$op->variants_count ?? 0)", $svc);
        $this->assertStringContainsString("withCount('variants')", $svc);
    }

    public function test_picker_renders_option_tags(): void
    {
        $js = file_get_contents(public_path('js/product-options-picker.js'));
        $this->assertStringContainsString('opt.does_not_affect_stock', $js);
        $this->assertStringContainsString("t('noStock', 'Does not affect stock')", $js);
        $this->assertStringContainsString('opt.variants_count', $js);
        $this->assertStringContainsString("t('variants', 'Variants')", $js);
        $this->assertStringContainsString('noStockBadge', $js);
        $this->assertStringContainsString('variantsBadge', $js);
    }

    public function test_picker_partial_defines_labels_and_fresh_asset(): void
    {
        $partial = file_get_contents(resource_path('views/partials/product-options-picker.blade.php'));
        $this->assertStringContainsString("noStock: @json(__('Does not affect stock'))", $partial);
        $this->assertStringContainsString("variants: @json(__('Variants'))", $partial);
        $this->assertStringContainsString('product-options-picker.js', $partial);
    }
}
