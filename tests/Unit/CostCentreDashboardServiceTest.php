<?php

namespace Tests\Unit;

use App\Services\CostCentreDashboardService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cost Centre Phase 6 — dashboard aggregation (plan §10/§13).
 *
 * The dashboard counts every figure ONCE per centre: the actual is
 * the centre's dimensioned expense for the month, each commitment is
 * counted once, and a whole-centre budget beats scoped budgets (they
 * are alternative caps, not additive money). The query count must
 * stay FIXED no matter how many centres the company has.
 */
class CostCentreDashboardServiceTest extends TestCase
{
    protected CostCentreDashboardService $dashboard;
    protected int $year;
    protected int $month;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        $this->year = (int) date('Y');
        $this->month = (int) date('n');
        $this->dashboard = new CostCentreDashboardService();

        DB::table('chart_of_accounts')->insert([
            ['id' => 621, 'code' => '621', 'name' => 'Utilities', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->seedCentre(1, 'LOG', 'Logistics');
        $this->seedCentre(2, 'ADM', 'Administration');
        $this->seedCentre(3, 'TEC', 'Technology');
    }

    protected function tearDown(): void
    {
        foreach (['journal_line_dimensions', 'treasury_line_dimensions', 'dimension_audit_events', 'cost_centre_allocation_lines', 'cost_centre_allocations', 'cost_centre_budget_approvals', 'cost_centre_commitments', 'cost_centre_budgets', 'journal_items', 'journal_entries', 'transaction_lines', 'bill_payments', 'chart_of_accounts', 'cost_centres', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_dashboard_figures_per_centre(): void
    {
        // LOG: budget 10,000, commitment 2,000, actual 24,000 → over.
        $this->seedBudget(1, '10000.00');
        $this->seedCommitment(1, '2000.00');
        $this->seedActual(1, 1, '24000.00');
        // ADM: budget 21,000, actual 21,000 → within, available 0.
        $this->seedBudget(2, '21000.00');
        $this->seedActual(2, 2, '21000.00');
        // TEC: no budget, actual 15,000 → no_budget.
        $this->seedActual(3, 3, '15000.00');
        // One pending approval for the company.
        DB::table('cost_centre_budget_approvals')->insert([
            'id' => 1, 'company_id' => 1, 'created_by' => 1, 'cost_centre_id' => 1,
            'fiscal_year' => $this->year, 'period' => $this->month, 'scope_key' => 'all',
            'amount' => '5000.00', 'currency_code' => 'MZN', 'status' => 'pending',
            'requested_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->dashboard->dashboard(1, $this->year, $this->month);

        $this->assertSame('MZN', $result['currency_code']);
        $this->assertSame('31000.00', $result['totals']['approved']);
        $this->assertSame('2000.00', $result['totals']['committed']);
        $this->assertSame('60000.00', $result['totals']['actual']);
        $this->assertSame('-31000.00', $result['totals']['available']);
        $this->assertSame('60000.00', $result['expense_total']);
        $this->assertSame('0.00', $result['cash_total']);
        $this->assertTrue($result['reconciliation']['balanced']);
        $this->assertSame('0.00', $result['reconciliation']['difference']);
        $this->assertSame(3, $result['reconciliation']['lines_checked']);
        $this->assertSame(0, $result['unassigned_counts']['missing_dimensions']);
        $this->assertSame(1, $result['pending_approvals']);

        $byCode = [];
        foreach ($result['centres'] as $row) {
            $byCode[$row['cost_centre_code']] = $row;
        }

        $this->assertSame('over', $byCode['LOG']['status']);
        $this->assertSame('10000.00', $byCode['LOG']['approved']);
        $this->assertSame('2000.00', $byCode['LOG']['committed']);
        $this->assertSame('24000.00', $byCode['LOG']['actual']);
        $this->assertSame('-16000.00', $byCode['LOG']['available']);
        $this->assertSame('14000.00', $byCode['LOG']['variance']);
        $this->assertSame('140', $byCode['LOG']['variance_percent']);

        $this->assertSame('within', $byCode['ADM']['status']);
        $this->assertSame('0.00', $byCode['ADM']['available']);

        $this->assertSame('no_budget', $byCode['TEC']['status']);
        $this->assertSame('0.00', $byCode['TEC']['approved']);
        $this->assertSame('-15000.00', $byCode['TEC']['available']);

        // Worst status first: LOG (over), TEC (no_budget), ADM (within).
        $this->assertSame(['LOG', 'TEC', 'ADM'], array_column($result['centres'], 'cost_centre_code'));

        // Warnings: over first, then spending without a budget.
        $this->assertCount(2, $result['warnings']);
        $this->assertSame('over', $result['warnings'][0]['kind']);
        $this->assertSame('LOG', $result['warnings'][0]['cost_centre_code']);
        $this->assertSame('no_budget', $result['warnings'][1]['kind']);
        $this->assertSame('TEC', $result['warnings'][1]['cost_centre_code']);
    }

    public function test_centre_with_budget_but_no_spending_still_shows(): void
    {
        $this->seedBudget(1, '5000.00');

        $result = $this->dashboard->dashboard(1, $this->year, $this->month);

        // All three seeded centres show — LOG with its budget, ADM and
        // TEC idle (no budget, no spending, no warning).
        $this->assertCount(3, $result['centres']);
        $byCode = [];
        foreach ($result['centres'] as $row) {
            $byCode[$row['cost_centre_code']] = $row;
        }
        $this->assertSame('5000.00', $byCode['LOG']['approved']);
        $this->assertSame('0.00', $byCode['LOG']['actual']);
        $this->assertSame('5000.00', $byCode['LOG']['available']);
        $this->assertSame('within', $byCode['LOG']['status']);
        $this->assertSame('no_budget', $byCode['ADM']['status']);
        $this->assertSame('no_budget', $byCode['TEC']['status']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_whole_centre_budget_beats_scoped_budgets_and_actual_counts_once(): void
    {
        // LOG holds a whole-centre budget (10,000) AND an account-scoped
        // budget (5,000 for account 621). The scoped one is an
        // alternative cap, not extra money — and the 24,000 actual must
        // be counted once, not once per budget row.
        $this->seedBudget(1, '10000.00');
        DB::table('cost_centre_budgets')->insert([
            'company_id' => 1, 'created_by' => 1, 'fiscal_year' => $this->year, 'period' => $this->month,
            'cost_centre_id' => 1, 'gl_account_id' => 621, 'project_id' => null, 'scope_key' => 'gl:621',
            'budget_amount' => '5000.00', 'approved_amount' => '5000.00', 'currency_code' => 'MZN',
            'status' => 'approved', 'approved_by' => 1, 'approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->seedActual(1, 1, '24000.00');

        $result = $this->dashboard->dashboard(1, $this->year, $this->month);

        // LOG first (over), then the two idle centres; the scoped
        // budget never inflates the approved figure.
        $this->assertCount(3, $result['centres']);
        $this->assertSame('LOG', $result['centres'][0]['cost_centre_code']);
        $this->assertSame('10000.00', $result['centres'][0]['approved']);
        $this->assertSame('24000.00', $result['centres'][0]['actual']);
        $this->assertSame('-14000.00', $result['centres'][0]['available']);
        $this->assertSame(2, $result['centres'][0]['budget_rows']);
    }

    public function test_query_count_is_fixed_regardless_of_centre_count(): void
    {
        // One listener for the whole test (DB::listen accumulates, so
        // registering it twice would double-count the second run).
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        // Two centres, each with a budget and actuals.
        $this->seedBudget(1, '10000.00');
        $this->seedActual(1, 1, '24000.00');
        $this->seedBudget(2, '21000.00');
        $this->seedActual(2, 2, '21000.00');
        $queries = 0;
        $this->dashboard->dashboard(1, $this->year, $this->month);
        $two = $queries;

        // Four more centres, each with a budget and actuals.
        $this->seedCentre(4, 'OPS', 'Operations');
        $this->seedCentre(5, 'FIN', 'Finance');
        $this->seedCentre(6, 'HR', 'Human Resources');
        $this->seedCentre(7, 'MKT', 'Marketing');
        $this->seedBudget(4, '1000.00');
        $this->seedActual(4, 4, '100.00');
        $this->seedBudget(5, '1000.00');
        $this->seedActual(5, 5, '100.00');
        $this->seedBudget(6, '1000.00');
        $this->seedActual(6, 6, '100.00');
        $this->seedBudget(7, '1000.00');
        $this->seedActual(7, 7, '100.00');
        $queries = 0;
        $result = $this->dashboard->dashboard(1, $this->year, $this->month);
        $six = $queries;

        $this->assertSame($two, $six, 'Dashboard queries must not grow with the centre count (N+1).');
        // 3 seeded centres + 4 added = 7 rows, every centre included.
        $this->assertCount(7, $result['centres']);
    }

    public function test_cash_total_comes_from_treasury_dimensions(): void
    {
        DB::table('bill_payments')->insert([
            'id' => 1, 'bill_id' => 1, 'date' => Carbon::today()->toDateString(),
            'amount' => '60000.00', 'account_id' => 1, 'payment_method' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_line_dimensions')->insert([
            'company_id' => 1, 'created_by' => 1, 'payment_type' => 'bill_payment',
            'payment_id' => 1, 'bill_id' => 1, 'cost_centre_id' => 1,
            'allocated_amount' => '60000.00', 'source' => 'inherited', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->dashboard->dashboard(1, $this->year, $this->month);

        $this->assertSame('60000.00', $result['cash_total']);
        $this->assertSame('0.00', $result['expense_total']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function seedCentre(int $id, string $code, string $name): void
    {
        DB::table('cost_centres')->insert([
            'id' => $id, 'company_id' => 1, 'created_by' => 1, 'code' => $code, 'name' => $name,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** One posted transaction line + its dimension row = the actual. */
    protected function seedActual(int $centreId, int $tlId, string $amount): void
    {
        DB::table('transaction_lines')->insert([
            'id' => $tlId, 'account_id' => 621, 'reference' => 'Bill',
            'reference_id' => $tlId, 'reference_sub_id' => $tlId,
            'date' => Carbon::today()->toDateString(), 'debit' => $amount, 'credit' => '0.00',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('journal_line_dimensions')->insert([
            'company_id' => 1, 'created_by' => 1, 'line_type' => 'transaction_line',
            'line_id' => $tlId, 'journal_id' => null, 'cost_centre_id' => $centreId,
            'allocated_amount' => $amount, 'currency_code' => 'MZN',
            'source' => 'manual', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function seedBudget(int $centreId, string $amount): void
    {
        DB::table('cost_centre_budgets')->insert([
            'company_id' => 1, 'created_by' => 1, 'fiscal_year' => $this->year, 'period' => $this->month,
            'cost_centre_id' => $centreId, 'gl_account_id' => null, 'project_id' => null, 'scope_key' => 'all',
            'budget_amount' => $amount, 'approved_amount' => $amount, 'currency_code' => 'MZN',
            'status' => 'approved', 'approved_by' => 1, 'approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function seedCommitment(int $centreId, string $amount): void
    {
        DB::table('cost_centre_commitments')->insert([
            'company_id' => 1, 'created_by' => 1, 'fiscal_year' => $this->year, 'period' => $this->month,
            'cost_centre_id' => $centreId, 'gl_account_id' => null, 'project_id' => null, 'scope_key' => 'all',
            'source_type' => 'manual', 'source_id' => $centreId, 'amount' => $amount,
            'currency_code' => 'MZN', 'status' => 'active', 'requested_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function createTables(): void
    {
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
        Schema::create('settings', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('chart_of_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->string('code', 50)->nullable();
            $table->string('name')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('transaction_lines', function ($table) {
            $table->bigIncrements('id');
            $table->integer('account_id')->nullable();
            $table->string('reference', 50)->nullable();
            $table->integer('reference_id')->nullable();
            $table->integer('reference_sub_id')->nullable();
            $table->date('date')->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('journal_entries', function ($table) {
            $table->bigIncrements('id');
            $table->date('date')->nullable();
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->integer('journal_id')->default(0);
            $table->integer('status')->default(1);
            $table->integer('accounting_period')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('journal_items', function ($table) {
            $table->bigIncrements('id');
            $table->integer('journal')->default(0);
            $table->integer('account')->default(0);
            $table->text('description')->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('journal_line_dimensions', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('line_type', 20);
            $table->unsignedBigInteger('line_id');
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('cost_centre_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->decimal('allocated_amount', 18, 2);
            $table->decimal('percentage', 9, 6)->nullable();
            $table->string('currency_code', 10)->nullable();
            $table->string('source', 20)->default('manual');
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->string('reversal_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('treasury_line_dimensions', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('payment_type', 20);
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->unsignedBigInteger('cost_centre_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->decimal('allocated_amount', 18, 2);
            $table->decimal('percentage', 9, 6)->nullable();
            $table->string('source', 20)->default('inherited');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
        Schema::create('bill_payments', function ($table) {
            $table->bigIncrements('id');
            $table->integer('bill_id');
            $table->date('date');
            $table->decimal('amount', 16, 2)->default('0.0');
            $table->integer('account_id');
            $table->integer('payment_method')->default(0);
            $table->string('reference')->nullable();
            $table->string('add_receipt')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
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
        Schema::create('cost_centre_allocations', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id');
            $table->string('source_line_type', 20);
            $table->unsignedBigInteger('source_line_id');
            $table->string('basis', 20)->default('percentage');
            $table->decimal('source_amount', 18, 2);
            $table->string('currency_code', 10)->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('rule_snapshot')->nullable();
            $table->unsignedBigInteger('template_id')->nullable();
            $table->unsignedBigInteger('replaces_allocation_id')->nullable();
            $table->integer('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->integer('reversed_by')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('cost_centre_allocation_lines', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('allocation_id');
            $table->unsignedBigInteger('cost_centre_id');
            $table->decimal('percentage', 9, 6)->nullable();
            $table->decimal('fixed_amount', 18, 2)->nullable();
            $table->decimal('allocated_amount', 18, 2);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('dimension_audit_events', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 30);
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->integer('actor_id')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });
    }
}
