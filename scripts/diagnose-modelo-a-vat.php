<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Http\Controllers\MonthlyVatReportController;
use App\Services\Accounting\VatModeloAReportService;
use Illuminate\Support\Facades\DB;

$cid = (int) (DB::table('transaction_lines')->select('created_by')->groupBy('created_by')->orderByRaw('COUNT(*) DESC')->value('created_by') ?? 0);
if ($cid === 0) {
    echo "No transaction_lines found.\n";
    exit(0);
}

echo "creatorId={$cid}\n";
$ctrl = app(MonthlyVatReportController::class);
$svc = app(VatModeloAReportService::class);
$settlementIds = $svc->settlementJournalIds($cid);
echo 'settlementJournalIds count='.count($settlementIds)."\n";

foreach ([1, 2, 3] as $m) {
    $start = sprintf('2025-%02d-01', $m);
    $end = date('Y-m-t', strtotime($start));
    echo "\nMonth {$m} ({$start} to {$end}):\n";

    $rows = DB::table('transaction_lines as tl')
        ->join('chart_of_accounts as coa', 'coa.id', '=', 'tl.account_id')
        ->where('tl.created_by', $cid)
        ->whereBetween('tl.date', [$start, $end])
        ->where(function ($q): void {
            $q->whereRaw('CAST(coa.code AS CHAR) LIKE ?', ['4432%'])
                ->orWhereRaw('CAST(coa.code AS CHAR) LIKE ?', ['44331%']);
        })
        ->selectRaw('coa.code, SUM(tl.debit) as d, SUM(tl.credit) as c')
        ->groupBy('coa.code')
        ->get();

    foreach ($rows as $r) {
        echo "  {$r->code}: debit={$r->d} credit={$r->c} net=".round($r->d - $r->c, 2)."\n";
    }

    $report = $ctrl->buildReportData($cid, $start, $end, [], true);
    echo "  Model A f02={$report['f02']} f07={$report['f07']}\n";

    $journals = DB::table('transaction_lines as tl')
        ->join('journal_entries as je', 'je.id', '=', 'tl.reference_id')
        ->join('chart_of_accounts as coa', 'coa.id', '=', 'tl.account_id')
        ->where('tl.created_by', $cid)
        ->where('tl.reference', 'Journal')
        ->whereBetween('tl.date', [$start, $end])
        ->where(function ($q): void {
            $q->whereRaw('CAST(coa.code AS CHAR) LIKE ?', ['44323%'])
                ->orWhereRaw('CAST(coa.code AS CHAR) LIKE ?', ['44331%']);
        })
        ->select('je.id', 'je.reference', 'coa.code', 'tl.debit', 'tl.credit')
        ->get();

    foreach ($journals as $j) {
        $inSet = in_array((int) $j->id, $settlementIds, true) ? 'YES' : 'NO';
        echo "  Journal {$j->id} ({$j->reference}) {$j->code} D={$j->debit} C={$j->credit} excluded={$inSet}\n";
    }
}
