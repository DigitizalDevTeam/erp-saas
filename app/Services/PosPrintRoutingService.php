<?php

namespace App\Services;

use App\Models\PosPrinter;
use App\Models\PosProduct;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;

class PosPrintRoutingService
{
  /**
   * Bar-oriented category name keywords (used when no explicit print_zone is set).
   *
   * @var array<int, string>
   */
    private const BAR_CATEGORY_KEYWORDS = [
        'drink', 'drinks', 'beverage', 'beverages', 'bar', 'cocktail', 'beer', 'wine',
        'spirit', 'liquor', 'alcohol', 'soft drink', 'soda', 'juice', 'mocktail', 'coffee', 'tea',
        // Portuguese (and common menu labels)
        'cerveja', 'cervejas', 'bebida', 'bebidas', 'bebida loca', 'bebidas quentes',
        'cocktail list', 'espirituosa', 'espirituosas', 'espumante', 'espumantes',
        'digestivo', 'digestivos', 'agua', 'aguas', 'águas', 'vinho', 'vinhos',
        'sumo', 'sumos', 'refresco', 'refrescos', 'whisky', 'vodka', 'gin', 'rum',
    ];

    public static function normalizePrintZone(?string $zone): string
    {
        $zone = strtolower(trim((string) $zone));
        if ($zone === PosProduct::PRINT_ZONE_BAR) {
            return PosProduct::PRINT_ZONE_BAR;
        }
        if ($zone === PosProduct::PRINT_ZONE_BOTH) {
            return PosProduct::PRINT_ZONE_BOTH;
        }

        return PosProduct::PRINT_ZONE_KITCHEN;
    }

    public static function resolvePrintZoneForProduct(ProductService $product): string
    {
        if (! empty($product->print_zone)) {
            return self::normalizePrintZone($product->print_zone);
        }

        $category = $product->relationLoaded('category')
            ? $product->category
            : ($product->category_id ? ProductServiceCategory::find($product->category_id) : null);

        $guard = 0;
        while ($category && $guard++ < 32) {
            if (! empty($category->print_zone)) {
                return self::normalizePrintZone($category->print_zone);
            }
            $parentId = $category->parent_id ? (int) $category->parent_id : 0;
            if ($parentId <= 0) {
                break;
            }
            $category = ProductServiceCategory::find($parentId);
        }

        $categoryName = mb_strtolower((string) ($product->category->name ?? ''), 'UTF-8');
        if ($categoryName !== '' && self::categoryNameSuggestsBar($categoryName)) {
            return PosProduct::PRINT_ZONE_BAR;
        }

        return PosProduct::PRINT_ZONE_KITCHEN;
    }

    public static function resolvePosPrinterIdForProduct(ProductService $product): ?int
    {
        if (! empty($product->pos_printer_id)) {
            return (int) $product->pos_printer_id;
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    public static function buildProductionPrintJobs(int $branchId, int $creatorId, array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        $printers = PosPrinter::where('branch_id', $branchId)
            ->where('created_by', $creatorId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $printersById = $printers->keyBy('id');
        $defaultPrinterByType = [];
        foreach ($printers as $printer) {
            $type = (string) ($printer->type ?? PosPrinter::TYPE_KITCHEN);
            if (! isset($defaultPrinterByType[$type])) {
                $defaultPrinterByType[$type] = $printer;
            }
        }

        $jobs = [];

        foreach ($lines as $line) {
            $optionLabels = [];
            foreach (is_array($line['options'] ?? null) ? $line['options'] : [] as $opt) {
                if (! is_array($opt)) {
                    continue;
                }
                $optName = trim((string) ($opt['name'] ?? ''));
                if ($optName === '') {
                    continue;
                }
                $optQty = (float) ($opt['quantity'] ?? 1);
                if ($optQty > 0 && abs($optQty - 1) > 0.0001) {
                    $optName .= ' ×' . rtrim(rtrim(number_format($optQty, 4, '.', ''), '0'), '.');
                }
                $optionLabels[] = $optName;
            }
            $ingredientLabels = [];
            foreach (is_array($line['ingredients'] ?? null) ? $line['ingredients'] : [] as $ing) {
                if (! is_array($ing)) {
                    continue;
                }
                $ingName = trim((string) ($ing['name'] ?? ''));
                if ($ingName === '') {
                    continue;
                }
                $ingQty = (float) ($ing['qty'] ?? 0);
                $ingUnit = trim((string) ($ing['unit'] ?? ''));
                if ($ingQty > 0) {
                    $ingName .= ' ×' . rtrim(rtrim(number_format($ingQty, 4, '.', ''), '0'), '.');
                    if ($ingUnit !== '') {
                        $ingName .= ' ' . $ingUnit;
                    }
                }
                $ingredientLabels[] = $ingName;
            }
            $row = [
                'name' => (string) ($line['name'] ?? ''),
                'qty' => (int) ($line['qty'] ?? 0),
                'note' => (string) ($line['note'] ?? ''),
                'options' => $optionLabels,
                'ingredients' => $ingredientLabels,
            ];

            $printerId = isset($line['pos_printer_id']) ? (int) $line['pos_printer_id'] : 0;
            if ($printerId > 0 && $printersById->has($printerId)) {
                self::appendPrintJobRow($jobs, 'printer_' . $printerId, $printersById->get($printerId), $row);
                continue;
            }

            $zone = self::normalizePrintZone($line['print_zone'] ?? PosProduct::PRINT_ZONE_KITCHEN);
            $targets = $zone === PosProduct::PRINT_ZONE_BOTH
                ? [PosPrinter::TYPE_KITCHEN, PosPrinter::TYPE_BAR]
                : [$zone === PosProduct::PRINT_ZONE_BAR ? PosPrinter::TYPE_BAR : PosPrinter::TYPE_KITCHEN];

            foreach ($targets as $targetType) {
                $printer = $defaultPrinterByType[$targetType] ?? null;
                $jobKey = $printer ? 'printer_' . $printer->id : 'zone_' . $targetType;
                self::appendPrintJobRow($jobs, $jobKey, $printer, $row, $targetType);
            }
        }

        return array_values(array_filter($jobs, static fn (array $job) => ! empty($job['rows'])));
    }

    /**
     * @param  array<string, array<string, mixed>>  $jobs
     * @param  array{name: string, qty: int, note: string}  $row
     */
    private static function appendPrintJobRow(array &$jobs, string $jobKey, ?PosPrinter $printer, array $row, ?string $zoneType = null): void
    {
        if (! isset($jobs[$jobKey])) {
            $title = $printer
                ? $printer->name
                : ($zoneType === PosPrinter::TYPE_BAR ? __('pos.bar_slip') : __('pos.kitchen_slip'));

            $jobs[$jobKey] = [
                'key' => $jobKey,
                'title' => $title,
                'printer_id' => $printer ? (int) $printer->id : null,
                'printer_type' => $printer ? ($printer->type ?? null) : $zoneType,
                'rows' => [],
            ];
        }

        $jobs[$jobKey]['rows'][] = $row;
    }

    private static function categoryNameSuggestsBar(string $categoryName): bool
    {
        foreach (self::BAR_CATEGORY_KEYWORDS as $keyword) {
            if (str_contains($categoryName, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
