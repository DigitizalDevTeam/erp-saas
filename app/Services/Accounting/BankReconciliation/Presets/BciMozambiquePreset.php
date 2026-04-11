<?php

namespace App\Services\Accounting\BankReconciliation\Presets;

use App\Services\Accounting\BankReconciliation\Contracts\BankStatementPresetInterface;

class BciMozambiquePreset implements BankStatementPresetInterface
{
    public function detect(array $rows): ?array
    {
        foreach ($rows as $i => $row) {
            $cells = array_map(fn ($c) => mb_strtolower(trim((string) ($c ?? ''))), $row);
            $haystack = implode('|', $cells);
            if (str_contains($haystack, 'data mov')
                && (str_contains($haystack, 'descri') || str_contains($haystack, 'descr'))
                && str_contains($haystack, 'valor')) {
                return [
                    'header_row' => $i,
                    'data_start_row' => $i + 1,
                    'date_col' => 0,
                    'description_col' => 4,
                    'amount_mode' => 'signed_column',
                    'amount_col' => 5,
                    'debit_col' => null,
                    'credit_col' => null,
                    'balance_col' => 6,
                ];
            }
        }

        return null;
    }
}
