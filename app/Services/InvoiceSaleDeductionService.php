<?php

namespace App\Services;

use App\Models\ProductIdentifier;
use App\Models\ProductKitItem;
use App\Models\ProductService;
use Illuminate\Support\Facades\DB;

/**
 * Apply the same stock rules to Sales -> Invoice as POS:
 * - Kit: deduct components
 * - FEFO: deduct from product_expiry_lots by earliest expiry
 * - Serial/MAC: validate identifiers and mark as sold
 *
 * Note: Invoices in this app do not store warehouse_id, so deductions are applied globally (warehouse_id = null/0).
 */
class InvoiceSaleDeductionService
{
    /**
     * Ensure Serial/MAC lines are valid and each identifier exists in stock for this product.
     * Call on every invoice save for tracked products, even when stock is deducted later (e.g. delivery note).
     */
    public static function validateTrackedIdentifiersForLine(int $productId, float $quantity, int $creatorId, ?string $identifiersRaw): void
    {
        if ($quantity <= 0) {
            return;
        }

        $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
        if (!$product || !($product->identifier_tracking ?? false)) {
            return;
        }

        $list = preg_split("/\r\n|\n|\r|,|;|\t/", (string) ($identifiersRaw ?? ''));
        $list = array_values(array_filter(array_map(function ($v) {
            $v = trim((string) $v);
            return $v === '' ? null : $v;
        }, $list)));

        $unique = array_values(array_unique($list));
        if (count($unique) !== count($list)) {
            throw new \RuntimeException(__('Duplicate Serial/MAC found in the list.'));
        }
        if (count($unique) !== (int) $quantity) {
            throw new \RuntimeException(__('Serial/MAC count must match quantity for tracked products.'));
        }

        $exists = ProductIdentifier::where('created_by', $creatorId)
            ->where('product_id', $productId)
            ->whereIn('identifier_value', $unique)
            ->where('status', 'in_stock')
            ->count();
        if ($exists !== count($unique)) {
            throw new \RuntimeException(__('One or more Serial/MAC is not available in stock.'));
        }
    }

    public static function deductLine(int $productId, float $quantity, int $creatorId, int $invoiceId, int $invoiceProductId, int $customerId, string $invoiceNoFormatted, ?string $identifiersRaw = null): void
    {
        if ($quantity <= 0) {
            return;
        }

        $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
        if (!$product) {
            return;
        }

        // Serial/MAC validation and assignment (if enabled)
        if (($product->identifier_tracking ?? false)) {
            self::validateTrackedIdentifiersForLine($productId, $quantity, $creatorId, $identifiersRaw);

            $list = preg_split("/\r\n|\n|\r|,|;|\t/", (string) ($identifiersRaw ?? ''));
            $list = array_values(array_filter(array_map(function ($v) {
                $v = trim((string) $v);
                return $v === '' ? null : $v;
            }, $list)));
            $unique = array_values(array_unique($list));

            ProductIdentifier::where('created_by', $creatorId)
                ->where('product_id', $productId)
                ->whereIn('identifier_value', $unique)
                ->update([
                    'status' => 'sold',
                    'customer_id' => $customerId ?: null,
                    'sold_at' => now(),
                    'invoice_id' => $invoiceId,
                    'invoice_product_id' => $invoiceProductId,
                    'updated_at' => now(),
                ]);
        }

        if (($product->type ?? null) === 'kit') {
            $items = ProductKitItem::where('kit_product_id', $productId)->where('created_by', $creatorId)->get();
            foreach ($items as $it) {
                $componentQty = (float) $it->quantity * $quantity;
                if ($componentQty <= 0) continue;
                self::deductSimpleProduct((int) $it->component_product_id, $componentQty, $creatorId, $invoiceId, $invoiceNoFormatted);
            }
            return;
        }

        // FEFO for batch/expiry tracked products (lots exist)
        if (($product->batch_tracking ?? false) || self::hasLots($productId, $creatorId)) {
            self::deductFefoLots($productId, $quantity, $creatorId);
            self::deductSimpleProduct($productId, $quantity, $creatorId, $invoiceId, $invoiceNoFormatted);
            return;
        }

        // Normal product
        if ($product->affect_stock ?? true) {
            self::deductSimpleProduct($productId, $quantity, $creatorId, $invoiceId, $invoiceNoFormatted);
        }
    }

    protected static function deductSimpleProduct(int $productId, float $quantity, int $creatorId, int $invoiceId, string $invoiceNoFormatted): void
    {
        $p = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
        if (!$p) return;

        $original = (float) ($p->quantity ?? 0);
        $newQty = max(0, $original - $quantity);
        ProductService::where('id', $productId)->update(['quantity' => $newQty]);

        $description = $quantity . ' ' . __(' quantity sold in invoice') . ' ' . $invoiceNoFormatted;
        \App\Models\Utility::addProductStock($productId, $quantity, 'invoice', $description, $invoiceId);
    }

    protected static function hasLots(int $productId, int $creatorId): bool
    {
        return DB::table('product_expiry_lots')
            ->where('created_by', $creatorId)
            ->where('product_id', $productId)
            ->where('quantity_remaining', '>', 0)
            ->exists();
    }

    protected static function deductFefoLots(int $productId, float $quantity, int $creatorId): void
    {
        $remaining = $quantity;
        DB::transaction(function () use ($productId, $creatorId, &$remaining) {
            $today = date('Y-m-d');
            $base = DB::table('product_expiry_lots')
                ->where('created_by', $creatorId)
                ->where('product_id', $productId)
                ->where('quantity_remaining', '>', 0);

            $lots = (clone $base)
                ->whereNotNull('expiry_date')
                ->where('expiry_date', '>=', $today)
                ->orderBy('expiry_date', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            foreach ($lots as $lot) {
                if ($remaining <= 0) break;
                $take = min((float) $lot->quantity_remaining, $remaining);
                DB::table('product_expiry_lots')->where('id', $lot->id)->update([
                    'quantity_remaining' => max(0, (float) $lot->quantity_remaining - $take),
                    'updated_at' => now(),
                ]);
                $remaining -= $take;
            }

            if ($remaining > 0) {
                $noExpiry = (clone $base)
                    ->whereNull('expiry_date')
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();
                foreach ($noExpiry as $lot) {
                    if ($remaining <= 0) break;
                    $take = min((float) $lot->quantity_remaining, $remaining);
                    DB::table('product_expiry_lots')->where('id', $lot->id)->update([
                        'quantity_remaining' => max(0, (float) $lot->quantity_remaining - $take),
                        'updated_at' => now(),
                    ]);
                    $remaining -= $take;
                }
            }
        });

        if ($remaining > 0) {
            throw new \RuntimeException(__('This product is out of stock!'));
        }
    }
}

