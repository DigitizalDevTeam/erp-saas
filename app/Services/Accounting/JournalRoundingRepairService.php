<?php

namespace App\Services\Accounting;

use App\Models\JournalItem;
use App\Models\TransactionLines;
use Illuminate\Support\Facades\DB;

/**
 * Removes duplicate Journal transaction_lines and fixes sub-cent
 * debit/credit mismatches so the ledger (and Balance Sheet) can balance.
 */
class JournalRoundingRepairService
{
    /**
     * @return array{deduped: int, adjusted: int, warnings: list<string>}
     */
    public function repair(?int $companyId = null): array
    {
        $deduped = 0;
        $adjusted = 0;
        $warnings = [];

        $query = TransactionLines::query()
            ->select('created_by', 'reference_id')
            ->where('reference', 'Journal')
            ->groupBy('created_by', 'reference_id');

        if ($companyId !== null) {
            $query->where('created_by', $companyId);
        }

        foreach ($query->get() as $group) {
            $createdBy = (int) $group->created_by;
            $journalId = (int) $group->reference_id;

            DB::transaction(function () use ($createdBy, $journalId, &$deduped, &$adjusted, &$warnings) {
                $removed = $this->dedupeJournalLines($createdBy, $journalId);
                $deduped += $removed;

                $diff = $this->journalDiff($createdBy, $journalId);
                if (abs($diff) < 0.005) {
                    $this->syncJournalItemsFromLines($journalId, $createdBy);

                    return;
                }

                if (abs($diff) > 1.0) {
                    $warnings[] = "Company {$createdBy}, journal {$journalId}: imbalance {$diff} exceeds 1.00 — skipped (needs manual review).";

                    return;
                }

                if ($this->adjustImbalance($createdBy, $journalId, $diff)) {
                    $adjusted++;
                    $this->syncJournalItemsFromLines($journalId, $createdBy);
                } else {
                    $warnings[] = "Company {$createdBy}, journal {$journalId}: could not adjust imbalance {$diff}.";
                }
            });
        }

        return [
            'deduped' => $deduped,
            'adjusted' => $adjusted,
            'warnings' => $warnings,
        ];
    }

    private function journalDiff(int $createdBy, int $journalId): float
    {
        $row = TransactionLines::query()
            ->where('created_by', $createdBy)
            ->where('reference', 'Journal')
            ->where('reference_id', $journalId)
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();

        return round((float) $row->d - (float) $row->c, 2);
    }

    /**
     * When each journal item was posted more than once, keep the posting
     * generation that balances (or the closest), delete the rest.
     */
    private function dedupeJournalLines(int $createdBy, int $journalId): int
    {
        $lines = TransactionLines::query()
            ->where('created_by', $createdBy)
            ->where('reference', 'Journal')
            ->where('reference_id', $journalId)
            ->orderBy('id')
            ->get();

        if ($lines->isEmpty()) {
            return 0;
        }

        $bySub = $lines->groupBy(fn ($l) => (int) $l->reference_sub_id);
        $counts = $bySub->map->count()->values()->unique();
        $n = (int) $bySub->first()->count();

        if ($counts->count() !== 1 || $n < 2) {
            return 0;
        }

        $candidates = [];
        for ($i = 0; $i < $n; $i++) {
            $set = [];
            $debit = 0.0;
            $credit = 0.0;
            foreach ($bySub as $group) {
                $sorted = $group->values()->sortBy('id')->values();
                $line = $sorted[$i];
                $set[] = $line;
                $debit += (float) $line->debit;
                $credit += (float) $line->credit;
            }
            $candidates[] = [
                'lines' => $set,
                'diff' => abs(round($debit - $credit, 2)),
                'ids' => collect($set)->pluck('id')->all(),
            ];
        }

        usort($candidates, fn ($a, $b) => $a['diff'] <=> $b['diff']);
        $keepIds = $candidates[0]['ids'];
        $remove = $lines->whereNotIn('id', $keepIds);
        $removed = $remove->count();
        foreach ($remove as $line) {
            $line->delete();
        }

        return $removed;
    }

    private function adjustImbalance(int $createdBy, int $journalId, float $diff): bool
    {
        // diff = debit - credit. Positive → need more credit (or less debit).
        $lines = TransactionLines::query()
            ->where('created_by', $createdBy)
            ->where('reference', 'Journal')
            ->where('reference_id', $journalId)
            ->orderByDesc(DB::raw('GREATEST(debit, credit)'))
            ->orderBy('id')
            ->get();

        if ($lines->isEmpty()) {
            return false;
        }

        $target = $lines->first();
        $amount = abs($diff);

        if ($diff > 0) {
            // Debits exceed credits.
            if ((float) $target->credit > 0) {
                $target->credit = round((float) $target->credit + $amount, 2);
            } elseif ((float) $target->debit > $amount) {
                $target->debit = round((float) $target->debit - $amount, 2);
            } else {
                $creditLine = $lines->first(fn ($l) => (float) $l->credit > 0) ?? $target;
                $creditLine->credit = round((float) $creditLine->credit + $amount, 2);
                $creditLine->save();

                return abs($this->journalDiff($createdBy, $journalId)) < 0.005;
            }
        } else {
            // Credits exceed debits.
            if ((float) $target->debit > 0) {
                $target->debit = round((float) $target->debit + $amount, 2);
            } elseif ((float) $target->credit > $amount) {
                $target->credit = round((float) $target->credit - $amount, 2);
            } else {
                $debitLine = $lines->first(fn ($l) => (float) $l->debit > 0) ?? $target;
                $debitLine->debit = round((float) $debitLine->debit + $amount, 2);
                $debitLine->save();

                return abs($this->journalDiff($createdBy, $journalId)) < 0.005;
            }
        }

        $target->save();

        return abs($this->journalDiff($createdBy, $journalId)) < 0.005;
    }

    private function syncJournalItemsFromLines(int $journalId, int $createdBy): void
    {
        $lines = TransactionLines::query()
            ->where('created_by', $createdBy)
            ->where('reference', 'Journal')
            ->where('reference_id', $journalId)
            ->get();

        foreach ($lines as $line) {
            $item = JournalItem::find((int) $line->reference_sub_id);
            if (! $item) {
                continue;
            }
            $item->debit = (float) $line->debit;
            $item->credit = (float) $line->credit;
            $item->account = (int) $line->account_id;
            $item->save();
        }
    }
}
