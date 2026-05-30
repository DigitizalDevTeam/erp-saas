<?php

namespace App\Services;

use App\Models\ProductRecipe;
use App\Models\ProductService;
use App\Models\StockReport;
use App\Models\Utility;
use App\Models\WarehouseProduct;

/**
 * Phase 5: Deduct stock by recipe (BOM) when product is sold, or by product quantity if no recipe.
 * Products with "does not affect stock" (e.g. composite dishes): only BOM ingredients are deducted.
 */
class PosRecipeDeductionService
{
    /**
     * Deduct stock for a POS line:
     * - If product has recipe (BOM): deduct only ingredients (product's own quantity is not touched).
     * - Else if product affects stock: deduct product quantity.
     * - Else (does not affect stock, no recipe): deduct nothing.
     */
    public static function deductForSale(int $productId, float $quantity, int $warehouseId, int $creatorId, string $posIdFormatted, int $posId, ?string $identifiersNote = null, ?int $productVariantId = null): void
    {
        $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
        if (!$product) {
            return;
        }

        $recipes = ProductRecipe::where('product_id', $productId)->where('created_by', $creatorId)->with('ingredientProduct')->get();
        if ($recipes->isNotEmpty()) {
            // Composite dish: deduct only ingredients; product's own stock is not affected
            foreach ($recipes as $recipe) {
                $ingredientQty = $recipe->quantity * $quantity;
                if ($ingredientQty <= 0) {
                    continue;
                }
                $ingredient = $recipe->ingredientProduct;
                if (! $ingredient || ($ingredient->type ?? '') !== 'ingredient') {
                    continue;
                }
                self::deductProductQuantity($ingredient->id, $ingredientQty, $warehouseId, $creatorId, $posIdFormatted, $posId, '', null);
            }
        } elseif ($product->affect_stock ?? true) {
            // Normal product: deduct its own quantity
            self::deductProductQuantity($productId, $quantity, $warehouseId, $creatorId, $posIdFormatted, $posId, $identifiersNote ?? '', $productVariantId);
        }
        // Else: does not affect stock and no recipe → nothing to deduct
    }

    protected static function deductProductQuantity(int $productId, float $quantity, int $warehouseId, int $creatorId, string $posIdFormatted, int $posId, string $extraDescriptionSuffix = '', ?int $productVariantId = null): void
    {
        $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
        if (!$product) {
            return;
        }
        $original = (float) $product->quantity;
        $newQty = max(0, $original - $quantity);
        ProductService::where('id', $productId)->update(['quantity' => $newQty]);
        Utility::warehouse_quantity('minus', $quantity, $productId, $warehouseId, $productVariantId);
        $description = $quantity . ' ' . __('quantity sold in pos') . ' ' . $posIdFormatted . $extraDescriptionSuffix;
        Utility::addProductStock($productId, $quantity, 'pos', $description, $posId);
    }
}
