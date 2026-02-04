<?php
namespace App\Exports;

use App\Models\Bill;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class BillDebitNotesExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        $bills = Bill::with('debitNote')
            ->where('created_by', auth()->user()->creatorId())
            ->get();

        $rows = collect();

        foreach ($bills as $bill) {
            foreach ($bill->debitNote as $debitNote) {
                $rows->push([
                    'bill_no' => auth()->user()->billNumberFormat($bill->bill_id),
                    'amount' => $debitNote->amount ?? '0.00',
                    'date' => $debitNote->date ? \Carbon\Carbon::parse($debitNote->date)->format('Y-m-d') : '-',
                    'description' => $debitNote->description ?? '-',
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

