<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountParent;
use App\Models\ChartOfAccountSubType;
use App\Models\ChartOfAccountType;
use App\Models\User;
use App\Support\ChartOfAccountHierarchy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ChartOfAccountCsvImportService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function parseCsvRows(): array
    {
        $filePath = public_path('chart_new_data.csv');
        if (! is_file($filePath)) {
            throw new \RuntimeException('CSV file not found: chart_new_data.csv');
        }

        $csv = array_map('str_getcsv', file($filePath));
        $rows = [];

        foreach ($csv as $rowIndex => $row) {
            if ($rowIndex < 3) {
                continue;
            }
            $code = trim($row[1] ?? '');
            if ($code === '' || ! preg_match('/^\d+$/', $code)) {
                continue;
            }
            $rows[] = [
                'row_index' => $rowIndex + 1,
                'code' => $code,
                'portuguese_name' => trim($row[2] ?? ''),
                'english_name' => trim($row[3] ?? ''),
                'category_pt' => trim($row[4] ?? ''),
                'english_type' => trim($row[5] ?? ''),
                'sub_category_pt' => trim($row[6] ?? ''),
                'english_sub_type' => trim($row[7] ?? ''),
                'account_level' => $this->normalizeLevel($row[9] ?? ''),
                'description_pt' => trim($row[11] ?? ''),
                'description_en' => trim($row[12] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @return array{status: string, imported?: int, error?: string}
     */
    public function importForCompany(int $companyId, bool $skipIfCode11Exists = false): array
    {
        $company = User::find($companyId);
        if (! $company || $company->type !== 'company') {
            return ['status' => 'error', 'error' => "Company #{$companyId} not found."];
        }

        if ($skipIfCode11Exists && ChartOfAccount::where('created_by', $companyId)->where('code', '11')->exists()) {
            return ['status' => 'skipped', 'error' => 'Chart already present (code 11 exists).'];
        }

        $rows = $this->parseCsvRows();
        $typeCache = ChartOfAccountType::where('created_by', $companyId)->get()->keyBy('name');
        $subTypeCache = [];
        foreach (ChartOfAccountSubType::where('created_by', $companyId)->get() as $subType) {
            $subTypeCache[$subType->type . '|' . $subType->name] = $subType;
        }

        $imported = 0;

        try {
            DB::transaction(function () use ($companyId, $rows, &$typeCache, &$subTypeCache, &$imported) {
                $accountsByCode = [];
                $depthStack = [];

                foreach (ChartOfAccount::where('created_by', $companyId)->orderBy('id')->get(['id', 'code']) as $existingRow) {
                    if (! isset($accountsByCode[$existingRow->code])) {
                        $accountsByCode[$existingRow->code] = ['id' => $existingRow->id];
                    }
                }

                foreach ($rows as $row) {
                    $imported += $this->upsertCsvRow(
                        $companyId,
                        $row,
                        $accountsByCode,
                        $depthStack,
                        $typeCache,
                        $subTypeCache
                    ) ? 1 : 0;
                }
            });

            $this->repairForCompany($companyId);

            return ['status' => 'imported', 'imported' => $imported];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    public function repairForCompany(int $companyId): void
    {
        $this->dedupeDuplicateAccountTypes($companyId);
        $this->dedupeDuplicateAccountsByCode($companyId);
        ChartOfAccountHierarchy::backfillLegacyParentLinks($companyId);
        ChartOfAccountHierarchy::syncMissingAccountsFromCsv($companyId);
        ChartOfAccountHierarchy::repairParentsByCodePrefix($companyId);
        ChartOfAccountHierarchy::applyCsvCategories($companyId);
        ChartOfAccountHierarchy::syncTypesFromParents($companyId);
    }

    /**
     * Remove duplicate chart_of_accounts rows (same code) after type merges or re-imports.
     */
    public function dedupeDuplicateAccountsByCode(int $companyId): int
    {
        $duplicateCodes = ChartOfAccount::where('created_by', $companyId)
            ->selectRaw('code')
            ->groupBy('code')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('code');

        if ($duplicateCodes->isEmpty()) {
            return 0;
        }

        $removed = 0;

        DB::transaction(function () use ($companyId, $duplicateCodes, &$removed) {
            foreach ($duplicateCodes as $code) {
                $accounts = ChartOfAccount::where('created_by', $companyId)
                    ->where('code', $code)
                    ->orderBy('id')
                    ->get();

                if ($accounts->count() < 2) {
                    continue;
                }

                $canonical = $this->pickCanonicalAccount($accounts);
                $duplicateIds = $accounts->pluck('id')
                    ->map(static fn ($id) => (int) $id)
                    ->filter(static fn (int $id) => $id !== (int) $canonical->id)
                    ->values()
                    ->all();

                foreach ($duplicateIds as $duplicateId) {
                    $this->remapAccountReferences($duplicateId, (int) $canonical->id, $companyId);
                    ChartOfAccountParent::where('created_by', $companyId)->where('account', $duplicateId)->delete();
                    ChartOfAccount::where('id', $duplicateId)->where('created_by', $companyId)->delete();
                    $removed++;
                }
            }
        });

        return $removed;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ChartOfAccount>  $accounts
     */
    private function pickCanonicalAccount($accounts): ChartOfAccount
    {
        if (! Schema::hasTable('transaction_lines')) {
            return $accounts->first();
        }

        $best = $accounts->first();
        $bestScore = -1;

        foreach ($accounts as $account) {
            $score = (int) DB::table('transaction_lines')
                ->where('account_id', $account->id)
                ->count();

            if (Schema::hasTable('journal_items')) {
                $score += (int) DB::table('journal_items')
                    ->where('account', $account->id)
                    ->count();
            }

            if ($score > $bestScore || ($score === $bestScore && (int) $account->id < (int) $best->id)) {
                $best = $account;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function remapAccountReferences(int $fromId, int $toId, int $companyId): void
    {
        if ($fromId === $toId) {
            return;
        }

        ChartOfAccount::where('created_by', $companyId)
            ->where('parent_account_id', $fromId)
            ->update(['parent_account_id' => $toId]);

        if (Schema::hasTable('chart_of_account_parents')) {
            ChartOfAccountParent::where('created_by', $companyId)
                ->where('account', $fromId)
                ->update(['account' => $toId]);
        }

        $columnUpdates = [
            'transaction_lines' => ['column' => 'account_id', 'scope' => false],
            'journal_items' => ['column' => 'account', 'scope' => false],
            'bank_accounts' => ['column' => 'chart_account_id', 'scope' => true],
            'payments' => ['column' => 'chart_account_id', 'scope' => true],
            'bill_accounts' => ['column' => 'chart_account_id', 'scope' => true],
            'bill_products' => ['column' => 'chart_account_id', 'scope' => true],
            'expenses' => ['column' => 'chart_account_id', 'scope' => true],
            'expense_categories' => ['column' => 'chart_account_id', 'scope' => true],
            'product_service_categories' => ['column' => 'chart_account_id', 'scope' => true],
            'employees' => ['column' => 'salary_chart_account_id', 'scope' => true],
            'allowances' => ['column' => 'chart_account_id', 'scope' => true],
            'commissions' => ['column' => 'chart_account_id', 'scope' => true],
            'loans' => ['column' => 'chart_account_id', 'scope' => true],
            'saturation_deductions' => ['column' => 'chart_account_id', 'scope' => true],
            'other_payments' => ['column' => 'chart_account_id', 'scope' => true],
            'overtimes' => ['column' => 'chart_account_id', 'scope' => true],
        ];

        foreach ($columnUpdates as $table => $meta) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $meta['column'])) {
                continue;
            }

            $query = DB::table($table)->where($meta['column'], $fromId);
            if ($meta['scope'] && Schema::hasColumn($table, 'created_by')) {
                $query->where('created_by', $companyId);
            }
            $query->update([$meta['column'] => $toId]);
        }

        if (Schema::hasTable('product_services')) {
            if (Schema::hasColumn('product_services', 'sale_chartaccount_id')) {
                DB::table('product_services')
                    ->where('sale_chartaccount_id', $fromId)
                    ->where('created_by', $companyId)
                    ->update(['sale_chartaccount_id' => $toId]);
            }
            if (Schema::hasColumn('product_services', 'expense_chartaccount_id')) {
                DB::table('product_services')
                    ->where('expense_chartaccount_id', $fromId)
                    ->where('created_by', $companyId)
                    ->update(['expense_chartaccount_id' => $toId]);
            }
        }
    }

    /**
     * Merge duplicate chart_of_account_types rows (same English name) created by re-import.
     */
    public function dedupeDuplicateAccountTypes(int $companyId): int
    {
        $merged = 0;
        $types = ChartOfAccountType::where('created_by', $companyId)->orderBy('id')->get();
        $canonicalByName = [];

        foreach ($types as $type) {
            $name = (string) $type->name;
            if ($name === '') {
                continue;
            }

            if (! isset($canonicalByName[$name])) {
                $canonicalByName[$name] = (int) $type->id;

                continue;
            }

            $canonicalId = $canonicalByName[$name];
            $duplicateId = (int) $type->id;

            foreach (ChartOfAccountSubType::where('created_by', $companyId)->where('type', $duplicateId)->get() as $dupSubType) {
                $canonicalSubType = ChartOfAccountSubType::where('created_by', $companyId)
                    ->where('type', $canonicalId)
                    ->where('name', $dupSubType->name)
                    ->first();

                if ($canonicalSubType) {
                    ChartOfAccount::where('created_by', $companyId)
                        ->where('sub_type', $dupSubType->id)
                        ->update([
                            'sub_type' => $canonicalSubType->id,
                            'type' => $canonicalId,
                        ]);
                    $dupSubType->delete();
                } else {
                    $dupSubType->type = $canonicalId;
                    $dupSubType->save();
                }
            }

            ChartOfAccount::where('created_by', $companyId)
                ->where('type', $duplicateId)
                ->update(['type' => $canonicalId]);

            if (Schema::hasTable('chart_of_account_parents')) {
                ChartOfAccountParent::where('created_by', $companyId)
                    ->where('type', $duplicateId)
                    ->update(['type' => $canonicalId]);
            }

            ChartOfAccountType::where('id', $duplicateId)->delete();
            $merged++;
        }

        return $merged;
    }

    public function companyHasChart(int $companyId): bool
    {
        return ChartOfAccount::where('created_by', $companyId)->where('code', '11')->exists();
    }

    /**
     * @return array<int, int>
     */
    public function companyIds(?int $onlyCompanyId = null): array
    {
        $query = User::where('type', 'company')->orderBy('id');
        if ($onlyCompanyId) {
            $query->where('id', $onlyCompanyId);
        }

        return $query->pluck('id')->map(static fn ($id) => (int) $id)->all();
    }

    /**
     * @return array{companies: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function syncAll(?int $onlyCompanyId = null, bool $onlyMissing = false): array
    {
        $results = [];
        $totals = ['imported' => 0, 'repaired' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($this->companyIds($onlyCompanyId) as $companyId) {
            $company = User::find($companyId);
            $name = $company?->name ?? ('#' . $companyId);

            if (! $this->companyHasChart($companyId)) {
                $result = $this->importForCompany($companyId, false);
                if ($result['status'] === 'imported') {
                    $totals['imported']++;
                    $results[] = [
                        'company_id' => $companyId,
                        'company_name' => $name,
                        'status' => 'imported',
                        'imported' => $result['imported'] ?? 0,
                    ];
                } else {
                    $totals['errors']++;
                    $results[] = [
                        'company_id' => $companyId,
                        'company_name' => $name,
                        'status' => 'error',
                        'error' => $result['error'] ?? 'Import failed.',
                    ];
                }

                continue;
            }

            if ($onlyMissing) {
                $totals['skipped']++;
                $results[] = [
                    'company_id' => $companyId,
                    'company_name' => $name,
                    'status' => 'skipped',
                ];
                continue;
            }

            try {
                $this->repairForCompany($companyId);
                $totals['repaired']++;
                $results[] = [
                    'company_id' => $companyId,
                    'company_name' => $name,
                    'status' => 'repaired',
                ];
            } catch (\Throwable $e) {
                $totals['errors']++;
                $results[] = [
                    'company_id' => $companyId,
                    'company_name' => $name,
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return ['companies' => $results, 'totals' => $totals];
    }

    /**
     * @param  array<string, array{id: int, depth?: int, can_have_children?: bool}>  $accountsByCode
     * @param  array<int, string>  $depthStack
     * @param  \Illuminate\Support\Collection<string, ChartOfAccountType>  $typeCache
     * @param  array<string, ChartOfAccountSubType>  $subTypeCache
     */
    private function upsertCsvRow(
        int $companyId,
        array $row,
        array &$accountsByCode,
        array &$depthStack,
        &$typeCache,
        array &$subTypeCache
    ): bool {
        $code = $row['code'];
        $englishName = $row['english_name'];
        $englishTypeName = $row['english_type'];
        $englishSubTypeName = $row['english_sub_type'];
        $accountLevel = $row['account_level'];
        $depth = $this->parseDepth($accountLevel);
        $canHaveChildren = str_contains($accountLevel, '- parent');

        if ($englishName === '' || $englishTypeName === '' || $englishSubTypeName === '') {
            throw new \RuntimeException("Row {$row['row_index']} (code {$code}): missing name, category, or sub-category.");
        }

        $typeModel = $this->resolveType($englishTypeName, $row['category_pt'], $companyId, $typeCache);
        $subType = $this->resolveSubType($englishSubTypeName, $row['sub_category_pt'], (int) $typeModel->id, $companyId, $subTypeCache);

        $accountPayload = [
            'code' => $code,
            'name' => $englishName,
            'name_pt_br' => $row['portuguese_name'] !== '' ? $row['portuguese_name'] : $englishName,
            'type' => $typeModel->id,
            'sub_type' => $subType->id,
            'description' => $row['description_en'],
            'description_pt_br' => $row['description_pt'] !== '' ? $row['description_pt'] : $row['description_en'],
            'is_enabled' => 1,
            'created_by' => $companyId,
        ];

        $parentCode = $this->resolveParentCode($code, $depth, $accountsByCode, $depthStack);

        if ($depth === 0) {
            $existing = ChartOfAccount::where('created_by', $companyId)->where('code', $code)->orderBy('id')->first();
            if ($existing) {
                $existing->fill(array_merge($accountPayload, ['parent' => 0, 'parent_account_id' => null]));
                $existing->save();
                $account = $existing;
            } else {
                $account = ChartOfAccount::create(array_merge($accountPayload, ['parent' => 0, 'parent_account_id' => null]));
            }

            ChartOfAccountParent::updateOrCreate(
                ['account' => $account->id, 'created_by' => $companyId],
                [
                    'name' => $account->name,
                    'name_pt_br' => $account->name_pt_br,
                    'type' => $typeModel->id,
                    'sub_type' => $subType->id,
                ]
            );

            $accountsByCode[$code] = ['id' => $account->id, 'depth' => 0, 'can_have_children' => $canHaveChildren];
            $depthStack[0] = $code;
            foreach (array_keys($depthStack) as $stackDepth) {
                if ($stackDepth > 0) {
                    unset($depthStack[$stackDepth]);
                }
            }

            return true;
        }

        if ($parentCode === null || ! isset($accountsByCode[$parentCode]['id'])) {
            throw new \RuntimeException("Row {$row['row_index']} (code {$code}, {$englishName}): could not resolve parent for level '{$accountLevel}'.");
        }

        $parentAccountId = (int) $accountsByCode[$parentCode]['id'];
        $legacyParentId = ChartOfAccountHierarchy::legacyParentRowId($parentAccountId);

        $existing = ChartOfAccount::where('created_by', $companyId)->where('code', $code)->orderBy('id')->first();
        if ($existing) {
            $existing->fill(array_merge($accountPayload, [
                'parent' => $legacyParentId,
                'parent_account_id' => $parentAccountId,
            ]));
            $existing->save();
            $account = $existing;
        } else {
            $account = ChartOfAccount::create(array_merge($accountPayload, [
                'parent' => $legacyParentId,
                'parent_account_id' => $parentAccountId,
            ]));
        }

        $accountsByCode[$code] = ['id' => $account->id, 'depth' => $depth, 'can_have_children' => $canHaveChildren];
        $depthStack[$depth] = $code;
        foreach (array_keys($depthStack) as $stackDepth) {
            if ($stackDepth > $depth) {
                unset($depthStack[$stackDepth]);
            }
        }

        return true;
    }

    private function normalizeLevel(?string $raw): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $raw ?? '')));
    }

    private function parseDepth(string $level): int
    {
        if ($level === 'parent account' || $level === 'main account') {
            return 0;
        }
        if (! preg_match('/^(?:sub\s+)+account/', $level)) {
            throw new \InvalidArgumentException("Unknown account structure level: {$level}");
        }

        return substr_count($level, 'sub');
    }

    /**
     * @param  array<string, array{id: int, depth?: int, can_have_children?: bool}>  $accountsByCode
     * @param  array<int, string>  $depthStack
     */
    private function resolveParentCode(string $code, int $depth, array $accountsByCode, array $depthStack): ?string
    {
        if ($depth === 0) {
            return null;
        }

        $overrides = ChartOfAccountHierarchy::csvParentOverrides();
        if (array_key_exists($code, $overrides)) {
            return $overrides[$code];
        }

        $findParentCode = static function (string $code, array $accountsByCode): ?string {
            for ($length = strlen($code) - 1; $length > 0; $length--) {
                $prefix = substr($code, 0, $length);
                if (isset($accountsByCode[$prefix])) {
                    return $prefix;
                }
            }

            return null;
        };

        $prefixParent = $findParentCode($code, $accountsByCode);

        if ($depth >= 2 && isset($depthStack[$depth - 1]) && str_starts_with($code, $depthStack[$depth - 1]) && strlen($code) > strlen($depthStack[$depth - 1])) {
            return $depthStack[$depth - 1];
        }

        if ($depth === 1 && isset($depthStack[0]) && str_starts_with($code, $depthStack[0]) && strlen($code) > strlen($depthStack[0])) {
            if ($prefixParent !== null && isset($accountsByCode[$prefixParent]) && strlen($prefixParent) > strlen($depthStack[0])) {
                $parentMeta = $accountsByCode[$prefixParent];
                if (($parentMeta['can_have_children'] ?? false) || ($parentMeta['depth'] ?? 0) >= 2) {
                    return $prefixParent;
                }
            }

            return $depthStack[0];
        }

        if ($prefixParent !== null && isset($accountsByCode[$prefixParent])) {
            $parentMeta = $accountsByCode[$prefixParent];
            if ($depth === 1 && ($parentMeta['depth'] ?? 0) >= 1 && ! ($parentMeta['can_have_children'] ?? false)) {
                $grandparentCode = $findParentCode($prefixParent, $accountsByCode);
                if ($grandparentCode !== null) {
                    return $grandparentCode;
                }
            }
        }

        return $prefixParent;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, ChartOfAccountType>  $typeCache
     */
    private function resolveType(string $englishType, string $categoryPt, int $creatorId, &$typeCache): ChartOfAccountType
    {
        if ($typeCache->has($englishType)) {
            return $typeCache->get($englishType);
        }

        $model = ChartOfAccountType::create([
            'name' => $englishType,
            'name_pt_br' => $categoryPt !== '' ? $categoryPt : $englishType,
            'created_by' => $creatorId,
        ]);
        $typeCache->put($englishType, $model);

        return $model;
    }

    /**
     * @param  array<string, ChartOfAccountSubType>  $subTypeCache
     */
    private function resolveSubType(
        string $englishSubType,
        string $subCategoryPt,
        int $typeId,
        int $creatorId,
        array &$subTypeCache
    ): ChartOfAccountSubType {
        $key = $typeId . '|' . $englishSubType;
        if (isset($subTypeCache[$key])) {
            return $subTypeCache[$key];
        }

        $model = ChartOfAccountSubType::create([
            'name' => $englishSubType,
            'name_pt_br' => $subCategoryPt !== '' ? $subCategoryPt : $englishSubType,
            'type' => $typeId,
            'created_by' => $creatorId,
        ]);
        $subTypeCache[$key] = $model;

        return $model;
    }
}
