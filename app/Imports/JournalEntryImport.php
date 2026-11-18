<?php

namespace App\Imports;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalItem;
use App\Support\AccountingPeriodDate;
use App\Support\MoneyAmount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Journal Entry XLSX import.
 *
 * One row per journal line; rows sharing the same reference
 * become one journal entry. Each entry must balance (total
 * debit = total credit) and have at least two lines. Valid
 * entries are created PENDING, exactly like a manual journal
 * entry — they still need approval before they post.
 * Invalid groups are skipped whole and reported with their
 * row numbers; one bad group never blocks the rest.
 */
class JournalEntryImport implements ToCollection, WithHeadingRow
{
    use Importable;

    /** @var array{created: int, skipped: int, errors: array<int, string>} */
    public array $result = ['created' => 0, 'skipped' => 0, 'errors' => []];

    public function __construct(
        protected int $creatorId,
    ) {
    }

    public function collection(Collection $rows): void
    {
        $groups = [];
        $order = [];

        foreach ($rows as $index => $row) {
            // Heading row is row 1, so the first data row is row 2.
            $rowNumber = $index + 2;
            $values = $row instanceof Collection ? $row->toArray() : (array) $row;

            if ($this->isBlankRow($values)) {
                continue;
            }

            $reference = trim((string) ($values['reference'] ?? ''));
            if ($reference === '') {
                $this->addError($rowNumber, __('Reference is required.'));
                $this->result['skipped']++;

                continue;
            }

            if (! isset($groups[$reference])) {
                $groups[$reference] = [];
                $order[] = $reference;
            }

            $groups[$reference][] = ['row' => $rowNumber, 'values' => $values];
        }

        foreach ($order as $reference) {
            $this->importGroup($reference, $groups[$reference]);
        }
    }

    /** @param array<int, array{row: int, values: array}> $lines */
    protected function importGroup(string $reference, array $lines): void
    {
        $parsed = [];
        $failed = false;

        foreach ($lines as $line) {
            $rowNumber = $line['row'];
            $values = $line['values'];

            $date = $this->parseDate($values['date'] ?? null);
            if ($date === null) {
                $this->addError($rowNumber, __('Date is not valid.'));
                $failed = true;

                continue;
            }

            $accountCode = trim((string) ($values['account_code'] ?? ''));
            $account = $accountCode !== ''
                ? ChartOfAccount::where('code', $accountCode)
                    ->where('created_by', $this->creatorId)
                    ->first()
                : null;
            if ($account === null) {
                $this->addError($rowNumber, __('Account code :code was not found.', ['code' => $accountCode]));
                $failed = true;

                continue;
            }

            $debit = MoneyAmount::parse($values['debit'] ?? 0);
            $credit = MoneyAmount::parse($values['credit'] ?? 0);
            if (($debit > 0) === ($credit > 0)) {
                $this->addError($rowNumber, __('Enter either a debit or a credit amount.'));
                $failed = true;

                continue;
            }

            $parsed[] = [
                'row' => $rowNumber,
                'date' => $date,
                'account' => $account,
                'description' => trim((string) ($values['line_description'] ?? '')),
                'debit' => $debit,
                'credit' => $credit,
            ];
        }

        if ($failed) {
            $this->result['skipped'] += count($lines);

            return;
        }

        $dates = array_unique(array_column($parsed, 'date'));
        if (count($dates) > 1) {
            $this->addError($parsed[0]['row'], __('All lines in one journal entry must have the same date.'));
            $this->result['skipped'] += count($lines);

            return;
        }

        if (count($parsed) < 2) {
            $this->addError($parsed[0]['row'], __('Each journal entry needs at least two lines.'));
            $this->result['skipped'] += count($lines);

            return;
        }

        $totalDebit = array_sum(array_column($parsed, 'debit'));
        $totalCredit = array_sum(array_column($parsed, 'credit'));
        if (! MoneyAmount::equals($totalDebit, $totalCredit)) {
            $this->addError($parsed[0]['row'], __('Debit and Credit must be Equal.'));
            $this->result['skipped'] += count($lines);

            return;
        }

        $date = $parsed[0]['date'];
        $year = (int) date('Y', strtotime($date));
        $month = (int) date('n', strtotime($date));
        $day = (int) date('j', strtotime($date));

        $description = '';
        foreach ($lines as $line) {
            $description = trim((string) ($line['values']['description'] ?? ''));
            if ($description !== '') {
                break;
            }
        }

        $journal = new JournalEntry();
        $journal->journal_id = $this->nextJournalNumber();
        $journal->date = AccountingPeriodDate::composeDate($year, $month, $day);
        $journal->reference = $reference;
        $journal->description = $description;
        // Same guard as JournalEntryController::store: some workflow
        // tests build a minimal journal_entries schema without it.
        if (Schema::hasColumn('journal_entries', 'source')) {
            $journal->source = JournalEntry::SOURCE_MANUAL;
        }
        $journal->status = JournalEntry::STATUS_PENDING;
        $journal->created_by = $this->creatorId;
        $journal->accounting_period = $month;
        $journal->save();

        foreach ($parsed as $line) {
            $item = new JournalItem();
            $item->journal = $journal->id;
            $item->account = $line['account']->id;
            $item->description = $line['description'];
            $item->debit = $line['debit'];
            $item->credit = $line['credit'];
            $item->save();
        }

        $this->result['created']++;
    }

    protected function nextJournalNumber(): int
    {
        $latest = JournalEntry::where('created_by', $this->creatorId)->max('journal_id');

        return $latest === null ? 1 : ((int) $latest) + 1;
    }

    protected function isBlankRow(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    protected function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_numeric($value)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        $timestamp = strtotime(trim((string) $value));
        if ($timestamp === false) {
            return null;
        }

        $year = (int) date('Y', $timestamp);
        if ($year < 2000 || $year > 2100) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    protected function addError(int $rowNumber, string $message): void
    {
        $this->result['errors'][] = __('Row :row: :message', ['row' => $rowNumber, 'message' => $message]);
    }

    /** Template headings (the import expects these column names). */
    public static function headings(): array
    {
        return [
            'date',
            'reference',
            'description',
            'account_code',
            'debit',
            'credit',
            'line_description',
        ];
    }

    /** One example entry (two lines) for the downloadable template. */
    public static function exampleRows(): array
    {
        return [
            ['2026-11-18', 'JE-IMPORT-001', 'Example journal entry', '611', '500', '', 'Inventory cost'],
            ['2026-11-18', 'JE-IMPORT-001', 'Example journal entry', '111', '', '500', 'Cash payment'],
        ];
    }
}
