<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Print Agent device-code pairing was removed from the Windows agent:
 * the agent now uses only ERP Base URL + Branch dropdown. The server-side
 * pairing table/endpoints remain only as dormant legacy compatibility.
 * Agent also supports start-with-Windows and print-as-image debug (C# side).
 */
class PrintAgentPairingTest extends TestCase
{
    public function test_pairing_table_and_model_exist(): void
    {
        $m = file_get_contents(database_path('migrations/2026_10_04_180000_create_pos_printer_agent_pairings_table.php'));
        $this->assertStringContainsString('pos_printer_agent_pairings', $m);
        $this->assertStringContainsString('device_code', $m);

        $model = file_get_contents(app_path('Models/PosPrinterAgentPairing.php'));
        $this->assertStringContainsString('function isPaired', $model);
    }

    public function test_api_pair_status_endpoint(): void
    {
        $routes = file_get_contents(base_path('routes/api.php'));
        $this->assertStringContainsString('printer-agent/pair-status', $routes);

        $api = file_get_contents(app_path('Http/Controllers/PosPrinterAgentApiController.php'));
        $this->assertStringContainsString('function pairStatus', $api);
        $this->assertStringContainsString('PosPrinterAgentPairing::firstOrCreate', $api);
    }

    public function test_web_pair_endpoint_requires_manage_printers(): void
    {
        $routes = file_get_contents(base_path('routes/web.php'));
        $this->assertStringContainsString('pair-agent', $routes);

        $ctl = file_get_contents(app_path('Http/Controllers/PosPrinterAgentController.php'));
        $this->assertStringContainsString('function pairAgent', $ctl);
        $this->assertStringContainsString('canManagePrinters()', $ctl);
        $this->assertStringContainsString('canAccessBranchForPos', $ctl);
    }

    public function test_agent_source_has_no_device_code_pairing(): void
    {
        $main = file_get_contents(base_path('printer-agent/MainForm.cs'));
        $this->assertStringNotContainsString('FetchPairStatusAsync', $main);
        $this->assertStringNotContainsString('DeviceCode', $main);
        $this->assertStringNotContainsString('device code', strtolower($main));
        $this->assertStringNotContainsString('PairTimer', $main);
        $this->assertStringContainsString('Start with Windows', $main);
        $this->assertStringContainsString('Print as image (debug)', $main);
        $this->assertStringContainsString('_cboBranch', $main);

        $config = file_get_contents(base_path('printer-agent/AgentConfig.cs'));
        $this->assertStringNotContainsString('EnsuredDeviceCode', $config);
        $this->assertStringNotContainsString('DeviceCode', $config);
        $this->assertStringNotContainsString('BranchManuallySelected', $config);
        $this->assertStringContainsString('DebugImagesFolder', $config);

        $api = file_get_contents(base_path('printer-agent/ApiClient.cs'));
        $this->assertStringNotContainsString('FetchPairStatusAsync', $api);
        $this->assertStringNotContainsString('PairStatusDto', $api);
        $this->assertStringNotContainsString('pair-status', $api);

        $printer = file_get_contents(base_path('printer-agent/SlipPrinter.cs'));
        $this->assertStringContainsString('SaveJobAsImage', $printer);

        $program = file_get_contents(base_path('printer-agent/Program.cs'));
        $this->assertStringContainsString('--tray', $program);
    }

    public function test_agent_branch_dropdown_and_automatic_logo(): void
    {
        $main = file_get_contents(base_path('printer-agent/MainForm.cs'));
        $this->assertStringContainsString('private readonly ComboBox _cboBranch', $main);
        $this->assertStringContainsString('FetchBranchesAsync', $main);
        $this->assertStringContainsString('OnBranchSelectionChangedAsync', $main);
        $this->assertStringContainsString('DownloadLogoIfChangedAsync', $main);
        $this->assertStringContainsString('DeleteSlipLogo', $main);
        $this->assertStringNotContainsString('Upload Logo', $main);
        $this->assertStringNotContainsString('OpenFileDialog', $main);
        $this->assertStringNotContainsString('SaveSlipLogoFromFile', $main);

        $config = file_get_contents(base_path('printer-agent/AgentConfig.cs'));
        $this->assertStringNotContainsString('BranchManuallySelected', $config);

        $api = file_get_contents(base_path('printer-agent/ApiClient.cs'));
        $this->assertStringContainsString('/printer-agent/branches', $api);
        $this->assertStringContainsString('/printer-agent/logo?branch_id=', $api);
    }
}
