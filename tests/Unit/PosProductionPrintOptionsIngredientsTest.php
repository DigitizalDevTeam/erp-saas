<?php

namespace Tests\Unit;

use App\Services\PosPrintRoutingService;
use Tests\TestCase;

class PosProductionPrintOptionsIngredientsTest extends TestCase
{
    public function test_production_print_jobs_show_all_options_and_ingredients_with_qty(): void
    {
        $jobs = PosPrintRoutingService::buildProductionPrintJobs(1, 1, [
            [
                'name' => 'Burger',
                'qty' => 2,
                'note' => '',
                'print_zone' => 'kitchen',
                'pos_printer_id' => null,
                'options' => [
                    ['name' => 'Extra Cheese', 'quantity' => 1],
                    ['name' => 'Bacon', 'quantity' => 2],
                    ['name' => 'Egg', 'quantity' => 1],
                ],
                'ingredients' => [
                    ['name' => 'Cheese', 'qty' => 2, 'unit' => 'slice'],
                    ['name' => 'Bun', 'qty' => 2, 'unit' => 'pcs'],
                    ['name' => 'Sauce', 'qty' => 0.5, 'unit' => 'cup'],
                ],
            ],
        ]);

        $this->assertCount(1, $jobs);
        $row = $jobs[0]['rows'][0];
        $this->assertSame('Burger', $row['name']);
        $this->assertSame([
            'Extra Cheese ×1',
            'Bacon ×2',
            'Egg ×1',
        ], $row['options']);
        $this->assertSame([
            'Cheese ×2 slice',
            'Bun ×2 pcs',
            'Sauce ×0.5 cup',
        ], $row['ingredients']);
    }

    public function test_production_details_include_option_recipe_ingredients(): void
    {
        $service = file_get_contents(app_path('Services/PosPrinterAgentService.php'));
        $this->assertStringContainsString('recipesByProduct', $service);
        $this->assertStringContainsString('Selected options are products too', $service);
        $this->assertStringContainsString('selectedQtyByOption', $service);
        $this->assertStringNotContainsString('$rowName .= "\n" . implode("\n", $optionLines);', $service);

        $options = file_get_contents(app_path('Services/ProductOptionService.php'));
        $this->assertStringContainsString('Always show the qty (including 1)', $options);
    }

    public function test_browser_slip_groups_options_and_ingredients(): void
    {
        $index = file_get_contents(resource_path('views/pos/index-restaurant.blade.php'));
        $this->assertStringContainsString('pos.production_options', $index);
        $this->assertStringContainsString('pos.production_ingredients', $index);
        $this->assertStringContainsString('appendSlipLabels(r.options', $index);
        $this->assertStringContainsString('appendSlipLabels(r.ingredients', $index);

        $en = file_get_contents(resource_path('lang/en/pos.php'));
        $pt = file_get_contents(resource_path('lang/pt/pos.php'));
        $this->assertStringContainsString("'production_options' => 'Options'", $en);
        $this->assertStringContainsString("'production_ingredients' => 'Ingredients'", $en);
        $this->assertStringContainsString("'production_options' => 'Opções'", $pt);
        $this->assertStringContainsString("'production_ingredients' => 'Ingredientes'", $pt);
    }

    public function test_kitchen_receipt_prints_line_note_bold(): void
    {
        // Server print jobs must carry the per-line note through to the slip.
        $jobs = PosPrintRoutingService::buildProductionPrintJobs(1, 1, [
            [
                'name' => 'Burger',
                'qty' => 1,
                'note' => 'No onion, extra spicy',
                'print_zone' => 'kitchen',
                'pos_printer_id' => null,
                'options' => [],
                'ingredients' => [],
            ],
        ]);
        $this->assertSame('No onion, extra spicy', $jobs[0]['rows'][0]['note']);

        // Browser kitchen slip (main POS page) prints the note in bold, in both
        // the server-jobs path and the unsent-rows fallback path.
        $index = file_get_contents(resource_path('views/pos/index.blade.php'));
        $this->assertStringContainsString('.note-bold{font-weight:bold;', $index);
        $this->assertStringContainsString('<span class="note-bold">', $index);
        $this->assertStringContainsString('<td class="note-bold">', $index);

        // Browser kitchen slip (restaurant POS page) also prints it bold.
        $restaurant = file_get_contents(resource_path('views/pos/index-restaurant.blade.php'));
        $this->assertStringContainsString('.note-bold{font-weight:bold;', $restaurant);
        $this->assertStringContainsString('<span class="note-bold">', $restaurant);

        // Print-agent kitchen/bar slip prints the note bold as well.
        $agent = file_get_contents(base_path('printer-agent/SlipPrinter.cs'));
        $this->assertStringContainsString('AddKv(lines, "Notes", note.Trim(), bold: true);', $agent);
    }
}
