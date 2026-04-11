<?php

namespace App\Services\Accounting\BankReconciliation;

use Maatwebsite\Excel\Facades\Excel;

class SpreadsheetLoader
{
    /**
     * @return array<int, array<int, mixed>>
     */
    public function loadRows(string $absolutePath): array
    {
        $collection = Excel::toCollection(null, $absolutePath);
        if ($collection->isEmpty()) {
            return [];
        }

        $sheet = $collection->first();

        return $sheet->map(fn ($row) => $row->toArray())->values()->all();
    }
}
