<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceProduct;
use App\Models\Pos;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\Transaction;
use App\Models\Utility;
use App\Services\Accounting\ChartAccountResolver;
use App\Services\Accounting\DocumentLedgerService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PosToInvoiceService
{
    /**
     * Create an invoice and payment from a completed POS sale.
     * Does NOT deduct inventory (POS already did that).
     *
     * Sales invoices post the same ledger sides as Accounting
     * invoices (income + AR + payment).
     *
     * Internal Consumption invoices (POS document_type
     * internal_consumption) are a separate series instead: they
     * are numbered in the #INT series (starting at 1), post
     * Dr expense 611 / Cr the product's purchase expense account,
     * and never touch sales income, AR or the bank (option B,
     * Riz 2026-11-03; account 611 confirmed 2026-11-08).
     *
     * @return Invoice|null The created invoice, or null on failure
     */
    public function createInvoiceFromPos(Pos $pos): ?Invoice
    {
        $creatorId = (int) $pos->created_by;
        $isInternal = ($pos->document_type ?? '') === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION;

        try {
            return DB::transaction(function () use ($pos, $creatorId, $isInternal) {
                // Fail fast: an internal invoice cannot be posted
                // without chart account 611. Throwing here rolls
                // the whole transaction back — no partial invoice.
                $consumptionAccountId = $isInternal
                    ? $this->internalConsumptionAccountId($creatorId)
                    : 0;

                $invoiceId = $this->nextInvoiceNumber($creatorId, $isInternal);
                $categoryId = $this->getDefaultIncomeCategoryId($creatorId);
                $customerId = $pos->customer_id ?: 0;

                $pos->load(['posPayments', 'items']);
                $payments = $pos->posPayments instanceof Collection ? $pos->posPayments : collect($pos->posPayments ?: []);
                $hasCredit = $payments->contains(function ($p) {
                    return strtolower((string) ($p->payment_method ?? '')) === 'credit';
                });
                $creditTermsRaw = null;
                if ($hasCredit) {
                    $creditPayment = $payments->first(function ($p) {
                        return strtolower((string) ($p->payment_method ?? '')) === 'credit';
                    });
                    $creditTermsRaw = $creditPayment ? $creditPayment->credit_terms : null;
                }
                $creditDays = $this->parseCreditDays($creditTermsRaw);
                $totalAmount = $payments->isNotEmpty()
                    ? (float) $payments->sum('amount')
                    : (float) $pos->getTotal();
                if ($totalAmount <= 0) {
                    $totalAmount = (float) $pos->getTotal();
                }
                $paidNow = (float) $payments->filter(function ($p) {
                    return strtolower((string) ($p->payment_method ?? '')) !== 'credit';
                })->sum('amount');
                $isCreditSale = $hasCredit && ($paidNow < $totalAmount);

                $invoice = new Invoice();
                $invoice->invoice_id = $invoiceId;
                $invoice->pos_id = $pos->id;
                $invoice->document_type = $isInternal ? Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION : null;
                $invoice->customer_id = $customerId;
                $invoice->issue_date = $pos->pos_date ?? date('Y-m-d');
                $invoice->due_date = $creditDays > 0
                    ? date('Y-m-d', strtotime($invoice->issue_date.' +'.$creditDays.' days'))
                    : $invoice->issue_date;
                // The internal invoice's ref is its displayed #INT
                // number, so the receipt, the invoice and the ledger
                // all name the same document. Sales refs stay as-is.
                $invoice->ref_number = $isInternal
                    ? $this->internalInvoiceDisplayNumber($creatorId, $invoiceId)
                    : 'POS-'.$pos->pos_id;
                $invoice->status = $isCreditSale ? ($paidNow > 0 ? 3 : 2) : 4;
                $invoice->category_id = $categoryId ?: 0;
                $invoice->created_by = $creatorId;
                $invoice->save();

                $posPayment = $payments->isNotEmpty() ? $payments->first() : $pos->posPayment;
                $cartDiscount = $posPayment ? (float) ($posPayment->discount ?? 0) : 0;
                $first = true;
                $ledger = app(DocumentLedgerService::class);

                foreach ($pos->items as $posItem) {
                    $product = $posItem->product;
                    $price = (float) $posItem->price;
                    $qty = (int) $posItem->quantity;
                    $lineDiscount = $first ? $cartDiscount : 0;
                    $first = false;

                    $invoiceProduct = new InvoiceProduct();
                    $invoiceProduct->invoice_id = $invoice->id;
                    $invoiceProduct->product_id = $posItem->product_id;
                    $invoiceProduct->quantity = $qty;
                    $invoiceProduct->tax = $posItem->tax ?? '';
                    $invoiceProduct->discount = $lineDiscount;
                    $invoiceProduct->price = $price;
                    $invoiceProduct->description = ($product && is_object($product)) ? $product->name : '';
                    $invoiceProduct->save();

                    $totalTaxPrice = 0;
                    if ($invoiceProduct->tax != null && $invoiceProduct->tax !== '') {
                        $taxes = Utility::tax($invoiceProduct->tax);
                        foreach ($taxes as $tax) {
                            $totalTaxPrice += Utility::taxRate(
                                $tax->rate,
                                $invoiceProduct->price,
                                $invoiceProduct->quantity,
                                $invoiceProduct->discount
                            );
                        }
                    }
                    $itemAmount = ($invoiceProduct->price * $invoiceProduct->quantity)
                        - $invoiceProduct->discount
                        + $totalTaxPrice;

                    if ($isInternal) {
                        $this->postInternalConsumptionLine(
                            $creatorId,
                            $invoice,
                            $posItem,
                            $product,
                            $itemAmount,
                            $consumptionAccountId
                        );
                    } else {
                        $saleAccountId = 0;
                        if ($product instanceof ProductService) {
                            $saleAccountId = (int) ($product->sale_chartaccount_id ?? 0);
                        } elseif ($posItem->product_id) {
                            $saleAccountId = (int) (ProductService::where('id', $posItem->product_id)->value('sale_chartaccount_id') ?? 0);
                        }

                        if ($saleAccountId > 0 && $itemAmount > 0) {
                            $data = [
                                'account_id' => $saleAccountId,
                                'transaction_type' => 'Credit',
                                'transaction_amount' => $itemAmount,
                                'reference' => 'Invoice',
                                'reference_id' => $invoice->id,
                                'reference_sub_id' => $posItem->product_id,
                                'date' => $invoice->issue_date,
                            ];
                            Utility::addTransactionLines($data, 'create');
                        }
                    }
                }

                // Internal Consumption invoices never touch AR or
                // the bank: no receivable balancing line, no
                // InvoicePayment, no bank balance movement.
                if (! $isInternal) {
                $ledger->balanceDocumentLines(
                    $creatorId,
                    (int) $invoice->id,
                    ['Invoice'],
                    'receivable',
                    'Invoice Receivable',
                    (string) $invoice->issue_date
                );

                $accountId = $this->getDefaultBankAccountId($creatorId);
                $amountToRecord = $isCreditSale ? $paidNow : $totalAmount;
                $paymentDate = $posPayment ? ($posPayment->date ?? date('Y-m-d')) : date('Y-m-d');

                if ($accountId && $amountToRecord > 0) {
                    $invoicePayment = new InvoicePayment();
                    $invoicePayment->invoice_id = $invoice->id;
                    $invoicePayment->date = $paymentDate;
                    $invoicePayment->amount = $amountToRecord;
                    $invoicePayment->account_id = $accountId;
                    $invoicePayment->payment_method = 0;
                    $invoicePayment->reference = 'POS-'.$pos->pos_id;
                    $invoicePayment->description = __('Payment from POS').' #'.$pos->pos_id;
                    $invoicePayment->save();

                    $invoicePayment->user_id = $customerId;
                    $invoicePayment->user_type = 'Customer';
                    $invoicePayment->type = 'Partial';
                    $invoicePayment->created_by = $pos->created_by;
                    $invoicePayment->payment_id = $invoicePayment->id;
                    $invoicePayment->category = 'Invoice';
                    $invoicePayment->account = $accountId;
                    Transaction::addTransaction($invoicePayment);

                    if ($customerId > 0) {
                        Utility::updateUserBalance('customer', $customerId, $amountToRecord, 'credit');
                    }
                    Utility::bankAccountBalance($accountId, $amountToRecord, 'credit');

                    $bankAccount = BankAccount::find($accountId);
                    $bankChartId = $bankAccount ? (int) ($bankAccount->chart_account_id ?? 0) : 0;
                    $ledger->postInvoicePayment(
                        $creatorId,
                        (int) $invoice->id,
                        (int) $invoicePayment->id,
                        (float) $amountToRecord,
                        (string) $invoicePayment->date,
                        $bankChartId > 0 ? $bankChartId : null
                    );
                }
                }

                return $invoice;
            });
        } catch (\Throwable $e) {
            Log::error('PosToInvoiceService: Failed to create invoice from POS.', [
                'pos_id' => $pos->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Next number in a series, under a row lock (the caller runs
     * inside a transaction). Internal invoices count only their
     * own #INT series (starting at 1); sales invoices exclude
     * internal ones, so a sales invoice created after an internal
     * one neither skips a number nor collides. Old internal
     * invoices (document_type NULL) still count for sales.
     */
    public function nextInvoiceNumber(int $creatorId, bool $internal = false): int
    {
        $query = Invoice::where('created_by', $creatorId)->lockForUpdate();

        if ($internal) {
            $query->where('document_type', Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION);
        } else {
            $query->where(function ($q) {
                $q->whereNull('document_type')
                    ->orWhere('document_type', '<>', Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION);
            });
        }

        $latest = $query->orderBy('invoice_id', 'desc')->first();

        return $latest ? (int) $latest->invoice_id + 1 : 1;
    }

    /**
     * Chart account 611 (Cost of Inventories Sold or Consumed)
     * for a company. Throws when the chart has no 611 — an
     * internal invoice must never post half a ledger.
     */
    protected function internalConsumptionAccountId(int $creatorId): int
    {
        $accountId = app(ChartAccountResolver::class)->resolveByCodeOrPrefix($creatorId, '611');

        if (! $accountId) {
            throw new \RuntimeException(
                'Internal Consumption cannot be posted: chart account 611 (Cost of Inventories Sold or Consumed) does not exist for this company. Create it in the Chart of Accounts first.'
            );
        }

        return (int) $accountId;
    }

    /**
     * Displayed #INT number for an internal invoice: the
     * pos_internal_prefix setting (default #INT, shared with POS
     * numbering) + the 5-digit series number.
     */
    protected function internalInvoiceDisplayNumber(int $creatorId, int $invoiceId): string
    {
        $settings = Utility::settingsById($creatorId);
        $prefix = $settings['pos_internal_prefix'] ?? '#INT';

        return ($prefix !== '' ? $prefix : '#INT') . sprintf('%05d', $invoiceId);
    }

    /**
     * Post one internal-consumption line: Dr 611 (Cost of
     * Inventories Sold or Consumed) / Cr the product's purchase
     * expense account — the account the Bill path debits when the
     * product is bought, so the consumed cost is reclassified
     * out of it into 611. NO sales income, NO AR, NO bank.
     *
     * There is no separate stock leg on a POS invoice (stock is
     * deducted by POS in quantities only, with no ledger entry),
     * so the product's purchase expense account is the cost side.
     * If the product has none, the credit goes to 611 itself
     * (net-zero within 611) with a warning, instead of inventing
     * an account — the document stays balanced either way.
     */
    protected function postInternalConsumptionLine(
        int $creatorId,
        Invoice $invoice,
        $posItem,
        $product,
        float $itemAmount,
        int $consumptionAccountId
    ): void {
        if ($itemAmount <= 0) {
            return;
        }

        $costAccountId = 0;
        if ($product instanceof ProductService) {
            $costAccountId = (int) ($product->expense_chartaccount_id ?? 0);
        } elseif ($posItem->product_id) {
            $costAccountId = (int) (ProductService::where('id', $posItem->product_id)->value('expense_chartaccount_id') ?? 0);
        }
        if ($costAccountId <= 0) {
            $costAccountId = $consumptionAccountId;
            Log::warning('PosToInvoiceService: internal consumption line has no purchase expense account; crediting 611 itself.', [
                'invoice_id' => $invoice->id,
                'product_id' => $posItem->product_id,
            ]);
        }

        Utility::addTransactionLines([
            'account_id' => $consumptionAccountId,
            'transaction_type' => 'Debit',
            'transaction_amount' => $itemAmount,
            'reference' => 'Invoice',
            'reference_id' => $invoice->id,
            'reference_sub_id' => $posItem->product_id,
            'date' => $invoice->issue_date,
            'created_by' => $creatorId,
        ], 'create');

        Utility::addTransactionLines([
            'account_id' => $costAccountId,
            'transaction_type' => 'Credit',
            'transaction_amount' => $itemAmount,
            'reference' => 'Invoice',
            'reference_id' => $invoice->id,
            'reference_sub_id' => $posItem->product_id,
            'date' => $invoice->issue_date,
            'created_by' => $creatorId,
        ], 'create');
    }

    protected function getDefaultIncomeCategoryId(int $creatorId): ?int
    {
        $category = ProductServiceCategory::where('created_by', $creatorId)
            ->where('type', 1)
            ->first();

        return $category ? (int) $category->id : null;
    }

    protected function getDefaultBankAccountId(int $creatorId): ?int
    {
        $account = BankAccount::where('created_by', $creatorId)->first();

        return $account ? (int) $account->id : null;
    }

    protected function parseCreditDays(?string $creditTerms): int
    {
        if (empty($creditTerms)) {
            return 0;
        }
        if (preg_match('/^(\d+)\s*(?:day|days)?$/i', trim($creditTerms), $m)) {
            return (int) $m[1];
        }

        return 0;
    }
}
