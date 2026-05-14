<?php

namespace App\Support;

use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountParent;
use App\Models\ChartOfAccountType;

class PayrollChartAccountOptions
{
    public static function listForCurrentUser(string $placeholder = 'Select chart of account')
    {
        $creatorId = \Auth::user()->creatorId();
        $nameColumn = 'name'; // ordering only; display uses ChartOfAccount::labelForLocale()
        $options = collect(['' => __($placeholder)]);

        $expenseType = ChartOfAccountType::where('created_by', $creatorId)
            ->where(function ($q) {
                $q->where('name', 'Expenses')
                    ->orWhere('name_pt_br', 'Despesas');
            })
            ->first();

        if (! $expenseType) {
            return $options;
        }

        $seen = [];
        $add = function (int $id, string $label) use (&$options, &$seen) {
            if ($id <= 0 || isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            $options[$id] = $label;
        };

        $parents = ChartOfAccountParent::where('created_by', $creatorId)
            ->where('type', $expenseType->id)
            ->with([
                'accountMain',
                'children' => function ($q) use ($nameColumn) {
                    $q->orderByRaw('CAST(code AS UNSIGNED)')->orderBy($nameColumn);
                },
                'children.subChildren' => function ($q) use ($nameColumn) {
                    $q->orderByRaw('CAST(code AS UNSIGNED)')->orderBy($nameColumn);
                },
            ])
            ->get()
            ->sortBy(function ($p) use ($nameColumn) {
                return [(int) ($p->accountMain->code ?? 0), $p->{$nameColumn} ?? $p->name ?? ''];
            });

        foreach ($parents as $parent) {
            if ($parent->accountMain) {
                $add($parent->accountMain->id, self::label($parent->accountMain, 0));
            }
            foreach ($parent->children as $child) {
                $add($child->id, self::label($child, 1));
                foreach ($child->subChildren as $sub) {
                    $add($sub->id, self::label($sub, 2));
                }
            }
        }

        $orphanQuery = ChartOfAccount::query()
            ->where('created_by', $creatorId)
            ->where('type', $expenseType->id);
        if ($seen !== []) {
            $orphanQuery->whereNotIn('id', array_keys($seen));
        }
        $orphans = $orphanQuery
            ->orderByRaw('CAST(code AS UNSIGNED)')
            ->orderBy($nameColumn)
            ->get();

        foreach ($orphans as $row) {
            $depth = ((int) $row->parent_account_id > 0) ? 2 : (((int) $row->parent > 0) ? 1 : 0);
            $add($row->id, self::label($row, $depth));
        }

        return $options;
    }

    /**
     * Asset accounts (e.g. employee advances / loans receivable).
     */
    public static function listAssetsForCurrentUser(string $placeholder = 'Select chart of account')
    {
        $creatorId = \Auth::user()->creatorId();
        $nameColumn = 'name';
        $options = collect(['' => __($placeholder)]);

        $assetType = ChartOfAccountType::where('created_by', $creatorId)
            ->where(function ($q) {
                $q->where('name', 'Assets')
                    ->orWhere('name_pt_br', 'Ativos');
            })
            ->first();

        if (! $assetType) {
            return $options;
        }

        $seen = [];
        $add = function (int $id, string $label) use (&$options, &$seen) {
            if ($id <= 0 || isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            $options[$id] = $label;
        };

        $parents = ChartOfAccountParent::where('created_by', $creatorId)
            ->where('type', $assetType->id)
            ->with([
                'accountMain',
                'children' => function ($q) use ($nameColumn) {
                    $q->orderByRaw('CAST(code AS UNSIGNED)')->orderBy($nameColumn);
                },
                'children.subChildren' => function ($q) use ($nameColumn) {
                    $q->orderByRaw('CAST(code AS UNSIGNED)')->orderBy($nameColumn);
                },
            ])
            ->get()
            ->sortBy(function ($p) use ($nameColumn) {
                return [(int) ($p->accountMain->code ?? 0), $p->{$nameColumn} ?? $p->name ?? ''];
            });

        foreach ($parents as $parent) {
            if ($parent->accountMain) {
                $add($parent->accountMain->id, self::label($parent->accountMain, 0));
            }
            foreach ($parent->children as $child) {
                $add($child->id, self::label($child, 1));
                foreach ($child->subChildren as $sub) {
                    $add($sub->id, self::label($sub, 2));
                }
            }
        }

        $orphanQuery = ChartOfAccount::query()
            ->where('created_by', $creatorId)
            ->where('type', $assetType->id);
        if ($seen !== []) {
            $orphanQuery->whereNotIn('id', array_keys($seen));
        }
        $orphans = $orphanQuery
            ->orderByRaw('CAST(code AS UNSIGNED)')
            ->orderBy($nameColumn)
            ->get();

        foreach ($orphans as $row) {
            $depth = ((int) $row->parent_account_id > 0) ? 2 : (((int) $row->parent > 0) ? 1 : 0);
            $add($row->id, self::label($row, $depth));
        }

        return $options;
    }

    /**
     * Income / revenue accounts (product default sale account, hotel folio revenue mapping, etc.).
     */
    public static function listIncomeForCurrentUser(string $placeholder = 'Select chart of account')
    {
        $creatorId = \Auth::user()->creatorId();
        $nameColumn = 'name';
        $options = collect(['' => __($placeholder)]);

        $incomeType = ChartOfAccountType::where('created_by', $creatorId)
            ->where(function ($q) {
                $q->where('name', 'Income')
                    ->orWhere('name_pt_br', 'Receita / Rendimento');
            })
            ->first();

        if (! $incomeType) {
            return $options;
        }

        $seen = [];
        $add = function (int $id, string $label) use (&$options, &$seen) {
            if ($id <= 0 || isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            $options[$id] = $label;
        };

        $parents = ChartOfAccountParent::where('created_by', $creatorId)
            ->where('type', $incomeType->id)
            ->with([
                'accountMain',
                'children' => function ($q) use ($nameColumn) {
                    $q->orderByRaw('CAST(code AS UNSIGNED)')->orderBy($nameColumn);
                },
                'children.subChildren' => function ($q) use ($nameColumn) {
                    $q->orderByRaw('CAST(code AS UNSIGNED)')->orderBy($nameColumn);
                },
            ])
            ->get()
            ->sortBy(function ($p) use ($nameColumn) {
                return [(int) ($p->accountMain->code ?? 0), $p->{$nameColumn} ?? $p->name ?? ''];
            });

        foreach ($parents as $parent) {
            if ($parent->accountMain) {
                $add($parent->accountMain->id, self::label($parent->accountMain, 0));
            }
            foreach ($parent->children as $child) {
                $add($child->id, self::label($child, 1));
                foreach ($child->subChildren as $sub) {
                    $add($sub->id, self::label($sub, 2));
                }
            }
        }

        $orphanQuery = ChartOfAccount::query()
            ->where('created_by', $creatorId)
            ->where('type', $incomeType->id);
        if ($seen !== []) {
            $orphanQuery->whereNotIn('id', array_keys($seen));
        }
        $orphans = $orphanQuery
            ->orderByRaw('CAST(code AS UNSIGNED)')
            ->orderBy($nameColumn)
            ->get();

        foreach ($orphans as $row) {
            $depth = ((int) $row->parent_account_id > 0) ? 2 : (((int) $row->parent > 0) ? 1 : 0);
            $add($row->id, self::label($row, $depth));
        }

        return $options;
    }

    /**
     * Accounts suitable for payroll loan deductions (recovery): assets first, then liabilities.
     */
    public static function listLoanDeductionAccountsForCurrentUser(string $placeholder = 'Select chart of account')
    {
        $head = collect(['' => __($placeholder)]);
        $assets = self::listAssetsForCurrentUser('')->except('');
        $liabs = self::listLiabilitiesForCurrentUser('')->except('');

        return $head->merge($assets)->merge($liabs);
    }

    /**
     * Liability accounts (IRPS payable, salaries payable, etc.).
     */
    public static function listLiabilitiesForCurrentUser(string $placeholder = 'Select chart of account')
    {
        $creatorId = \Auth::user()->creatorId();
        $nameColumn = 'name';
        $options = collect(['' => __($placeholder)]);

        $liabilityType = ChartOfAccountType::where('created_by', $creatorId)
            ->where(function ($q) {
                $q->where('name', 'Liabilities')
                    ->orWhere('name_pt_br', 'Passivos');
            })
            ->first();

        if (! $liabilityType) {
            return $options;
        }

        $seen = [];
        $add = function (int $id, string $label) use (&$options, &$seen) {
            if ($id <= 0 || isset($seen[$id])) {
                return;
            }
            $seen[$id] = true;
            $options[$id] = $label;
        };

        $parents = ChartOfAccountParent::where('created_by', $creatorId)
            ->where('type', $liabilityType->id)
            ->with([
                'accountMain',
                'children' => function ($q) use ($nameColumn) {
                    $q->orderByRaw('CAST(code AS UNSIGNED)')->orderBy($nameColumn);
                },
                'children.subChildren' => function ($q) use ($nameColumn) {
                    $q->orderByRaw('CAST(code AS UNSIGNED)')->orderBy($nameColumn);
                },
            ])
            ->get()
            ->sortBy(function ($p) use ($nameColumn) {
                return [(int) ($p->accountMain->code ?? 0), $p->{$nameColumn} ?? $p->name ?? ''];
            });

        foreach ($parents as $parent) {
            if ($parent->accountMain) {
                $add($parent->accountMain->id, self::label($parent->accountMain, 0));
            }
            foreach ($parent->children as $child) {
                $add($child->id, self::label($child, 1));
                foreach ($child->subChildren as $sub) {
                    $add($sub->id, self::label($sub, 2));
                }
            }
        }

        $orphanQuery = ChartOfAccount::query()
            ->where('created_by', $creatorId)
            ->where('type', $liabilityType->id);
        if ($seen !== []) {
            $orphanQuery->whereNotIn('id', array_keys($seen));
        }
        $orphans = $orphanQuery
            ->orderByRaw('CAST(code AS UNSIGNED)')
            ->orderBy($nameColumn)
            ->get();

        foreach ($orphans as $row) {
            $depth = ((int) $row->parent_account_id > 0) ? 2 : (((int) $row->parent > 0) ? 1 : 0);
            $add($row->id, self::label($row, $depth));
        }

        return $options;
    }

    private static function label(ChartOfAccount $account, int $depth): string
    {
        $prefix = $depth > 0 ? str_repeat('· ', $depth) : '';

        return $prefix . $account->code . ' — ' . $account->labelForLocale();
    }
}
