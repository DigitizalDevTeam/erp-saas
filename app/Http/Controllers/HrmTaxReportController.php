<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\PaySlip;
use App\Services\IrpsModel22ReportService;
use Illuminate\Http\Request;

/**
 * HRM tax & deductions overview: statutory withholdings by payslip period and employee loan balances / repayments.
 */
class HrmTaxReportController extends Controller
{
    public function index(Request $request)
    {
        if (! \Auth::user()->can('manage report')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = \Auth::user()->creatorId();

        $defaultTo = date('Y-m');
        $defaultFrom = date('Y-m', strtotime(date('Y-m-01').' -11 months'));

        $fromMonth = $request->filled('date_from') ? substr((string) $request->input('date_from'), 0, 7) : $defaultFrom;
        $toMonth = $request->filled('date_to') ? substr((string) $request->input('date_to'), 0, 7) : $defaultTo;
        if ($fromMonth > $toMonth) {
            [$fromMonth, $toMonth] = [$toMonth, $fromMonth];
        }

        $branchId = $request->filled('branch_id') ? (int) $request->branch_id : null;
        $departmentId = $request->filled('department_id') ? (int) $request->department_id : null;

        $branches = Branch::where('created_by', $creatorId)->orderBy('name')->get()->pluck('name', 'id');
        $departments = Department::where('created_by', $creatorId)->orderBy('name')->get()->pluck('name', 'id');

        $payslips = PaySlip::query()
            ->with(['employees'])
            ->where('created_by', $creatorId)
            ->whereBetween('salary_month', [$fromMonth, $toMonth])
            ->when($branchId || $departmentId, function ($q) use ($branchId, $departmentId): void {
                $q->whereHas('employees', function ($eq) use ($branchId, $departmentId): void {
                    if ($branchId) {
                        $eq->where('branch_id', $branchId);
                    }
                    if ($departmentId) {
                        $eq->where('department_id', $departmentId);
                    }
                });
            })
            ->orderByDesc('salary_month')
            ->orderBy('employee_id')
            ->get();

        $withholdingLines = [];
        $sum = [
            'gross_irps_base' => 0.0,
            'inss_employee' => 0.0,
            'irps_payslip' => 0.0,
            'irps_calculated' => 0.0,
            'loan_deducted' => 0.0,
            'net' => 0.0,
        ];

        foreach ($payslips as $payslip) {
            $employee = $payslip->employees ?: Employee::find($payslip->employee_id);
            $dependents = (int) ($employee?->dependents ?? 0);
            $b = IrpsModel22ReportService::breakdown($payslip, $dependents);

            $withholdingLines[] = [
                'salary_month' => $payslip->salary_month,
                'employee_code' => $employee?->employee_id,
                'name' => $employee?->name,
                'nuit' => $employee?->tax_payer_id,
                'gross_irps_base' => (float) $b['gross_irps_base'],
                'inss_employee' => (float) $b['inss_employee'],
                'irps_payslip' => (float) $b['irps'],
                'irps_calculated' => (float) $b['irps_a_reter'],
                'loan_deducted' => (float) $b['loan'],
                'net' => (float) $b['net'],
            ];

            $sum['gross_irps_base'] += (float) $b['gross_irps_base'];
            $sum['inss_employee'] += (float) $b['inss_employee'];
            $sum['irps_payslip'] += (float) $b['irps'];
            $sum['irps_calculated'] += (float) $b['irps_a_reter'];
            $sum['loan_deducted'] += (float) $b['loan'];
            $sum['net'] += (float) $b['net'];
        }

        foreach ($sum as $k => $v) {
            $sum[$k] = round($v, 2);
        }

        $loans = Loan::query()
            ->with(['employee'])
            ->where('created_by', $creatorId)
            ->when($branchId || $departmentId, function ($q) use ($branchId, $departmentId): void {
                $q->whereHas('employee', function ($eq) use ($branchId, $departmentId): void {
                    if ($branchId) {
                        $eq->where('branch_id', $branchId);
                    }
                    if ($departmentId) {
                        $eq->where('department_id', $departmentId);
                    }
                });
            })
            ->orderByDesc('id')
            ->get();

        $loanTotals = [
            'principal' => 0.0,
            'outstanding' => 0.0,
            'monthly_installment' => 0.0,
        ];
        foreach ($loans as $ln) {
            $principal = (float) ($ln->principal_amount ?? $ln->amount ?? 0);
            $out = $ln->outstanding_principal !== null ? (float) $ln->outstanding_principal : null;
            $loanTotals['principal'] += $principal;
            if ($out !== null) {
                $loanTotals['outstanding'] += $out;
            }
            $loanTotals['monthly_installment'] += (float) ($ln->monthly_installment ?? 0);
        }
        $loanTotals['principal'] = round($loanTotals['principal'], 2);
        $loanTotals['outstanding'] = round($loanTotals['outstanding'], 2);
        $loanTotals['monthly_installment'] = round($loanTotals['monthly_installment'], 2);

        $filters = [
            'date_from' => $fromMonth,
            'date_to' => $toMonth,
            'branch_id' => $branchId,
            'department_id' => $departmentId,
        ];

        return view('report.hrm_tax', [
            'withholdingLines' => $withholdingLines,
            'withholdingTotals' => $sum,
            'loans' => $loans,
            'loanTotals' => $loanTotals,
            'branches' => $branches,
            'departments' => $departments,
            'filters' => $filters,
        ]);
    }
}
