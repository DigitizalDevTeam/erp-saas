<?php

namespace App\Http\Controllers;

use App\Services\TrialBalanceReportService;
use Illuminate\Http\Request;
use Spatie\Browsershot\Browsershot;

class MonthlyReportController extends Controller
{
    public function trialBalance(Request $request, $view = 'government')
    {
        if (!\Auth::user()->can('menu.dashboard.accounting.reports.monthly.trial_balance')
            && !\Auth::user()->can('trial balance report')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $resolved = TrialBalanceReportService::resolveFilter($request->all());

        $report = app(TrialBalanceReportService::class)->build(
            (int) \Auth::user()->creatorId(),
            $resolved['start'],
            $resolved['end'],
            'expand',
            $resolved['accountingPeriod'],
            $resolved['fiscalYear'],
            $resolved['accountingPeriodFrom'],
            $resolved['accountingPeriodTo']
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
                    ->route('monthly.reports.trial-balance', array_filter([
                        'start_date' => $filter['startDateRange'],
                        'end_date' => $filter['endDateRange'],
                        'fiscal_year' => $filter['fiscalYear'] ?? null,
                        'accounting_period_from' => $filter['accountingPeriodFrom'] ?? null,
                        'accounting_period_to' => $filter['accountingPeriodTo'] ?? null,
                    ], static fn ($v) => $v !== null && $v !== ''))
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
