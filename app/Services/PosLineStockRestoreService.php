<?php

namespace App\Services;

use App\Models\Pos;
use App\Models\PosProduct;
use App\Models\ProductKitItem;
use App\Models\ProductRecipe;
use App\Models\ProductService;
use App\Models\Utility;

/**
 * Returns stock for a restaurant POS line that was already deducted at
 * production time (see PosLineStockDeductionService) and is now voided,
 * removed from the cart, or part of a cancelled order.
 *
 * Mirrors the deduction ladder in reverse: selected options, parent BOM
 * (recipe ingredients / kit components), kit components, recipe ingredients
 * or the product itself. Each returned quantity also writes a stock_reports
 * row of type 'pos' with a NEGATIVE quantity, so the stock movement report
 * shows the return as a positive movement next to the original deduction.
 *
 * Batch/FEFO lot quantities are not restored (same fidelity as invoice
 * voids); only the global and warehouse quantities are returned.
 */
class PosLineStockRestoreService
{
    /**
     * Restore stock for one line. Returns true when stock was returned by
     * this call, false when the line was never deducted at production or
     * was already restored.
     */
    public static function restoreLine(Pos $pos, PosProduct $line, int $creatorId, string $posNoFormatted): bool
    {
        if (! $line->stock_deducted_at || $line->stock_restored_at) {
            return false;
        }

        $product = ProductService::where('id', $line->product_id)->where('created_by', $creatorId)->first();
        if (! $product) {
            return false;
        }

        $warehouseId = (int) ($line->warehouse_id ?? 0) > 0
            ? (int) $line->warehouse_id
            : (int) ($pos->warehouse_id ?? 0);

        $lineQty = (float) $line->quantity;
        $posId = (int) $pos->id;

        $selectedOpts = ProductOptionService::normalizeSelectedOptions($line->selected_options_json ?? null);

        if (($product->options_enabled ?? false) && $selectedOpts !== []) {
            foreach ($selectedOpts as $row) {
                self::restoreOption($row, $lineQty, $warehouseId, $creatorId, $posNoFormatted, $posId);
            }
            // Parent BOM was deducted after the options; return it as well.
            self::restoreParentBom($product, $lineQty, $warehouseId, $creatorId, $posNoFormatted, $posId);
        } elseif (($product->type ?? null) === 'kit') {
            self::restoreKitComponents((int) $product->id, $lineQty, $warehouseId, $creatorId, $posNoFormatted, $posId);
        } else {
            self::restoreRecipeOrProduct($product, $lineQty, $warehouseId, $creatorId, $posNoFormatted, $posId, $line->product_variant_id ? (int) $line->product_variant_id : null);
        }

        $line->stock_restored_at = now();
        $line->save();

        return true;
    }

    /**
     * @param  array{product_id: int, quantity: float}  $row
     */
    protected static function restoreOption(array $row, float $lineQty, int $warehouseId, int $creatorId, string $posNoFormatted, int $posId): void
    {
        $optId = (int) ($row['product_id'] ?? 0);
        // Option quantity is per parent unit; restore setup qty x line qty,
        // mirroring the deduction.
        $qty = (float) ($row['quantity'] ?? 1) * ($lineQty > 0 ? $lineQty : 1);
        if ($optId <= 0 || $qty <= 0) {
            return;
        }

        $opt = ProductService::where('id', $optId)->where('created_by', $creatorId)->first()
            ?: ProductService::where('id', $optId)->first();
        if (! $opt) {
            return;
        }

        // Kit options deducted their components on sale; return those. This
        // must run before the affect_stock skip — kits always have
        // affect_stock = false, so the kit branch was unreachable before.
        if (($opt->type ?? null) === 'kit') {
            self::restoreKitComponents($optId, $qty, $warehouseId, $creatorId, $posNoFormatted, $posId);

            return;
        }

        if (! ($opt->affect_stock ?? true)) {
            return;
        }

        self::addBack($optId, $qty, $warehouseId, $creatorId, $posNoFormatted, $posId, true);
    }

    protected static function restoreParentBom(ProductService $product, float $lineQty, int $warehouseId, int $creatorId, string $posNoFormatted, int $posId): void
    {
        if (($product->type ?? null) === 'kit') {
            self::restoreKitComponents((int) $product->id, $lineQty, $warehouseId, $creatorId, $posNoFormatted, $posId);

            return;
        }

        // The parent BOM deduction only ran when recipes exist, and
        // PosRecipeDeductionService then deducts ingredients only.
        // No-affect ingredients were never deducted, so they are never
        // added back either (or a void would create stock from nothing).
        $recipes = ProductRecipe::where('product_id', (int) $product->id)->where('created_by', $creatorId)->with('ingredientProduct')->get();
        foreach ($recipes as $recipe) {
            $ingredient = $recipe->ingredientProduct;
            if (! $ingredient || ($ingredient->type ?? '') !== 'ingredient') {
                continue;
            }
            if (! ($ingredient->affect_stock ?? true)) {
                continue;
            }
            $qty = (float) $recipe->quantity * $lineQty;
            if ($qty > 0) {
                self::addBack((int) $ingredient->id, $qty, $warehouseId, $creatorId, $posNoFormatted, $posId, false);
            }
        }
    }

    protected static function restoreKitComponents(int $kitProductId, float $quantity, int $warehouseId, int $creatorId, string $posNoFormatted, int $posId): void
    {
        $items = ProductKitItem::where('kit_product_id', $kitProductId)->where('created_by', $creatorId)->get();
        foreach ($items as $item) {
            $qty = (float) $item->quantity * $quantity;
            if ($qty > 0) {
                self::addBack((int) $item->component_product_id, $qty, $warehouseId, $creatorId, $posNoFormatted, $posId, false);
            }
        }
    }

    protected static function restoreRecipeOrProduct(ProductService $product, float $lineQty, int $warehouseId, int $creatorId, string $posNoFormatted, int $posId, ?int $variantId): void
    {
        $recipes = ProductRecipe::where('product_id', (int) $product->id)->where('created_by', $creatorId)->with('ingredientProduct')->get();
        if ($recipes->isNotEmpty()) {
            foreach ($recipes as $recipe) {
                $ingredient = $recipe->ingredientProduct;
                if (! $ingredient || ($ingredient->type ?? '') !== 'ingredient') {
                    continue;
                }
                if (! ($ingredient->affect_stock ?? true)) {
                    continue;
                }
                $qty = (float) $recipe->quantity * $lineQty;
                if ($qty > 0) {
                    self::addBack((int) $ingredient->id, $qty, $warehouseId, $creatorId, $posNoFormatted, $posId, false);
                }
            }

            return;
        }

        if ($product->affect_stock ?? true) {
            self::addBack((int) $product->id, $lineQty, $warehouseId, $creatorId, $posNoFormatted, $posId, false, $variantId);
        }
    }

    /**
     * Add a quantity back to the global and warehouse stock and record the
     * return in the stock ledger. $guardWarehouse mirrors the option
     * deduction path, which only touches the warehouse when one is set.
     */
    protected static function addBack(int $productId, float $qty, int $warehouseId, int $creatorId, string $posNoFormatted, int $posId, bool $guardWarehouse, ?int $variantId = null): void
    {
        if ($qty <= 0) {
            return;
        }

        $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
        if (! $product) {
            return;
        }

        ProductService::where('id', $productId)->update(['quantity' => (float) ($product->quantity ?? 0) + $qty]);
        if (! $guardWarehouse || $warehouseId > 0) {
            Utility::warehouse_quantity('plus', $qty, $productId, $warehouseId, $variantId);
        }
        Utility::addProductStock($productId, -$qty, 'pos', $qty . ' ' . __('quantity returned in pos') . ' ' . $posNoFormatted, $posId);
    }
}
