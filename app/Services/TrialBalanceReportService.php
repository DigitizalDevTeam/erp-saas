<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountType;
use App\Models\TransactionLines;
use App\Support\ChartOfAccountHierarchy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TrialBalanceReportService
{
    /**
     * Build trial balance rows grouped by account type.
     *
     * @return array{
     *     totalAccounts: array<string, list<array<string, mixed>>>,
     *     grandTotalDebit: float,
     *     grandTotalCredit: float,
     *     filter: array{startDateRange: string, endDateRange: string}
     * }
     */
    public function build(int $creatorId, string $start, string $end, string $view = 'expand'): array
    {
        ChartOfAccountHierarchy::backfillLegacyParentLinks($creatorId);

        $balances = $this->accountBalances($creatorId, $start, $end);
        $grandTotals = $this->grandTotals($creatorId, $start, $end);

        $sections = [];
        $types = ChartOfAccountType::where('created_by', $creatorId)->orderBy('id')->get();

        foreach ($types as $type) {
            $accounts = ChartOfAccountHierarchy::accountsForCreator($creatorId, (int) $type->id);
            if ($accounts->isEmpty()) {
                continue;
            }

            $tree = ChartOfAccountHierarchy::buildTree($accounts);
            $rows = [];

            foreach ($tree as $root) {
                $result = $this->rowsForNode($root, $balances, $view, 0);
                $rows = array_merge($rows, $result['rows']);
            }

            if ($rows !== []) {
                $sections[$type->name] = $rows;
            }
        }

        return [
            'totalAccounts' => $sections,
            'grandTotalDebit' => $grandTotals['totalDebit'],
            'grandTotalCredit' => $grandTotals['totalCredit'],
            'filter' => [
                'startDateRange' => $start,
                'endDateRange' => $end,
            ],
        ];
    }

    /**
     * @return array<int, array{totalDebit: float, totalCredit: float}>
     */
    private function accountBalances(int $creatorId, string $start, string $end): array
    {
        $rows = TransactionLines::query()
            ->select(
                'account_id',
                DB::raw('SUM(debit) as totalDebit'),
                DB::raw('SUM(credit) as totalCredit')
            )
            ->where('created_by', $creatorId)
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end)
            ->groupBy('account_id')
            ->get();

        $balances = [];
        foreach ($rows as $row) {
            $normalized = $this->normalizeAmounts(
                (float) $row->totalDebit,
                (float) $row->totalCredit
            );
            if ($this->hasActivity($normalized['totalDebit'], $normalized['totalCredit'])) {
                $balances[(int) $row->account_id] = $normalized;
            }
        }

        return $balances;
    }

    /**
     * @return array{totalDebit: float, totalCredit: float}
     */
    private function grandTotals(int $creatorId, string $start, string $end): array
    {
        $totals = TransactionLines::query()
            ->select(
                DB::raw('COALESCE(SUM(debit), 0) as totalDebit'),
                DB::raw('COALESCE(SUM(credit), 0) as totalCredit')
            )
            ->where('created_by', $creatorId)
            ->whereDate('date', '>=', $start)
            ->whereDate('date', '<=', $end)
            ->first();

        return $this->normalizeAmounts(
            (float) ($totals->totalDebit ?? 0),
            (float) ($totals->totalCredit ?? 0)
        );
    }

    /**
     * @param  array<int, array{totalDebit: float, totalCredit: float}>  $balances
     * @return array{rows: list<array<string, mixed>>, subtreeDebit: float, subtreeCredit: float}
     */
    private function rowsForNode(ChartOfAccount $node, array $balances, string $view, int $depth): array
    {
        $direct = $balances[(int) $node->id] ?? ['totalDebit' => 0.0, 'totalCredit' => 0.0];

        /** @var Collection<int, ChartOfAccount> $children */
        $children = $node->tree_children ?? collect();

        $childRows = [];
        $childDebit = 0.0;
        $childCredit = 0.0;

        foreach ($children as $child) {
            $childResult = $this->rowsForNode($child, $balances, $view, $depth + 1);
            if ($childResult['rows'] === []) {
                continue;
            }
            $childRows = array_merge($childRows, $childResult['rows']);
            $childDebit += $childResult['subtreeDebit'];
            $childCredit += $childResult['subtreeCredit'];
        }

        $subtreeDebit = $direct['totalDebit'] + $childDebit;
        $subtreeCredit = $direct['totalCredit'] + $childCredit;

        if (! $this->hasActivity($subtreeDebit, $subtreeCredit)) {
            return ['rows' => [], 'subtreeDebit' => 0.0, 'subtreeCredit' => 0.0];
        }

        $rows = [];
        $hasActiveChildren = $childRows !== [];

        if ($hasActiveChildren) {
            if ($view !== 'collapse' || $depth === 0) {
                $rows[] = $this->makeRow($node, 'parent', $subtreeDebit, $subtreeCredit);
            }

            if ($view === 'expand') {
                $rows = array_merge($rows, $childRows);
            }
        } elseif ($this->hasActivity($direct['totalDebit'], $direct['totalCredit'])) {
            $role = $depth === 0 ? '' : 'subAccount';
            $rows[] = $this->makeRow($node, $role, $direct['totalDebit'], $direct['totalCredit']);
        }

        return [
            'rows' => $rows,
            'subtreeDebit' => $subtreeDebit,
            'subtreeCredit' => $subtreeCredit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function makeRow(ChartOfAccount $account, string $role, float $totalDebit, float $totalCredit): array
    {
        $amounts = $this->normalizeAmounts($totalDebit, $totalCredit);

        return [
            'account_id' => (int) $account->id,
            'account_code' => (string) ($account->code ?? ''),
            'account_name' => $account->labelForLocale(),
            'account' => $role,
            'totalDebit' => $amounts['totalDebit'],
            'totalCredit' => $amounts['totalCredit'],
            'balanceDebit' => $amounts['totalDebit'] > $amounts['totalCredit']
                ? $amounts['totalDebit'] - $amounts['totalCredit']
                : 0.0,
            'balanceCredit' => $amounts['totalCredit'] > $amounts['totalDebit']
                ? $amounts['totalCredit'] - $amounts['totalDebit']
                : 0.0,
        ];
    }

    /**
     * @return array{totalDebit: float, totalCredit: float}
     */
    private function normalizeAmounts(float $totalDebit, float $totalCredit): array
    {
        if ($totalDebit < 0) {
            $totalCredit += abs($totalDebit);
            $totalDebit = 0.0;
        }

        if ($totalCredit < 0) {
            $totalDebit += abs($totalCredit);
            $totalCredit = 0.0;
        }

        return [
            'totalDebit' => round($totalDebit, 2),
            'totalCredit' => round($totalCredit, 2),
        ];
    }

    private function hasActivity(float $totalDebit, float $totalCredit): bool
    {
        return abs($totalDebit) > 0.00001 || abs($totalCredit) > 0.00001;
    }
}
