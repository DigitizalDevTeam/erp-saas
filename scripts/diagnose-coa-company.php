<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountType;
use App\Models\User;
use App\Support\ChartOfAccountHierarchy;

$companyId = (int) ($argv[1] ?? 77);

$company = User::find($companyId);
echo 'Company #' . $companyId . ' ' . ($company?->name ?? '?') . ' type=' . ($company?->type ?? '?') . PHP_EOL;

$types = ChartOfAccountType::where('created_by', $companyId)->get();
$accCount = ChartOfAccount::where('created_by', $companyId)->count();
$has11 = ChartOfAccount::where('created_by', $companyId)->where('code', '11')->exists();

echo "Types: {$types->count()} | Accounts: {$accCount} | Code 11: " . ($has11 ? 'yes' : 'no') . PHP_EOL . PHP_EOL;

foreach ($types as $type) {
    $accounts = ChartOfAccountHierarchy::accountsForCreator($companyId, (int) $type->id);
    $tree = ChartOfAccountHierarchy::buildTree($accounts, 'name');
    echo "{$type->name}: db_accounts={$accounts->count()} tree_roots={$tree->count()}" . PHP_EOL;
}

// Orphan parent links
$orphanParents = ChartOfAccount::where('created_by', $companyId)
    ->whereNotNull('parent_account_id')
    ->whereNotIn('parent_account_id', ChartOfAccount::where('created_by', $companyId)->pluck('id'))
    ->count();
echo PHP_EOL . "Accounts with missing parent_account_id target: {$orphanParents}" . PHP_EOL;

// Sample accounts
$samples = ChartOfAccount::where('created_by', $companyId)->orderBy('code')->limit(5)->get(['code', 'name', 'type', 'parent_account_id']);
foreach ($samples as $s) {
    echo "  {$s->code} parent_id={$s->parent_account_id} type={$s->type}" . PHP_EOL;
}
