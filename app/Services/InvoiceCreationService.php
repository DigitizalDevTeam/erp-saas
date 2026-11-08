<?php

namespace App\Services;

use App\Models\CustomField;
use App\Models\Invoice;
use App\Models\InvoiceProduct;
use App\Models\Pos;
use App\Models\ProductService;
use App\Models\Proforma;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\StockReport;
use App\Models\Utility;
use App\Services\Accounting\DocumentLedgerService;
use App\Support\MoneyAmount;
use Illuminate\Support\Facades\DB;

/**
 * Contract Management Phase 2 — the ONE invoice creation path.
 *
 * Extracted from InvoiceController::store() (same fields, same
 * values, same stock-deduction behaviour) so a manual invoice and
 * a recurring invoice are created by the same code. Both callers
 * go through here; there is no second invoice path.
 *
 * Invoice numbering: the next number is allocated inside the same
 * database transaction as the invoice, under a row lock on the
 * company's latest invoice (plan risk 1). Two creators at the same
 * moment — a manual invoice and a recurring run, or two recurring
 * workers — serialize on that lock instead of taking the same
 * number. nextInvoiceNumber() MUST be called inside a transaction;
 * createInvoice() already runs inside one.
 *
 * The ledger posting (postInvoiceToLedger) mirrors
 * InvoiceController::releaseInvoiceToAccount() line for line: one
 * Credit line per invoice product to the product's sale account
 * (or a per-line override, used by the recurring engine for the
 * contract line's chart account), then
 * DocumentLedgerService::balanceDocumentLines() for the receivable.
 * The controller's release path now calls this method too.
 */
class InvoiceCreationService
{
    /**
     * Next invoice number for a company, under a row lock.
     * MUST be called inside a database transaction.
     */
    public function nextInvoiceNumber(int $creatorId): int
    {
        // Sales series only: internal-consumption invoices live
        // in their own #INT series (document_type
        // internal_consumption) and must not make sales numbers
        // skip or collide. Old internal invoices (document_type
        // NULL) still count for sales — they were issued in the
        // sales series and stay there.
        $latest = Invoice::where('created_by', $creatorId)
            ->where(function ($q) {
                $q->whereNull('document_type')
                    ->orWhere('document_type', '<>', Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION);
            })
            ->lockForUpdate()
            ->orderByDesc('invoice_id')
            ->first();

        return $latest ? ((int) $latest->invoice_id + 1) : 1;
    }

    /**
     * Create an invoice + lines exactly like InvoiceController::store().
     *
     * $data: customer_id, issue_date, due_date, category_id,
     * ref_number, billing_address_id, shipping_address_id,
     * client_po_number, client_po_date, client_po_details,
     * quotation_reference, items[] (item|product_id, quantity, tax,
     * discount, price, description, product_variant_id, identifiers,
     * measurement_json, selected_options_json), customField (array),
     * source_proforma_id, source_quotation_id, source_purchase_id.
     *
     * Amounts are stored as given — the caller parses them
     * (MoneyAmount::parse for web input, exact 4-decimal strings
     * for the recurring engine, whose discounts carry proration).
     * The invoice is created in status 0 (draft); posting to the
     * ledger is a separate step (postInvoiceToLedger), same as the
     * manual flow where creation and release are separate.
     */
    public function createInvoice(int $creatorId, array $data): Invoice
    {
        return DB::transaction(function () use ($creatorId, $data) {
            $invoice = new Invoice();
            $invoice->invoice_id = $this->nextInvoiceNumber($creatorId);
            $invoice->customer_id = $data['customer_id'];
            $invoice->status = Invoice::STATUS_DRAFT;
            $invoice->issue_date = $data['issue_date'];
            $invoice->due_date = $data['due_date'];
            $invoice->category_id = $data['category_id'];
            $invoice->ref_number = $data['ref_number'] ?? null;
            $invoice->client_po_number = $data['client_po_number'] ?? null;
            $invoice->client_po_date = $data['client_po_date'] ?? null;
            $invoice->client_po_details = $data['client_po_details'] ?? null;
            $invoice->quotation_reference = $data['quotation_reference'] ?? null;
            $invoice->billing_address_id = $data['billing_address_id'] ?? null;
            $invoice->shipping_address_id = $data['shipping_address_id'] ?? null;
            $invoice->created_by = $creatorId;
            $invoice->quotation_id = null;
            $invoice->proforma_id = null;
            $invoice->purchase_id = null;

            if (! empty($data['source_proforma_id'])) {
                $pf = Proforma::where('id', (int) $data['source_proforma_id'])
                    ->where('created_by', $creatorId)->first();
                if ($pf) {
                    $invoice->proforma_id = $pf->id;
                    if ($pf->quotation_id) {
                        $invoice->quotation_id = $pf->quotation_id;
                    }
                }
            }
            if (! empty($data['source_quotation_id']) && empty($invoice->quotation_id)) {
                $q = Quotation::where('id', (int) $data['source_quotation_id'])
                    ->where('created_by', $creatorId)->first();
                if ($q) {
                    $invoice->quotation_id = $q->id;
                }
            }
            if (! empty($data['source_purchase_id'])) {
                $purchase = Purchase::where('id', (int) $data['source_purchase_id'])
                    ->where('created_by', $creatorId)->first();
                if ($purchase) {
                    $invoice->purchase_id = $purchase->id;
                    if (empty($invoice->proforma_id) && ! empty($purchase->proforma_id)) {
                        $invoice->proforma_id = $purchase->proforma_id;
                    }
                    if (empty($invoice->quotation_id) && ! empty($purchase->quotation_id)) {
                        $invoice->quotation_id = $purchase->quotation_id;
                    }
                }
            }
            if (empty($invoice->quotation_reference) && ! empty($invoice->quotation_id)) {
                $q = Quotation::find($invoice->quotation_id);
                if ($q) {
                    $settings = Utility::settingsById($creatorId);
                    $invoice->quotation_reference = ($settings['quotation_prefix'] ?? '#Cot')
                        . sprintf('%05d', (int) $q->quotation_id);
                }
            }
            $invoice->save();

            if (! empty($data['customField'])) {
                CustomField::saveData($invoice, $data['customField']);
            }

            StockReport::where('type', '=', 'invoice')
                ->where('type_id', '=', $invoice->id)->delete();

            $settings = Utility::settingsById($creatorId);
            $timing = $settings['stock_deduction_timing'] ?? 'invoice';
            $formattedNumber = ($settings['invoice_prefix'] ?? '#Fatura')
                . sprintf('%05d', (int) $invoice->invoice_id);

            foreach ($data['items'] as $p) {
                $productId = (int) ($p['item'] ?? $p['product_id']);
                $quantity = (float) ($p['quantity'] ?? 0);

                InvoiceSaleDeductionService::validateTrackedIdentifiersForLine(
                    $productId,
                    $quantity,
                    $creatorId,
                    $p['identifiers'] ?? null
                );

                $invoiceProduct = new InvoiceProduct();
                $invoiceProduct->invoice_id = $invoice->id;
                $invoiceProduct->product_id = $productId;
                $invoiceProduct->product_variant_id = ! empty($p['product_variant_id'])
                    ? (int) $p['product_variant_id'] : null;
                $invoiceProduct->quantity = $p['quantity'] ?? 0;
                $invoiceProduct->tax = $p['tax'] ?? null;
                $invoiceProduct->discount = $p['discount'] ?? 0;
                $invoiceProduct->price = $p['price'] ?? 0;
                $invoiceProduct->description = $p['description'] ?? null;
                $invoiceProduct->identifiers = $p['identifiers'] ?? null;
                $invoiceProduct->measurement_json = $p['measurement_json'] ?? null;
                $invoiceProduct->selected_options_json = ! empty($p['selected_options_json'])
                    ? (is_string($p['selected_options_json'])
                        ? $p['selected_options_json']
                        : json_encode($p['selected_options_json']))
                    : null;
                $invoiceProduct->save();

                if ($timing === 'invoice') {
                    InvoiceSaleDeductionService::deductLine(
                        $productId,
                        $quantity,
                        $creatorId,
                        (int) $invoice->id,
                        (int) $invoiceProduct->id,
                        (int) $invoice->customer_id,
                        $formattedNumber,
                        $invoiceProduct->identifiers,
                        $invoiceProduct->selected_options_json
                    );
                }
            }

            if ($timing === 'invoice' && empty($invoice->stock_deducted_at)) {
                $invoice->stock_deducted_at = now();
                $invoice->stock_deducted_via = 'invoice';
                $invoice->save();
            }

            if (! empty($invoice->purchase_id)) {
                Purchase::where('id', (int) $invoice->purchase_id)
                    ->where('created_by', $creatorId)
                    ->update([
                        'converted_invoice_id' => $invoice->id,
                        'is_invoice_converted' => 1,
                    ]);
            }
            if (! empty($invoice->proforma_id)) {
                Proforma::where('id', (int) $invoice->proforma_id)
                    ->where('created_by', $creatorId)
                    ->update([
                        'converted_invoice_id' => $invoice->id,
                        'is_invoice_converted' => 1,
                    ]);
            }
            if (! empty($invoice->quotation_id)) {
                Quotation::where('id', (int) $invoice->quotation_id)
                    ->where('created_by', $creatorId)
                    ->update([
                        'converted_invoice_id' => $invoice->id,
                    ]);
            }

            return $invoice->fresh();
        });
    }

    /**
     * Post an invoice's lines to the ledger, exactly like
     * InvoiceController::releaseInvoiceToAccount() did: one Credit
     * line per invoice product, then the receivable balancing line.
     * Idempotent — already-posted invoices are left alone.
     *
     * $accountOverrides maps invoice_product_id => chart account id
     * (the recurring engine passes the contract line's chart
     * account; without an override the product's sale account is
     * used, as before).
     */
    public function postInvoiceToLedger(Invoice $invoice, int $creatorId, array $accountOverrides = []): void
    {
        $alreadyPosted = \App\Models\TransactionLines::where('reference_id', $invoice->id)
            ->whereIn('reference', ['Invoice', 'Invoice Receivable'])
            ->exists();

        if ($alreadyPosted) {
            return;
        }

        $invoiceProducts = InvoiceProduct::where('invoice_id', $invoice->id)->get();
        foreach ($invoiceProducts as $invoiceProduct) {
            $product = ProductService::find($invoiceProduct->product_id);
            if (! $product) {
                continue;
            }
            $optionsAmount = ProductOptionService::calculateOptionsAmount(
                ProductOptionService::normalizeSelectedOptions($invoiceProduct->selected_options_json ?? null)
            );
            $taxableBase = ($invoiceProduct->price * $invoiceProduct->quantity)
                - ($invoiceProduct->discount) + $optionsAmount;
            $totalTaxPrice = 0;
            if ($invoiceProduct->tax != null) {
                $taxes = Utility::tax($invoiceProduct->tax);
                foreach ($taxes as $tax) {
                    if ($tax) {
                        $taxPrice = $taxableBase * ($tax->rate / 100);
                        $totalTaxPrice += $taxPrice;
                    }
                }
            }

            $itemAmount = $taxableBase + $totalTaxPrice;

            Utility::addTransactionLines([
                'account_id' => $accountOverrides[(int) $invoiceProduct->id]
                    ?? $product->sale_chartaccount_id,
                'transaction_type' => 'Credit',
                'transaction_amount' => $itemAmount,
                'reference' => 'Invoice',
                'reference_id' => $invoice->id,
                'reference_sub_id' => $product->id,
                'date' => $invoice->issue_date,
                'created_by' => $creatorId,
            ], 'create');
        }

        app(DocumentLedgerService::class)->balanceDocumentLines(
            $creatorId,
            (int) $invoice->id,
            ['Invoice'],
            'receivable',
            'Invoice Receivable',
            (string) $invoice->issue_date
        );
    }
}
