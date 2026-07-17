<?php

namespace App\Http\Controllers;

use App\Services\TrialBalanceReportService;
use Illuminate\Http\Request;
use Spatie\Browsershot\Browsershot;

class MonthlyReportController extends Controller
{
    public function trialBalance(Request $request, $view = 'government')
    {
        if (!\Auth::user()->can('trial balance report')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        if (! empty($request->start_date) && ! empty($request->end_date)) {
            $start = $request->start_date;
            $end = $request->end_date;
        } elseif ($request->query('range') === 'ytd') {
            $range = TrialBalanceReportService::yearToDateRange();
            $start = $range['start'];
            $end = $range['end'];
        } else {
            $range = TrialBalanceReportService::defaultDateRange();
            $start = $range['start'];
            $end = $range['end'];
        }

        $report = app(TrialBalanceReportService::class)->build(
            (int) \Auth::user()->creatorId(),
            $start,
            $end,
            'expand'
        );

        $filter = $report['filter'];
        $totalAccounts = $report['totalAccounts'];
        $grandTotalDebit = $report['grandTotalDebit'];
        $grandTotalCredit = $report['grandTotalCredit'];

        $blade = $view === 'classic' ? 'report.trial_balance' : 'reports.monthly.trial_balance';

        if ($request->query('download') === 'pdf') {
            $fileName = sprintf(
                'trial-balance_%s_to_%s.pdf',
                $filter['startDateRange'],
                $filter['endDateRange']
            );

            if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
                return \Barryvdh\DomPDF\Facade\Pdf::loadView(
                    'reports.monthly.trial_balance_pdf',
                    compact('filter', 'totalAccounts', 'view', 'grandTotalDebit', 'grandTotalCredit')
                )->setPaper('a4')->download($fileName);
            }

            try {
                $html = view('reports.monthly.trial_balance_pdf', compact('filter', 'totalAccounts', 'view', 'grandTotalDebit', 'grandTotalCredit'))->render();
                $pdfBinary = Browsershot::html($html)
                    ->showBackground()
                    ->format('A4')
                    ->margins(12, 12, 16, 12)
                    ->pdf();

                return response()->streamDownload(static function () use ($pdfBinary): void {
                    echo $pdfBinary;
                }, $fileName, ['Content-Type' => 'application/pdf']);
            } catch (\Throwable $e) {
                report($e);

                return redirect()
                    ->route('monthly.reports.trial-balance', [
                        'start_date' => $filter['startDateRange'],
                        'end_date' => $filter['endDateRange'],
                    ])
                    ->with('error', __('PDF generation is not available on this server. Please install Node.js for Browsershot or enable DomPDF.'));
            }
        }

        if ($view === 'classic') {
            return view($blade, [
                'filter' => $filter,
                'totalAccounts' => $totalAccounts,
                'grandTotalDebit' => $grandTotalDebit,
                'grandTotalCredit' => $grandTotalCredit,
                'view' => 'expand',
            ]);
        }

        return view($blade, compact('filter', 'totalAccounts', 'view', 'grandTotalDebit', 'grandTotalCredit'));
    }
}
