<?php

namespace App\Services\Accounting\BankReconciliation;

use App\Models\BankReconciliationMatch;
use App\Models\BankReconciliationSession;
use App\Models\BankStatementLine;
use App\Models\Transaction;
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
                    'match_type' => 'auto',
                ]);

                $usedTransactionIds[] = (int) $tx->id;
                $autoMatched++;

                break;
            }
        }

        return ['auto_matched' => $autoMatched];
    }
}
