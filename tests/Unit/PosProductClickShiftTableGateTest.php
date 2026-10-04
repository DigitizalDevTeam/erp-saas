<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * On the restaurant POS, a product click is gated before anything else:
 * 1. no open shift -> trigger the Open Shift button / pop-up
 * 2. dine-in with no open table -> trigger the Open Table button (table grid)
 * Only then do the normal variant/options/cart checks run.
 */
class PosProductClickShiftTableGateTest extends TestCase
{
    public function test_product_click_checks_shift_then_table_first(): void
    {
        $view = file_get_contents(resource_path('views/pos/index-restaurant.blade.php'));

        $clickPos = strpos($view, "$(document).on('click', '.toacart'");
        $this->assertNotFalse($clickPos, 'Product click handler missing');

        $shiftGatePos = strpos($view, "if (!$('#pos_shift_id_hidden').val())", $clickPos);
        $tableGatePos = strpos($view, "clickOrderType === 'dine_in' && !clickTableId", $clickPos);
        $firstRealCheckPos = strpos($view, 'if (!navigator.onLine', $clickPos);

        // Both gates exist and run before every other check in the handler.
        $this->assertNotFalse($shiftGatePos);
        $this->assertNotFalse($tableGatePos);
        $this->assertLessThan($firstRealCheckPos, $shiftGatePos);
        $this->assertLessThan($tableGatePos, $shiftGatePos);
        $this->assertLessThan($firstRealCheckPos, $tableGatePos);

        // Shift gate triggers the Open Shift button, falling back to the modal.
        $this->assertStringContainsString("$('#pos-open-shift-btn')", $view);
        $this->assertStringContainsString("$('#posOpenShiftModal').modal('show')", $view);

        // Table gate triggers the Open Table panel button.
        $this->assertStringContainsString("$('#pos-open-table-panel-btn')", $view);
        $this->assertStringContainsString("$('#pos-restaurant-table-grid-wrap')", $view);
    }
}
