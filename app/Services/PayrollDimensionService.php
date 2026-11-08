<?php

namespace App\Services;

use App\Exceptions\CostCentreException;
use App\Models\CostCentre;
use App\Models\DimensionAuditEvent;
use App\Models\JournalItem;
use App\Models\JournalLineDimension;
use App\Models\PaySlip;
use App\Models\PayslipCostCentreSplit;
use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cost Centre Phase 7 — HR payroll.
 *
 * A payslip's gross salary expense (basic + allowance + commission +
 * other payment + overtime — the same lines the payroll journal
 * debits) can be split across cost centres by percentage. The split
 * is validated by the Phase 1 AllocationEngine (percentages sum to
 * 100.000000%, residual on the deterministic final line) and then
 * tagged onto each Dr class-6 line of the posted payroll journal
 * via DimensionPostingService — dimensions beside the money only,
 * the posted amounts never change.
 *
 * One project per split: DimensionPostingService tags a whole line
 * under a single project, so all rows of one payslip split share
 * the split's project (or none).
 */
class PayrollDimensionService
{
    /** JSON payslip modules that become Dr expense lines. */
    public const EXPENSE_MODULES = ['allowance', 'commission', 'other_payment', 'overtime'];

    public function __construct(
        protected AllocationEngine $engine = new AllocationEngine(),
        protected DimensionPostingService $posting = new DimensionPostingService(),
        protected CostCentreService $centres = new CostCentreService(),
    ) {
    }

    /**
     * Gross salary expense of a payslip: basic + the earning JSON
     * modules. Mirrors PayrollAccountingService::moduleAmount so the
     * split total always equals the posted Dr expense total.
     */
    public function grossExpenseTotal(PaySlip $payslip): string
    {
        $basic = (float) ($payslip->basic_salary ?? 0);
        $total = $basic;

        foreach (self::EXPENSE_MODULES as $module) {
            $json = $payslip->{$module} ?? null;
            if (empty($json)) {
                continue;
            }
            $rows = json_decode($json, true);
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $total += $this->moduleAmount($row, $module, $basic);
            }
        }

        return number_format($total, 2, '.', '');
    }

    /**
     * Store (replace) a payslip's centre split and re-apply it to the
     * posted journal when there is one. Throws CostCentreException
     * when the percentages do not sum to 100%, a centre is invalid,
     * or the payslip has no salary expense.
     *
     * @param  array<int, array{cost_centre_id: int, percentage: string|float}>  $rows
     * @return \Illuminate\Database\Eloquent\Collection<int, PayslipCostCentreSplit>
     */
    public function setSplit(PaySlip $payslip, array $rows, int $userId, ?int $projectId = null)
    {
        $creatorId = (int) $payslip->created_by;

        if (! $this->splitsTableExists()) {
            throw new CostCentreException(__('Payslip cost centre splits are not available yet.'));
        }

        $gross = $this->grossExpenseTotal($payslip);
        if (bccomp($gross, '0', 2) <= 0) {
            throw new CostCentreException(__('This payslip has no salary expense to split.'));
        }

        $date = $this->payslipDate($payslip);

        if ($projectId !== null) {
            $exists = Project::where('id', $projectId)->where('created_by', $creatorId)->exists();
            if (! $exists) {
                throw new CostCentreException(__('Project not found.'));
            }
        }

        $seen = [];
        $engineLines = [];
        foreach ($rows as $index => $row) {
            $centreId = (int) ($row['cost_centre_id'] ?? 0);
            if (isset($seen[$centreId])) {
                throw new CostCentreException(__('Each cost centre can appear only once in a split.'));
            }
            $seen[$centreId] = true;

            $centre = CostCentre::find($centreId);
            if (! $centre) {
                throw new CostCentreException(__('The selected cost centre does not exist.'));
            }
            if ((int) $centre->created_by !== $creatorId) {
                throw new CostCentreException(__('Cost centre :code belongs to another company.', ['code' => $centre->code]));
            }
            $this->centres->assertCanAcceptTransactions($centre, $date);

            $engineLines[] = [
                'cost_centre_id' => $centreId,
                'percentage' => number_format((float) ($row['percentage'] ?? 0), 6, '.', ''),
                'fixed_amount' => null,
                'sort_order' => $index,
            ];
        }

        // Validates the 100% sum and computes each row's amount with
        // the residual on the deterministic final line.
        $computed = $this->engine->compute($gross, $engineLines, 'percentage');

        $stored = DB::transaction(function () use ($payslip, $creatorId, $computed, $projectId, $userId) {
            PayslipCostCentreSplit::forPayslip((int) $payslip->id)->delete();

            foreach ($computed['lines'] as $row) {
                PayslipCostCentreSplit::create([
                    'company_id' => $creatorId,
                    'created_by' => $creatorId,
                    'payslip_id' => (int) $payslip->id,
                    'cost_centre_id' => (int) $row['cost_centre_id'],
                    'project_id' => $projectId,
                    'percentage' => $row['percentage'],
                    'amount' => $row['allocated_amount'],
                    'updated_by' => $userId,
                ]);
            }

            return PayslipCostCentreSplit::forPayslip((int) $payslip->id)->get();
        });

        $this->applyForPayslip($payslip, $userId);

        return $stored;
    }

    /**
     * Tag the stored split onto every Dr class-6 line of the payslip's
     * posted payroll journal (source 'payroll'). Idempotent:
     * attachToLine supersedes the previous rows. No split or no
     * posted journal → no-op, returns 0.
     */
    public function applyForPayslip(PaySlip $payslip, int $userId): int
    {
        if (! $this->splitsTableExists() || empty($payslip->payroll_journal_id)) {
            return 0;
        }

        $splits = PayslipCostCentreSplit::forPayslip((int) $payslip->id)->get();
        if ($splits->isEmpty()) {
            return 0;
        }

        $projectId = $splits->first()->project_id !== null ? (int) $splits->first()->project_id : null;

        $engineLines = [];
        foreach ($splits as $index => $split) {
            $engineLines[] = [
                'cost_centre_id' => (int) $split->cost_centre_id,
                'percentage' => number_format((float) $split->percentage, 6, '.', ''),
                'fixed_amount' => null,
                'sort_order' => $index,
            ];
        }

        $items = JournalItem::query()
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_items.account')
            ->where('journal_items.journal', (int) $payslip->payroll_journal_id)
            ->where('journal_items.debit', '>', 0)
            ->where('chart_of_accounts.code', 'like', '6%')
            ->select('journal_items.*')
            ->get();

        $written = 0;
        foreach ($items as $item) {
            $after = $this->posting->attachToLine(
                JournalLineDimension::LINE_JOURNAL_ITEM,
                (int) $item->id,
                $engineLines,
                'percentage',
                (int) $payslip->created_by,
                $userId,
                $projectId,
                JournalLineDimension::SOURCE_PAYROLL,
            );
            $written += count($after['rows'] ?? []);
        }

        return $written;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, PayslipCostCentreSplit>
     */
    public function splitsForPayslip(PaySlip $payslip)
    {
        if (! $this->splitsTableExists()) {
            return collect();
        }

        return PayslipCostCentreSplit::forPayslip((int) $payslip->id)->get();
    }

    public function splitsTableExists(): bool
    {
        // Live check on every call, never memoized.
        return Schema::hasTable('payslip_cost_centre_splits');
    }

    protected function payslipDate(PaySlip $payslip): Carbon
    {
        if (! empty($payslip->salary_month)) {
            try {
                return Carbon::parse($payslip->salary_month)->startOfMonth();
            } catch (\Throwable) {
                // Fall through to today.
            }
        }

        return Carbon::today();
    }

    /**
     * Mirrors PayrollAccountingService::moduleAmount exactly — same
     * formula, so the split total equals the posted journal total.
     */
    protected function moduleAmount(array $row, string $module, float $basicSalary): float
    {
        if ($module === 'overtime') {
            return round((float) ($row['number_of_days'] ?? 0) * (float) ($row['hours'] ?? 0) * (float) ($row['rate'] ?? 0), 2);
        }

        $amount = (float) ($row['amount'] ?? 0);
        if (($row['type'] ?? 'fixed') === 'percentage') {
            return round($amount * $basicSalary / 100, 2);
        }

        return round($amount, 2);
    }
}
