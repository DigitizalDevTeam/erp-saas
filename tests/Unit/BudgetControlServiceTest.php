<?php

namespace Tests\Unit;

use App\Exceptions\CostCentreException;
use App\Models\CostCentreBudget;
use App\Models\CostCentreBudgetApproval;
use App\Models\CostCentreCommitment;
use App\Models\DimensionAuditEvent;
use App\Services\BudgetControlService;
use App\Services\DimensionReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BudgetControlServiceTest extends TestCase
{
    protected BudgetControlService $service;
    protected StubReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Manager', 'email' => 'manager@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Controller', 'email' => 'controller@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Requester', 'email' => 'requester@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 5, 'name' => 'Delegate', 'email' => 'delegate@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'Outsider', 'email' => 'outsider@test.local', 'type' => 'employee', 'created_by' => 99, 'default_company_id' => 99, 'email_verified_at' => now()],
        ]);

        DB::table('cost_centres')->insert([
            ['id' => 1, 'company_id' => 1, 'created_by' => 1, 'code' => 'LOG', 'name' => 'Logistics', 'manager_user_id' => 2, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'company_id' => 1, 'created_by' => 1, 'code' => 'ADM', 'name' => 'Administration', 'manager_user_id' => 3, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'company_id' => 1, 'created_by' => 1, 'code' => 'OPS', 'name' => 'Operations', 'manager_user_id' => null, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->reports = new StubReportService();
        $this->service = new BudgetControlService($this->reports);

        $this->setPolicy('block');
    }

    protected function tearDown(): void
    {
        foreach (['cost_centre_budget_delegations', 'cost_centre_budget_approvals', 'cost_centre_commitments', 'cost_centre_budgets', 'dimension_audit_events', 'cost_centres', 'settings', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Policy modes
    // ------------------------------------------------------------------

    public function test_check_allowed_when_within_budget(): void
    {
        $this->approveBudget(centreId: 1, amount: '10000.00');
        $this->setActuals([['cost_centre_id' => 1, 'project_id' => null, 'month' => 3, 'total' => '2000.00', 'accounts' => []]]);

        $check = $this->service->check(1, 1, '3000.00', 4, Carbon::create(2026, 3, 10));

        $this->assertSame('allowed', $check['decision']);
        // Available is reported before the new expense: 10000 approved
        // − 2000 actual = 8000. The 3000 being checked is not deducted.
        $this->assertSame('8000.00', $check['available']);
        $this->assertSame('0.00', $check['over_by']);
    }

    public function test_check_without_any_budget_passes_with_no_budget_reason(): void
    {
        $check = $this->service->check(1, 1, '99999.00', 4, Carbon::create(2026, 3, 10));

        $this->assertSame('allowed', $check['decision']);
        $this->assertSame('no_budget', $check['reason']);
    }

    public function test_policy_off_ignores_overrun(): void
    {
        $this->setPolicy('off');
        $this->approveBudget(centreId: 1, amount: '100.00');

        $check = $this->service->check(1, 1, '99999.00', 4, Carbon::create(2026, 3, 10));

        $this->assertSame('allowed', $check['decision']);
        $this->assertSame('off', $check['policy']);
    }

    public function test_policy_warn_allows_and_records_warning(): void
    {
        $this->setPolicy('warn');
        $this->approveBudget(centreId: 1, amount: '1000.00');

        $check = $this->service->check(1, 1, '1500.00', 4, Carbon::create(2026, 3, 10));

        $this->assertSame('allowed', $check['decision']);
        $this->assertSame('500.00', $check['over_by']);
        $this->assertNotNull($check['warning']);
        $this->assertTrue(DimensionAuditEvent::where('action', 'budget_warning')->exists());
    }

    public function test_policy_block_refuses_and_reserve_throws(): void
    {
        $this->approveBudget(centreId: 1, amount: '1000.00');

        $check = $this->service->check(1, 1, '1500.00', 4, Carbon::create(2026, 3, 10));

        $this->assertSame('blocked', $check['decision']);
        $this->assertSame('500.00', $check['over_by']);

        $this->expectException(CostCentreException::class);
        $this->service->reserveCommitment(1, 1, '1500.00', 'purchase', 77, 4, Carbon::create(2026, 3, 10));
    }

    public function test_policy_approval_creates_request_and_pending_commitment(): void
    {
        $this->setPolicy('approval');
        $this->setController(3);
        $this->approveBudget(centreId: 1, amount: '1000.00');

        $commitment = $this->service->reserveCommitment(1, 1, '1500.00', 'purchase', 77, 4, Carbon::create(2026, 3, 10), null, null, 'Urgent fuel');

        $this->assertSame(CostCentreCommitment::STATUS_PENDING_APPROVAL, $commitment->status);
        $this->assertNotNull($commitment->approval_id);

        // A pending commitment reserves nothing.
        $figures = $this->service->figures(1, 1, 2026, 3);
        $this->assertSame('0.00', $figures['committed']);
        $this->assertSame('1000.00', $figures['available']);

        $approval = CostCentreBudgetApproval::find($commitment->approval_id);
        $this->assertSame(CostCentreBudgetApproval::STATUS_PENDING, $approval->status);
        $this->assertTrue($approval->requires_manager);
        $this->assertTrue($approval->requires_controller);

        // Manager approves — still needs the controller (threshold 0).
        $this->service->decide($approval->fresh(), 1, 2, 'approved', 'OK');
        $this->assertSame(CostCentreBudgetApproval::STATUS_MANAGER_APPROVED, $approval->fresh()->status);
        $this->assertSame(CostCentreCommitment::STATUS_PENDING_APPROVAL, $commitment->fresh()->status);

        // Controller approves — the commitment activates and counts.
        $this->service->decide($approval->fresh(), 1, 3, 'approved');
        $this->assertSame(CostCentreBudgetApproval::STATUS_APPROVED, $approval->fresh()->status);
        $this->assertSame(CostCentreCommitment::STATUS_ACTIVE, $commitment->fresh()->status);

        $figures = $this->service->figures(1, 1, 2026, 3);
        $this->assertSame('1500.00', $figures['committed']);
        $this->assertSame('-500.00', $figures['available']);
    }

    // ------------------------------------------------------------------
    // Thresholds, self-approval, delegation
    // ------------------------------------------------------------------

    public function test_small_amount_skips_controller_step(): void
    {
        $this->setPolicy('approval');
        $this->setController(3);
        $this->setThreshold('5000.00');
        $this->approveBudget(centreId: 1, amount: '1000.00');

        $commitment = $this->service->reserveCommitment(1, 1, '3000.00', 'purchase', 78, 4, Carbon::create(2026, 3, 10));
        $approval = CostCentreBudgetApproval::find($commitment->approval_id);

        $this->assertTrue($approval->requires_manager);
        $this->assertFalse($approval->requires_controller);

        $this->service->decide($approval->fresh(), 1, 2, 'approved');
        $this->assertSame(CostCentreBudgetApproval::STATUS_APPROVED, $approval->fresh()->status);
        $this->assertSame(CostCentreCommitment::STATUS_ACTIVE, $commitment->fresh()->status);
    }

    public function test_large_amount_needs_controller_step(): void
    {
        $this->setPolicy('approval');
        $this->setController(3);
        $this->setThreshold('5000.00');
        $this->approveBudget(centreId: 1, amount: '1000.00');

        $commitment = $this->service->reserveCommitment(1, 1, '6000.00', 'purchase', 79, 4, Carbon::create(2026, 3, 10));
        $approval = CostCentreBudgetApproval::find($commitment->approval_id);

        $this->assertTrue($approval->requires_manager);
        $this->assertTrue($approval->requires_controller);
    }

    public function test_requester_cannot_decide_own_request(): void
    {
        $this->setPolicy('approval');
        $this->setController(3);
        $this->approveBudget(centreId: 1, amount: '1000.00');

        // Requester 2 IS the centre manager: the manager step is
        // skipped, the request waits on the controller.
        $approval = $this->service->requestApproval(1, 1, '1500.00', 2, Carbon::create(2026, 3, 10));
        $this->assertFalse($approval->requires_manager);
        $this->assertTrue($approval->requires_controller);

        $this->expectException(CostCentreException::class);
        $this->expectExceptionMessageMatches('/yourself/');
        $this->service->decide($approval, 1, 2, 'approved');
    }

    public function test_wrong_user_cannot_decide(): void
    {
        $this->setPolicy('approval');
        $this->setController(3);
        $this->approveBudget(centreId: 1, amount: '1000.00');

        $approval = $this->service->requestApproval(1, 1, '1500.00', 4, Carbon::create(2026, 3, 10));

        $this->expectException(CostCentreException::class);
        $this->service->decide($approval, 1, 5, 'approved');
    }

    public function test_delegated_approver_can_decide_manager_step(): void
    {
        $this->setPolicy('approval');
        $this->setController(3);
        $this->setThreshold('5000.00');
        $this->approveBudget(centreId: 1, amount: '1000.00');

        // Manager 2 delegates the manager step to user 5, no end date.
        $this->service->createDelegation(1, 2, 5, 'manager', null, null, 1);

        $approval = $this->service->requestApproval(1, 1, '3000.00', 4, Carbon::create(2026, 3, 10));

        $this->service->decide($approval->fresh(), 1, 5, 'approved', 'Covering for manager');
        $this->assertSame(CostCentreBudgetApproval::STATUS_APPROVED, $approval->fresh()->status);
        $this->assertSame(5, (int) $approval->fresh()->manager_decided_by);
    }

    public function test_expired_delegation_does_not_count(): void
    {
        $this->setPolicy('approval');
        $this->setController(3);
        $this->setThreshold('5000.00');
        $this->approveBudget(centreId: 1, amount: '1000.00');

        // Delegation ended in February; the request is in March.
        $this->service->createDelegation(1, 2, 5, 'manager', Carbon::create(2026, 2, 1), Carbon::create(2026, 2, 28), 1);

        $approval = $this->service->requestApproval(1, 1, '3000.00', 4, Carbon::create(2026, 3, 10));

        $this->expectException(CostCentreException::class);
        $this->service->decide($approval, 1, 5, 'approved');
    }

    public function test_auto_approve_when_requester_holds_all_roles(): void
    {
        $this->setPolicy('approval');
        // Centre OPS has no manager; the controller falls back to the
        // company user (1). Requester 1 holds every role.
        $approval = $this->service->requestApproval(1, 3, '500.00', 1, Carbon::create(2026, 3, 10));

        $this->assertSame(CostCentreBudgetApproval::STATUS_APPROVED, $approval->status);
        $this->assertNotNull($approval->auto_note);
    }

    public function test_rejection_cancels_pending_commitment(): void
    {
        $this->setPolicy('approval');
        $this->setController(3);
        $this->approveBudget(centreId: 1, amount: '1000.00');

        $commitment = $this->service->reserveCommitment(1, 1, '1500.00', 'purchase', 80, 4, Carbon::create(2026, 3, 10));
        $approval = CostCentreBudgetApproval::find($commitment->approval_id);

        $this->service->decide($approval->fresh(), 1, 2, 'rejected', 'Too expensive');

        $this->assertSame(CostCentreBudgetApproval::STATUS_REJECTED, $approval->fresh()->status);
        $this->assertSame(CostCentreCommitment::STATUS_CANCELLED, $commitment->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Commitments — no double count, idempotency, release/convert
    // ------------------------------------------------------------------

    public function test_figures_keep_committed_actual_available_separate(): void
    {
        $this->approveBudget(centreId: 1, amount: '10000.00');
        $this->service->reserveCommitment(1, 1, '3000.00', 'purchase', 81, 4, Carbon::create(2026, 3, 10));
        $this->setActuals([['cost_centre_id' => 1, 'project_id' => null, 'month' => 3, 'total' => '2000.00', 'accounts' => []]]);

        $figures = $this->service->figures(1, 1, 2026, 3);

        $this->assertSame('10000.00', $figures['approved']);
        $this->assertSame('3000.00', $figures['committed']);
        $this->assertSame('2000.00', $figures['actual']);
        $this->assertSame('5000.00', $figures['available']);
        $this->assertSame('-8000.00', $figures['variance']);
        $this->assertSame('-80', $figures['variance_percent']);
    }

    public function test_convert_releases_commitment_and_actual_takes_over(): void
    {
        $this->approveBudget(centreId: 1, amount: '10000.00');
        $this->service->reserveCommitment(1, 1, '4000.00', 'purchase', 82, 4, Carbon::create(2026, 3, 10));

        $before = $this->service->figures(1, 1, 2026, 3);
        $this->assertSame('4000.00', $before['committed']);
        $this->assertSame('0.00', $before['actual']);
        $this->assertSame('6000.00', $before['available']);

        // The invoice arrives: commitment converts, actuals now show
        // the same 4000 through the GL dimensions.
        $this->assertSame(1, $this->service->convertCommitment(1, 'purchase', 82, 99, 4));
        $this->setActuals([['cost_centre_id' => 1, 'project_id' => null, 'month' => 3, 'total' => '4000.00', 'accounts' => []]]);

        $after = $this->service->figures(1, 1, 2026, 3);
        $this->assertSame('0.00', $after['committed']);
        $this->assertSame('4000.00', $after['actual']);
        // Available is unchanged: the money moved from committed to
        // actual, it was never counted twice.
        $this->assertSame('6000.00', $after['available']);
    }

    public function test_release_is_idempotent(): void
    {
        $this->service->reserveCommitment(1, 1, '1000.00', 'purchase', 83, 4, Carbon::create(2026, 3, 10));

        $this->assertSame(1, $this->service->releaseCommitment(1, 'purchase', 83, 4));
        $this->assertSame(0, $this->service->releaseCommitment(1, 'purchase', 83, 4));
    }

    public function test_reserve_is_idempotent_per_source(): void
    {
        $first = $this->service->reserveCommitment(1, 1, '1000.00', 'purchase', 84, 4, Carbon::create(2026, 3, 10));
        $second = $this->service->reserveCommitment(1, 1, '1000.00', 'purchase', 84, 4, Carbon::create(2026, 3, 10));

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CostCentreCommitment::where('source_type', 'purchase')->where('source_id', 84)->count());
    }

    public function test_cancel_pending_commitment_rejects_its_approval(): void
    {
        $this->setPolicy('approval');
        $this->setController(3);
        $this->approveBudget(centreId: 1, amount: '1000.00');

        $commitment = $this->service->reserveCommitment(1, 1, '1500.00', 'purchase', 85, 4, Carbon::create(2026, 3, 10));
        $approval = CostCentreBudgetApproval::find($commitment->approval_id);

        $this->service->cancelCommitment($commitment->fresh(), 1, 4);

        $this->assertSame(CostCentreCommitment::STATUS_CANCELLED, $commitment->fresh()->status);
        $this->assertSame(CostCentreBudgetApproval::STATUS_REJECTED, $approval->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Budgets — scope, draft, duplicates
    // ------------------------------------------------------------------

    public function test_most_specific_budget_wins(): void
    {
        $this->approveBudget(centreId: 1, amount: '10000.00');
        $this->approveBudget(centreId: 1, amount: '3000.00', glAccountId: 621);

        // An expense on account 621 is governed by the account budget.
        $figures = $this->service->figures(1, 1, 2026, 3, 621);
        $this->assertSame('3000.00', $figures['approved']);

        // An expense with no account is governed by the centre budget.
        $figures = $this->service->figures(1, 1, 2026, 3);
        $this->assertSame('10000.00', $figures['approved']);
    }

    public function test_draft_budget_counts_as_zero(): void
    {
        $this->service->createBudget(1, [
            'cost_centre_id' => 1, 'fiscal_year' => 2026, 'period' => 3, 'budget_amount' => '10000.00',
        ], 1);

        $figures = $this->service->figures(1, 1, 2026, 3);
        $this->assertNull($figures['budget_id']);
        $this->assertSame('0.00', $figures['approved']);
    }

    public function test_duplicate_budget_scope_rejected(): void
    {
        $this->approveBudget(centreId: 1, amount: '1000.00');

        $this->expectException(CostCentreException::class);
        $this->service->createBudget(1, [
            'cost_centre_id' => 1, 'fiscal_year' => 2026, 'period' => 3, 'budget_amount' => '2000.00',
        ], 1);
    }

    public function test_approved_budget_cannot_be_edited_until_reopened(): void
    {
        $budget = $this->approveBudget(centreId: 1, amount: '1000.00');

        $this->expectException(CostCentreException::class);
        $this->service->updateBudget($budget, 1, ['budget_amount' => '2000.00'], 1);
    }

    public function test_reopen_resets_approved_amount_and_allows_edit(): void
    {
        $budget = $this->approveBudget(centreId: 1, amount: '1000.00');

        $reopened = $this->service->reopenBudget($budget, 1, 1);
        $this->assertSame(CostCentreBudget::STATUS_DRAFT, $reopened->status);
        $this->assertSame('0.00', $reopened->approved_amount);

        $updated = $this->service->updateBudget($reopened, 1, ['budget_amount' => '2000.00'], 1);
        $this->assertSame('2000.00', $updated->budget_amount);

        $approved = $this->service->approveBudget($updated, 1, 1);
        $this->assertSame('2000.00', $approved->approved_amount);
    }

    // ------------------------------------------------------------------
    // Budget vs actual report
    // ------------------------------------------------------------------

    public function test_budget_vs_actual_report_with_variance_and_no_budget_row(): void
    {
        $this->approveBudget(centreId: 1, amount: '10000.00');
        $this->service->reserveCommitment(1, 1, '3000.00', 'purchase', 86, 4, Carbon::create(2026, 3, 10));
        $this->setActuals([
            ['cost_centre_id' => 1, 'project_id' => null, 'month' => 3, 'total' => '2000.00', 'accounts' => []],
            // Centre 2 has actuals but no budget at all.
            ['cost_centre_id' => 2, 'project_id' => null, 'month' => 3, 'total' => '500.00', 'accounts' => []],
        ]);

        $report = $this->service->budgetVsActual(1, 2026, 3);

        $this->assertCount(2, $report['rows']);

        $budgeted = collect($report['rows'])->firstWhere('cost_centre_id', 1);
        $this->assertSame('10000.00', $budgeted['approved']);
        $this->assertSame('3000.00', $budgeted['committed']);
        $this->assertSame('2000.00', $budgeted['actual']);
        $this->assertSame('5000.00', $budgeted['available']);
        $this->assertSame('-8000.00', $budgeted['variance']);
        $this->assertSame('within', $budgeted['status']);

        $unbudgeted = collect($report['rows'])->firstWhere('cost_centre_id', 2);
        $this->assertSame('no_budget', $unbudgeted['status']);
        $this->assertSame('0.00', $unbudgeted['approved']);
        $this->assertSame('500.00', $unbudgeted['actual']);
        $this->assertSame('-500.00', $unbudgeted['available']);

        $this->assertSame('10000.00', $report['totals']['approved']);
        $this->assertSame('2500.00', $report['totals']['actual']);
    }

    public function test_over_budget_row_is_flagged(): void
    {
        $this->approveBudget(centreId: 1, amount: '1000.00');
        $this->setActuals([['cost_centre_id' => 1, 'project_id' => null, 'month' => 3, 'total' => '1500.00', 'accounts' => []]]);

        $report = $this->service->budgetVsActual(1, 2026, 3);

        $this->assertSame('over', $report['rows'][0]['status']);
        $this->assertSame('500.00', $report['rows'][0]['variance']);
        $this->assertSame('50', $report['rows'][0]['variance_percent']);
    }

    // ------------------------------------------------------------------
    // Fiscal year (D5: calendar year)
    // ------------------------------------------------------------------

    public function test_fiscal_year_is_calendar_year(): void
    {
        $this->assertSame(2026, BudgetControlService::fiscalYearForDate(Carbon::create(2026, 3, 15)));
        $this->assertSame(3, BudgetControlService::periodForDate(Carbon::create(2026, 3, 15)));

        [$from, $to] = BudgetControlService::monthRange(2026, 3);
        $this->assertSame('2026-03-01', $from->toDateString());
        $this->assertSame('2026-03-31', $to->toDateString());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function approveBudget(int $centreId, string $amount, ?int $glAccountId = null, ?int $projectId = null): CostCentreBudget
    {
        $budget = $this->service->createBudget(1, [
            'cost_centre_id' => $centreId,
            'fiscal_year' => 2026,
            'period' => 3,
            'gl_account_id' => $glAccountId,
            'project_id' => $projectId,
            'budget_amount' => $amount,
        ], 1);

        return $this->service->approveBudget($budget, 1, 1);
    }

    protected function setPolicy(string $policy): void
    {
        DB::table('settings')->updateOrInsert(
            ['name' => 'budget_policy', 'created_by' => 1],
            ['value' => $policy, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    protected function setThreshold(string $threshold): void
    {
        DB::table('settings')->updateOrInsert(
            ['name' => 'budget_approval_threshold', 'created_by' => 1],
            ['value' => $threshold, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    protected function setController(int $userId): void
    {
        DB::table('settings')->updateOrInsert(
            ['name' => 'budget_finance_controller_user_id', 'created_by' => 1],
            ['value' => (string) $userId, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    /** Canned dimensioned actuals, in the shape expenseByCentre returns. */
    protected function setActuals(array $rows): void
    {
        $this->reports->rows = $rows;
    }

    protected function createTables(): void
    {
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('type', 100)->nullable();
            $table->integer('created_by')->default(0);
            $table->integer('default_company_id')->nullable();
            $table->integer('is_enable_login')->default(1);
            $table->integer('is_active')->default(1);
            $table->integer('delete_status')->default(1);
            $table->string('lang')->default('en');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('settings', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('cost_centres', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('parent_id')->nullable();
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->bigInteger('manager_user_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['created_by', 'code'], 'cc_company_code_uq');
        });
        Schema::create('cost_centre_budgets', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->integer('fiscal_year');
            $table->unsignedTinyInteger('period');
            $table->unsignedBigInteger('cost_centre_id');
            $table->unsignedBigInteger('gl_account_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('scope_key', 60);
            $table->decimal('budget_amount', 18, 2)->default(0);
            $table->decimal('approved_amount', 18, 2)->default(0);
            $table->string('currency_code', 10)->nullable();
            $table->string('status', 20)->default('draft');
            $table->integer('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'fiscal_year', 'period', 'cost_centre_id', 'scope_key'], 'ccb_scope_unique');
        });
        Schema::create('cost_centre_commitments', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->integer('fiscal_year');
            $table->unsignedTinyInteger('period');
            $table->unsignedBigInteger('cost_centre_id');
            $table->unsignedBigInteger('gl_account_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('scope_key', 60);
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id');
            $table->decimal('amount', 18, 2);
            $table->string('currency_code', 10)->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('converted_bill_id')->nullable();
            $table->unsignedBigInteger('approval_id')->nullable();
            $table->integer('requested_by')->nullable();
            $table->integer('released_by')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'source_type', 'source_id', 'cost_centre_id', 'scope_key'], 'ccc_source_unique');
        });
        Schema::create('cost_centre_budget_approvals', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->unsignedBigInteger('cost_centre_id');
            $table->unsignedBigInteger('budget_id')->nullable();
            $table->unsignedBigInteger('commitment_id')->nullable();
            $table->integer('fiscal_year');
            $table->unsignedTinyInteger('period');
            $table->unsignedBigInteger('gl_account_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('scope_key', 60);
            $table->decimal('amount', 18, 2);
            $table->string('currency_code', 10)->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending');
            $table->integer('requested_by');
            $table->integer('manager_user_id')->nullable();
            $table->integer('controller_user_id')->nullable();
            $table->boolean('requires_manager')->default(true);
            $table->boolean('requires_controller')->default(true);
            $table->integer('manager_decided_by')->nullable();
            $table->timestamp('manager_decided_at')->nullable();
            $table->string('manager_decision', 20)->nullable();
            $table->text('manager_note')->nullable();
            $table->integer('controller_decided_by')->nullable();
            $table->timestamp('controller_decided_at')->nullable();
            $table->string('controller_decision', 20)->nullable();
            $table->text('controller_note')->nullable();
            $table->string('auto_note')->nullable();
            $table->timestamps();
        });
        Schema::create('cost_centre_budget_delegations', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->integer('delegator_user_id');
            $table->integer('delegate_user_id');
            $table->string('role', 20);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();
        });
        Schema::create('dimension_audit_events', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('action');
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->integer('actor_id')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });
    }
}

/**
 * DimensionReportService stub: returns canned expense-by-centre rows,
 * filtered by the requested centre / project / month like the real
 * service, so budget figures can be tested without the GL tables.
 */
class StubReportService extends DimensionReportService
{
    public array $rows = [];

    public function expenseByCentre(
        int $creatorId,
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?int $centreId = null,
        ?int $projectId = null
    ): array {
        $month = $from !== null ? (int) $from->format('n') : null;
        $out = [];

        foreach ($this->rows as $row) {
            if ($centreId !== null && (int) $row['cost_centre_id'] !== $centreId) {
                continue;
            }

            if ($projectId !== null && (int) ($row['project_id'] ?? 0) !== $projectId) {
                continue;
            }

            if ($month !== null && isset($row['month']) && (int) $row['month'] !== $month) {
                continue;
            }

            $out[] = [
                'cost_centre_id' => (int) $row['cost_centre_id'],
                'project_id' => $row['project_id'] ?? null,
                'cost_centre_code' => $row['cost_centre_code'] ?? '#'.$row['cost_centre_id'],
                'cost_centre_name' => $row['cost_centre_name'] ?? null,
                'total' => (string) ($row['total'] ?? '0'),
                'rows' => 1,
                'accounts' => $row['accounts'] ?? [],
                'total_including_children' => (string) ($row['total'] ?? '0'),
            ];
        }

        return ['basis' => 'accrual', 'rows' => $out, 'total' => '0.00'];
    }
}
