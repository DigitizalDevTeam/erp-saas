<?php

namespace App\Services;

use App\Exceptions\CostCentreException;
use App\Models\CostCentre;
use App\Models\CostCentreBudget;
use App\Models\CostCentreBudgetApproval;
use App\Models\CostCentreBudgetDelegation;
use App\Models\CostCentreCommitment;
use App\Models\DimensionAuditEvent;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Budget control (Cost Centre Phase 5, plan §9, spec §9, decisions
 * D3 + D5).
 *
 * One service for the whole budget lifecycle:
 *  - budgets per company + fiscal year + month + centre (+ optional GL
 *    account / project), approved / committed / actual / available
 *    tracked as SEPARATE figures and never mixed;
 *  - policy off / warn / block / approval (company setting
 *    `budget_policy`, Phase 1 — it warned that no budget data existed;
 *    now the data exists, so the policy is real);
 *  - commitments: a purchase order (or equivalent) reserves budget at
 *    PO stage; the invoice releases the commitment and the actual goes
 *    up through the dimensioned GL actuals — the same money is never
 *    counted twice;
 *  - approval chain requester → cost centre manager → finance
 *    controller, with a per-company threshold (small amounts skip the
 *    controller step, D3) and delegated approvers, time-stamped
 *    decisions, no self-approval — enforced here, not only in the
 *    blade.
 *
 * Fiscal year (D5): the app has no fiscal-year configuration of its
 * own — the year-end close is FY-{year}-CLOSE on Dec 31 — so the
 * budget year IS the calendar year and the period is the month (1–12).
 *
 * Scope rule (most-specific-first): a budget with an account covers
 * only that account; a budget with a project covers only that project;
 * a budget with neither covers the whole centre. A check uses the
 * most specific approved budget that matches the expense. A
 * commitment counts against a budget only when its scope sits inside
 * the budget's scope. When no approved budget matches a scope at all,
 * the check passes with reason 'no_budget' — budgets are per month and
 * a company may budget only some centres; blocking every unbudgeted
 * expense would make the policy unusable until every centre and month
 * is budgeted.
 *
 * The Purchase-flow wiring itself is NOT in this phase: the
 * Purchasing integration is v3 (plan Phase 7). This service is the
 * contract — reserveCommitment() at PO stage, convertCommitment() at
 * invoice — and the budget UI exercises it.
 */
class BudgetControlService
{
    public function __construct(
        protected DimensionReportService $reports = new DimensionReportService(),
        protected DimensionPostingService $posting = new DimensionPostingService(),
    ) {}

    // ------------------------------------------------------------------
    // Fiscal year + period (D5: calendar year, month 1–12)
    // ------------------------------------------------------------------

    public static function fiscalYearForDate(Carbon $date): int
    {
        return (int) $date->format('Y');
    }

    public static function periodForDate(Carbon $date): int
    {
        return (int) $date->format('n');
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public static function monthRange(int $fiscalYear, int $period): array
    {
        $from = Carbon::create($fiscalYear, $period, 1)->startOfDay()->startOfMonth();
        $to = $from->copy()->endOfMonth()->endOfDay();

        return [$from, $to];
    }

    public function baseCurrency(int $creatorId): string
    {
        return $this->posting->baseCurrency($creatorId);
    }

    // ------------------------------------------------------------------
    // Budgets — CRUD
    // ------------------------------------------------------------------

    public function createBudget(int $creatorId, array $data, int $userId): CostCentreBudget
    {
        $centre = $this->centreForCompany((int) $data['cost_centre_id'], $creatorId);
        $fiscalYear = (int) $data['fiscal_year'];
        $period = (int) $data['period'];
        $glAccountId = isset($data['gl_account_id']) && $data['gl_account_id'] !== '' ? (int) $data['gl_account_id'] : null;
        $projectId = isset($data['project_id']) && $data['project_id'] !== '' ? (int) $data['project_id'] : null;
        $amount = $this->amount($data['budget_amount'] ?? 0);

        if ($fiscalYear < 2000 || $fiscalYear > 2100) {
            throw new CostCentreException(__('Fiscal year must be between 2000 and 2100.'));
        }

        if ($period < 1 || $period > 12) {
            throw new CostCentreException(__('Period must be a month (1–12).'));
        }

        if (bccomp($amount, '0', 2) < 0) {
            throw new CostCentreException(__('Budget amount cannot be negative.'));
        }

        // Budgets sit on leaf centres only (D2: parents are summary-only).
        $this->assertCentreCanTakeBudget($centre, $creatorId);

        $scopeKey = CostCentreBudget::scopeKey($glAccountId, $projectId);

        $exists = CostCentreBudget::forCompany($creatorId)
            ->where('fiscal_year', $fiscalYear)
            ->where('period', $period)
            ->where('cost_centre_id', $centre->id)
            ->where('scope_key', $scopeKey)
            ->exists();

        if ($exists) {
            throw new CostCentreException(__('A budget already exists for this centre, month and scope. Edit it instead of creating a second one.'));
        }

        $budget = CostCentreBudget::create([
            'company_id' => $creatorId,
            'created_by' => $creatorId,
            'fiscal_year' => $fiscalYear,
            'period' => $period,
            'cost_centre_id' => $centre->id,
            'gl_account_id' => $glAccountId,
            'project_id' => $projectId,
            'scope_key' => $scopeKey,
            'budget_amount' => $amount,
            'approved_amount' => '0.00',
            'currency_code' => $this->baseCurrency($creatorId),
            'status' => CostCentreBudget::STATUS_DRAFT,
            'updated_by' => $userId,
        ]);

        $this->audit($creatorId, 'cost_centre_budget', $budget->id, 'created', null, $budget->only(['fiscal_year', 'period', 'cost_centre_id', 'scope_key', 'budget_amount']), $userId);

        return $budget;
    }

    /**
     * Only the planned amount can change, and only while the budget is
     * a draft. Scope, year, month and centre are immutable after
     * creation — a different scope is a different budget.
     */
    public function updateBudget(CostCentreBudget $budget, int $creatorId, array $data, int $userId): CostCentreBudget
    {
        $this->assertBudgetCompany($budget, $creatorId);

        if ($budget->status !== CostCentreBudget::STATUS_DRAFT) {
            throw new CostCentreException(__('Only a draft budget can be edited. Reopen an approved budget first.'));
        }

        $amount = $this->amount($data['budget_amount'] ?? $budget->budget_amount);

        if (bccomp($amount, '0', 2) < 0) {
            throw new CostCentreException(__('Budget amount cannot be negative.'));
        }

        $before = $budget->only(['budget_amount', 'status']);
        $budget->update(['budget_amount' => $amount, 'updated_by' => $userId]);
        $this->audit($creatorId, 'cost_centre_budget', $budget->id, 'updated', $before, $budget->only(['budget_amount', 'status']), $userId);

        return $budget->fresh();
    }

    /**
     * Approval sets the approved figure to the planned figure. Only an
     * approved budget limits spending.
     */
    public function approveBudget(CostCentreBudget $budget, int $creatorId, int $userId): CostCentreBudget
    {
        $this->assertBudgetCompany($budget, $creatorId);

        if ($budget->status !== CostCentreBudget::STATUS_DRAFT) {
            throw new CostCentreException(__('Only a draft budget can be approved.'));
        }

        $before = $budget->only(['status', 'approved_amount']);
        $budget->update([
            'status' => CostCentreBudget::STATUS_APPROVED,
            'approved_amount' => $budget->budget_amount,
            'approved_by' => $userId,
            'approved_at' => now(),
            'updated_by' => $userId,
        ]);
        $this->audit($creatorId, 'cost_centre_budget', $budget->id, 'approved', $before, $budget->only(['status', 'approved_amount']), $userId);

        return $budget->fresh();
    }

    /**
     * Reopening an approved budget returns it to draft and zeroes the
     * approved figure, so an approved number can never be silently
     * changed — it must be edited as a draft and approved again.
     */
    public function reopenBudget(CostCentreBudget $budget, int $creatorId, int $userId): CostCentreBudget
    {
        $this->assertBudgetCompany($budget, $creatorId);

        if ($budget->status !== CostCentreBudget::STATUS_APPROVED) {
            throw new CostCentreException(__('Only an approved budget can be reopened.'));
        }

        $before = $budget->only(['status', 'approved_amount']);
        $budget->update([
            'status' => CostCentreBudget::STATUS_DRAFT,
            'approved_amount' => '0.00',
            'approved_by' => null,
            'approved_at' => null,
            'updated_by' => $userId,
        ]);
        $this->audit($creatorId, 'cost_centre_budget', $budget->id, 'reopened', $before, $budget->only(['status', 'approved_amount']), $userId);

        return $budget->fresh();
    }

    // ------------------------------------------------------------------
    // Figures: approved / committed / actual / available
    // ------------------------------------------------------------------

    /**
     * The most specific APPROVED budget matching the scope, or null.
     * Specificity: account + project (3) > account-only (2) >
     * project-only (1) > whole centre (0).
     */
    public function budgetFor(int $creatorId, int $centreId, int $fiscalYear, int $period, ?int $glAccountId = null, ?int $projectId = null): ?CostCentreBudget
    {
        $candidates = CostCentreBudget::forCompany($creatorId)
            ->approved()
            ->where('fiscal_year', $fiscalYear)
            ->where('period', $period)
            ->where('cost_centre_id', $centreId)
            ->where(function ($q) use ($glAccountId, $projectId) {
                // The budget's scope must contain the expense's scope.
                $q->whereNull('gl_account_id');
                if ($glAccountId !== null) {
                    $q->orWhere('gl_account_id', $glAccountId);
                }
            })
            ->where(function ($q) use ($projectId) {
                $q->whereNull('project_id');
                if ($projectId !== null) {
                    $q->orWhere('project_id', $projectId);
                }
            })
            ->get();

        $best = null;
        foreach ($candidates as $candidate) {
            if ($best === null || $candidate->specificity() > $best->specificity()) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * The four figures, always separate: approved (from the matching
     * approved budget, 0 when there is none), committed (active
     * commitments only), actual (dimensioned GL actuals, accrual
     * basis, expense accounts only), available (approved − committed −
     * actual), plus the variance of actual against approved.
     */
    public function figures(int $creatorId, int $centreId, int $fiscalYear, int $period, ?int $glAccountId = null, ?int $projectId = null): array
    {
        $budget = $this->budgetFor($creatorId, $centreId, $fiscalYear, $period, $glAccountId, $projectId);
        $approved = $budget ? (string) $budget->approved_amount : '0.00';
        $committed = $this->committedSlice($creatorId, $centreId, $fiscalYear, $period, $glAccountId, $projectId);
        $actual = $this->actualSlice($creatorId, $centreId, $fiscalYear, $period, $glAccountId, $projectId);
        $available = bcsub(bcsub($approved, $committed, 2), $actual, 2);
        $variance = bcsub($actual, $approved, 2);

        return [
            'budget_id' => $budget?->id,
            'approved' => $approved,
            'committed' => $committed,
            'actual' => $actual,
            'available' => $available,
            'variance' => $variance,
            'variance_percent' => bccomp($approved, '0', 2) === 0 ? null : (string) round(((float) $variance / (float) $approved) * 100, 2),
        ];
    }

    /**
     * Active commitments whose scope sits inside the budget's scope:
     * a commitment with an account counts only against budgets for
     * that account (or the whole centre); a commitment with no account
     * counts only against the whole-centre budget.
     */
    public function committedSlice(int $creatorId, int $centreId, int $fiscalYear, int $period, ?int $glAccountId = null, ?int $projectId = null): string
    {
        $total = '0.00';

        $rows = CostCentreCommitment::forCompany($creatorId)
            ->where('fiscal_year', $fiscalYear)
            ->where('period', $period)
            ->where('cost_centre_id', $centreId)
            ->where('status', CostCentreCommitment::STATUS_ACTIVE)
            ->get(['gl_account_id', 'project_id', 'amount']);

        foreach ($rows as $row) {
            if (! $this->scopeContains($glAccountId, $projectId, $row->gl_account_id, $row->project_id)) {
                continue;
            }

            $total = bcadd($total, (string) $row->amount, 2);
        }

        return $total;
    }

    /**
     * Dimensioned GL actuals for the month, sliced to the budget's
     * scope (Phase 4 expense-by-centre, accrual basis). The report is
     * called once per month and sliced in PHP so the budget-vs-actual
     * report does not run one query per budget row.
     */
    public function actualSlice(int $creatorId, int $centreId, int $fiscalYear, int $period, ?int $glAccountId = null, ?int $projectId = null): string
    {
        [$from, $to] = self::monthRange($fiscalYear, $period);
        $report = $this->reports->expenseByCentre($creatorId, $from, $to, $centreId, $projectId);

        return $this->sliceActualRows($report['rows'] ?? [], $glAccountId);
    }

    /**
     * Sum the actual rows that fall inside the budget's scope. Rows are
     * per (centre, project) with an accounts breakdown; a budget with
     * no project covers every project row, and a budget with no account
     * covers every account inside the row.
     */
    protected function sliceActualRows(array $rows, ?int $glAccountId): string
    {
        $total = '0.00';

        foreach ($rows as $row) {
            $accounts = $row['accounts'] ?? [];

            if ($glAccountId === null) {
                $total = bcadd($total, (string) ($row['total'] ?? '0'), 2);
                continue;
            }

            foreach ($accounts as $account) {
                if ((int) ($account['account_id'] ?? 0) === $glAccountId) {
                    $total = bcadd($total, (string) ($account['total'] ?? '0'), 2);
                }
            }
        }

        return $total;
    }

    /**
     * Does the budget scope (outer) contain the commitment/expense
     * scope (inner)? A null outer side means "everything"; a null
     * inner side means "whole centre" and only fits an outer null.
     */
    protected function scopeContains(?int $outerAccount, ?int $outerProject, ?int $innerAccount, ?int $innerProject): bool
    {
        if ($outerAccount !== null && $innerAccount !== $outerAccount) {
            return false;
        }

        if ($outerAccount === null && $innerAccount !== null) {
            return false;
        }

        if ($outerProject !== null && $innerProject !== $outerProject) {
            return false;
        }

        if ($outerProject === null && $innerProject !== null) {
            return false;
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Policy check — off / warn / block / approval
    // ------------------------------------------------------------------

    public function policy(int $creatorId): string
    {
        $policy = CostCentreSettings::get($creatorId, CostCentreSettings::KEY_BUDGET_POLICY) ?? 'off';

        return in_array($policy, CostCentreSettings::BUDGET_POLICIES, true) ? $policy : 'off';
    }

    /**
     * Check a new expense (or commitment) against the budget. Returns
     * the decision; it never throws for an over-budget amount — the
     * caller decides what a 'blocked' or 'needs_approval' outcome
     * means for its flow. reserveCommitment() turns 'blocked' into an
     * exception and 'needs_approval' into a pending commitment.
     */
    public function check(int $creatorId, int $centreId, string|float $amount, int $requesterId, ?Carbon $date = null, ?int $glAccountId = null, ?int $projectId = null, ?string $reason = null): array
    {
        $centre = $this->centreForCompany($centreId, $creatorId);
        $date = ($date ?? Carbon::today())->copy();
        $amount = $this->amount($amount);
        $fiscalYear = self::fiscalYearForDate($date);
        $period = self::periodForDate($date);
        $policy = $this->policy($creatorId);

        $this->assertCentreCanTakeBudget($centre, $creatorId, $date);

        if (bccomp($amount, '0', 2) <= 0) {
            throw new CostCentreException(__('Amount must be greater than zero.'));
        }

        $figures = $this->figures($creatorId, $centreId, $fiscalYear, $period, $glAccountId, $projectId);
        $base = [
            'policy' => $policy,
            'fiscal_year' => $fiscalYear,
            'period' => $period,
            'budget_id' => $figures['budget_id'],
            'approved' => $figures['approved'],
            'committed' => $figures['committed'],
            'actual' => $figures['actual'],
            'available' => $figures['available'],
            'amount' => $amount,
            'over_by' => '0.00',
            'approval_id' => null,
            'warning' => null,
            'reason' => null,
        ];

        if ($policy === 'off') {
            $base['decision'] = 'allowed';

            return $base;
        }

        if ($figures['budget_id'] === null) {
            // No approved budget matches this scope: the check passes
            // and says so. Budgets are per month and a company may
            // budget only some centres; blocking every unbudgeted
            // expense would make the policy unusable until every centre
            // and month is budgeted.
            $base['decision'] = 'allowed';
            $base['reason'] = 'no_budget';

            return $base;
        }

        if (bccomp($amount, $figures['available'], 2) <= 0) {
            $base['decision'] = 'allowed';

            return $base;
        }

        $base['over_by'] = bcsub($amount, $figures['available'], 2);

        if ($policy === 'warn') {
            $base['decision'] = 'allowed';
            $base['warning'] = __('Over budget by :amount (available :available). The expense was allowed and the warning was recorded.', [
                'amount' => $base['over_by'],
                'available' => $figures['available'],
            ]);
            $this->audit($creatorId, 'cost_centre_budget', (int) $figures['budget_id'], 'budget_warning', $figures, ['amount' => $amount, 'over_by' => $base['over_by'], 'requested_by' => $requesterId], $requesterId);

            return $base;
        }

        if ($policy === 'block') {
            $base['decision'] = 'blocked';
            $base['warning'] = __('Over budget by :amount (available :available).', [
                'amount' => $base['over_by'],
                'available' => $figures['available'],
            ]);

            return $base;
        }

        // approval: one pending request per (scope, requester, amount)
        // — asking twice for the same spend must not create two
        // requests.
        $approval = $this->requestApproval(
            $creatorId, $centreId, $amount, $requesterId, $date, $glAccountId, $projectId, $reason, $figures['budget_id']
        );
        $base['decision'] = $approval->status === CostCentreBudgetApproval::STATUS_APPROVED ? 'allowed' : 'needs_approval';
        $base['approval_id'] = $approval->id;
        $base['warning'] = $base['decision'] === 'needs_approval'
            ? __('Over budget by :amount. Sent for approval.', ['amount' => $base['over_by']])
            : null;

        return $base;
    }

    // ------------------------------------------------------------------
    // Commitments — reserve at PO stage, release/convert at invoice
    // ------------------------------------------------------------------

    public function reserveCommitment(int $creatorId, int $centreId, string|float $amount, string $sourceType, int $sourceId, int $requesterId, ?Carbon $date = null, ?int $glAccountId = null, ?int $projectId = null, ?string $reason = null): CostCentreCommitment
    {
        if (! in_array($sourceType, CostCentreCommitment::SOURCES, true)) {
            throw new CostCentreException(__('Unknown commitment source.'));
        }

        $date = ($date ?? Carbon::today())->copy();
        $fiscalYear = self::fiscalYearForDate($date);
        $period = self::periodForDate($date);
        $scopeKey = CostCentreBudget::scopeKey($glAccountId, $projectId);

        // Idempotency: the same source reserves once per centre+scope.
        $existing = CostCentreCommitment::forCompany($creatorId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('cost_centre_id', $centreId)
            ->where('scope_key', $scopeKey)
            ->where('status', '!=', CostCentreCommitment::STATUS_CANCELLED)
            ->first();

        if ($existing) {
            return $existing;
        }

        $check = $this->check($creatorId, $centreId, $amount, $requesterId, $date, $glAccountId, $projectId, $reason);

        if ($check['decision'] === 'blocked') {
            throw new CostCentreException($check['warning'] ?? __('Over budget.'));
        }

        return DB::transaction(function () use ($creatorId, $centreId, $amount, $sourceType, $sourceId, $requesterId, $date, $fiscalYear, $period, $glAccountId, $projectId, $scopeKey, $reason, $check) {
            $commitment = CostCentreCommitment::create([
                'company_id' => $creatorId,
                'created_by' => $creatorId,
                'fiscal_year' => $fiscalYear,
                'period' => $period,
                'cost_centre_id' => $centreId,
                'gl_account_id' => $glAccountId,
                'project_id' => $projectId,
                'scope_key' => $scopeKey,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'amount' => $this->amount($amount),
                'currency_code' => $this->baseCurrency($creatorId),
                'status' => $check['decision'] === 'needs_approval'
                    ? CostCentreCommitment::STATUS_PENDING_APPROVAL
                    : CostCentreCommitment::STATUS_ACTIVE,
                'approval_id' => $check['approval_id'],
                'requested_by' => $requesterId,
                'reason' => $reason,
            ]);

            if ($check['approval_id'] !== null) {
                CostCentreBudgetApproval::where('id', $check['approval_id'])
                    ->update(['commitment_id' => $commitment->id]);
            }

            $this->audit($creatorId, 'cost_centre_commitment', $commitment->id, 'reserved', null, $commitment->only(['fiscal_year', 'period', 'cost_centre_id', 'scope_key', 'source_type', 'source_id', 'amount', 'status']), $requesterId);

            return $commitment;
        });
    }

    /**
     * Release every active commitment for a source (the invoice has
     * arrived). Idempotent: releasing twice releases nothing the
     * second time. The actual rises through the dimensioned GL
     * actuals, so the money is counted once — never as committed AND
     * actual.
     */
    public function releaseCommitment(int $creatorId, string $sourceType, int $sourceId, int $userId): int
    {
        $released = 0;

        $commitments = CostCentreCommitment::forCompany($creatorId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', CostCentreCommitment::STATUS_ACTIVE)
            ->get();

        foreach ($commitments as $commitment) {
            $commitment->update([
                'status' => CostCentreCommitment::STATUS_RELEASED,
                'released_by' => $userId,
                'released_at' => now(),
            ]);
            $this->audit($creatorId, 'cost_centre_commitment', $commitment->id, 'released', ['status' => 'active'], ['status' => 'released'], $userId);
            $released++;
        }

        return $released;
    }

    /**
     * Convert a commitment into its invoice: same release as above,
     * plus the bill link so the trace from PO to invoice survives.
     */
    public function convertCommitment(int $creatorId, string $sourceType, int $sourceId, int $billId, int $userId): int
    {
        $converted = 0;

        $commitments = CostCentreCommitment::forCompany($creatorId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', CostCentreCommitment::STATUS_ACTIVE)
            ->get();

        foreach ($commitments as $commitment) {
            $commitment->update([
                'status' => CostCentreCommitment::STATUS_CONVERTED,
                'converted_bill_id' => $billId,
                'released_by' => $userId,
                'released_at' => now(),
            ]);
            $this->audit($creatorId, 'cost_centre_commitment', $commitment->id, 'converted', ['status' => 'active'], ['status' => 'converted', 'converted_bill_id' => $billId], $userId);
            $converted++;
        }

        return $converted;
    }

    public function cancelCommitment(CostCentreCommitment $commitment, int $creatorId, int $userId): CostCentreCommitment
    {
        $this->assertCommitmentCompany($commitment, $creatorId);

        if (! in_array($commitment->status, [CostCentreCommitment::STATUS_ACTIVE, CostCentreCommitment::STATUS_PENDING_APPROVAL], true)) {
            throw new CostCentreException(__('Only an active or pending commitment can be cancelled.'));
        }

        return DB::transaction(function () use ($commitment, $creatorId, $userId) {
            $before = $commitment->only(['status']);
            $commitment->update(['status' => CostCentreCommitment::STATUS_CANCELLED]);
            $this->audit($creatorId, 'cost_centre_commitment', $commitment->id, 'cancelled', $before, ['status' => 'cancelled'], $userId);

            // A pending approval for a cancelled commitment dies with
            // it — nobody should approve money that no longer exists.
            if ($commitment->approval_id !== null) {
                $approval = CostCentreBudgetApproval::find($commitment->approval_id);
                if ($approval && ! $approval->isDecided()) {
                    $approval->update([
                        'status' => CostCentreBudgetApproval::STATUS_REJECTED,
                        'auto_note' => __('Commitment cancelled.'),
                    ]);
                    $this->audit($creatorId, 'cost_centre_budget_approval', $approval->id, 'rejected', ['status' => $approval->status], ['status' => 'rejected', 'auto_note' => 'Commitment cancelled.'], $userId);
                }
            }

            return $commitment->fresh();
        });
    }

    // ------------------------------------------------------------------
    // Approval chain — requester → manager → finance controller
    // ------------------------------------------------------------------

    /**
     * Who is the finance controller for this company? The user named
     * in the company setting, when it names a user of this company;
     * otherwise the company user itself.
     */
    public function resolveController(int $creatorId): ?int
    {
        $configured = CostCentreSettings::get($creatorId, CostCentreSettings::KEY_BUDGET_FINANCE_CONTROLLER);
        $configuredId = $configured !== null && $configured !== '' ? (int) $configured : null;

        if ($configuredId !== null && $this->isCompanyUser($configuredId, $creatorId)) {
            return $configuredId;
        }

        return User::where('id', $creatorId)->exists() ? $creatorId : null;
    }

    /**
     * The amount at or below which the controller step is skipped
     * (D3: small amounts skip a step). Default 0 — every over-budget
     * amount needs both steps until the company sets a threshold.
     */
    public function approvalThreshold(int $creatorId): string
    {
        $value = CostCentreSettings::get($creatorId, CostCentreSettings::KEY_BUDGET_APPROVAL_THRESHOLD);

        if ($value === null || $value === '' || ! is_numeric($value) || (float) $value < 0) {
            return '0.00';
        }

        return $this->amount($value);
    }

    public function requestApproval(int $creatorId, int $centreId, string|float $amount, int $requesterId, ?Carbon $date = null, ?int $glAccountId = null, ?int $projectId = null, ?string $reason = null, ?int $budgetId = null, ?int $commitmentId = null): CostCentreBudgetApproval
    {
        $centre = $this->centreForCompany($centreId, $creatorId);
        $date = ($date ?? Carbon::today())->copy();
        $amount = $this->amount($amount);
        $fiscalYear = self::fiscalYearForDate($date);
        $period = self::periodForDate($date);
        $scopeKey = CostCentreBudget::scopeKey($glAccountId, $projectId);

        // One pending request per (scope, requester, amount).
        $existing = CostCentreBudgetApproval::forCompany($creatorId)
            ->where('cost_centre_id', $centreId)
            ->where('fiscal_year', $fiscalYear)
            ->where('period', $period)
            ->where('scope_key', $scopeKey)
            ->where('requested_by', $requesterId)
            ->where('amount', $amount)
            ->whereIn('status', [CostCentreBudgetApproval::STATUS_PENDING, CostCentreBudgetApproval::STATUS_MANAGER_APPROVED])
            ->first();

        if ($existing) {
            return $existing;
        }

        $managerId = $this->companyManager($centre);
        $controllerId = $this->resolveController($creatorId);
        $threshold = $this->approvalThreshold($creatorId);

        // A step is not required when its approver IS the requester
        // (no self-approval, ever) — and the controller step is not
        // required for small amounts (D3).
        $requiresManager = $managerId !== null && $managerId !== $requesterId;
        $requiresController = $controllerId !== null
            && $controllerId !== $requesterId
            && bccomp($amount, $threshold, 2) > 0;

        $approval = CostCentreBudgetApproval::create([
            'company_id' => $creatorId,
            'created_by' => $creatorId,
            'cost_centre_id' => $centreId,
            'budget_id' => $budgetId,
            'commitment_id' => $commitmentId,
            'fiscal_year' => $fiscalYear,
            'period' => $period,
            'gl_account_id' => $glAccountId,
            'project_id' => $projectId,
            'scope_key' => $scopeKey,
            'amount' => $amount,
            'currency_code' => $this->baseCurrency($creatorId),
            'reason' => $reason,
            'status' => CostCentreBudgetApproval::STATUS_PENDING,
            'requested_by' => $requesterId,
            'manager_user_id' => $managerId,
            'controller_user_id' => $controllerId,
            'requires_manager' => $requiresManager,
            'requires_controller' => $requiresController,
        ]);

        // The requester holds every approval role: there is nobody
        // else to ask. Auto-approve, and say so on the record — never
        // silently.
        if (! $requiresManager && ! $requiresController) {
            $approval->update([
                'status' => CostCentreBudgetApproval::STATUS_APPROVED,
                'auto_note' => __('Auto-approved: the requester holds every approval role for this centre.'),
            ]);
            $this->audit($creatorId, 'cost_centre_budget_approval', $approval->id, 'auto_approved', ['status' => 'pending'], ['status' => 'approved'], $requesterId);
            $this->activateCommitment($approval, $creatorId, $requesterId);
            return $approval->fresh();
        }

        $this->audit($creatorId, 'cost_centre_budget_approval', $approval->id, 'requested', null, $approval->only(['cost_centre_id', 'amount', 'status', 'requires_manager', 'requires_controller']), $requesterId);

        return $approval;
    }

    /**
     * Decide the waiting step. $decision is 'approved' or 'rejected'.
     * The decider must be the step's approver or one of their active
     * delegates, and never the requester.
     */
    public function decide(CostCentreBudgetApproval $approval, int $creatorId, int $userId, string $decision, ?string $note = null): CostCentreBudgetApproval
    {
        $this->assertApprovalCompany($approval, $creatorId);

        if (! in_array($decision, [CostCentreBudgetApproval::DECISION_APPROVED, CostCentreBudgetApproval::DECISION_REJECTED], true)) {
            throw new CostCentreException(__('Decision must be approved or rejected.'));
        }

        if ($approval->isDecided()) {
            throw new CostCentreException(__('This request has already been decided.'));
        }

        if ($userId === (int) $approval->requested_by) {
            throw new CostCentreException(__('You cannot approve a request you raised yourself.'));
        }

        $step = $approval->currentStep();

        if ($step === null) {
            throw new CostCentreException(__('This request has no step waiting for a decision.'));
        }

        $stepUserId = $step === 'manager' ? (int) $approval->manager_user_id : (int) $approval->controller_user_id;
        $allowed = $this->effectiveDeciders($creatorId, $stepUserId, $step);

        if (! in_array($userId, $allowed, true)) {
            throw new CostCentreException(__('You cannot decide this step.'));
        }

        return DB::transaction(function () use ($approval, $creatorId, $userId, $decision, $note, $step) {
            $before = ['status' => $approval->status];

            if ($step === 'manager') {
                $approval->manager_decided_by = $userId;
                $approval->manager_decided_at = now();
                $approval->manager_decision = $decision;
                $approval->manager_note = $note;
            } else {
                $approval->controller_decided_by = $userId;
                $approval->controller_decided_at = now();
                $approval->controller_decision = $decision;
                $approval->controller_note = $note;
            }

            if ($decision === CostCentreBudgetApproval::DECISION_REJECTED) {
                $approval->status = CostCentreBudgetApproval::STATUS_REJECTED;
                $approval->save();
                $this->audit($creatorId, 'cost_centre_budget_approval', $approval->id, 'rejected', $before, ['status' => 'rejected', 'step' => $step], $userId);
                $this->cancelLinkedCommitment($approval, $creatorId, $userId);

                return $approval->fresh();
            }

            if ($step === 'manager' && $approval->requires_controller) {
                $approval->status = CostCentreBudgetApproval::STATUS_MANAGER_APPROVED;
                $approval->save();
                $this->audit($creatorId, 'cost_centre_budget_approval', $approval->id, 'manager_approved', $before, ['status' => 'manager_approved'], $userId);

                return $approval->fresh();
            }

            $approval->status = CostCentreBudgetApproval::STATUS_APPROVED;
            $approval->save();
            $this->audit($creatorId, 'cost_centre_budget_approval', $approval->id, 'approved', $before, ['status' => 'approved', 'step' => $step], $userId);
            $this->activateCommitment($approval, $creatorId, $userId);

            return $approval->fresh();
        });
    }

    /**
     * The step's approver plus their active delegates for this step.
     * Delegation ADDS an approver — the delegator can still decide.
     */
    public function effectiveDeciders(int $creatorId, int $stepUserId, string $role, ?Carbon $date = null): array
    {
        $date = ($date ?? Carbon::today())->copy();
        $deciders = [$stepUserId];

        $delegates = CostCentreBudgetDelegation::forCompany($creatorId)
            ->where('delegator_user_id', $stepUserId)
            ->where('role', $role)
            ->get();

        foreach ($delegates as $delegation) {
            if (! $delegation->isActiveOn($date)) {
                continue;
            }

            if (! $this->isCompanyUser((int) $delegation->delegate_user_id, $creatorId)) {
                continue;
            }

            $deciders[] = (int) $delegation->delegate_user_id;
        }

        return array_values(array_unique($deciders));
    }

    /**
     * A delegation: one user covers another's approval step for a
     * validity window. The delegate must be a user of this company.
     */
    public function createDelegation(int $creatorId, int $delegatorId, int $delegateId, string $role, ?Carbon $validFrom, ?Carbon $validTo, int $userId): CostCentreBudgetDelegation
    {
        if (! in_array($role, CostCentreBudgetDelegation::ROLES, true)) {
            throw new CostCentreException(__('Delegation role must be manager or controller.'));
        }

        if (! $this->isCompanyUser($delegatorId, $creatorId) || ! $this->isCompanyUser($delegateId, $creatorId)) {
            throw new CostCentreException(__('Delegation must be between users of this company.'));
        }

        if ($delegatorId === $delegateId) {
            throw new CostCentreException(__('A user cannot delegate to themselves.'));
        }

        if ($validFrom && $validTo && $validFrom->greaterThan($validTo)) {
            throw new CostCentreException(__('Delegation cannot end before it starts.'));
        }

        return CostCentreBudgetDelegation::create([
            'company_id' => $creatorId,
            'created_by' => $creatorId,
            'delegator_user_id' => $delegatorId,
            'delegate_user_id' => $delegateId,
            'role' => $role,
            'valid_from' => $validFrom?->toDateString(),
            'valid_to' => $validTo?->toDateString(),
        ]);
    }

    public function deleteDelegation(CostCentreBudgetDelegation $delegation, int $creatorId): void
    {
        if ((int) $delegation->created_by !== $creatorId) {
            throw new CostCentreException(__('Permission denied.'));
        }

        $delegation->delete();
    }

    // ------------------------------------------------------------------
    // Budget vs actual report
    // ------------------------------------------------------------------

    /**
     * Monthly budget vs actual per centre (plan §9). One row per
     * budget (approved figures) plus one row per (centre, month) that
     * has dimensioned actuals but no budget at all — spending without
     * a budget must be visible, not hidden. Variance is actual −
     * approved; a row is 'over' when the available figure is negative.
     */
    public function budgetVsActual(int $creatorId, int $fiscalYear, ?int $period = null, ?int $centreId = null): array
    {
        $months = $period !== null ? [$period] : range(1, 12);
        $rows = [];
        $totals = ['approved' => '0.00', 'committed' => '0.00', 'actual' => '0.00', 'available' => '0.00'];

        $budgets = CostCentreBudget::forCompany($creatorId)
            ->approved()
            ->where('fiscal_year', $fiscalYear)
            ->when($period !== null, fn ($q) => $q->where('period', $period))
            ->when($centreId !== null, fn ($q) => $q->where('cost_centre_id', $centreId))
            ->orderBy('cost_centre_id')
            ->orderBy('period')
            ->get();

        // Actuals + commitments are loaded once per month and sliced in
        // PHP — one report must not run one query per budget row.
        $actualsByMonth = [];
        $commitmentsByMonth = [];
        foreach ($months as $month) {
            [$from, $to] = self::monthRange($fiscalYear, $month);
            $actualsByMonth[$month] = $this->reports->expenseByCentre($creatorId, $from, $to, $centreId, null)['rows'] ?? [];
            $commitmentsByMonth[$month] = CostCentreCommitment::forCompany($creatorId)
                ->where('fiscal_year', $fiscalYear)
                ->where('period', $month)
                ->where('status', CostCentreCommitment::STATUS_ACTIVE)
                ->when($centreId !== null, fn ($q) => $q->where('cost_centre_id', $centreId))
                ->get(['cost_centre_id', 'gl_account_id', 'project_id', 'amount']);
        }

        $coveredCentreMonths = [];

        foreach ($budgets as $budget) {
            $month = (int) $budget->period;
            $centreIdRow = (int) $budget->cost_centre_id;
            $coveredCentreMonths[$centreIdRow.'|'.$month] = true;

            $actual = $this->sliceMonthActuals($actualsByMonth[$month], $centreIdRow, $budget->gl_account_id, $budget->project_id);
            $committed = $this->sliceMonthCommitments($commitmentsByMonth[$month], $centreIdRow, $budget->gl_account_id, $budget->project_id);
            $approved = (string) $budget->approved_amount;
            $available = bcsub(bcsub($approved, $committed, 2), $actual, 2);

            $rows[] = $this->varianceRow($budget, $approved, $committed, $actual, $available, 'budgeted');
        }

        // Centres with actuals but no budget at all for the month.
        foreach ($months as $month) {
            foreach ($actualsByMonth[$month] as $row) {
                $rowCentreId = (int) ($row['cost_centre_id'] ?? 0);

                if ($rowCentreId === 0 || isset($coveredCentreMonths[$rowCentreId.'|'.$month])) {
                    continue;
                }

                $actual = (string) ($row['total'] ?? '0');
                $committed = $this->sliceMonthCommitments($commitmentsByMonth[$month], $rowCentreId, null, null);

                if (bccomp($actual, '0', 2) === 0 && bccomp($committed, '0', 2) === 0) {
                    continue;
                }

                $rows[] = $this->varianceRow(null, '0.00', $committed, $actual, bcsub(bcsub('0.00', $committed, 2), $actual, 2), 'no_budget', $rowCentreId, $month, $fiscalYear, $row['cost_centre_code'] ?? null, $row['cost_centre_name'] ?? null);
            }
        }

        foreach ($rows as $row) {
            $totals['approved'] = bcadd($totals['approved'], $row['approved'], 2);
            $totals['committed'] = bcadd($totals['committed'], $row['committed'], 2);
            $totals['actual'] = bcadd($totals['actual'], $row['actual'], 2);
            $totals['available'] = bcadd($totals['available'], $row['available'], 2);
        }

        return [
            'fiscal_year' => $fiscalYear,
            'period' => $period,
            'currency_code' => $this->baseCurrency($creatorId),
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function varianceRow(?CostCentreBudget $budget, string $approved, string $committed, string $actual, string $available, string $kind, ?int $centreId = null, ?int $period = null, ?int $fiscalYear = null, ?string $code = null, ?string $name = null): array
    {
        $variance = bcsub($actual, $approved, 2);
        $centre = $budget ? $budget->centre : null;

        return [
            'budget_id' => $budget?->id,
            'cost_centre_id' => $budget ? (int) $budget->cost_centre_id : $centreId,
            'cost_centre_code' => $centre?->code ?? $code,
            'cost_centre_name' => $centre?->name ?? $name,
            'fiscal_year' => $budget ? (int) $budget->fiscal_year : $fiscalYear,
            'period' => $budget ? (int) $budget->period : $period,
            'gl_account_id' => $budget?->gl_account_id,
            'project_id' => $budget?->project_id,
            'scope_key' => $budget?->scope_key ?? 'all',
            'approved' => $approved,
            'committed' => $committed,
            'actual' => $actual,
            'available' => $available,
            'variance' => $variance,
            'variance_percent' => bccomp($approved, '0', 2) === 0 ? null : (string) round(((float) $variance / (float) $approved) * 100, 2),
            'status' => $kind === 'no_budget' ? 'no_budget' : (bccomp($available, '0', 2) < 0 ? 'over' : 'within'),
        ];
    }

    protected function sliceMonthActuals(array $rows, int $centreId, ?int $glAccountId, ?int $projectId): string
    {
        $total = '0.00';

        foreach ($rows as $row) {
            if ((int) ($row['cost_centre_id'] ?? 0) !== $centreId) {
                continue;
            }

            if ($projectId !== null && (int) ($row['project_id'] ?? 0) !== $projectId) {
                continue;
            }

            $total = bcadd($total, $this->sliceActualRows([$row], $glAccountId), 2);
        }

        return $total;
    }

    protected function sliceMonthCommitments($rows, int $centreId, ?int $glAccountId, ?int $projectId): string
    {
        $total = '0.00';

        foreach ($rows as $row) {
            if ((int) $row->cost_centre_id !== $centreId) {
                continue;
            }

            if (! $this->scopeContains($glAccountId, $projectId, $row->gl_account_id, $row->project_id)) {
                continue;
            }

            $total = bcadd($total, (string) $row->amount, 2);
        }

        return $total;
    }

    protected function activateCommitment(CostCentreBudgetApproval $approval, int $creatorId, int $userId): void
    {
        if ($approval->commitment_id === null) {
            return;
        }

        $commitment = CostCentreCommitment::find($approval->commitment_id);

        if ($commitment && $commitment->status === CostCentreCommitment::STATUS_PENDING_APPROVAL) {
            $commitment->update(['status' => CostCentreCommitment::STATUS_ACTIVE]);
            $this->audit($creatorId, 'cost_centre_commitment', $commitment->id, 'activated', ['status' => 'pending_approval'], ['status' => 'active'], $userId);
        }
    }

    protected function cancelLinkedCommitment(CostCentreBudgetApproval $approval, int $creatorId, int $userId): void
    {
        if ($approval->commitment_id === null) {
            return;
        }

        $commitment = CostCentreCommitment::find($approval->commitment_id);

        if ($commitment && $commitment->status === CostCentreCommitment::STATUS_PENDING_APPROVAL) {
            $commitment->update(['status' => CostCentreCommitment::STATUS_CANCELLED]);
            $this->audit($creatorId, 'cost_centre_commitment', $commitment->id, 'cancelled', ['status' => 'pending_approval'], ['status' => 'cancelled'], $userId);
        }
    }

    protected function centreForCompany(int $centreId, int $creatorId): CostCentre
    {
        $centre = CostCentre::where('id', $centreId)->where('created_by', $creatorId)->first();

        if (! $centre) {
            throw new CostCentreException(__('Cost centre not found.'));
        }

        return $centre;
    }

    protected function assertCentreCanTakeBudget(CostCentre $centre, int $creatorId, ?Carbon $date = null): void
    {
        // Budgets sit on leaf centres only (D2: parents are
        // summary-only) and only while the centre is active and inside
        // its effective window.
        if (! $centre->canAcceptTransactions($date, CostCentreSettings::parentsSummaryOnly($creatorId))) {
            throw new CostCentreException(__('This cost centre cannot take a budget (inactive, outside its effective dates, or a parent centre).'));
        }
    }

    protected function companyManager(CostCentre $centre): ?int
    {
        if ($centre->manager_user_id === null) {
            return null;
        }

        $managerId = (int) $centre->manager_user_id;

        return $this->isCompanyUser($managerId, (int) $centre->created_by) ? $managerId : null;
    }

    protected function isCompanyUser(int $userId, int $creatorId): bool
    {
        if ($userId === $creatorId) {
            return User::where('id', $userId)->exists();
        }

        return User::where('id', $userId)->where('created_by', $creatorId)->exists();
    }

    protected function assertBudgetCompany(CostCentreBudget $budget, int $creatorId): void
    {
        if ((int) $budget->created_by !== $creatorId) {
            throw new CostCentreException(__('Permission denied.'));
        }
    }

    protected function assertCommitmentCompany(CostCentreCommitment $commitment, int $creatorId): void
    {
        if ((int) $commitment->created_by !== $creatorId) {
            throw new CostCentreException(__('Permission denied.'));
        }
    }

    protected function assertApprovalCompany(CostCentreBudgetApproval $approval, int $creatorId): void
    {
        if ((int) $approval->created_by !== $creatorId) {
            throw new CostCentreException(__('Permission denied.'));
        }
    }

    protected function amount(string|float|int $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * Audit writes are guarded by a live hasTable check on every call
     * (never memoized): the dimension audit table exists since Phase 1,
     * but a minimal schema (unit tests, POS-only installs) must never
     * break a budget write.
     */
    protected function audit(int $creatorId, string $entityType, int $entityId, string $action, ?array $before, ?array $after, ?int $actorId): void
    {
        if (! Schema::hasTable('dimension_audit_events')) {
            return;
        }

        DimensionAuditEvent::record($creatorId, $entityType, $entityId, $action, $before, $after, $actorId);
    }
}
