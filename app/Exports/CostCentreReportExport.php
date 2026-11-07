<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Generic cost centre export (Phase 6): the same headings + rows the
 * report screen shows, as an Excel file. One class for all reports,
 * the allocation ledger and the GL dimensions screen — the controller
 * hands it the already-built data, so an export can never disagree
 * with its screen.
 */
class CostCentreReportExport implements FromCollection, WithHeadings
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
