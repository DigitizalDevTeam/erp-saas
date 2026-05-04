<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PaySlip;
use App\Services\IrpsModel22ReportService;
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

    /**
     * Monthly IRPS / payroll statutory detail (Model 22): earnings, deductions, IRPS — from pay_slips only.
     *
     * PDF (all): ?month=Y-m&download=pdf
     * PDF (one employee): ?month=Y-m&download=pdf&payslip_id={id}
     */
    public function modelo22(Request $request)
    {
        if (! \Auth::user()->can('manage report')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $month = ! empty($request->month) ? $request->month : date('Y-m');
        $filter = ['month' => $month];

        if ($request->query('download') === 'pdf' && $request->filled('payslip_id')) {
            $payslip = PaySlip::query()
                ->with(['employees'])
                ->where('created_by', \Auth::user()->creatorId())
                ->where('salary_month', $month)
                ->where('id', (int) $request->query('payslip_id'))
                ->first();

            if (! $payslip) {
                return redirect()
                    ->route('monthly.reports.irps-modelo-22', ['month' => $month])
                    ->with('error', __('Payslip not found for this month.'));
            }

            ['rows' => $rows, 'totals' => $totals] = $this->buildModel22Rows(collect([$payslip]));
            $empPart = (string) ($rows[0]['employee_id'] ?? '');
            if ($empPart === '') {
                $empPart = 'payslip-'.$payslip->id;
            }
            $safeEmp = preg_replace('/[^\w\-]+/u', '-', $empPart) ?: 'emp';
            $fileName = sprintf('irps-modelo-22_%s_%s.pdf', $month, $safeEmp);

            return $this->downloadModel22Pdf($fileName, $filter, $rows, $totals);
        }

        $payslips = PaySlip::query()
            ->with(['employees'])
            ->where('created_by', \Auth::user()->creatorId())
            ->where('salary_month', $month)
            ->orderBy('employee_id')
            ->get();

        ['rows' => $rows, 'totals' => $totals] = $this->buildModel22Rows($payslips);

        if ($request->query('download') === 'pdf') {
            $fileName = sprintf('irps-modelo-22_%s_all.pdf', $month);

            return $this->downloadModel22Pdf($fileName, $filter, $rows, $totals);
        }

        return view('reports.monthly.irps_modelo_22', compact('filter', 'rows', 'totals'));
    }

    /**
     * @param \Illuminate\Support\Collection<int, \App\Models\PaySlip>|\Illuminate\Database\Eloquent\Collection<int, \App\Models\PaySlip> $payslips
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, float>}
     */
    private function buildModel22Rows($payslips): array
    {
        $rows = [];
        $totals = [
            'basic' => 0.0,
            'allowance' => 0.0,
            'commission' => 0.0,
            'other_payment' => 0.0,
            'overtime' => 0.0,
            'total_earnings_components' => 0.0,
            'loan' => 0.0,
            'saturation' => 0.0,
            'inss_employee' => 0.0,
            'inss_employer' => 0.0,
            'irps' => 0.0,
            'irps_a_reter' => 0.0,
            'gross_irps_base' => 0.0,
            'net' => 0.0,
        ];

        foreach ($payslips as $payslip) {
            $employee = $payslip->employees ?: Employee::find($payslip->employee_id);
            $dependents = (int) ($employee?->dependents ?? 0);
            $b = IrpsModel22ReportService::breakdown($payslip, $dependents);

            $rows[] = array_merge([
                'payslip_id' => $payslip->id,
                'employee_id' => $employee?->employee_id,
                'name' => $employee?->name,
                'tax_payer_id' => $employee?->tax_payer_id,
                'dependents' => $dependents,
            ], $b);

            foreach ($totals as $key => $_) {
                $totals[$key] += (float) ($b[$key] ?? 0);
            }
        }

        foreach ($totals as $key => $v) {
            $totals[$key] = round($v, 2);
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, float> $totals
     */
    private function downloadModel22Pdf(string $fileName, array $filter, array $rows, array $totals)
    {
        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            return \Barryvdh\DomPDF\Facade\Pdf::loadView(
                'reports.monthly.irps_modelo_22_pdf',
                compact('filter', 'rows', 'totals')
            )->setPaper('a4', 'landscape')->download($fileName);
        }

        $html = view('reports.monthly.irps_modelo_22_pdf', compact('filter', 'rows', 'totals'))->render();
        $pdfBinary = Browsershot::html($html)
            ->showBackground()
            ->format('A4')
            ->margins(8, 8, 8, 8)
            ->pdf();

        return response()->streamDownload(static function () use ($pdfBinary): void {
            echo $pdfBinary;
        }, $fileName, ['Content-Type' => 'application/pdf']);
    }
}

