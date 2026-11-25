<?php

namespace Database\Seeders;

use App\Models\IrpsWithholdingRow;
use Illuminate\Database\Seeder;

class IrpsWithholdingMoz2014Seeder extends Seeder
{
    /**
     * Seeds the IRPS withholding table from:
     * "IRPS Retencao Trabalho Dependente 2014F.pdf" (Mozambique).
     *
     * Seeds for every company that runs payroll (every distinct
     * employees.created_by) plus the super admin (1), so no company
     * is left with an empty table and a 0.00 IRPS on every payslip.
     */
    public function run(): void
    {
        $createdByIds = [1];

        try {
            $fromEmployees = \DB::table('employees')->distinct()->pluck('created_by')->all();
            foreach ($fromEmployees as $id) {
                $createdByIds[] = (int) $id;
            }
        } catch (\Throwable $e) {
            // Employees table missing (fresh install order): seed company 1 only.
        }

        foreach (array_values(array_unique($createdByIds)) as $createdBy) {
            IrpsWithholdingRow::seedMoz2014ForCompany($createdBy);
        }
    }
}

