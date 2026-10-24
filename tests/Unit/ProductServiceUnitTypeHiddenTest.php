<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * "How stock is counted" (unit_type) is hidden from the product form:
 * create defaults it to Piece/unit, edit preserves the saved value,
 * and the dimension JS still reads the same element IDs.
 */
class ProductServiceUnitTypeHiddenTest extends TestCase
{
    public function test_create_form_hides_unit_type_dropdown(): void
    {
        $blade = file_get_contents(base_path('resources/views/productservice/create.blade.php'));

        $this->assertStringNotContainsString("Form::select('unit_type'", $blade);
        $this->assertStringNotContainsString('How stock is counted', $blade);
        $this->assertStringContainsString('<input type="hidden" name="unit_type" id="unit_type_create" value="unit">', $blade);
        // Dimension JS still reads the same ID, so the measures section
        // keeps working for the (now fixed) default value.
        $this->assertStringContainsString("$('#unit_type_create')", $blade);
    }

    public function test_edit_form_hides_unit_type_dropdown_but_preserves_saved_value(): void
    {
        $blade = file_get_contents(base_path('resources/views/productservice/edit.blade.php'));

        $this->assertStringNotContainsString("Form::select('unit_type'", $blade);
        $this->assertStringNotContainsString('How stock is counted', $blade);
        $this->assertStringContainsString('<input type="hidden" name="unit_type" id="unit_type_edit" value="{{ $productService->unit_type ?? \'unit\' }}">', $blade);
        $this->assertStringContainsString("$('#unit_type_edit')", $blade);
    }

    public function test_controller_never_stores_an_invalid_unit_type_enum_value(): void
    {
        $controller = file_get_contents(base_path('app/Http/Controllers/ProductServiceController.php'));

        // unit_type is a MySQL ENUM column, so an unchecked request value
        // would fail the save; the controller must whitelist it.
        $this->assertStringContainsString("in_array(\$request->unit_type, ['kg', 'unit', 'cbm', 'sm', 'volume'], true)", $controller);
    }
}
