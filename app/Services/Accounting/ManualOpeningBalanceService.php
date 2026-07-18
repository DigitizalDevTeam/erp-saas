<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;

class ManualOpeningBalanceService
{
    public function __construct(
        private readonly JournalEntryPoster $poster,
    ) {
    }

    public function referenceKey(int $targetYear): string
    {
        $prefix = (string) config('fiscal_closing.manual_opening_reference_prefix', 'OPEN-MAN');

        return sprintf('%s-%d-P0', $prefix, $targetYear);
    }

    /**
     * @param  list<array{account_id?: mixed, debit?: mixed, credit?: mixed}>  $inputLines
     * @return array{
     *     success: bool,
     *     target_year: int,
     *     opening_date: string,
     *     reference: string,
     *     already_posted: bool,
     *     lines: list<array<string, mixed>>,
     *     total_debit: float,
     *     total_credit: float,
     *     messages: list<string>
     * }
     */
    public function preview(int $createdBy, int $targetYear, array $inputLines): array
    {
        $openingDate = sprintf('%04d-01-01', $targetYear);
        $reference = $this->referenceKey($targetYear);
        $alreadyPosted = JournalEntry::where('created_by', $createdBy)
            ->where('reference', $reference)
            ->exists();

        $lines = $this->normalizeLines($createdBy, $inputLines);
        $totalDebit = round(array_sum(array_column($lines, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($lines, 'credit')), 2);

        $messages = [];
        if ($lines === []) {
            $messages[] = __('Enter at least one opening balance line for a class 1–5 account.');
        } elseif (abs($totalDebit - $totalCredit) > 0.02) {
            $messages[] = __('Manual opening balances are out of balance (debit :d, credit :c).', [
                'd' => number_format($totalDebit, 2),
                'c' => number_format($totalCredit, 2),
            ]);
        }

        return [
            'success' => $lines !== [] && abs($totalDebit - $totalCredit) <= 0.02,
            'target_year' => $targetYear,
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
     * @param  list<array{account_id?: mixed, debit?: mixed, credit?: mixed}>  $inputLines
     * @return array{success: bool, messages: list<string>, journal_id: int|null}
     */
    public function post(int $createdBy, int $targetYear, array $inputLines, bool $skipIfReferenceExists = true): array
    {
        $preview = $this->preview($createdBy, $targetYear, $inputLines);

        if ($preview['already_posted'] && $skipIfReferenceExists) {
            return [
                'success' => false,
                'messages' => [__('Manual opening balances for period 0 (:ref) already exist. Delete that journal first or allow duplicate posting.', [
                    'ref' => $preview['reference'],
                ])],
                'journal_id' => null,
            ];
        }

        if (! $preview['success']) {
            return [
                'success' => false,
                'messages' => $preview['messages'] ?: [__('Nothing to post for manual period 0 opening balances.')],
                'journal_id' => null,
            ];
        }

        $journalLines = [];
        foreach ($preview['lines'] as $line) {
            $journalLines[] = [
                'account' => (int) $line['account_id'],
                'debit' => (float) $line['debit'],
                'credit' => (float) $line['credit'],
                'description' => __('Manual opening balance :y', ['y' => $targetYear]),
            ];
        }

        $journal = $this->poster->post(
            $createdBy,
            $preview['opening_date'],
            $preview['reference'],
            __('Period 0 opening balances — manual entry (classes 1–5).'),
            $journalLines,
            0
        );

        return [
            'success' => true,
            'messages' => [__('Posted manual period 0 opening balances for :y (:count accounts). Journal #:id.', [
                'y' => $targetYear,
                'count' => count($journalLines),
                'id' => $journal->journal_id,
            ])],
            'journal_id' => (int) $journal->id,
        ];
    }

    /**
     * @param  list<array{account_id?: mixed, debit?: mixed, credit?: mixed}>  $inputLines
     * @return list<array<string, mixed>>
     */
    private function normalizeLines(int $createdBy, array $inputLines): array
    {
        $accountIds = [];
        foreach ($inputLines as $inputLine) {
            $accountId = (int) ($inputLine['account_id'] ?? 0);
            if ($accountId > 0) {
                $accountIds[] = $accountId;
            }
        }

        $accounts = ChartOfAccount::query()
            ->where('created_by', $createdBy)
            ->whereIn('id', array_values(array_unique($accountIds)))
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        $lines = [];
        $seenAccounts = [];

        foreach ($inputLines as $inputLine) {
            $accountId = (int) ($inputLine['account_id'] ?? 0);
            $debit = round(max(0, (float) ($inputLine['debit'] ?? 0)), 2);
            $credit = round(max(0, (float) ($inputLine['credit'] ?? 0)), 2);

            if ($accountId <= 0 || ($debit <= 0 && $credit <= 0)) {
                continue;
            }

            if ($debit > 0 && $credit > 0) {
                continue;
            }

            $account = $accounts->get($accountId);
            if ($account === null || ! OpeningBalanceCarryForwardService::isOpeningBalanceClass($account->code)) {
                continue;
            }

            if (isset($seenAccounts[$accountId])) {
                continue;
            }
            $seenAccounts[$accountId] = true;

            $lines[] = [
                'account_id' => $accountId,
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'debit' => $debit,
                'credit' => $credit,
            ];
        }

        usort($lines, static fn (array $a, array $b) => (int) $a['code'] <=> (int) $b['code']);

        return $lines;
    }
}
