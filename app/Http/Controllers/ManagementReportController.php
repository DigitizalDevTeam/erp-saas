<?php

namespace App\Http\Controllers;

use App\Services\ManagementDocumentCosts;
use App\Services\ManagementReportExport;
use App\Services\MonthlyManagementData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Management Reports — the client's management P&L (from
 * PnL2026_Baseline.xlsx), rebuilt on the website:
 *
 *  - P&L (Management): monthly Jan–Dez + TOTAL + AVG. Revenue comes
 *    from issued invoices (automatic, at issue, ex-VAT); costs come
 *    from Management Documents + mapped manual journals (no
 *    system-derived costs alongside — no double-count). Months with
 *    imported history keep their figures (the history overlay).
 *  - %P&L (Management): every line as % of that month's sales;
 *    DELIVERY COSTS % is computed from the Delivery Partner
 *    documents, NOT hardcoded 0; VARIABLE excludes delivery.
 *  - Costs by Category: the file's Costs tabs — rows = categories,
 *    months + TOTAL, group subtotals, TOTAL GERAL, no-cost-centre
 *    and unmapped rows, with a cost-centre selector.
 *  - Sales: monthly revenue split by channel (the file's Sales tab).
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

        return view('management-reports.pl_percent', [
            'year' => $year,
            'lines' => $this->buildPlPercentLines($data, $pl),
            'historyMonths' => $data['historyMonths'],
            'years' => range((int) date('Y'), (int) date('Y') - 5),
        ]);
    }

    /**
     * The %P&L line set: GROSS REVENUE in € on top (the file shows
     * the € values, not %), Revenue vs prior year, then every P&L
     * line as % of that month's gross revenue. VARIABLE excludes the
     * delivery-partner commission (its own line). Each line carries
     * the same per-line history flags as the P&L.
     */
    private function buildPlPercentLines(array $data, array $pl): array
    {
        // Every line as % of that month's gross revenue.
        $pct = fn (array $months) => $this->toPercent($months, $data['revenue']);

        $lines = [];
        $lines[] = [
            'label' => __('Gross Revenue'),
            'months' => $this->monthsOf($data['revenue'], 'ex'),
            'total' => array_sum($this->monthsOf($data['revenue'], 'ex')),
            'avg' => null, 'kind' => 'money', 'class' => 'fw-bold',
            'history' => $pl['historyByLabel']['FATURAÇÃO BRUTA'],
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
            'history' => $pl['historyByLabel']['FATURAÇÃO BRUTA'],
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
                'history' => $pl['historyByLabel'][$label] ?? array_fill(1, 12, false),
            ];
        }

        return $lines;
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

    /** Sales by channel (the file's Sales tab shape). */
    public function managementSales(Request $request)
    {
        $this->authorizeManagement('menu.dashboard.accounting.reports.management.management_sales');
        $creatorId = \Auth::user()->creatorId();
        $year = (int) $request->input('year', (int) date('Y'));

        $data = MonthlyManagementData::build($creatorId, $year);

        return view('management-reports.sales', [
            'year' => $year,
            'channels' => $data['channels'],
            'historyLines' => $data['historyLines'],
            'years' => range((int) date('Y'), (int) date('Y') - 5),
        ]);
    }

    /** Export the P&L as .xlsx. */
    public function exportPl(Request $request)
    {
        $this->authorizeManagement('menu.dashboard.accounting.reports.management.management_pl');
        $year = (int) $request->input('year', (int) date('Y'));
        $pl = $this->buildPl(MonthlyManagementData::build(\Auth::user()->creatorId(), $year));

        return ManagementReportExport::download(
            ManagementReportExport::pl($pl['lines'], $year),
            "management-pl-{$year}.xlsx"
        );
    }

    /** Export the %P&L as .xlsx. */
    public function exportPlPercent(Request $request)
    {
        $this->authorizeManagement('menu.dashboard.accounting.reports.management.management_pl_percent');
        $creatorId = \Auth::user()->creatorId();
        $year = (int) $request->input('year', (int) date('Y'));

        $data = MonthlyManagementData::build($creatorId, $year);
        $pl = $this->buildPl($data);
        $lines = $this->buildPlPercentLines($data, $pl);

        return ManagementReportExport::download(
            ManagementReportExport::plPercent($lines, $year),
            "management-pl-percent-{$year}.xlsx"
        );
    }

    /** Export the Costs table as .xlsx. */
    public function exportCosts(Request $request)
    {
        $this->authorizeManagement('menu.dashboard.accounting.reports.management.management_costs');
        $creatorId = \Auth::user()->creatorId();
        $year = (int) $request->input('year', (int) date('Y'));
        $costCentre = $request->input('cost_centre', 'all');

        $table = ManagementDocumentCosts::costsTable($creatorId, $year, $costCentre);

        return ManagementReportExport::download(
            ManagementReportExport::costs($table, $year),
            "management-costs-{$year}.xlsx"
        );
    }

    /** Export the Sales by channel table as .xlsx. */
    public function exportSales(Request $request)
    {
        $this->authorizeManagement('menu.dashboard.accounting.reports.management.management_sales');
        $year = (int) $request->input('year', (int) date('Y'));
        $data = MonthlyManagementData::build(\Auth::user()->creatorId(), $year);

        return ManagementReportExport::download(
            ManagementReportExport::sales($data['channels'], $year),
            "management-sales-{$year}.xlsx"
        );
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

        $line = function (string $label, array $months, string $class = '', array $history = [], ?string $link = null, ?int $centreId = null) use ($sum, $avg): array {
            $total = $sum($months);

            return [
                'label' => __($label),
                'months' => $months,
                'total' => $total,
                'avg' => $avg($total),
                'kind' => 'money',
                'class' => $class,
                // Per-line, per-month history flags: true when this
                // line-month comes from imported history (the view
                // marks those cells with * — not the whole month).
                'history' => $history,
                // Drill-down: 'costs' links a month cell to the
                // Documents list for that year+month; 'dept' adds the
                // cost centre filter. Derived/revenue lines have none.
                'link' => $link,
                'centreId' => $centreId,
            ];
        };

        // Per-line history flags, from the history-lines map
        // (month => [line => amount]). A derived line (MARGEM BRUTA,
        // EBITDA, RESULTADO OPERACIONAL) is history-sourced only when
        // ALL its inputs are history-sourced that month.
        $historyLines = $data['historyLines'] ?? [];
        $has = fn (int $m, string $key): bool => isset($historyLines[$m][$key]);
        $hasPersonnel = function (int $m) use ($historyLines): bool {
            foreach ($historyLines[$m] ?? [] as $key => $_) {
                if (str_starts_with($key, 'personnel:')) {
                    return true;
                }
            }

            return false;
        };
        $allInputs = function (int $m, array $keys, bool $personnel = false) use ($has, $hasPersonnel): bool {
            foreach ($keys as $key) {
                if (! $has($m, $key)) {
                    return false;
                }
            }

            return ! $personnel || $hasPersonnel($m);
        };
        $historyMap = function (callable $fn): array {
            $out = [];
            for ($m = 1; $m <= 12; $m++) {
                $out[$m] = (bool) $fn($m);
            }

            return $out;
        };
        $historyByLabel = [
            'FATURAÇÃO BRUTA' => $historyMap(fn ($m) => $has($m, 'revenue_ex')),
            'F&B COST' => $historyMap(fn ($m) => $has($m, 'cogs')),
            'VARIAVEIS' => $historyMap(fn ($m) => $has($m, 'variables')),
            // DELIVERY COSTS is part of the variables group, so the
            // variables history row covers it too.
            'DELIVERY COSTS' => $historyMap(fn ($m) => $has($m, 'variables')),
            'MARGEM BRUTA' => $historyMap(fn ($m) => $allInputs($m, ['revenue_ex', 'cogs', 'variables'])),
            'PESSOAL' => $historyMap(fn ($m) => $hasPersonnel($m)),
            'Impostos e Outros Custos' => $historyMap(fn ($m) => $has($m, 'personnel:Impostos e Outros Custos')),
            'FIXOS' => $historyMap(fn ($m) => $has($m, 'fixed')),
            'OUTROS' => $historyMap(fn ($m) => $has($m, 'other')),
            'EBITDA' => $historyMap(fn ($m) => $allInputs($m, ['revenue_ex', 'cogs', 'variables', 'fixed', 'other'], true)),
            'DEPRECIAÇÕES' => $historyMap(fn ($m) => $has($m, 'depreciation')),
            'AMORTIZAÇÕES' => $historyMap(fn ($m) => $has($m, 'amortization')),
            'RESULTADO OPERACIONAL' => $historyMap(fn ($m) => $allInputs($m, ['revenue_ex', 'cogs', 'variables', 'fixed', 'other', 'depreciation', 'amortization'], true)),
        ];

        $lines = [];
        $lines[] = $line('FATURAÇÃO BRUTA', $revenueEx, 'fw-bold', $historyByLabel['FATURAÇÃO BRUTA']);
        $lines[] = $line('F&B COST', $data['cogs'], '', $historyByLabel['F&B COST'], 'costs');
        $lines[] = $line('VARIAVEIS', $data['variables'], '', $historyByLabel['VARIAVEIS'], 'costs');
        $lines[] = $line('MARGEM BRUTA', $margin, 'fw-bold', $historyByLabel['MARGEM BRUTA']);
        // PESSOAL with its department sub-rows + the Impostos sub-row.
        $lines[] = $line('PESSOAL', $personnelTotal, 'fw-bold', $historyByLabel['PESSOAL'], 'costs');
        foreach ($data['departments'] as $dept) {
            $deptMonths = [];
            for ($m = 1; $m <= 12; $m++) {
                $deptMonths[$m] = $data['personnel'][$m]['departments'][$dept] ?? 0.0;
            }
            $lines[] = $line($dept, $deptMonths, 'ps-4', $historyMap(fn ($m) => $has($m, "personnel:{$dept}")), 'dept', $data['departmentCentres'][$dept] ?? null);
        }
        $inssMonths = [];
        for ($m = 1; $m <= 12; $m++) {
            $inssMonths[$m] = $data['personnel'][$m]['inss'];
        }
        $lines[] = $line('Impostos e Outros Custos', $inssMonths, 'ps-4', $historyByLabel['Impostos e Outros Custos'], 'costs');
        $lines[] = $line('FIXOS', $data['expenses'], '', $historyByLabel['FIXOS'], 'costs');
        $lines[] = $line('OUTROS', $data['other'], '', $historyByLabel['OUTROS'], 'costs');
        $lines[] = $line('EBITDA', $ebitda, 'fw-bold', $historyByLabel['EBITDA']);
        $lines[] = $line('DEPRECIAÇÕES', $data['depreciation'], '', $historyByLabel['DEPRECIAÇÕES']);
        $lines[] = $line('AMORTIZAÇÕES', $data['amortization'], '', $historyByLabel['AMORTIZAÇÕES']);
        $lines[] = $line('RESULTADO OPERACIONAL', $operatingResult, 'fw-bold', $historyByLabel['RESULTADO OPERACIONAL']);

        return [
            'lines' => $lines,
            'margin' => $margin,
            'ebitda' => $ebitda,
            'operatingResult' => $operatingResult,
            'personnelTotal' => $personnelTotal,
            'monthsWithRevenue' => $monthsWithRevenue,
            'historyByLabel' => $historyByLabel,
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
