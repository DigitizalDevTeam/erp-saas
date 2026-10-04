<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * POS "Add Closing Balance" pop-up (Riz instruction):
 * - no Print button in the pop-up (Print stays on the full close page)
 * - the submit says what it does: "Add closing balance and close shift"
 *   (PosController::save_closing_balance saves the balance AND closes).
 */
class PosClosingPopupPrintButtonTest extends TestCase
{
    public function test_popup_hides_print_and_labels_full_action(): void
    {
        $popup = file_get_contents(resource_path('views/pos/add_closing_balance.blade.php'));
        $this->assertStringContainsString("'hidePrint' => true", $popup);
        $this->assertStringContainsString("value=\"{{ __('pos.add_closing_balance') }}\"", $popup);

        $partial = file_get_contents(resource_path('views/pos_shift/partials/close_count.blade.php'));
        $this->assertStringContainsString('@unless($hidePrint ?? false)', $partial);
        $this->assertStringContainsString('shift-close-print', $partial);

        // The full close page does not hide the Print button.
        $closePage = file_get_contents(resource_path('views/pos_shift/close.blade.php'));
        $this->assertStringNotContainsString("'hidePrint' => true", $closePage);

        // Both languages name the full action on the pop-up button.
        $en = file_get_contents(resource_path('lang/en/pos.php'));
        $this->assertStringContainsString("'add_closing_balance' => 'Add closing balance and close shift'", $en);
        $pt = file_get_contents(resource_path('lang/pt/pos.php'));
        $this->assertStringContainsString("'add_closing_balance' => 'Adicionar Saldo Final e Terminar Turno'", $pt);
    }
}
