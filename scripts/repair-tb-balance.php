<?php

/**
 * Repair existing one-sided Bill/Invoice transaction_lines and COA hierarchy.
 * Usage: php scripts/repair-tb-balance.php [company_id]
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Bill;
use App\Models\Invoice;
use App\Models\TransactionLines;
use App\Models\User;
use App\Services\Accounting\DocumentLedgerService;
use App\Support\ChartOfAccountHierarchy;

$companyId = isset($argv[1]) ? (int) $argv[1] : null;
$companies = User::where('type', 'company')
    ->when($companyId, fn ($q) => $q->where('id', $companyId))
    ->orderBy('id')
    ->get();

$ledger = app(DocumentLedgerService::class);

// Clean mistaken counterpart lines from earlier repair (wrong created_by via Auth).
TransactionLines::whereIn('reference', ['Bill Payable', 'Invoice Receivable'])
    ->when($companyId, function ($q) use ($companyId) {
        // Keep only lines whose reference_id belongs to this company's docs, delete orphans later per company
    })
    ->whereNotIn('created_by', $companies->pluck('id'))
    ->delete();

foreach ($companies as $company) {
    echo "=== Company {$company->id} {$company->name} ===\n";

    $hier = ChartOfAccountHierarchy::repairParentsByCodePrefix((int) $company->id);
    ChartOfAccountHierarchy::syncTypesFromParents((int) $company->id);
    echo 'Hierarchy: ' . json_encode($hier) . "\n";

    // Remove any counterpart lines for this company then rebuild.
    TransactionLines::where('created_by', $company->id)
        ->whereIn('reference', ['Bill Payable', 'Invoice Receivable'])
        ->delete();

    $billsFixed = 0;
    $billIds = TransactionLines::where('created_by', $company->id)
        ->whereIn('reference', ['Bill', 'Bill Account'])
        ->distinct()
        ->pluck('reference_id');

    foreach ($billIds as $billId) {
        $bill = Bill::find($billId);
        if (! $bill) {
            continue;
        }
        $before = TransactionLines::where('created_by', $company->id)
            ->where('reference_id', $billId)
            ->whereIn('reference', ['Bill', 'Bill Account'])
            ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')
            ->first();
        $diff = round((float) $before->d - (float) $before->c, 2);
        if (abs($diff) < 0.01) {
            continue;
        }
        $ledger->balanceDocumentLines(
            (int) $company->id,
            (int) $billId,
            ['Bill', 'Bill Account'],
            'payable',
            'Bill Payable',
            (string) ($bill->bill_date ?? $bill->send_date ?? date('Y-m-d'))
        );
        $billsFixed++;
    }
    echo "Bills balanced: {$billsFixed}\n";

    $invoicesFixed = 0;
    $invoiceIds = TransactionLines::where('created_by', $company->id)
        ->where('reference', 'Invoice')
        ->distinct()
        ->pluck('reference_id');

    foreach ($invoiceIds as $invoiceId) {
        $invoice = Invoice::find($invoiceId);
        if (! $invoice) {
            continue;
        }
        $before = TransactionLines::where('created_by', $company->id)
            ->where('reference_id', $invoiceId)
            ->whereIn('reference', ['Invoice'])
            ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')
            ->first();
        $diff = round((float) $before->d - (float) $before->c, 2);
        if (abs($diff) < 0.01) {
            continue;
        }
        $ledger->balanceDocumentLines(
            (int) $company->id,
            (int) $invoiceId,
            ['Invoice'],
            'receivable',
            'Invoice Receivable',
            (string) ($invoice->issue_date ?? date('Y-m-d'))
        );
        $invoicesFixed++;
    }
    echo "Invoices balanced: {$invoicesFixed}\n";

    // Fix one-sided invoice payments (Debit bank without Credit AR)
    $paymentsFixed = 0;
    $payRows = TransactionLines::where('created_by', $company->id)
        ->where('reference', 'Invoice Payment')
        ->get();
    foreach ($payRows as $pay) {
        $hasAr = TransactionLines::where('created_by', $company->id)
            ->where('reference', 'Invoice Payment AR')
            ->where('reference_id', $pay->reference_id)
            ->where('reference_sub_id', $pay->reference_sub_id)
            ->exists();
        if ($hasAr) {
            continue;
        }
        $bankId = (int) $pay->account_id;
        $amount = (float) $pay->debit > 0 ? (float) $pay->debit : (float) $pay->credit;
        $ledger->postInvoicePayment(
            (int) $company->id,
            (int) $pay->reference_id,
            (int) $pay->reference_sub_id,
            $amount,
            (string) $pay->date,
            $bankId
        );
        $paymentsFixed++;
    }
    echo "Invoice payments balanced: {$paymentsFixed}\n";

    $t = TransactionLines::where('created_by', $company->id)
        ->selectRaw('COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c')
        ->first();
    echo 'TB totals now: debit=' . round($t->d, 2) . ' credit=' . round($t->c, 2) . ' diff=' . round($t->d - $t->c, 2) . "\n";

    // Verify 4210 parent
    $a4210 = \App\Models\ChartOfAccount::where('created_by', $company->id)->where('code', '4210')->first();
    if ($a4210) {
        $p = $a4210->parent_account_id ? \App\Models\ChartOfAccount::find($a4210->parent_account_id) : null;
        echo '4210 parent=' . ($p->code ?? 'null') . "\n";
    }
    echo "\n";
}

echo "Done.\n";
