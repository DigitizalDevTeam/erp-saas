<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Printer settings are global (header), not POS-only:
 * - old "change pos print settings" permission replaced by "manage printers"
 * - header printer button + settings modal only for permission holders
 * - POS screen no longer carries the printer button or settings modal
 */
class GlobalPrinterSettingsPermissionTest extends TestCase
{
    public function test_permission_replaced_everywhere(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_10_04_170000_replace_change_pos_print_settings_with_manage_printers.php'));
        $this->assertStringContainsString("'manage printers'", $migration);
        $this->assertStringContainsString("'change pos print settings'", $migration);

        $user = file_get_contents(app_path('Models/User.php'));
        $this->assertStringContainsString("function canManagePrinters", $user);
        $this->assertStringContainsString("can('manage printers')", $user);
        $this->assertStringNotContainsString('canChangePosPrintSettings', $user);

        $catalog = file_get_contents(app_path('Services/RolePermissionUiCatalog.php'));
        $this->assertStringContainsString("'manage printers' => 'Manage Printers'", $catalog);
        $this->assertStringNotContainsString('change pos print settings', $catalog);
    }

    public function test_company_and_administrador_bypass_permission(): void
    {
        $user = file_get_contents(app_path('Models/User.php'));
        // Company accounts and the Administrador role always manage printers,
        // even without the "manage printers" permission (Riz instruction).
        $this->assertStringContainsString("\$this->type === 'company'", $user);
        $this->assertStringContainsString("hasRole('Administrador')", $user);
    }

    public function test_printer_settings_ajax_sends_csrf_header_and_never_fails_silently(): void
    {
        $modal = file_get_contents(resource_path('views/partials/printer-settings-modal.blade.php'));
        // CSRF goes in the X-CSRF-TOKEN header (read live from the page meta
        // tag, same as the other POS AJAX calls), not only baked into the body.
        $this->assertStringContainsString('printerSettingsCsrf', $modal);
        $this->assertStringContainsString("'X-CSRF-TOKEN': printerSettingsCsrf()", $modal);
        // A stale-token 419 gets a clear "refresh the page" message, and toast
        // failures fall back to alert() so a save click is never silent.
        $this->assertStringContainsString('xhr.status === 419', $modal);
        $this->assertStringContainsString('Session expired. Please refresh the page and try again.', $modal);
        $this->assertStringContainsString('alert(message)', $modal);
        // The script block must parse in the browser: a broken literal token
        // field here once killed every click handler in this modal.
        $this->assertStringNotContainsString('<redacted>', $modal);
        // Handlers bind on DOMContentLoaded: admin pages load jQuery in the
        // footer after this partial, so immediate execution never binds there.
        $this->assertStringContainsString("document.addEventListener('DOMContentLoaded'", $modal);
    }

    public function test_header_button_and_global_modal_are_permission_gated(): void
    {
        $header = file_get_contents(resource_path('views/partials/admin/header.blade.php'));
        $this->assertStringContainsString('id="global-printer-settings-btn"', $header);
        $this->assertStringContainsString('canManagePrinters()', $header);

        $layout = file_get_contents(resource_path('views/layouts/admin.blade.php'));
        $this->assertStringContainsString("partials.printer-settings-modal", $layout);

        $modal = file_get_contents(resource_path('views/partials/printer-settings-modal.blade.php'));
        $this->assertStringContainsString('id="printerSettingsModal"', $modal);
        $this->assertStringContainsString('id="printer-settings-branch"', $modal);
        $this->assertStringContainsString('id="printer-settings-save-image"', $modal);
        $this->assertStringNotContainsString('printer-settings-pair-code', $modal);
        $this->assertStringNotContainsString('printer-settings-pair-btn', $modal);
        $this->assertStringNotContainsString('Pair Print Agent', $modal);
    }

    public function test_pos_screens_no_longer_carry_printer_settings(): void
    {
        $restaurant = file_get_contents(resource_path('views/pos/restaurant.blade.php'));
        $this->assertStringNotContainsString('pos-printer-config-btn', $restaurant);
        $this->assertStringNotContainsString('posPrinterConfigModal', $restaurant);

        $index = file_get_contents(resource_path('views/pos/index-restaurant.blade.php'));
        $this->assertStringNotContainsString('posLoadPrinterConfigModal', $index);
        $this->assertStringNotContainsString('pos-printer-config-btn', $index);
    }

    public function test_restaurant_pos_header_has_printer_settings_button(): void
    {
        $index = file_get_contents(resource_path('views/pos/index-restaurant.blade.php'));
        $this->assertStringContainsString('id="global-printer-settings-btn"', $index);
        $this->assertStringContainsString('canChangePosPrintSettings', $index);
        $this->assertStringContainsString('partials.printer-settings-modal', $index);
        $this->assertStringContainsString('posPrinterBranches', $index);

        $modal = file_get_contents(resource_path('views/partials/printer-settings-modal.blade.php'));
        $this->assertStringContainsString('posPrinterBranches', $modal);
        $this->assertStringContainsString('#pos-restaurant-branch', $modal);
    }

    public function test_agent_endpoints_require_manage_printers(): void
    {
        $ctl = file_get_contents(app_path('Http/Controllers/PosPrinterAgentController.php'));
        $this->assertStringContainsString('canManagePrinters()', $ctl);
        $this->assertStringNotContainsString('canChangePosPrintSettings', $ctl);
    }
}
