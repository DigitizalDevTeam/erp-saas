<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\ChartOfAccountHierarchy;

$companyId = (int) ($argv[1] ?? 77);
$tree = ChartOfAccountHierarchy::buildMasterTree($companyId, 'name');

$codes = $tree->pluck('code')->all();
echo 'Root accounts: ' . count($codes) . PHP_EOL;

foreach (['41', '42', '43', '44', '45'] as $code) {
    $idx = array_search($code, $codes, true);
    if ($idx !== false) {
        $root = $tree->firstWhere('code', $code);
        echo "  {$code} at index {$idx} type=" . ($root->types->name ?? '?') . PHP_EOL;
    }
}

$i41 = array_search('41', $codes, true);
$i42 = array_search('42', $codes, true);
$i43 = array_search('43', $codes, true);
if ($i41 !== false && $i42 !== false && $i43 !== false) {
    echo ($i41 < $i42 && $i42 < $i43 ? 'OK: 41 -> 42 -> 43 sequential' : 'FAIL: order broken') . PHP_EOL;
}
