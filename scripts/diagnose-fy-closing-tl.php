<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$rows = DB::table('journal_entries')
    ->where('reference', 'like', 'FY-%CLOSE%')
    ->orderByDesc('id')
    ->limit(30)
    ->get(['id', 'journal_id', 'reference', 'date', 'created_by', 'accounting_period']);

if ($rows->isEmpty()) {
    echo "No FY-*-CLOSE journals found.\n";
    exit;
}

foreach ($rows as $r) {
    $ji = DB::table('journal_items')->where('journal', $r->id)->count();
    $tl = DB::table('transaction_lines')
        ->where('reference', 'Journal')
        ->where('reference_id', $r->id)
        ->count();
    $tlBy = DB::table('transaction_lines')
        ->where('reference', 'Journal')
        ->where('reference_id', $r->id)
        ->selectRaw('created_by, COUNT(*) c')
        ->groupBy('created_by')
        ->get();
    echo "JE#{$r->id} {$r->reference} date={$r->date} je.created_by={$r->created_by} period={$r->accounting_period} items={$ji} tl={$tl}";
    foreach ($tlBy as $t) {
        echo " tl.created_by={$t->created_by}({$t->c})";
    }
    echo PHP_EOL;
}
