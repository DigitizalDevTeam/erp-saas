<?php

namespace App\Services;

use App\Models\ProductService;
use App\Models\warehouse;
use App\Models\WarehouseProduct;
use Illuminate\Support\Facades\DB;

/**
 * Full stock chart for one product, used by the Products list
 * "Stock Status" popup: the product itself, every assigned product
 * option and every recipe ingredient, with stock per warehouse.
 *
 * "Can sell" mirrors the restaurant POS picker exactly
 * (PosWarehouseAvailabilityService with no options selected).
 */
class ProductStockStatusService
{
    public function __construct(private PosWarehouseAvailabilityService $availability)
    {
    }

    public function chartFor(ProductService $product, int $creatorId): array
    {
        $warehouses = warehouse::where('created_by', $creatorId)
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn ($w) => ['id' => (int) $w->id, 'name' => (string) $w->name])
            ->values()
            ->all();
        $warehouseIds = array_column($warehouses, 'id');

        $optionItems = $product->optionItems()
            ->where('product_option_items.created_by', $creatorId)
            ->with('optionProduct')
            ->get();
        $recipes = $product->recipes()
            ->where('product_recipes.created_by', $creatorId)
            ->with('ingredientProduct')
            ->get();

        $relatedIds = [(int) $product->id];
        foreach ($optionItems as $item) {
            if ($item->optionProduct) {
                $relatedIds[] = (int) $item->optionProduct->id;
            }
        }
        foreach ($recipes as $recipe) {
            if ($recipe->ingredientProduct) {
                $relatedIds[] = (int) $recipe->ingredientProduct->id;
            }
        }
        $stock = $this->stockByWarehouse(array_values(array_unique($relatedIds)), $warehouseIds, $creatorId);

        $row = function (ProductService $item, ?float $perSale, ?string $group = null) use ($stock, $warehouseIds) {
            $perWarehouse = [];
            $total = 0.0;
            foreach ($warehouseIds as $warehouseId) {
                $qty = (float) ($stock[(int) $item->id][$warehouseId] ?? 0);
                $perWarehouse[$warehouseId] = $qty;
                $total += $qty;
            }

            return [
                'id' => (int) $item->id,
                'name' => (string) $item->name,
                'sku' => (string) ($item->sku ?? ''),
                'type' => (string) ($item->type ?? ''),
                'group' => $group,
                'per_sale' => $perSale,
                'affect_stock' => (bool) ($item->affect_stock ?? true),
                'low_stock_threshold' => $item->low_stock_threshold !== null ? (int) $item->low_stock_threshold : null,
                'stock' => $perWarehouse,
                'total' => $total,
            ];
        };

        $options = [];
        foreach ($optionItems as $item) {
            if ($item->optionProduct) {
                $options[] = $row($item->optionProduct, (float) $item->quantity, $item->option_group ?: null);
            }
        }

        $ingredients = [];
        foreach ($recipes as $recipe) {
            if ($recipe->ingredientProduct) {
                $ingredients[] = $row($recipe->ingredientProduct, (float) $recipe->quantity);
            }
        }

        $canSell = [];
        foreach ($warehouseIds as $warehouseId) {
            $canSell[$warehouseId] = $this->availability->maxSellableQty($product, $warehouseId, null, [], $creatorId);
        }

        return [
            'product' => [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'sku' => (string) ($product->sku ?? ''),
                'type' => (string) ($product->type ?? ''),
            ],
            'warehouses' => $warehouses,
            'product_stock' => $row($product, null),
            'options' => $options,
            'ingredients' => $ingredients,
            'can_sell' => $canSell,
            // maxSellableQty() sentinel for "nothing in this line consumes stock".
            'unlimited_value' => 999999,
        ];
    }

    /**
     * SUM(quantity) per product per warehouse — the same numbers the
     * warehouse stock report and the POS availability check read.
     *
     * @return array<int, array<int, float>>
     */
    private function stockByWarehouse(array $productIds, array $warehouseIds, int $creatorId): array
    {
        if ($productIds === [] || $warehouseIds === []) {
            return [];
        }

        $rows = WarehouseProduct::where('created_by', $creatorId)
            ->whereIn('product_id', $productIds)
            ->whereIn('warehouse_id', $warehouseIds)
            ->groupBy('product_id', 'warehouse_id')
            ->select('product_id', 'warehouse_id', DB::raw('SUM(quantity) as qty'))
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->product_id][(int) $row->warehouse_id] = (float) $row->qty;
        }

        return $map;
    }
}
