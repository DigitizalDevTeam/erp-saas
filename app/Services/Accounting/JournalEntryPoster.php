<?php

namespace App\Services\Accounting;

use App\Models\BankAccount;
use App\Models\JournalEntry;
use App\Models\JournalItem;
use App\Models\TransactionLines;

class JournalEntryPoster
{
    public function nextJournalId(int $createdBy): int
    {
        $latest = JournalEntry::where('created_by', $createdBy)->latest()->first();

        return $latest ? (int) $latest->journal_id + 1 : 1;
    }

    /**
     * @param  array<int, array{account: int, debit?: float|int, credit?: float|int, description?: string|null}>  $lines
     */
    public function post(
        int $createdBy,
        string $date,
        ?string $reference,
        ?string $description,
        array $lines,
        ?int $accountingPeriod = null
    ): JournalEntry {
        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $normalizedLines = [];
        foreach ($lines as $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);
            $net = round($debit - $credit, 2);
            if (abs($net) < 0.0001) {
                continue;
            }
            $normalized = [
                'account' => (int) $line['account'],
                'debit' => $net > 0 ? $net : 0.0,
                'credit' => $net < 0 ? abs($net) : 0.0,
                'description' => $line['description'] ?? null,
            ];
            $totalDebit += $normalized['debit'];
            $totalCredit += $normalized['credit'];
            $normalizedLines[] = $normalized;
        }
        if (round($totalDebit, 2) !== round($totalCredit, 2)) {
            throw new \InvalidArgumentException(__('Debit and credit totals must be equal.'));
        }

        $journal = new JournalEntry;
        $journal->journal_id = $this->nextJournalId($createdBy);
        $journal->date = $date;
        $journal->reference = $reference;
        $journal->description = $description;
        // This poster writes the ledger lines immediately, so the journal is
        // already in effect. Leaving it "pending" lets a later manual Approve
        // post the same lines a second time and double-count the journal.
        $journal->status = JournalEntry::STATUS_APPROVED;
        $journal->created_by = $createdBy;
        $journal->accounting_period = $accountingPeriod;
        $journal->save();

        foreach ($normalizedLines as $line) {
            $debit = $line['debit'];
            $credit = $line['credit'];

            $journalItem = new JournalItem;
            $journalItem->journal = $journal->id;
            $journalItem->account = (int) $line['account'];
            $journalItem->description = $line['description'] ?? null;
            $journalItem->debit = $debit;
            $journalItem->credit = $credit;
            $journalItem->save();

            $bankAccounts = BankAccount::where('chart_account_id', '=', $line['account'])->get();
            foreach ($bankAccounts as $bankAccount) {
                $old_balance = $bankAccount->opening_balance;
                $new_balance = null;
                if ($journalItem->debit > 0) {
                    $new_balance = $old_balance - $journalItem->debit;
                }
                if ($journalItem->credit > 0) {
                    $new_balance = $old_balance + $journalItem->credit;
                }
                if ($new_balance !== null) {
                    $bankAccount->opening_balance = $new_balance;
                    $bankAccount->save();
                }
            }

            // Same company id as the journal — never Auth::creatorId() (can drift).
            $tl = TransactionLines::query()
                ->where('reference', 'Journal')
                ->where('reference_id', $journal->id)
                ->where('reference_sub_id', $journalItem->id)
                ->first() ?? new TransactionLines();

            $tl->account_id = (int) $line['account'];
            $tl->reference = 'Journal';
            $tl->reference_id = $journal->id;
            $tl->reference_sub_id = $journalItem->id;
            $tl->date = $journal->date;
            $tl->debit = $debit > 0 ? $debit : 0;
            $tl->credit = $credit > 0 ? $credit : 0;
            $tl->created_by = $createdBy;
            $tl->save();
        }

        return $journal;
    }
}
