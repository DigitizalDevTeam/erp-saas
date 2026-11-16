<?php

namespace App\Services;

use App\Models\ManagementCostCentre;
use App\Models\ManagementDocument;
use App\Models\ManagementRule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Import Management Documents from a CSV or .xlsx file with the
 * Documentos columns (the client's file: date, supplier NIF, supplier
 * name, doc number, type, base, IVA, retention, total, status, bank
 * account, payment method, category code, cost centre code, notes).
 *
 *  - Category is matched by CODE (e.g. 6241), cost centre by CODE
 *    (e.g. 101). A row with an unknown code is skipped and listed.
 *  - Every row is validated with the same rules as the form
 *    (ManagementDocumentValidator): total = base + VAT − retention,
 *    credit note negative, doc number required for all types,
 *    duplicates skipped.
 *  - Numbers accept both 5.57 and 5,57 (European decimals); dates
 *    accept YYYY-MM-DD, DD/MM/YYYY and Excel serial dates.
 *
 * Returns ['imported' => int, 'skipped' => [['row' => int, 'reason' => string], ...]].
 */
class ManagementDocumentImport
{
    /** Header synonyms (normalised: lowercase, spaces -> underscores). */
    private const HEADER_MAP = [
        'date' => 'doc_date',
        'doc_date' => 'doc_date',
        'supplier_nif' => 'supplier_nif',
        'nif' => 'supplier_nif',
        'supplier_name' => 'supplier_name',
        'supplier' => 'supplier_name',
        'doc_number' => 'doc_number',
        'document_number' => 'doc_number',
        'document_no' => 'doc_number',
        'type' => 'doc_type',
        'doc_type' => 'doc_type',
        'base' => 'base',
        'iva' => 'iva',
        'vat' => 'iva',
        'retention' => 'retention',
        'total' => 'total',
        'status' => 'status',
        'bank_account' => 'bank_account',
        'bank' => 'bank_account',
        'account' => 'bank_account',
        'payment_method' => 'payment_method',
        'payment' => 'payment_method',
        'category' => 'category_code',
        'category_code' => 'category_code',
        'cost_centre' => 'cost_centre_code',
        'cost_center' => 'cost_centre_code',
        'cc' => 'cost_centre_code',
        'cost_centre_code' => 'cost_centre_code',
        'cost_center_code' => 'cost_centre_code',
        'notes' => 'notes',
        'note' => 'notes',
    ];

    /** @return array{imported: int, skipped: list<array{row: int, reason: string}>} */
    public static function import(int $creatorId, string $path, string $extension): array
    {
        $rows = strtolower($extension) === 'xlsx'
            ? self::readXlsx($path)
            : self::readCsv($path);

        if ($rows === []) {
            return ['imported' => 0, 'skipped' => [['row' => 0, 'reason' => __('The file has no rows.')]]];
        }

        $header = array_shift($rows);
        $map = self::mapHeader($header);
        if (! in_array('doc_date', $map, true) || ! in_array('total', $map, true)) {
            return ['imported' => 0, 'skipped' => [['row' => 1, 'reason' => __('The file must have at least a date column and a total column.')]]];
        }

        $rules = ManagementRule::where('created_by', $creatorId)->pluck('id', 'code');
        $centres = ManagementCostCentre::where('created_by', $creatorId)->pluck('id', 'code');

        $imported = 0;
        $skipped = [];
        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // header is row 1
            if (self::isEmptyRow($row)) {
                continue;
            }
            $input = self::mapRow($row, $map);

            // Category / cost centre by code.
            if (! empty($input['category_code'])) {
                $code = trim((string) $input['category_code']);
                if (! isset($rules[$code])) {
                    $skipped[] = ['row' => $rowNumber, 'reason' => __('Unknown category code: :code', ['code' => $code])];
                    continue;
                }
                $input['management_rule_id'] = $rules[$code];
            }
            if (! empty($input['cost_centre_code'])) {
                $code = trim((string) $input['cost_centre_code']);
                if (! isset($centres[$code])) {
                    $skipped[] = ['row' => $rowNumber, 'reason' => __('Unknown cost centre code: :code', ['code' => $code])];
                    continue;
                }
                $input['management_cost_centre_id'] = $centres[$code];
            }
            unset($input['category_code'], $input['cost_centre_code']);

            // Normalise the type / status spellings.
            if (! empty($input['doc_type'])) {
                $input['doc_type'] = strtolower(str_replace(' ', '_', trim((string) $input['doc_type'])));
            }
            if (! empty($input['status'])) {
                $input['status'] = self::canonicalStatus((string) $input['status']);
            }
            if (array_key_exists('doc_date', $input)) {
                $input['doc_date'] = self::parseDate($input['doc_date']);
            }
            foreach (['base', 'iva', 'retention', 'total'] as $field) {
                if (array_key_exists($field, $input)) {
                    $input[$field] = self::parseNumber($input[$field]);
                }
            }

            try {
                $data = ManagementDocumentValidator::validate($input, $creatorId);
            } catch (ValidationException $e) {
                $skipped[] = ['row' => $rowNumber, 'reason' => (string) collect($e->errors())->flatten()->first()];
                continue;
            }

            ManagementDocument::create(array_merge($data, ['created_by' => $creatorId]));
            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /** Read a CSV file into rows (arrays of cell values). */
    private static function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }
        // Strip a UTF-8 BOM if present, then peek at the first line:
        // a semicolon there means a European CSV (semicolon delimiter).
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        $firstLine = fgets($handle);
        $delimiter = str_contains($firstLine ?: '', ';') ? ';' : ',';
        rewind($handle);
        if ($bom === "\xEF\xBB\xBF") {
            fseek($handle, 3);
        }
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }

    /** Read the first sheet of an .xlsx file into rows. */
    private static function readXlsx(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = [];
        foreach ($sheet->toArray(null, true, false, false) as $row) {
            $rows[] = array_map(fn ($v) => $v === null ? null : (string) $v, $row);
        }

        return $rows;
    }

    /** Map header cells to internal field names (column index => field). */
    private static function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $index => $cell) {
            $key = strtolower(str_replace(' ', '_', trim((string) $cell)));
            if (isset(self::HEADER_MAP[$key])) {
                $map[$index] = self::HEADER_MAP[$key];
            }
        }

        return $map;
    }

    private static function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /** Map one row's cells to field names using the header map. */
    private static function mapRow(array $row, array $map): array
    {
        $input = [];
        foreach ($map as $index => $field) {
            $value = $row[$index] ?? null;
            if ($value !== null) {
                $value = trim((string) $value);
            }
            $input[$field] = $value === '' ? null : $value;
        }

        return $input;
    }

    private static function canonicalStatus(string $status): string
    {
        foreach (ManagementDocument::STATUSES as $canonical) {
            if (mb_strtolower(trim($status)) === mb_strtolower($canonical)) {
                return $canonical;
            }
        }

        return trim($status);
    }

    /** "5,57" / "5.57" / "1 234,56" -> 5.57 / 1234.56 ; empty -> null. */
    private static function parseNumber(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $value = str_replace(["\u{00a0}", ' '], '', trim((string) $value));
        if (str_contains($value, ',') && str_contains($value, '.')) {
            // European: 1.234,56 -> 1234.56
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /** Y-m-d, d/m/Y, d-m-Y, d.m.Y, or an Excel serial date -> Y-m-d. */
    private static function parseDate(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $value = trim((string) $value);
        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                return $value;
            }
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'Y/m/d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false) {
                return $date->format('Y-m-d');
            }
        }
        $time = strtotime($value);

        return $time !== false ? date('Y-m-d', $time) : $value;
    }
}
