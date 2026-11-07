<?php

namespace App\Services\Accounting\BankReconciliation;

use App\Models\BankReconciliationMatch;
use App\Models\BankReconciliationSession;
use App\Models\BankStatementLine;
use App\Models\Transaction;
use App\Models\TreasuryEntry;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryOperation;
use Carbon\Carbon;

class ReconciliationMatcher
{
    public function runAutoMatch(BankReconciliationSession $session): array
    {
        $tol = (int) $session->date_tolerance_days;
        $eps = (float) config('bank_reconciliation.amount_epsilon');
        $creatorId = (int) $session->created_by;
        $bankAccountId = (int) $session->bank_account_id;

        BankReconciliationMatch::where('session_id', $session->id)
            ->where('match_type', 'auto')
            ->delete();

        $remaining = BankReconciliationMatch::where('session_id', $session->id)->get();

        $usedTransactionIds = $remaining
            ->pluck('transaction_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $lockedStatementLineIds = $remaining
            ->pluck('statement_line_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($session->period_from === null || $session->period_to === null) {
            return ['auto_matched' => 0, 'message' => __('Statement has no dated lines to match.')];
        }

        $from = Carbon::parse($session->period_from)->subDays($tol)->toDateString();
        $to = Carbon::parse($session->period_to)->addDays($tol)->toDateString();

        $transactions = Transaction::query()
            ->where('created_by', $creatorId)
            ->where('account', $bankAccountId)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $lines = BankStatementLine::query()
            ->where('session_id', $session->id)
            ->whereNotIn('id', $lockedStatementLineIds)
            ->whereNotNull('txn_date')
            ->whereNotNull('amount')
            ->orderBy('txn_date')
            ->orderBy('id')
            ->get();

        $autoMatched = 0;

        foreach ($lines as $line) {
            $lineDate = Carbon::parse($line->txn_date);
            $targetAbs = abs((float) $line->amount);

            foreach ($transactions as $tx) {
                if (in_array((int) $tx->id, $usedTransactionIds, true)) {
                    continue;
                }

                $txAbs = abs((float) $tx->amount);
                if (abs($txAbs - $targetAbs) > $eps) {
                    continue;
                }

                $txDate = Carbon::parse($tx->date);
                if (abs($txDate->diffInDays($lineDate)) > $tol) {
                    continue;
                }

                BankReconciliationMatch::create([
                    'session_id' => $session->id,
                    'statement_line_id' => $line->id,
                    'transaction_id' => $tx->id,
                    'matched_amount' => number_format($targetAbs, 4, '.', ''),
                    'match_type' => 'auto',
                ]);

                $usedTransactionIds[] = (int) $tx->id;
                $autoMatched++;

                break;
            }
        }

        // Phase 7 — then try the Treasury operations: a confirmed
        // entry on this bank account with the same absolute amount
        // and a posting date within tolerance. Only the approved
        // exact-match rule auto-matches; anything else stays for
        // manual confirmation.
        $matchedLineIds = BankReconciliationMatch::where('session_id', $session->id)
            ->pluck('statement_line_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $usedOperationIds = BankReconciliationMatch::where('session_id', $session->id)
            ->whereNotNull('treasury_operation_id')
            ->pluck('treasury_operation_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        // Cross-session guard (CA12): an operation matched in another
        // session must not be auto-matched again here. Snapshot the
        // matched totals before this run; within-run consumption is
        // tracked by $usedOperationIds below.
        $matchedByOperation = BankReconciliationMatch::whereNotNull('treasury_operation_id')
            ->selectRaw('treasury_operation_id, SUM(matched_amount) as total')
            ->groupBy('treasury_operation_id')
            ->pluck('total', 'treasury_operation_id')
            ->map(fn ($total) => (float) $total)
            ->all();

        $treasuryAccountIds = TreasuryFinancialAccount::where('bank_account_id', $bankAccountId)
            ->where('created_by', $creatorId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($treasuryAccountIds !== []) {
            $entries = TreasuryEntry::query()
                ->join('treasury_operations', 'treasury_operations.id', '=', 'treasury_entries.operation_id')
                ->where('treasury_operations.created_by', $creatorId)
                ->where('treasury_operations.state', TreasuryOperation::STATE_CONFIRMED)
                ->whereIn('treasury_entries.financial_account_id', $treasuryAccountIds)
                ->select('treasury_entries.*')
                ->orderBy('treasury_entries.confirmed_at')
                ->orderBy('treasury_entries.id')
                ->get();

            foreach ($lines as $line) {
                if (in_array((int) $line->id, $matchedLineIds, true)) {
                    continue;
                }

                $lineDate = Carbon::parse($line->txn_date);
                $targetAbs = abs((float) $line->amount);

                foreach ($entries as $entry) {
                    if (in_array((int) $entry->operation_id, $usedOperationIds, true)) {
                        continue;
                    }

                    // The auto rule matches the operation's remaining
                    // residual, never its full amount: an operation
                    // partly matched in another session (or manually
                    // here) must not be auto-matched for more than
                    // what is left (CA12).
                    $alreadyMatched = $matchedByOperation[(int) $entry->operation_id] ?? 0.0;
                    $entryResidual = abs((float) $entry->amount_original) - $alreadyMatched;
                    if ($entryResidual <= $eps) {
                        continue;
                    }
                    if (abs($entryResidual - $targetAbs) > $eps) {
                        continue;
                    }

                    $entryDate = $entry->confirmed_at ? Carbon::parse($entry->confirmed_at) : null;
                    if ($entryDate !== null && abs($entryDate->diffInDays($lineDate)) > $tol) {
                        continue;
                    }

                    BankReconciliationMatch::create([
                        'session_id' => $session->id,
                        'statement_line_id' => $line->id,
                        'transaction_id' => null,
                        'treasury_operation_id' => (int) $entry->operation_id,
                        'matched_amount' => number_format($targetAbs, 4, '.', ''),
                        'match_type' => 'auto',
                    ]);

                    $usedOperationIds[] = (int) $entry->operation_id;
                    $matchedLineIds[] = (int) $line->id;
                    $autoMatched++;

                    break;
                }
            }
        }

        return ['auto_matched' => $autoMatched];
    }
}
