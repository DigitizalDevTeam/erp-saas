<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\DocumentVoidLog;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Transaction;
use App\Models\TransactionLines;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DocumentVoidService
{
    public static function voidInvoice(Invoice $invoice, array $options = []): DocumentVoidLog
    {
        if ((int) $invoice->status === 5) {
            throw new \RuntimeException(__('Invoice already cancelled.'));
        }

        $creatorId = (int) $invoice->created_by;
        $settings = Utility::settingsById($creatorId);
        $user = Auth::user();

        $reason = trim((string) ($options['reason'] ?? ''));
        $stockMode = $options['stock_mode'] ?? ($settings['document_void_restore_stock'] ?? 'auto');
        $accountingMode = $options['accounting_mode'] ?? ($settings['document_void_credit_note'] ?? 'auto');
        $warehouseId = self::resolveWarehouseId($options, $settings, $stockMode);

        return DB::transaction(function () use ($invoice, $creatorId, $user, $reason, $stockMode, $accountingMode, $warehouseId, $settings) {
            $stockRestored = false;
            $noteCreated = false;
            $noteId = null;
            $noteType = null;

            if ($stockMode !== 'off') {
                if ($stockMode === 'manual' && ! $warehouseId) {
                    throw new \RuntimeException(__('Please select a warehouse to return stock.'));
                }
                $invoice->load('items');
                $stockRestored = StockRestoreService::restoreInvoiceStock(
                    $invoice,
                    $creatorId,
                    $warehouseId ?: null
                );
            }

            foreach ($invoice->payments as $payment) {
                Utility::bankAccountBalance($payment->account_id, $payment->amount, 'debit');
                $transaction = Transaction::where('payment_id', $payment->id)->first();
                if ($transaction) {
                    $transaction->delete();
                }
                InvoicePayment::where('id', $payment->id)->delete();
            }

            if ($invoice->customer_id != 0 && $invoice->status != 0) {
                Utility::updateUserBalance('customer', $invoice->customer_id, $invoice->getDue(), 'debit');
            }

            TransactionLines::where('reference_id', $invoice->id)->where('reference', 'Invoice')->delete();
            TransactionLines::where('reference_id', $invoice->id)->where('reference', 'Invoice Payment')->delete();

            $remainingCredit = $invoice->getTotal() - $invoice->invoiceTotalCreditNote();
            $shouldCreateNote = $accountingMode === 'auto'
                || ($accountingMode === 'manual' && ! empty($options['create_credit_note']));

            if ($shouldCreateNote && $remainingCredit > 0) {
                $creditNote = new CreditNote();
                $creditNote->invoice = $invoice->id;
                $creditNote->customer = $invoice->customer_id;
                $creditNote->amount = $remainingCredit;
                $creditNote->date = date('Y-m-d');
                $creditNote->description = $reason !== ''
                    ? $reason
                    : __('Auto-generated on document cancellation');
                $creditNote->created_by = $creatorId;
                $creditNote->save();
                $noteCreated = true;
                $noteId = (int) $creditNote->id;
                $noteType = 'credit_note';
            }

            $invoice->status = 5;
            $invoice->voided_at = now();
            $invoice->voided_by = $user ? $user->id : null;
            $invoice->void_reason = $reason ?: null;
            $invoice->save();

            $formatter = User::find($creatorId);

            return DocumentVoidLog::create([
                'created_by' => $creatorId,
                'user_id' => $user ? $user->id : null,
                'document_type' => DocumentVoidLog::TYPE_INVOICE,
                'document_id' => $invoice->id,
                'document_number' => $formatter ? $formatter->invoiceNumberFormat($invoice->invoice_id) : (string) $invoice->invoice_id,
                'reason' => $reason ?: null,
                'warehouse_id' => $warehouseId ?: null,
                'stock_restored' => $stockRestored,
                'accounting_note_created' => $noteCreated,
                'accounting_note_id' => $noteId,
                'accounting_note_type' => $noteType,
                'meta' => [
                    'stock_mode' => $stockMode,
                    'accounting_mode' => $accountingMode,
                ],
            ]);
        });
    }

    public static function voidBill(Bill $bill, array $options = []): DocumentVoidLog
    {
        if ((int) $bill->status === 5) {
            throw new \RuntimeException(__('Bill already cancelled.'));
        }

        $creatorId = (int) $bill->created_by;
        $settings = Utility::settingsById($creatorId);
        $user = Auth::user();

        $reason = trim((string) ($options['reason'] ?? ''));
        $stockMode = $options['stock_mode'] ?? ($settings['document_void_restore_stock'] ?? 'auto');
        $accountingMode = $options['accounting_mode'] ?? ($settings['document_void_credit_note'] ?? 'auto');
        $warehouseId = self::resolveWarehouseId($options, $settings, $stockMode);

        return DB::transaction(function () use ($bill, $creatorId, $user, $reason, $stockMode, $accountingMode, $warehouseId) {
            $stockRestored = false;
            $noteCreated = false;
            $noteId = null;
            $noteType = null;

            if ($stockMode !== 'off') {
                if ($stockMode === 'manual' && ! $warehouseId) {
                    throw new \RuntimeException(__('Please select a warehouse to return stock.'));
                }
                $bill->load('items');
                $stockRestored = StockRestoreService::restoreBillStock(
                    $bill,
                    $creatorId,
                    $warehouseId ?: null
                );
            }

            foreach ($bill->payments as $payment) {
                Utility::bankAccountBalance($payment->account_id, $payment->amount, 'credit');
                $transaction = Transaction::where('payment_id', $payment->id)->first();
                if ($transaction) {
                    $transaction->delete();
                }
                BillPayment::where('id', $payment->id)->delete();
            }

            if ($bill->vender_id != 0 && $bill->status != 0) {
                Utility::updateUserBalance('vendor', $bill->vender_id, $bill->getDue(), 'credit');
            }

            TransactionLines::where('reference_id', $bill->id)->where('reference', 'Bill')->delete();
            TransactionLines::where('reference_id', $bill->id)->where('reference', 'Bill Account')->delete();
            TransactionLines::where('reference_id', $bill->id)->where('reference', 'Bill Payment')->delete();

            $remainingCredit = $bill->getTotal() - $bill->billTotalCreditNote();
            $shouldCreateNote = $accountingMode === 'auto'
                || ($accountingMode === 'manual' && ! empty($options['create_debit_note']));

            if ($shouldCreateNote && $remainingCredit > 0) {
                $debit = new DebitNote();
                $debit->bill = $bill->id;
                $debit->vendor = $bill->vender_id;
                $debit->date = date('Y-m-d');
                $debit->amount = $remainingCredit;
                $debit->description = $reason !== ''
                    ? $reason
                    : __('Auto-generated on document cancellation');
                $debit->created_by = $creatorId;
                $debit->is_credit = 1;
                $debit->save();
                $noteCreated = true;
                $noteId = (int) $debit->id;
                $noteType = 'debit_note';
            }

            $bill->status = 5;
            $bill->voided_at = now();
            $bill->voided_by = $user ? $user->id : null;
            $bill->void_reason = $reason ?: null;
            $bill->save();

            $formatter = User::find($creatorId);

            return DocumentVoidLog::create([
                'created_by' => $creatorId,
                'user_id' => $user ? $user->id : null,
                'document_type' => DocumentVoidLog::TYPE_BILL,
                'document_id' => $bill->id,
                'document_number' => $formatter ? $formatter->billNumberFormat($bill->bill_id) : (string) $bill->bill_id,
                'reason' => $reason ?: null,
                'warehouse_id' => $warehouseId ?: null,
                'stock_restored' => $stockRestored,
                'accounting_note_created' => $noteCreated,
                'accounting_note_id' => $noteId,
                'accounting_note_type' => $noteType,
                'meta' => [
                    'stock_mode' => $stockMode,
                    'accounting_mode' => $accountingMode,
                ],
            ]);
        });
    }

    public static function logPosLineVoid(int $lineId, int $posId, string $posNumber, ?string $reason = null, ?int $warehouseId = null): DocumentVoidLog
    {
        $user = Auth::user();

        return DocumentVoidLog::create([
            'created_by' => $user ? $user->creatorId() : 0,
            'user_id' => $user ? $user->id : null,
            'document_type' => DocumentVoidLog::TYPE_POS_LINE,
            'document_id' => $lineId,
            'document_number' => $posNumber,
            'reason' => $reason,
            'warehouse_id' => $warehouseId,
            'stock_restored' => false,
            'accounting_note_created' => false,
            'meta' => ['pos_id' => $posId],
        ]);
    }

    protected static function resolveWarehouseId(array $options, array $settings, string $stockMode): int
    {
        if (! empty($options['warehouse_id'])) {
            return (int) $options['warehouse_id'];
        }

        if ($stockMode === 'auto') {
            return (int) ($settings['document_void_default_warehouse_id'] ?? 0);
        }

        return 0;
    }
}
