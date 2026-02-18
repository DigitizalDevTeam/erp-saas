<?php

namespace App\Services;

use App\Models\ProductRecipe;
use App\Models\ProductService;
use App\Models\StockReport;
use App\Models\Utility;
use App\Models\WarehouseProduct;

/**
 * Phase 5: Deduct stock by recipe (BOM) when product is sold, or by product quantity if no recipe.
 */
class PosRecipeDeductionService
{
    /**
     * Deduct stock for a POS line: by recipe ingredients if product has recipe, else by product quantity.
     */
    public static function deductForSale(int $productId, float $quantity, int $warehouseId, int $creatorId, string $posIdFormatted, int $posId): void
    {
        $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
        if (!$product) {
            return;
        }

        $recipes = ProductRecipe::where('product_id', $productId)->where('created_by', $creatorId)->with('ingredientProduct')->get();
        if ($recipes->isNotEmpty()) {
            foreach ($recipes as $recipe) {
                $ingredientQty = $recipe->quantity * $quantity;
                if ($ingredientQty <= 0) {
                    continue;
                }
                $ingredient = $recipe->ingredientProduct;
                if (!$ingredient) {
                    continue;
                }
                self::deductProductQuantity($ingredient->id, $ingredientQty, $warehouseId, $creatorId, $posIdFormatted, $posId);
            }
        } else {
            self::deductProductQuantity($productId, $quantity, $warehouseId, $creatorId, $posIdFormatted, $posId);
        }
    }

    protected static function deductProductQuantity(int $productId, float $quantity, int $warehouseId, int $creatorId, string $posIdFormatted, int $posId): void
    {
        $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
        if (!$product) {
            return;
        }
        $original = (float) $product->quantity;
        $newQty = max(0, $original - $quantity);
        ProductService::where('id', $productId)->update(['quantity' => $newQty]);
        Utility::warehouse_quantity('minus', $quantity, $productId, $warehouseId);
        $description = $quantity . ' ' . __('quantity sold in pos') . ' ' . $posIdFormatted;
        Utility::addProductStock($productId, $quantity, 'pos', $description, $posId);
    }
}
