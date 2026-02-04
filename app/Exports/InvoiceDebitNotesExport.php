<?php
namespace App\Exports;

use App\Models\Invoice;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class InvoiceDebitNotesExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        $invoices = Invoice::with('salesDebitNote')
            ->where('created_by', auth()->user()->creatorId())
            ->get();

        $rows = collect();

        foreach ($invoices as $invoice) {
            foreach ($invoice->salesDebitNote as $salesDebitNote) {
                $rows->push([
                    'invoice_no' => auth()->user()->invoiceNumberFormat($invoice->invoice_id),
                    'amount' => $salesDebitNote->amount ?? '0.00',
                    'date' => $salesDebitNote->date ? \Carbon\Carbon::parse($salesDebitNote->date)->format('Y-m-d') : '-',
                    'description' => $salesDebitNote->description ?? '-',
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
        ];
    }
}

