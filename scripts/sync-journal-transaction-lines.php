<?php

/**
 * Sync journal_items → transaction_lines so journals (incl. year-end closing)
 * appear on Trial Balance.
 *
 * Usage:
 *   php scripts/sync-journal-transaction-lines.php
 *   php scripts/sync-journal-transaction-lines.php 6
 *   php scripts/sync-journal-transaction-lines.php 6 --closing-only
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\JournalEntry;
use App\Models\JournalItem;
use App\Models\TransactionLines;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$companyId = null;
$closingOnly = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--closing-only') {
        $closingOnly = true;
    } elseif (ctype_digit($arg)) {
        $companyId = (int) $arg;
    }
}

$companies = User::where('type', 'company')
    ->when($companyId, fn ($q) => $q->where('id', $companyId))
    ->orderBy('id')
    ->get();

foreach ($companies as $company) {
    echo "=== Company {$company->id} {$company->name} ===\n";

    $q = JournalEntry::where('created_by', $company->id);
    if ($closingOnly) {
        $q->where('reference', 'like', 'FY-%CLOSE%');
    }

    $journals = $q->orderBy('id')->get();
    $created = 0;
    $updated = 0;
    $skipped = 0;

    foreach ($journals as $journal) {
        $items = JournalItem::where('journal', $journal->id)->get();
        foreach ($items as $item) {
            $debit = round((float) $item->debit, 2);
            $credit = round((float) $item->credit, 2);
            if (abs($debit) < 0.0001 && abs($credit) < 0.0001) {
                $skipped++;
                continue;
            }

            // Prefer exact match; also fix lines that landed under the wrong created_by.
            $tl = TransactionLines::query()
                ->where('reference', 'Journal')
                ->where('reference_id', $journal->id)
                ->where('reference_sub_id', $item->id)
                ->first();

            if (! $tl) {
                $tl = new TransactionLines();
                $created++;
            } else {
                $updated++;
            }

            $tl->account_id = (int) $item->account;
            $tl->reference = 'Journal';
            $tl->reference_id = (int) $journal->id;
            $tl->reference_sub_id = (int) $item->id;
            $tl->date = $journal->date;
            $tl->debit = $debit > 0 ? $debit : 0;
            $tl->credit = $credit > 0 ? $credit : 0;
            $tl->created_by = (int) $company->id;
            $tl->save();
        }
    }

    // Remove orphan Journal TL rows for this company that point at missing items
    // (optional safety — only for closing refs if flag set).
    if ($closingOnly) {
        $closingIds = JournalEntry::where('created_by', $company->id)
            ->where('reference', 'like', 'FY-%CLOSE%')
            ->pluck('id');
        $orphanWrongCompany = TransactionLines::query()
            ->where('reference', 'Journal')
            ->whereIn('reference_id', $closingIds)
            ->where('created_by', '!=', $company->id)
            ->delete();
        if ($orphanWrongCompany) {
            echo "Removed {$orphanWrongCompany} closing TL row(s) with wrong created_by.\n";
        }
    }

    echo "Journals scanned: {$journals->count()} | TL created: {$created} | updated: {$updated} | skipped empty: {$skipped}\n\n";
}

echo "Done. Open Trial Balance with a date range that includes the closing date.\n";
