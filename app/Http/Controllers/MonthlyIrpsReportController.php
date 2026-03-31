<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PaySlip;
use Illuminate\Http\Request;
use Spatie\Browsershot\Browsershot;

class MonthlyIrpsReportController extends Controller
{
    public function modelo19(Request $request)
    {
        if (!\Auth::user()->can('manage report')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $month = !empty($request->month) ? $request->month : date('Y-m');

        $payslips = PaySlip::query()
            ->with(['employees'])
            ->where('created_by', \Auth::user()->creatorId())
            ->where('salary_month', $month)
            ->get();

        $rows = [];
        $totals = [
            'gross' => 0.0,
            'inss_employee' => 0.0,
            'inss_employer' => 0.0,
            'irps' => 0.0,
            'net' => 0.0,
        ];

        foreach ($payslips as $payslip) {
            $employee = $payslip->employees ?: Employee::find($payslip->employee_id);

            $gross = (float) $payslip->net_payble + (float) $payslip->inss_employee_amount + (float) $payslip->irps_amount;

            $rows[] = [
                'employee_id' => $employee?->employee_id,
                'name' => $employee?->name,
                'tax_payer_id' => $employee?->tax_payer_id,
                'dependents' => (int) ($employee?->dependents ?? 0),
                'gross' => $gross,
                'inss_employee' => (float) $payslip->inss_employee_amount,
                'inss_employer' => (float) $payslip->inss_employer_amount,
                'irps' => (float) $payslip->irps_amount,
                'net' => (float) $payslip->net_payble,
            ];

            $totals['gross'] += $gross;
            $totals['inss_employee'] += (float) $payslip->inss_employee_amount;
            $totals['inss_employer'] += (float) $payslip->inss_employer_amount;
            $totals['irps'] += (float) $payslip->irps_amount;
            $totals['net'] += (float) $payslip->net_payble;
        }

        $filter = [
            'month' => $month,
        ];

        if ($request->query('download') === 'pdf') {
            $fileName = sprintf('irps-modelo-19_%s.pdf', $month);
            $paperWidthMm = 267;
            $paperHeightMm = 330;
            $mmToPt = 72 / 25.4;
            $paperWidthPt = $paperWidthMm * $mmToPt;
            $paperHeightPt = $paperHeightMm * $mmToPt;

            if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
                return \Barryvdh\DomPDF\Facade\Pdf::loadView(
                    'reports.monthly.irps_modelo_19_pdf',
                    compact('filter', 'rows', 'totals')
                )->setPaper([0, 0, $paperWidthPt, $paperHeightPt], 'portrait')->download($fileName);
            }

            $html = view('reports.monthly.irps_modelo_19_pdf', compact('filter', 'rows', 'totals'))->render();
            $pdfBinary = Browsershot::html($html)
                ->showBackground()
                ->paperSize($paperWidthMm, $paperHeightMm, 'mm')
                ->margins(8, 8, 8, 8)
                ->pdf();

            return response()->streamDownload(static function () use ($pdfBinary): void {
                echo $pdfBinary;
            }, $fileName, ['Content-Type' => 'application/pdf']);
        }

        return view('reports.monthly.irps_modelo_19', compact('filter', 'rows', 'totals'));
    }
}

