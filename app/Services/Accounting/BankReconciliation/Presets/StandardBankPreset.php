<?php

namespace App\Services\Accounting\BankReconciliation\Presets;

use App\Services\Accounting\BankReconciliation\Contracts\BankStatementPresetInterface;

/**
 * Typical Standard Bank-style exports: header row with Date, narrative, Debit, Credit, Balance.
 */
class StandardBankPreset implements BankStatementPresetInterface
{
    public function detect(array $rows): ?array
    {
        foreach ($rows as $i => $row) {
            $norm = [];
            foreach ($row as $col => $cell) {
                $norm[$col] = mb_strtolower(preg_replace('/\s+/', ' ', trim((string) ($cell ?? ''))));
            }

            $first = $norm[0] ?? '';
            if ($first !== 'date' && ! str_starts_with($first, 'date ')) {
                continue;
            }

            $byName = [];
            foreach ($norm as $col => $name) {
                if ($name === '') {
                    continue;
                }
                $byName[$name] = $col;
                if (str_contains($name, 'narrative') || $name === 'description' || str_contains($name, 'details')) {
                    $byName['_desc'] = $col;
                }
                if ($name === 'debit' || str_contains($name, 'debit amount')) {
                    $byName['_debit'] = $col;
                }
                if ($name === 'credit' || str_contains($name, 'credit amount')) {
                    $byName['_credit'] = $col;
                }
                if ($name === 'balance' || str_contains($name, 'running balance')) {
                    $byName['_balance'] = $col;
                }
                if ($name === 'amount' || str_contains($name, 'transaction amount')) {
                    $byName['_amount'] = $col;
                }
            }

            $dateCol = 0;
            $descCol = $byName['_desc'] ?? ($byName['narrative'] ?? ($byName['description'] ?? 1));

            if (isset($byName['_debit'], $byName['_credit'])) {
                return [
                    'header_row' => $i,
                    'data_start_row' => $i + 1,
                    'date_col' => $dateCol,
                    'description_col' => $descCol,
                    'amount_mode' => 'debit_credit',
                    'amount_col' => null,
                    'debit_col' => $byName['_debit'],
                    'credit_col' => $byName['_credit'],
                    'balance_col' => $byName['_balance'] ?? null,
                ];
            }

            if (isset($byName['_amount'])) {
                return [
                    'header_row' => $i,
                    'data_start_row' => $i + 1,
                    'date_col' => $dateCol,
                    'description_col' => $descCol,
                    'amount_mode' => 'signed_column',
                    'amount_col' => $byName['_amount'],
                    'debit_col' => null,
                    'credit_col' => null,
                    'balance_col' => $byName['_balance'] ?? null,
                ];
            }
        }

        return null;
    }
}
