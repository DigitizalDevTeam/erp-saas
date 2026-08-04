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
use App\Services\Accounting\DocumentLedgerService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PosToInvoiceService
{
    /**
     * Create an invoice and payment from a completed POS sale.
     * Does NOT deduct inventory (POS already did that).
     * Posts the same ledger sides as Accounting invoices (income + AR + payment).
     *
     * @return Invoice|null The created invoice, or null on failure
     */
    public function createInvoiceFromPos(Pos $pos): ?Invoice
    {
        $creatorId = (int) $pos->created_by;

        try {
            return DB::transaction(function () use ($pos, $creatorId) {
                $invoiceId = $this->nextInvoiceNumber($creatorId);
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
                $invoice->customer_id = $customerId;
                $invoice->issue_date = $pos->pos_date ?? date('Y-m-d');
                $invoice->due_date = $creditDays > 0
                    ? date('Y-m-d', strtotime($invoice->issue_date.' +'.$creditDays.' days'))
                    : $invoice->issue_date;
                $invoice->ref_number = 'POS-'.$pos->pos_id;
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

    protected function nextInvoiceNumber(int $creatorId): int
    {
        $latest = Invoice::where('created_by', $creatorId)->orderBy('invoice_id', 'desc')->first();

        return $latest ? (int) $latest->invoice_id + 1 : 1;
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
