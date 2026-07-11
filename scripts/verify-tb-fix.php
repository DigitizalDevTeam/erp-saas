<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\TransactionLines;
use App\Models\ChartOfAccount;
use App\Support\ChartOfAccountHierarchy;

$cid = 6;
$start = '2025-01-01';
$end = '2026-07-10';

echo "=== By reference (period) ===\n";
$rows = TransactionLines::where('created_by', $cid)
    ->whereDate('date', '>=', $start)
    ->whereDate('date', '<=', $end)
    ->selectRaw('reference, SUM(debit) d, SUM(credit) c, COUNT(*) n')
    ->groupBy('reference')
    ->get();
$td = 0; $tc = 0;
foreach ($rows as $r) {
    echo "{$r->reference}: d={$r->d} c={$r->c} n={$r->n} diff=".($r->d-$r->c)."\n";
    $td += $r->d; $tc += $r->c;
}
echo "TOTAL d=$td c=$tc diff=".($td-$tc)."\n";

echo "\n=== 4210 parent ===\n";
$a = ChartOfAccount::where('created_by', $cid)->where('code', '4210')->first();
$p = $a && $a->parent_account_id ? ChartOfAccount::find($a->parent_account_id) : null;
echo '4210 -> '.($p->code ?? 'null')."\n";

echo "\n=== Siblings under 42 ===\n";
$a42 = ChartOfAccount::where('created_by', $cid)->where('code', '42')->first();
$kids = ChartOfAccount::where('created_by', $cid)->get()
    ->filter(fn ($x) => ChartOfAccountHierarchy::effectiveParentAccountId($x) === (int)$a42->id)
    ->sortBy(fn ($x) => (int)$x->code);
foreach ($kids as $k) {
    if (in_array((string)$k->code, ['421','422','423','425','429','4210'], true)) {
        echo "  {$k->code} {$k->name}\n";
    }
}

echo "\n=== Bank Account lines ===\n";
$bank = TransactionLines::where('created_by', $cid)->where('reference', 'Bank Account')->get();
foreach ($bank as $b) {
    $ca = ChartOfAccount::find($b->account_id);
    echo "{$b->date} {$ca->code} d={$b->debit} c={$b->credit} ref_id={$b->reference_id}\n";
}
