<?php

namespace App\Imports;

use App\Services\FixedAssetAcquisitionService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Fixed Assets Phase 1 — Excel import of old assets (D4).
 *
 * One row per asset: name, category_code, purchase_cost and
 * the optional columns from the template (location_code,
 * cost_centre_code, acquisition_method, purchase_date,
 * currency, exchange_rate, transport, installation,
 * import_duties, non_recoverable_taxes, professional_fees,
 * directly_attributable, discounts, vat_amount,
 * vat_recoverable, opening_accumulated_depreciation,
 * remaining_life, remaining_life_unit, residual_value,
 * serial_number, brand, model, supplier, warranty dates,
 * source_document_reference, description).
 *
 * Valid rows become DRAFT assets with opening accumulated
 * depreciation + remaining life; the normal review → approval
 * → capitalization flow capitalizes them. Invalid rows are
 * skipped and reported — one bad row never blocks the rest.
 */
class FixedAssetImport implements ToCollection, WithHeadingRow
{
    use Importable;

    /** @var array{created: int, skipped: int, errors: array<int, string>} */
    public array $result = ['created' => 0, 'skipped' => 0, 'errors' => []];

    public function __construct(
        protected FixedAssetAcquisitionService $acquisitions,
        protected int $creatorId,
        protected int $userId,
    ) {
    }

    public function collection(Collection $rows): void
    {
        $this->result = $this->acquisitions->importRows($rows->toArray(), $this->creatorId, $this->userId);
    }

    /** Template headings (the import expects these column names). */
    public static function headings(): array
    {
        return [
            'name',
            'category_code',
            'location_code',
            'cost_centre_code',
            'acquisition_method',
            'purchase_date',
            'purchase_cost',
            'currency',
            'exchange_rate',
            'transport',
            'installation',
            'import_duties',
            'non_recoverable_taxes',
            'professional_fees',
            'directly_attributable',
            'discounts',
            'vat_amount',
            'vat_recoverable',
            'opening_accumulated_depreciation',
            'remaining_life',
            'remaining_life_unit',
            'residual_value',
            'serial_number',
            'brand',
            'model',
            'supplier',
            'warranty_start',
            'warranty_end',
            'source_document_reference',
            'description',
        ];
    }

    /** One example row for the downloadable template. */
    public static function exampleRow(): array
    {
        return [
            'Office Desk',
            'FUR',
            '',
            '',
            'opening_balance',
            '2023-05-10',
            '45000',
            'MZN',
            '1',
            '0',
            '0',
            '0',
            '0',
            '0',
            '0',
            '0',
            '0',
            'false',
            '18000',
            '3',
            'year',
            '0',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
        ];
    }
}
