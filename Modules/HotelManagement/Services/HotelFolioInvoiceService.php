<?php

namespace Modules\HotelManagement\Services;

use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceProduct;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\Tax;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelAccountingSetting;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelFolioLine;

class HotelFolioInvoiceService
{
    public function __construct(
        private readonly HotelGuestCrmSyncService $crmSyncService,
        private readonly HotelFolioAccountingService $accountingService,
    ) {
    }

    public function createInvoiceFromFolio(HotelFolio $folio, ?int $creatorId = null): ?Invoice
    {
        if (! Schema::hasColumn('hotel_folios', 'invoice_id')) {
            return null;
        }

        $folio->refresh();
        if ($folio->invoice_id) {
            return Invoice::where('created_by', $folio->created_by)->find($folio->invoice_id);
        }

        $creatorId = $creatorId ?? (int) $folio->created_by;
        $grandTotal = round((float) $folio->grand_total, 2);
        if ($grandTotal <= 0) {
            return null;
        }

        $folio->loadMissing(['lines', 'payments', 'stay.reservation.guest']);

        try {
            return DB::transaction(function () use ($folio, $creatorId) {
                $folio->refresh();
                if ($folio->invoice_id) {
                    return Invoice::find($folio->invoice_id);
                }

                $guest = $folio->stay?->reservation?->guest;
                $customerId = 0;
                if ($guest) {
                    $customer = $this->crmSyncService->syncGuest($guest, $creatorId);
                    $customerId = $customer ? (int) $customer->id : 0;
                }

                $settings = $this->accountingService->settingsFor($creatorId);
                $paidTotal = round((float) $folio->paid_total, 2);
                $grandTotal = round((float) $folio->grand_total, 2);

                $status = 2;
                if ($paidTotal >= $grandTotal - 0.01) {
                    $status = 4;
                } elseif ($paidTotal > 0) {
                    $status = 3;
                }

                $issueDate = now()->format('Y-m-d');
                if ($folio->stay?->checked_out_at) {
                    $issueDate = $folio->stay->checked_out_at->format('Y-m-d');
                }

                $invoice = new Invoice();
                $invoice->invoice_id = $this->nextInvoiceNumber($creatorId);
                $invoice->customer_id = $customerId;
                $invoice->issue_date = $issueDate;
                $invoice->due_date = $issueDate;
                $invoice->ref_number = 'HOTEL-' . $folio->folio_no;
                $invoice->status = $status;
                $invoice->category_id = $this->defaultIncomeCategoryId($creatorId) ?: 0;
                $invoice->created_by = $creatorId;
                $invoice->save();

                $productId = $this->folioLineProductId($creatorId);

                foreach ($folio->lines as $line) {
                    $this->createInvoiceProductFromFolioLine($invoice, $line, $productId, $settings, $creatorId);
                }

                foreach ($folio->payments as $folioPayment) {
                    $bankId = $settings?->bankAccountIdForFolioPaymentMethod((string) $folioPayment->method)
                        ?? $this->defaultBankAccountId($creatorId);
                    if (! $bankId) {
                        continue;
                    }

                    $invoicePayment = new InvoicePayment();
                    $invoicePayment->invoice_id = $invoice->id;
                    $invoicePayment->date = $folioPayment->created_at
                        ? $folioPayment->created_at->format('Y-m-d')
                        : $issueDate;
                    $invoicePayment->amount = round((float) $folioPayment->amount, 2);
                    $invoicePayment->account_id = $bankId;
                    $invoicePayment->payment_method = 0;
                    $invoicePayment->reference = $folioPayment->reference ?: $folio->folio_no;
                    $invoicePayment->description = __('Hotel folio payment') . ' — ' . $folio->folio_no;
                    $invoicePayment->save();
                }

                $folio->invoice_id = $invoice->id;
                $folio->save();

                return $invoice;
            });
        } catch (\Throwable $e) {
            Log::error('HotelFolioInvoiceService: failed to create invoice from folio.', [
                'folio_id' => $folio->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function createInvoiceProductFromFolioLine(
        Invoice $invoice,
        HotelFolioLine $line,
        int $productId,
        ?HotelAccountingSetting $settings,
        int $creatorId
    ): void {
        $qty = max(1, (int) $line->quantity);
        $discount = round((float) $line->discount_amount, 2);
        [$unitPrice, $taxId] = $this->netUnitPriceAndTax($line, $settings, $creatorId);

        $invoiceProduct = new InvoiceProduct();
        $invoiceProduct->invoice_id = $invoice->id;
        $invoiceProduct->product_id = $productId;
        $invoiceProduct->quantity = $qty;
        $invoiceProduct->tax = $taxId;
        $invoiceProduct->discount = $discount;
        $invoiceProduct->price = $unitPrice;
        $invoiceProduct->description = (string) $line->description;
        $invoiceProduct->save();
    }

    /**
     * @return array{0: float, 1: string}
     */
    private function netUnitPriceAndTax(HotelFolioLine $line, ?HotelAccountingSetting $settings, int $creatorId): array
    {
        $qty = max(1, (int) $line->quantity);
        $defaultRate = (float) ($settings?->default_vat_rate_percent ?? 0);

        if ((float) $line->tax_amount > 0.001) {
            $netLine = max(0, round((float) $line->line_total - (float) $line->tax_amount, 2));
            $net = max(0.01, ((float) $line->quantity * (float) $line->unit_price) - (float) $line->discount_amount);
            $effectiveRate = round(((float) $line->tax_amount / $net) * 100, 2);
            $unitPrice = round($netLine / $qty, 4);

            return [$unitPrice, (string) $this->resolveTaxIdByRate($creatorId, $effectiveRate)];
        }

        if ($defaultRate > 0) {
            $grossLine = max(0, round((float) $line->line_total, 2));
            $netLine = round($grossLine / (1 + ($defaultRate / 100)), 2);
            $unitPrice = round($netLine / $qty, 4);

            return [$unitPrice, (string) $this->resolveTaxIdByRate($creatorId, $defaultRate)];
        }

        $netLine = max(0, round((float) $line->line_total, 2));
        $unitPrice = $qty > 0 ? round($netLine / $qty, 4) : round((float) $line->unit_price, 4);

        return [$unitPrice, ''];
    }

    private function resolveTaxIdByRate(int $creatorId, float $rate): int
    {
        $tax = Tax::where('created_by', $creatorId)
            ->whereRaw('ABS(rate - ?) < 0.05', [$rate])
            ->first();

        if ($tax) {
            return (int) $tax->id;
        }

        $fallback = Tax::where('created_by', $creatorId)->orderBy('id')->first();

        return $fallback ? (int) $fallback->id : 0;
    }

    private function folioLineProductId(int $creatorId): int
    {
        $sku = '__hotel_folio_service__';
        $existing = ProductService::where('created_by', $creatorId)->where('sku', $sku)->first();
        if ($existing) {
            return (int) $existing->id;
        }

        $product = new ProductService();
        $product->name = __('Hotel service');
        $product->sku = $sku;
        $product->sale_price = 0;
        $product->purchase_price = 0;
        $product->type = 'service';
        $product->category_id = 0;
        $product->unit_id = 0;
        $product->created_by = $creatorId;
        $product->save();

        return (int) $product->id;
    }

    private function nextInvoiceNumber(int $creatorId): int
    {
        $latest = Invoice::where('created_by', $creatorId)->orderByDesc('invoice_id')->first();

        return $latest ? ((int) $latest->invoice_id + 1) : 1;
    }

    private function defaultIncomeCategoryId(int $creatorId): ?int
    {
        $category = ProductServiceCategory::where('created_by', $creatorId)
            ->where('type', 1)
            ->first();

        return $category ? (int) $category->id : null;
    }

    private function defaultBankAccountId(int $creatorId): ?int
    {
        $account = BankAccount::where('created_by', $creatorId)->first();

        return $account ? (int) $account->id : null;
    }
}
