<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ChartOfAccount;
use App\Models\TransactionLines;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$start = '2025-01-01';
$end = '2026-07-10';

foreach ([2, 6, 7, 12, 20, 23, 26, 27] as $cid) {
    $u = User::find($cid);
    if (!$u) continue;
    $t = TransactionLines::where('created_by', $cid)
        ->whereDate('date', '>=', $start)
        ->whereDate('date', '<=', $end)
        ->selectRaw('SUM(debit) d, SUM(credit) c')
        ->first();
    $diff = round(($t->d ?? 0) - ($t->c ?? 0), 2);
    if (abs($t->d ?? 0) < 1 && abs($t->c ?? 0) < 1) continue;
    echo "{$cid} {$u->name}: d=".round($t->d,2)." c=".round($t->c,2)." diff=$diff\n";
}

$cid = 6;
echo "\n=== Bill TL accounts company 6 ===\n";
$rows = DB::table('transaction_lines as tl')
    ->join('chart_of_accounts as ca', 'ca.id', '=', 'tl.account_id')
    ->where('tl.created_by', $cid)
    ->where('tl.reference', 'Bill')
    ->groupBy('ca.code', 'ca.name')
    ->selectRaw('ca.code, ca.name, SUM(tl.debit) d, SUM(tl.credit) c, COUNT(*) n')
    ->orderByDesc('d')
    ->limit(20)
    ->get();
foreach ($rows as $r) {
    echo "{$r->code} d={$r->d} c={$r->c} n={$r->n} {$r->name}\n";
}

echo "\n=== All refs company 6 ===\n";
$byRef = TransactionLines::where('created_by', $cid)
    ->whereDate('date', '>=', $start)
    ->whereDate('date', '<=', $end)
    ->selectRaw('reference, SUM(debit) d, SUM(credit) c, COUNT(*) n')
    ->groupBy('reference')
    ->get();
foreach ($byRef as $r) {
    echo "{$r->reference}: d={$r->d} c={$r->c} n={$r->n} diff=".($r->d-$r->c)."\n";
}

echo "\n=== Bill Account / Bill Payment samples ===\n";
foreach (['Bill', 'Bill Account', 'Bill Payment', 'Bill Category'] as $ref) {
    $sample = TransactionLines::where('created_by', $cid)->where('reference', $ref)->limit(5)->get();
    echo "-- $ref count=".TransactionLines::where('created_by', $cid)->where('reference', $ref)->count()."\n";
    foreach ($sample as $s) {
        $ca = ChartOfAccount::find($s->account_id);
        echo "  id={$s->id} ref_id={$s->reference_id} code=".($ca->code??'?')." d={$s->debit} c={$s->credit}\n";
    }
}

// Find which company has 95384.57 style balances
echo "\n=== Search net ~95384 on any company ===\n";
$hits = DB::table('transaction_lines as tl')
    ->join('chart_of_accounts as ca', 'ca.id', '=', 'tl.account_id')
    ->whereDate('tl.date', '>=', $start)
    ->whereDate('tl.date', '<=', $end)
    ->groupBy('tl.created_by', 'ca.code')
    ->selectRaw('tl.created_by, ca.code, ROUND(SUM(tl.debit)-SUM(tl.credit),2) net')
    ->havingRaw('ABS(ROUND(SUM(tl.debit)-SUM(tl.credit),2) - 95384.57) < 1 OR ABS(ROUND(SUM(tl.debit)-SUM(tl.credit),2) - 85957.85) < 1')
    ->get();
foreach ($hits as $h) {
    echo "company={$h->created_by} code={$h->code} net={$h->net}\n";
}
