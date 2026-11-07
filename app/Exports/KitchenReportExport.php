<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Generic kitchen report export (Phase 7): the same headings + rows
 * the report screen shows, as an Excel file. One class for all
 * reports — the controller hands it the already-built data.
 */
class KitchenReportExport implements FromCollection, WithHeadings
{
    public function __construct(
        protected array $headings,
        protected array $rows,
    ) {
    }

    public function collection()
    {
        return collect($this->rows);
    }

    public function headings(): array
    {
        return $this->headings;
    }
}
