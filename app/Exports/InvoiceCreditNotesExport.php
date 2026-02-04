<?php
namespace App\Exports;

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

        $rows = collect();

        foreach ($invoices as $invoice) {
            foreach ($invoice->creditNote as $creditNote) {
                $rows->push([
                    'invoice_no' => auth()->user()->invoiceNumberFormat($invoice->invoice_id),
                    'amount' => $creditNote->amount ?? '0.00',
                    'date' => $creditNote->date ? \Carbon\Carbon::parse($creditNote->date)->format('Y-m-d') : '-',
                    'description' => $creditNote->description ?? '-',
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

