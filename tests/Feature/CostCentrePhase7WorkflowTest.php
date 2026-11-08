<?php

namespace Tests\Feature;

use App\Exceptions\CostCentreException;
use App\Models\Bill;
use App\Models\CostCentreBudgetApproval;
use App\Models\CostCentreCommitment;
use App\Models\JournalItem;
use App\Models\JournalLineDimension;
use App\Models\KitchenProductionCostDimension;
use App\Models\KitchenRecipe;
use App\Models\PaySlip;
use App\Models\PayslipCostCentreSplit;
use App\Models\Purchase;
use App\Models\User;
use App\Services\BudgetControlService;
use App\Services\DimensionPostingService;
use App\Services\DimensionReportService;
use App\Services\KitchenProductionOrderService;
use App\Services\KitchenRecipeCostService;
use App\Services\KitchenUnitConversionService;
use App\Services\PayrollAccountingService;
use App\Services\PayrollDimensionService;
use App\Services\ProductionDimensionService;
use App\Services\PurchaseCommitmentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cost Centre Phase 7 (v3 — spec Phase 3) workflow tests.
 *
 * (a) Purchasing: commitment at PO stage, block/warn/approval
 *     policies, release exactly once at invoice posting.
 * (b) Payroll: a 60/40 payslip split lands 60/40 in centre actuals.
 * (c) Projects: project × centre report, filters, Unassigned rows.
 * (d) Production: completing a kitchen order attributes its
 *     ingredient cost to the centre; stock + journals unchanged.
 * (e) Profitability: revenue == GL sales income, cost ==
 *     dimensioned expense, margin computed, tenant-isolated.
 */
class CostCentrePhase7WorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();
        $this->resetUtilityCaches();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['create purchase', 'edit purchase', 'create bill', 'view cost centre reports', 'export cost centre reports', 'manage cost centre allocations', 'approve cost centre budgets'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'plan' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Manager', 'email' => 'manager@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'plan' => null, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Controller', 'email' => 'controller@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'plan' => null, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'plan' => null, 'email_verified_at' => now()],
            ['id' => 5, 'name' => 'Other Employee', 'email' => 'otheremp@test.local', 'type' => 'employee', 'created_by' => 4, 'default_company_id' => 4, 'plan' => null, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'plan' => null, 'email_verified_at' => now()],
            ['id' => 7, 'name' => 'Requester', 'email' => 'requester@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'plan' => null, 'email_verified_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['create purchase', 'edit purchase', 'create bill', 'view cost centre reports', 'export cost centre reports', 'manage cost centre allocations']);
        User::findOrFail(2)->givePermissionTo(['approve cost centre budgets', 'view cost centre reports']);
        User::findOrFail(3)->givePermissionTo(['approve cost centre budgets', 'view cost centre reports']);
        User::findOrFail(4)->givePermissionTo(['view cost centre reports']);
        User::findOrFail(5)->givePermissionTo(['view cost centre reports']);
        User::findOrFail(7)->givePermissionTo(['create purchase']);

        DB::table('plans')->insert([
            ['id' => 1, 'name' => 'Test', 'hrm' => 1, 'account' => 1],
        ]);

        DB::table('cost_centres')->insert([
            ['id' => 1, 'company_id' => 1, 'created_by' => 1, 'code' => 'LOG', 'name' => 'Logistics', 'manager_user_id' => 2, 'status' => 'active'],
            ['id' => 2, 'company_id' => 1, 'created_by' => 1, 'code' => 'ADM', 'name' => 'Admin', 'manager_user_id' => 2, 'status' => 'active'],
            ['id' => 3, 'company_id' => 4, 'created_by' => 4, 'code' => 'OTH', 'name' => 'Other Co Centre', 'manager_user_id' => 5, 'status' => 'active'],
        ]);

        DB::table('projects')->insert([
            ['id' => 1, 'project_name' => 'Alpha', 'created_by' => 1],
            ['id' => 2, 'project_name' => 'Beta', 'created_by' => 4],
        ]);

        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'code' => '621', 'name' => 'Salary expense', 'created_by' => 1],
            ['id' => 2, 'code' => '211', 'name' => 'Salaries payable', 'created_by' => 1],
            ['id' => 3, 'code' => '242', 'name' => 'IRPS liability', 'created_by' => 1],
            ['id' => 4, 'code' => '243', 'name' => 'INSS liability', 'created_by' => 1],
            ['id' => 5, 'code' => '121', 'name' => 'Bank', 'created_by' => 1],
            ['id' => 6, 'code' => '711', 'name' => 'Sales income', 'created_by' => 1],
        ]);

        $this->setSetting('budget_policy', 'block');
        $this->setSetting('budget_finance_controller_user_id', '3');
        $this->setSetting('payroll_integration_mode', 'hrm_accounting');
        $this->setSetting('payroll_irps_liability_account', '3');
        $this->setSetting('payroll_inss_employee_liability_account', '4');
        $this->setSetting('payroll_salaries_payable_account', '2');

        DB::table('product_service_units')->insert([
            ['id' => 1, 'name' => 'kg', 'created_by' => 1],
            ['id' => 2, 'name' => 'g', 'created_by' => 1],
            ['id' => 3, 'name' => 'portion', 'created_by' => 1],
        ]);
        DB::table('kitchen_unit_conversions')->insert([
            ['from_unit_id' => 1, 'to_unit_id' => 2, 'factor' => 1000, 'product_id' => null, 'created_by' => 1],
        ]);
        DB::table('product_services')->insert([
            ['id' => 10, 'name' => 'Widget', 'type' => 'product', 'sale_price' => 150, 'sale_price_type' => 'exclusive', 'purchase_price' => 100, 'unit_id' => 1, 'quantity' => 0, 'expense_chartaccount_id' => 1, 'created_by' => 1],
            ['id' => 11, 'name' => 'Uncooked Rice', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 100, 'unit_id' => 1, 'quantity' => 10, 'expense_chartaccount_id' => null, 'created_by' => 1],
            ['id' => 12, 'name' => 'Beef Mince', 'type' => 'ingredient', 'sale_price' => 0, 'sale_price_type' => 'exclusive', 'purchase_price' => 800, 'unit_id' => 1, 'quantity' => 50, 'expense_chartaccount_id' => null, 'created_by' => 1],
            ['id' => 14, 'name' => 'Beef Burger', 'type' => 'product', 'sale_price' => 500, 'sale_price_type' => 'exclusive', 'purchase_price' => 0, 'unit_id' => 3, 'quantity' => 0, 'expense_chartaccount_id' => null, 'created_by' => 1],
        ]);
        DB::table('warehouses')->insert([
            ['id' => 1, 'name' => 'COZINHA', 'created_by' => 1],
        ]);
        DB::table('warehouse_products')->insert([
            ['warehouse_id' => 1, 'product_id' => 11, 'quantity' => 5, 'created_by' => 1],
            ['warehouse_id' => 1, 'product_id' => 12, 'quantity' => 50, 'created_by' => 1],
        ]);
        DB::table('venders')->insert([
            ['id' => 1, 'name' => 'Supplier', 'created_by' => 1],
        ]);
        DB::table('employees')->insert([
            ['id' => 1, 'name' => 'Ana', 'salary_chart_account_id' => 1, 'account' => 1, 'created_by' => 1],
        ]);
        DB::table('bank_accounts')->insert([
            ['id' => 1, 'holder_name' => 'Company', 'bank_name' => 'Bank', 'account_number' => '123', 'chart_account_id' => 5, 'opening_balance' => 0, 'created_by' => 1],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['kitchen_production_cost_dimensions', 'payslip_cost_centre_splits', 'product_expiry_lots', 'stock_reports', 'warehouse_products', 'warehouses', 'kitchen_production_order_lot_usages', 'kitchen_production_batches', 'kitchen_production_order_lines', 'kitchen_production_orders', 'product_recipe_alternatives', 'product_recipes', 'kitchen_unit_conversions', 'kitchen_recipes', 'product_service_units', 'product_services', 'pay_slips', 'employees', 'bank_accounts', 'bill_products', 'bills', 'purchase_products', 'purchases', 'taxes', 'venders', 'plans', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'permissions', 'roles', 'email_templates', 'languages', 'ch_messages', 'branches', 'branch_user', 'settings', 'users', 'projects', 'chart_of_accounts', 'journal_items', 'journal_entries', 'transaction_lines', 'journal_line_dimensions', 'cost_centre_budgets', 'cost_centre_commitments', 'cost_centre_budget_approvals', 'cost_centre_budget_delegations', 'dimension_audit_events', 'cost_centres'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // (a) Purchasing
    // ------------------------------------------------------------------

    public function test_purchase_reserves_commitment_at_po_stage(): void
    {
        $this->makeBudget('1000.00');

        $purchase = $this->makePurchase([
            ['product_id' => 10, 'quantity' => 4, 'price' => 100, 'tax' => '', 'discount' => 0],
        ]);

        $reserved = (new PurchaseCommitmentService())->reserveForPurchase($purchase, 1);

        $this->assertCount(1, $reserved);
        $this->assertSame('400.00', $reserved[0]->amount);
        $this->assertSame(CostCentreCommitment::STATUS_ACTIVE, $reserved[0]->status);
        $this->assertSame('purchase', $reserved[0]->source_type);

        $figures = (new BudgetControlService())->figures(1, 1, 2026, 11, 1, null);
        $this->assertSame('1000.00', $figures['approved']);
        $this->assertSame('400.00', $figures['committed']);
        $this->assertSame('600.00', $figures['available']);

        // Idempotent: reserving again returns the same commitment.
        $again = (new PurchaseCommitmentService())->reserveForPurchase($purchase, 1);
        $this->assertCount(1, $again);
        $this->assertSame($reserved[0]->id, $again[0]->id);
        $this->assertSame(1, CostCentreCommitment::count());
    }

    public function test_purchase_block_policy_refuses_over_budget(): void
    {
        $this->makeBudget('1000.00');

        $purchase = $this->makePurchase([
            ['product_id' => 10, 'quantity' => 15, 'price' => 100, 'tax' => '', 'discount' => 0],
        ]);

        $check = (new PurchaseCommitmentService())->checkForPurchase($purchase, 1);
        $this->assertSame('blocked', $check['decision']);

        try {
            (new PurchaseCommitmentService())->reserveForPurchase($purchase, 1);
            $this->fail('Expected a CostCentreException for an over-budget block.');
        } catch (CostCentreException) {
            // Expected.
        }

        $this->assertSame(0, CostCentreCommitment::count());
    }

    public function test_purchase_warn_policy_allows_and_flags(): void
    {
        $this->setSetting('budget_policy', 'warn');
        $this->makeBudget('1000.00');

        $purchase = $this->makePurchase([
            ['product_id' => 10, 'quantity' => 15, 'price' => 100, 'tax' => '', 'discount' => 0],
        ]);

        $check = (new PurchaseCommitmentService())->checkForPurchase($purchase, 1);
        $this->assertSame('allowed', $check['decision']);
        $this->assertNotEmpty($check['warnings']);

        $reserved = (new PurchaseCommitmentService())->reserveForPurchase($purchase, 1);
        $this->assertCount(1, $reserved);
        $this->assertSame('1500.00', $reserved[0]->amount);
        $this->assertSame(CostCentreCommitment::STATUS_ACTIVE, $reserved[0]->status);
    }

    public function test_purchase_approval_policy_routes_to_approver(): void
    {
        $this->setSetting('budget_policy', 'approval');
        $this->makeBudget('1000.00');

        $purchase = $this->makePurchase([
            ['product_id' => 10, 'quantity' => 15, 'price' => 100, 'tax' => '', 'discount' => 0],
        ]);

        $check = (new PurchaseCommitmentService())->checkForPurchase($purchase, 7);
        $this->assertSame('needs_approval', $check['decision']);

        $reserved = (new PurchaseCommitmentService())->reserveForPurchase($purchase, 7);
        $this->assertSame(CostCentreCommitment::STATUS_PENDING_APPROVAL, $reserved[0]->status);

        $approval = CostCentreBudgetApproval::findOrFail($reserved[0]->approval_id);
        $this->assertSame(CostCentreBudgetApproval::STATUS_PENDING, $approval->status);

        // Manager approves, then the finance controller approves —
        // the commitment activates.
        $budgets = new BudgetControlService();
        $budgets->decide($approval, 1, 2, 'approved');
        $this->assertSame(CostCentreBudgetApproval::STATUS_MANAGER_APPROVED, $approval->fresh()->status);
        $this->assertSame(CostCentreCommitment::STATUS_PENDING_APPROVAL, $reserved[0]->fresh()->status);

        $budgets->decide($approval->fresh(), 1, 3, 'approved');
        $this->assertSame(CostCentreBudgetApproval::STATUS_APPROVED, $approval->fresh()->status);
        $this->assertSame(CostCentreCommitment::STATUS_ACTIVE, $reserved[0]->fresh()->status);
    }

    public function test_purchase_convert_releases_commitment_exactly_once_and_actual_appears(): void
    {
        $this->makeBudget('1000.00');

        $purchase = $this->makePurchase([
            ['product_id' => 10, 'quantity' => 4, 'price' => 100, 'tax' => '', 'discount' => 0],
        ]);
        (new PurchaseCommitmentService())->reserveForPurchase($purchase, 1);
        $this->assertSame(1, CostCentreCommitment::where('status', CostCentreCommitment::STATUS_ACTIVE)->count());

        $bill = Bill::create([
            'bill_id' => 1, 'vender_id' => 1, 'bill_date' => '2026-11-10', 'due_date' => '2026-11-10',
            'status' => 0, 'type' => 'Bill', 'user_type' => 'vendor', 'category_id' => 0,
            'order_number' => 1, 'purchase_id' => $purchase->id, 'created_by' => 1,
        ]);

        $converted = (new PurchaseCommitmentService())->convertForPurchase($purchase, $bill, 1);
        $this->assertSame(1, $converted);

        // Exactly once: a second convert converts nothing.
        $convertedAgain = (new PurchaseCommitmentService())->convertForPurchase($purchase, $bill, 1);
        $this->assertSame(0, $convertedAgain);

        $commitment = CostCentreCommitment::firstOrFail();
        $this->assertSame(CostCentreCommitment::STATUS_CONVERTED, $commitment->status);
        $this->assertSame($bill->id, (int) $commitment->converted_bill_id);

        // The invoice posts: tag the bill's transaction line and the
        // actual appears in the centre — commitment is 0, actual 400.
        $tlId = $this->makeTransactionLine('Bill', $bill->id, 1, '400.00', '0.00', '2026-11-10');
        (new DimensionPostingService())->attachToLine(
            JournalLineDimension::LINE_TRANSACTION_LINE,
            $tlId,
            [['cost_centre_id' => 1, 'percentage' => '100.000000', 'fixed_amount' => null, 'sort_order' => 0]],
            'percentage',
            1, 1, null
        );

        $figures = (new BudgetControlService())->figures(1, 1, 2026, 11, 1, null);
        $this->assertSame('0.00', $figures['committed']);
        $this->assertSame('400.00', $figures['actual']);
        $this->assertSame('600.00', $figures['available']);

        $report = (new DimensionReportService())->expenseByCentre(1, Carbon::create(2026, 11, 1), Carbon::create(2026, 11, 30));
        $this->assertSame('400.00', $report['total']);
    }

    public function test_purchase_store_refuses_over_budget_and_creates_nothing(): void
    {
        $this->makeBudget('1000.00');

        $this->actingAs(User::findOrFail(1))
            ->post(route('purchase.store'), [
                'vender_id' => 1,
                'warehouse_id' => 1,
                'purchase_date' => '2026-11-10',
                'category_id' => 1,
                'cost_centre_id' => 1,
                'project_id' => 1,
                'items' => [
                    ['item' => 10, 'quantity' => 15, 'price' => 100, 'tax' => '', 'discount' => 0, 'description' => '', 'row_no' => '', 'shelf_no' => ''],
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, Purchase::count());
        $this->assertSame(0, CostCentreCommitment::count());
    }

    public function test_purchase_convert_http_converts_commitment(): void
    {
        $this->makeBudget('1000.00');

        $purchase = $this->makePurchase([
            ['product_id' => 10, 'quantity' => 4, 'price' => 100, 'tax' => '', 'discount' => 0],
        ]);
        (new PurchaseCommitmentService())->reserveForPurchase($purchase, 1);

        $this->actingAs(User::findOrFail(1))
            ->get(route('purchase.convert-vendor-bill', \Crypt::encrypt($purchase->id)))
            ->assertRedirect();

        $this->assertSame(1, Bill::count());
        $bill = Bill::firstOrFail();
        $this->assertSame($purchase->id, (int) $bill->purchase_id);
        $this->assertSame($bill->id, (int) $purchase->fresh()->converted_bill_id);

        $commitment = CostCentreCommitment::firstOrFail();
        $this->assertSame(CostCentreCommitment::STATUS_CONVERTED, $commitment->status);
        $this->assertSame($bill->id, (int) $commitment->converted_bill_id);
    }

    public function test_purchase_sync_after_edit_re_reserves_from_new_lines(): void
    {
        $this->makeBudget('1000.00');

        $purchase = $this->makePurchase([
            ['product_id' => 10, 'quantity' => 4, 'price' => 100, 'tax' => '', 'discount' => 0],
        ]);
        $original = (new PurchaseCommitmentService())->reserveForPurchase($purchase, 1)[0];

        // Edit the lines down to 2 units: sync cancels + re-reserves 200.
        DB::table('purchase_products')->where('purchase_id', $purchase->id)->update(['quantity' => 2]);
        $synced = (new PurchaseCommitmentService())->syncForPurchase($purchase->fresh(), 1);

        $this->assertCount(1, $synced);
        $this->assertSame('200.00', $synced[0]->amount);
        $this->assertSame(1, CostCentreCommitment::where('status', CostCentreCommitment::STATUS_ACTIVE)->count());
        // The old commitment was cancelled (its audit event survives)
        // and its dead row was cleared so the fresh reserve could take
        // the same unique scope — exactly one commitment row remains.
        $this->assertSame(1, CostCentreCommitment::count());
        $this->assertNotSame($original->id, $synced[0]->id);
        $this->assertTrue(DB::table('dimension_audit_events')
            ->where('entity_type', 'cost_centre_commitment')
            ->where('entity_id', $original->id)
            ->where('action', 'cancelled')
            ->exists());
    }

    public function test_purchase_sync_block_leaves_old_commitments_untouched(): void
    {
        $this->makeBudget('1000.00');

        $purchase = $this->makePurchase([
            ['product_id' => 10, 'quantity' => 4, 'price' => 100, 'tax' => '', 'discount' => 0],
        ]);
        (new PurchaseCommitmentService())->reserveForPurchase($purchase, 1);

        // Edit the lines up past the budget: sync refuses, old 400 stays.
        DB::table('purchase_products')->where('purchase_id', $purchase->id)->update(['quantity' => 15]);

        try {
            (new PurchaseCommitmentService())->syncForPurchase($purchase->fresh(), 1);
            $this->fail('Expected a CostCentreException for an over-budget sync.');
        } catch (CostCentreException) {
            // Expected.
        }

        $this->assertSame('400.00', CostCentreCommitment::where('status', CostCentreCommitment::STATUS_ACTIVE)->firstOrFail()->amount);
    }

    // ------------------------------------------------------------------
    // (b) Payroll
    // ------------------------------------------------------------------

    public function test_payslip_split_lands_60_40_in_centre_actuals(): void
    {
        $payslip = $this->makePayslip();
        app(PayrollAccountingService::class)->postPaymentJournal($payslip, \App\Models\Employee::findOrFail(1));
        $payslip = $payslip->fresh();

        $this->assertNotNull($payslip->payroll_journal_id);
        $this->assertSame('1000.00', (new PayrollDimensionService())->grossExpenseTotal($payslip));

        $splits = (new PayrollDimensionService())->setSplit($payslip, [
            ['cost_centre_id' => 1, 'percentage' => '60'],
            ['cost_centre_id' => 2, 'percentage' => '40'],
        ], 1);

        $this->assertCount(2, $splits);
        $this->assertSame('600.00', number_format((float) $splits->firstWhere('cost_centre_id', 1)->amount, 2, '.', ''));
        $this->assertSame('400.00', number_format((float) $splits->firstWhere('cost_centre_id', 2)->amount, 2, '.', ''));

        // The Dr salary line (1000) is tagged 600/400 with source payroll.
        $dimensions = JournalLineDimension::where('source', JournalLineDimension::SOURCE_PAYROLL)
            ->where('status', 'active')
            ->orderBy('cost_centre_id')
            ->get();
        $this->assertCount(2, $dimensions);
        $this->assertSame('600.00', $dimensions[0]->allocated_amount);
        $this->assertSame('400.00', $dimensions[1]->allocated_amount);

        $report = (new DimensionReportService())->expenseByCentre(1, Carbon::create(2026, 11, 1), Carbon::create(2026, 11, 30));
        $this->assertSame('1000.00', $report['total']);
        $byCentre = collect($report['rows'])->keyBy('cost_centre_id');
        $this->assertSame('600.00', $byCentre[1]['total']);
        $this->assertSame('400.00', $byCentre[2]['total']);

        // Re-applying is idempotent: still exactly two dimension rows.
        (new PayrollDimensionService())->applyForPayslip($payslip->fresh(), 1);
        $this->assertSame(2, JournalLineDimension::where('source', JournalLineDimension::SOURCE_PAYROLL)->where('status', 'active')->count());
    }

    public function test_payslip_split_validation_rejects_bad_splits(): void
    {
        $payslip = $this->makePayslip();
        $service = new PayrollDimensionService();

        // Percentages must sum to 100.
        try {
            $service->setSplit($payslip, [
                ['cost_centre_id' => 1, 'percentage' => '50'],
                ['cost_centre_id' => 2, 'percentage' => '40'],
            ], 1);
            $this->fail('Expected a CostCentreException for a 90% split.');
        } catch (CostCentreException) {
            // Expected.
        }

        // One centre cannot appear twice.
        try {
            $service->setSplit($payslip, [
                ['cost_centre_id' => 1, 'percentage' => '60'],
                ['cost_centre_id' => 1, 'percentage' => '40'],
            ], 1);
            $this->fail('Expected a CostCentreException for a duplicated centre.');
        } catch (CostCentreException) {
            // Expected.
        }

        // A centre from another company is refused.
        try {
            $service->setSplit($payslip, [
                ['cost_centre_id' => 3, 'percentage' => '100'],
            ], 1);
            $this->fail('Expected a CostCentreException for a foreign centre.');
        } catch (CostCentreException) {
            // Expected.
        }

        $this->assertSame(0, PayslipCostCentreSplit::count());
    }

    public function test_payslip_split_endpoint_stores_and_applies(): void
    {
        $payslip = $this->makePayslip();
        app(PayrollAccountingService::class)->postPaymentJournal($payslip, \App\Models\Employee::findOrFail(1));

        $this->actingAs(User::findOrFail(1))
            ->post(route('payslip.cost-centre-split.store', $payslip->id), [
                'cost_centre_id' => [1, 2],
                'percentage' => [60, 40],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(2, PayslipCostCentreSplit::count());
        $this->assertSame(2, JournalLineDimension::where('source', JournalLineDimension::SOURCE_PAYROLL)->where('status', 'active')->count());
    }

    public function test_paysalary_applies_stored_split(): void
    {
        $payslip = $this->makePayslip();
        (new PayrollDimensionService())->setSplit($payslip, [
            ['cost_centre_id' => 1, 'percentage' => '60'],
            ['cost_centre_id' => 2, 'percentage' => '40'],
        ], 1);
        // No journal yet, so nothing is tagged.
        $this->assertSame(0, JournalLineDimension::count());

        $this->actingAs(User::findOrFail(1))
            ->get(route('payslip.paysalary', ['id' => 1, 'date' => '2026-11']))
            ->assertRedirect(route('payslip.index'))
            ->assertSessionHas('success');

        $this->assertSame(1, (int) $payslip->fresh()->status);
        $this->assertNotNull($payslip->fresh()->payroll_journal_id);

        $dimensions = JournalLineDimension::where('source', JournalLineDimension::SOURCE_PAYROLL)
            ->where('status', 'active')
            ->orderBy('cost_centre_id')
            ->get();
        $this->assertCount(2, $dimensions);
        $this->assertSame('600.00', $dimensions[0]->allocated_amount);
        $this->assertSame('400.00', $dimensions[1]->allocated_amount);
    }

    // ------------------------------------------------------------------
    // (c) Projects
    // ------------------------------------------------------------------

    public function test_project_centre_report_groups_by_project_and_centre(): void
    {
        $itemA = $this->makeJournalLine(1, '300.00', '0.00', '2026-11-15');
        $itemB = $this->makeJournalLine(1, '200.00', '0.00', '2026-11-16');
        $itemC = $this->makeJournalLine(1, '500.00', '0.00', '2026-11-17');

        $posting = new DimensionPostingService();
        $posting->attachToLine(JournalLineDimension::LINE_JOURNAL_ITEM, $itemA, [['cost_centre_id' => 1, 'percentage' => '100.000000', 'fixed_amount' => null, 'sort_order' => 0]], 'percentage', 1, 1, 1);
        $posting->attachToLine(JournalLineDimension::LINE_JOURNAL_ITEM, $itemB, [['cost_centre_id' => 1, 'percentage' => '100.000000', 'fixed_amount' => null, 'sort_order' => 0]], 'percentage', 1, 1, null);
        $posting->attachToLine(JournalLineDimension::LINE_JOURNAL_ITEM, $itemC, [['cost_centre_id' => 2, 'percentage' => '100.000000', 'fixed_amount' => null, 'sort_order' => 0]], 'percentage', 1, 1, 1);

        $from = Carbon::create(2026, 11, 1);
        $to = Carbon::create(2026, 11, 30);
        $report = (new DimensionReportService())->costByProjectAndCentre(1, $from, $to);

        $this->assertSame('1000.00', $report['total']);
        $this->assertCount(3, $report['rows']);

        $byKey = [];
        foreach ($report['rows'] as $row) {
            $byKey[$row['cost_centre_id'].'|'.($row['project_id'] ?? '')] = $row;
        }
        $this->assertSame('300.00', $byKey['1|1']['total']);
        $this->assertSame('Alpha', $byKey['1|1']['project_name']);
        // Empty project rows appear as Unassigned, never dropped.
        $this->assertSame('200.00', $byKey['1|']['total']);
        $this->assertNull($byKey['1|']['project_name']);
        $this->assertSame('500.00', $byKey['2|1']['total']);

        // Project-only filter.
        $byProject = (new DimensionReportService())->costByProjectAndCentre(1, $from, $to, null, 1);
        $this->assertSame('800.00', $byProject['total']);
        $this->assertCount(2, $byProject['rows']);

        // Centre-only filter.
        $byCentre = (new DimensionReportService())->costByProjectAndCentre(1, $from, $to, 1, null);
        $this->assertSame('500.00', $byCentre['total']);
        $this->assertCount(2, $byCentre['rows']);
    }

    public function test_project_centre_report_http(): void
    {
        $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-reports.project-centre', ['from' => '2026-11-01', 'to' => '2026-11-30']))
            ->assertOk()
            ->assertSee('Cost by Project and Centre');

        // The other company sees an empty report.
        $this->actingAs(User::findOrFail(5))
            ->get(route('cost-centre-reports.project-centre', ['from' => '2026-11-01', 'to' => '2026-11-30']))
            ->assertOk()
            ->assertSee('No expenses in this period.');
    }

    // ------------------------------------------------------------------
    // (d) Production
    // ------------------------------------------------------------------

    public function test_production_completion_attributes_cost_to_centre(): void
    {
        $burger = $this->makeRecipe('KR-2026-0002', 'Beef Burger', 14, 10, 3, 1, 3, [
            ['ingredient_product_id' => 12, 'quantity' => 1, 'unit_id' => 1, 'loss_percent' => 0],
        ]);

        $service = $this->orderService();
        $order = $service->create([
            'kitchen_recipe_id' => $burger->id,
            'production_date' => '2026-11-10',
            'section' => 'hot_kitchen',
            'branch_id' => null,
            'warehouse_id' => 1,
            'planned_quantity' => 10,
            'planned_unit_id' => 3,
            'responsible_user_id' => 2,
            'notes' => null,
            'cost_centre_id' => 1,
        ], User::findOrFail(1));
        $order = $service->approve($order, User::findOrFail(3));
        $order = $service->start($order, User::findOrFail(2));
        $line = $order->lines->firstOrFail();

        $stockBefore = (float) DB::table('warehouse_products')->where('product_id', 12)->value('quantity');
        $journalsBefore = DB::table('journal_entries')->count();

        $completed = $service->complete($order, User::findOrFail(2), [(int) $line->id => 3.0], 10, 3, date('Y-m-d', strtotime('+5 days')));

        // 3 kg of Beef Mince at 800 = 2400 attributed to LOG.
        $this->assertSame('2400.00', (string) $completed->actual_cost);
        $rows = KitchenProductionCostDimension::where('kitchen_production_order_id', $completed->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]->cost_centre_id);
        $this->assertSame('2400.00', number_format((float) $rows[0]->amount, 2, '.', ''));

        // Stock deduction happened (50 − 3 = 47) and no journal posted.
        $this->assertSame($stockBefore - 3.0, (float) DB::table('warehouse_products')->where('product_id', 12)->value('quantity'));
        $this->assertSame($journalsBefore, DB::table('journal_entries')->count());

        // Attributing again is a no-op (one row per order).
        $again = (new ProductionDimensionService())->attributeCompletion($completed->fresh(), 2);
        $this->assertSame($rows[0]->id, $again->id);
        $this->assertSame(1, KitchenProductionCostDimension::count());

        // An order without a centre attributes nothing.
        $plain = $service->create([
            'kitchen_recipe_id' => $burger->id,
            'production_date' => '2026-11-11',
            'section' => 'hot_kitchen',
            'branch_id' => null,
            'warehouse_id' => 1,
            'planned_quantity' => 10,
            'planned_unit_id' => 3,
            'responsible_user_id' => 2,
            'notes' => null,
            'cost_centre_id' => null,
        ], User::findOrFail(1));
        $plain = $service->approve($plain, User::findOrFail(3));
        $plain = $service->start($plain, User::findOrFail(2));
        $plainLine = $plain->lines->firstOrFail();
        $service->complete($plain, User::findOrFail(2), [(int) $plainLine->id => 1.0], 10, 3, date('Y-m-d', strtotime('+5 days')));
        $this->assertSame(1, KitchenProductionCostDimension::count());
    }

    // ------------------------------------------------------------------
    // (e) Profitability
    // ------------------------------------------------------------------

    public function test_profitability_reconciles_revenue_cost_and_margin(): void
    {
        $from = Carbon::create(2026, 11, 1);
        $to = Carbon::create(2026, 11, 30);

        // Sales income 5000 (GL) on two invoice lines: 3000 dimensioned
        // to LOG/Alpha, 2000 left undimensioned.
        $invoiceLine = $this->makeTransactionLine('Invoice', 1, 6, '0.00', '3000.00', '2026-11-10');
        (new DimensionPostingService())->attachToLine(
            JournalLineDimension::LINE_TRANSACTION_LINE,
            $invoiceLine,
            [['cost_centre_id' => 1, 'percentage' => '100.000000', 'fixed_amount' => null, 'sort_order' => 0]],
            'percentage',
            1, 1, 1
        );
        $this->makeTransactionLine('Invoice', 1, 6, '0.00', '2000.00', '2026-11-11');

        // Expense 2000 (GL), fully dimensioned to LOG/Alpha.
        $billLine = $this->makeTransactionLine('Bill', 1, 1, '2000.00', '0.00', '2026-11-12');
        (new DimensionPostingService())->attachToLine(
            JournalLineDimension::LINE_TRANSACTION_LINE,
            $billLine,
            [['cost_centre_id' => 1, 'percentage' => '100.000000', 'fixed_amount' => null, 'sort_order' => 0]],
            'percentage',
            1, 1, 1
        );

        // Production cost 500 attributed to LOG.
        KitchenProductionCostDimension::create([
            'company_id' => 1, 'created_by' => 1, 'kitchen_production_order_id' => 99,
            'cost_centre_id' => 1, 'project_id' => null, 'amount' => '500.00',
            'currency_code' => 'MZN', 'status' => 'active', 'production_date' => '2026-11-20',
        ]);

        $report = (new DimensionReportService())->profitability(1, $from, $to);

        // Revenue total == GL sales income; cost total == dimensioned expense.
        $this->assertSame('5000.00', $report['revenue_total']);
        $this->assertSame('2000.00', $report['cost_total']);
        $this->assertSame('500.00', $report['production_total']);
        $this->assertSame('2500.00', $report['margin_total']);
        $this->assertSame('3000.00', $report['revenue_dimensioned_total']);
        $this->assertSame('2000.00', $report['revenue_undimensioned_total']);

        $this->assertTrue($report['reconciliation']['matches']);
        $this->assertTrue($report['reconciliation']['revenue_matches']);
        $this->assertTrue($report['reconciliation']['cost_matches']);
        $this->assertTrue($report['reconciliation']['production_matches']);
        $this->assertSame('5000.00', $report['reconciliation']['revenue_gl_total']);
        $this->assertSame('2000.00', $report['reconciliation']['cost_gl_total']);

        // Three rows: the dimensioned (LOG, Alpha) row, the production
        // row (LOG, no project — production carries no project), and
        // the Unassigned revenue row.
        $this->assertCount(3, $report['rows']);
        $byKey = [];
        foreach ($report['rows'] as $row) {
            $byKey[($row['cost_centre_id'] ?? '').'|'.($row['project_id'] ?? '')] = $row;
        }
        $this->assertSame('3000.00', $byKey['1|1']['revenue']);
        $this->assertSame('2000.00', $byKey['1|1']['cost']);
        $this->assertSame('0.00', $byKey['1|1']['production']);
        $this->assertSame('1000.00', $byKey['1|1']['margin']);
        $this->assertSame('0.00', $byKey['1|']['revenue']);
        $this->assertSame('0.00', $byKey['1|']['cost']);
        $this->assertSame('500.00', $byKey['1|']['production']);
        $this->assertSame('-500.00', $byKey['1|']['margin']);
        // Undimensioned revenue is never silently dropped.
        $this->assertSame('2000.00', $byKey['|']['revenue']);
        $this->assertSame('0.00', $byKey['|']['cost']);
        $this->assertSame('2000.00', $byKey['|']['margin']);

        // Tenant isolation: the other company sees zero rows/totals.
        $other = (new DimensionReportService())->profitability(4, $from, $to);
        $this->assertSame([], $other['rows']);
        $this->assertSame('0.00', $other['revenue_total']);
        $this->assertSame('0.00', $other['cost_total']);
        $this->assertSame('0.00', $other['production_total']);
        $this->assertSame('0.00', $other['margin_total']);
    }

    public function test_profitability_report_http(): void
    {
        $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-reports.profitability', ['from' => '2026-11-01', 'to' => '2026-11-30']))
            ->assertOk()
            ->assertSee('Profitability by Centre and Project')
            ->assertSee('Reconciliation');

        // The other company sees an empty report.
        $this->actingAs(User::findOrFail(5))
            ->get(route('cost-centre-reports.profitability', ['from' => '2026-11-01', 'to' => '2026-11-30']))
            ->assertOk()
            ->assertSee('No revenue or costs in this period.');

        // CSV export works.
        $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-reports.profitability', ['from' => '2026-11-01', 'to' => '2026-11-30', 'export' => 'csv']))
            ->assertOk();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Utility caches settings + tax rates in statics — clear them so rows from an earlier test file's in-memory DB do not leak into this one (established Billing-test pattern). */
    protected function resetUtilityCaches(): void
    {
        $ref = new \ReflectionClass(\App\Models\Utility::class);
        foreach (['getsettingsid' => [], 'getsettings' => null, 'rates' => null, 'data' => null] as $name => $value) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue(null, $value);
        }
    }

    protected function setSetting(string $name, string $value): void
    {
        DB::table('settings')->updateOrInsert(
            ['name' => $name, 'created_by' => 1],
            ['value' => $value, 'created_at' => now(), 'updated_at' => now()]
        );
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
        Schema::create('branches', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('branch_user', function ($table) {
            $table->bigIncrements('id');
            $table->integer('branch_id');
            $table->integer('user_id');
            $table->timestamps();
        });
        Schema::create('settings', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('type', 100)->nullable();
            $table->integer('created_by')->default(0);
            $table->integer('default_company_id')->nullable();
            $table->integer('plan')->nullable();
            $table->integer('is_enable_login')->default(1);
            $table->integer('is_active')->default(1);
            $table->integer('delete_status')->default(1);
            $table->string('lang')->default('en');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('roles', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('permissions', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('model_has_permissions', function ($table) {
            $table->integer('permission_id');
            $table->string('model_type');
            $table->integer('model_id');
            $table->index(['model_id', 'model_type']);
        });
        Schema::create('model_has_roles', function ($table) {
            $table->integer('role_id');
            $table->string('model_type');
            $table->integer('model_id');
            $table->index(['model_id', 'model_type']);
        });
        Schema::create('role_has_permissions', function ($table) {
            $table->integer('permission_id');
            $table->integer('role_id');
            $table->primary(['permission_id', 'role_id']);
        });
        Schema::create('plans', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('hrm')->default(0);
            $table->integer('account')->default(0);
            $table->timestamps();
        });
        Schema::create('languages', function ($table) {
            $table->increments('id');
            $table->string('code')->nullable();
            $table->string('full_name')->nullable();
            $table->timestamps();
        });
        Schema::create('ch_messages', function ($table) {
            $table->increments('id');
            $table->integer('to_id')->nullable();
            $table->integer('seen')->default(0);
            $table->timestamps();
        });
        Schema::create('email_templates', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('from')->nullable();
            $table->string('slug')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('chart_of_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->string('code', 50)->nullable();
            $table->string('name')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('projects', function ($table) {
            $table->bigIncrements('id');
            $table->string('project_name')->nullable();
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

        // --- Phase 7 tables ---
        Schema::create('purchases', function ($table) {
            $table->bigIncrements('id');
            $table->integer('purchase_id')->nullable();
            $table->integer('purchase_quotation_id')->nullable();
            $table->integer('purchase_proforma_id')->nullable();
            $table->integer('proforma_id')->nullable();
            $table->integer('quotation_id')->nullable();
            $table->integer('converted_bill_id')->nullable();
            $table->integer('converted_invoice_id')->nullable();
            $table->integer('is_invoice_converted')->default(0);
            $table->integer('stock_on_delivery')->default(0);
            $table->integer('vender_id')->nullable();
            $table->integer('warehouse_id')->nullable();
            $table->date('purchase_date')->nullable();
            $table->integer('purchase_number')->nullable();
            $table->integer('discount_apply')->default(0);
            $table->integer('category_id')->nullable();
            $table->unsignedBigInteger('cost_centre_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->integer('status')->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('purchase_products', function ($table) {
            $table->bigIncrements('id');
            $table->integer('purchase_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->string('tax')->nullable();
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('price', 15, 2)->default(0);
            $table->text('description')->nullable();
            $table->string('row_no')->nullable();
            $table->string('shelf_no')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('batch_no')->nullable();
            $table->date('mfg_date')->nullable();
            $table->text('selected_options_json')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('bills', function ($table) {
            $table->bigIncrements('id');
            $table->integer('bill_id')->nullable();
            $table->integer('vender_id')->nullable();
            $table->date('bill_date')->nullable();
            $table->date('due_date')->nullable();
            $table->integer('status')->default(0);
            $table->string('type', 20)->nullable();
            $table->string('user_type', 20)->nullable();
            $table->integer('category_id')->nullable();
            $table->integer('order_number')->nullable();
            $table->integer('purchase_id')->nullable();
            $table->boolean('suppress_inventory')->default(false);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('bill_products', function ($table) {
            $table->bigIncrements('id');
            $table->integer('bill_id');
            $table->integer('product_id');
            $table->decimal('quantity', 15, 4)->default(0);
            $table->string('tax')->nullable();
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('price', 15, 2)->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('pay_slips', function ($table) {
            $table->bigIncrements('id');
            $table->integer('employee_id');
            $table->decimal('net_payble', 15, 2)->default(0);
            $table->decimal('basic_salary', 15, 2)->default(0);
            $table->text('allowance')->nullable();
            $table->text('commission')->nullable();
            $table->text('loan')->nullable();
            $table->text('saturation_deduction')->nullable();
            $table->text('other_payment')->nullable();
            $table->text('overtime')->nullable();
            $table->decimal('inss_employee_amount', 15, 2)->default(0);
            $table->decimal('inss_employer_amount', 15, 2)->default(0);
            $table->decimal('irps_amount', 15, 2)->default(0);
            $table->string('salary_month', 20)->nullable();
            $table->integer('status')->default(0);
            $table->unsignedBigInteger('payroll_journal_id')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('employees', function ($table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->nullable();
            $table->string('name')->nullable();
            $table->integer('salary_chart_account_id')->nullable();
            $table->integer('account')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('bank_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->string('holder_name')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->integer('chart_account_id')->nullable();
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->string('contact_number')->nullable();
            $table->string('bank_address')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('taxes', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->decimal('rate', 10, 2)->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('venders', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('warehouses', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('product_service_units', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('dimension_mode')->nullable();
            $table->string('length_unit')->nullable();
            $table->decimal('volumetric_divisor', 10, 2)->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('product_services', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('type', 20)->nullable();
            $table->decimal('sale_price', 15, 4)->default(0);
            $table->string('sale_price_type', 20)->nullable();
            $table->decimal('purchase_price', 15, 4)->default(0);
            $table->integer('tax_id')->nullable();
            $table->integer('unit_id')->nullable();
            $table->integer('show_on_pos')->default(1);
            $table->float('quantity')->default(0);
            $table->boolean('batch_tracking')->default(false);
            $table->string('batch_consumption_method', 20)->nullable();
            $table->boolean('allow_negative_stock')->default(false);
            $table->boolean('affect_stock')->default(true);
            $table->integer('low_stock_threshold')->nullable();
            $table->integer('expense_chartaccount_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('kitchen_recipes', function ($table) {
            $table->increments('id');
            $table->string('code', 50);
            $table->string('name');
            $table->integer('product_id');
            $table->integer('category_id')->nullable();
            $table->integer('version')->default(1);
            $table->string('status', 20)->default('draft');
            $table->decimal('expected_yield_quantity', 12, 4)->nullable();
            $table->integer('expected_yield_unit_id')->nullable();
            $table->decimal('standard_portion_quantity', 12, 4)->nullable();
            $table->integer('standard_portion_unit_id')->nullable();
            $table->integer('preparation_time_minutes')->nullable();
            $table->text('equipment')->nullable();
            $table->text('instructions')->nullable();
            $table->text('storage_conditions')->nullable();
            $table->text('allergens')->nullable();
            $table->string('photo')->nullable();
            $table->decimal('loss_percent', 5, 2)->default(0);
            $table->integer('cost_centre_id')->nullable();
            $table->integer('branch_id')->nullable();
            $table->integer('created_by');
            $table->integer('submitted_by')->nullable();
            $table->integer('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_note')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'code', 'version']);
            $table->unique(['created_by', 'product_id', 'version']);
        });
        Schema::create('kitchen_unit_conversions', function ($table) {
            $table->increments('id');
            $table->integer('from_unit_id');
            $table->integer('to_unit_id');
            $table->decimal('factor', 18, 6);
            $table->integer('product_id')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('product_recipes', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('kitchen_recipe_id')->nullable();
            $table->integer('version')->default(1);
            $table->integer('ingredient_product_id');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('loss_percent', 5, 2)->default(0);
            $table->string('unit')->nullable();
            $table->integer('unit_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('product_recipe_alternatives', function ($table) {
            $table->increments('id');
            $table->integer('product_recipe_id');
            $table->integer('alternative_product_id');
            $table->decimal('quantity', 12, 4)->nullable();
            $table->integer('sort_order')->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('kitchen_production_orders', function ($table) {
            $table->increments('id');
            $table->string('order_number', 30)->nullable();
            $table->integer('kitchen_recipe_id');
            $table->integer('kitchen_production_plan_id')->nullable();
            $table->integer('recipe_version')->default(1);
            $table->date('production_date')->nullable();
            $table->string('section', 30)->nullable();
            $table->integer('branch_id')->nullable();
            $table->integer('warehouse_id');
            $table->decimal('planned_quantity', 12, 4);
            $table->integer('planned_unit_id')->nullable();
            $table->decimal('actual_yield_quantity', 12, 4)->nullable();
            $table->integer('actual_yield_unit_id')->nullable();
            $table->string('status', 20)->default('draft');
            $table->integer('responsible_user_id')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('planned_cost', 15, 2)->default(0);
            $table->decimal('actual_cost', 15, 2)->nullable();
            $table->decimal('cost_per_portion', 15, 2)->nullable();
            $table->decimal('food_cost_percent', 5, 2)->nullable();
            $table->string('food_cost_band', 20)->nullable();
            $table->decimal('planned_food_cost_percent', 5, 2)->nullable();
            $table->string('planned_food_cost_band', 20)->nullable();
            $table->date('batch_expiry_date')->nullable();
            $table->integer('submitted_by')->nullable();
            $table->integer('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->integer('started_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->integer('completed_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('validated_by')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('stock_deducted_at')->nullable();
            $table->timestamp('stock_received_at')->nullable();
            $table->integer('cost_centre_id')->nullable();
            $table->integer('created_by');
            $table->timestamps();
            $table->unique(['created_by', 'order_number']);
        });
        Schema::create('kitchen_production_order_lines', function ($table) {
            $table->increments('id');
            $table->integer('kitchen_production_order_id');
            $table->integer('ingredient_product_id');
            $table->decimal('planned_quantity', 12, 4);
            $table->integer('planned_unit_id')->nullable();
            $table->decimal('planned_unit_cost', 15, 4)->default(0);
            $table->decimal('planned_cost', 15, 2)->default(0);
            $table->decimal('actual_quantity', 12, 4)->nullable();
            $table->decimal('actual_unit_cost', 15, 4)->nullable();
            $table->decimal('actual_cost', 15, 2)->nullable();
            $table->decimal('loss_percent', 5, 2)->default(0);
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('kitchen_production_batches', function ($table) {
            $table->increments('id');
            $table->string('batch_number', 50);
            $table->integer('kitchen_production_order_id');
            $table->integer('product_id');
            $table->decimal('quantity_produced', 12, 4);
            $table->decimal('quantity_remaining', 12, 4);
            $table->integer('unit_id')->nullable();
            $table->integer('warehouse_id');
            $table->date('expiry_date')->nullable();
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->decimal('total_cost', 15, 2)->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('kitchen_production_order_lot_usages', function ($table) {
            $table->increments('id');
            $table->integer('kitchen_production_order_line_id');
            $table->integer('product_expiry_lot_id');
            $table->decimal('quantity', 12, 4);
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('warehouse_products', function ($table) {
            $table->increments('id');
            $table->integer('warehouse_id')->default(0);
            $table->integer('product_id')->default(0);
            $table->integer('product_variant_id')->nullable();
            $table->decimal('quantity', 15, 4)->default(0);
            $table->string('row_no')->nullable();
            $table->string('shelf_no')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('stock_reports', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->decimal('quantity', 15, 4)->default(0);
            $table->string('type')->nullable();
            $table->integer('type_id')->nullable();
            $table->integer('warehouse_loss_id')->nullable();
            $table->text('description')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('product_expiry_lots', function ($table) {
            $table->increments('id');
            $table->integer('product_id');
            $table->integer('warehouse_id')->nullable();
            $table->integer('purchase_id')->nullable();
            $table->integer('purchase_product_id')->nullable();
            $table->string('source', 20)->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('batch_no')->nullable();
            $table->date('mfg_date')->nullable();
            $table->decimal('quantity_in', 15, 4)->default(0);
            $table->decimal('quantity_remaining', 15, 4)->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('payslip_cost_centre_splits', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->unsignedBigInteger('payslip_id');
            $table->unsignedBigInteger('cost_centre_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->decimal('percentage', 9, 6);
            $table->decimal('amount', 20, 4)->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['payslip_id', 'cost_centre_id', 'project_id'], 'pcss_payslip_centre_uq');
            $table->index('payslip_id', 'pcss_payslip_idx');
        });
        Schema::create('kitchen_production_cost_dimensions', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->unsignedBigInteger('kitchen_production_order_id');
            $table->unsignedBigInteger('cost_centre_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->decimal('amount', 20, 4);
            $table->string('currency_code', 10)->nullable();
            $table->string('status', 20)->default('active');
            $table->date('production_date')->nullable();
            $table->timestamps();
            $table->unique('kitchen_production_order_id', 'kpcd_order_uq');
            $table->index(['created_by', 'cost_centre_id'], 'kpcd_centre_idx');
        });
    }

    protected function makeBudget(string $amount, ?int $glAccountId = 1, ?int $projectId = null, int $period = 11)
    {
        $service = new BudgetControlService();
        $budget = $service->createBudget(1, [
            'cost_centre_id' => 1, 'fiscal_year' => 2026, 'period' => $period,
            'budget_amount' => $amount, 'gl_account_id' => $glAccountId, 'project_id' => $projectId,
        ], 1);
        $service->approveBudget($budget, 1, 1);

        return $budget->fresh();
    }

    protected function makePurchase(array $lines, ?int $centreId = 1, ?int $projectId = null, string $date = '2026-11-10'): Purchase
    {
        $purchase = Purchase::create([
            'purchase_id' => 1, 'vender_id' => 1, 'warehouse_id' => 1, 'purchase_date' => $date,
            'purchase_number' => 1, 'status' => 0, 'stock_on_delivery' => 1, 'category_id' => 1,
            'cost_centre_id' => $centreId, 'project_id' => $projectId, 'created_by' => 1,
        ]);

        foreach ($lines as $line) {
            DB::table('purchase_products')->insert(array_merge([
                'purchase_id' => $purchase->id, 'product_id' => 10, 'quantity' => 1,
                'tax' => '', 'discount' => 0, 'price' => 100, 'description' => null,
            ], $line));
        }

        return $purchase->fresh();
    }

    protected function makePayslip(): PaySlip
    {
        return PaySlip::create([
            'employee_id' => 1, 'net_payble' => 1000, 'basic_salary' => 1000,
            'allowance' => null, 'commission' => null, 'loan' => null,
            'saturation_deduction' => null, 'other_payment' => null, 'overtime' => null,
            'inss_employee_amount' => 0, 'inss_employer_amount' => 0, 'irps_amount' => 0,
            'salary_month' => '2026-11', 'status' => 0, 'created_by' => 1,
        ]);
    }

    /** Create a manual journal line; returns the JournalItem id. */
    protected function makeJournalLine(int $accountId, string $debit, string $credit, string $date): int
    {
        $entryId = DB::table('journal_entries')->insertGetId([
            'date' => $date, 'reference' => 'TEST', 'description' => 'Phase 7 fixture',
            'journal_id' => 1, 'status' => 1, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('journal_items')->insertGetId([
            'journal' => $entryId, 'account' => $accountId, 'description' => 'Phase 7 fixture',
            'debit' => $debit, 'credit' => $credit, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Create a document transaction line; returns the TransactionLines id. */
    protected function makeTransactionLine(string $reference, int $referenceId, int $accountId, string $debit, string $credit, string $date): int
    {
        return DB::table('transaction_lines')->insertGetId([
            'account_id' => $accountId, 'reference' => $reference, 'reference_id' => $referenceId,
            'reference_sub_id' => null, 'date' => $date, 'debit' => $debit, 'credit' => $credit,
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function orderService(): KitchenProductionOrderService
    {
        $conversions = new KitchenUnitConversionService();

        return new KitchenProductionOrderService($conversions, new KitchenRecipeCostService($conversions));
    }

    protected function makeRecipe(string $code, string $name, int $productId, float $yieldQty, int $yieldUnit, float $portionQty, int $portionUnit, array $lines): KitchenRecipe
    {
        $recipe = KitchenRecipe::create([
            'code' => $code,
            'name' => $name,
            'product_id' => $productId,
            'version' => 1,
            'status' => KitchenRecipe::STATUS_APPROVED,
            'expected_yield_quantity' => $yieldQty,
            'expected_yield_unit_id' => $yieldUnit,
            'standard_portion_quantity' => $portionQty,
            'standard_portion_unit_id' => $portionUnit,
            'loss_percent' => 0,
            'created_by' => 1,
        ]);

        foreach ($lines as $line) {
            DB::table('product_recipes')->insert(array_merge($line, [
                'product_id' => $productId,
                'kitchen_recipe_id' => $recipe->id,
                'version' => 1,
                'created_by' => 1,
            ]));
        }

        return $recipe->fresh();
    }
}
