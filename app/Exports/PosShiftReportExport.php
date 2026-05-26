<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class PosShiftReportExport implements FromArray, WithColumnWidths, WithStyles
{
    public function __construct(
        public array $filter,
        public array $summary,
        public array $shiftRows,
        public array $aggregates,
    ) {}

    public function columnWidths(): array
    {
        return [
            'A' => 14,
            'B' => 22,
            'C' => 18,
            'D' => 18,
            'E' => 18,
            'F' => 12,
            'G' => 14,
            'H' => 14,
            'I' => 14,
            'J' => 14,
            'K' => 14,
            'L' => 14,
        ];
    }

    public function array(): array
    {
        $user = \Auth::user();
        $fmt = function ($n) use ($user) {
            return $user ? $user->priceFormat($n) : number_format((float) $n, 2);
        };

        $rows = [];
        $rows[] = [__('pos.shift_report')];
        $rows[] = [__('Start Date'), $this->filter['start_date'], __('End Date'), $this->filter['end_date']];
        $rows[] = [];
        $rows[] = [__('Shifts'), $this->summary['shift_count'], __('Sales Total'), $fmt($this->summary['sales_total'])];
        $rows[] = [__('Cash'), $fmt($this->summary['cash_total']), __('Card'), $fmt($this->summary['card_total']), __('Credit'), $fmt($this->summary['credit_total'])];
        $rows[] = [__('Cash drops'), $fmt($this->summary['cash_drops_total']), __('Difference (closed)'), $fmt($this->summary['difference_total'])];
        $rows[] = [];
        $rows[] = [__('Shifts')];
        $rows[] = [
            __('Shift'),
            __('Branch'),
            __('pos.shift_user'),
            __('Opened At'),
            __('Closed At'),
            __('Status'),
            __('Opening Balance'),
            __('Sales Total'),
            __('Cash'),
            __('Card'),
            __('Credit'),
            __('Expected cash'),
            __('Difference'),
        ];
        foreach ($this->shiftRows as $r) {
            $rows[] = [
                $r['id'],
                $r['branch'],
                $r['cashier'],
                $r['opened'],
                $r['closed'],
                $r['status'],
                $fmt($r['opening']),
                $fmt($r['sales']),
                $fmt($r['cash']),
                $fmt($r['card']),
                $fmt($r['credit']),
                $fmt($r['expected_cash']),
                $r['difference'] !== null ? $fmt($r['difference']) : '-',
            ];
        }
        $rows[] = [];
        $rows[] = [__('Payment Summary')];
        $rows[] = [__('Payment Method'), __('Amount'), __('Count')];
        foreach ($this->aggregates['paymentsByMethod'] ?? [] as $pm) {
            $rows[] = [$pm['name'], $fmt($pm['amount']), $pm['count']];
        }
        $rows[] = [];
        $rows[] = [__('Products sold')];
        $rows[] = [__('Product'), __('Qty'), __('Amount')];
        foreach ($this->aggregates['products'] ?? [] as $p) {
            $rows[] = [$p['name'], $p['quantity'], $fmt($p['amount'])];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            8 => ['font' => ['bold' => true]],
        ];
    }
}
