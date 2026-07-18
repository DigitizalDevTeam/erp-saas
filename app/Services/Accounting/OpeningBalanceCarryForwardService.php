<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

class OpeningBalanceCarryForwardService
{
    public function __construct(
        private readonly JournalEntryPoster $poster,
    ) {
    }

    public function referenceKey(int $targetYear): string
    {
        $prefix = (string) config('fiscal_closing.opening_reference_prefix', 'OPEN');

        return sprintf('%s-%d-P0', $prefix, $targetYear);
    }

    /**
     * @return array{
     *     success: bool,
     *     target_year: int,
     *     source_year: int,
     *     opening_date: string,
     *     reference: string,
     *     already_posted: bool,
     *     lines: list<array<string, mixed>>,
     *     total_debit: float,
     *     total_credit: float,
     *     messages: list<string>
     * }
     */
    public function preview(int $createdBy, int $targetYear): array
    {
        $sourceYear = $targetYear - 1;
        $openingDate = sprintf('%04d-01-01', $targetYear);
        $reference = $this->referenceKey($targetYear);
        $alreadyPosted = JournalEntry::where('created_by', $createdBy)
            ->where('reference', $reference)
            ->exists();

        $lines = $this->buildOpeningLines($createdBy, $sourceYear);
        $totalDebit = round(array_sum(array_column($lines, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($lines, 'credit')), 2);

        $messages = [];
        if ($lines === []) {
            $messages[] = __('No closing balances found in classes 1–5 for source year :y.', ['y' => $sourceYear]);
        } elseif (abs($totalDebit - $totalCredit) > 0.02) {
            $messages[] = __('Preview is out of balance (debit :d, credit :c). Review prior-year closing before posting.', [
                'd' => number_format($totalDebit, 2),
                'c' => number_format($totalCredit, 2),
            ]);
        }

        return [
            'success' => $lines !== [] && abs($totalDebit - $totalCredit) <= 0.02,
            'target_year' => $targetYear,
            'source_year' => $sourceYear,
            'opening_date' => $openingDate,
            'reference' => $reference,
            'already_posted' => $alreadyPosted,
            'lines' => $lines,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'messages' => $messages,
        ];
    }

    /**
     * @return array{success: bool, messages: list<string>, journal_id: int|null}
     */
    public function post(int $createdBy, int $targetYear, bool $skipIfReferenceExists = true): array
    {
        $preview = $this->preview($createdBy, $targetYear);

        if ($preview['already_posted'] && $skipIfReferenceExists) {
            return [
                'success' => false,
                'messages' => [__('Opening balances for period 0 (:ref) already exist. Delete that journal first or allow duplicate posting.', [
                    'ref' => $preview['reference'],
                ])],
                'journal_id' => null,
            ];
        }

        if ($preview['lines'] === []) {
            return [
                'success' => false,
                'messages' => $preview['messages'] ?: [__('Nothing to post for period 0.')],
                'journal_id' => null,
            ];
        }

        if (! $preview['success']) {
            return [
                'success' => false,
                'messages' => $preview['messages'],
                'journal_id' => null,
            ];
        }

        $journalLines = [];
        foreach ($preview['lines'] as $line) {
            $journalLines[] = [
                'account' => (int) $line['account_id'],
                'debit' => (float) $line['debit'],
                'credit' => (float) $line['credit'],
                'description' => __('Opening balance :y from :source', [
                    'y' => $targetYear,
                    'source' => $preview['source_year'],
                ]),
            ];
        }

        $journal = $this->poster->post(
            $createdBy,
            $preview['opening_date'],
            $preview['reference'],
            __('Period 0 opening balances — carry forward from :y (classes 1–5).', ['y' => $preview['source_year']]),
            $journalLines,
            0
        );

        return [
            'success' => true,
            'messages' => [__('Posted period 0 opening balances for :y (:count accounts). Journal #:id.', [
                'y' => $targetYear,
                'count' => count($journalLines),
                'id' => $journal->journal_id,
            ])],
            'journal_id' => (int) $journal->id,
        ];
    }

    public static function isOpeningBalanceClass(?string $code): bool
    {
        $normalized = preg_replace('/\D/', '', (string) $code) ?? '';
        if ($normalized === '') {
            return false;
        }

        $firstDigit = (int) substr($normalized, 0, 1);
        $allowed = config('fiscal_closing.opening_balance_class_digits', [1, 2, 3, 4, 5]);

        return in_array($firstDigit, $allowed, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildOpeningLines(int $createdBy, int $sourceYear): array
    {
        $fromDate = sprintf('%04d-01-01', $sourceYear);
        $toDate = sprintf('%04d-12-31', $sourceYear);
        $lines = [];

        $accounts = ChartOfAccount::where('created_by', $createdBy)
            ->orderByRaw('CAST(code AS UNSIGNED)')
            ->get(['id', 'code', 'name']);

        foreach ($accounts as $account) {
            if (! self::isOpeningBalanceClass($account->code)) {
                continue;
            }

            $totals = $this->accountTotalsForRange((int) $account->id, $createdBy, $fromDate, $toDate);
            $debit = $totals['debit'];
            $credit = $totals['credit'];

            if ($debit <= 0 && $credit <= 0) {
                continue;
            }

            if (abs($debit - $credit) < 0.0001) {
                continue;
            }

            $lineDebit = 0.0;
            $lineCredit = 0.0;
            if ($debit > $credit) {
                $lineDebit = round($debit - $credit, 2);
            } else {
                $lineCredit = round($credit - $debit, 2);
            }

            if ($lineDebit <= 0 && $lineCredit <= 0) {
                continue;
            }

            $lines[] = [
                'account_id' => (int) $account->id,
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'debit' => $lineDebit,
                'credit' => $lineCredit,
                'source_debit' => $debit,
                'source_credit' => $credit,
            ];
        }

        return $lines;
    }

    /**
     * @return array{debit: float, credit: float}
     */
    private function accountTotalsForRange(int $accountId, int $createdBy, string $fromDate, string $toDate): array
    {
        $tl = DB::table('transaction_lines')
            ->where('account_id', $accountId)
            ->where('created_by', $createdBy)
            ->whereDate('date', '>=', $fromDate)
            ->whereDate('date', '<=', $toDate)
            ->selectRaw('COALESCE(SUM(debit),0) as debit, COALESCE(SUM(credit),0) as credit')
            ->first();

        $debit = round((float) ($tl->debit ?? 0), 2);
        $credit = round((float) ($tl->credit ?? 0), 2);

        if ($debit > 0 || $credit > 0) {
            return ['debit' => $debit, 'credit' => $credit];
        }

        $ji = DB::table('journal_items as ji')
            ->join('journal_entries as je', 'ji.journal', '=', 'je.id')
            ->where('ji.account', $accountId)
            ->where('je.created_by', $createdBy)
            ->whereDate('je.date', '>=', $fromDate)
            ->whereDate('je.date', '<=', $toDate)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('transaction_lines as tl')
                    ->whereColumn('tl.reference_id', 'je.id')
                    ->where('tl.reference', 'Journal');
            })
            ->selectRaw('COALESCE(SUM(ji.debit),0) as debit, COALESCE(SUM(ji.credit),0) as credit')
            ->first();

        return [
            'debit' => round((float) ($ji->debit ?? 0), 2),
            'credit' => round((float) ($ji->credit ?? 0), 2),
        ];
    }
}
