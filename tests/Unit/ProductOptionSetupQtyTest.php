<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Product setup: each linked option product gets its own quantity (like the
 * qty per kit on kit components). The quantity is stored on
 * product_option_items, exposed in the options payload, and pre-filled in
 * the options picker at sale time (the cashier can still change it).
 */
class ProductOptionSetupQtyTest extends TestCase
{
    public function test_migration_and_model_carry_quantity(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_10_06_100000_add_quantity_to_product_option_items.php'));
        $this->assertStringContainsString("Schema::hasColumn('product_option_items', 'quantity')", $migration);
        $this->assertStringContainsString("decimal('quantity', 15, 3)->default(1)", $migration);

        $model = file_get_contents(app_path('Models/ProductOptionItem.php'));
        $this->assertStringContainsString("'quantity',", $model);
        $this->assertStringContainsString("'quantity' => 'float'", $model);
    }

    public function test_setup_save_and_payload_expose_quantity(): void
    {
        $svc = file_get_contents(app_path('Services/ProductOptionService.php'));
        $this->assertStringContainsString("'quantity' => ((\$qty = (float) (\$row['quantity'] ?? 1)) > 0) ? \$qty : 1", $svc);
        $this->assertStringContainsString("'quantity' => ((\$setupQty = (float) (\$item->quantity ?? 1)) > 0) ? \$setupQty : 1", $svc);
    }

    public function test_setup_forms_have_qty_column(): void
    {
        foreach (['create', 'edit'] as $view) {
            $blade = file_get_contents(resource_path("views/productservice/{$view}.blade.php"));
            $this->assertStringContainsString('<th width="110">{{ __(\'Qty\') }}</th>', $blade, $view);
            $this->assertStringContainsString('[quantity]', $blade, $view);
            $this->assertStringContainsString('colspan="5"', $blade, $view);
        }
        $edit = file_get_contents(resource_path('views/productservice/edit.blade.php'));
        $this->assertStringContainsString('($oi->quantity ?? null) !== null', $edit);
    }

    public function test_picker_prefills_setup_quantity(): void
    {
        $js = file_get_contents(public_path('js/product-options-picker.js'));
        $this->assertStringContainsString('var setupQty = parseFloat(opt.quantity);', $js);
        $this->assertStringContainsString('setupQty +', $js);

        $partial = file_get_contents(resource_path('views/partials/product-options-picker.blade.php'));
        $this->assertStringContainsString('?v=20261006a', $partial);
    }
}
