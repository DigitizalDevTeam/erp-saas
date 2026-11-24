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
 * Uploading the same file again is safe: entries that are
 * already imported are skipped and counted separately (not
 * errors) — by journal number when one was given, otherwise by
 * matching reference, date, accounting period and lines.
 *
 * The downloadable template's example rows use the logged-in
 * company's own accounts (see exampleRows), so the file imports
 * cleanly as downloaded.
 *
 * Optional columns: `journal_number` (a whole number of the
 * user's own, e.g. from their old books; empty means the next
 * number is assigned automatically) and `year`, `month`, `day`
 * (used when `date` is empty, the same three fields as the
 * manual form — month 0–15, day valid for that month/year).
 * `date` wins when both it and year/month/day are given.
 */
class JournalEntryImport implements ToCollection, WithHeadingRow
{
    use Importable;

    /** @var array{created: int, already_imported: int, already_imported_entries: array<int, array{journal_number: int, date: string, reference: string, amount: float}>, skipped: int, errors: array<int, string>} */
    public array $result = ['created' => 0, 'already_imported' => 0, 'already_imported_entries' => [], 'skipped' => 0, 'errors' => []];

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

            $resolved = $this->resolveDate($values);
            if ($resolved === null) {
                $this->addError($rowNumber, __('Date is not valid.'));
                $failed = true;

                continue;
            }

            // Optional journal number: empty means auto-assign.
            $journalNumberRaw = trim((string) ($values['journal_number'] ?? ''));
            $journalNumber = null;
            if ($journalNumberRaw !== '') {
                $journalNumber = $this->parseJournalNumber($journalNumberRaw);
                if ($journalNumber === null) {
                    $this->addError($rowNumber, __('Journal number :number is not valid.', ['number' => $journalNumberRaw]));
                    $failed = true;

                    continue;
                }
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
                'date' => $resolved['date'],
                'period' => $resolved['period'],
                'journal_number' => $journalNumber,
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

        $periods = array_unique(array_column($parsed, 'period'));
        if (count($periods) > 1) {
            $this->addError($parsed[0]['row'], __('All lines in one journal entry must have the same accounting period.'));
            $this->result['skipped'] += count($lines);

            return;
        }

        $journalNumbers = array_values(array_unique(array_filter(
            array_column($parsed, 'journal_number'),
            fn ($number) => $number !== null
        )));
        if (count($journalNumbers) > 1) {
            $this->addError($parsed[0]['row'], __('All lines in one journal entry must have the same journal number.'));
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
        $period = (int) $parsed[0]['period'];

        // A supplied journal number that already exists for this
        // company means the entry was already imported — skip it
        // quietly (it is NOT an error) and count it separately, so
        // the same file can be uploaded again after fixing the
        // rows that failed, without duplicating the rows that
        // already went in. Groups import one by one, so a number
        // used by an earlier group in the same file is caught too.
        $journalNumber = $journalNumbers[0] ?? null;
        if ($journalNumber !== null && JournalEntry::where('created_by', $this->creatorId)->where('journal_id', $journalNumber)->exists()) {
            $this->result['already_imported']++;
            $this->recordDuplicate($journalNumber, $date, $reference, (float) $totalCredit);

            return;
        }

        // Without a journal number there is nothing to match on, so
        // the same file uploaded again would create a second
        // identical entry. Skip the group when an identical entry
        // (same reference, date, accounting period and lines)
        // already exists for this company.
        $identical = $journalNumber === null ? $this->findIdenticalEntry($reference, $date, $period, $parsed) : null;
        if ($identical !== null) {
            $this->result['already_imported']++;
            $this->recordDuplicate((int) $identical->journal_id, $date, $reference, (float) $totalCredit);

            return;
        }

        $description = '';
        foreach ($lines as $line) {
            $description = trim((string) ($line['values']['description'] ?? ''));
            if ($description !== '') {
                break;
            }
        }

        $journal = new JournalEntry();
        $journal->journal_id = $journalNumber ?? $this->nextJournalNumber();
        $journal->date = $date;
        $journal->reference = $reference;
        $journal->description = $description;
        // Same guard as JournalEntryController::store: some workflow
        // tests build a minimal journal_entries schema without it.
        if (Schema::hasColumn('journal_entries', 'source')) {
            $journal->source = JournalEntry::SOURCE_MANUAL;
        }
        $journal->status = JournalEntry::STATUS_PENDING;
        $journal->created_by = $this->creatorId;
        $journal->accounting_period = $period;
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

    /**
     * Remember one duplicate group for the results report: the
     * journal number, date, reference and amount the user needs
     * to recognise which entry already exists. Values come from
     * the uploaded file — for an identical match they are the
     * same as the stored entry's.
     */
    protected function recordDuplicate(int $journalNumber, string $date, string $reference, float $amount): void
    {
        $this->result['already_imported_entries'][] = [
            'journal_number' => $journalNumber,
            'date' => $date,
            'reference' => $reference,
            'amount' => $amount,
        ];
    }

    /**
     * Find the identical entry already stored for this company,
     * or null. Identity is reference + date + accounting period
     * + the lines (account and amounts) — descriptions are
     * labels, not identity, so fixing a typo in one does not
     * re-import the entry. Any status counts: an approved entry
     * is still the same entry.
     */
    protected function findIdenticalEntry(string $reference, string $date, int $period, array $parsed): ?JournalEntry
    {
        $signature = $this->linesSignature(array_map(
            fn (array $line) => [(int) $line['account']->id, $line['debit'], $line['credit']],
            $parsed
        ));

        $candidates = JournalEntry::where('created_by', $this->creatorId)
            ->where('reference', $reference)
            ->where('date', $date)
            ->where('accounting_period', $period)
            ->get();

        foreach ($candidates as $candidate) {
            $items = JournalItem::where('journal', $candidate->id)->get();
            $itemsSignature = $this->linesSignature(array_map(
                fn (JournalItem $item) => [(int) $item->account, $item->debit, $item->credit],
                $items->all()
            ));
            if ($itemsSignature === $signature) {
                return $candidate;
            }
        }

        return null;
    }

    /** Sorted "account|debit|credit" signature of a set of lines. */
    protected function linesSignature(array $lines): string
    {
        $parts = [];
        foreach ($lines as [$accountId, $debit, $credit]) {
            $parts[] = $accountId.'|'.number_format((float) $debit, 2, '.', '').'|'.number_format((float) $credit, 2, '.', '');
        }
        sort($parts);

        return implode(';', $parts);
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

    /**
     * Resolve one line's date: the `date` column wins when it is
     * filled; otherwise year/month/day are used, exactly like the
     * manual form (month 0–15, day valid for that month/year).
     * Returns the stored date plus the accounting period, or null
     * when neither source gives a valid date.
     *
     * @return array{date: string, period: int}|null
     */
    protected function resolveDate(array $values): ?array
    {
        $rawDate = $values['date'] ?? null;
        if (is_string($rawDate)) {
            $rawDate = trim($rawDate);
        }
        if ($rawDate !== null && $rawDate !== '') {
            $date = $this->parseDate($rawDate);
            if ($date === null) {
                return null;
            }

            return ['date' => $date, 'period' => (int) date('n', strtotime($date))];
        }

        $year = $this->parseIntCell($values['year'] ?? null);
        $month = $this->parseIntCell($values['month'] ?? null);
        $day = $this->parseIntCell($values['day'] ?? null);
        if ($year === null || $month === null || $day === null) {
            return null;
        }
        if ($year < 2000 || $year > 2100 || $month < 0 || $month > 15) {
            return null;
        }
        if ($day < 1 || $day > AccountingPeriodDate::daysInPeriod($year, $month)) {
            return null;
        }

        return [
            'date' => AccountingPeriodDate::composeDate($year, $month, $day),
            'period' => $month,
        ];
    }

    /** A whole number from a cell, or null when it is empty or not a number. */
    protected function parseIntCell(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $text = trim((string) $value);
        if (! preg_match('/^\d+$/', $text)) {
            return null;
        }

        return (int) $text;
    }

    /**
     * The journal number for one line: a plain whole number, or the
     * number in the formatted form the list shows (e.g. JE-00001).
     * Returns null when the value is not a usable number.
     */
    protected function parseJournalNumber(string $value): ?int
    {
        if (preg_match('/^\d+$/', $value, $matches)) {
            $number = (int) $matches[0];
        } elseif (preg_match('/^[^0-9]*0*(\d+)$/', $value, $matches)) {
            $number = (int) $matches[1];
        } else {
            return null;
        }

        return $number > 0 ? $number : null;
    }

    protected function addError(int $rowNumber, string $message): void
    {
        $this->result['errors'][] = __('Row :row: :message', ['row' => $rowNumber, 'message' => $message]);
    }

    /** Template headings (the import expects these column names). */
    public static function headings(): array
    {
        return [
            'journal_number',
            'date',
            'year',
            'month',
            'day',
            'reference',
            'description',
            'account_code',
            'debit',
            'credit',
            'line_description',
        ];
    }

    /**
     * One example entry (two lines) for the downloadable template.
     * When the company is known, the example uses that company's
     * own accounts — a cost account on the debit line and a cash
     * account on the credit line — so the downloaded file imports
     * cleanly as-is, whichever company is logged in. Otherwise
     * (or when the company has no matching accounts) it falls
     * back to the generic 611 / 111 example.
     */
    public static function exampleRows(?int $creatorId = null): array
    {
        $debitCode = '611';
        $creditCode = '111';
        if ($creatorId !== null) {
            $debitCode = self::exampleAccountCode($creatorId, ['611', '61', '6']) ?? $debitCode;
            $creditCode = self::exampleAccountCode($creatorId, ['11', '12']) ?? $creditCode;
        }

        return [
            ['', '2026-11-18', '2026', '11', '18', 'JE-IMPORT-001', 'Example journal entry', $debitCode, '500', '', 'Inventory cost'],
            ['', '2026-11-18', '2026', '11', '18', 'JE-IMPORT-001', 'Example journal entry', $creditCode, '', '500', 'Cash payment'],
        ];
    }

    /**
     * First account of this company whose code starts with one of
     * the given prefixes (in order), or null when there is none.
     * Codes are integers, ordered numerically, so the smallest
     * matching code wins (e.g. 11 Cash before 1110001).
     */
    protected static function exampleAccountCode(int $creatorId, array $prefixes): ?string
    {
        foreach ($prefixes as $prefix) {
            $account = ChartOfAccount::where('created_by', $creatorId)
                ->where('code', 'like', $prefix.'%')
                ->orderBy('code')
                ->first();
            if ($account !== null) {
                return (string) $account->code;
            }
        }

        return null;
    }
}
