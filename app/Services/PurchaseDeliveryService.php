<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\BillProduct;
use App\Models\ProductExpiryLot;
use App\Models\ProductIdentifier;
use App\Models\ProductService;
use App\Models\Purchase;
use App\Models\PurchaseDeliveryNote;
use App\Models\PurchaseDeliveryNoteItem;
use App\Models\PurchaseProduct;
use App\Models\Utility;
use App\Models\warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Purchase delivery notes: the ONLY stock-in step for delivery-driven
 * purchase orders and delivery-driven supplier bills.
 *
 * Legacy documents (stock_on_delivery = 0, or the marker column missing
 * before the migration runs) already added their stock at create time,
 * so delivery notes are refused for them — otherwise stock would be
 * counted twice.
 */
class PurchaseDeliveryService
{
    public static function tablesAvailable(): bool
    {
        // Per-call guard, never memoized: PHPUnit reuses one process
        // across tests with different in-memory schemas.
        return Schema::hasTable('purchase_delivery_notes')
            && Schema::hasTable('purchase_delivery_note_items');
    }

    public static function deliveredQty(string $sourceType, int $lineId): float
    {
        if (! self::tablesAvailable()) {
            return 0.0;
        }

        return (float) PurchaseDeliveryNoteItem::where('source_type', $sourceType)
            ->where('source_line_id', $lineId)
            ->sum('delivered_qty');
    }

    /** @return array<int, array<string, mixed>> */
    public static function sourceLinesForPurchase(Purchase $purchase): array
    {
        $rows = [];
        foreach ($purchase->items as $line) {
            $ordered = (float) $line->quantity;
            $delivered = self::deliveredQty('purchase', (int) $line->id);
            $rows[] = [
                'source_type' => 'purchase',
                'line' => $line,
                'product' => $line->product,
                'ordered' => $ordered,
                'delivered' => $delivered,
                'remaining' => $ordered - $delivered,
            ];
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    public static function sourceLinesForBill(Bill $bill): array
    {
        $rows = [];
        foreach ($bill->items as $line) {
            if (empty($line->product_id)) {
                continue;
            }
            $ordered = (float) $line->quantity;
            $delivered = self::deliveredQty('bill', (int) $line->id);
            $rows[] = [
                'source_type' => 'bill',
                'line' => $line,
                'product' => $line->product,
                'ordered' => $ordered,
                'delivered' => $delivered,
                'remaining' => $ordered - $delivered,
            ];
        }

        return $rows;
    }

    public static function isDeliveryDrivenPurchase(Purchase $purchase): bool
    {
        if (! Schema::hasTable('purchases') || ! Schema::hasColumn('purchases', 'stock_on_delivery')) {
            return false;
        }

        return (bool) ($purchase->stock_on_delivery ?? false);
    }

    public static function isDeliveryDrivenBill(Bill $bill): bool
    {
        if (! Schema::hasTable('bills') || ! Schema::hasColumn('bills', 'stock_on_delivery')) {
            return false;
        }

        return (bool) ($bill->stock_on_delivery ?? false);
    }

    /**
     * @param  array<string, mixed>  $input  vender_id, warehouse_id,
     *     purchase_id|bill_id, delivery_date, items[] each with
     *     source_type, source_line_id, delivered_qty and, for tracked
     *     products, batch_no / expiry_date / mfg_date / identifiers.
     */
    public static function createNote(int $creatorId, array $input): PurchaseDeliveryNote
    {
        if (! self::tablesAvailable()) {
            throw new InvalidArgumentException('Delivery notes are not set up yet. Run the migration first.');
        }

        $warehouseId = (int) ($input['warehouse_id'] ?? 0);
        $venderId = (int) ($input['vender_id'] ?? 0);
        $purchase = ! empty($input['purchase_id'])
            ? Purchase::where('id', (int) $input['purchase_id'])->where('created_by', $creatorId)->first()
            : null;
        $bill = ! empty($input['bill_id'])
            ? Bill::where('id', (int) $input['bill_id'])->where('created_by', $creatorId)->first()
            : null;

        if ($purchase === null && $bill === null) {
            throw new InvalidArgumentException('A delivery note needs a purchase order or a supplier bill.');
        }
        if ($purchase !== null && $bill !== null) {
            throw new InvalidArgumentException('A delivery note belongs to one purchase order or one bill, not both.');
        }

        if ($purchase !== null) {
            if (! self::isDeliveryDrivenPurchase($purchase)) {
                throw new InvalidArgumentException('This purchase order already added its stock when it was created. Delivery notes are only for new purchase orders.');
            }
            if ((int) $purchase->vender_id !== $venderId) {
                throw new InvalidArgumentException('The supplier does not match this purchase order.');
            }
            if ((int) $purchase->warehouse_id !== $warehouseId) {
                throw new InvalidArgumentException('The warehouse does not match this purchase order.');
            }
        }

        if ($bill !== null) {
            if (! empty($bill->purchase_id)) {
                throw new InvalidArgumentException('This bill was converted from a purchase order. Create the delivery note against that purchase order instead.');
            }
            if (! self::isDeliveryDrivenBill($bill)) {
                throw new InvalidArgumentException('This bill already added its stock when it was created. Delivery notes are only for new bills.');
            }
            if ((int) $bill->vender_id !== $venderId) {
                throw new InvalidArgumentException('The supplier does not match this bill.');
            }
        }

        $warehouse = warehouse::where('id', $warehouseId)->where('created_by', $creatorId)->first();
        if (! $warehouse) {
            throw new InvalidArgumentException('Invalid warehouse.');
        }

        $rawItems = $input['items'] ?? [];
        if (! is_array($rawItems) || $rawItems === []) {
            throw new InvalidArgumentException('Enter at least one delivered quantity.');
        }

        // Phase 1: validate everything before touching any stock.
        $resolved = [];
        $perSource = [];
        foreach ($rawItems as $raw) {
            $qty = (float) ($raw['delivered_qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $sourceType = (string) ($raw['source_type'] ?? '');
            $lineId = (int) ($raw['source_line_id'] ?? 0);
            if (! in_array($sourceType, ['purchase', 'bill'], true) || $lineId <= 0) {
                throw new InvalidArgumentException('Invalid delivery line.');
            }
            if ($purchase !== null && $sourceType !== 'purchase') {
                throw new InvalidArgumentException('This delivery note is for a purchase order, not a bill.');
            }
            if ($bill !== null && $sourceType !== 'bill') {
                throw new InvalidArgumentException('This delivery note is for a bill, not a purchase order.');
            }

            $line = $sourceType === 'purchase'
                ? PurchaseProduct::where('id', $lineId)->where('purchase_id', $purchase->id)->first()
                : BillProduct::where('id', $lineId)->where('bill_id', $bill->id)->first();
            if (! $line || empty($line->product_id)) {
                throw new InvalidArgumentException('One delivery line does not belong to this document.');
            }

            $product = ProductService::where('id', (int) $line->product_id)->where('created_by', $creatorId)->first();
            if (! $product) {
                throw new InvalidArgumentException('One delivered product was not found.');
            }

            $ordered = (float) $line->quantity;
            $already = self::deliveredQty($sourceType, $lineId);
            $inNote = $perSource[$sourceType . ':' . $lineId] ?? 0.0;
            if ($qty + $already + $inNote > $ordered + 0.0001) {
                throw new InvalidArgumentException('Delivered quantity is more than the remaining quantity for ' . $product->name . '.');
            }
            $perSource[$sourceType . ':' . $lineId] = $inNote + $qty;

            $batchNo = trim((string) ($raw['batch_no'] ?? ($line->batch_no ?? '')));
            $expiry = ! empty($raw['expiry_date']) ? $raw['expiry_date'] : ($line->expiry_date ?? null);
            $mfg = ! empty($raw['mfg_date']) ? $raw['mfg_date'] : ($line->mfg_date ?? null);
            if (($product->batch_tracking ?? false) && ($product->affect_stock ?? true)) {
                if ($batchNo === '' || empty($expiry)) {
                    throw new InvalidArgumentException('Batch number and expiry date are required for batch-tracked product ' . $product->name . '.');
                }
            }

            $identifiers = [];
            if ($product->identifier_tracking ?? false) {
                if (abs($qty - round($qty)) > 0.0001) {
                    throw new InvalidArgumentException('Serial / MAC tracked products must be delivered in whole quantities.');
                }
                $list = preg_split("/\r\n|\n|\r|,|;|\t/", (string) ($raw['identifiers'] ?? ''));
                $identifiers = array_values(array_filter(array_map(fn ($v) => trim((string) $v) === '' ? null : trim((string) $v), $list)));
                if (count(array_unique($identifiers)) !== count($identifiers)) {
                    throw new InvalidArgumentException('Duplicate Serial / MAC found for ' . $product->name . '.');
                }
                if (count($identifiers) !== (int) round($qty)) {
                    throw new InvalidArgumentException('Serial / MAC count must match the delivered quantity for ' . $product->name . '.');
                }
                if (ProductIdentifier::where('created_by', $creatorId)->whereIn('identifier_value', $identifiers)->exists()) {
                    throw new InvalidArgumentException('One or more Serial / MAC already exists for ' . $product->name . '.');
                }
            }

            $resolved[] = [
                'line' => $line,
                'product' => $product,
                'source_type' => $sourceType,
                'qty' => $qty,
                'ordered' => $ordered,
                'batch_no' => $batchNo !== '' ? $batchNo : null,
                'expiry_date' => $expiry,
                'mfg_date' => $mfg,
                'identifiers' => $identifiers,
            ];
        }

        if ($resolved === []) {
            throw new InvalidArgumentException('Enter at least one delivered quantity greater than zero.');
        }

        // Phase 2: create the note and add stock, all in one transaction.
        return DB::transaction(function () use ($creatorId, $input, $purchase, $bill, $warehouseId, $venderId, $resolved) {
            $noteNumber = (int) (PurchaseDeliveryNote::where('created_by', $creatorId)->max('note_number') ?? 0) + 1;
            $note = PurchaseDeliveryNote::create([
                'note_number' => $noteNumber,
                'vender_id' => $venderId,
                'warehouse_id' => $warehouseId,
                'purchase_id' => $purchase ? $purchase->id : null,
                'bill_id' => $bill ? $bill->id : null,
                'delivery_date' => $input['delivery_date'] ?? now()->toDateString(),
                'created_by' => $creatorId,
            ]);

            $description = 'quantity add in purchase delivery DN-' . $noteNumber;

            foreach ($resolved as $row) {
                /** @var PurchaseProduct|BillProduct $line */
                $line = $row['line'];
                $product = $row['product'];
                $qty = $row['qty'];
                [$rowNo, $shelfNo] = Utility::normalizeWarehouseLocation(
                    $line->row_no ?? '',
                    $line->shelf_no ?? ''
                );
                $selectedJson = $row['source_type'] === 'purchase' ? ($line->selected_options_json ?? null) : null;
                $selectedOpts = $selectedJson
                    ? ProductOptionService::normalizeSelectedOptions($selectedJson)
                    : [];

                PurchaseDeliveryNoteItem::create([
                    'purchase_delivery_note_id' => $note->id,
                    'source_type' => $row['source_type'],
                    'source_line_id' => $line->id,
                    'product_id' => $line->product_id,
                    'product_variant_id' => $line->product_variant_id ?? null,
                    'ordered_qty' => $row['ordered'],
                    'delivered_qty' => $qty,
                    'price' => $line->price ?? 0,
                    'row_no' => $rowNo !== '' ? $rowNo : null,
                    'shelf_no' => $shelfNo !== '' ? $shelfNo : null,
                    'batch_no' => $row['batch_no'],
                    'expiry_date' => $row['expiry_date'],
                    'mfg_date' => $row['mfg_date'],
                    'selected_options_json' => $selectedJson,
                    'created_by' => $creatorId,
                ]);

                // Same stock path as the old purchase create, but only for
                // the delivered quantity: options stock their own lines,
                // otherwise the product itself gains global + warehouse stock.
                if (($product->options_enabled ?? false) && $selectedOpts !== []) {
                    ProductOptionService::addOptionsForPurchase(
                        $selectedOpts,
                        $qty,
                        $warehouseId,
                        $creatorId,
                        'purchase_delivery',
                        $description,
                        (int) $note->id
                    );
                } else {
                    Utility::total_quantity('plus', $qty, (int) $line->product_id);
                    Utility::addProductStock((int) $line->product_id, $qty, 'purchase_delivery', $description, (int) $note->id);
                    Utility::addWarehouseStock((int) $line->product_id, $qty, $warehouseId, $rowNo, $shelfNo, $line->product_variant_id ?? null);
                }

                if (($product->batch_tracking ?? false) && ($product->affect_stock ?? true)) {
                    ProductExpiryLot::create([
                        'product_id' => (int) $line->product_id,
                        'warehouse_id' => $warehouseId,
                        'purchase_id' => $purchase ? $purchase->id : null,
                        'purchase_product_id' => $row['source_type'] === 'purchase' ? (int) $line->id : null,
                        'source' => 'purchase',
                        'expiry_date' => $row['expiry_date'],
                        'batch_no' => $row['batch_no'],
                        'mfg_date' => $row['mfg_date'],
                        'quantity_in' => $qty,
                        'quantity_remaining' => $qty,
                        'created_by' => $creatorId,
                    ]);
                }

                if (($product->identifier_tracking ?? false) && $row['identifiers'] !== []) {
                    $type = in_array($product->identifier_type ?? 'serial', ['serial', 'mac', 'both'], true)
                        ? ($product->identifier_type ?? 'serial')
                        : 'serial';
                    $warrantyDays = (int) ($product->warranty_days ?? 0);
                    $baseDate = $purchase ? $purchase->purchase_date : ($bill ? $bill->bill_date : null);
                    $warrantyEnd = null;
                    if ($warrantyDays > 0 && ! empty($baseDate)) {
                        $warrantyEnd = \Carbon\Carbon::parse($baseDate)->addDays($warrantyDays)->toDateString();
                    }
                    foreach ($row['identifiers'] as $value) {
                        ProductIdentifier::create([
                            'product_id' => (int) $line->product_id,
                            'warehouse_id' => $warehouseId,
                            'purchase_id' => $purchase ? $purchase->id : null,
                            'purchase_product_id' => $row['source_type'] === 'purchase' ? (int) $line->id : null,
                            'identifier_type' => $type,
                            'identifier_value' => $value,
                            'status' => 'in_stock',
                            'warranty_end_date' => $warrantyEnd,
                            'created_by' => $creatorId,
                        ]);
                    }
                }
            }

            return $note;
        });
    }
}
