<?php

namespace App\Services;

use App\Models\Utility;
use App\Models\User;

class IrpcCalculationService
{
    /**
     * @return array<string, mixed>
     */
    public function buildAnnualGuide(int $creatorId, int $fiscalYear): array
    {
        $dreReport = DreDemonstracaoResultadosService::build($creatorId, $fiscalYear, $fiscalYear - 1);
        $settings = Utility::settingsById($creatorId);
        $creator = User::find($creatorId);

        return $this->buildPayloadFromComparativeReport(
            $dreReport,
            $fiscalYear,
            (float) config('fiscal_closing.irpc_rate'),
            $settings,
            $creator?->name
        );
    }

    /**
     * @param  array<string, mixed>  $dreReport
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function buildPayloadFromComparativeReport(
        array $dreReport,
        int $fiscalYear,
        float $irpcRate,
        array $settings = [],
        ?string $fallbackCompanyName = null
    ): array {
        $sourceKey = ((int) ($dreReport['year1'] ?? 0) === $fiscalYear) ? 'y1' : 'y2';
        $previousYear = $fiscalYear - 1;

        $operatingCosts = round((float) ($dreReport['A'][$sourceKey] ?? 0.0), 2);
        $financialCosts = round((float) ($dreReport['C'][$sourceKey] ?? 0.0), 2);
        $extraordinaryCosts = round((float) ($dreReport['E'][$sourceKey] ?? 0.0), 2);
        $incomeTaxExpense = round((float) ($dreReport['income_tax'][$sourceKey] ?? 0.0), 2);
        $totalRevenue = round((float) ($dreReport['H'][$sourceKey] ?? 0.0), 2);
        $totalExpensesBeforeTax = round($operatingCosts + $financialCosts + $extraordinaryCosts, 2);
        $accountingProfitBeforeTax = round($totalRevenue - $totalExpensesBeforeTax, 2);
        $taxableIncome = round(max(0.0, $accountingProfitBeforeTax), 2);
        $priorPayments = 0.0;
        $irpcPayable = round($taxableIncome * $irpcRate, 2);
        $netPayable = round(max(0.0, $irpcPayable - $priorPayments), 2);

        return [
            'fiscal_year' => $fiscalYear,
            'previous_fiscal_year' => $previousYear,
            'period_start' => sprintf('%04d-01-01', $fiscalYear),
            'period_end' => sprintf('%04d-12-31', $fiscalYear),
            'period_label' => sprintf('01/01/%04d - 31/12/%04d', $fiscalYear, $fiscalYear),
            'company_name' => $settings['company_name'] ?? $fallbackCompanyName ?? '',
            'company_nuit' => $settings['vat_number'] ?? '',
            'company_address' => $settings['company_address'] ?? '',
            'company_city' => $settings['company_city'] ?? '',
            'company_phone' => $settings['company_telephone'] ?? ($settings['company_phone'] ?? ''),
            'company_email' => $settings['company_email'] ?? '',
            'total_revenue' => $totalRevenue,
            'operating_costs' => $operatingCosts,
            'financial_costs' => $financialCosts,
            'extraordinary_costs' => $extraordinaryCosts,
            'total_expenses_before_tax' => $totalExpensesBeforeTax,
            'income_tax_expense' => $incomeTaxExpense,
            'accounting_profit_before_tax' => $accountingProfitBeforeTax,
            'taxable_income' => $taxableIncome,
            'irpc_rate' => $irpcRate,
            'irpc_rate_percent' => round($irpcRate * 100, 2),
            'prior_payments' => $priorPayments,
            'irpc_payable' => $irpcPayable,
            'net_payable' => $netPayable,
            'source_report' => [
                'dre' => [
                    'year1' => (int) ($dreReport['year1'] ?? $fiscalYear),
                    'year2' => (int) ($dreReport['year2'] ?? $previousYear),
                ],
                'trial_balance' => [
                    'start_date' => sprintf('%04d-01-01', $fiscalYear),
                    'end_date' => sprintf('%04d-12-31', $fiscalYear),
                ],
            ],
        ];
    }
}
