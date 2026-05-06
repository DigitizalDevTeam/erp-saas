<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DreDemonstracaoResultadosExport implements FromArray, WithStyles, WithColumnWidths, WithCustomStartCell
{
    private const NUMBER_FMT = '#,##0.00';

    public function __construct(
        private readonly array $report,
        private readonly array $filled,
        private readonly string $currency = 'MT',
        private readonly string $unit = 'Euros'
    ) {
    }

    public function startCell(): string
    {
        return 'A1';
    }

    public function columnWidths(): array
    {
        return ['A' => 4, 'B' => 76, 'C' => 16, 'D' => 16];
    }

    public function array(): array
    {
        $y1 = (string) ($this->report['year1'] ?? date('Y'));
        $y2 = (string) ($this->report['year2'] ?? ((int) date('Y') - 1));

        $rows = [];
        $rows[] = ['', 'Exercício:', 'Moeda:', 'Unidade:'];
        $rows[] = ['', $y1, $this->currency, $this->unit];
        $rows[] = ['', 'Demonstração dos Resultados Anual - Comparativa', '', ''];
        $rows[] = ['', 'Contribuinte:', '', ''];
        $rows[] = ['', '', '', ''];

        $rows[] = ['', 'Custos e perdas', $y1, $y2];
        foreach ($this->filled['cost'] as $row) {
            if ($row['style'] === 'heading') {
                $rows[] = ['', $row['label'], '', ''];
                continue;
            }
            $rows[] = ['', $row['label'], $row['y1'], $row['y2']];
        }

        $rows[] = ['', '', '', ''];
        $rows[] = ['', 'Proveitos e ganhos', '', ''];
        foreach ($this->filled['revenue'] as $row) {
            if ($row['style'] === 'heading') {
                $rows[] = ['', $row['label'], '', ''];
                continue;
            }
            $rows[] = ['', $row['label'], $row['y1'], $row['y2']];
        }

        $rows[] = ['', '', '', ''];
        $rows[] = ['', 'Resumo:', '', ''];
        foreach ($this->filled['summary'] as $row) {
            $rows[] = ['', $row['label'], $row['y1'], $row['y2']];
        }

        $rows[] = ['', '', '', ''];
        $rows[] = ['', 'A Gerência', '', 'O Técnico oficial de contas'];
        $rows[] = ['', '______________________________', '', '__________________________________'];

        return $rows;
    }

    public function styles(Worksheet $sheet): void
    {
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('Arial')->setSize(9);
        $lastRow = $sheet->getHighestRow();

        $sheet->mergeCells('B3:D3');
        $sheet->getStyle('B3')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('B3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle('B1:D2')->getFont()->setBold(true);
        $sheet->getStyle('B1:D2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('B4')->getFont()->setBold(true);

        for ($r = 1; $r <= $lastRow; $r++) {
            $label = trim((string) $sheet->getCell("B{$r}")->getValue());
            if ($label === '') {
                continue;
            }

            if ($label === 'Custos e perdas' || $label === 'Proveitos e ganhos' || $label === 'Resumo:') {
                $sheet->getStyle("B{$r}:D{$r}")->getFont()->setBold(true);
                $sheet->getStyle("B{$r}:D{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9D9D9');
                $sheet->getStyle("B{$r}:D{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
                continue;
            }

            if (in_array($label, ['Custos e perdas', 'Proveitos e ganhos'], true)) {
                continue;
            }

            if (str_starts_with($label, 'Sub-total') || str_contains($label, '(A)') || str_contains($label, '(B)') || str_contains($label, '(C)') || str_contains($label, '(D)') || str_contains($label, '(E)') || str_contains($label, '(F)') || str_contains($label, '(G)') || str_contains($label, '(H)') || str_contains($label, 'Resultado líquido')) {
                $sheet->getStyle("B{$r}:D{$r}")->getFont()->setBold(true);
            }

            if (! str_ends_with($label, ':')) {
                $sheet->getStyle("B{$r}:D{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            }

            foreach (['C', 'D'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::NUMBER_FMT);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
        }
    }
}

