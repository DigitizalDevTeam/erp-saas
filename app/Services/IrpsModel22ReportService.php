<?php

namespace App\Services;

use App\Models\PaySlip;

/**
 * Builds per-payslip breakdown for the monthly IRPS / payroll report (Model 22).
 * Amounts come from the payslip snapshot JSON and statutory fields (no manual entry).
 */
class IrpsModel22ReportService
{
    /**
     * @return array<string, float>
     */
    public static function breakdown(PaySlip $p): array
    {
        $basic = (float) $p->basic_salary;
        $allowance = self::sumTypedAmountJson($p->allowance, $basic);
        $commission = self::sumTypedAmountJson($p->commission, $basic);
        $otherPayment = self::sumTypedAmountJson($p->other_payment, $basic);
        $overtime = self::sumOvertimeJson($p->overtime);
        $loan = self::sumLoanJson($p->loan, $basic);
        $saturation = self::sumTypedAmountJson($p->saturation_deduction, $basic);

        $inssEmployee = (float) $p->inss_employee_amount;
        $inssEmployer = (float) $p->inss_employer_amount;
        $irps = (float) $p->irps_amount;
        $net = (float) $p->net_payble;

        $grossIrpsBase = round($net + $inssEmployee + $irps, 2);
        $totalEarnings = round($basic + $allowance + $commission + $otherPayment + $overtime, 2);

        return [
            'basic' => round($basic, 2),
            'allowance' => $allowance,
            'commission' => $commission,
            'other_payment' => $otherPayment,
            'overtime' => $overtime,
            'total_earnings_components' => $totalEarnings,
            'loan' => $loan,
            'saturation' => $saturation,
            'inss_employee' => round($inssEmployee, 2),
            'inss_employer' => round($inssEmployer, 2),
            'irps' => round($irps, 2),
            'gross_irps_base' => $grossIrpsBase,
            'net' => round($net, 2),
            'total_deductions' => round($loan + $saturation + $inssEmployee + $irps, 2),
        ];
    }

    private static function sumTypedAmountJson(?string $json, float $basicSalary): float
    {
        if ($json === null || $json === '') {
            return 0.0;
        }
        $decoded = json_decode($json, false);
        if ($decoded === null) {
            return 0.0;
        }
        if (is_object($decoded)) {
            $decoded = [$decoded];
        }
        if (! is_array($decoded)) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($decoded as $row) {
            if (! is_object($row)) {
                continue;
            }
            $type = strtolower((string) ($row->type ?? 'fixed'));
            $amt = (float) ($row->amount ?? 0);
            if ($type === 'percentage') {
                $sum += round($basicSalary * $amt / 100, 2);
            } else {
                $sum += $amt;
            }
        }

        return round($sum, 2);
    }

    private static function sumOvertimeJson(?string $json): float
    {
        if ($json === null || $json === '') {
            return 0.0;
        }
        $decoded = json_decode($json, false);
        if ($decoded === null) {
            return 0.0;
        }
        if (is_object($decoded)) {
            $decoded = [$decoded];
        }
        if (! is_array($decoded)) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($decoded as $row) {
            if (! is_object($row)) {
                continue;
            }
            $sum += (float) ($row->number_of_days ?? 0) * (float) ($row->hours ?? 0) * (float) ($row->rate ?? 0);
        }

        return round($sum, 2);
    }

    private static function sumLoanJson(?string $json, float $basicSalary): float
    {
        if ($json === null || $json === '') {
            return 0.0;
        }
        $decoded = json_decode($json, false);
        if ($decoded === null) {
            return 0.0;
        }
        if (is_object($decoded)) {
            $decoded = [$decoded];
        }
        if (! is_array($decoded)) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($decoded as $row) {
            if (! is_object($row)) {
                continue;
            }
            $type = strtolower((string) ($row->type ?? 'fixed'));
            $amt = (float) ($row->amount ?? 0);
            if ($type === 'percentage') {
                $sum += round($basicSalary * $amt / 100, 2);
            } else {
                $sum += $amt;
            }
        }

        return round($sum, 2);
    }
}
