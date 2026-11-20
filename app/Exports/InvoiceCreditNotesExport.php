<?php
namespace App\Exports;

use App\Models\CreditNote;
use App\Models\CreditNoteRefund;
use App\Models\CustomerCreditLedger;
use App\Models\Invoice;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class InvoiceCreditNotesExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        $invoices = Invoice::with('creditNote')
            ->where('created_by', auth()->user()->creatorId())
            ->get();

        // Phase 3: per-note refund + credit-use for the export columns.
        $refundsByNote = CreditNoteRefund::where('created_by', auth()->user()->creatorId())
            ->get()
            ->keyBy('credit_note_id');
        $creditUsedByNote = CustomerCreditLedger::where('created_by', auth()->user()->creatorId())
            ->where('type', CustomerCreditLedger::TYPE_USED)
            ->selectRaw('credit_note_id, SUM(amount * -1) as used')
            ->groupBy('credit_note_id')
            ->pluck('used', 'credit_note_id');

        $rows = collect();

        foreach ($invoices as $invoice) {
            foreach ($invoice->creditNote as $creditNote) {
                $refund = $refundsByNote[$creditNote->id] ?? null;
                $rows->push([
                    'invoice_no' => auth()->user()->invoiceNumberFormat($invoice->invoice_id),
                    'amount' => $creditNote->amount ?? '0.00',
                    'date' => $creditNote->date ? \Carbon\Carbon::parse($creditNote->date)->format('Y-m-d') : '-',
                    'description' => $creditNote->description ?? '-',
                    'status' => CreditNote::STATUS_LABELS[$creditNote->status ?? CreditNote::STATUS_APPROVED] ?? '-',
                    'type' => $creditNote->disposition === 'refund' ? 'Refund' : ($creditNote->disposition === 'credit' ? 'Keep as credit' : '-'),
                    'refunded' => $refund ? (string) $refund->amount : '-',
                    'credit_used' => isset($creditUsedByNote[$creditNote->id]) ? (string) $creditUsedByNote[$creditNote->id] : '-',
                ]);
            }
        }
        return $rows;
    }

    public function headings(): array
    {
        return [
            "Invoice No",
            "Amount",
            "Date",
            "Description",
            "Status",
            "Type",
            "Refunded",
            "Credit Used",
        ];
    }
}
