<?php

namespace App\Services;

use App\Models\Pos;
use App\Models\PosAuditLog;
use App\Models\PosProduct;
use App\Models\ProductRecipe;
use App\Models\ProductService;

/**
 * Deducts stock for a saved restaurant POS line (a PosProduct row).
 *
 * Restaurant lines are deducted when they are SENT TO PRODUCTION
 * (PosController::sendToProduction), so the stock movement report shows the
 * movement at production time instead of at payment time. The line is then
 * flagged with stock_deducted_at and PosController::store() skips it, so the
 * later payment never deducts twice. Lines created before this behaviour
 * (or by other flows) have no flag and are still deducted at payment.
 *
 * The ladder mirrors exactly what store() used to run inline at payment:
 * options -> parent BOM, kit, variant recipe, FEFO lots, recipe/simple.
 * Identifier (serial) assignment intentionally stays at payment time.
 */
class PosLineStockDeductionService
{
    /**
     * Deduct stock for one saved POS line. Returns true when the line was
     * deducted by this call, false when it was already deducted or the
     * product no longer exists.
     */
    public static function deductLine(Pos $pos, PosProduct $line, int $creatorId, string $posNoFormatted): bool
    {
        if ($line->stock_deducted_at) {
            return false;
        }

        $product = ProductService::where('id', $line->product_id)->where('created_by', $creatorId)->first();
        if (! $product) {
            return false;
        }

        $warehouseId = (int) ($line->warehouse_id ?? 0) > 0
            ? (int) $line->warehouse_id
            : (int) ($pos->warehouse_id ?? 0);

        $variantId = $line->product_variant_id ? (int) $line->product_variant_id : null;
        if ($variantId !== null && $variantId <= 0) {
            $variantId = null;
        }

        $identifiersNote = InvoiceSaleDeductionService::formatIdentifiersNote($line->identifiers ?? null);
        $lineQty = (float) $line->quantity;
        $posId = (int) $pos->id;

        $selectedOpts = ProductOptionService::normalizeSelectedOptions($line->selected_options_json ?? null);

        // A manager-authorized line may take its whole ladder below zero:
        // parent, options, recipe ingredients and kit components alike.
        // Lots are never oversold, so an authorized line skips the FEFO
        // branch and deducts from warehouse stock instead.
        $allowNegative = ! empty($line->negative_stock_authorized_by);

        if (ProductOptionService::tryDeductPosLine($product, [
            'quantity' => $line->quantity,
            'selected_options' => $selectedOpts,
            'selected_options_json' => $line->selected_options_json,
        ], $warehouseId, $creatorId, $posNoFormatted, $posId, $allowNegative)) {
            // Options deducted; option recipes (an option's own ingredients,
            // honoring restaurant popup picks) are consumed too.
            ProductOptionService::deductOptionRecipesForSale($selectedOpts, $lineQty, $warehouseId, $creatorId, $posNoFormatted, $posId, $allowNegative);
            // Options deducted; the parent's own BOM (recipe ingredients /
            // kit components) is still consumed per unit sold.
            self::deductParentBom($product, $lineQty, $warehouseId, $creatorId, $posNoFormatted, $posId, $identifiersNote, $variantId, $allowNegative);
        } elseif (($product->type ?? null) === 'kit') {
            PosKitDeductionService::deductForSale(
                (int) $line->product_id,
                $lineQty,
                $warehouseId,
                $creatorId,
                $posNoFormatted,
                $posId,
                'pos',
                $allowNegative
            );
        } elseif (($product->variants_enabled ?? false) && $variantId) {
            PosRecipeDeductionService::deductForSale(
                (int) $line->product_id,
                $lineQty,
                $warehouseId,
                $creatorId,
                $posNoFormatted,
                $posId,
                $identifiersNote,
                $variantId,
                $allowNegative
            );
        } elseif (! $allowNegative && (($product->batch_tracking ?? false) || PosFefoDeductionService::hasLots((int) $line->product_id, $warehouseId, $creatorId))) {
            PosFefoDeductionService::deductForSale(
                (int) $line->product_id,
                $lineQty,
                $warehouseId,
                $creatorId,
                $posNoFormatted,
                $posId,
                $identifiersNote
            );
        } else {
            PosRecipeDeductionService::deductForSale(
                (int) $line->product_id,
                $lineQty,
                $warehouseId,
                $creatorId,
                $posNoFormatted,
                $posId,
                $identifiersNote,
                $variantId,
                $allowNegative
            );
        }

        $line->stock_deducted_at = now();
        $line->save();

        if ($allowNegative) {
            PosAuditLogService::log(PosAuditLog::ACTION_NEGATIVE_STOCK, 'pos_product', (int) $line->id, __('Negative stock sale authorized by manager'), [
                'product_id' => (int) $line->product_id,
                'warehouse_id' => $warehouseId,
                'quantity' => $lineQty,
                'authorized_by' => (int) $line->negative_stock_authorized_by,
            ]);
        }

        return true;
    }

    /**
     * Mirror of PosController::deductParentBomAfterOptions(): after the
     * selected options were deducted, consume the parent product's own BOM.
     */
    protected static function deductParentBom(ProductService $product, float $lineQty, int $warehouseId, int $creatorId, string $posNoFormatted, int $posId, ?string $identifiersNote, ?int $variantId, bool $allowNegative = false): void
    {
        if (($product->type ?? null) === 'kit') {
            PosKitDeductionService::deductForSale((int) $product->id, $lineQty, $warehouseId, $creatorId, $posNoFormatted, $posId, 'pos', $allowNegative);

            return;
        }

        if (ProductRecipe::where('product_id', (int) $product->id)->where('created_by', $creatorId)->exists()) {
            PosRecipeDeductionService::deductForSale((int) $product->id, $lineQty, $warehouseId, $creatorId, $posNoFormatted, $posId, $identifiersNote, $variantId, $allowNegative);
        }
    }
}
