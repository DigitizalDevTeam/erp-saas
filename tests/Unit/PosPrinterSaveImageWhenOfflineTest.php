<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * "Auto save as image when printer not connected" branch option:
 * with the option ON and the Print Agent offline, bill/receipt prints
 * are stored as PNG images instead of going to a dead printer queue.
 */
class PosPrinterSaveImageWhenOfflineTest extends TestCase
{
    public function test_config_column_and_model_are_wired(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_10_04_160000_add_save_image_when_offline_to_pos_branch_print_configs.php'));
        $this->assertStringContainsString("'save_image_when_offline'", $migration);

        $model = file_get_contents(app_path('Models/PosBranchPrintConfig.php'));
        $this->assertStringContainsString("'save_image_when_offline'", $model);
    }

    public function test_service_exposes_offline_image_save(): void
    {
        $svc = file_get_contents(app_path('Services/PosPrinterAgentService.php'));
        $this->assertStringContainsString('function saveImageWhenOfflineEnabled', $svc);
        $this->assertStringContainsString('function printerAgentOffline', $svc);
        $this->assertStringContainsString('function saveReceiptImageForPos', $svc);
        $this->assertStringContainsString('uploads/pos-receipt-images', $svc);
    }

    public function test_manual_print_returns_saved_image_when_offline(): void
    {
        $ctl = file_get_contents(app_path('Http/Controllers/PosPrinterAgentController.php'));
        $this->assertStringContainsString('saveImageWhenOfflineEnabled($config)', $ctl);
        $this->assertStringContainsString("'saved_as_image' => true", $ctl);
        $this->assertStringContainsString('"image_url"', $ctl);
    }

    public function test_settings_ui_has_the_option_switch(): void
    {
        $modal = file_get_contents(resource_path('views/pos/restaurant.blade.php'));
        $this->assertStringContainsString('id="pos-save-image-when-offline"', $modal);
        $this->assertStringContainsString("__('pos.save_image_when_offline')", $modal);

        $js = file_get_contents(resource_path('views/pos/index-restaurant.blade.php'));
        $this->assertStringContainsString('save_image_when_offline:', $js);
        $this->assertStringContainsString('config.save_image_when_offline', $js);
    }

    public function test_frontend_opens_saved_image(): void
    {
        foreach (['views/pos/printview.blade.php', 'views/pos/receipts/_print_footer.blade.php'] as $f) {
            $src = file_get_contents(resource_path($f));
            $this->assertStringContainsString('res.saved_as_image && res.image_url', $src);
        }
    }
}
