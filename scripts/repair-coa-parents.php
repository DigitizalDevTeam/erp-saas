<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$r = App\Support\ChartOfAccountHierarchy::repairParentsByCodePrefix();
echo 'Repair: ' . json_encode($r) . PHP_EOL;

$accs = App\Models\ChartOfAccount::whereIn('code', [21, 29, 210, 44, 63])
    ->orderBy('created_by')
    ->orderByRaw('CAST(code AS UNSIGNED)')
    ->limit(40)
    ->get(['id', 'code', 'parent_account_id', 'created_by', 'name']);

foreach ($accs as $a) {
    $p = $a->parent_account_id ? App\Models\ChartOfAccount::find($a->parent_account_id) : null;
    echo $a->created_by . ' | ' . $a->code . ' | parent=' . ($p ? $p->code : 'null') . ' | ' . $a->name . PHP_EOL;
}
