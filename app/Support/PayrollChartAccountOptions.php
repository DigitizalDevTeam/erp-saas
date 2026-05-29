<?php

namespace App\Support;

use App\Models\ChartOfAccountType;
use Illuminate\Support\Collection;

class PayrollChartAccountOptions
{
    public static function listForCurrentUser(string $placeholder = 'Select chart of account'): Collection
    {
        $creatorId = \Auth::user()->creatorId();
        $expenseType = ChartOfAccountType::where('created_by', $creatorId)
            ->where(function ($q) {
                $q->where('name', 'Expenses')
                    ->orWhere('name_pt_br', 'Despesas');
            })
            ->first();

        return self::listByType($creatorId, $expenseType, $placeholder);
    }

    public static function listAssetsForCurrentUser(string $placeholder = 'Select chart of account'): Collection
    {
        $creatorId = \Auth::user()->creatorId();
        $assetType = ChartOfAccountType::where('created_by', $creatorId)
            ->where(function ($q) {
                $q->where('name', 'Assets')
                    ->orWhere('name_pt_br', 'Ativos');
            })
            ->first();

        return self::listByType($creatorId, $assetType, $placeholder);
    }

    public static function listIncomeForCurrentUser(string $placeholder = 'Select chart of account'): Collection
    {
        $creatorId = \Auth::user()->creatorId();
        $incomeType = ChartOfAccountType::where('created_by', $creatorId)
            ->where(function ($q) {
                $q->where('name', 'Income')
                    ->orWhere('name_pt_br', 'Receita / Rendimento');
            })
            ->first();

        return self::listByType($creatorId, $incomeType, $placeholder);
    }

    public static function listLoanDeductionAccountsForCurrentUser(string $placeholder = 'Select chart of account'): Collection
    {
        $head = collect(['' => __($placeholder)]);
        $assets = self::listAssetsForCurrentUser('')->except('');
        $liabs = self::listLiabilitiesForCurrentUser('')->except('');

        return $head->merge($assets)->merge($liabs);
    }

    public static function listLiabilitiesForCurrentUser(string $placeholder = 'Select chart of account'): Collection
    {
        $creatorId = \Auth::user()->creatorId();
        $liabilityType = ChartOfAccountType::where('created_by', $creatorId)
            ->where(function ($q) {
                $q->where('name', 'Liabilities')
                    ->orWhere('name_pt_br', 'Passivos');
            })
            ->first();

        return self::listByType($creatorId, $liabilityType, $placeholder);
    }

    private static function listByType(int $creatorId, ?ChartOfAccountType $type, string $placeholder): Collection
    {
        $options = collect(['' => __($placeholder)]);
        if (! $type) {
            return $options;
        }

        ChartOfAccountHierarchy::backfillLegacyParentLinks($creatorId);
        $accounts = ChartOfAccountHierarchy::accountsForCreator($creatorId, (int) $type->id);
        $tree = ChartOfAccountHierarchy::buildTree($accounts);

        ChartOfAccountHierarchy::walkTree($tree, function ($node, $depth) use (&$options) {
            $prefix = $depth > 0 ? str_repeat('· ', $depth) : '';
            $options[$node->id] = $prefix . $node->code . ' — ' . $node->labelForLocale();
        });

        return $options;
    }
}
