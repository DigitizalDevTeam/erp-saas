<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PaySlip;
use App\Models\Revenue;
use App\Services\IrpsModel22ReportService;
use Carbon\Carbon;
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

        $line10 = 0.0;
        foreach ($payslips as $payslip) {
            $employee = $payslip->employees ?: Employee::find($payslip->employee_id);
            $dependents = (int) ($employee?->dependents ?? 0);
            $breakdown = IrpsModel22ReportService::breakdown($payslip, $dependents);
            $line10 += (float) ($breakdown['total_earnings_components'] ?? 0);
        }

        $line10 = round($line10, 2);
        $line10Auto = $line10;

        // Allow manual override when user needs to adjust payroll-derived salary value.
        $line10Manual = $this->toFloat($request->query('line10_manual'));
        if ($line10Manual !== null) {
            $line10 = $line10Manual;
        }

        $line11 = $this->toFloat($request->query('line11')) ?? 0.0; // Pension
        $monthStart = Carbon::createFromFormat('Y-m', $month)->startOfMonth()->toDateString();
        $monthEnd = Carbon::createFromFormat('Y-m', $month)->endOfMonth()->toDateString();
        $line12Auto = (float) Revenue::query()
            ->where('created_by', \Auth::user()->creatorId())
            ->whereDate('date', '>=', $monthStart)
            ->whereDate('date', '<=', $monthEnd)
            ->sum('amount');
        $line12 = $this->toFloat($request->query('line12')) ?? round($line12Auto, 2); // Professional income (accounting)
        $line13 = $this->toFloat($request->query('line13')) ?? 0.0; // Business income
        $line14 = $this->toFloat($request->query('line14')) ?? 0.0; // Games income
        $line15 = $this->toFloat($request->query('line15')) ?? 0.0; // Capital gains
        $line16 = $this->toFloat($request->query('line16')) ?? 0.0; // Rental income
        $line20 = $this->toFloat($request->query('line20')) ?? 0.0; // Advance payment
        $line21 = $this->toFloat($request->query('line21')) ?? 0.0; // Interest

        $lineTotal = round($line10 + $line11 + $line12 + $line13 + $line14 + $line15 + $line16, 2);

        $settings = \App\Models\Utility::settings();
        $creator = \App\Models\User::find(\Auth::user()->creatorId());
        $monthYear = Carbon::createFromFormat('Y-m', $month)->format('m/Y');

        $reportData = [
            'month' => $month,
            'month_year' => $monthYear,
            'company_name' => $settings['company_name'] ?? ($creator?->name ?? ''),
            'company_nuit' => $settings['vat_number'] ?? '',
            'address' => $settings['company_address'] ?? '',
            'phone' => $settings['company_telephone'] ?? ($settings['company_phone'] ?? ''),
            'email' => $settings['company_email'] ?? '',
            'line10' => $line10,
            'line11' => $line11,
            'line12' => $line12,
            'line13' => $line13,
            'line14' => $line14,
            'line15' => $line15,
            'line16' => $line16,
            'line20' => $line20,
            'line21' => $line21,
            'line_total' => $lineTotal,
            'auto_line10' => $line10Auto,
            'auto_line12' => round($line12Auto, 2),
        ];

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
                    compact('filter', 'reportData')
                )->setPaper([0, 0, $paperWidthPt, $paperHeightPt], 'portrait')->download($fileName);
            }

            $html = view('reports.monthly.irps_modelo_19_pdf', compact('filter', 'reportData'))->render();
            $pdfBinary = Browsershot::html($html)
                ->showBackground()
                ->paperSize($paperWidthMm, $paperHeightMm, 'mm')
                ->margins(8, 8, 8, 8)
                ->pdf();

            return response()->streamDownload(static function () use ($pdfBinary): void {
                echo $pdfBinary;
            }, $fileName, ['Content-Type' => 'application/pdf']);
        }

        return view('reports.monthly.irps_modelo_19', compact('filter', 'reportData'));
    }

    private function toFloat($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], (string) $value);
        if (! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
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

