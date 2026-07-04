<?php

namespace App\Services;

use App\Models\ChartOfAccountType;
use App\Models\TransactionLines;
use Carbon\Carbon;

class BalanceSheetReportService
{
    /** @var list<array{section: string, label: string, code_label: string, prefixes: list<string>}> */
    private const LINE_MAPPINGS = [
        ['section' => 'Assets', 'label' => 'Fixed Assets', 'code_label' => '32', 'prefixes' => ['32']],
        ['section' => 'Assets', 'label' => 'Intangible Assets', 'code_label' => '33', 'prefixes' => ['33']],
        ['section' => 'Assets', 'label' => 'Inventory', 'code_label' => '21', 'prefixes' => ['21']],
        ['section' => 'Assets', 'label' => 'Clients', 'code_label' => '411', 'prefixes' => ['411']],
        ['section' => 'Assets', 'label' => 'Cash / Bank', 'code_label' => '11,12', 'prefixes' => ['11', '12']],
        ['section' => 'Liabilities', 'label' => 'Tax Payable', 'code_label' => '4437', 'prefixes' => ['4437']],
        ['section' => 'Equity', 'label' => 'Equity', 'code_label' => '59', 'prefixes' => ['59']],
    ];

    private const CREDIT_NORMAL_TYPES = ['Liabilities', 'Equity', 'Income'];

    private const DEBIT_NORMAL_TYPES = ['Assets', 'Expenses', 'Costs of Goods Sold', 'Results'];

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     totals: array<string, array<string, float>>,
     *     filter: array<string, string>,
     * }
     */
    public function build(int $creatorId, Carbon $currentStart, Carbon $currentEnd): array
    {
        if ($currentStart->gt($currentEnd)) {
            [$currentStart, $currentEnd] = [$currentEnd->copy(), $currentStart->copy()];
        }

        $previousStart = $currentStart->copy()->subYear();
        $previousEnd = $currentEnd->copy()->subYear();

        $rows = [];
        foreach (self::LINE_MAPPINGS as $mapping) {
            $rows[] = [
                'section' => $mapping['section'],
                'label' => $mapping['label'],
                'code_label' => $mapping['code_label'],
                'current_year' => $this->sumByCodePrefixes(
                    $mapping['prefixes'],
                    $mapping['section'],
                    $currentEnd,
                    $creatorId
                ),
                'previous_year' => $this->sumByCodePrefixes(
                    $mapping['prefixes'],
                    $mapping['section'],
                    $previousEnd,
                    $creatorId
                ),
            ];
        }

        $assetsCurrent = $this->sectionTotal('Assets', $currentEnd, $creatorId);
        $assetsPrevious = $this->sectionTotal('Assets', $previousEnd, $creatorId);
        $liabilitiesCurrent = $this->sectionTotal('Liabilities', $currentEnd, $creatorId);
        $liabilitiesPrevious = $this->sectionTotal('Liabilities', $previousEnd, $creatorId);
        $equityCurrent = $this->sectionTotal('Equity', $currentEnd, $creatorId);
        $equityPrevious = $this->sectionTotal('Equity', $previousEnd, $creatorId);
        $netIncomeCurrent = $this->netIncome($currentEnd, $creatorId);
        $netIncomePrevious = $this->netIncome($previousEnd, $creatorId);

        if ($this->hasBalance(abs($netIncomeCurrent))) {
            $rows[] = [
                'section' => 'Equity',
                'label' => 'Current Period Result',
                'code_label' => '',
                'current_year' => $netIncomeCurrent,
                'previous_year' => $netIncomePrevious,
            ];
        }

        $equityWithResultCurrent = $equityCurrent + $netIncomeCurrent;
        $equityWithResultPrevious = $equityPrevious + $netIncomePrevious;

        $totals = [
            'assets' => ['current' => $assetsCurrent, 'previous' => $assetsPrevious],
            'liabilities' => ['current' => $liabilitiesCurrent, 'previous' => $liabilitiesPrevious],
            'equity' => ['current' => $equityWithResultCurrent, 'previous' => $equityWithResultPrevious],
            'equity_liabilities' => [
                'current' => $liabilitiesCurrent + $equityWithResultCurrent,
                'previous' => $liabilitiesPrevious + $equityWithResultPrevious,
            ],
            'difference' => [
                'current' => $assetsCurrent - ($liabilitiesCurrent + $equityWithResultCurrent),
                'previous' => $assetsPrevious - ($liabilitiesPrevious + $equityWithResultPrevious),
            ],
        ];

        return [
            'rows' => $rows,
            'totals' => $totals,
            'filter' => [
                'startDateRange' => $currentStart->format('Y-m-d'),
                'endDateRange' => $currentEnd->format('Y-m-d'),
                'previousStartDateRange' => $previousStart->format('Y-m-d'),
                'previousEndDateRange' => $previousEnd->format('Y-m-d'),
                'currentYear' => (string) $currentEnd->year,
                'previousYear' => (string) $previousEnd->year,
            ],
        ];
    }

    private function sectionTotal(string $typeName, Carbon $asOf, int $creatorId): float
    {
        return $this->sumByAccountType($typeName, $asOf, $creatorId);
    }

    private function netIncome(Carbon $asOf, int $creatorId): float
    {
        $income = $this->sumByAccountType('Income', $asOf, $creatorId);
        $cogs = $this->sumByAccountType('Costs of Goods Sold', $asOf, $creatorId);
        $expenses = $this->sumByAccountType('Expenses', $asOf, $creatorId);
        $results = $this->sumByAccountType('Results', $asOf, $creatorId);

        return round($income - $cogs - $expenses + $results, 2);
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function sumByCodePrefixes(array $prefixes, string $section, Carbon $asOf, int $creatorId): float
    {
        if ($prefixes === []) {
            return 0.0;
        }

        $balanceExpr = $this->balanceExpression($section);

        $query = TransactionLines::query()
            ->join('chart_of_accounts', 'transaction_lines.account_id', '=', 'chart_of_accounts.id')
            ->join('chart_of_account_types', 'chart_of_accounts.type', '=', 'chart_of_account_types.id')
            ->where('transaction_lines.created_by', $creatorId)
            ->where('chart_of_accounts.created_by', $creatorId)
            ->where('chart_of_account_types.name', $section)
            ->whereDate('transaction_lines.date', '<=', $asOf->toDateString())
            ->where(function ($q) use ($prefixes) {
                foreach ($prefixes as $prefix) {
                    $q->orWhere('chart_of_accounts.code', 'like', $prefix . '%');
                }
            });

        return round((float) ($query->selectRaw("COALESCE(SUM({$balanceExpr}), 0) as balance")->value('balance') ?? 0), 2);
    }

    private function sumByAccountType(string $typeName, Carbon $asOf, int $creatorId): float
    {
        if (! ChartOfAccountType::where('created_by', $creatorId)->where('name', $typeName)->exists()) {
            return 0.0;
        }

        $balanceExpr = $this->balanceExpression($typeName);

        $value = TransactionLines::query()
            ->join('chart_of_accounts', 'transaction_lines.account_id', '=', 'chart_of_accounts.id')
            ->join('chart_of_account_types', 'chart_of_accounts.type', '=', 'chart_of_account_types.id')
            ->where('transaction_lines.created_by', $creatorId)
            ->where('chart_of_accounts.created_by', $creatorId)
            ->where('chart_of_account_types.name', $typeName)
            ->whereDate('transaction_lines.date', '<=', $asOf->toDateString())
            ->selectRaw("COALESCE(SUM({$balanceExpr}), 0) as balance")
            ->value('balance');

        return round((float) ($value ?? 0), 2);
    }

    private function balanceExpression(string $typeOrSection): string
    {
        if (in_array($typeOrSection, self::CREDIT_NORMAL_TYPES, true)) {
            return 'transaction_lines.credit - transaction_lines.debit';
        }

        return 'transaction_lines.debit - transaction_lines.credit';
    }

    private function hasBalance(float $amount): bool
    {
        return abs($amount) > 0.005;
    }
}
