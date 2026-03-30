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
     * IMPORTANT: This seeds rows for created_by=1 only. In multi-tenant setups,
     * users should copy/insert rows per company as needed.
     */
    public function run(): void
    {
        $createdBy = 1;

        $rows = [
            // salary_from, salary_to, base0, base1, base2, base3, base4+, coef
            [0.00, 20249.99, null, null, null, null, null, null],
            [20250.00, 20749.99, 0.00, null, null, null, null, 0.10],
            [20750.00, 20999.99, 50.00, 0.00, null, null, null, 0.10],
            [21000.00, 21249.99, 75.00, 25.00, 0.00, null, null, 0.10],
            [21250.00, 21749.99, 100.00, 50.00, 25.00, 0.00, null, 0.10],
            [21750.00, 22249.99, 150.00, 100.00, 75.00, 50.00, 0.00, 0.10],
            [22250.00, 32749.99, 200.00, 150.00, 125.00, 100.00, 50.00, 0.15],
            [32750.00, 60749.99, 1775.00, 1725.00, 1700.00, 1675.00, 1625.00, 0.20],
            [60750.00, 144749.99, 7375.00, 7325.00, 7300.00, 7275.00, 7225.00, 0.25],
            [144750.00, null, 28375.00, 28325.00, 28300.00, 28275.00, 28225.00, 0.32],
        ];

        foreach ($rows as [$from, $to, $b0, $b1, $b2, $b3, $b4, $coef]) {
            IrpsWithholdingRow::updateOrCreate(
                [
                    'created_by' => $createdBy,
                    'salary_from' => $from,
                    'salary_to' => $to,
                ],
                [
                    'base_dep0' => $b0,
                    'base_dep1' => $b1,
                    'base_dep2' => $b2,
                    'base_dep3' => $b3,
                    'base_dep4plus' => $b4,
                    'coefficient' => $coef,
                ]
            );
        }
    }
}

