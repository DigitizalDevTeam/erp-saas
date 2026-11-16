<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\DocumentVoidLog;
use App\Models\Invoice;
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
        // Extracted here (not read inside the closure): $options is not
        // in the transaction closure's use list, so reading it there
        // would silently evaluate as empty and the manual-mode
        // checkbox would never fire.
        $createCreditNote = ! empty($options['create_credit_note']);
        // The stock was taken from the recorded warehouse; restore
        // goes back there (auto mode), not to the configured default.
        $warehouseId = self::resolveWarehouseId($options, $settings, $stockMode, (int) ($invoice->stock_deducted_warehouse_id ?? 0));

        return DB::transaction(function () use ($invoice, $creatorId, $user, $reason, $stockMode, $accountingMode, $createCreditNote, $warehouseId, $settings) {
            $stockRestored = false;
            $noteCreated = false;
            $noteId = null;
            $noteType = null;

            // Amounts are computed BEFORE anything changes: getDue()
            // must see the payments as they are, and the invoice is
            // not voided yet (getDue() returns 0 for a voided invoice).
            $dueBeforeVoid = $invoice->getDue();
            $totalPaid = (float) $invoice->payments->sum('amount');

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

            // Payments are KEPT for history: reverse the bank balance
            // once each and mark the row voided instead of deleting
            // it. The payment's Transaction row is kept too; only the
            // ledger lines are removed below.
            foreach ($invoice->payments as $payment) {
                if (! empty($payment->voided_at)) {
                    continue;
                }
                Utility::bankAccountBalance($payment->account_id, $payment->amount, 'debit');
                $payment->voided_at = now();
                $payment->save();
            }

            // The invoice no longer exists, so the customer no longer
            // owes its unpaid part: credit the remaining due. A fully
            // paid invoice has due 0 here, so nothing moves.
            if ($invoice->customer_id != 0 && $invoice->status != 0 && $dueBeforeVoid != 0) {
                Utility::updateUserBalance('customer', $invoice->customer_id, $dueBeforeVoid, 'credit');
            }

            // Credit notes post no ledger entries of their own, so the
            // invoice's lines must still be removed to keep the ledger
            // correct after the void.
            TransactionLines::where('reference_id', $invoice->id)->where('reference', 'Invoice')->delete();
            TransactionLines::where('reference_id', $invoice->id)->where('reference', 'Invoice Payment')->delete();

            // Credit note: in auto mode only for money actually
            // received — an unpaid invoice gets no credit note, a
            // partially paid one gets a note for the paid amount
            // (capped at what is still un-credited). Manual mode
            // keeps the old checkbox behaviour.
            $remainingCredit = $invoice->getTotal() - $invoice->invoiceTotalCreditNote();
            if ($accountingMode === 'auto') {
                $noteAmount = min($totalPaid, $remainingCredit);
                $shouldCreateNote = $noteAmount > 0;
            } else {
                $noteAmount = $remainingCredit;
                $shouldCreateNote = $createCreditNote;
            }

            if ($shouldCreateNote && $noteAmount > 0) {
                $creditNote = new CreditNote();
                $creditNote->uid = self::nextCreditNoteUid($creatorId);
                $creditNote->is_debit = 0;
                $creditNote->invoice = $invoice->id;
                $creditNote->customer = $invoice->customer_id;
                $creditNote->amount = $noteAmount;
                $creditNote->date = date('Y-m-d');
                $creditNote->description = $reason !== ''
                    ? $reason
                    : __('Auto-generated on document cancellation');
                $creditNote->created_by = $creatorId;
                $creditNote->save();
                $noteCreated = true;
                $noteId = (int) $creditNote->id;
                $noteType = 'credit_note';

                // Mirror a manually created credit note, which debits
                // the customer balance by the note amount (the refund
                // now owed to the customer).
                if ($invoice->customer_id != 0) {
                    Utility::updateUserBalance('customer', $invoice->customer_id, $noteAmount, 'debit');
                }
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
        // A delivery-driven bill must not be voided away from its
        // deliveries: voiding would not reverse the delivered stock
        // (restoreBillStock only reverses ordered bill stock), so
        // refuse rather than leave delivered stock stranded.
        if (\App\Services\PurchaseDeliveryService::tablesAvailable()
            && $bill->deliveryNotes()->exists()) {
            throw new \RuntimeException(__('Cannot void a bill that already has delivery notes.'));
        }

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

        return DB::transaction(function () use ($bill, $creatorId, $user, $reason, $stockMode, $accountingMode, $warehouseId, $options) {
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

    /**
     * Next sequential credit-note number for a company.
     *
     * Same rule as CreditNoteController (latest uid + 1, default 1),
     * but ordered by id instead of created_at so ties in the same
     * second cannot pick the wrong row. Called inside the void
     * transaction with a row lock, so two concurrent voids serialize
     * on the latest credit note and cannot take the same number.
     */
    protected static function nextCreditNoteUid(int $creatorId): int
    {
        $latest = CreditNote::where('created_by', $creatorId)
            ->lockForUpdate()
            ->orderByDesc('uid')
            ->first();

        return $latest ? (int) $latest->uid + 1 : 1;
    }

    protected static function resolveWarehouseId(array $options, array $settings, string $stockMode, int $recordedWarehouseId = 0): int
    {
        if (! empty($options['warehouse_id'])) {
            return (int) $options['warehouse_id'];
        }

        if ($stockMode === 'auto') {
            // The stock was actually taken from the recorded
            // warehouse; return it there. Fall back to the
            // configured default for older invoices that recorded none.
            if ($recordedWarehouseId > 0) {
                return $recordedWarehouseId;
            }

            return (int) ($settings['document_void_default_warehouse_id'] ?? 0);
        }

        return 0;
    }
}
