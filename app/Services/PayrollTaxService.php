<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\IrpsWithholdingRow;

class PayrollTaxService
{
    public static function calculateInss(Employee $employee, float $grossMonthly): array
    {
        $grossMonthly = max(0, $grossMonthly);

        $employeeRate = 0.03; // 3%
        $employerRate = 0.04; // 4%

        $employeePortion = round($grossMonthly * $employeeRate, 2);
        $employerPortion = round($grossMonthly * $employerRate, 2);

        if ((bool) $employee->inss_employer_pays_all) {
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
}

