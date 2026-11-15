<?php

namespace App\Http\Controllers;

use App\Services\ManagementDocumentCosts;
use App\Services\MonthlyManagementData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Management Reports — the client's management P&L (from
 * PnL2026_Baseline.xlsx), rebuilt on the website:
 *
 *  - P&L (Management): monthly Jan–Dez + TOTAL + AVG. Revenue comes
 *    from issued invoices (automatic, at issue, ex-VAT); costs come
 *    from Management Documents ONLY (no system-derived costs
 *    alongside — no double-count). Months with imported history keep
 *    their figures (the history overlay).
 *  - %P&L (Management): every line as % of that month's sales;
 *    DELIVERY COSTS % is computed from the Delivery Partner
 *    documents, NOT hardcoded 0; VARIABLE excludes delivery.
 *  - Costs by Category: the file's Costs tabs — rows = categories,
 *    months + TOTAL, group subtotals, TOTAL GERAL, no-cost-centre
 *    and unmapped rows, with a cost-centre selector.
 *
 * The classic P&L (ReportController::profitLoss) is a different
 * report and is NOT touched by this controller.
 */
class ManagementReportController extends Controller
{
    private function authorizeManagement(string $menuPermission): void
    {
        $user = Auth::user();
        if ($user && ($user->can($menuPermission) || $user->can('manage report'))) {
            return;
        }
        abort(403, __('Permission Denied.'));
    }

    public function managementPl(Request $request)
    {
        $this->authorizeManagement('menu.dashboard.accounting.reports.management.management_pl');
        $creatorId = \Auth::user()->creatorId();
        $year = (int) $request->input('year', (int) date('Y'));

        $data = MonthlyManagementData::build($creatorId, $year);
        $pl = $this->buildPl($data);

        return view('management-reports.pl', [
            'year' => $year,
            'lines' => $pl['lines'],
            'historyMonths' => $data['historyMonths'],
            'excluded' => $data['excluded'],
            'monthsWithRevenue' => $pl['monthsWithRevenue'],
            'years' => range((int) date('Y'), (int) date('Y') - 5),
        ]);
    }

    public function managementPlPercent(Request $request)
    {
        $this->authorizeManagement('menu.dashboard.accounting.reports.management.management_pl_percent');
        $creatorId = \Auth::user()->creatorId();
        $year = (int) $request->input('year', (int) date('Y'));

        $data = MonthlyManagementData::build($creatorId, $year);
        $pl = $this->buildPl($data);

        // Every line as % of that month's gross revenue. VARIABLE
        // excludes the delivery-partner commission (its own line).
        $pct = fn (array $months) => $this->toPercent($months, $data['revenue']);

        $lines = [];
        // GROSS REVENUE in € on top (the file shows the € values, not %).
        $lines[] = [
            'label' => __('Gross Revenue'),
            'months' => $this->monthsOf($data['revenue'], 'ex'),
            'total' => array_sum($this->monthsOf($data['revenue'], 'ex')),
            'avg' => null, 'kind' => 'money', 'class' => 'fw-bold',
        ];
        // Revenue vs prior year (% change, the file's "Revenue vs 2025").
        $vsPrior = [];
        for ($m = 1; $m <= 12; $m++) {
            $prior = $data['priorRevenue'][$m]['ex'] ?? 0.0;
            $vsPrior[$m] = $prior > 0
                ? round(($data['revenue'][$m]['ex'] - $prior) / $prior * 100, 1)
                : 0.0;
        }
        $lines[] = [
            'label' => __('Revenue vs prior year'),
            'months' => $vsPrior,
            'total' => null, 'avg' => null, 'kind' => 'pct', 'class' => '',
        ];

        $map = [
            ['F&B COST', $data['cogs']],
            ['VARIAVEIS', array_map(fn ($m) => $data['variables'][$m] - $data['delivery'][$m], range(1, 12))],
            ['DELIVERY COSTS', $data['delivery']],
            ['MARGEM BRUTA', $pl['margin']],
            ['PESSOAL', $pl['personnelTotal']],
            ['FIXOS', $data['expenses']],
            ['OUTROS', $data['other']],
            ['EBITDA', $pl['ebitda']],
            ['DEPRECIAÇÕES', $data['depreciation']],
            ['AMORTIZAÇÕES', $data['amortization']],
            ['RESULTADO OPERACIONAL', $pl['operatingResult']],
        ];
        foreach ($map as [$label, $months]) {
            $months = array_combine(range(1, 12), array_values($months));
            $lines[] = [
                'label' => __($label),
                'months' => $pct($months),
                'total' => null, 'avg' => null, 'kind' => 'pct',
                'class' => in_array($label, ['MARGEM BRUTA', 'EBITDA', 'RESULTADO OPERACIONAL'], true) ? 'fw-bold' : '',
            ];
        }

        return view('management-reports.pl_percent', [
            'year' => $year,
            'lines' => $lines,
            'historyMonths' => $data['historyMonths'],
            'years' => range((int) date('Y'), (int) date('Y') - 5),
        ]);
    }

    public function managementCosts(Request $request)
    {
        $this->authorizeManagement('menu.dashboard.accounting.reports.management.management_costs');
        $creatorId = \Auth::user()->creatorId();
        $year = (int) $request->input('year', (int) date('Y'));
        $costCentre = $request->input('cost_centre', 'all');

        $table = ManagementDocumentCosts::costsTable($creatorId, $year, $costCentre);

        return view('management-reports.costs', [
            'year' => $year,
            'costCentre' => $costCentre,
            'table' => $table,
            'years' => range((int) date('Y'), (int) date('Y') - 5),
        ]);
    }

    /**
     * The P&L line set (the file's PnL tab, same order and formulas):
     * FATURAÇÃO BRUTA = sales (ex-VAT); MARGEM BRUTA = sales − F&B −
     * variables; EBITDA = margin − pessoal − fixos − outros;
     * RESULTADO OPERACIONAL = EBITDA − depreciações − amortizações.
     * AVG = TOTAL / months WITH revenue (the file's rule).
     */
    private function buildPl(array $data): array
    {
        $sum = fn (array $months) => round(array_sum($months), 2);
        $monthsWithRevenue = 0;
        for ($m = 1; $m <= 12; $m++) {
            if ($data['revenue'][$m]['ex'] > 0) {
                $monthsWithRevenue++;
            }
        }
        $avg = fn (float $total) => $monthsWithRevenue > 0 ? round($total / $monthsWithRevenue, 2) : 0.0;

        $revenueEx = $this->monthsOf($data['revenue'], 'ex');
        $margin = [];
        $ebitda = [];
        $operatingResult = [];
        $personnelTotal = [];
        for ($m = 1; $m <= 12; $m++) {
            $personnelTotal[$m] = round($data['personnel'][$m]['total'], 2);
            $margin[$m] = round($revenueEx[$m] - $data['cogs'][$m] - $data['variables'][$m], 2);
            $ebitda[$m] = round($margin[$m] - $personnelTotal[$m] - $data['expenses'][$m] - $data['other'][$m], 2);
            $operatingResult[$m] = round($ebitda[$m] - $data['depreciation'][$m] - $data['amortization'][$m], 2);
        }

        $line = function (string $label, array $months, string $class = '') use ($sum, $avg): array {
            $total = $sum($months);

            return [
                'label' => __($label),
                'months' => $months,
                'total' => $total,
                'avg' => $avg($total),
                'kind' => 'money',
                'class' => $class,
            ];
        };

        $lines = [];
        $lines[] = $line('FATURAÇÃO BRUTA', $revenueEx, 'fw-bold');
        $lines[] = $line('F&B COST', $data['cogs']);
        $lines[] = $line('VARIAVEIS', $data['variables']);
        $lines[] = $line('MARGEM BRUTA', $margin, 'fw-bold');
        // PESSOAL with its department sub-rows + the Impostos sub-row.
        $lines[] = $line('PESSOAL', $personnelTotal, 'fw-bold');
        foreach ($data['departments'] as $dept) {
            $deptMonths = [];
            for ($m = 1; $m <= 12; $m++) {
                $deptMonths[$m] = $data['personnel'][$m]['departments'][$dept] ?? 0.0;
            }
            $lines[] = $line($dept, $deptMonths, 'ps-4');
        }
        $inssMonths = [];
        for ($m = 1; $m <= 12; $m++) {
            $inssMonths[$m] = $data['personnel'][$m]['inss'];
        }
        $lines[] = $line('Impostos e Outros Custos', $inssMonths, 'ps-4');
        $lines[] = $line('FIXOS', $data['expenses']);
        $lines[] = $line('OUTROS', $data['other']);
        $lines[] = $line('EBITDA', $ebitda, 'fw-bold');
        $lines[] = $line('DEPRECIAÇÕES', $data['depreciation']);
        $lines[] = $line('AMORTIZAÇÕES', $data['amortization']);
        $lines[] = $line('RESULTADO OPERACIONAL', $operatingResult, 'fw-bold');

        return [
            'lines' => $lines,
            'margin' => $margin,
            'ebitda' => $ebitda,
            'operatingResult' => $operatingResult,
            'personnelTotal' => $personnelTotal,
            'monthsWithRevenue' => $monthsWithRevenue,
        ];
    }

    /** Extract one key (ex/iva/total) per month from the revenue set. */
    private function monthsOf(array $revenue, string $key): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $out[$m] = round($revenue[$m][$key] ?? 0.0, 2);
        }

        return $out;
    }

    /** Line values as % of that month's gross revenue (0 when no sales). */
    private function toPercent(array $months, array $revenue): array
    {
        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $base = $revenue[$m]['ex'] ?? 0.0;
            $out[$m] = $base > 0 ? round(($months[$m] ?? 0.0) / $base * 100, 1) : 0.0;
        }

        return $out;
    }
}
