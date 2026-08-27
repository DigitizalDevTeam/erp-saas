<?php

namespace App\Services\Accounting;

use App\Models\JournalItem;
use App\Models\TransactionLines;
use Illuminate\Support\Facades\DB;

/**
 * Removes duplicate transaction_lines and fixes sub-cent debit/credit
 * mismatches so the ledger (and Balance Sheet) can balance.
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

        $journalQuery = TransactionLines::query()
            ->select('created_by', 'reference_id')
            ->where('reference', 'Journal')
            ->groupBy('created_by', 'reference_id');

        if ($companyId !== null) {
            $journalQuery->where('created_by', $companyId);
        }

        foreach ($journalQuery->get() as $group) {
            $createdBy = (int) $group->created_by;
            $journalId = (int) $group->reference_id;

            DB::transaction(function () use ($createdBy, $journalId, &$deduped, &$adjusted, &$warnings) {
                $removed = $this->dedupeLines($createdBy, 'Journal', $journalId);
                $deduped += $removed;

                $this->repairGroup($createdBy, 'Journal', $journalId, $adjusted, $warnings, true);
            });
        }

        $documentQuery = TransactionLines::query()
            ->select('created_by', 'reference', 'reference_id')
            ->where('reference', '!=', 'Journal')
            ->groupBy('created_by', 'reference', 'reference_id');

        if ($companyId !== null) {
            $documentQuery->where('created_by', $companyId);
        }

        foreach ($documentQuery->get() as $group) {
            $createdBy = (int) $group->created_by;
            $reference = (string) $group->reference;
            $referenceId = (int) $group->reference_id;

            DB::transaction(function () use ($createdBy, $reference, $referenceId, &$deduped, &$adjusted, &$warnings) {
                $removed = $this->dedupeLines($createdBy, $reference, $referenceId);
                $deduped += $removed;

                $this->repairGroup($createdBy, $reference, $referenceId, $adjusted, $warnings, false);
            });
        }

        return [
            'deduped' => $deduped,
            'adjusted' => $adjusted,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  list<string>  $warnings
     */
    private function repairGroup(
        int $createdBy,
        string $reference,
        int $referenceId,
        int &$adjusted,
        array &$warnings,
        bool $syncJournalItems
    ): void {
        $diff = $this->groupDiff($createdBy, $reference, $referenceId);

        if (abs($diff) < 0.005) {
            if ($syncJournalItems) {
                $this->syncJournalItemsFromLines($referenceId, $createdBy);
            }

            return;
        }

        $label = $reference === 'Journal'
            ? "journal {$referenceId}"
            : "{$reference} #{$referenceId}";

        if (abs($diff) > 1.0) {
            $warnings[] = "Company {$createdBy}, {$label}: imbalance {$diff} exceeds 1.00 — skipped (needs manual review).";

            return;
        }

        if ($this->adjustGroupImbalance($createdBy, $reference, $referenceId, $diff)) {
            $adjusted++;
            if ($syncJournalItems) {
                $this->syncJournalItemsFromLines($referenceId, $createdBy);
            }
        } else {
            $warnings[] = "Company {$createdBy}, {$label}: could not adjust imbalance {$diff}.";
        }
    }

    private function groupDiff(int $createdBy, string $reference, int $referenceId): float
    {
        $row = TransactionLines::query()
            ->where('created_by', $createdBy)
            ->where('reference', $reference)
            ->where('reference_id', $referenceId)
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();

        return round((float) $row->d - (float) $row->c, 2);
    }

    /**
     * When each sub-line was posted more than once, keep the generation
     * that balances (or the closest), delete the rest.
     */
    private function dedupeLines(int $createdBy, string $reference, int $referenceId): int
    {
        $lines = TransactionLines::query()
            ->where('created_by', $createdBy)
            ->where('reference', $reference)
            ->where('reference_id', $referenceId)
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

    private function adjustGroupImbalance(int $createdBy, string $reference, int $referenceId, float $diff): bool
    {
        // diff = debit - credit. Positive → need more credit (or less debit).
        $lines = TransactionLines::query()
            ->where('created_by', $createdBy)
            ->where('reference', $reference)
            ->where('reference_id', $referenceId)
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

                return abs($this->groupDiff($createdBy, $reference, $referenceId)) < 0.005;
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

                return abs($this->groupDiff($createdBy, $reference, $referenceId)) < 0.005;
            }
        }

        $target->save();

        return abs($this->groupDiff($createdBy, $reference, $referenceId)) < 0.005;
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
