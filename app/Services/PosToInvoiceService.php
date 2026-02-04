<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceProduct;
use App\Models\Pos;
use App\Models\ProductServiceCategory;
use App\Models\Transaction;
use App\Models\Utility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PosToInvoiceService
{
    /**
     * Create an invoice and payment from a completed POS sale.
     * Does NOT deduct inventory (POS already did that).
     *
     * @param Pos $pos The completed POS sale
     * @return Invoice|null The created invoice, or null on failure
     */
    public function createInvoiceFromPos(Pos $pos): ?Invoice
    {
        $creatorId = $pos->created_by;

        try {
            return DB::transaction(function () use ($pos, $creatorId) {
                $invoiceId = $this->nextInvoiceNumber($creatorId);
                $categoryId = $this->getDefaultIncomeCategoryId($creatorId);
                $customerId = $pos->customer_id ?: 0;

                $invoice = new Invoice();
                $invoice->invoice_id = $invoiceId;
                $invoice->pos_id = $pos->id;
                $invoice->customer_id = $customerId;
                $invoice->issue_date = $pos->pos_date ?? date('Y-m-d');
                $invoice->due_date = $invoice->issue_date;
                $invoice->ref_number = 'POS-' . $pos->pos_id;
                $invoice->status = 4; // Paid
                $invoice->category_id = $categoryId ?: 0;
                $invoice->created_by = $creatorId;
                $invoice->save();

                $posPayment = $pos->posPayment;
                $cartDiscount = $posPayment ? (float) ($posPayment->discount ?? 0) : 0;
                $first = true;

                foreach ($pos->items as $posItem) {
                    $product = $posItem->product();
                    $price = (float) $posItem->price;
                    $qty = (int) $posItem->quantity;
                    $subtotal = $price * $qty;
                    $lineDiscount = $first ? $cartDiscount : 0;
                    $first = false;

                    $invoiceProduct = new InvoiceProduct();
                    $invoiceProduct->invoice_id = $invoice->id;
                    $invoiceProduct->product_id = $posItem->product_id;
                    $invoiceProduct->quantity = $qty;
                    $invoiceProduct->tax = $posItem->tax ?? '';
                    $invoiceProduct->discount = $lineDiscount;
                    $invoiceProduct->price = $price;
                    $invoiceProduct->description = is_object($product) ? $product->name : '';
                    $invoiceProduct->save();
                }

                $totalAmount = $posPayment ? (float) $posPayment->discount_amount : (float) $pos->getTotal();
                $accountId = $this->getDefaultBankAccountId($creatorId);

                if ($accountId) {
                    $invoicePayment = new InvoicePayment();
                    $invoicePayment->invoice_id = $invoice->id;
                    $invoicePayment->date = $posPayment ? ($posPayment->date ?? date('Y-m-d')) : date('Y-m-d');
                    $invoicePayment->amount = $totalAmount;
                    $invoicePayment->account_id = $accountId;
                    $invoicePayment->payment_method = 0;
                    $invoicePayment->reference = 'POS-' . $pos->pos_id;
                    $invoicePayment->description = __('Payment from POS') . ' #' . $pos->pos_id;
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
                        Utility::updateUserBalance('customer', $customerId, $totalAmount, 'credit');
                    }
                    Utility::bankAccountBalance($accountId, $totalAmount, 'credit');

                    $bankAccount = BankAccount::find($accountId);
                    if ($bankAccount && $bankAccount->chart_account_id) {
                        $data = [
                            'account_id' => $bankAccount->chart_account_id,
                            'transaction_type' => 'Debit',
                            'transaction_amount' => $totalAmount,
                            'reference' => 'Invoice Payment',
                            'reference_id' => $invoice->id,
                            'reference_sub_id' => $invoicePayment->id,
                            'date' => $invoicePayment->date,
                        ];
                        Utility::addTransactionLines($data, 'create');
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

    protected function nextInvoiceNumber(int $creatorId): int
    {
        $latest = Invoice::where('created_by', $creatorId)->orderBy('invoice_id', 'desc')->first();
        return $latest ? (int) $latest->invoice_id + 1 : 1;
    }

    protected function getDefaultIncomeCategoryId(int $creatorId): ?int
    {
        $category = ProductServiceCategory::where('created_by', $creatorId)
            ->where('type', 1) // Income
            ->first();
        return $category ? (int) $category->id : null;
    }

    protected function getDefaultBankAccountId(int $creatorId): ?int
    {
        $account = BankAccount::where('created_by', $creatorId)->first();
        return $account ? (int) $account->id : null;
    }
}
