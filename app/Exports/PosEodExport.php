<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

class PosEodExport implements FromArray, WithColumnWidths, WithStyles
{
    public function __construct(
        public string $companyName,
        public string $vatNumber,
        public string $companyAddress,
        public string $date,
        public float $totalSales,
        public int $receiptsCount,
        public $ticketFrom,
        public $ticketTo,
        public array $paymentMethodRows,
        public array $statementRows,
        public array $salesPerEmployee,
        public array $categorySummary,
        public array $taxSummary,
        public array $itemsSold,
        public $poses
    ) {}

    public function columnWidths(): array
    {
        return [
            'A' => 28,
            'B' => 18,
            'C' => 14,
            'D' => 12,
            'E' => 14,
            'F' => 14,
        ];
    }

    public function array(): array
    {
        $user = \Auth::user();
        $fmt = function ($n) use ($user) {
            return $user ? $user->priceFormat($n) : number_format($n, 2);
        };
        $rows = [];
        $rows[] = ['Company: ' . $this->companyName];
        $rows[] = ['VAT NR: ' . $this->vatNumber];
        $rows[] = ['Address: ' . $this->companyAddress];
        $rows[] = [];
        $rows[] = ['Total Daily: ' . $fmt($this->totalSales) . '  Cash Register: 1'];
        $rows[] = ['Ticket From: ' . sprintf('%09d', $this->ticketFrom ?? 0) . ' To: ' . sprintf('%09d', $this->ticketTo ?? 0)];
        $rows[] = ['Receipts Issued: ' . $this->receiptsCount];
        $rows[] = ['Date: ' . $this->date . '  Time: ' . date('H:i:s')];
        $rows[] = [];
        $rows[] = ['SALES BY PAYMENT METHOD'];
        $rows[] = ['PAYMENT METHOD', 'AMOUNT', 'NO.'];
        foreach ($this->paymentMethodRows as $r) {
            $rows[] = [$r['name'], $fmt($r['amount']), $r['count']];
        }
        $rows[] = [];
        $rows[] = ['STATEMENT BY PAYMENT METHOD'];
        $rows[] = ['N.', 'TICKET', 'COUNT', 'TIME', 'Employee', 'AMOUNT'];
        $n = 1;
        foreach ($this->statementRows as $r) {
            $rows[] = ['T001', $r['ticket'], $r['count'], $r['time'], $r['employee'], $fmt($r['amount'])];
            $n++;
        }
        $rows[] = [];
        $rows[] = ['SALES PER EMPLOYEE'];
        $rows[] = ['Employee', 'AMOUNT', 'N.O'];
        foreach ($this->salesPerEmployee as $r) {
            $rows[] = [$r['name'], $fmt($r['amount']), $r['count']];
        }
        $rows[] = [];
        $rows[] = ['SALES BY SECTION'];
        $rows[] = ['SECTION', 'N. ART', 'AMOUNT', '%'];
        $totalCat = array_sum(array_column($this->categorySummary, 'amount'));
        foreach ($this->categorySummary as $name => $r) {
            $pct = $totalCat > 0 ? round($r['amount'] / $totalCat * 100, 2) . '%' : '0%';
            $rows[] = [$name, $r['qty'], $fmt($r['amount']), $pct];
        }
        $rows[] = [];
        $rows[] = ['TAXES'];
        $rows[] = ['TAX', 'BASE', 'QUOTA', 'TOTAL'];
        foreach ($this->taxSummary as $name => $r) {
            $rows[] = [$name, $fmt($r['base']), $fmt($r['quota']), $fmt($r['total'])];
        }
        $rows[] = [];
        $rows[] = ['SUMMARY OF ITEMS SOLD'];
        $rows[] = ['ITEM', 'QTY', 'AMOUNT'];
        foreach ($this->itemsSold as $name => $r) {
            $rows[] = [$name, $r['qty'], $fmt($r['amount'])];
        }
        return $rows;
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
            2 => ['font' => ['bold' => true]],
        ];
    }
}
