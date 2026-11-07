<?php

namespace App\Http\Controllers;

use App\Models\CostCentre;
use App\Services\DimensionReportService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CostCentreReportController extends Controller
{
    public function __construct(
        protected DimensionReportService $reports = new DimensionReportService(),
    ) {}

    /**
     * Expense by cost centre — ACCRUAL basis (plan §7/§9). An expense
     * is recognised once, at the bill or journal date — never again at
     * payment. The report labels its basis on every render.
     */
    public function expense(Request $request)
    {
        if (! Auth::user()->can('view cost centre reports')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        [$from, $to] = $this->period($request);
        $centreId = $request->filled('cost_centre_id') ? (int) $request->input('cost_centre_id') : null;
        $projectId = $request->filled('project_id') ? (int) $request->input('project_id') : null;

        $report = $this->reports->expenseByCentre($creatorId, $from, $to, $centreId, $projectId);

        if ($request->input('export') === 'csv') {
            return $this->requireExportPermission($request, $report, 'expense-by-centre', [
                [__('Cost Centre'), __('Project'), __('Lines'), __('Total'), __('Total incl. children')],
            ], function () use ($report) {
                foreach ($report['rows'] as $row) {
                    yield [
                        $row['cost_centre_code'],
                        $row['project_id'] ?? '',
                        $row['rows'],
                        $row['total'],
                        $row['total_including_children'],
                    ];
                }
            });
        }

        return view('cost-centres.reports.expense', array_merge($report, [
            'centres' => $this->centres($creatorId),
            'selectedCentreId' => $centreId,
            'selectedProjectId' => $projectId,
        ]));
    }

    /**
     * Cash outflow by cost centre — CASH basis (plan §7/§9). Reads the
     * Phase 3 treasury dimensions: a bill payment inherits the bill's
     * split, so the cash is shown once, at the payment date. The
     * expense was recognised at the bill and is not recognised again.
     */
    public function cash(Request $request)
    {
        if (! Auth::user()->can('view cost centre reports')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        [$from, $to] = $this->period($request);
        $centreId = $request->filled('cost_centre_id') ? (int) $request->input('cost_centre_id') : null;
        $projectId = $request->filled('project_id') ? (int) $request->input('project_id') : null;

        $report = $this->reports->cashOutflowByCentre($creatorId, $from, $to, $centreId, $projectId);

        if ($request->input('export') === 'csv') {
            return $this->requireExportPermission($request, $report, 'cash-outflow-by-centre', [
                [__('Cost Centre'), __('Project'), __('Lines'), __('Total'), __('Total incl. children')],
            ], function () use ($report) {
                foreach ($report['rows'] as $row) {
                    yield [
                        $row['cost_centre_code'],
                        $row['project_id'] ?? '',
                        $row['rows'],
                        $row['total'],
                        $row['total_including_children'],
                    ];
                }
            });
        }

        return view('cost-centres.reports.cash', array_merge($report, [
            'centres' => $this->centres($creatorId),
            'selectedCentreId' => $centreId,
            'selectedProjectId' => $projectId,
        ]));
    }

    /**
     * Reconciliation check (plan §13): dimension totals must equal the
     * eligible journal line amounts. Shows the GL total, the dimensioned
     * total, the difference, and every line whose dimensions do not sum
     * to the line amount.
     */
    public function reconciliation(Request $request)
    {
        if (! Auth::user()->can('view cost centre reports')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        [$from, $to] = $this->period($request);
        $scope = $request->input('scope', 'expense');
        if (! in_array($scope, ['expense', 'all'], true)) {
            $scope = 'expense';
        }

        $report = $this->reports->reconciliation($creatorId, $from, $to, $scope);

        if ($request->input('export') === 'csv') {
            return $this->requireExportPermission($request, $report, 'reconciliation', [
                [__('Line'), __('Account'), __('Date'), __('GL amount'), __('Dimensioned'), __('Difference')],
            ], function () use ($report) {
                foreach (array_merge($report['mismatches'], $report['unassigned']) as $line) {
                    yield [
                        $line['line_type'].' #'.$line['line_id'],
                        $line['account_code'],
                        $line['date'],
                        $line['net'],
                        $line['dimensioned'],
                        bcsub((string) $line['net'], (string) $line['dimensioned'], 2),
                    ];
                }
            });
        }

        return view('cost-centres.reports.reconciliation', array_merge($report, ['scope' => $scope]));
    }

    /**
     * Unassigned / invalid allocations (plan §9): expense lines with no
     * dimensions, dimension rows pointing at a centre that can no
     * longer take them, and posted allocations whose centre is now
     * inactive.
     */
    public function unassigned(Request $request)
    {
        if (! Auth::user()->can('view cost centre reports')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        [$from, $to] = $this->period($request);

        $report = $this->reports->unassigned($creatorId, $from, $to);

        if ($request->input('export') === 'csv') {
            return $this->requireExportPermission($request, $report, 'unassigned', [
                [__('Section'), __('Line'), __('Account'), __('Date'), __('Amount'), __('Reason')],
            ], function () use ($report) {
                foreach ($report['missing_dimensions'] as $line) {
                    yield [__('Missing dimensions'), $line['line_type'].' #'.$line['line_id'], $line['account_code'], $line['date'], $line['net'], $line['reason']];
                }
                foreach ($report['invalid_dimensions'] as $row) {
                    yield [__('Invalid dimension'), $row['line_type'].' #'.$row['line_id'], '', '', $row['allocated_amount'], $row['reason']];
                }
                foreach ($report['invalid_allocations'] as $row) {
                    yield [__('Invalid allocation'), 'allocation #'.$row['allocation_id'], '', '', $row['allocated_amount'], $row['reason']];
                }
            });
        }

        return view('cost-centres.reports.unassigned', $report);
    }

    /**
     * Budget vs actual (Cost Centre Phase 5, plan §9). Monthly budget
     * vs actual per centre, with variance. Actuals are the Phase 4
     * dimensioned GL actuals (accrual basis); committed is the active
     * commitments only; available is approved − committed − actual.
     * Rows with no budget at all are listed as 'no_budget' — spending
     * without a budget must be visible, not hidden.
     */
    public function budget(Request $request)
    {
        if (! Auth::user()->can('view cost centre reports')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        $fiscalYear = (int) ($request->input('fiscal_year') ?: date('Y'));
        $period = $request->filled('period') ? (int) $request->input('period') : null;
        $centreId = $request->filled('cost_centre_id') ? (int) $request->input('cost_centre_id') : null;

        $budgets = new \App\Services\BudgetControlService();
        $report = $budgets->budgetVsActual($creatorId, $fiscalYear, $period, $centreId);
        // from/to only feed the CSV header filename.
        $report['from'] = $period !== null ? sprintf('%04d-%02d-01', $fiscalYear, $period) : sprintf('%04d-01-01', $fiscalYear);
        $report['to'] = $period !== null ? sprintf('%04d-%02d-01', $fiscalYear, $period) : sprintf('%04d-12-31', $fiscalYear);

        if ($request->input('export') === 'csv') {
            return $this->requireExportPermission($request, $report, 'budget-vs-actual', [
                [__('Cost Centre'), __('Month'), __('Scope'), __('Approved'), __('Committed'), __('Actual'), __('Available'), __('Variance'), __('Variance %'), __('Status')],
            ], function () use ($report) {
                foreach ($report['rows'] as $row) {
                    yield [
                        ($row['cost_centre_code'] ?? '').' '.($row['cost_centre_name'] ?? ''),
                        $row['fiscal_year'].'-'.str_pad((string) $row['period'], 2, '0', STR_PAD_LEFT),
                        $row['scope_key'],
                        $row['approved'],
                        $row['committed'],
                        $row['actual'],
                        $row['available'],
                        $row['variance'],
                        $row['variance_percent'] ?? '',
                        $row['status'],
                    ];
                }
            });
        }

        return view('cost-centres.reports.budget', array_merge($report, [
            'centres' => $this->centres($creatorId),
            'selectedCentreId' => $centreId,
            'selectedPeriod' => $period,
        ]));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Period filter: ?from= ?to=, defaulting to the current month. */
    protected function period(Request $request): array
    {
        $from = $request->filled('from') ? Carbon::parse($request->input('from'))->startOfDay() : Carbon::now()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->input('to'))->endOfDay() : Carbon::now()->endOfMonth();

        return [$from, $to];
    }

    protected function centres(int $creatorId)
    {
        return CostCentre::forCompany($creatorId)->orderBy('code')->get();
    }

    protected function requireExportPermission(Request $request, array $report, string $filename, array $header, callable $rows)
    {
        if (! Auth::user()->can('export cost centre reports')) {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $currency = $report['currency_code'] ?? '';
        $basis = $report['basis_label'] ?? ($report['scope'] ?? '');

        return new StreamedResponse(function () use ($header, $rows, $report, $currency, $basis) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [__('Cost Centre Report'), $report['from'] ?? '', '—', $report['to'] ?? '', $currency]);
            if ($basis !== '') {
                fputcsv($out, [$basis]);
            }
            foreach ($header as $line) {
                fputcsv($out, $line);
            }
            foreach ($rows() as $line) {
                fputcsv($out, $line);
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'-'.($report['from'] ?? date('Y-m-d')).'.csv"',
        ]);
    }
}
