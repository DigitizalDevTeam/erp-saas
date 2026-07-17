<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ChartOfAccount;
use App\Models\ChartOfAccountType;

$companyId = (int) ($argv[1] ?? 77);

$dupCodes = ChartOfAccount::where('created_by', $companyId)
    ->selectRaw('code, COUNT(*) as c')
    ->groupBy('code')
    ->havingRaw('COUNT(*) > 1')
    ->orderBy('code')
    ->get();

echo "Company #{$companyId} duplicate codes: {$dupCodes->count()}" . PHP_EOL;

$extraRows = 0;
foreach ($dupCodes as $d) {
    $extraRows += ((int) $d->c - 1);
    if ($dupCodes->count() <= 30 || (int) $d->code <= 15) {
        echo "  code {$d->code} x{$d->c}" . PHP_EOL;
    }
}
echo "Extra duplicate rows to remove: {$extraRows}" . PHP_EOL;

$eleven = ChartOfAccount::where('created_by', $companyId)->where('code', '11')->get(['id', 'type', 'sub_type', 'name', 'parent_account_id']);
echo PHP_EOL . "Code 11 rows:" . PHP_EOL;
foreach ($eleven as $a) {
    echo "  id={$a->id} type={$a->type} sub_type={$a->sub_type} parent={$a->parent_account_id} name={$a->name}" . PHP_EOL;
}

$types = ChartOfAccountType::where('created_by', $companyId)->get(['id', 'name']);
echo PHP_EOL . "Types:" . PHP_EOL;
foreach ($types as $t) {
    $cnt = ChartOfAccount::where('created_by', $companyId)->where('type', $t->id)->count();
    echo "  {$t->id} {$t->name}: {$cnt}" . PHP_EOL;
}
