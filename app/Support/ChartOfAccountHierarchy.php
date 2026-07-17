<?php

namespace App\Support;

use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountParent;
use App\Models\ChartOfAccountSubType;
use App\Models\ChartOfAccountType;
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
            $orderCmp = self::displayOrderKey((string) ($a->code ?? ''))
                <=> self::displayOrderKey((string) ($b->code ?? ''));
            if ($orderCmp !== 0) {
                return $orderCmp;
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
            $orderCmp = self::displayOrderKey((string) ($a->code ?? ''))
                <=> self::displayOrderKey((string) ($b->code ?? ''));
            if ($orderCmp !== 0) {
                return $orderCmp;
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
        $updated = self::applyCsvCategories($creatorId);

        $query = ChartOfAccount::query()->whereNotNull('parent_account_id');

        if ($creatorId) {
            $query->where('created_by', $creatorId);
        }

        $csvMap = self::csvCategoryMap();
        $accounts = $query->get()->sortBy(static fn (ChartOfAccount $account) => strlen((string) $account->code));

        foreach ($accounts as $account) {
            $code = preg_replace('/\D/', '', (string) $account->code) ?? '';
            if ($code !== '' && isset($csvMap[$code])) {
                continue;
            }

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
     * Apply category/sub-category from chart_new_data.csv to existing accounts.
     */
    public static function applyCsvCategories(?int $creatorId = null): int
    {
        $csvMap = self::csvCategoryMap();
        if ($csvMap === []) {
            return 0;
        }

        $query = ChartOfAccount::query();
        if ($creatorId) {
            $query->where('created_by', $creatorId);
        }

        $updated = 0;
        $typeCache = [];
        $subTypeCache = [];

        foreach ($query->get() as $account) {
            $code = preg_replace('/\D/', '', (string) $account->code) ?? '';
            if ($code === '' || ! isset($csvMap[$code])) {
                continue;
            }

            $meta = $csvMap[$code];
            $companyId = (int) $account->created_by;
            $typeId = self::resolveOrCreateTypeId(
                $companyId,
                $meta['type'],
                $meta['category_pt'],
                $typeCache
            );
            $subTypeId = self::resolveOrCreateSubTypeId(
                $companyId,
                $typeId,
                $meta['sub_type'],
                $meta['sub_category_pt'],
                $subTypeCache
            );

            if ((int) $account->type === $typeId && (int) $account->sub_type === $subTypeId) {
                continue;
            }

            $account->type = $typeId;
            $account->sub_type = $subTypeId;
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

    /**
     * PGC codes whose parent cannot be inferred reliably from numeric prefix alone.
     *
     * @return array<string, string|null>
     */
    public static function csvParentOverrides(): array
    {
        return [
            '44324' => '4432',
            '44341' => '4432',
            '4461' => '443',
        ];
    }

    /**
     * @return array<string, array{
     *     english_name: string,
     *     portuguese_name: string,
     *     english_type: string,
     *     english_sub_type: string,
     *     category_pt: string,
     *     sub_category_pt: string,
     *     description_en: string,
     *     description_pt: string,
     *     account_level: string
     * }>
     */
    public static function csvAccountRows(): array
    {
        static $rows = null;
        if ($rows !== null) {
            return $rows;
        }

        $rows = [];
        $file = public_path('chart_new_data.csv');
        if (! is_file($file)) {
            return $rows;
        }

        $csv = array_map('str_getcsv', file($file));
        foreach ($csv as $rowIndex => $row) {
            if ($rowIndex < 3) {
                continue;
            }

            $code = trim($row[1] ?? '');
            if ($code === '' || ! preg_match('/^\d+$/', $code)) {
                continue;
            }

            $englishName = trim($row[3] ?? '');
            $englishType = trim($row[5] ?? '');
            $englishSubType = trim($row[7] ?? '');
            if ($englishName === '' || $englishType === '' || $englishSubType === '') {
                continue;
            }

            $rows[$code] = [
                'english_name' => $englishName,
                'portuguese_name' => trim($row[2] ?? ''),
                'english_type' => $englishType,
                'english_sub_type' => $englishSubType,
                'category_pt' => trim($row[4] ?? ''),
                'sub_category_pt' => trim($row[6] ?? ''),
                'description_en' => trim($row[12] ?? ''),
                'description_pt' => trim($row[11] ?? ''),
                'account_level' => strtolower(trim(preg_replace('/\s+/', ' ', $row[9] ?? ''))),
            ];
        }

        return $rows;
    }

    /**
     * Create chart rows that exist in chart_new_data.csv but not yet in a company chart.
     */
    public static function syncMissingAccountsFromCsv(?int $creatorId = null): int
    {
        $rows = self::csvAccountRows();
        if ($rows === []) {
            return 0;
        }

        $parentMap = self::csvParentMap();
        $creatorIds = $creatorId
            ? [(int) $creatorId]
            : ChartOfAccount::query()->distinct()->pluck('created_by')->map(static fn ($id) => (int) $id)->filter(static fn (int $id) => $id > 0)->all();

        $created = 0;
        $typeCache = [];
        $subTypeCache = [];

        foreach ($creatorIds as $companyId) {
            $existingCodes = [];
            foreach (ChartOfAccount::where('created_by', $companyId)->get(['code']) as $account) {
                $normalized = preg_replace('/\D/', '', (string) $account->code) ?? '';
                if ($normalized !== '') {
                    $existingCodes[$normalized] = true;
                }
            }

            foreach ($rows as $code => $row) {
                if (isset($existingCodes[$code])) {
                    continue;
                }

                $typeId = self::resolveOrCreateTypeId(
                    $companyId,
                    $row['english_type'],
                    $row['category_pt'],
                    $typeCache
                );
                $subTypeId = self::resolveOrCreateSubTypeId(
                    $companyId,
                    $typeId,
                    $row['english_sub_type'],
                    $row['sub_category_pt'],
                    $subTypeCache
                );

                $parentCode = $parentMap[$code] ?? null;
                $parentAccountId = null;
                $legacyParentId = 0;

                if ($parentCode !== null) {
                    $parentAccount = ChartOfAccount::where('created_by', $companyId)
                        ->where('code', $parentCode)
                        ->first();
                    if (! $parentAccount) {
                        continue;
                    }
                    $parentAccountId = (int) $parentAccount->id;
                    $legacyParentId = self::legacyParentRowId($parentAccountId);
                }

                ChartOfAccount::create([
                    'code' => $code,
                    'name' => $row['english_name'],
                    'name_pt_br' => $row['portuguese_name'] !== '' ? $row['portuguese_name'] : $row['english_name'],
                    'type' => $typeId,
                    'sub_type' => $subTypeId,
                    'parent' => $legacyParentId,
                    'parent_account_id' => $parentAccountId,
                    'description' => $row['description_en'],
                    'description_pt_br' => $row['description_pt'] !== '' ? $row['description_pt'] : $row['description_en'],
                    'is_enabled' => 1,
                    'created_by' => $companyId,
                ]);

                $existingCodes[$code] = true;
                $created++;
            }
        }

        self::clearCache();

        return $created;
    }

    /**
     * Re-link parents from chart_new_data.csv structure (PGC levels).
     * Fixes cases like 4210 wrongly nested under 421 (should be under 42, after 429).
     * Custom codes not in the CSV use longest-prefix with sibling-guard (+1 digit on non-parent nodes).
     *
     * @return array{updated: int, cleared: int, csv_links: int}
     */
    public static function repairParentsByCodePrefix(?int $creatorId = null): array
    {
        $csvParents = self::csvParentMap();
        $csvLevels = self::csvLevelMap();

        $query = ChartOfAccount::query();
        if ($creatorId) {
            $query->where('created_by', $creatorId);
        }

        $accounts = $query->get();
        $byCreator = $accounts->groupBy(static fn (ChartOfAccount $a) => (int) $a->created_by);

        $updated = 0;
        $cleared = 0;
        $csvLinks = 0;

        foreach ($byCreator as $creatorAccounts) {
            /** @var Collection<int, ChartOfAccount> $creatorAccounts */
            $byCode = [];
            foreach ($creatorAccounts as $account) {
                $code = preg_replace('/\D/', '', (string) $account->code);
                if ($code === '') {
                    continue;
                }
                $byCode[$code] = $account;
            }

            $codes = array_keys($byCode);
            usort($codes, static function (string $a, string $b) {
                return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
            });

            foreach ($codes as $code) {
                $account = $byCode[$code];
                $parentCode = null;

                if (array_key_exists($code, $csvParents)) {
                    $parentCode = $csvParents[$code]; // may be null for top-level
                    $csvLinks++;
                } else {
                    for ($len = strlen($code) - 1; $len > 0; $len--) {
                        $prefix = substr($code, 0, $len);
                        if (! isset($byCode[$prefix])) {
                            continue;
                        }
                        $extra = substr($code, $len);
                        $level = $csvLevels[$prefix] ?? '';
                        // Guard: 4210 must not nest under 421 (same CSV "Sub Account" level, +1 digit).
                        if (strlen($extra) === 1
                            && $level !== ''
                            && ! str_contains($level, 'parent')
                            && $level !== 'parent account'
                            && $level !== 'main account') {
                            continue;
                        }
                        $parentCode = $prefix;
                        break;
                    }
                }

                if ($parentCode !== null && ! isset($byCode[$parentCode])) {
                    // CSV parent missing in this company chart — fall back.
                    $parentCode = null;
                    for ($len = strlen($code) - 1; $len > 0; $len--) {
                        $prefix = substr($code, 0, $len);
                        if (isset($byCode[$prefix])) {
                            $parentCode = $prefix;
                            break;
                        }
                    }
                }

                $newParentId = $parentCode !== null ? (int) $byCode[$parentCode]->id : null;
                $currentParentId = self::effectiveParentAccountId($account);

                if ($newParentId === $currentParentId
                    || ($newParentId === null && empty($account->parent_account_id) && (int) $account->parent === 0)) {
                    continue;
                }

                if ($newParentId !== null && self::isDescendantOf($byCode[$parentCode], (int) $account->id)) {
                    continue;
                }

                $account->parent_account_id = $newParentId;
                $account->parent = $newParentId ? self::legacyParentRowId($newParentId) : 0;

                if ($newParentId !== null) {
                    $updated++;
                } else {
                    $cleared++;
                }

                $account->save();
            }
        }

        self::clearCache();
        self::applyCsvCategories($creatorId);

        return ['updated' => $updated, 'cleared' => $cleared, 'csv_links' => $csvLinks];
    }

    /**
     * Stable chart display order: CSV sequence first (so 210 sits after 29, before 31),
     * then numeric code for custom accounts not present in the master CSV.
     */
    public static function displayOrderKey(string $code): float
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if ($code === '') {
            return PHP_FLOAT_MAX;
        }

        $csvOrder = self::csvCodeOrderMap();
        if (isset($csvOrder[$code])) {
            return (float) $csvOrder[$code];
        }

        // Keep custom codes after master-chart rows, still roughly numeric.
        return 100000.0 + (float) (int) $code;
    }

    /**
     * @return array<string, array{type: string, sub_type: string, category_pt: string, sub_category_pt: string}>
     */
    public static function csvCategoryMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $map = [];
        $file = public_path('chart_new_data.csv');
        if (! is_file($file)) {
            return $map;
        }

        $csv = array_map('str_getcsv', file($file));
        foreach ($csv as $rowIndex => $row) {
            if ($rowIndex < 3) {
                continue;
            }

            $code = trim($row[1] ?? '');
            if ($code === '' || ! preg_match('/^\d+$/', $code)) {
                continue;
            }

            $englishType = trim($row[5] ?? '');
            $englishSubType = trim($row[7] ?? '');
            if ($englishType === '' || $englishSubType === '') {
                continue;
            }

            $map[$code] = [
                'type' => $englishType,
                'sub_type' => $englishSubType,
                'category_pt' => trim($row[4] ?? ''),
                'sub_category_pt' => trim($row[6] ?? ''),
            ];
        }

        return $map;
    }

    /**
     * @param  array<string, ChartOfAccountType>  $typeCache
     */
    private static function resolveOrCreateTypeId(int $creatorId, string $englishType, string $categoryPt, array &$typeCache): int
    {
        if (isset($typeCache[$creatorId . '|' . $englishType])) {
            return (int) $typeCache[$creatorId . '|' . $englishType]->id;
        }

        $type = ChartOfAccountType::firstOrCreate(
            ['name' => $englishType, 'created_by' => $creatorId],
            ['name_pt_br' => $categoryPt !== '' ? $categoryPt : $englishType]
        );
        $typeCache[$creatorId . '|' . $englishType] = $type;

        return (int) $type->id;
    }

    /**
     * @param  array<string, ChartOfAccountSubType>  $subTypeCache
     */
    private static function resolveOrCreateSubTypeId(
        int $creatorId,
        int $typeId,
        string $englishSubType,
        string $subCategoryPt,
        array &$subTypeCache
    ): int {
        $cacheKey = $creatorId . '|' . $typeId . '|' . $englishSubType;
        if (isset($subTypeCache[$cacheKey])) {
            return (int) $subTypeCache[$cacheKey]->id;
        }

        $subType = ChartOfAccountSubType::firstOrCreate(
            ['name' => $englishSubType, 'type' => $typeId, 'created_by' => $creatorId],
            ['name_pt_br' => $subCategoryPt !== '' ? $subCategoryPt : $englishSubType]
        );
        $subTypeCache[$cacheKey] = $subType;

        return (int) $subType->id;
    }

    /**
     * @return array<string, int> code => 0-based index in chart_new_data.csv
     */
    private static function csvCodeOrderMap(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        $map = [];
        $file = public_path('chart_new_data.csv');
        if (! is_file($file)) {
            return $map;
        }

        $csv = array_map('str_getcsv', file($file));
        $index = 0;
        foreach ($csv as $rowIndex => $row) {
            if ($rowIndex < 3) {
                continue;
            }
            $code = trim($row[1] ?? '');
            if ($code === '' || ! preg_match('/^\d+$/', $code) || isset($map[$code])) {
                continue;
            }
            $map[$code] = $index++;
        }

        return $map;
    }

    /**
     * @return array<string, string|null> code => parent code (null = root)
     */
    private static function csvParentMap(): array
    {
        $file = public_path('chart_new_data.csv');
        if (! is_file($file)) {
            return [];
        }

        $csv = array_map('str_getcsv', file($file));
        $normalizeLevel = static function (?string $raw): string {
            return strtolower(trim(preg_replace('/\s+/', ' ', $raw ?? '')));
        };
        $parseDepth = static function (string $level): int {
            if ($level === 'parent account' || $level === 'main account') {
                return 0;
            }
            if (! preg_match('/^(?:sub\s+)+account/', $level)) {
                return -1;
            }

            return substr_count($level, 'sub');
        };
        $findParentCode = static function (string $code, array $accountsByCode): ?string {
            for ($length = strlen($code) - 1; $length > 0; $length--) {
                $prefix = substr($code, 0, $length);
                if (isset($accountsByCode[$prefix])) {
                    return $prefix;
                }
            }

            return null;
        };

        $parents = [];
        $accountsByCode = [];
        $depthStack = [];

        foreach ($csv as $rowIndex => $row) {
            if ($rowIndex < 3) {
                continue;
            }
            $code = trim($row[1] ?? '');
            if ($code === '' || ! preg_match('/^\d+$/', $code)) {
                continue;
            }
            $level = $normalizeLevel($row[9] ?? '');
            $depth = $parseDepth($level);
            if ($depth < 0) {
                continue;
            }
            $canHaveChildren = str_contains($level, '- parent');

            if ($depth === 0) {
                $parents[$code] = null;
                $accountsByCode[$code] = ['depth' => 0, 'can_have_children' => $canHaveChildren];
                $depthStack = [0 => $code];
                continue;
            }

            $prefixParent = $findParentCode($code, $accountsByCode);
            $parentCode = null;

            if ($depth >= 2 && isset($depthStack[$depth - 1]) && str_starts_with($code, $depthStack[$depth - 1]) && strlen($code) > strlen($depthStack[$depth - 1])) {
                $parentCode = $depthStack[$depth - 1];
            } elseif ($depth === 1 && isset($depthStack[0]) && str_starts_with($code, $depthStack[0]) && strlen($code) > strlen($depthStack[0])) {
                if ($prefixParent !== null && isset($accountsByCode[$prefixParent]) && strlen($prefixParent) > strlen($depthStack[0])) {
                    $parentMeta = $accountsByCode[$prefixParent];
                    if (($parentMeta['can_have_children'] ?? false) || ($parentMeta['depth'] ?? 0) >= 2) {
                        $parentCode = $prefixParent;
                    } else {
                        $parentCode = $depthStack[0];
                    }
                } else {
                    $parentCode = $depthStack[0];
                }
            } else {
                $parentCode = $prefixParent;
            }

            // Same-level Sub Accounts under a Parent (421, 429, 4210 → all under 42):
            // prefer the depth-0 stack parent when prefix would nest under a sibling.
            if ($depth === 1 && isset($depthStack[0])) {
                $root = $depthStack[0];
                if (str_starts_with($code, $root) && ($parentCode === null || ! str_starts_with($parentCode, $root) || strlen($parentCode) > strlen($root))) {
                    // If longest prefix is another depth-1 sibling (e.g. 421 for 4210), use root.
                    if ($parentCode !== null && isset($accountsByCode[$parentCode]) && ($accountsByCode[$parentCode]['depth'] ?? 0) === 1 && ! ($accountsByCode[$parentCode]['can_have_children'] ?? false)) {
                        $parentCode = $root;
                    }
                }
                if ($parentCode === null) {
                    $parentCode = $root;
                }
            }

            $parents[$code] = $parentCode;
            $accountsByCode[$code] = ['depth' => $depth, 'can_have_children' => $canHaveChildren];
            $depthStack[$depth] = $code;
            foreach (array_keys($depthStack) as $stackDepth) {
                if ($stackDepth > $depth) {
                    unset($depthStack[$stackDepth]);
                }
            }
        }

        foreach (self::csvParentOverrides() as $code => $parentCode) {
            if (array_key_exists($code, $parents)) {
                $parents[$code] = $parentCode;
            }
        }

        return $parents;
    }

    /**
     * @return array<string, string> code => normalized level string
     */
    private static function csvLevelMap(): array
    {
        $file = public_path('chart_new_data.csv');
        if (! is_file($file)) {
            return [];
        }
        $csv = array_map('str_getcsv', file($file));
        $map = [];
        foreach ($csv as $rowIndex => $row) {
            if ($rowIndex < 3) {
                continue;
            }
            $code = trim($row[1] ?? '');
            if ($code === '' || ! preg_match('/^\d+$/', $code)) {
                continue;
            }
            $map[$code] = strtolower(trim(preg_replace('/\s+/', ' ', $row[9] ?? '')));
        }

        return $map;
    }

    private static function parentRecordById(int $parentRowId): ?ChartOfAccountParent
    {
        if (! array_key_exists($parentRowId, self::$parentRecordCache)) {
            self::$parentRecordCache[$parentRowId] = ChartOfAccountParent::find($parentRowId);
        }

        return self::$parentRecordCache[$parentRowId];
    }
}
