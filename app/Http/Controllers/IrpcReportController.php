<?php

namespace App\Http\Controllers;

use App\Services\IrpcCalculationService;
use Illuminate\Http\Request;
use Spatie\Browsershot\Browsershot;

class IrpcReportController extends Controller
{
    public function __construct(
        private IrpcCalculationService $irpcCalculationService
    ) {}

    public function index(Request $request)
    {
        if (! \Auth::user()->can('tax report')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $fiscalYear = $this->normalizeFiscalYear($request->query('year'));
        $reportData = $this->irpcCalculationService->buildAnnualGuide(\Auth::user()->creatorId(), $fiscalYear);

        if ($request->query('download') === 'pdf') {
            return $this->downloadPdf($reportData);
        }

        return view('reports.annual.irpc_payment_guide', compact('reportData'));
    }

    private function normalizeFiscalYear($year): int
    {
        $year = (int) ($year ?: date('Y'));

        return max(2000, min(2100, $year));
    }

    /**
     * @param  array<string, mixed>  $reportData
     */
    private function downloadPdf(array $reportData)
    {
        $fileName = sprintf('irpc-payment-guide_%s.pdf', $reportData['fiscal_year']);

        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            return \Barryvdh\DomPDF\Facade\Pdf::loadView(
                'reports.annual.irpc_payment_guide_pdf',
                compact('reportData')
            )->setPaper('a4', 'portrait')->download($fileName);
        }

        try {
            $html = view('reports.annual.irpc_payment_guide_pdf', compact('reportData'))->render();
            $pdfBinary = Browsershot::html($html)
                ->showBackground()
                ->paperSize(210, 297, 'mm')
                ->margins(8, 8, 8, 8)
                ->pdf();

            return response()->streamDownload(static function () use ($pdfBinary): void {
                echo $pdfBinary;
            }, $fileName, ['Content-Type' => 'application/pdf']);
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('annual.reports.irpc-payment-guide', ['year' => $reportData['fiscal_year']])
                ->with('error', __('PDF generation is not available on this server. Please install Node.js for Browsershot or enable DomPDF.'));
        }
    }
}
