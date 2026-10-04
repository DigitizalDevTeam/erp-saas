<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * EOD report prints: professional A4 + 80mm slip designs (approved by Riz).
 * /report/pos-eod?print=a4 and ?print=80 render standalone print pages.
 */
class EodReportPrintDesignsTest extends TestCase
{
    public function test_controller_routes_print_formats(): void
    {
        $c = file_get_contents(app_path('Http/Controllers/ReportController.php'));
        $this->assertStringContainsString("'print' => 'a4'", str_replace('"', "'", $c));
        $this->assertStringContainsString("report.pos_eod_print_a4", $c);
        $this->assertStringContainsString("report.pos_eod_print_80", $c);
    }

    public function test_page_has_two_print_buttons_and_no_width_selector(): void
    {
        $v = file_get_contents(resource_path('views/report/pos_eod.blade.php'));
        $this->assertStringContainsString("'print' => 'a4'", $v);
        $this->assertStringContainsString("'print' => '80'", $v);
        $this->assertStringContainsString("Print A4", $v);
        $this->assertStringContainsString("Print 80mm Slip", $v);
        $this->assertStringNotContainsString('eod-print-width', $v);
    }

    public function test_a4_view_is_professional_design(): void
    {
        $v = file_get_contents(resource_path('views/report/pos_eod_print_a4.blade.php'))
            . file_get_contents(resource_path('views/report/partials/pos_eod_a4_content.blade.php'));
        foreach (['End of Day (EOD) Report', 'SALES BY PAYMENT METHOD', 'SALES PER EMPLOYEE', 'TAXES', 'SUMMARY OF ITEMS SOLD', 'Manager Signature', 'size: A4'] as $needle) {
            $this->assertStringContainsString($needle, $v, $needle);
        }
    }

    public function test_a4_view_is_plain_no_cards_no_colours(): void
    {
        $v = file_get_contents(resource_path('views/report/pos_eod_print_a4.blade.php'))
            . file_get_contents(resource_path('views/report/partials/pos_eod_a4_content.blade.php'))
            . file_get_contents(resource_path('views/report/partials/pos_eod_a4_styles.blade.php'));
        $this->assertStringNotContainsString('kpi', $v);
        $this->assertStringNotContainsString('#0f5132', $v);
        $this->assertStringNotContainsString('border-radius', $v);
    }

    public function test_on_screen_page_uses_same_a4_report_layout(): void
    {
        $page = file_get_contents(resource_path('views/report/pos_eod.blade.php'));
        $this->assertStringContainsString("@include('report.partials.pos_eod_a4_content')", $page);
        $this->assertStringNotContainsString('eod-printable', $page);
        $this->assertStringNotContainsString('Courier New', $page);
    }

    public function test_pdf_download_uses_same_a4_format(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));
        $this->assertStringContainsString('report.pos.eod.download', $routes);

        $service = file_get_contents(app_path('Services/PosEodReportService.php'));
        $this->assertStringContainsString("Pdf::loadView('report.pos_eod_print_a4'", $service);

        $page = file_get_contents(resource_path('views/report/pos_eod.blade.php'));
        $this->assertStringContainsString('Download PDF', $page);
    }

    public function test_80_view_is_slip_design(): void
    {
        $v = file_get_contents(resource_path('views/report/pos_eod_print_80.blade.php'));
        foreach (['END OF DAY REPORT', 'TOTAL SALES', 'SALES BY PAYMENT METHOD', 'END OF REPORT', 'size: 80mm'] as $needle) {
            $this->assertStringContainsString($needle, $v, $needle);
        }
    }
}
