<?php

namespace App\Support;

use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountParent;
use App\Models\ChartOfAccountSubType;
use Illuminate\Support\Collection;

class ChartOfAccountHierarchy
{
    /** @var array<int, int|null> */
    private static array $parentLinkCache = [];

    /** @var array<int, ChartOfAccountParent|null> */
    private static array $parentRecordCache = [];

    public static function clearCache(): void
    {
        self::$parentLinkCache = [];
        self::$parentRecordCache = [];
    }

    /**
     * Effective parent account id (chart_of_accounts.id), resolving legacy `parent` → ChartOfAccountParent.
     */
    public static function effectiveParentAccountId(ChartOfAccount $account): ?int
    {
        if (! empty($account->parent_account_id)) {
            return (int) $account->parent_account_id;
        }

        if ((int) $account->parent > 0) {
            $link = self::parentRecordById((int) $account->parent);

            return $link && $link->account ? (int) $link->account : null;
        }

        return null;
    }

    /**
     * Top-level account id in the tree (for legacy `parent` column on reports).
     */
    public static function rootAccountId(ChartOfAccount $account): int
    {
        $current = $account;
        $guard = 0;

        while ($guard++ < 100) {
            $parentId = self::effectiveParentAccountId($current);
            if (! $parentId) {
                return (int) $current->id;
            }
            $parent = ChartOfAccount::find($parentId);
            if (! $parent) {
                return (int) $current->id;
            }
            $current = $parent;
        }

        return (int) $account->id;
    }

    public static function legacyParentRowId(?int $parentAccountId): int
    {
        if (! $parentAccountId) {
            return 0;
        }

        $rootId = self::rootAccountId(ChartOfAccount::find($parentAccountId) ?? new ChartOfAccount(['id' => $parentAccountId]));
        $link = ChartOfAccountParent::where('account', $rootId)->first();

        return $link ? (int) $link->id : 0;
    }

    /**
     * @return Collection<int, ChartOfAccount>
     */
    public static function accountsForCreator(int $creatorId, ?int $typeId = null): Collection
    {
        $query = ChartOfAccount::where('created_by', $creatorId);
        if ($typeId) {
            $query->where('type', $typeId);
        }

        return $query->get();
    }

    /**
     * Build nested tree: each account gets dynamic `tree_children` collection.
     *
     * @return Collection<int, ChartOfAccount>
     */
    public static function buildTree(Collection $accounts, string $nameColumn = 'name'): Collection
    {
        self::clearCache();

        $childrenByParent = [];
        foreach ($accounts as $account) {
            $parentId = self::effectiveParentAccountId($account);
            if ($parentId) {
                $childrenByParent[$parentId][] = $account;
            }
        }

        $sorter = static function (ChartOfAccount $a, ChartOfAccount $b) use ($nameColumn) {
            $codeA = (int) ($a->code ?? 0);
            $codeB = (int) ($b->code ?? 0);
            if ($codeA !== $codeB) {
                return $codeA <=> $codeB;
            }

            return strcmp((string) ($a->{$nameColumn} ?? $a->name ?? ''), (string) ($b->{$nameColumn} ?? $b->name ?? ''));
        };

        $attach = null;
        $attach = static function (ChartOfAccount $node) use (&$attach, $childrenByParent, $sorter) {
            $kids = collect($childrenByParent[$node->id] ?? [])->sort($sorter)->values();
            foreach ($kids as $child) {
                $attach($child);
            }
            $node->setRelation('tree_children', $kids);

            return $node;
        };

        $accountsById = $accounts->keyBy('id');
        $roots = $accounts->filter(static function (ChartOfAccount $account) use ($accountsById) {
            $parentId = self::effectiveParentAccountId($account);

            return $parentId === null || ! $accountsById->has($parentId);
        })->sort($sorter)->values();

        return $roots->map($attach);
    }

    public static function accountMatchesSearch(ChartOfAccount $account, string $search, string $nameColumn = 'name'): bool
    {
        if ($search === '') {
            return true;
        }
        $needle = mb_strtolower($search);

        return str_contains(mb_strtolower((string) ($account->code ?? '')), $needle)
            || str_contains(mb_strtolower((string) ($account->{$nameColumn} ?? $account->name ?? '')), $needle)
            || str_contains(mb_strtolower((string) ($account->name ?? '')), $needle);
    }

    /**
     * Keep node if it or any descendant matches search.
     */
    public static function pruneTreeForSearch(ChartOfAccount $node, string $search, string $nameColumn = 'name'): bool
    {
        if ($search === '') {
            return true;
        }

        $children = $node->tree_children ?? collect();
        $keptChildren = collect();
        foreach ($children as $child) {
            if (self::pruneTreeForSearch($child, $search, $nameColumn)) {
                $keptChildren->push($child);
            }
        }
        $node->setRelation('tree_children', $keptChildren);

        return self::accountMatchesSearch($node, $search, $nameColumn) || $keptChildren->isNotEmpty();
    }

    /**
     * All accounts available as parents for a given sub-type (includes custom + default).
     *
     * @return Collection<int, ChartOfAccount>
     */
    public static function accountsForParentPicker(int $creatorId, int $subTypeId): Collection
    {
        self::backfillLegacyParentLinks($creatorId);

        $subType = ChartOfAccountSubType::find($subTypeId);
        if (! $subType) {
            return collect();
        }

        $typeId = (int) $subType->type;

        return ChartOfAccount::where('created_by', $creatorId)
            ->where(function ($q) use ($typeId, $subTypeId) {
                $q->where('type', $typeId)
                    ->orWhere('sub_type', $subTypeId);
            })
            ->orderByRaw('CAST(code AS UNSIGNED)')
            ->orderBy('name')
            ->get();
    }

    /**
     * Flat id => label map — every account in the set (not only tree roots).
     *
     * @return array<int|string, string>
     */
    public static function flatParentOptions(Collection $accounts, ?int $excludeAccountId = null, string $placeholder = 'Select an account'): array
    {
        $options = ['0' => __($placeholder)];
        if ($accounts->isEmpty()) {
            return $options;
        }

        $blocked = self::blockedIdsForParentPicker($excludeAccountId);
        $byId = $accounts->keyBy('id');
        $depthCache = [];

        $depthOf = static function (ChartOfAccount $account) use (&$depthOf, &$depthCache, $byId): int {
            $id = (int) $account->id;
            if (isset($depthCache[$id])) {
                return $depthCache[$id];
            }
            $parentId = self::effectiveParentAccountId($account);
            if (! $parentId || ! $byId->has($parentId)) {
                return $depthCache[$id] = 0;
            }

            return $depthCache[$id] = 1 + $depthOf($byId->get($parentId));
        };

        $sorted = $accounts->sort(function (ChartOfAccount $a, ChartOfAccount $b) use ($depthOf) {
            $depthCmp = $depthOf($a) <=> $depthOf($b);
            if ($depthCmp !== 0) {
                return $depthCmp;
            }
            $codeA = (int) ($a->code ?? 0);
            $codeB = (int) ($b->code ?? 0);
            if ($codeA !== $codeB) {
                return $codeA <=> $codeB;
            }

            return strcmp($a->labelForLocale(), $b->labelForLocale());
        });

        foreach ($sorted as $account) {
            if (in_array((int) $account->id, $blocked, true)) {
                continue;
            }
            $depth = $depthOf($account);
            $prefix = $depth > 0 ? str_repeat('· ', $depth) : '';
            $options[(int) $account->id] = $prefix . $account->code . ' — ' . $account->labelForLocale();
        }

        return $options;
    }

    /**
     * Flat id => label map with indentation (any depth).
     *
     * @return array<int|string, string>
     */
    public static function optionsForSubType(int $creatorId, int $subTypeId, ?int $excludeAccountId = null, string $placeholder = 'Select an account'): array
    {
        $accounts = self::accountsForParentPicker($creatorId, $subTypeId);

        if ($accounts->isEmpty()) {
            return ['0' => __($placeholder)];
        }

        return self::flatParentOptions($accounts, $excludeAccountId, $placeholder);
    }

    /**
     * Options for entire account type (all sub-types under type).
     *
     * @return array<int|string, string>
     */
    public static function optionsForType(int $creatorId, int $typeId, ?int $excludeAccountId = null): array
    {
        self::backfillLegacyParentLinks($creatorId);
        $accounts = self::accountsForCreator($creatorId, $typeId);

        return self::flatParentOptions($accounts, $excludeAccountId, 'Select an account');
    }

    /**
     * @return int[]
     */
    public static function blockedIdsForParentPicker(?int $accountId): array
    {
        if (! $accountId) {
            return [];
        }

        return array_merge([(int) $accountId], self::descendantIds($accountId));
    }

    /**
     * Walk tree and invoke callback for each account (depth-first).
     */
    public static function walkTree(Collection $roots, callable $callback, int $depth = 0): void
    {
        foreach ($roots as $node) {
            $callback($node, $depth);
            $children = $node->tree_children ?? collect();
            if ($children->isNotEmpty()) {
                self::walkTree($children, $callback, $depth + 1);
            }
        }
    }

    public static function isDescendantOf(ChartOfAccount $account, ?int $ancestorId): bool
    {
        if (! $ancestorId) {
            return false;
        }

        $current = $account;
        $guard = 0;
        while ($guard++ < 100) {
            $parentId = self::effectiveParentAccountId($current);
            if (! $parentId) {
                return false;
            }
            if ((int) $parentId === (int) $ancestorId) {
                return true;
            }
            $parent = ChartOfAccount::find($parentId);
            if (! $parent) {
                return false;
            }
            $current = $parent;
        }

        return false;
    }

    public static function descendantIds(int $accountId, ?int $creatorId = null): array
    {
        $creatorId = $creatorId ?? (int) (ChartOfAccount::find($accountId)?->created_by ?? 0);
        if ($creatorId <= 0) {
            return [];
        }

        $all = ChartOfAccount::where('created_by', $creatorId)->get();
        $ids = [];
        $collect = static function (int $id) use (&$collect, $all, &$ids) {
            foreach ($all as $row) {
                if (self::effectiveParentAccountId($row) === $id) {
                    $ids[] = (int) $row->id;
                    $collect((int) $row->id);
                }
            }
        };
        $collect($accountId);

        return $ids;
    }

    /**
     * Align child type/sub_type with direct parent (matches manual create behaviour).
     */
    public static function syncTypesFromParents(?int $creatorId = null): int
    {
        $query = ChartOfAccount::query()->whereNotNull('parent_account_id');

        if ($creatorId) {
            $query->where('created_by', $creatorId);
        }

        $updated = 0;
        $accounts = $query->get()->sortBy(static fn (ChartOfAccount $account) => strlen((string) $account->code));

        foreach ($accounts as $account) {
            $parent = ChartOfAccount::find((int) $account->parent_account_id);
            if (! $parent) {
                continue;
            }

            if ((int) $account->type === (int) $parent->type && (int) $account->sub_type === (int) $parent->sub_type) {
                continue;
            }

            $account->type = $parent->type;
            $account->sub_type = $parent->sub_type;
            $account->save();
            $updated++;
        }

        self::clearCache();

        return $updated;
    }

    /**
     * Backfill parent_account_id for legacy level-2 rows (parent = ChartOfAccountParent.id).
     */
    public static function backfillLegacyParentLinks(?int $creatorId = null): int
    {
        $query = ChartOfAccount::query()
            ->whereNull('parent_account_id')
            ->where('parent', '>', 0);

        if ($creatorId) {
            $query->where('created_by', $creatorId);
        }

        $updated = 0;
        foreach ($query->get() as $account) {
            $link = self::parentRecordById((int) $account->parent);
            if ($link && $link->account) {
                $account->parent_account_id = (int) $link->account;
                $account->save();
                $updated++;
            }
        }

        self::clearCache();

        return $updated;
    }

    private static function parentRecordById(int $parentRowId): ?ChartOfAccountParent
    {
        if (! array_key_exists($parentRowId, self::$parentRecordCache)) {
            self::$parentRecordCache[$parentRowId] = ChartOfAccountParent::find($parentRowId);
        }

        return self::$parentRecordCache[$parentRowId];
    }
}
