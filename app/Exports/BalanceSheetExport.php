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

class BalanceSheetExport implements FromArray, WithStyles, WithColumnWidths, WithCustomStartCell
{
    private const GRAY_HEADER = 'D9D9D9';
    private const GRAND_TOTAL = 'E8E8E8';
    private const NUMBER_FMT  = '#,##0.00';

    // First data row inside the table (after meta-header block rows 1-7)
    private const TABLE_START = 8;
    private const SIGNATURE_ROWS = 2;

    protected array $rows;
    protected array $totals;
    protected array $header;
    protected array $filter;

    public function __construct(array $rows, array $totals, array $header, array $filter)
    {
        $this->rows   = $rows;
        $this->totals = $totals;
        $this->header = $header;
        $this->filter = $filter;
    }

    public function startCell(): string { return 'A1'; }

    public function columnWidths(): array
    {
        return ['A' => 4, 'B' => 42, 'C' => 8, 'D' => 16, 'E' => 16];
    }

    // ── Sheet data ─────────────────────────────────────────────────────────────
    public function array(): array
    {
        $cy = $this->filter['currentYear']  ?? 'Current Year';
        $py = $this->filter['previousYear'] ?? 'Previous Year';

        $data = [];

        // Rows 1-7: meta header (outside the table)
        $data[] = ['', $this->header['company_name'] ?? '', '', '', ''];          // 1
        $data[] = ['', '', '', 'Moeda:',        $this->header['currency'] ?? '']; // 2
        $data[] = ['', '', '', 'Contribuinte:', $this->header['nuit'] ?? ''];     // 3
        $data[] = ['', '', '', '', ''];                                            // 4
        $data[] = ['', 'Balanço', '', '', ''];                                    // 5
        $data[] = ['', '', '', '', ''];                                            // 6
        $data[] = ['', '', '', '', ''];                                            // 7

        // ── TABLE starts at row 8 ──────────────────────────────────────────────

        // Row 8: column headers
        $data[] = ['', 'RUBRICAS', 'Notas', $cy, $py];

        // Assets
        $data[] = ['', 'ACTIVOS', '', null, null];
        $data[] = ['', 'Activos não correntes', '', null, null];
        foreach ($this->rows as $row) {
            if ($row['section'] === 'Assets') {
                $data[] = ['', $row['label'], '', $row['current_year'], $row['previous_year']];
            }
        }
        $data[] = ['', 'Total das activos', '',
            $this->totals['assets']['current'],
            $this->totals['assets']['previous'],
        ];

        // Equity & Liabilities — NO spacer row, table is continuous
        $data[] = ['', 'CAPITAL PRÓPRIO E PASSIVOS', '', null, null];
        $data[] = ['', 'Capital Próprio', '', null, null];
        foreach ($this->rows as $row) {
            if ($row['section'] === 'Equity') {
                $data[] = ['', $row['label'], '', $row['current_year'], $row['previous_year']];
            }
        }
        $data[] = ['', 'Total do capital próprio', '',
            $this->totals['equity']['current'],
            $this->totals['equity']['previous'],
        ];
        $data[] = ['', 'Passivos não correntes', '', null, null];
        $data[] = ['', 'Passivos correntes', '', null, null];
        foreach ($this->rows as $row) {
            if ($row['section'] === 'Liabilities') {
                $data[] = ['', $row['label'], '', $row['current_year'], $row['previous_year']];
            }
        }
        $data[] = ['', 'Total dos passivos', '',
            $this->totals['liabilities']['current'],
            $this->totals['liabilities']['previous'],
        ];
        $data[] = ['', 'Total do capital próprio e dos passivos', '',
            $this->totals['equity_liabilities']['current'],
            $this->totals['equity_liabilities']['previous'],
        ];

        // Signature lines — outside the table
        $data[] = ['', '', '', '', ''];
        $data[] = ['', 'A Administração/Gerência ___________________________', '', '', ''];
        $data[] = ['', 'O Técnico oficial de contas ___________________________', '', '', ''];

        return $data;
    }

    // ── Styles ─────────────────────────────────────────────────────────────────
    public function styles(Worksheet $sheet): void
    {
        $sheet->getParent()->getDefaultStyle()->getFont()->setName('Arial')->setSize(9);
        $this->styleMetaHeader($sheet);
        $tableEnd = max(self::TABLE_START, $sheet->getHighestRow() - (self::SIGNATURE_ROWS + 1));
        $this->styleTable($sheet, self::TABLE_START, $tableEnd);
        $this->styleSignatures($sheet, $tableEnd + 2);
    }

    // ─── Rows 1-7: outside the table ──────────────────────────────────────────
    private function styleMetaHeader(Worksheet $sheet): void
    {
        $sheet->mergeCells('B1:E1');
        $sheet->getStyle('B1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('B1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getRowDimension(1)->setRowHeight(22);

        $sheet->getStyle('D2')->getFont()->setBold(true);
        $sheet->getStyle('D2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('D3')->getFont()->setBold(true);
        $sheet->getStyle('D3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $sheet->mergeCells('B5:E5');
        $sheet->getStyle('B5')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('B5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getRowDimension(5)->setRowHeight(20);
    }

    // ─── Single pass over all table rows ──────────────────────────────────────
    private function styleTable(Worksheet $sheet, int $start, int $end): void
    {
        $med    = Border::BORDER_MEDIUM;
        $thin   = Border::BORDER_THIN;
        $dbl    = Border::BORDER_DOUBLE;

        // Collect row labels → row numbers so we can identify types
        $labelMap = [];
        for ($r = $start; $r <= $end; $r++) {
            $labelMap[$r] = trim((string) $sheet->getCell("B{$r}")->getValue());
        }

        for ($r = $start; $r <= $end; $r++) {
            $label = $labelMap[$r];
            $sheet->getRowDimension($r)->setRowHeight(15);

            // Keep vertical borders continuous for full table (fixes broken lines in middle columns).
            $sheet->getStyle("B{$r}")->getBorders()->getLeft()->setBorderStyle($med);
            $sheet->getStyle("B{$r}")->getBorders()->getRight()->setBorderStyle($thin);
            $sheet->getStyle("C{$r}")->getBorders()->getLeft()->setBorderStyle($thin);
            $sheet->getStyle("C{$r}")->getBorders()->getRight()->setBorderStyle($thin);
            $sheet->getStyle("D{$r}")->getBorders()->getLeft()->setBorderStyle($thin);
            $sheet->getStyle("D{$r}")->getBorders()->getRight()->setBorderStyle($thin);
            $sheet->getStyle("E{$r}")->getBorders()->getLeft()->setBorderStyle($thin);
            $sheet->getStyle("E{$r}")->getBorders()->getRight()->setBorderStyle($med);

            // ── Column header row ────────────────────────────────────────────
            if ($r === $start) {
                $sheet->getRowDimension($r)->setRowHeight(20);
                $sheet->getStyle("B{$r}:E{$r}")->applyFromArray([
                    'font'      => ['bold' => true],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::GRAY_HEADER]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getStyle("B{$r}")->getBorders()->applyFromArray(['top'=>['borderStyle'=>$med],'bottom'=>['borderStyle'=>$med],'left'=>['borderStyle'=>$med],'right'=>['borderStyle'=>$thin]]);
                $sheet->getStyle("C{$r}")->getBorders()->applyFromArray(['top'=>['borderStyle'=>$med],'bottom'=>['borderStyle'=>$med],'left'=>['borderStyle'=>$thin],'right'=>['borderStyle'=>$thin]]);
                $sheet->getStyle("D{$r}")->getBorders()->applyFromArray(['top'=>['borderStyle'=>$med],'bottom'=>['borderStyle'=>$med],'left'=>['borderStyle'=>$thin],'right'=>['borderStyle'=>$thin]]);
                $sheet->getStyle("E{$r}")->getBorders()->applyFromArray(['top'=>['borderStyle'=>$med],'bottom'=>['borderStyle'=>$med],'left'=>['borderStyle'=>$thin],'right'=>['borderStyle'=>$med]]);
                continue;
            }

            // ── Section header ────────────────────────────────────────────────
            if ($this->isSectionHeader($label)) {
                $sheet->getStyle("B{$r}:E{$r}")->applyFromArray([
                    'font'      => ['bold' => true],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::GRAY_HEADER]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
                $sheet->getStyle("B{$r}:E{$r}")->getBorders()->getTop()->setBorderStyle($thin);
                $sheet->getStyle("B{$r}:E{$r}")->getBorders()->getBottom()->setBorderStyle($thin);
                continue;
            }

            // ── Sub-section header ────────────────────────────────────────────
            if ($this->isSubSectionHeader($label)) {
                $sheet->getStyle("B{$r}:E{$r}")->getFont()->setBold(true);
                $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                $sheet->getStyle("B{$r}:E{$r}")->getBorders()->getTop()->setBorderStyle($thin);
                $sheet->getStyle("B{$r}:E{$r}")->getBorders()->getBottom()->setBorderStyle($thin);
                continue;
            }

            // ── Grand total ───────────────────────────────────────────────────
            if ($this->isGrandTotal($label)) {
                $sheet->getStyle("B{$r}:E{$r}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::GRAND_TOTAL]],
                ]);
                $sheet->getStyle("B{$r}:E{$r}")->getBorders()->getTop()->setBorderStyle($thin);
                $sheet->getStyle("B{$r}:E{$r}")->getBorders()->getBottom()->setBorderStyle($dbl);
                $this->numFmt($sheet, $r);
                continue;
            }

            // ── Section totals ────────────────────────────────────────────────
            if ($this->isTotalRow($label)) {
                $sheet->getStyle("B{$r}:E{$r}")->getFont()->setBold(true);
                $sheet->getStyle("B{$r}:E{$r}")->getBorders()->getTop()->setBorderStyle($thin);
                $sheet->getStyle("B{$r}:E{$r}")->getBorders()->getBottom()->setBorderStyle($dbl);
                $this->numFmt($sheet, $r);
                continue;
            }

            // ── Regular item row ──────────────────────────────────────────────
            $sheet->getStyle("B{$r}")->getAlignment()->setIndent(1);
            $this->numFmt($sheet, $r);
        }
    }

    private function styleSignatures(Worksheet $sheet, int $startRow): void
    {
        for ($r = $startRow; $r < $startRow + self::SIGNATURE_ROWS; $r++) {
            $sheet->getRowDimension($r)->setRowHeight(22);
            $sheet->getStyle("B{$r}")->getFont()->setSize(9);
            $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle("B{$r}:E{$r}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_NONE);
        }
    }

    private function numFmt(Worksheet $sheet, int $row): void
    {
        foreach (['D', 'E'] as $col) {
            $sheet->getStyle("{$col}{$row}")->getNumberFormat()->setFormatCode(self::NUMBER_FMT);
            $sheet->getStyle("{$col}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
    }

    private function isSectionHeader(string $l): bool
    {
        return in_array($l, ['ACTIVOS', 'CAPITAL PRÓPRIO E PASSIVOS'], true);
    }

    private function isSubSectionHeader(string $l): bool
    {
        return in_array($l, [
            'Activos não correntes', 'Activos correntes',
            'Capital Próprio', 'Passivos não correntes', 'Passivos correntes',
        ], true);
    }

    private function isTotalRow(string $l): bool
    {
        return in_array($l, ['Total das activos', 'Total do capital próprio', 'Total dos passivos'], true);
    }

    private function isGrandTotal(string $l): bool
    {
        return str_contains($l, 'Total do capital próprio e dos passivos');
    }

}