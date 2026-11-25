<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\IrpsWithholdingRow;
use App\Models\PaySlip;
use App\Models\Utility;

class PayrollTaxService
{
    public const BURDEN_WORKER_PAYS = 'worker_pays';
    public const BURDEN_COMPANY_PAYS_INSS = 'company_pays_inss';
    public const BURDEN_COMPANY_PAYS_ALL = 'company_pays_all';

    private static function resolvePayrollBurdenMode(Employee $employee): string
    {
        $settings = Utility::settingsById((int) $employee->created_by);
        $mode = (string) ($settings['payroll_tax_burden_mode'] ?? self::BURDEN_WORKER_PAYS);

        if (!in_array($mode, [self::BURDEN_WORKER_PAYS, self::BURDEN_COMPANY_PAYS_INSS, self::BURDEN_COMPANY_PAYS_ALL], true)) {
            return self::BURDEN_WORKER_PAYS;
        }

        return $mode;
    }

    public static function calculateInss(Employee $employee, float $grossMonthly): array
    {
        $grossMonthly = max(0, $grossMonthly);

        $employeeRate = 0.03; // 3%
        $employerRate = 0.04; // 4%

        $employeePortion = round($grossMonthly * $employeeRate, 2);
        $employerPortion = round($grossMonthly * $employerRate, 2);

        $burdenMode = self::resolvePayrollBurdenMode($employee);
        $companyPaysEmployeeInss = ((bool) $employee->inss_employer_pays_all)
            || in_array($burdenMode, [self::BURDEN_COMPANY_PAYS_INSS, self::BURDEN_COMPANY_PAYS_ALL], true);

        if ($companyPaysEmployeeInss) {
            // Company pays employee share too (not deducted from employee net)
            return [
                'employee' => 0.0,
                'employer' => round($employeePortion + $employerPortion, 2),
            ];
        }

        return [
            'employee' => $employeePortion,
            'employer' => $employerPortion,
        ];
    }

    public static function calculateIrps(Employee $employee, float $grossMonthly): float
    {
        $grossMonthly = max(0, $grossMonthly);
        $burdenMode = self::resolvePayrollBurdenMode($employee);
        if ($burdenMode === self::BURDEN_COMPANY_PAYS_ALL) {
            // Company absorbs IRPS in this mode, so no employee withholding on payslip.
            return 0.0;
        }

        $dependents = (int) ($employee->dependents ?? 0);

        $row = IrpsWithholdingRow::query()
            ->where('created_by', $employee->created_by)
            ->where('salary_from', '<=', $grossMonthly)
            ->where(function ($q) use ($grossMonthly) {
                $q->whereNull('salary_to')->orWhere('salary_to', '>=', $grossMonthly);
            })
            ->orderByDesc('salary_from')
            ->first();

        if (!$row) {
            return 0.0;
        }

        $base = match (true) {
            $dependents <= 0 => $row->base_dep0,
            $dependents === 1 => $row->base_dep1,
            $dependents === 2 => $row->base_dep2,
            $dependents === 3 => $row->base_dep3,
            default => $row->base_dep4plus,
        };

        // In the source table, "-" means no withholding and no coefficient.
        if ($base === null || $row->coefficient === null) {
            return 0.0;
        }

        $additional = ($grossMonthly - (float) $row->salary_from) * (float) $row->coefficient;
        $tax = (float) $base + $additional;

        return round(max(0, $tax), 2);
    }

    /**
     * Recompute the statutory amounts on a payslip from the employee's
     * current components and the company's withholding table, and set
     * the net payable. Does NOT save: the caller saves the payslip.
     *
     * INSS (employee 3% / employer 4%) is calculated on the net before
     * statutory deductions; IRPS is calculated on the IRPS base
     * (basic + allowance + commission + other payment + overtime).
     */
    public static function recalculatePayslip(PaySlip $payslip, Employee $employee): void
    {
        $salaryMonth = $payslip->salary_month ?: date('Y-m');
        $netBeforeStatutory = (float) $employee->get_net_salary($salaryMonth);
        $irpsBase = (float) $employee->get_irps_base_salary($salaryMonth);

        $inss = self::calculateInss($employee, $netBeforeStatutory);
        $irps = self::calculateIrps($employee, $irpsBase);

        $payslip->inss_employee_amount = $inss['employee'];
        $payslip->inss_employer_amount = $inss['employer'];
        $payslip->irps_amount = $irps;
        $payslip->net_payble = max(0, $netBeforeStatutory - (float) $payslip->inss_employee_amount - (float) $payslip->irps_amount);
    }
}

