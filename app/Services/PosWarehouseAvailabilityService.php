<?php

namespace App\Services;

use App\Models\ProductRecipe;
use App\Models\ProductService;
use App\Models\WarehouseProduct;

/**
 * Whether a restaurant POS line can be sold from one warehouse:
 * product/variant stock, selected options that affect stock, and recipe ingredients.
 */
class PosWarehouseAvailabilityService
{
    /**
     * Largest line quantity this warehouse can cover. 999999 when nothing in the line consumes stock.
     */
    public function maxSellableQty(ProductService $product, int $warehouseId, ?int $variantId, array $selectedOptions, int $creatorId): int
    {
        if ($warehouseId <= 0) {
            return 0;
        }

        $recipes = ProductRecipe::where('product_id', $product->id)
            ->where('created_by', $creatorId)
            ->with('ingredientProduct')
            ->get();

        $optionsSelected = $selectedOptions !== [];
        $affectStock = ($product->type === 'kit') ? false : (bool) ($product->affect_stock ?? true);
        if (($product->options_enabled ?? false) && $optionsSelected) {
            $affectStock = false;
        }

        $limits = [];

        if ($optionsSelected) {
            foreach ($selectedOptions as $optRow) {
                $opt = ProductService::where('id', (int) ($optRow['product_id'] ?? 0))
                    ->where('created_by', $creatorId)
                    ->first();
                if (! $opt || ! ($opt->affect_stock ?? true)) {
                    continue;
                }
                $need = (float) ($optRow['quantity'] ?? 1);
                if ($need <= 0) {
                    continue;
                }
                $have = $this->qtyInWarehouse((int) $opt->id, $warehouseId, null, $creatorId);
                if ($have < $need) {
                    return 0;
                }
            }
        } elseif ($affectStock) {
            if ($product->variants_enabled ?? false) {
                $limits[] = (int) floor($product->variantStockInWarehouse($warehouseId, $variantId));
            } else {
                $limits[] = $this->qtyInWarehouse((int) $product->id, $warehouseId, $variantId, $creatorId);
            }
        }

        foreach ($recipes as $recipe) {
            $ingredient = $recipe->ingredientProduct;
            if (! $ingredient || ($ingredient->type ?? '') !== 'ingredient') {
                continue;
            }
            $per = (float) $recipe->quantity;
            if ($per <= 0) {
                continue;
            }
            $have = $this->qtyInWarehouse((int) $ingredient->id, $warehouseId, null, $creatorId);
            $limits[] = (int) floor($have / $per);
        }

        if ($limits === []) {
            return 999999;
        }

        return max(0, min($limits));
    }

    protected function qtyInWarehouse(int $productId, int $warehouseId, ?int $variantId, int $creatorId): int
    {
        if ($warehouseId <= 0 || $productId <= 0) {
            return 0;
        }

        $query = WarehouseProduct::where('created_by', $creatorId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId);

        if ($variantId !== null && $variantId > 0) {
            $query->where('product_variant_id', $variantId);
        }

        return (int) floor((float) $query->sum('quantity'));
    }
}
