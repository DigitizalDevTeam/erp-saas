<?php

namespace Database\Seeders;

use App\Models\ManagementReportHistory;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Import the client's verified Jan–May 2026 figures from
 * PnL2026_Baseline.xlsx (the Hoya company) into
 * management_report_history — the same 95 rows as the erpsaaspro
 * import (company 1 there). The values are the file's ROUNDED monthly
 * figures (cogs 213 / 968 / 438 / 1235 / 85, fixed 2173 / 0 / 1591 /
 * 3433 / 0, personnel Operação 1994 / 0 / 911 / 1135 / 0), so the
 * reports show the same totals as the file's display.
 *
 * Run explicitly: php artisan db:seed --class=ManagementReportHistorySeeder
 * It finds the Hoya company by email and skips silently when the
 * company does not exist (e.g. a fresh install without that company).
 * Re-running is safe: rows are matched on (created_by, year, month,
 * line) and updated, never duplicated.
 */
class ManagementReportHistorySeeder extends Seeder
{
    /** Hoya company account (same on live and on the temp site). */
    public const HOYA_EMAIL = 'utharateste@gmail.com';

    /**
     * The 95 imported rows: [month, line, amount] for 2026.
     * revenue_ex/revenue_iva are the file's Sales tab; cogs/variables/
     * fixed/other/personnel are the file's PnL lines; channel:* are the
     * file's Sales channels (kept for parity — no report reads them yet).
     */
    public static function rows(): array
    {
        $months = [
            1 => [
                'revenue_ex' => 12000, 'revenue_iva' => 1946,
                'cogs' => 213, 'variables' => 6, 'fixed' => 2173, 'other' => 0,
                'depreciation' => 0, 'amortization' => 0,
                'personnel:Operação (CC 101)' => 1994,
                'personnel:Group (CC 102)' => 0,
                'personnel:Impostos e Outros Custos' => 0,
                'channel:Software Faturação:ex' => 12000, 'channel:Software Faturação:iva' => 1946,
                'channel:Restaurante:ex' => 10000, 'channel:Restaurante:iva' => 1700,
                'channel:Bar:ex' => 2000, 'channel:Bar:iva' => 246,
                'channel:Eventos:ex' => 0, 'channel:Eventos:iva' => 0,
            ],
            2 => [
                'revenue_ex' => 10000, 'revenue_iva' => 1700,
                'cogs' => 968, 'variables' => 0, 'fixed' => 0, 'other' => 0,
                'depreciation' => 0, 'amortization' => 0,
                'personnel:Operação (CC 101)' => 0,
                'personnel:Group (CC 102)' => 0,
                'personnel:Impostos e Outros Custos' => 0,
                'channel:Software Faturação:ex' => 10000, 'channel:Software Faturação:iva' => 1700,
                'channel:Restaurante:ex' => 10000, 'channel:Restaurante:iva' => 1700,
                'channel:Bar:ex' => 0, 'channel:Bar:iva' => 0,
                'channel:Eventos:ex' => 0, 'channel:Eventos:iva' => 0,
            ],
            3 => [
                'revenue_ex' => 0, 'revenue_iva' => 0,
                'cogs' => 438, 'variables' => 6, 'fixed' => 1591, 'other' => 76,
                'depreciation' => 0, 'amortization' => 0,
                'personnel:Operação (CC 101)' => 911,
                'personnel:Group (CC 102)' => 0,
                'personnel:Impostos e Outros Custos' => 0,
                'channel:Software Faturação:ex' => 0, 'channel:Software Faturação:iva' => 0,
                'channel:Restaurante:ex' => 0, 'channel:Restaurante:iva' => 0,
                'channel:Bar:ex' => 0, 'channel:Bar:iva' => 0,
                'channel:Eventos:ex' => 0, 'channel:Eventos:iva' => 0,
            ],
            4 => [
                'revenue_ex' => 0, 'revenue_iva' => 0,
                'cogs' => 1235, 'variables' => 0, 'fixed' => 3433, 'other' => 0,
                'depreciation' => 0, 'amortization' => 0,
                'personnel:Operação (CC 101)' => 1135,
                'personnel:Group (CC 102)' => 0,
                'personnel:Impostos e Outros Custos' => 0,
                'channel:Software Faturação:ex' => 0, 'channel:Software Faturação:iva' => 0,
                'channel:Restaurante:ex' => 0, 'channel:Restaurante:iva' => 0,
                'channel:Bar:ex' => 0, 'channel:Bar:iva' => 0,
                'channel:Eventos:ex' => 0, 'channel:Eventos:iva' => 0,
            ],
            5 => [
                'revenue_ex' => 0, 'revenue_iva' => 0,
                'cogs' => 85, 'variables' => 0, 'fixed' => 0, 'other' => 0,
                'depreciation' => 0, 'amortization' => 0,
                'personnel:Operação (CC 101)' => 0,
                'personnel:Group (CC 102)' => 0,
                'personnel:Impostos e Outros Custos' => 0,
                'channel:Software Faturação:ex' => 0, 'channel:Software Faturação:iva' => 0,
                'channel:Restaurante:ex' => 0, 'channel:Restaurante:iva' => 0,
                'channel:Bar:ex' => 0, 'channel:Bar:iva' => 0,
                'channel:Eventos:ex' => 0, 'channel:Eventos:iva' => 0,
            ],
        ];

        $rows = [];
        foreach ($months as $month => $lines) {
            foreach ($lines as $line => $amount) {
                $rows[] = ['year' => 2026, 'month' => $month, 'line' => $line, 'amount' => $amount];
            }
        }

        return $rows;
    }

    public function run(): void
    {
        $company = User::where('email', self::HOYA_EMAIL)->first();
        if (! $company) {
            return;
        }

        foreach (self::rows() as $row) {
            ManagementReportHistory::updateOrCreate(
                [
                    'created_by' => $company->id,
                    'year' => $row['year'],
                    'month' => $row['month'],
                    'line' => $row['line'],
                ],
                ['amount' => $row['amount']]
            );
        }
    }
}
