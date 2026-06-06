<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceProduct;
use App\Models\ProductIdentifier;
use App\Models\ProductKitItem;
use App\Models\ProductService;
use App\Models\StockReport;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Support\Facades\Auth;

class StockRestoreService
{
    public static function restoreInvoiceStock(Invoice $invoice, int $creatorId, ?int $warehouseId = null): bool
    {
        if (empty($invoice->stock_deducted_at)) {
            return false;
        }

        $user = User::find($creatorId);
        $invoiceNo = $user ? $user->invoiceNumberFormat($invoice->invoice_id) : (string) $invoice->invoice_id;
        $restored = false;

        foreach ($invoice->items as $line) {
            if (self::restoreInvoiceLine($line, $creatorId, (int) $invoice->id, $invoiceNo, $warehouseId)) {
                $restored = true;
            }
        }

        return $restored;
    }

    public static function restoreInvoiceLine(
        InvoiceProduct $line,
        int $creatorId,
        int $invoiceId,
        string $invoiceNoFormatted,
        ?int $warehouseId = null
    ): bool {
        $quantity = (float) $line->quantity;
        if ($quantity <= 0) {
            return false;
        }

        $product = ProductService::where('id', $line->product_id)->where('created_by', $creatorId)->first();
        if (! $product) {
            return false;
        }

        $selectedOptions = ProductOptionService::normalizeSelectedOptions($line->selected_options_json);
        if (($product->options_enabled ?? false) && $selectedOptions !== []) {
            ProductOptionService::restoreOptionsForVoid(
                $selectedOptions,
                $quantity,
                $warehouseId ?? 0,
                $creatorId,
                'invoice_void',
                __('Stock returned on invoice void') . ' ' . $invoiceNoFormatted,
                $invoiceId
            );

            return true;
        }

        if (($product->identifier_tracking ?? false)) {
            ProductIdentifier::where('created_by', $creatorId)
                ->where('product_id', $line->product_id)
                ->where('invoice_id', $invoiceId)
                ->where('invoice_product_id', $line->id)
                ->update([
                    'status' => 'in_stock',
                    'customer_id' => null,
                    'sold_at' => null,
                    'invoice_id' => null,
                    'invoice_product_id' => null,
                    'updated_at' => now(),
                ]);
        }

        if (($product->type ?? null) === 'kit') {
            $items = ProductKitItem::where('kit_product_id', $line->product_id)->where('created_by', $creatorId)->get();
            foreach ($items as $kitItem) {
                $componentQty = (float) $kitItem->quantity * $quantity;
                if ($componentQty > 0) {
                    self::restoreSimpleProduct(
                        (int) $kitItem->component_product_id,
                        $componentQty,
                        $creatorId,
                        $invoiceId,
                        $invoiceNoFormatted,
                        $warehouseId
                    );
                }
            }

            return true;
        }

        if (! ($product->affect_stock ?? true)) {
            return false;
        }

        self::restoreSimpleProduct(
            (int) $line->product_id,
            $quantity,
            $creatorId,
            $invoiceId,
            $invoiceNoFormatted,
            $warehouseId,
            InvoiceSaleDeductionService::formatIdentifiersNote($line->identifiers)
        );

        return true;
    }

    public static function restoreBillStock($bill, int $creatorId, ?int $warehouseId = null): bool
    {
        if ($bill->suppress_inventory ?? false) {
            return false;
        }

        $user = User::find($creatorId);
        $billNo = $user ? $user->billNumberFormat($bill->bill_id) : (string) $bill->bill_id;
        $restored = false;

        foreach ($bill->items as $line) {
            $qty = (float) $line->quantity;
            if ($qty <= 0) {
                continue;
            }

            Utility::total_quantity('minus', $qty, $line->product_id);

            if ($warehouseId) {
                Utility::warehouse_quantity('minus', $qty, (int) $line->product_id, $warehouseId);
            }

            $description = $qty . ' ' . __('quantity reversed on bill void') . ' ' . $billNo;
            self::addStockReport((int) $line->product_id, $qty, 'bill_void', $description, (int) $bill->id, $creatorId);
            $restored = true;
        }

        return $restored;
    }

    protected static function restoreSimpleProduct(
        int $productId,
        float $quantity,
        int $creatorId,
        int $documentId,
        string $documentNoFormatted,
        ?int $warehouseId = null,
        string $extraDescriptionSuffix = ''
    ): void {
        $product = ProductService::where('id', $productId)->where('created_by', $creatorId)->first();
        if (! $product) {
            return;
        }

        $original = (float) ($product->quantity ?? 0);
        ProductService::where('id', $productId)->update(['quantity' => $original + $quantity]);

        if ($warehouseId) {
            Utility::warehouse_quantity('plus', $quantity, $productId, $warehouseId);
        }

        $description = $quantity . ' ' . __('quantity returned on invoice void') . ' ' . $documentNoFormatted . $extraDescriptionSuffix;
        self::addStockReport($productId, $quantity, 'invoice_void', $description, $documentId, $creatorId);
    }

    protected static function addStockReport(
        int $productId,
        float $quantity,
        string $type,
        string $description,
        int $typeId,
        int $creatorId
    ): void {
        $report = new StockReport();
        $report->product_id = $productId;
        $report->quantity = $quantity;
        $report->type = $type;
        $report->type_id = $typeId;
        $report->description = $description;
        $report->created_by = Auth::check() ? Auth::user()->creatorId() : $creatorId;
        $report->save();
    }
}
