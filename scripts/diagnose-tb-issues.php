<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ChartOfAccount;
use App\Models\User;
use App\Models\TransactionLines;
use App\Support\ChartOfAccountHierarchy;
use Illuminate\Support\Facades\DB;

// Find MMCEMENT company
$company = User::where('name', 'like', '%MMCEMENT%')->orWhere('name', 'like', '%MMC%')->first();
if (!$company) {
    $company = User::where('type', 'company')->orderBy('id')->first();
}
echo "Company: {$company->id} {$company->name}\n\n";
$cid = (int) $company->id;

$start = '2025-01-01';
$end = '2026-07-10';

function bal($cid, $accountId, $start, $end) {
    $row = TransactionLines::where('created_by', $cid)
        ->where('account_id', $accountId)
        ->whereDate('date', '>=', $start)
        ->whereDate('date', '<=', $end)
        ->selectRaw('SUM(debit) d, SUM(credit) c')
        ->first();
    return [(float)($row->d ?? 0), (float)($row->c ?? 0)];
}

$codes = ['21', '211', '2110001', '632', '632131', '42', '421', '4210', '4210001', '429'];
$accounts = ChartOfAccount::where('created_by', $cid)->whereIn('code', $codes)->get()->keyBy(fn ($a) => (string) $a->code);

foreach ($codes as $code) {
    $a = $accounts->get($code);
    if (!$a) {
        echo "MISSING code $code\n";
        continue;
    }
    $parent = $a->parent_account_id ? ChartOfAccount::find($a->parent_account_id) : null;
    [$d, $c] = bal($cid, $a->id, $start, $end);
    $net = $d - $c;
    echo sprintf(
        "%s id=%d parent=%s direct_debit=%.2f credit=%.2f net=%.2f name=%s\n",
        $code,
        $a->id,
        $parent ? $parent->code : 'null',
        $d,
        $c,
        $net,
        $a->name
    );
}

echo "\n--- Children of 21 ---\n";
$a21 = $accounts->get('21');
if ($a21) {
    $kids = ChartOfAccount::where('created_by', $cid)->get()->filter(fn ($x) => ChartOfAccountHierarchy::effectiveParentAccountId($x) === (int)$a21->id);
    foreach ($kids as $k) {
        [$d, $c] = bal($cid, $k->id, $start, $end);
        echo "  {$k->code} net=".($d-$c)." parent_id={$k->parent_account_id}\n";
    }
}

echo "\n--- Children of 211 ---\n";
$a211 = $accounts->get('211');
if ($a211) {
    $kids = ChartOfAccount::where('created_by', $cid)->get()->filter(fn ($x) => ChartOfAccountHierarchy::effectiveParentAccountId($x) === (int)$a211->id);
    foreach ($kids as $k) {
        [$d, $c] = bal($cid, $k->id, $start, $end);
        echo "  {$k->code} net=".($d-$c)."\n";
    }
}

echo "\n--- Children of 632 ---\n";
$a632 = ChartOfAccount::where('created_by', $cid)->where('code', '632')->first();
if ($a632) {
    $all = ChartOfAccount::where('created_by', $cid)->get();
    $kids = $all->filter(fn ($x) => ChartOfAccountHierarchy::effectiveParentAccountId($x) === (int)$a632->id);
    $sumNet = 0;
    foreach ($kids as $k) {
        [$d, $c] = bal($cid, $k->id, $start, $end);
        $net = $d - $c;
        if (abs($d) > 0.001 || abs($c) > 0.001) {
            echo "  {$k->code} debit=$d credit=$c net=$net\n";
            $sumNet += $net;
        }
    }
    [$pd, $pc] = bal($cid, $a632->id, $start, $end);
    echo "632 direct net=".($pd-$pc)." children_sum_net=$sumNet total=".(($pd-$pc)+$sumNet)."\n";
}

echo "\n--- Children of 632131 ---\n";
$a632131 = ChartOfAccount::where('created_by', $cid)->where('code', '632131')->first();
if ($a632131) {
    $all = ChartOfAccount::where('created_by', $cid)->get();
    $kids = $all->filter(fn ($x) => ChartOfAccountHierarchy::effectiveParentAccountId($x) === (int)$a632131->id);
    $sumNet = 0;
    foreach ($kids as $k) {
        [$d, $c] = bal($cid, $k->id, $start, $end);
        $net = $d - $c;
        if (abs($d) > 0.001 || abs($c) > 0.001) {
            echo "  {$k->code} net=$net\n";
            $sumNet += $net;
        }
    }
    [$pd, $pc] = bal($cid, $a632131->id, $start, $end);
    echo "632131 direct net=".($pd-$pc)." children_sum=$sumNet total=".(($pd-$pc)+$sumNet)."\n";
}

echo "\n--- Grand totals ---\n";
$t = TransactionLines::where('created_by', $cid)
    ->whereDate('date', '>=', $start)
    ->whereDate('date', '<=', $end)
    ->selectRaw('SUM(debit) d, SUM(credit) c')
    ->first();
echo "debit={$t->d} credit={$t->c} diff=".($t->d - $t->c)."\n";

// Unbalanced journals
echo "\n--- Top unbalanced journals ---\n";
$unbal = DB::table('journal_entries as je')
    ->join('journal_items as ji', 'ji.journal', '=', 'je.id')
    ->where('je.created_by', $cid)
    ->whereBetween('je.date', [$start, $end])
    ->groupBy('je.id', 'je.journal_id', 'je.reference', 'je.date')
    ->selectRaw('je.id, je.journal_id, je.reference, je.date, SUM(ji.debit) d, SUM(ji.credit) c, ROUND(SUM(ji.debit)-SUM(ji.credit),2) diff')
    ->havingRaw('ABS(SUM(ji.debit)-SUM(ji.credit)) > 0.01')
    ->orderByDesc(DB::raw('ABS(SUM(ji.debit)-SUM(ji.credit))'))
    ->limit(15)
    ->get();
foreach ($unbal as $u) {
    echo "JE {$u->journal_id} {$u->date} {$u->reference} d={$u->d} c={$u->c} diff={$u->diff}\n";
}

// TL vs JI mismatch sample
echo "\n--- TL imbalance by reference type ---\n";
$byRef = TransactionLines::where('created_by', $cid)
    ->whereDate('date', '>=', $start)
    ->whereDate('date', '<=', $end)
    ->selectRaw('reference, SUM(debit) d, SUM(credit) c, ROUND(SUM(debit)-SUM(credit),2) diff')
    ->groupBy('reference')
    ->orderByDesc(DB::raw('ABS(SUM(debit)-SUM(credit))'))
    ->limit(20)
    ->get();
foreach ($byRef as $r) {
    echo "{$r->reference}: d={$r->d} c={$r->c} diff={$r->diff}\n";
}
