<?php

namespace App\Services;

use App\Models\ProductService;
use App\Models\Utility;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Batch / lot stock deduction with configurable consumption order:
 * - FEFO: earliest expiry first (non-expired lots only)
 * - FIFO: oldest lot first (by receipt order)
 * - LIFO / FILO: newest lot first
 */
class BatchLotDeductionService
{
    public const METHOD_FEFO = 'fefo';
    public const METHOD_FIFO = 'fifo';
    public const METHOD_LIFO = 'lifo';

    /** @return array<string, string> */
    public static function methodLabels(): array
    {
        return [
            self::METHOD_FEFO => __('FEFO (First expiry first out)'),
            self::METHOD_FIFO => __('FIFO (First in first out)'),
            self::METHOD_LIFO => __('LIFO / FILO (Last in first out)'),
        ];
    }

    public static function normalizeMethod(?string $method): string
    {
        $method = strtolower(trim((string) $method));

        return in_array($method, [self::METHOD_FEFO, self::METHOD_FIFO, self::METHOD_LIFO], true)
            ? $method
            : self::METHOD_FEFO;
    }

    public static function methodForProduct(?ProductService $product): string
    {
        if (! $product) {
            return self::METHOD_FEFO;
        }

        return self::normalizeMethod($product->batch_consumption_method ?? self::METHOD_FEFO);
    }

    public static function hasLots(int $productId, int $warehouseId, int $creatorId): bool
    {
        return self::baseLotQuery($productId, $warehouseId, $creatorId)->exists();
    }

    public static function availableQty(int $productId, int $warehouseId, int $creatorId): float
    {
        $today = date('Y-m-d');

        return (float) (self::baseLotQuery($productId, $warehouseId, $creatorId)
            ->where(function ($qq) use ($today) {
                $qq->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today);
            })
            ->sum('quantity_remaining') ?? 0);
    }

    /**
     * Deduct from lots only (no aggregate qty / warehouse / ledger).
     *
     * @return float Quantity that could not be deducted from lots
     */
    public static function deductLots(
        int $productId,
        float $quantity,
        int $creatorId,
        int $warehouseId = 0,
        ?string $method = null
    ): float {
        if ($quantity <= 0) {
            return 0.0;
        }

        $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
        $method = self::normalizeMethod($method ?? self::methodForProduct($product));
        $remaining = $quantity;

        DB::transaction(function () use ($productId, $warehouseId, $creatorId, $method, &$remaining) {
            $lots = self::orderedLotsForDeduction($productId, $warehouseId, $creatorId, $method);

            foreach ($lots as $lot) {
                if ($remaining <= 0) {
                    break;
                }
                $take = min((float) $lot->quantity_remaining, $remaining);
                DB::table('product_expiry_lots')->where('id', $lot->id)->update([
                    'quantity_remaining' => max(0, (float) $lot->quantity_remaining - $take),
                    'updated_at' => now(),
                ]);
                $remaining -= $take;
            }
        });

        return max(0, $remaining);
    }

    /**
     * Deduct lots plus aggregate product qty, warehouse qty, and stock ledger.
     */
    public static function deductForSale(
        int $productId,
        float $quantity,
        int $warehouseId,
        int $creatorId,
        string $referenceFormatted,
        int $referenceId,
        ?string $identifiersNote = null,
        string $stockType = 'pos'
    ): void {
        if ($quantity <= 0) {
            return;
        }

        $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
        if (! $product) {
            return;
        }

        $remaining = self::deductLots($productId, $quantity, $creatorId, $warehouseId);

        if ($remaining > 0) {
            return;
        }

        $original = (float) ($product->quantity ?? 0);
        ProductService::where('id', $productId)->update(['quantity' => max(0, $original - $quantity)]);
        Utility::warehouse_quantity('minus', $quantity, $productId, $warehouseId);

        $suffix = $identifiersNote ?? '';
        if ($stockType === 'hotel') {
            $description = $quantity . ' ' . $referenceFormatted . $suffix;
        } else {
            $description = $quantity . ' ' . __('quantity sold in pos') . ' ' . $referenceFormatted . $suffix;
        }

        Utility::addProductStock($productId, $quantity, $stockType, $description, $referenceId);
    }

    /**
     * @return Collection<int, object>
     */
    protected static function orderedLotsForDeduction(
        int $productId,
        int $warehouseId,
        int $creatorId,
        string $method
    ): Collection {
        $today = date('Y-m-d');
        $base = self::baseLotQuery($productId, $warehouseId, $creatorId)
            ->where('quantity_remaining', '>', 0)
            ->where(function ($q) use ($today) {
                $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today);
            });

        if ($method === self::METHOD_FEFO) {
            $withExpiry = (clone $base)
                ->whereNotNull('expiry_date')
                ->orderBy('expiry_date', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            $noExpiry = (clone $base)
                ->whereNull('expiry_date')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            return $withExpiry->concat($noExpiry);
        }

        if ($method === self::METHOD_FIFO) {
            return (clone $base)
                ->orderBy('created_at', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();
        }

        return (clone $base)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->lockForUpdate()
            ->get();
    }

    protected static function baseLotQuery(int $productId, int $warehouseId, int $creatorId)
    {
        $q = DB::table('product_expiry_lots')
            ->where('created_by', $creatorId)
            ->where('product_id', $productId)
            ->where('quantity_remaining', '>', 0);

        if ($warehouseId > 0) {
            $q->where(function ($qq) use ($warehouseId) {
                $qq->whereNull('warehouse_id')->orWhere('warehouse_id', $warehouseId);
            });
        }

        return $q;
    }
}
