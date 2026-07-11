<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ChartOfAccount;
use App\Models\TransactionLines;
use App\Support\ChartOfAccountHierarchy;
use Illuminate\Support\Facades\DB;

$cid = 6;
$start = '2025-01-01';
$end = '2026-07-10';

echo "=== Accounts with activity under code starting 21 ===\n";
$rows = DB::table('transaction_lines as tl')
    ->join('chart_of_accounts as ca', 'ca.id', '=', 'tl.account_id')
    ->where('tl.created_by', $cid)
    ->whereDate('tl.date', '>=', $start)
    ->whereDate('tl.date', '<=', $end)
    ->where('ca.code', 'like', '21%')
    ->groupBy('ca.id', 'ca.code', 'ca.name', 'ca.parent_account_id')
    ->selectRaw('ca.id, ca.code, ca.name, ca.parent_account_id, SUM(tl.debit) d, SUM(tl.credit) c')
    ->orderByRaw('CAST(ca.code AS CHAR)')
    ->get();
foreach ($rows as $r) {
    $p = $r->parent_account_id ? ChartOfAccount::find($r->parent_account_id) : null;
    echo "{$r->code} parent=".($p->code ?? 'null')." d={$r->d} c={$r->c} net=".($r->d-$r->c)." {$r->name}\n";
}

echo "\n=== Accounts with activity under 632 ===\n";
$rows = DB::table('transaction_lines as tl')
    ->join('chart_of_accounts as ca', 'ca.id', '=', 'tl.account_id')
    ->where('tl.created_by', $cid)
    ->whereDate('tl.date', '>=', $start)
    ->whereDate('tl.date', '<=', $end)
    ->where(function ($q) {
        $q->where('ca.code', 'like', '632%')->orWhere('ca.code', '63');
    })
    ->groupBy('ca.id', 'ca.code', 'ca.name', 'ca.parent_account_id')
    ->selectRaw('ca.id, ca.code, ca.name, ca.parent_account_id, SUM(tl.debit) d, SUM(tl.credit) c')
    ->orderByRaw('LENGTH(CAST(ca.code AS CHAR)), CAST(ca.code AS CHAR)')
    ->get();
foreach ($rows as $r) {
    $p = $r->parent_account_id ? ChartOfAccount::find($r->parent_account_id) : null;
    echo "{$r->code} parent=".($p->code ?? 'null')." d={$r->d} c={$r->c} net=".($r->d-$r->c)." {$r->name}\n";
}

echo "\n=== Sample Bill TL rows (missing credit?) ===\n";
$bills = TransactionLines::where('created_by', $cid)
    ->where('reference', 'Bill')
    ->whereDate('date', '>=', $start)
    ->whereDate('date', '<=', $end)
    ->orderBy('reference_id')
    ->limit(30)
    ->get(['id', 'account_id', 'debit', 'credit', 'reference_id', 'date', 'transaction_type']);
foreach ($bills as $b) {
    $ca = ChartOfAccount::find($b->account_id);
    echo "bill#{$b->reference_id} {$b->date} {$ca->code} d={$b->debit} c={$b->credit} type={$b->transaction_type}\n";
}

echo "\n=== Bill journal/items check for one bill ===\n";
$billId = TransactionLines::where('created_by', $cid)->where('reference', 'Bill')->value('reference_id');
if ($billId) {
    $tl = TransactionLines::where('created_by', $cid)->where('reference', 'Bill')->where('reference_id', $billId)->get();
    echo "TL for bill $billId:\n";
    foreach ($tl as $t) {
        $ca = ChartOfAccount::find($t->account_id);
        echo "  {$ca->code} d={$t->debit} c={$t->credit}\n";
    }
    // bill table
    $bill = DB::table('bills')->where('id', $billId)->first();
    if ($bill) {
        echo "Bill status={$bill->status} total=" . ($bill->get_total ?? $bill->total ?? '?') . "\n";
        print_r(array_keys((array)$bill));
    }
}

echo "\n=== Codes like %0001 under 21/42 ===\n";
$custom = ChartOfAccount::where('created_by', $cid)
    ->where(function ($q) {
        $q->where('code', 'like', '21%')->orWhere('code', 'like', '42%');
    })
    ->orderByRaw('CAST(code AS CHAR)')
    ->get(['code', 'name', 'parent_account_id']);
foreach ($custom as $c) {
    if (strlen((string)$c->code) >= 6) {
        $p = $c->parent_account_id ? ChartOfAccount::find($c->parent_account_id) : null;
        echo "{$c->code} parent=".($p->code ?? 'null')." {$c->name}\n";
    }
}
