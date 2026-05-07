<?php

namespace Modules\HotelManagement\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class SimpleSheetExport implements FromArray, WithHeadings
{
    public function __construct(
        protected array $headings,
        protected array $rows
    ) {
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function array(): array
    {
        return $this->rows;
    }
}
