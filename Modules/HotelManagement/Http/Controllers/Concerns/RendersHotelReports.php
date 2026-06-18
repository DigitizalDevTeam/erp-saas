<?php

namespace Modules\HotelManagement\Http\Controllers\Concerns;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Modules\HotelManagement\Exports\SimpleSheetExport;
use Spatie\Browsershot\Browsershot;

/**
 * Shared rendering for tabular hotel reports: resolves date ranges and renders
 * a report "payload" either as the on-screen view, a professional PDF, or Excel
 * depending on the `export` query parameter.
 */
trait RendersHotelReports
{
    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function reportDateRange(Request $request, int $defaultDays = 29): array
    {
        $to = $request->filled('to') ? Carbon::parse($request->get('to'))->endOfDay() : Carbon::today()->endOfDay();
        $from = $request->filled('from')
            ? Carbon::parse($request->get('from'))->startOfDay()
            : $to->copy()->subDays($defaultDays)->startOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        return [$from, $to];
    }

    /**
     * Render a report payload as screen / PDF / Excel.
     *
     * Expected $report keys: key, title, subtitle, icon, from, to, columns, rows,
     * summary, totalsRow, orientation, showDateFilter, emptyText.
     *
     * @param  array<string, mixed>  $report
     */
    protected function renderReport(Request $request, array $report)
    {
        $report = array_merge([
            'subtitle' => '',
            'icon' => 'ti ti-report',
            'summary' => [],
            'columns' => [],
            'rows' => [],
            'totalsRow' => null,
            'orientation' => 'portrait',
            'showDateFilter' => true,
            'emptyText' => __('No data found for this period.'),
        ], $report);

        $export = strtolower((string) $request->get('export'));

        if ($export === 'excel' || $export === 'xlsx') {
            return $this->exportReportExcel($report);
        }

        if ($export === 'pdf') {
            return $this->exportReportPdf($report);
        }

        return view('hotelmanagement::reports.generic', $report);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    protected function exportReportExcel(array $report)
    {
        $headings = array_map(fn ($c) => $c['label'], $report['columns']);
        $rows = array_map(function (array $row) use ($report) {
            return array_map(fn ($c) => $this->exportCellValue($row[$c['key']] ?? '', $c['type'] ?? 'text'), $report['columns']);
        }, $report['rows']);

        return Excel::download(new SimpleSheetExport($headings, $rows), $this->reportFileName($report, 'xlsx'));
    }

    /**
     * @param  array<string, mixed>  $report
     */
    protected function exportReportPdf(array $report)
    {
        $html = view('hotelmanagement::reports.print.generic', $report)->render();

        return $this->downloadReportPdf($html, $this->reportFileName($report, 'pdf'), $report['orientation'] ?? 'portrait');
    }

    protected function exportCellValue(mixed $value, string $type): mixed
    {
        if ($type === 'money' || $type === 'number' || $type === 'percent') {
            return is_numeric($value) ? (float) $value : $value;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    protected function reportFileName(array $report, string $ext): string
    {
        $name = $report['key'] ?? 'hotel-report';
        if (! empty($report['from']) && ! empty($report['to'])) {
            $name .= '-' . $report['from']->format('Ymd') . '-' . $report['to']->format('Ymd');
        } else {
            $name .= '-' . now()->format('Ymd');
        }

        return $name . '.' . $ext;
    }

    protected function downloadReportPdf(string $html, string $fileName, string $orientation = 'portrait')
    {
        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', $orientation)->download($fileName);
        }

        $shot = Browsershot::html($html)->showBackground()->format('A4')->margins(12, 12, 16, 12);
        if ($orientation === 'landscape') {
            $shot->landscape();
        }
        $pdfBinary = $shot->pdf();

        return response()->streamDownload(static function () use ($pdfBinary): void {
            echo $pdfBinary;
        }, $fileName, ['Content-Type' => 'application/pdf']);
    }
}
