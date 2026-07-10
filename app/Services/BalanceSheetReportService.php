<?php

namespace App\Services;

use App\Models\ChartOfAccountType;
use App\Models\TransactionLines;
use Carbon\Carbon;

class BalanceSheetReportService
{
    /**
     * PGC MOZ-style balance sheet lines. Prefixes match account codes and descendants.
     *
     * @var list<array{section: string, label: string, code_label: string, prefixes: list<string>}>
     */
    private const LINE_MAPPINGS = [
        // Assets
        ['section' => 'Assets', 'label' => 'Cash', 'code_label' => '11', 'prefixes' => ['11']],
        ['section' => 'Assets', 'label' => 'Bank deposits', 'code_label' => '12', 'prefixes' => ['12']],
        ['section' => 'Assets', 'label' => 'Other cash equivalents', 'code_label' => '13', 'prefixes' => ['13']],
        ['section' => 'Assets', 'label' => 'Purchases / Inventory', 'code_label' => '21', 'prefixes' => ['21']],
        ['section' => 'Assets', 'label' => 'Goods for resale', 'code_label' => '22', 'prefixes' => ['22']],
        ['section' => 'Assets', 'label' => 'Raw materials & consumables', 'code_label' => '23', 'prefixes' => ['23']],
        ['section' => 'Assets', 'label' => 'Finished & intermediate products', 'code_label' => '24', 'prefixes' => ['24']],
        ['section' => 'Assets', 'label' => 'Other inventories', 'code_label' => '25,26,27,28,29', 'prefixes' => ['25', '26', '27', '28', '29']],
        ['section' => 'Assets', 'label' => 'Tangible fixed assets', 'code_label' => '32', 'prefixes' => ['32']],
        ['section' => 'Assets', 'label' => 'Intangible assets', 'code_label' => '33', 'prefixes' => ['33']],
        ['section' => 'Assets', 'label' => 'Financial investments', 'code_label' => '31,34,35', 'prefixes' => ['31', '34', '35']],
        ['section' => 'Assets', 'label' => 'Clients / Trade receivables', 'code_label' => '41', 'prefixes' => ['41']],
        ['section' => 'Assets', 'label' => 'Other debtors', 'code_label' => '45', 'prefixes' => ['45']],
        ['section' => 'Assets', 'label' => 'VAT recoverable & related', 'code_label' => '4432,4438', 'prefixes' => ['4432', '4438', '4431', '4434', '4439']],
        ['section' => 'Assets', 'label' => 'Deferred tax assets', 'code_label' => '446', 'prefixes' => ['446']],

        // Liabilities
        ['section' => 'Liabilities', 'label' => 'Suppliers', 'code_label' => '42', 'prefixes' => ['42']],
        ['section' => 'Liabilities', 'label' => 'State / Tax authorities', 'code_label' => '44', 'prefixes' => ['44']],
        ['section' => 'Liabilities', 'label' => 'VAT payable', 'code_label' => '4437', 'prefixes' => ['4437']],
        ['section' => 'Liabilities', 'label' => 'Income tax payable', 'code_label' => '441', 'prefixes' => ['441']],
        ['section' => 'Liabilities', 'label' => 'Other creditors', 'code_label' => '46', 'prefixes' => ['46']],
        ['section' => 'Liabilities', 'label' => 'Borrowings & financing', 'code_label' => '25,26', 'prefixes' => ['251', '252', '261', '262']],

        // Equity
        ['section' => 'Equity', 'label' => 'Share capital', 'code_label' => '51', 'prefixes' => ['51']],
        ['section' => 'Equity', 'label' => 'Share premiums & reserves', 'code_label' => '52,55', 'prefixes' => ['52', '55']],
        ['section' => 'Equity', 'label' => 'Revaluation reserves', 'code_label' => '56', 'prefixes' => ['56']],
        ['section' => 'Equity', 'label' => 'Retained earnings / other equity', 'code_label' => '59', 'prefixes' => ['59']],
        ['section' => 'Equity', 'label' => 'Net results', 'code_label' => '88', 'prefixes' => ['88']],
        ['section' => 'Equity', 'label' => 'Current / interim results', 'code_label' => '81,82,83', 'prefixes' => ['81', '82', '83']],
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
            if ($mapping['prefixes'] === []) {
                continue;
            }

            $current = $this->sumByCodePrefixes(
                $mapping['prefixes'],
                $mapping['section'],
                $currentEnd,
                $creatorId
            );
            $previous = $this->sumByCodePrefixes(
                $mapping['prefixes'],
                $mapping['section'],
                $previousEnd,
                $creatorId
            );

            // Always keep structural lines the client expects (21, 44) even at zero.
            $alwaysShow = in_array($mapping['code_label'], ['21', '44', '11', '12', '41', '51', '88'], true);
            if (! $alwaysShow && ! $this->hasBalance($current) && ! $this->hasBalance($previous)) {
                continue;
            }

            $rows[] = [
                'section' => $mapping['section'],
                'label' => $mapping['label'],
                'code_label' => $mapping['code_label'],
                'current_year' => $current,
                'previous_year' => $previous,
            ];
        }

        $assetsCurrent = $this->sectionTotal('Assets', $currentEnd, $creatorId);
        $assetsPrevious = $this->sectionTotal('Assets', $previousEnd, $creatorId);
        $liabilitiesCurrent = $this->sectionTotal('Liabilities', $currentEnd, $creatorId);
        $liabilitiesPrevious = $this->sectionTotal('Liabilities', $previousEnd, $creatorId);
        $equityCurrent = $this->sectionTotal('Equity', $currentEnd, $creatorId);
        $equityPrevious = $this->sectionTotal('Equity', $previousEnd, $creatorId);

        // Unclosed P&L for the period (Income − Expenses). Do not add Results (81–88)
        // again — those live under Equity after / during closing.
        $netIncomeCurrent = $this->netIncome($currentEnd, $creatorId);
        $netIncomePrevious = $this->netIncome($previousEnd, $creatorId);

        // Avoid double-counting when 81/82/83/88 already hold the result.
        $resultsAlreadyInEquityCurrent = $this->sumByCodePrefixes(
            ['81', '82', '83', '88'],
            'Equity',
            $currentEnd,
            $creatorId
        );
        $resultsAlreadyInEquityPrevious = $this->sumByCodePrefixes(
            ['81', '82', '83', '88'],
            'Equity',
            $previousEnd,
            $creatorId
        );

        $addNetCurrent = $this->hasBalance($netIncomeCurrent) && abs($resultsAlreadyInEquityCurrent) < 0.01;
        $addNetPrevious = $this->hasBalance($netIncomePrevious) && abs($resultsAlreadyInEquityPrevious) < 0.01;

        if ($addNetCurrent || $addNetPrevious) {
            $rows[] = [
                'section' => 'Equity',
                'label' => 'Current Period Result',
                'code_label' => '',
                'current_year' => $addNetCurrent ? $netIncomeCurrent : 0.0,
                'previous_year' => $addNetPrevious ? $netIncomePrevious : 0.0,
            ];
        }

        $equityWithResultCurrent = $equityCurrent + ($addNetCurrent ? $netIncomeCurrent : 0.0);
        $equityWithResultPrevious = $equityPrevious + ($addNetPrevious ? $netIncomePrevious : 0.0);

        // ASSETS = LIABILITIES + EQUITY
        $totals = [
            'assets' => ['current' => $assetsCurrent, 'previous' => $assetsPrevious],
            'liabilities' => ['current' => $liabilitiesCurrent, 'previous' => $liabilitiesPrevious],
            'equity' => ['current' => $equityWithResultCurrent, 'previous' => $equityWithResultPrevious],
            'equity_liabilities' => [
                'current' => round($liabilitiesCurrent + $equityWithResultCurrent, 2),
                'previous' => round($liabilitiesPrevious + $equityWithResultPrevious, 2),
            ],
            'difference' => [
                'current' => round($assetsCurrent - ($liabilitiesCurrent + $equityWithResultCurrent), 2),
                'previous' => round($assetsPrevious - ($liabilitiesPrevious + $equityWithResultPrevious), 2),
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

        return round($income - $cogs - $expenses, 2);
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
            ->whereDate('transaction_lines.date', '<=', $asOf->toDateString())
            ->where(function ($q) use ($prefixes) {
                foreach ($prefixes as $prefix) {
                    // Exact code or proper descendant (21 matches 21, 210, 211 — not 29).
                    $q->orWhere('chart_of_accounts.code', $prefix)
                        ->orWhere('chart_of_accounts.code', 'like', $prefix . '%');
                }
            });

        // Prefer matching section type so Assets/Liabilities signs stay correct.
        $typeNames = $this->typeAliases($section);
        $query->whereIn('chart_of_account_types.name', $typeNames);

        return round((float) ($query->selectRaw("COALESCE(SUM({$balanceExpr}), 0) as balance")->value('balance') ?? 0), 2);
    }

    private function sumByAccountType(string $typeName, Carbon $asOf, int $creatorId): float
    {
        $aliases = $this->typeAliases($typeName);
        $exists = ChartOfAccountType::where('created_by', $creatorId)
            ->whereIn('name', $aliases)
            ->exists();
        if (! $exists) {
            return 0.0;
        }

        $balanceExpr = $this->balanceExpression($typeName);

        $value = TransactionLines::query()
            ->join('chart_of_accounts', 'transaction_lines.account_id', '=', 'chart_of_accounts.id')
            ->join('chart_of_account_types', 'chart_of_accounts.type', '=', 'chart_of_account_types.id')
            ->where('transaction_lines.created_by', $creatorId)
            ->where('chart_of_accounts.created_by', $creatorId)
            ->whereIn('chart_of_account_types.name', $aliases)
            ->whereDate('transaction_lines.date', '<=', $asOf->toDateString())
            ->selectRaw("COALESCE(SUM({$balanceExpr}), 0) as balance")
            ->value('balance');

        return round((float) ($value ?? 0), 2);
    }

    /**
     * @return list<string>
     */
    private function typeAliases(string $typeName): array
    {
        return match ($typeName) {
            'Assets' => ['Assets', 'Asset'],
            'Liabilities' => ['Liabilities', 'Liability'],
            'Equity' => ['Equity', 'Capital', 'Equity and Liabilities'],
            'Income' => ['Income', 'Revenue'],
            'Expenses' => ['Expenses', 'Expense'],
            'Costs of Goods Sold' => ['Costs of Goods Sold', 'Cost of Goods Sold', 'COGS'],
            'Results' => ['Results', 'Result'],
            default => [$typeName],
        };
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
