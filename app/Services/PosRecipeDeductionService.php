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
     *
     * Kitchen Production Phase 2: a prepared product (an approved
     * kitchen recipe makes it) is sold from its batches only — the raw
     * ingredients were already deducted at production and must never be
     * deducted again. And a recipe line whose ingredient is itself a
     * prepared product also consumes that ingredient's batches, so the
     * batch record and the warehouse stock stay reconciled.
     *
     * @param array<int, array{product_id: int, quantity: float}> $selectedOptions normalized selected options of the line (for the ingredient swap)
     */
    public static function deductForSale(int $productId, float $quantity, int $warehouseId, int $creatorId, string $posIdFormatted, int $posId, ?string $identifiersNote = null, ?int $productVariantId = null, bool $allowNegative = false, array $selectedOptions = [], ?\App\Models\PosProduct $line = null): void
    {
        $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
        if (!$product) {
            return;
        }

        // Prepared product: batch only. When the batches cover the sale
        // this returns having deducted nothing raw; when they do not
        // cover it, fall through to the legacy raw deduction (the
        // send-time shortage check is the enforcement point — a
        // payment-time deduction must not fail the sale).
        $kitchen = app(\App\Services\KitchenPosSaleService::class);
        if ($kitchen->deductPreparedSale($productId, $quantity, $warehouseId, $creatorId, $posIdFormatted, $posId, $line, $allowNegative)) {
            return;
        }

        $recipes = ProductRecipe::where('product_id', $productId)->where('created_by', $creatorId)->with('ingredientProduct')->get();
        if ($recipes->isNotEmpty()) {
            // An option that consumes a defined alternative of a recipe
            // line swaps that line: the main ingredient is not deducted
            // (the alternative already came through the option).
            $swapped = $kitchen->swappedRecipeLineIds($productId, $selectedOptions, $quantity, $creatorId);

            // Composite dish: deduct only ingredients; product's own stock is not affected
            foreach ($recipes as $recipe) {
                if (isset($swapped[(int) $recipe->id])) {
                    continue;
                }
                $ingredientQty = $recipe->quantity * $quantity;
                if ($ingredientQty <= 0) {
                    continue;
                }
                $ingredient = $recipe->ingredientProduct;
                if (! $ingredient || ($ingredient->type ?? '') !== 'ingredient') {
                    continue;
                }
                // No-affect ingredients move no stock, mirroring the
                // availability check and the option deduction path.
                if (! ($ingredient->affect_stock ?? true)) {
                    continue;
                }
                self::deductProductQuantity($ingredient->id, $ingredientQty, $warehouseId, $creatorId, $posIdFormatted, $posId, '', null, $allowNegative);
                // The ingredient is itself a prepared product: the batch
                // it came from must fall by the same amount.
                $kitchen->consumePreparedIngredient($recipe, $ingredientQty, $warehouseId, $creatorId, $line);
            }
        } elseif ($product->affect_stock ?? true) {
            // Normal product: deduct its own quantity
            self::deductProductQuantity($productId, $quantity, $warehouseId, $creatorId, $posIdFormatted, $posId, $identifiersNote ?? '', $productVariantId, $allowNegative);
        }
        // Else: does not affect stock and no recipe → nothing to deduct
    }

    protected static function deductProductQuantity(int $productId, float $quantity, int $warehouseId, int $creatorId, string $posIdFormatted, int $posId, string $extraDescriptionSuffix = '', ?int $productVariantId = null, bool $allowNegative = false): void
    {
        $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
        if (!$product) {
            return;
        }
        $original = (float) $product->quantity;
        $newQty = ($allowNegative || $product->allowsNegativeStock())
            ? $original - $quantity
            : max(0, $original - $quantity);
        ProductService::where('id', $productId)->update(['quantity' => $newQty]);
        Utility::warehouse_quantity('minus', $quantity, $productId, $warehouseId, $productVariantId, $allowNegative);
        $description = $quantity . ' ' . __('quantity sold in pos') . ' ' . $posIdFormatted . $extraDescriptionSuffix;
        Utility::addProductStock($productId, $quantity, 'pos', $description, $posId);
    }

    /**
     * Deduct a substituted ingredient at cooking time: same mechanics as a
     * normal recipe deduction, only the stock-report note names the main
     * ingredient it replaced.
     */
    public static function deductForSubstitution(int $alternativeId, float $quantity, int $warehouseId, int $creatorId, string $posIdFormatted, int $posId, string $mainName): void
    {
        self::deductProductQuantity($alternativeId, $quantity, $warehouseId, $creatorId, $posIdFormatted, $posId, ' (substituted for ' . $mainName . ')', null);
    }
}
