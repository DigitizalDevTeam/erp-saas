<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;

class FiscalYearClosingService
{
    public function __construct(
        private ChartAccountResolver $resolver,
        private JournalEntryPoster $poster
    ) {}

    /**
     * Net balance in "credit-positive" form: sum(credit) - sum(debit).
     * Prefers transaction_lines (Trial Balance source) so year-end clearing matches the screen.
     *
     * @param  bool  $excludeFiscalClosingJournals  If true, ignore FY-*-CLOSE* journals (any year/period).
     *                                              Prevents double-close and reverse-close when prior
     *                                              closing entries sit in the same date window.
     */
    public function netBalanceCreditPositive(
        int $accountId,
        string $fromDate,
        string $toDate,
        int $createdBy,
        bool $excludeFiscalClosingJournals = true
    ): float {
        $excludedJournalIds = [];
        if ($excludeFiscalClosingJournals) {
            $excludedJournalIds = DB::table('journal_entries')
                ->where('created_by', $createdBy)
                ->where('reference', 'like', 'FY-%-CLOSE%')
                ->pluck('id')
                ->map(static fn ($id) => (int) $id)
                ->all();
        }

        $tl = DB::table('transaction_lines')
            ->where('account_id', $accountId)
            ->where('created_by', $createdBy)
            ->whereBetween('date', [$fromDate, $toDate]);

        if ($excludedJournalIds !== []) {
            $tl->where(function ($w) use ($excludedJournalIds) {
                $w->where('reference', '!=', 'Journal')
                    ->orWhereNull('reference_id')
                    ->orWhereNotIn('reference_id', $excludedJournalIds);
            });
        }

        $tlRow = $tl->selectRaw(
            'COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) as net, COUNT(*) as cnt'
        )->first();

        // Prefer transaction_lines whenever any row exists (including a zero net).
        // Falling through to journal_items when net is 0 double-counts journals that already have TL.
        if ($tlRow && (int) $tlRow->cnt > 0) {
            return round((float) $tlRow->net, 2);
        }

        // Fallback when older journals never wrote transaction_lines.
        $q = DB::table('journal_items')
            ->join('journal_entries', 'journal_items.journal', '=', 'journal_entries.id')
            ->where('journal_items.account', $accountId)
            ->where('journal_entries.created_by', $createdBy)
            ->whereBetween('journal_entries.date', [$fromDate, $toDate]);

        if ($excludedJournalIds !== []) {
            $q->whereNotIn('journal_entries.id', $excludedJournalIds);
        }

        return round((float) ($q->selectRaw(
            'COALESCE(SUM(journal_items.credit),0) - COALESCE(SUM(journal_items.debit),0) as net'
        )->value('net') ?? 0), 2);
    }

    /**
     * @return array{success: bool, messages: array<int, string>, journals: array<int, int>}
     */
    public function run(int $createdBy, int $fiscalYear, string $closingDate, int $accountingPeriod, bool $skipIfReferenceExists = true): array
    {
        $messages = [];
        $journalIds = [];
        $fromDate = sprintf('%04d-01-01', $fiscalYear);
        $toDate = $closingDate;

        $cfg = config('fiscal_closing');
        $classes = $cfg['classes'];
        $codes = $cfg['accounts'];

        $id81 = $this->resolver->resolveByCodeOrPrefix($createdBy, $codes['operating_results']);
        $id82 = $this->resolver->resolveByCodeOrPrefix($createdBy, $codes['financial_results']);
        $id83 = $this->resolver->resolveByCodeOrPrefix($createdBy, $codes['current_results']);
        $id85 = $this->resolver->resolveByCodeOrPrefix($createdBy, $codes['current_tax']);
        $id88 = $this->resolver->resolveByCodeOrPrefix($createdBy, $codes['net_profits']);
        $id44101 = $this->resolver->resolveByCodeOrPrefix($createdBy, $codes['income_tax_payable']);

        if (! $id81 || ! $id82 || ! $id83) {
            return [
                'success' => false,
                'messages' => [__('Chart accounts for classes 81, 82 and 83 must exist (codes starting with configured prefixes in config/fiscal_closing.php).')],
                'journals' => [],
            ];
        }

        if ($skipIfReferenceExists) {
            $existingInWindow = JournalEntry::where('created_by', $createdBy)
                ->where('reference', 'like', 'FY-%-CLOSE%')
                ->whereBetween('date', [$fromDate, $toDate])
                ->orderBy('id')
                ->get(['id', 'reference']);

            if ($existingInWindow->isNotEmpty()) {
                $refs = $existingInWindow->pluck('reference')->unique()->values()->all();

                return [
                    'success' => false,
                    'messages' => [__('Closing journals already exist in :y (:refs). Delete those journal entries first, or enable "allow duplicate" to replace them.', [
                        'y' => $fiscalYear,
                        'refs' => implode(', ', $refs),
                    ])],
                    'journals' => [],
                ];
            }
        } else {
            $removed = $this->deleteClosingJournalsInDateRange($createdBy, $fromDate, $toDate);
            if ($removed > 0) {
                $messages[] = __('Removed :n existing closing journal(s) in the :y date range before re-posting.', [
                    'n' => $removed,
                    'y' => $fiscalYear,
                ]);
            }
        }

        $reserved = array_filter([$id81, $id82, $id83, $id85, $id88, $id44101]);

        $accounts = ChartOfAccount::where('created_by', $createdBy)->get();

        // --- Close operating expenses (61–68) to 81
        $linesOpExp = [];
        foreach ($accounts as $acc) {
            if (in_array($acc->id, $reserved, true)) {
                continue;
            }
            $cls = $this->resolver->twoDigitClass($acc->code);
            if ($cls === null || ! in_array($cls, $classes['operating_expense'], true)) {
                continue;
            }
            $net = $this->netBalanceCreditPositive((int) $acc->id, $fromDate, $toDate, $createdBy, true);
            if (abs($net) < 0.0001) {
                continue;
            }
            $adj = -$net;
            if ($adj > 0.0001) {
                $linesOpExp[] = [
                    'account' => (int) $acc->id,
                    'debit' => 0,
                    'credit' => $adj,
                    'description' => __('Year-end close — operating expense'),
                ];
                $linesOpExp[] = [
                    'account' => $id81,
                    'debit' => $adj,
                    'credit' => 0,
                    'description' => __('Year-end close — operating expense'),
                ];
            } elseif ($adj < -0.0001) {
                $linesOpExp[] = [
                    'account' => (int) $acc->id,
                    'debit' => -$adj,
                    'credit' => 0,
                    'description' => __('Year-end close — operating expense'),
                ];
                $linesOpExp[] = [
                    'account' => $id81,
                    'debit' => 0,
                    'credit' => -$adj,
                    'description' => __('Year-end close — operating expense'),
                ];
            }
        }
        $this->mergeSameAccountLines($linesOpExp);
        if (count($linesOpExp) > 0) {
            $j = $this->poster->post($createdBy, $closingDate, 'FY-'.$fiscalYear.'-CLOSE-OP-EXP', __('Fiscal year :y — close operating expenses', ['y' => $fiscalYear]), $linesOpExp, $accountingPeriod);
            $journalIds[] = (int) $j->id;
            $messages[] = __('Posted operating expense closing (journal #:num).', ['num' => $j->journal_id]);
        }

        // --- Close operating income (71–76) to 81
        $linesOpInc = [];
        foreach ($accounts as $acc) {
            if (in_array($acc->id, $reserved, true)) {
                continue;
            }
            $cls = $this->resolver->twoDigitClass($acc->code);
            if ($cls === null || ! in_array($cls, $classes['operating_income'], true)) {
                continue;
            }
            $net = $this->netBalanceCreditPositive((int) $acc->id, $fromDate, $toDate, $createdBy, true);
            if (abs($net) < 0.0001) {
                continue;
            }
            if ($net > 0.0001) {
                $linesOpInc[] = [
                    'account' => (int) $acc->id,
                    'debit' => $net,
                    'credit' => 0,
                    'description' => __('Year-end close — operating income'),
                ];
                $linesOpInc[] = [
                    'account' => $id81,
                    'debit' => 0,
                    'credit' => $net,
                    'description' => __('Year-end close — operating income'),
                ];
            } elseif ($net < -0.0001) {
                $linesOpInc[] = [
                    'account' => (int) $acc->id,
                    'debit' => 0,
                    'credit' => -$net,
                    'description' => __('Year-end close — operating income'),
                ];
                $linesOpInc[] = [
                    'account' => $id81,
                    'debit' => -$net,
                    'credit' => 0,
                    'description' => __('Year-end close — operating income'),
                ];
            }
        }
        $this->mergeSameAccountLines($linesOpInc);
        if (count($linesOpInc) > 0) {
            $j = $this->poster->post($createdBy, $closingDate, 'FY-'.$fiscalYear.'-CLOSE-OP-INC', __('Fiscal year :y — close operating income', ['y' => $fiscalYear]), $linesOpInc, $accountingPeriod);
            $journalIds[] = (int) $j->id;
            $messages[] = __('Posted operating income closing (journal #:num).', ['num' => $j->journal_id]);
        }

        // --- Financial expense 69 → 82
        $lines69 = [];
        foreach ($accounts as $acc) {
            if (in_array($acc->id, $reserved, true)) {
                continue;
            }
            $cls = $this->resolver->twoDigitClass($acc->code);
            if ($cls === null || ! in_array($cls, $classes['financial_expense'], true)) {
                continue;
            }
            $net = $this->netBalanceCreditPositive((int) $acc->id, $fromDate, $toDate, $createdBy, true);
            if (abs($net) < 0.0001) {
                continue;
            }
            $adj = -$net;
            if ($adj > 0.0001) {
                $lines69[] = [
                    'account' => (int) $acc->id,
                    'debit' => 0,
                    'credit' => $adj,
                    'description' => __('Year-end close — financial expense'),
                ];
                $lines69[] = [
                    'account' => $id82,
                    'debit' => $adj,
                    'credit' => 0,
                    'description' => __('Year-end close — financial expense'),
                ];
            } elseif ($adj < -0.0001) {
                $lines69[] = [
                    'account' => (int) $acc->id,
                    'debit' => -$adj,
                    'credit' => 0,
                    'description' => __('Year-end close — financial expense'),
                ];
                $lines69[] = [
                    'account' => $id82,
                    'debit' => 0,
                    'credit' => -$adj,
                    'description' => __('Year-end close — financial expense'),
                ];
            }
        }
        $this->mergeSameAccountLines($lines69);
        if (count($lines69) > 0) {
            $j = $this->poster->post($createdBy, $closingDate, 'FY-'.$fiscalYear.'-CLOSE-FIN-69', __('Fiscal year :y — close class 69', ['y' => $fiscalYear]), $lines69, $accountingPeriod);
            $journalIds[] = (int) $j->id;
            $messages[] = __('Posted financial expense closing (journal #:num).', ['num' => $j->journal_id]);
        }

        // --- Financial income 78 → 82
        $lines78 = [];
        foreach ($accounts as $acc) {
            if (in_array($acc->id, $reserved, true)) {
                continue;
            }
            $cls = $this->resolver->twoDigitClass($acc->code);
            if ($cls === null || ! in_array($cls, $classes['financial_income'], true)) {
                continue;
            }
            $net = $this->netBalanceCreditPositive((int) $acc->id, $fromDate, $toDate, $createdBy, true);
            if (abs($net) < 0.0001) {
                continue;
            }
            if ($net > 0.0001) {
                $lines78[] = [
                    'account' => (int) $acc->id,
                    'debit' => $net,
                    'credit' => 0,
                    'description' => __('Year-end close — financial income'),
                ];
                $lines78[] = [
                    'account' => $id82,
                    'debit' => 0,
                    'credit' => $net,
                    'description' => __('Year-end close — financial income'),
                ];
            } elseif ($net < -0.0001) {
                $lines78[] = [
                    'account' => (int) $acc->id,
                    'debit' => 0,
                    'credit' => -$net,
                    'description' => __('Year-end close — financial income'),
                ];
                $lines78[] = [
                    'account' => $id82,
                    'debit' => -$net,
                    'credit' => 0,
                    'description' => __('Year-end close — financial income'),
                ];
            }
        }
        $this->mergeSameAccountLines($lines78);
        if (count($lines78) > 0) {
            $j = $this->poster->post($createdBy, $closingDate, 'FY-'.$fiscalYear.'-CLOSE-FIN-78', __('Fiscal year :y — close class 78', ['y' => $fiscalYear]), $lines78, $accountingPeriod);
            $journalIds[] = (int) $j->id;
            $messages[] = __('Posted financial income closing (journal #:num).', ['num' => $j->journal_id]);
        }

        $bal81 = $this->netBalanceCreditPositive($id81, $fromDate, $toDate, $createdBy, false);
        $bal82 = $this->netBalanceCreditPositive($id82, $fromDate, $toDate, $createdBy, false);

        $lines83 = [];
        if (abs($bal81) >= 0.01) {
            if ($bal81 > 0) {
                $lines83[] = ['account' => $id81, 'debit' => $bal81, 'credit' => 0, 'description' => __('Transfer to current results')];
                $lines83[] = ['account' => $id83, 'debit' => 0, 'credit' => $bal81, 'description' => __('Transfer from operating results')];
            } else {
                $a = -$bal81;
                $lines83[] = ['account' => $id81, 'debit' => 0, 'credit' => $a, 'description' => __('Transfer to current results')];
                $lines83[] = ['account' => $id83, 'debit' => $a, 'credit' => 0, 'description' => __('Transfer from operating results')];
            }
        }
        if (abs($bal82) >= 0.01) {
            if ($bal82 > 0) {
                $lines83[] = ['account' => $id82, 'debit' => $bal82, 'credit' => 0, 'description' => __('Transfer to current results')];
                $lines83[] = ['account' => $id83, 'debit' => 0, 'credit' => $bal82, 'description' => __('Transfer from financial results')];
            } else {
                $a = -$bal82;
                $lines83[] = ['account' => $id82, 'debit' => 0, 'credit' => $a, 'description' => __('Transfer to current results')];
                $lines83[] = ['account' => $id83, 'debit' => $a, 'credit' => 0, 'description' => __('Transfer from financial results')];
            }
        }

        $this->mergeSameAccountLines($lines83);
        if (count($lines83) > 0) {
            $j = $this->poster->post($createdBy, $closingDate, 'FY-'.$fiscalYear.'-CLOSE-83', __('Fiscal year :y — transfer to account 83', ['y' => $fiscalYear]), $lines83, $accountingPeriod);
            $journalIds[] = (int) $j->id;
            $messages[] = __('Posted transfer to current results (journal #:num).', ['num' => $j->journal_id]);
        }

        $bal83 = $this->netBalanceCreditPositive($id83, $fromDate, $toDate, $createdBy, false);

        if (abs($bal83) < 0.01) {
            $messages[] = __('Account 83 has no balance; no tax or net profit/loss entry was posted.');

            return ['success' => true, 'messages' => $messages, 'journals' => $journalIds];
        }

        if ($bal83 < 0) {
            if (! $id88) {
                $messages[] = __('Loss detected but account 88 (net profits / retained) was not found in the chart.');

                return ['success' => false, 'messages' => $messages, 'journals' => $journalIds];
            }
            $loss = -$bal83;
            $j = $this->poster->post(
                $createdBy,
                $closingDate,
                'FY-'.$fiscalYear.'-CLOSE-LOSS',
                __('Fiscal year :y — net loss', ['y' => $fiscalYear]),
                [
                    ['account' => $id88, 'debit' => $loss, 'credit' => 0, 'description' => __('Net loss for the year')],
                    ['account' => $id83, 'debit' => 0, 'credit' => $loss, 'description' => __('Net loss for the year')],
                ],
                $accountingPeriod
            );
            $journalIds[] = (int) $j->id;
            $messages[] = __('Posted net loss to account 88 (journal #:num).', ['num' => $j->journal_id]);

            return ['success' => true, 'messages' => $messages, 'journals' => $journalIds];
        }

        if (! $id85 || ! $id44101 || ! $id88) {
            $messages[] = __('Profit detected: add chart accounts for 85, 44101 and 88 to record IRPC and net profit.');

            return ['success' => false, 'messages' => $messages, 'journals' => $journalIds];
        }

        $rate = (float) $cfg['irpc_rate'];
        $tax = round($bal83 * $rate, 2);
        $net = round($bal83 - $tax, 2);

        $taxLines = [
            ['account' => $id83, 'debit' => $tax, 'credit' => 0, 'description' => __('IRPC :pct%', ['pct' => round($rate * 100, 2)])],
            ['account' => $id85, 'debit' => 0, 'credit' => $tax, 'description' => __('Current tax provision')],
            ['account' => $id85, 'debit' => $tax, 'credit' => 0, 'description' => __('Settlement to payable')],
            ['account' => $id44101, 'debit' => 0, 'credit' => $tax, 'description' => __('Income tax payable')],
        ];
        $j = $this->poster->post($createdBy, $closingDate, 'FY-'.$fiscalYear.'-CLOSE-TAX', __('Fiscal year :y — income tax', ['y' => $fiscalYear]), $taxLines, $accountingPeriod);
        $journalIds[] = (int) $j->id;
        $messages[] = __('Posted IRPC and tax payable (journal #:num).', ['num' => $j->journal_id]);

        if (abs($net) >= 0.01) {
            $j = $this->poster->post(
                $createdBy,
                $closingDate,
                'FY-'.$fiscalYear.'-CLOSE-TAX-NET',
                __('Fiscal year :y — net profit', ['y' => $fiscalYear]),
                [
                    ['account' => $id83, 'debit' => $net, 'credit' => 0, 'description' => __('Net profit after tax')],
                    ['account' => $id88, 'debit' => 0, 'credit' => $net, 'description' => __('Net profit after tax')],
                ],
                $accountingPeriod
            );
            $journalIds[] = (int) $j->id;
            $messages[] = __('Posted net profit to account 88 (journal #:num).', ['num' => $j->journal_id]);
        }

        return ['success' => true, 'messages' => $messages, 'journals' => $journalIds];
    }

    /**
     * Delete FY-*-CLOSE* journals (items + transaction_lines) whose date falls in the window.
     */
    private function deleteClosingJournalsInDateRange(int $createdBy, string $fromDate, string $toDate): int
    {
        $journals = JournalEntry::where('created_by', $createdBy)
            ->where('reference', 'like', 'FY-%-CLOSE%')
            ->whereBetween('date', [$fromDate, $toDate])
            ->get();

        $count = 0;
        foreach ($journals as $journal) {
            DB::table('transaction_lines')
                ->where('reference', 'Journal')
                ->where('reference_id', $journal->id)
                ->delete();
            DB::table('journal_items')->where('journal', $journal->id)->delete();
            $journal->delete();
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<int, array{account: int, debit: float, credit: float, description?: string}>  $lines
     */
    private function mergeSameAccountLines(array &$lines): void
    {
        $map = [];
        foreach ($lines as $line) {
            $id = (int) $line['account'];
            if (! isset($map[$id])) {
                $map[$id] = ['debit' => 0.0, 'credit' => 0.0, 'description' => $line['description'] ?? ''];
            }
            $map[$id]['debit'] += (float) ($line['debit'] ?? 0);
            $map[$id]['credit'] += (float) ($line['credit'] ?? 0);
        }
        $lines = [];
        foreach ($map as $accountId => $v) {
            // Net to a single side so journal_items and transaction_lines stay in sync.
            $net = round($v['debit'] - $v['credit'], 2);
            if (abs($net) < 0.0001) {
                continue;
            }
            $lines[] = [
                'account' => $accountId,
                'debit' => $net > 0 ? $net : 0.0,
                'credit' => $net < 0 ? abs($net) : 0.0,
                'description' => $v['description'],
            ];
        }
    }
}
