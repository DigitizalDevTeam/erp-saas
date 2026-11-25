<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IrpsWithholdingRow extends Model
{
    protected $fillable = [
        'salary_from',
        'salary_to',
        'base_dep0',
        'base_dep1',
        'base_dep2',
        'base_dep3',
        'base_dep4plus',
        'coefficient',
        'created_by',
    ];

    /**
     * The official Mozambique IRPS monthly withholding table
     * ("IRPS Retencao Trabalho Dependente 2014F").
     *
     * Each row: salary_from, salary_to (null = no upper bound),
     * base amount to withhold at the lower limit per dependents
     * (0, 1, 2, 3, 4+), coefficient applied to each unit above
     * the lower bound. A null base/coefficient means no withholding.
     *
     * @return array<int, array{0: float, 1: float|null, 2: float|null, 3: float|null, 4: float|null, 5: float|null, 6: float|null, 7: float|null}>
     */
    public static function moz2014Rows(): array
    {
        return [
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
    }

    /**
     * Seed (or refresh) the official table for one company.
     * Idempotent: existing rows are updated, never duplicated.
     */
    public static function seedMoz2014ForCompany(int $createdBy): void
    {
        foreach (self::moz2014Rows() as [$from, $to, $b0, $b1, $b2, $b3, $b4, $coef]) {
            self::updateOrCreate(
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

