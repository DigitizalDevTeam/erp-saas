<?php

namespace Modules\HotelManagement\Services;

use App\Models\ProductService;
use App\Models\Utility;
use App\Models\WarehouseProduct;
use App\Services\BatchLotDeductionService;
use Illuminate\Support\Collection;

/**
 * Deducts centralized ERP product stock when a hotel guest consumes a room
 * consumable (minibar). Mirrors the POS deduction path so warehouse stock,
 * aggregate product quantity, batch lots (FEFO/FIFO/LIFO) and the stock ledger all stay in sync.
 */
class HotelStockDeductionService
{
    /**
     * Stockable products (centralized catalog) usable as room consumables.
     *
     * @return Collection<int, ProductService>
     */
    public function consumableProducts(int $creatorId): Collection
    {
        return ProductService::where('created_by', $creatorId)
            ->where('type', 'product')
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'sale_price', 'tax_id', 'quantity']);
    }

    public function findProduct(int $productId, int $creatorId): ?ProductService
    {
        return ProductService::where('id', $productId)
            ->where('created_by', $creatorId)
            ->first();
    }

    /**
     * Warehouses that currently hold a stock record for the product.
     * Falls back to all warehouses for the tenant when the product has never
     * been stocked, so staff can still record consumption (oversell allowed).
     *
     * @return array<int, array{id:int, name:string, qty:float}>
     */
    public function warehousesForProduct(int $productId, int $creatorId): array
    {
        $rows = WarehouseProduct::where('product_id', $productId)
            ->where('created_by', $creatorId)
            ->with('warehouse')
            ->get()
            ->map(fn (WarehouseProduct $wp) => [
                'id' => (int) $wp->warehouse_id,
                'name' => optional($wp->warehouse)->name ?: ('#' . $wp->warehouse_id),
                'qty' => (float) $wp->quantity,
            ])
            ->values()
            ->all();

        if (! empty($rows)) {
            return $rows;
        }

        return \App\Models\warehouse::where('created_by', $creatorId)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($w) => [
                'id' => (int) $w->id,
                'name' => $w->name,
                'qty' => 0.0,
            ])
            ->values()
            ->all();
    }

    /**
     * Combined tax rate (percent) configured on the product.
     */
    public function taxRateForProduct(ProductService $product): float
    {
        if (empty($product->tax_id)) {
            return 0.0;
        }

        return (float) $product->taxRate($product->tax_id);
    }

    /**
     * Deduct stock for a consumed product from the chosen warehouse,
     * mirroring the POS deduction (warehouse qty, aggregate qty, FEFO lots,
     * stock ledger entry). Oversell is allowed (quantity floored at 0).
     */
    public function deduct(
        int $productId,
        float $quantity,
        int $warehouseId,
        int $creatorId,
        string $reference,
        int $referenceId = 0
    ): void {
        if ($quantity <= 0) {
            return;
        }

        $product = $this->findProduct($productId, $creatorId);
        if (! $product) {
            return;
        }

        // Batch-tracked products: deduct lots using product consumption method.
        if (($product->batch_tracking ?? false)
            || BatchLotDeductionService::hasLots($productId, $warehouseId, $creatorId)) {
            BatchLotDeductionService::deductForSale(
                $productId,
                $quantity,
                $warehouseId,
                $creatorId,
                $reference,
                $referenceId,
                null,
                'hotel'
            );

            return;
        }

        if (! ($product->affect_stock ?? true)) {
            return;
        }

        $original = (float) ($product->quantity ?? 0);
        ProductService::where('id', $productId)->update(['quantity' => max(0, $original - $quantity)]);
        Utility::warehouse_quantity('minus', $quantity, $productId, $warehouseId);
        Utility::addProductStock($productId, $quantity, 'hotel', $reference, $referenceId);
    }
}
