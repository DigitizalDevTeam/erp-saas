<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Real Excel (.xlsx) exports for the management reports, built with
 * PhpSpreadsheet like the VAT report (MonthlyVatReportController).
 * Amounts are written as numbers (not text) so the file can be
 * summed and checked in Excel.
 */
class ManagementReportExport
{
    /** Month headers, as in the file (JAN–DEZ). */
    public const MONTHS = ['JAN', 'FEV', 'MAR', 'ABR', 'MAI', 'JUN', 'JUL', 'AGO', 'SET', 'OUT', 'NOV', 'DEZ'];

    public static function download(Spreadsheet $spreadsheet, string $fileName): StreamedResponse
    {
        return response()->streamDownload(function () use ($spreadsheet): void {
            \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx')->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /** P&L (Management): one row per line, months + TOTAL + AVG. */
    public static function pl(array $lines, int $year): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("P&L {$year}");
        $sheet->fromArray(array_merge(['P&L (Management)'], self::MONTHS, ['TOTAL', 'AVG']), null, 'A1');
        $row = 2;
        foreach ($lines as $line) {
            $values = [$line['label']];
            for ($m = 1; $m <= 12; $m++) {
                $values[] = round((float) ($line['months'][$m] ?? 0), 2);
            }
            $values[] = round((float) $line['total'], 2);
            $values[] = round((float) ($line['avg'] ?? 0), 2);
            $sheet->fromArray($values, null, "A{$row}");
            $row++;
        }

        return $spreadsheet;
    }

    /** %P&L (Management): money rows as amounts, % rows as percents. */
    public static function plPercent(array $lines, int $year): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("%P&L {$year}");
        $sheet->fromArray(array_merge(['%P&L (Management)'], self::MONTHS, ['TOTAL']), null, 'A1');
        $row = 2;
        foreach ($lines as $line) {
            $values = [$line['label']];
            for ($m = 1; $m <= 12; $m++) {
                $values[] = round((float) ($line['months'][$m] ?? 0), 2);
            }
            $values[] = $line['total'] === null ? null : round((float) $line['total'], 2);
            $sheet->fromArray($values, null, "A{$row}");
            if (($line['kind'] ?? 'pct') === 'pct') {
                // Values are percents (e.g. 63.5 means 63.5%).
                $sheet->getStyle("B{$row}:N{$row}")->getNumberFormat()->setFormatCode('0.0"%"');
            }
            $row++;
        }

        return $spreadsheet;
    }

    /** Costs by Category: category rows + group subtotals + totals. */
    public static function costs(array $table, int $year): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("Costs {$year}");
        $sheet->fromArray(array_merge(['Category', 'P&L Group'], self::MONTHS, ['TOTAL']), null, 'A1');
        $row = 2;
        foreach ($table['rows'] as $r) {
            $values = [$r['label'], $r['group']];
            for ($m = 1; $m <= 12; $m++) {
                $values[] = round((float) ($r['months'][$m] ?? 0), 2);
            }
            $values[] = round((float) $r['total'], 2);
            $sheet->fromArray($values, null, "A{$row}");
            $row++;
        }
        foreach ($table['subtotals'] as $sub) {
            $values = [$sub['label'], $sub['group']];
            for ($m = 1; $m <= 12; $m++) {
                $values[] = round((float) ($sub['months'][$m] ?? 0), 2);
            }
            $values[] = round((float) $sub['total'], 2);
            $sheet->fromArray($values, null, "A{$row}");
            $row++;
        }
        $totals = [
            ['TOTAL GERAL', ''],
            ['Documents without a cost centre', ''],
            ['Unmapped', ''],
        ];
        $amounts = [$table['total_geral'], $table['no_cost_centre'], $table['unmapped']];
        foreach ($totals as $i => [$label]) {
            $values = [$label, ''];
            for ($m = 1; $m <= 12; $m++) {
                $values[] = round((float) ($amounts[$i][$m] ?? 0), 2);
            }
            $values[] = round(array_sum($amounts[$i]), 2);
            $sheet->fromArray($values, null, "A{$row}");
            $row++;
        }

        return $spreadsheet;
    }

    /** Sales by channel: per channel Sem IVA / IVA / Total rows. */
    public static function sales(array $channels, int $year): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle("Sales {$year}");
        $sheet->fromArray(array_merge(['Sales'], self::MONTHS, ['TOTAL']), null, 'A1');
        $row = 2;
        foreach (['Software Faturação', 'Restaurante', 'Bar', 'Eventos', 'Outras'] as $channel) {
            foreach (['ex' => 'Sem IVA', 'iva' => 'IVA', 'total' => 'Total'] as $key => $label) {
                $values = ["{$channel} — {$label}"];
                $total = 0.0;
                for ($m = 1; $m <= 12; $m++) {
                    $v = round((float) ($channels[$channel][$m][$key] ?? 0), 2);
                    $values[] = $v;
                    $total += $v;
                }
                $values[] = round($total, 2);
                $sheet->fromArray($values, null, "A{$row}");
                $row++;
            }
        }

        return $spreadsheet;
    }

    /** Management Documents: the filtered list, one row per document. */
    public static function documents(iterable $docs): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Documents');
        $sheet->fromArray(
            ['Date', 'Supplier', 'Document No.', 'Type', 'Category', 'P&L Group', 'Cost Centre', 'Base', 'VAT', 'Total', 'Net Value', 'Status'],
            null,
            'A1'
        );
        $row = 2;
        foreach ($docs as $doc) {
            $sheet->fromArray([
                $doc->doc_date ? $doc->doc_date->format('Y-m-d') : '',
                $doc->supplier_name ?? '',
                $doc->doc_number ?? '',
                $doc->doc_type,
                $doc->rule ? $doc->rule->label() : '',
                $doc->rule ? $doc->rule->group : '',
                $doc->costCentre ? $doc->costCentre->label() : '',
                $doc->base !== null ? round((float) $doc->base, 2) : null,
                $doc->iva !== null ? round((float) $doc->iva, 2) : null,
                round((float) $doc->total, 2),
                round($doc->netValue(), 2),
                $doc->status,
            ], null, "A{$row}");
            $row++;
        }

        return $spreadsheet;
    }
}
