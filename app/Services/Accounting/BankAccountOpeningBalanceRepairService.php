<?php

namespace App\Services\Accounting;

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\TransactionLines;
use Illuminate\Support\Facades\DB;

/**
 * Repairs historical one-sided "Bank Account" opening postings that left
 * the ledger (and therefore the balance sheet) out of balance.
 */
class BankAccountOpeningBalanceRepairService
{
    public const BANK_REFERENCE = 'Bank Account';

    public const EQUITY_REFERENCE = 'Bank Account Opening Equity';

    public function __construct(private DocumentLedgerService $ledger) {}

    /**
     * @return array{repaired: int, skipped: int, warnings: list<string>}
     */
    public function repair(?int $companyId = null): array
    {
        $repaired = 0;
        $skipped = 0;
        $warnings = [];

        $query = TransactionLines::query()
            ->select('created_by', 'reference_id')
            ->where('reference', self::BANK_REFERENCE)
            ->groupBy('created_by', 'reference_id');

        if ($companyId !== null) {
            $query->where('created_by', $companyId);
        }

        $groups = $query->get();

        foreach ($groups as $group) {
            $createdBy = (int) $group->created_by;
            $bankAccountId = (int) $group->reference_id;

            $hasEquity = TransactionLines::query()
                ->where('created_by', $createdBy)
                ->where('reference', self::EQUITY_REFERENCE)
                ->where('reference_id', $bankAccountId)
                ->exists();

            if ($hasEquity) {
                $skipped++;

                continue;
            }

            $bankLines = TransactionLines::query()
                ->where('created_by', $createdBy)
                ->where('reference', self::BANK_REFERENCE)
                ->where('reference_id', $bankAccountId)
                ->get();

            $debit = round((float) $bankLines->sum('debit'), 2);
            $credit = round((float) $bankLines->sum('credit'), 2);
            $netCredit = round($credit - $debit, 2);

            if (abs($netCredit) < 0.01 && abs($debit - $credit) < 0.01) {
                $skipped++;

                continue;
            }

            $equityAccountId = $this->ledger->openingBalanceEquityId($createdBy);
            if (! $equityAccountId) {
                $warnings[] = "Company {$createdBy}, bank account {$bankAccountId}: no equity account (59/51) found — skipped.";
                $skipped++;

                continue;
            }

            $date = (string) ($bankLines->sortBy('date')->first()->date ?? date('Y-m-d'));
            $bank = BankAccount::find($bankAccountId);
            $chartId = $bank ? (int) $bank->chart_account_id : (int) ($bankLines->first()->account_id ?? 0);

            if ($chartId > 0) {
                $coa = ChartOfAccount::with('types')->find($chartId);
                $typeName = $coa?->types?->name ?? '';
                if ($typeName !== '' && ! in_array(strtolower($typeName), ['assets', 'asset'], true)) {
                    $warnings[] = "Company {$createdBy}, bank account {$bankAccountId}: chart account {$coa->code} is type \"{$typeName}\" (expected Assets). Counterpart posted; remap CoA to cash/bank (11/12).";
                }
            }

            DB::transaction(function () use ($createdBy, $bankAccountId, $netCredit, $equityAccountId, $date) {
                // Lone credit → debit equity; lone debit → credit equity.
                if ($netCredit > 0) {
                    $this->ledger->writeLine(
                        $createdBy,
                        $equityAccountId,
                        'Debit',
                        $netCredit,
                        self::EQUITY_REFERENCE,
                        $bankAccountId,
                        0,
                        $date
                    );
                } else {
                    $this->ledger->writeLine(
                        $createdBy,
                        $equityAccountId,
                        'Credit',
                        abs($netCredit),
                        self::EQUITY_REFERENCE,
                        $bankAccountId,
                        0,
                        $date
                    );
                }
            });

            $repaired++;
        }

        return [
            'repaired' => $repaired,
            'skipped' => $skipped,
            'warnings' => $warnings,
        ];
    }
}
