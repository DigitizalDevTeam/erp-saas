<?php

namespace App\Services;

use App\Models\PaySlip;

/**
 * Builds per-payslip breakdown for the monthly IRPS / payroll report (Model 22).
 * Amounts come from the payslip snapshot JSON and statutory fields (no manual entry).
 */
class IrpsModel22ReportService
{
    /** Mínimo não tributável mensal (MT), Sheet 1 reference. */
    public const MIN_NON_TAXABLE_MONTHLY = 18750.0;

    /**
     * IRPS retenção na fonte — tabela progressiva mensal (Rendimentos Colectáveis em MT).
     *
     * @return array{
     *     irps_rendimento_mensal: float,
     *     irps_minimo_nao_tributavel: float,
     *     irps_rendimento_colectavel: float,
     *     irps_taxa: float,
     *     irps_calculo_imposto: float,
     *     irps_parcela_abater: float,
     *     irps_colecta: float,
     *     irps_deducao_dependentes: float,
     *     irps_a_reter: float
     * }
     */
    public static function computeIrpsWithholding(float $rendimentoMensal, int $dependents): array
    {
        $minNaoTrib = self::MIN_NON_TAXABLE_MONTHLY;
        $colectavel = max(0.0, round($rendimentoMensal - $minNaoTrib, 2));
        [$rate, $parcela] = self::irpsRateAndParcela($colectavel);
        $calculoImposto = round($colectavel * $rate, 2);
        $parcelaAbater = round($parcela, 2);
        $colecta = round($calculoImposto - $parcelaAbater, 2);
        $deducaoDep = self::dependentsCollectaDeduction($dependents);
        $irpsAReter = round(max(0.0, $colecta - $deducaoDep), 2);

        return [
            'irps_rendimento_mensal' => round($rendimentoMensal, 2),
            'irps_minimo_nao_tributavel' => $minNaoTrib,
            'irps_rendimento_colectavel' => $colectavel,
            'irps_taxa' => $rate,
            'irps_calculo_imposto' => $calculoImposto,
            'irps_parcela_abater' => $parcelaAbater,
            'irps_colecta' => $colecta,
            'irps_deducao_dependentes' => $deducaoDep,
            'irps_a_reter' => $irpsAReter,
        ];
    }

    /**
     * @return array{0: float, 1: float} [rate decimal, parcela MT]
     */
    private static function irpsRateAndParcela(float $h): array
    {
        if ($h <= 3500) {
            return [0.10, 0.0];
        }
        if ($h <= 14000) {
            return [0.15, 175.0];
        }
        if ($h <= 42000) {
            return [0.20, 875.0];
        }
        if ($h <= 126000) {
            return [0.25, 2975.0];
        }

        return [0.32, 11795.0];
    }

    private static function dependentsCollectaDeduction(int $dependents): float
    {
        return match (true) {
            $dependents <= 0 => 150.0,
            $dependents === 1 => 200.0,
            $dependents === 2 => 225.0,
            $dependents === 3 => 250.0,
            default => 300.0,
        };
    }

    /**
     * @return array<string, float|int>
     */
    public static function breakdown(PaySlip $p, int $dependents = 0): array
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

        $totalEarnings = round($basic + $allowance + $commission + $otherPayment + $overtime, 2);
        $grossIrpsBase = $totalEarnings;

        $irpsSheet = self::computeIrpsWithholding($grossIrpsBase, $dependents);

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
        ] + $irpsSheet;
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
