<?php

namespace App\Services\Accounting;

use App\Models\TransactionLines;

/**
 * Posts balanced transaction_lines for sales/purchase documents.
 * Bills/Invoices historically posted only one side — Trial Balance then cannot balance.
 */
class DocumentLedgerService
{
    public function __construct(private ChartAccountResolver $resolver) {}

    public function accountsPayableId(int $createdBy): ?int
    {
        return $this->resolver->resolveByCodeOrPrefix($createdBy, '421')
            ?? $this->resolver->resolveByCodeOrPrefix($createdBy, '42');
    }

    public function accountsReceivableId(int $createdBy): ?int
    {
        return $this->resolver->resolveByCodeOrPrefix($createdBy, '411')
            ?? $this->resolver->resolveByCodeOrPrefix($createdBy, '41');
    }

    /**
     * Write a transaction line with an explicit company id (avoids Auth::creatorId() drift).
     */
    public function writeLine(
        int $createdBy,
        int $accountId,
        string $type,
        float $amount,
        string $reference,
        int $referenceId,
        int $referenceSubId,
        string $date
    ): void {
        if ($accountId <= 0 || abs($amount) < 0.0001) {
            return;
        }

        $line = TransactionLines::query()
            ->where('created_by', $createdBy)
            ->where('reference', $reference)
            ->where('reference_id', $referenceId)
            ->where('reference_sub_id', $referenceSubId)
            ->first() ?? new TransactionLines();

        $line->account_id = $accountId;
        $line->reference = $reference;
        $line->reference_id = $referenceId;
        $line->reference_sub_id = $referenceSubId;
        $line->date = $date;
        $line->debit = $type === 'Debit' ? round($amount, 2) : 0;
        $line->credit = $type === 'Credit' ? round($amount, 2) : 0;
        $line->created_by = $createdBy;
        $line->save();
    }

    /**
     * Ensure a document's transaction_lines debit total equals credit total
     * by posting the missing counterpart to AP (bills) or AR (invoices).
     *
     * @param  list<string>  $references
     */
    public function balanceDocumentLines(
        int $createdBy,
        int $documentId,
        array $references,
        string $counterpartType,
        string $counterpartReference,
        string $date
    ): void {
        $lines = TransactionLines::query()
            ->where('created_by', $createdBy)
            ->where('reference_id', $documentId)
            ->whereIn('reference', $references)
            ->get();

        $debit = round((float) $lines->sum('debit'), 2);
        $credit = round((float) $lines->sum('credit'), 2);
        $diff = round($debit - $credit, 2);

        TransactionLines::query()
            ->where('created_by', $createdBy)
            ->where('reference_id', $documentId)
            ->where('reference', $counterpartReference)
            ->delete();

        if (abs($diff) < 0.009) {
            return;
        }

        $accountId = $counterpartType === 'payable'
            ? $this->accountsPayableId($createdBy)
            : $this->accountsReceivableId($createdBy);

        if (! $accountId) {
            return;
        }

        if ($diff > 0) {
            $this->writeLine($createdBy, $accountId, 'Credit', abs($diff), $counterpartReference, $documentId, 0, $date);
        } else {
            $this->writeLine($createdBy, $accountId, 'Debit', abs($diff), $counterpartReference, $documentId, 0, $date);
        }
    }

    public function postBillPayment(int $createdBy, int $billId, int $paymentId, float $amount, string $date, ?int $bankChartAccountId): void
    {
        TransactionLines::query()
            ->where('created_by', $createdBy)
            ->where('reference_id', $billId)
            ->where('reference_sub_id', $paymentId)
            ->whereIn('reference', ['Bill Payment', 'Bill Payment Bank'])
            ->delete();

        $apId = $this->accountsPayableId($createdBy);
        if ($apId && $amount > 0) {
            $this->writeLine($createdBy, $apId, 'Debit', $amount, 'Bill Payment', $billId, $paymentId, $date);
        }

        if ($bankChartAccountId && $amount > 0) {
            $this->writeLine($createdBy, $bankChartAccountId, 'Credit', $amount, 'Bill Payment Bank', $billId, $paymentId, $date);
        }
    }

    public function postInvoicePayment(int $createdBy, int $invoiceId, int $paymentId, float $amount, string $date, ?int $bankChartAccountId): void
    {
        TransactionLines::query()
            ->where('created_by', $createdBy)
            ->where('reference_id', $invoiceId)
            ->where('reference_sub_id', $paymentId)
            ->whereIn('reference', ['Invoice Payment', 'Invoice Payment AR'])
            ->delete();

        if ($bankChartAccountId && $amount > 0) {
            $this->writeLine($createdBy, $bankChartAccountId, 'Debit', $amount, 'Invoice Payment', $invoiceId, $paymentId, $date);
        }

        $arId = $this->accountsReceivableId($createdBy);
        if ($arId && $amount > 0) {
            $this->writeLine($createdBy, $arId, 'Credit', $amount, 'Invoice Payment AR', $invoiceId, $paymentId, $date);
        }
    }
}
