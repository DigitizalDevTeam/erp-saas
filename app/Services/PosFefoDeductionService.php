<?php

namespace App\Services;

/**
 * @deprecated Use BatchLotDeductionService directly. Kept for backward compatibility.
 */
class PosFefoDeductionService
{
    public static function hasLots(int $productId, int $warehouseId, int $creatorId): bool
    {
        return BatchLotDeductionService::hasLots($productId, $warehouseId, $creatorId);
    }

    public static function deductForSale(
        int $productId,
        float $quantity,
        int $warehouseId,
        int $creatorId,
        string $posIdFormatted,
        int $posId,
        ?string $identifiersNote = null
    ): void {
        BatchLotDeductionService::deductForSale(
            $productId,
            $quantity,
            $warehouseId,
            $creatorId,
            $posIdFormatted,
            $posId,
            $identifiersNote,
            'pos'
        );
    }

    public static function availableQty(int $productId, int $warehouseId, int $creatorId): float
    {
        return BatchLotDeductionService::availableQty($productId, $warehouseId, $creatorId);
    }
}
