<?php

namespace App\Exports;

use App\Models\InvoiceProduct;
use App\Models\StockReport;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class ProductStockExport implements FromCollection, WithHeadings
{
    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        $data = [];
        $data = StockReport::where('created_by', \Auth::user()->creatorId())->orderByDesc('id')->get();
        if (!empty($data)) {
            foreach ($data as $k => $Stock) {
                // $product  = $Stock->product_id;
                $product = StockReport::products($Stock->product_id);
                // unset($Stock->id,$Stock->created_by,$Stock->updated_at,$Stock->type_id);
                // $data[$k]["product_id"]        = $product;

                $formattedDate = $Stock->created_at->format('d M Y');
                $identifiers = '';
                if ($Stock->type === 'invoice' && ! empty($Stock->type_id)) {
                    $lines = InvoiceProduct::where('invoice_id', $Stock->type_id)
                        ->where('product_id', $Stock->product_id)
                        ->whereNotNull('identifiers')
                        ->where('identifiers', '!=', '')
                        ->pluck('identifiers');
                    if ($lines->isNotEmpty()) {
                        $identifiers = $lines->implode('; ');
                    }
                }
                $data[$k] = [
                    'product_name' => $product,
                    'quantity' => $Stock->quantity,
                    'type' => ucfirst($Stock->type),
                    'serial_mac' => $identifiers,
                    'description' => $Stock->description,
                    'date' => $formattedDate,
                ];
            }
        }
        return collect($data);
    }

    public function headings(): array
    {
        return [
            "Product Name",
            "Quantity",
            "Type",
            "Serial / MAC",
            "Description",
            "Date",
        ];
    }
}
