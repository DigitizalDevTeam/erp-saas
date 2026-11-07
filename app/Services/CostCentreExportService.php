<?php

namespace App\Services;

use App\Exports\CostCentreReportExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cost centre exports (Cost Centre Phase 6, plan §13): CSV + XLSX +
 * PDF for the reports, the allocation ledger and the GL dimensions
 * screen.
 *
 * One helper for every screen so the three formats always carry the
 * same headings and rows — an export that disagrees with its screen
 * is a reconciliation break. The export permission is checked here,
 * so no caller can bypass it; the rows are built lazily (callable)
 * so a plain HTML view never pays for them.
 */
class CostCentreExportService
{
    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    /**
     * Returns a download response when ?export=csv|xlsx|pdf is present,
     * or null when no export was requested (the caller renders HTML).
     * A missing export permission redirects back with an error.
     *
     * $metaLine is the first CSV row (report title + period + currency
     * for the reports; the title alone elsewhere). $from stamps the
     * filename; it defaults to today.
     */
    public function responseFor(
        Request $request,
        string $filename,
        string $title,
        string $description,
        array $headings,
        callable|array $rows,
        ?string $from = null,
        ?array $metaLine = null
    ): mixed {
        $format = $request->input('export');
        if (! in_array($format, self::FORMATS, true)) {
            return null;
        }

        if (! Auth::user()->can('export cost centre reports')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $data = is_callable($rows) ? $rows() : $rows;
        $data = array_map(fn ($line) => array_values((array) $line), $data);
        $stamp = $from ?? date('Y-m-d');

        if ($format === 'xlsx') {
            return Excel::download(
                new CostCentreReportExport($headings, $data),
                $filename.'-'.$stamp.'.xlsx'
            );
        }

        if ($format === 'pdf') {
            return Pdf::loadView('cost-centres.reports.pdf', [
                'title' => $title,
                'description' => $description,
                'headings' => $headings,
                'rows' => $data,
                'totals' => [],
            ])->download($filename.'-'.$stamp.'.pdf');
        }

        return new StreamedResponse(function () use ($headings, $data, $title, $description, $metaLine) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $metaLine ?? [$title]);
            if ($description !== '') {
                fputcsv($out, [$description]);
            }
            fputcsv($out, $headings);
            foreach ($data as $line) {
                fputcsv($out, $line);
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'-'.$stamp.'.csv"',
        ]);
    }
}
