<?php

namespace Tests\Unit;

use App\Models\PosAgentPrinter;
use App\Models\PosPrintJob;
use App\Services\PosPrinterAgentService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

class PrinterSettingsTestPrintTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('pos_agent_printers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('created_by');
            $table->string('system_name');
            $table->string('display_name')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_print_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('created_by')->default(0);
            $table->unsignedBigInteger('pos_id')->nullable();
            $table->string('zone', 20);
            $table->string('system_printer_name');
            $table->string('title')->nullable();
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('printed_at')->nullable();
            $table->timestamps();
        });
    }

    private function makePrinter(int $branchId, int $creatorId, string $systemName): PosAgentPrinter
    {
        return PosAgentPrinter::create([
            'branch_id' => $branchId,
            'created_by' => $creatorId,
            'system_name' => $systemName,
            'display_name' => $systemName,
        ]);
    }

    public function test_kitchen_and_bar_test_prints_queue_slip_jobs(): void
    {
        $kitchen = $this->makePrinter(10, 1, 'cozinha');
        $bar = $this->makePrinter(10, 1, 'GP-80160(Cut) Series');

        $job = PosPrinterAgentService::enqueueTestPrint(10, 1, 'kitchen', $kitchen->id, 'Riz');
        $this->assertNotNull($job);
        $this->assertSame('kitchen', $job->zone);
        $this->assertSame('cozinha', $job->system_printer_name);
        $this->assertSame('pending', $job->status);
        $this->assertNull($job->pos_id);
        $this->assertSame('Test Print', $job->title);
        $this->assertSame('TEST PRINT', $job->payload['rows'][0]['name']);
        $this->assertSame(1, $job->payload['rows'][0]['qty']);
        $this->assertStringContainsString('Kitchen Printer', $job->payload['rows'][0]['note']);

        $barJob = PosPrinterAgentService::enqueueTestPrint(10, 1, 'bar', $bar->id);
        $this->assertSame('bar', $barJob->zone);
        $this->assertSame('GP-80160(Cut) Series', $barJob->system_printer_name);
    }

    public function test_receipt_test_print_uses_native_render(): void
    {
        $default = $this->makePrinter(10, 1, 'XP-80 (copy 1)');

        $job = PosPrinterAgentService::enqueueTestPrint(10, 1, 'receipt', $default->id);

        $this->assertNotNull($job);
        $this->assertSame('receipt', $job->zone);
        $this->assertSame('native', $job->payload['render']);
        $this->assertSame('TEST PRINT', $job->payload['header']['document_title']);
    }

    public function test_rejects_unknown_zone_and_foreign_printer(): void
    {
        $printer = $this->makePrinter(10, 1, 'cozinha');

        $this->assertNull(PosPrinterAgentService::enqueueTestPrint(10, 1, 'unknown', $printer->id));
        $this->assertNull(PosPrinterAgentService::enqueueTestPrint(11, 1, 'kitchen', $printer->id));
        $this->assertNull(PosPrinterAgentService::enqueueTestPrint(10, 2, 'kitchen', $printer->id));
        $this->assertNull(PosPrinterAgentService::enqueueTestPrint(10, 1, 'kitchen', 9999));
        $this->assertSame(0, PosPrintJob::count());
    }

    public function test_route_and_modal_wire_the_buttons(): void
    {
        $this->assertTrue(Route::has('pos.restaurant.test-print'));

        $modal = file_get_contents(base_path('resources/views/partials/printer-settings-modal.blade.php'));
        $this->assertStringContainsString('printer-settings-test-btn', $modal);
        $this->assertStringContainsString('data-zone="kitchen"', $modal);
        $this->assertStringContainsString('data-zone="bar"', $modal);
        $this->assertStringContainsString('data-zone="receipt"', $modal);
        $this->assertStringContainsString('route("pos.restaurant.test-print")', $modal);

        $controller = file_get_contents(app_path('Http/Controllers/PosPrinterAgentController.php'));
        $this->assertStringContainsString('function testPrint', $controller);
        $this->assertStringContainsString('enqueueTestPrint', $controller);
    }
}
