<?php

namespace App\Services;

use App\Models\ProductKitItem;
use App\Models\ProductService;
use App\Models\Utility;

/**
 * Product Kit: deduct stock from kit components when a kit is sold.
 *
 * This is intentionally separate from the Recipes (BOM) module.
 */
class PosKitDeductionService
{
    public static function deductForSale(int $kitProductId, float $quantity, int $warehouseId, int $creatorId, string $posIdFormatted, int $posId, string $stockType = 'pos', bool $allowNegative = false): void
    {
        if ($quantity <= 0) {
            return;
        }

        $kit = ProductService::where('id', $kitProductId)->where('created_by', $creatorId)->first();
        if (!$kit || ($kit->type ?? null) !== 'kit') {
            return;
        }

        $items = ProductKitItem::where('kit_product_id', $kitProductId)
            ->where('created_by', $creatorId)
            ->get();

        foreach ($items as $item) {
            $componentQty = (float) $item->quantity * $quantity;
            if ($componentQty <= 0) {
                continue;
            }
            self::deductProductQuantity((int) $item->component_product_id, $componentQty, $warehouseId, $creatorId, $posIdFormatted, $posId, $stockType, $allowNegative);
        }
    }

    protected static function deductProductQuantity(int $productId, float $quantity, int $warehouseId, int $creatorId, string $posIdFormatted, int $posId, string $stockType = 'pos', bool $allowNegative = false): void
    {
        $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
        if (!$product) {
            return;
        }

        $original = (float) ($product->quantity ?? 0);
        $newQty = ($allowNegative || $product->allowsNegativeStock())
            ? $original - $quantity
            : max(0, $original - $quantity);
        ProductService::where('id', $productId)->update(['quantity' => $newQty]);
        if ($warehouseId > 0) {
            Utility::warehouse_quantity('minus', $quantity, $productId, $warehouseId, null, $allowNegative);
        }

        $soldIn = $stockType === 'invoice' ? __('quantity sold in invoice') : __('quantity sold in pos');
        $description = $quantity . ' ' . $soldIn . ' ' . $posIdFormatted;
        Utility::addProductStock($productId, $quantity, $stockType, $description, $posId);
    }
}

