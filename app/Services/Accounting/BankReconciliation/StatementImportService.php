<?php

namespace App\Services\Accounting\BankReconciliation;

use App\Models\BankReconciliationSession;
use App\Models\BankStatementLine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StatementImportService
{
    public function __construct(
        protected SpreadsheetLoader $loader,
        protected AmountNormalizer $amountNormalizer
    ) {}

    /**
     * Try to auto-detect columns using a registered preset detector (optional helper for the mapping UI).
     *
     * @param  array<int, array<int, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    public function suggestMappingForRows(array $rows, string $presetKey): ?array
    {
        $class = config('bank_reconciliation.presets.'.$presetKey.'.detector');
        if (! $class || ! class_exists($class)) {
            return null;
        }

        $preset = app($class);

        return $preset->detect($rows);
    }

    /**
     * Import using explicit column mapping (any bank / any layout).
     *
     * @param  array<string, mixed>  $mapping  Normalized mapping (see normalizeCustomMapping)
     * @return array{mapping: array<string, mixed>, imported: int, skipped: int}
     */
    public function importFromColumnMapping(
        BankReconciliationSession $session,
        string $absolutePath,
        array $mapping
    ): array {
        $rows = $this->loadRows($absolutePath);
        if ($rows === []) {
            throw new \InvalidArgumentException(__('The uploaded file has no rows.'));
        }

        $mapping = $this->normalizeCustomMapping($mapping);

        DB::transaction(function () use ($session) {
            BankStatementLine::where('session_id', $session->id)->delete();
        });

        $imported = 0;
        $skipped = 0;
        $minDate = null;
        $maxDate = null;

        $start = (int) $mapping['data_start_row'];
        for ($i = $start; $i < count($rows); $i++) {
            $row = $rows[$i];
            $parsed = $this->parseRow($row, $mapping);
            if ($parsed['empty']) {
                $skipped++;

                continue;
            }

            if ($parsed['txn_date']) {
                $d = $parsed['txn_date'];
                $minDate = $minDate === null || $d->lt($minDate) ? $d : $minDate;
                $maxDate = $maxDate === null || $d->gt($maxDate) ? $d : $maxDate;
            }

            BankStatementLine::create([
                'session_id' => $session->id,
                'row_index' => $i,
                'txn_date' => $parsed['txn_date']?->format('Y-m-d'),
                'description' => $parsed['description'],
                'amount' => $parsed['amount'],
                'balance' => $parsed['balance'],
                'parse_error' => $parsed['error'],
                'raw_row' => $row,
            ]);
            $imported++;
        }

        $session->column_mapping = $mapping;
        $session->period_from = $minDate?->format('Y-m-d');
        $session->period_to = $maxDate?->format('Y-m-d');
        $session->save();

        return ['mapping' => $mapping, 'imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function loadRows(string $absolutePath): array
    {
        return $this->loader->loadRows($absolutePath);
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @return array<string, mixed>
     */
    public function normalizeCustomMapping(array $mapping): array
    {
        $dataStart = (int) ($mapping['data_start_row'] ?? 0);
        $mode = $mapping['amount_mode'] ?? 'signed_column';

        $base = [
            'header_row' => isset($mapping['header_row']) ? (int) $mapping['header_row'] : max(0, $dataStart - 1),
            'data_start_row' => $dataStart,
            'date_col' => (int) $mapping['date_col'],
            'description_col' => (int) $mapping['description_col'],
            'amount_mode' => $mode,
            'amount_col' => isset($mapping['amount_col']) ? (int) $mapping['amount_col'] : null,
            'debit_col' => isset($mapping['debit_col']) ? (int) $mapping['debit_col'] : null,
            'credit_col' => isset($mapping['credit_col']) ? (int) $mapping['credit_col'] : null,
            'balance_col' => isset($mapping['balance_col']) && $mapping['balance_col'] !== '' && $mapping['balance_col'] !== null ? (int) $mapping['balance_col'] : null,
        ];

        if ($base['amount_mode'] === 'debit_credit') {
            if ($base['debit_col'] === null || $base['credit_col'] === null) {
                throw new \InvalidArgumentException(__('Debit and credit columns are required for debit/credit mode.'));
            }
        } elseif ($base['amount_col'] === null) {
            throw new \InvalidArgumentException(__('Amount column is required for signed amount mode.'));
        }

        return $base;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, mixed>  $map
     * @return array{empty: bool, txn_date: ?Carbon, description: ?string, amount: ?float, balance: ?float, error: ?string}
     */
    protected function parseRow(array $row, array $map): array
    {
        $cell = function (int $idx) use ($row) {
            return $row[$idx] ?? null;
        };

        $descRaw = $cell((int) $map['description_col']);
        $description = $descRaw !== null ? trim((string) $descRaw) : '';

        $dateVal = $cell((int) $map['date_col']);
        $txnDate = $this->parseDate($dateVal);

        $amount = null;
        $balance = null;

        if (($map['amount_mode'] ?? '') === 'debit_credit') {
            $debit = $this->amountNormalizer->parse($cell((int) $map['debit_col']) !== null ? (string) $cell((int) $map['debit_col']) : null) ?? 0.0;
            $credit = $this->amountNormalizer->parse($cell((int) $map['credit_col']) !== null ? (string) $cell((int) $map['credit_col']) : null) ?? 0.0;
            $amount = round($credit - $debit, 4);
        } else {
            $amount = $this->amountNormalizer->parse($cell((int) $map['amount_col']) !== null ? (string) $cell((int) $map['amount_col']) : null);
        }

        if (isset($map['balance_col']) && $map['balance_col'] !== null && $map['balance_col'] !== '') {
            $balance = $this->amountNormalizer->parse($cell((int) $map['balance_col']) !== null ? (string) $cell((int) $map['balance_col']) : null);
        }

        $empty = $description === '' && $txnDate === null && ($amount === null || abs($amount) < 0.00001);

        $error = null;
        if (! $empty && $txnDate === null) {
            $error = __('Unrecognized date format');
        }
        if (! $empty && $amount === null) {
            $error = ($error ? $error.'; ' : '').__('Could not parse amount');
        }

        return [
            'empty' => $empty,
            'txn_date' => $txnDate,
            'description' => $description !== '' ? $description : null,
            'amount' => $amount,
            'balance' => $balance,
            'error' => $error,
        ];
    }

    protected function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value))->startOfDay();
        }

        if (is_numeric($value)) {
            $n = (float) $value;
            if ($n > 30000 && $n < 60000 && class_exists(\PhpOffice\PhpSpreadsheet\Shared\Date::class)) {
                try {
                    $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($n);

                    return Carbon::instance($dt)->startOfDay();
                } catch (\Throwable) {
                    // fall through
                }
            }
        }

        $s = trim((string) $value);
        $formats = ['d-m-Y', 'd/m/Y', 'Y-m-d', 'm/d/Y', 'd.m.Y', 'Y/m/d'];

        foreach ($formats as $fmt) {
            try {
                $d = Carbon::createFromFormat($fmt, $s);
                if ($d !== false) {
                    return $d->startOfDay();
                }
            } catch (\Throwable) {
                continue;
            }
        }

        try {
            return Carbon::parse($s)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
