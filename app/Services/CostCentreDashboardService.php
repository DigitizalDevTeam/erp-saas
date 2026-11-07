<?php

namespace App\Services;

use App\Models\CostCentre;
use App\Models\CostCentreBudget;
use App\Models\CostCentreBudgetApproval;
use App\Models\CostCentreCommitment;

/**
 * Cost centre dashboard (Cost Centre Phase 6, plan §10/§13, exit gate:
 * "dashboard loads with budget status per centre and links to the
 * allocation ledger").
 *
 * One screen per company for the current fiscal period: per-centre
 * budget status (approved / committed / actual / available, variance
 * %), overspend warnings, expense vs cash totals, the reconciliation
 * summary and the unassigned counts.
 *
 * Counting rule (so the dashboard can never disagree with the GL):
 * every figure is computed ONCE per centre from the bulk sources.
 * A centre may hold several budget rows (whole centre + per account
 * + per project) — those are alternative caps for different scopes,
 * not additive, so the dashboard takes the whole-centre budget when
 * one exists and the scoped budgets otherwise; the actual is the
 * centre's dimensioned expense total for the month, counted once;
 * each active commitment is counted once. The per-budget detail
 * stays on the budget-vs-actual report.
 *
 * Everything is aggregated in bulk and sliced in PHP — the dashboard
 * runs a FIXED number of queries no matter how many centres the
 * company has. No per-centre queries anywhere.
 */
class CostCentreDashboardService
{
    public function __construct(
        protected BudgetControlService $budgets = new BudgetControlService(),
        protected DimensionReportService $reports = new DimensionReportService(),
    ) {}

    public function dashboard(int $creatorId, int $fiscalYear, int $period): array
    {
        [$from, $to] = BudgetControlService::monthRange($fiscalYear, $period);

        $expense = $this->reports->expenseByCentre($creatorId, $from, $to);
        $cash = $this->reports->cashOutflowByCentre($creatorId, $from, $to);
        $recon = $this->reports->reconciliation($creatorId, $from, $to, 'expense');
        $unassigned = $this->reports->unassigned($creatorId, $from, $to);

        $centres = $this->perCentreStatus($creatorId, $fiscalYear, $period, $expense['rows'] ?? []);

        $pendingApprovals = CostCentreBudgetApproval::forCompany($creatorId)
            ->whereIn('status', [
                CostCentreBudgetApproval::STATUS_PENDING,
                CostCentreBudgetApproval::STATUS_MANAGER_APPROVED,
            ])
            ->count();

        $totals = ['approved' => '0.00', 'committed' => '0.00', 'actual' => '0.00', 'available' => '0.00'];
        foreach ($centres as $centre) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] = bcadd($totals[$key], (string) $centre[$key], 2);
            }
        }

        return [
            'fiscal_year' => $fiscalYear,
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency_code' => $this->budgets->baseCurrency($creatorId),
            'centres' => $centres,
            'totals' => $totals,
            'warnings' => $this->warnings($centres),
            'expense_total' => (string) ($expense['total'] ?? '0.00'),
            'cash_total' => (string) ($cash['total'] ?? '0.00'),
            'reconciliation' => [
                'balanced' => (bool) $recon['balanced'],
                'difference' => (string) $recon['difference'],
                'gl_total' => (string) $recon['gl_total'],
                'dimensioned_total' => (string) $recon['dimensioned_total'],
                'lines_checked' => (int) $recon['lines_checked'],
                'mismatches' => count($recon['mismatches']),
                'unassigned' => count($recon['unassigned']),
            ],
            'unassigned_counts' => [
                'missing_dimensions' => count($unassigned['missing_dimensions']),
                'invalid_dimensions' => count($unassigned['invalid_dimensions']),
                'invalid_allocations' => count($unassigned['invalid_allocations']),
            ],
            'pending_approvals' => $pendingApprovals,
        ];
    }

    /**
     * One row per centre, from the bulk sources. A centre appears when
     * it has a budget, a commitment or actuals for the month — a centre
     * with a budget and no spending yet shows approved with actual 0.
     */
    protected function perCentreStatus(int $creatorId, int $fiscalYear, int $period, array $expenseRows): array
    {
        // Actuals: the centre's own dimensioned expense for the month.
        // Rows are per (centre, project), so they are summed; the
        // 'total' (own postings) is used, never 'total_including_children'
        // — children are separate dashboard rows and would double-count.
        $actuals = [];
        foreach ($expenseRows as $row) {
            $centreId = (int) ($row['cost_centre_id'] ?? 0);
            if ($centreId === 0) {
                continue;
            }
            $actuals[$centreId] = bcadd($actuals[$centreId] ?? '0.00', (string) ($row['total'] ?? '0'), 2);
        }

        // Approved budgets for the month, bulk-loaded once.
        $budgets = CostCentreBudget::forCompany($creatorId)
            ->approved()
            ->where('fiscal_year', $fiscalYear)
            ->where('period', $period)
            ->get(['cost_centre_id', 'scope_key', 'approved_amount']);
        $approvedAll = [];
        $approvedScoped = [];
        $budgetRows = [];
        $hasBudget = [];
        foreach ($budgets as $budget) {
            $centreId = (int) $budget->cost_centre_id;
            $hasBudget[$centreId] = true;
            $budgetRows[$centreId] = ($budgetRows[$centreId] ?? 0) + 1;
            if ($budget->scope_key === 'all') {
                $approvedAll[$centreId] = bcadd($approvedAll[$centreId] ?? '0.00', (string) $budget->approved_amount, 2);
            } else {
                $approvedScoped[$centreId] = bcadd($approvedScoped[$centreId] ?? '0.00', (string) $budget->approved_amount, 2);
            }
        }

        // Active commitments for the month, bulk-loaded once, each
        // counted once against its own centre.
        $committed = [];
        $commitmentRows = CostCentreCommitment::forCompany($creatorId)
            ->where('fiscal_year', $fiscalYear)
            ->where('period', $period)
            ->where('status', CostCentreCommitment::STATUS_ACTIVE)
            ->get(['cost_centre_id', 'amount']);
        foreach ($commitmentRows as $commitment) {
            $centreId = (int) $commitment->cost_centre_id;
            $committed[$centreId] = bcadd($committed[$centreId] ?? '0.00', (string) $commitment->amount, 2);
        }

        $names = CostCentre::forCompany($creatorId)->get(['id', 'code', 'name'])->keyBy('id');

        // Every centre of the company shows — even an idle one with no
        // budget, no spending and no commitments — plus any centre id
        // that only appears in the figures (e.g. a deactivated centre
        // that still holds actuals for the month).
        $centreIds = array_unique(array_merge(
            array_keys($names->all()),
            array_keys($actuals),
            array_keys($hasBudget),
            array_keys($committed)
        ));

        $rows = [];
        foreach ($centreIds as $centreId) {
            // A whole-centre budget is the centre's budget; scoped
            // budgets only count when there is no whole-centre one —
            // they are alternative caps for different scopes, not
            // additional money.
            $approved = isset($approvedAll[$centreId])
                ? $approvedAll[$centreId]
                : ($approvedScoped[$centreId] ?? '0.00');
            $actual = $actuals[$centreId] ?? '0.00';
            $committedAmount = $committed[$centreId] ?? '0.00';
            $available = bcsub(bcsub($approved, $committedAmount, 2), $actual, 2);
            $variance = bcsub($actual, $approved, 2);
            $centre = $names->get($centreId);

            $rows[] = [
                'cost_centre_id' => $centreId,
                'cost_centre_code' => $centre?->code ?? '#'.$centreId,
                'cost_centre_name' => $centre?->name,
                'approved' => $approved,
                'committed' => $committedAmount,
                'actual' => $actual,
                'available' => $available,
                'variance' => $variance,
                'variance_percent' => bccomp($approved, '0', 2) === 0
                    ? null
                    : (string) round(((float) $variance / (float) $approved) * 100, 2),
                'budget_rows' => $budgetRows[$centreId] ?? 0,
                'has_budget' => $hasBudget[$centreId] ?? false,
                // 'over' means the centre HAD a budget and exceeded it;
                // a centre with no budget at all is 'no_budget', even
                // though its available figure is negative by construction.
                'status' => ! ($hasBudget[$centreId] ?? false)
                    ? 'no_budget'
                    : (bccomp($available, '0', 2) < 0 ? 'over' : 'within'),
            ];
        }

        // Centres with the worst status first, then by code.
        usort($rows, function ($a, $b) {
            $rank = ['over' => 0, 'no_budget' => 1, 'within' => 2];

            return [$rank[$a['status']] ?? 3, (string) $a['cost_centre_code']]
                <=> [$rank[$b['status']] ?? 3, (string) $b['cost_centre_code']];
        });

        return $rows;
    }

    /**
     * Overspend warnings, worst first, capped at five: centres over
     * budget (by available, most negative first), then centres
     * spending with no budget at all (by actual, largest first).
     */
    protected function warnings(array $centres): array
    {
        $warnings = [];

        $over = array_values(array_filter($centres, fn ($c) => $c['status'] === 'over'));
        usort($over, fn ($a, $b) => bccomp((string) $a['available'], (string) $b['available'], 2));
        foreach (array_slice($over, 0, 5) as $centre) {
            $warnings[] = [
                'cost_centre_id' => $centre['cost_centre_id'],
                'cost_centre_code' => $centre['cost_centre_code'],
                'cost_centre_name' => $centre['cost_centre_name'],
                'kind' => 'over',
                'amount' => (string) $centre['available'],
                'message' => __('Over budget by :amount.', ['amount' => number_format((float) ltrim((string) $centre['available'], '-'), 2)]),
            ];
        }

        if (count($warnings) < 5) {
            $noBudget = array_values(array_filter($centres, fn ($c) => $c['status'] === 'no_budget' && bccomp((string) $c['actual'], '0', 2) > 0));
            usort($noBudget, fn ($a, $b) => bccomp((string) $b['actual'], (string) $a['actual'], 2));
            foreach (array_slice($noBudget, 0, 5 - count($warnings)) as $centre) {
                $warnings[] = [
                    'cost_centre_id' => $centre['cost_centre_id'],
                    'cost_centre_code' => $centre['cost_centre_code'],
                    'cost_centre_name' => $centre['cost_centre_name'],
                    'kind' => 'no_budget',
                    'amount' => (string) $centre['actual'],
                    'message' => __('Spending :amount with no budget for this month.', ['amount' => number_format((float) $centre['actual'], 2)]),
                ];
            }
        }

        return $warnings;
    }
}
