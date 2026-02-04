<?php
namespace App\Exports;

use App\Models\Bill;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class BillCreditNotesExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        $bills = Bill::with('creditNote')
            ->where('created_by', auth()->user()->creatorId())
            ->get();

        $rows = collect();

        foreach ($bills as $bill) {
            foreach ($bill->creditNote as $creditNote) {
                $rows->push([
                    'bill_no' => auth()->user()->billNumberFormat($bill->bill_id),
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
            "Bill No",
            "Amount",
            "Date",
            "Description",
        ];
    }
}

