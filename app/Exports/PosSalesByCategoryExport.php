<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PosSalesByCategoryExport implements FromArray, WithColumnWidths, WithStyles
{
    public function __construct(
        public array $filter,
        public array $summary,
        public array $rows,
        public array $taxSummary,
        public array $itemsSummary,
    ) {}

    public function array(): array
    {
        $user = \Auth::user();
        $fmt = function ($n) use ($user) {
            return $user ? $user->priceFormat((float) $n) : number_format((float) $n, 2);
        };

        $dash = str_repeat('-', 60);
        $result = [];
        $result[] = [];
        $result[] = ['SALES BY CATEGORY'];
        $result[] = [];
        $result[] = ['CATEGORY', 'N.ITEMS', 'AMOUNT', '%'];
        $result[] = [$dash];
        foreach ($this->rows as $row) {
            $result[] = [
                (string) ($row['category_name'] ?? ''),
                number_format((float) ($row['qty_sold'] ?? 0), 2),
                $fmt($row['amount_sold'] ?? 0),
                number_format((float) ($row['sales_pct'] ?? 0), 2) . '%',
            ];
        }
        $result[] = ['TOTAL', number_format((float) ($this->summary['total_qty'] ?? 0), 2), $fmt($this->summary['total_sales'] ?? 0), '100%'];

        $result[] = [];
        $result[] = [];
        $result[] = ['IMPOSTOS'];
        $result[] = [];
        $result[] = ['', 'BASE IMP.', 'COTA', 'TOTAL'];
        $result[] = [$dash];
        foreach ($this->taxSummary as $name => $row) {
            $result[] = [
                (string) $name,
                number_format((float) ($row['base'] ?? 0), 2, '.', ','),
                number_format((float) ($row['quota'] ?? 0), 2, '.', ','),
                number_format((float) ($row['total'] ?? 0), 2, '.', ','),
            ];
        }

        $result[] = [];
        $result[] = [];
        $result[] = ['SUMMARY OF ITEMS SOLD'];
        $result[] = [];
        $result[] = ['PRODUCT', 'QTY', 'AMOUNT'];
        $result[] = [$dash];
        foreach ($this->itemsSummary as $name => $row) {
            $result[] = [
                (string) $name,
                number_format((float) ($row['qty'] ?? 0), 2),
                (float) ($row['amount'] ?? 0) > 0 ? $fmt($row['amount']) : '-',
            ];
        }
        $result[] = ['TOTAL', number_format((float) ($this->summary['total_qty'] ?? 0), 2), $fmt($this->summary['total_sales'] ?? 0)];

        return $result;
    }

    public function columnWidths(): array
    {
        return [
            'A' => 34,
            'B' => 14,
            'C' => 18,
            'D' => 10,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $categoryCount = count($this->rows);
        $taxCount = count($this->taxSummary);
        $itemsCount = count($this->itemsSummary);

        $title1 = 2;
        $header1 = 4;
        $total1 = 6 + $categoryCount;

        $title2 = $total1 + 3;
        $header2 = $title2 + 2;

        $taxStart = $header2 + 2;
        $taxEnd = $taxStart + max(0, $taxCount - 1);

        $title3 = $taxEnd + 3;
        $header3 = $title3 + 2;
        $total3 = $header3 + 2 + $itemsCount;

        $sheet->mergeCells('A' . $title1 . ':D' . $title1);
        $sheet->mergeCells('A' . $title2 . ':D' . $title2);
        $sheet->mergeCells('A' . $title3 . ':D' . $title3);

        return [
            $title1 => ['font' => ['bold' => true, 'size' => 20], 'alignment' => ['horizontal' => 'center']],
            $header1 => ['font' => ['bold' => true]],
            $total1 => ['font' => ['bold' => true]],
            $title2 => ['font' => ['bold' => true, 'size' => 20], 'alignment' => ['horizontal' => 'center']],
            $header2 => ['font' => ['bold' => true]],
            $title3 => ['font' => ['bold' => true, 'size' => 20], 'alignment' => ['horizontal' => 'center']],
            $header3 => ['font' => ['bold' => true]],
            $total3 => ['font' => ['bold' => true]],
        ];
    }
}

