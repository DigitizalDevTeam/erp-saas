<?php

namespace App\Services;

use App\Models\ProductService;
use App\Models\Utility;
use Illuminate\Support\Facades\DB;

/**
 * Auto FEFO stock deduction using product_expiry_lots (earliest expiry first).
 *
 * This deducts from lots with:
 * - same creator
 * - same product
 * - same warehouse (when provided)
 * - expiry_date >= today (non-expired) OR expiry_date is NULL (consumed last)
 * - quantity_remaining > 0
 */
class PosFefoDeductionService
{
    public static function hasLots(int $productId, int $warehouseId, int $creatorId): bool
    {
        $q = DB::table('product_expiry_lots')
            ->where('created_by', $creatorId)
            ->where('product_id', $productId)
            ->where('quantity_remaining', '>', 0);

        if ($warehouseId > 0) {
            $q = $q->where(function ($qq) use ($warehouseId) {
                $qq->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId);
            });
        }

        return $q->exists();
    }

    public static function deductForSale(int $productId, float $quantity, int $warehouseId, int $creatorId, string $posIdFormatted, int $posId, ?string $identifiersNote = null): void
    {
        if ($quantity <= 0) {
            return;
        }

        $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
        if (!$product) {
            return;
        }

        $remainingToDeduct = $quantity;

        DB::transaction(function () use ($productId, $warehouseId, $creatorId, $posIdFormatted, $posId, &$remainingToDeduct) {
            // Non-expired lots first, earliest expiry
            $today = date('Y-m-d');

            $base = DB::table('product_expiry_lots')
                ->where('created_by', $creatorId)
                ->where('product_id', $productId)
                ->where('quantity_remaining', '>', 0);

            if ($warehouseId > 0) {
                $base = $base->where(function ($q) use ($warehouseId) {
                    $q->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId);
                });
            }

            $lots = (clone $base)
                ->whereNotNull('expiry_date')
                ->where('expiry_date', '>=', $today)
                ->orderBy('expiry_date', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            foreach ($lots as $lot) {
                if ($remainingToDeduct <= 0) {
                    break;
                }
                $take = min((float) $lot->quantity_remaining, $remainingToDeduct);
                DB::table('product_expiry_lots')->where('id', $lot->id)->update([
                    'quantity_remaining' => max(0, (float) $lot->quantity_remaining - $take),
                    'updated_at' => now(),
                ]);
                $remainingToDeduct -= $take;
            }

            // Then lots without expiry_date (consumed last)
            if ($remainingToDeduct > 0) {
                $noExpiryLots = (clone $base)
                    ->whereNull('expiry_date')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();
                foreach ($noExpiryLots as $lot) {
                    if ($remainingToDeduct <= 0) {
                        break;
                    }
                    $take = min((float) $lot->quantity_remaining, $remainingToDeduct);
                    DB::table('product_expiry_lots')->where('id', $lot->id)->update([
                        'quantity_remaining' => max(0, (float) $lot->quantity_remaining - $take),
                        'updated_at' => now(),
                    ]);
                    $remainingToDeduct -= $take;
                }
            }
        });

        if ($remainingToDeduct > 0) {
            // Not enough lots to cover; do not silently over-sell. (Caller should prevent this.)
            return;
        }

        // Keep existing aggregate quantity + warehouse quantity behavior in sync
        $p = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
        if (!$p) {
            return;
        }
        $original = (float) ($p->quantity ?? 0);
        $newQty = max(0, $original - $quantity);
        ProductService::where('id', $productId)->update(['quantity' => $newQty]);
        Utility::warehouse_quantity('minus', $quantity, $productId, $warehouseId);
        $suffix = $identifiersNote ?? '';
        $description = $quantity . ' ' . __('quantity sold in pos') . ' ' . $posIdFormatted . $suffix;
        Utility::addProductStock($productId, $quantity, 'pos', $description, $posId);
    }

    public static function availableQty(int $productId, int $warehouseId, int $creatorId): float
    {
        $today = date('Y-m-d');
        $q = DB::table('product_expiry_lots')
            ->where('created_by', $creatorId)
            ->where('product_id', $productId)
            ->where('quantity_remaining', '>', 0)
            ->where(function ($qq) use ($today) {
                $qq->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today);
            });

        if ($warehouseId > 0) {
            $q = $q->where(function ($qq) use ($warehouseId) {
                $qq->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId);
            });
        }

        return (float) ($q->sum('quantity_remaining') ?? 0);
    }
}

