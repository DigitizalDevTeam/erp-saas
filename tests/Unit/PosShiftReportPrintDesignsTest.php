<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * POS shift reports use the same print formats as EOD:
 * /report/pos-shift?print=a4|80 and /pos-shift/{id}/report?print=a4|80
 * render standalone auto-print pages.
 */
class PosShiftReportPrintDesignsTest extends TestCase
{
    public function test_multi_shift_report_controller_supports_a4_and_80mm_prints(): void
    {
        $c = file_get_contents(app_path('Http/Controllers/ReportController.php'));
        $this->assertStringContainsString("report.pos_shift_print_a4", $c);
        $this->assertStringContainsString("report.pos_shift_print_80", $c);
        $this->assertStringContainsString("printShiftRows", $c);
        $this->assertStringContainsString("buildShiftMetrics", $c);
    }

    public function test_multi_shift_report_page_has_a4_and_80mm_buttons(): void
    {
        $v = file_get_contents(resource_path('views/report/pos_shift.blade.php'));
        $this->assertStringContainsString("Print A4", $v);
        $this->assertStringContainsString("Print 80mm Slip", $v);
        $this->assertStringContainsString("'print' => 'a4'", $v);
        $this->assertStringContainsString("'print' => '80'", $v);
        $this->assertStringNotContainsString('onclick="window.print();"', $v);
    }

    public function test_multi_shift_a4_print_uses_plain_shift_layout(): void
    {
        $v = file_get_contents(resource_path('views/report/pos_shift_print_a4.blade.php'))
            . file_get_contents(resource_path('views/report/partials/pos_shift_report_a4_content.blade.php'))
            . file_get_contents(resource_path('views/report/partials/pos_shift_a4_styles.blade.php'));
        $this->assertStringContainsString("@page { size: A4", $v);
        $this->assertStringContainsString('pos_shift_report_a4_content', $v);
        $this->assertStringContainsString('shift-a4-data', $v);
        $this->assertStringContainsString('printShiftRows', $v);
        $this->assertStringContainsString('paymentsByMethod', $v);
        $this->assertStringContainsString('window.print()', $v);
    }

    public function test_multi_shift_80mm_print_is_a_slip(): void
    {
        $v = file_get_contents(resource_path('views/report/pos_shift_print_80.blade.php'));
        $this->assertStringContainsString('width:72mm', $v);
        $this->assertStringContainsString('@page { size: 80mm auto', $v);
        $this->assertStringContainsString('POS SHIFT REPORT', $v);
        $this->assertStringContainsString('printShiftRows', $v);
        $this->assertStringContainsString('window.print()', $v);
    }

    public function test_single_shift_report_supports_a4_and_80mm_prints(): void
    {
        $c = file_get_contents(app_path('Http/Controllers/PosShiftController.php'));
        $this->assertStringContainsString("pos_shift.report_print_a4", $c);
        $this->assertStringContainsString("pos_shift.report_print_80", $c);
        $this->assertStringContainsString("request()->input('print')", $c);

        $page = file_get_contents(resource_path('views/pos_shift/report.blade.php'));
        $this->assertStringContainsString("Print A4", $page);
        $this->assertStringContainsString("Print 80mm Slip", $page);
        $this->assertStringContainsString("'print' => 'a4'", $page);
        $this->assertStringContainsString("'print' => '80'", $page);
        $this->assertStringNotContainsString('onclick="window.print();"', $page);
    }

    public function test_single_shift_print_views_use_shift_metrics(): void
    {
        $a4 = file_get_contents(resource_path('views/pos_shift/report_print_a4.blade.php'))
            . file_get_contents(resource_path('views/pos_shift/partials/shift_report_a4_content.blade.php'));
        $this->assertStringContainsString('shift_report_a4_content', $a4);
        $this->assertStringContainsString('Cash reconciliation', $a4);
        $this->assertStringContainsString('productQtySummary', $a4);
        $this->assertStringContainsString('window.print()', $a4);

        $slip = file_get_contents(resource_path('views/pos_shift/report_print_80.blade.php'));
        $this->assertStringContainsString('width:72mm', $slip);
        $this->assertStringContainsString('@page { size: 80mm auto', $slip);
        $this->assertStringContainsString('SHIFT REPORT', $slip);
        $this->assertStringContainsString('productQtySummary', $slip);
        $this->assertStringContainsString('window.print()', $slip);
    }
}
