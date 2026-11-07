<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\CostCentreAllocation;
use App\Models\DimensionAuditEvent;
use App\Models\JournalLineDimension;
use App\Models\TreasuryLineDimension;
use App\Models\User;
use App\Services\CostCentreAllocationService;
use App\Services\BudgetControlService;
use App\Services\CostCentreDashboardService;
use App\Services\DimensionPostingService;
use App\Services\DimensionReportService;
use App\Services\TreasuryDimensionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Cost Centre Phase 6 — dashboard, exports, hardening, and the full
 * end-to-end chain (centre → allocate a bill → pay it → dimension the
 * GL lines → budget check → report reconciles).
 */
class CostCentreDashboardWorkflowTest extends TestCase
{
    protected CostCentreAllocationService $allocations;

    protected DimensionPostingService $posting;

    protected TreasuryDimensionService $treasury;

    protected BudgetControlService $budgets;

    protected CostCentreDashboardService $dashboard;

    protected DimensionReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();

        // Spatie caches the permission list across tests in one
        // process — drop it, or givePermissionTo below reads the
        // previous test's permissions (same fix as the Phase 3–5
        // workflow tests).
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->createTables();

        foreach ([
            'view cost centres', 'edit cost centres',
            'manage cost centre allocations', 'approve cost centre allocations',
            'manage treasury dimensions', 'manage gl dimensions',
            'view cost centre reports', 'export cost centre reports',
            'manage cost centre budgets', 'approve cost centre budgets',
            'create payment bill', 'delete payment bill',
        ] as $name) {
            DB::table('permissions')->insert(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
        }

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Manager', 'email' => 'manager@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Controller', 'email' => 'controller@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 5, 'name' => 'Other Employee', 'email' => 'otheremp@test.local', 'type' => 'employee', 'created_by' => 4, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 7, 'name' => 'Viewer', 'email' => 'viewer@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        // User 1 gets every cost-centre permission, user 4 the same set
        // for its own company, user 7 only the read-only report view.
        $all = DB::table('permissions')->pluck('name')->all();
        User::findOrFail(1)->givePermissionTo($all);
        User::findOrFail(4)->givePermissionTo($all);
        User::findOrFail(7)->givePermissionTo(['view cost centre reports']);

        foreach ([[1, 'LOG', 'Logistics'], [2, 'ADM', 'Administration'], [3, 'TEC', 'Technical']] as [$id, $code, $name]) {
            DB::table('cost_centres')->insert([
                'id' => $id, 'code' => $code, 'name' => $name, 'status' => 'active',
                'company_id' => 1, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('cost_centres')->insert([
            'id' => 4, 'code' => 'OTH', 'name' => 'Other Centre', 'status' => 'active',
            'company_id' => 4, 'created_by' => 4, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->allocations = new CostCentreAllocationService();
        $this->posting = new DimensionPostingService();
        $this->treasury = new TreasuryDimensionService();
        $this->budgets = new BudgetControlService();
        $this->dashboard = new CostCentreDashboardService();
        $this->reports = new DimensionReportService();
    }

    // ------------------------------------------------------------------
    // End-to-end chain
    // ------------------------------------------------------------------

    public function test_end_to_end_chain_dashboard_reports_reconcile(): void
    {
        [$bill, $payment] = $this->seedEndToEnd();

        // The chain landed: 3 treasury rows, 3 GL dimension rows, one
        // posted allocation, two approved budgets.
        $this->assertSame(1, CostCentreAllocation::posted()->count());
        $this->assertCount(3, TreasuryLineDimension::forCompany(1)->forPayment('bill_payment', (int) $payment->id)->active()->get());
        $this->assertSame(3, JournalLineDimension::forCompany(1)->active()->count());

        // Dashboard: LOG over budget, ADM within, TEC spending with no
        // budget; expense and cash both 60,000; reconciliation balanced.
        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-dashboard.index', ['fiscal_year' => 2026, 'period' => 11]));
        $response->assertOk();
        $response->assertSee('Logistics');
        $response->assertSee('Administration');
        $response->assertSee('Technical');
        $response->assertSee('Over budget by 14,000.00');
        $response->assertSee('Spending 15,000.00 with no budget');
        $response->assertSee('Allocation ledger');
        $response->assertSee('60,000.00');
        $response->assertSee('Balanced');

        // The reports agree with the dashboard.
        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-reports.reconciliation', ['fiscal_year' => 2026, 'period' => 11]));
        $response->assertOk();
        $response->assertSee('Balanced');

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-reports.expense', ['fiscal_year' => 2026, 'period' => 11]));
        $response->assertOk();
        $response->assertSee('24,000.00');

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-reports.cash', ['fiscal_year' => 2026, 'period' => 11]));
        $response->assertOk();
        $response->assertSee('24,000.00');

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-reports.budget', ['fiscal_year' => 2026, 'period' => 11]));
        $response->assertOk();
        $response->assertSee('LOG');
        $response->assertSee('10,000.00');
    }

    // ------------------------------------------------------------------
    // Dashboard: permissions + company scope
    // ------------------------------------------------------------------

    public function test_dashboard_requires_permission(): void
    {
        $response = $this->actingAs(User::findOrFail(6))
            ->get(route('cost-centre-dashboard.index'));
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_dashboard_cross_company_sees_nothing(): void
    {
        $this->seedEndToEnd();

        $response = $this->actingAs(User::findOrFail(4))
            ->get(route('cost-centre-dashboard.index', ['fiscal_year' => 2026, 'period' => 11]));
        $response->assertOk();
        $response->assertDontSee('Logistics');
        $response->assertDontSee('Administration');
        $response->assertDontSee('60,000.00');
        // Its own centre shows — only company 1's data stays hidden.
        $response->assertSee('Other Centre');
    }

    // ------------------------------------------------------------------
    // Exports
    // ------------------------------------------------------------------

    public function test_exports_cover_all_four_reports(): void
    {
        [$bill, $payment] = $this->seedEndToEnd();

        // Allocations ledger: XLSX (reuses maatwebsite/excel). The
        // response is streamed, so assert headers, not body content.
        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-allocations.index', ['export' => 'xlsx']));
        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('cost-centre-allocations', $response->headers->get('Content-Disposition'));

        // Expense report: CSV headers + the LOG row.
        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-reports.expense', ['fiscal_year' => 2026, 'period' => 11, 'export' => 'csv']));
        $response->assertOk();
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $content = $response->streamedContent();
        $this->assertStringContainsString('"Cost Centre"', $content);
        $this->assertStringContainsString('LOG', $content);
        // CSV rows carry raw amounts, no thousands separator.
        $this->assertStringContainsString('24000.00', $content);

        // Expense report: PDF (also streamed — headers only).
        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-reports.expense', ['fiscal_year' => 2026, 'period' => 11, 'export' => 'pdf']));
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('expense-by-centre', $response->headers->get('Content-Disposition'));

        // Budget vs actual: CSV headers + the LOG budget row.
        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-reports.budget', ['fiscal_year' => 2026, 'period' => 11, 'export' => 'csv']));
        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('"Cost Centre"', $content);
        $this->assertStringContainsString('LOG', $content);
        $this->assertStringContainsString('10000.00', $content);

        // Reconciliation: CSV headers + the unassigned line. The export
        // is an exceptions report — a balanced month exports no rows —
        // so seed one line with no dimensions first.
        DB::table('transaction_lines')->insert([
            'id' => 50, 'account_id' => 1, 'reference' => 'Bill', 'reference_id' => $bill->id,
            'reference_sub_id' => 999, 'date' => '2026-11-07', 'debit' => '1000.00', 'credit' => 0,
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-reports.reconciliation', ['fiscal_year' => 2026, 'period' => 11, 'export' => 'csv']));
        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('"GL amount"', $content);
        $this->assertStringContainsString('1000.00', $content);

        // GL dimensions: CSV with the synced bill line.
        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('gl-dimensions.index', ['export' => 'csv']));
        $response->assertOk();
        $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Journal,Date,Reference,Lines,Tagged', $response->streamedContent());
    }

    public function test_export_requires_export_permission(): void
    {
        $this->seedEndToEnd();

        // The viewer can read the report but may not export it.
        $response = $this->actingAs(User::findOrFail(7))
            ->get(route('cost-centre-reports.expense', ['fiscal_year' => 2026, 'period' => 11, 'export' => 'csv']));
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    // ------------------------------------------------------------------
    // Audit trail
    // ------------------------------------------------------------------

    public function test_audit_trail_lists_and_filters(): void
    {
        DimensionAuditEvent::record(1, 'cost_centre_allocation', 1, 'posted', null, ['status' => 'posted'], 1);
        DimensionAuditEvent::record(1, 'journal_line_dimension', 2, 'tagged', null, ['status' => 'tagged'], 2);

        $response = $this->actingAs(User::findOrFail(1))->get(route('cost-centre-audit-trail.index'));
        $response->assertOk();
        $response->assertSee('posted');
        $response->assertSee('tagged');
        $response->assertSee('cost_centre_allocation');

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-audit-trail.index', ['action' => 'posted']));
        $response->assertOk();
        $response->assertSee('>posted</span>', false);
        // The filter <select> lists every action, so assert on the
        // badge text only — the 'tagged' row must not render.
        $response->assertDontSee('>tagged</span>', false);

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-audit-trail.index', ['entity_type' => 'journal_line_dimension']));
        $response->assertOk();
        $response->assertSee('>tagged</span>', false);
        $response->assertDontSee('>posted</span>', false);

        // Another company's user sees none of company 1's events.
        $response = $this->actingAs(User::findOrFail(4))->get(route('cost-centre-audit-trail.index'));
        $response->assertOk();
        $response->assertDontSee('posted');
        $response->assertDontSee('tagged');
    }

    // ------------------------------------------------------------------
    // Allocations ledger centre filter
    // ------------------------------------------------------------------

    public function test_allocations_ledger_centre_filter(): void
    {
        $bill = $this->makeBill();
        $this->allocateBill($bill, [1, 2, 3], ['40.000000', '35.000000', '25.000000']);

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-allocations.index', ['cost_centre_id' => 1]));
        $response->assertOk();
        $response->assertSee('Bill #'.$bill->id);
        $response->assertSee('60,000.00');

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-allocations.index', ['cost_centre_id' => 2]));
        $response->assertOk();
        $response->assertSee('Bill #'.$bill->id);

        // A centre with no split in the allocation shows nothing.
        DB::table('cost_centres')->insert([
            'id' => 5, 'code' => 'NEW', 'name' => 'New Centre', 'status' => 'active',
            'company_id' => 1, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-allocations.index', ['cost_centre_id' => 5]));
        $response->assertOk();
        $response->assertDontSee('Bill #'.$bill->id);
    }

    // ------------------------------------------------------------------
    // Closed-period hardening
    // ------------------------------------------------------------------

    public function test_closed_period_blocks_clearing_a_gl_tag(): void
    {
        DB::table('chart_of_accounts')->insert([
            'id' => 1, 'code' => '621', 'name' => 'Utilities', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('journal_entries')->insert([
            'id' => 1, 'date' => '2026-06-15', 'reference' => 'JE-2026-1', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('journal_items')->insert([
            'id' => 1, 'journal' => 1, 'account' => 621, 'description' => 'Utilities',
            'debit' => '1000.00', 'credit' => '0.00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->posting->attachToLine('journal_item', 1, [['cost_centre_id' => 1, 'percentage' => '100.000000']], 'percentage', 1, 1);
        $this->assertSame(1, JournalLineDimension::forCompany(1)->active()->count());

        $this->closeYear(2026);

        // Clearing the tag would strip dimensions from a closed year.
        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('gl-dimensions.journal.update', 1), [
                'lines' => [['line_id' => 1, 'basis' => 'percentage', 'lines' => []]],
            ]);
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(1, JournalLineDimension::forCompany(1)->active()->count());
    }

    public function test_closed_period_blocks_sync(): void
    {
        $bill = $this->makeBill();
        $this->allocateBill($bill, [1, 2, 3], ['40.000000', '35.000000', '25.000000']);
        DB::table('transaction_lines')->insert([
            'id' => 1, 'account_id' => 1, 'reference' => 'Bill', 'reference_id' => $bill->id,
            'reference_sub_id' => 1, 'date' => '2026-11-07', 'debit' => '60000.00', 'credit' => 0,
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->closeYear(2026);

        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('gl-dimensions.sync-bill', $bill->id));
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(0, JournalLineDimension::forCompany(1)->active()->count());
    }

    public function test_closed_period_blocks_treasury_reset_but_payment_delete_still_cleans_up(): void
    {
        $bill = $this->makeBill();
        $this->allocateBill($bill, [1, 2], ['60.000000', '40.000000']);
        $this->actingAs(User::findOrFail(1))->post(route('bill.payment', $bill->id), [
            'date' => '2026-11-07', 'amount' => '30000.00', 'account_id' => 1,
        ]);
        $payment = BillPayment::where('bill_id', $bill->id)->firstOrFail();
        $this->assertCount(2, TreasuryLineDimension::forCompany(1)->forPayment('bill_payment', (int) $payment->id)->active()->get());

        $this->closeYear(2026);

        // The manual reset would re-tag a closed-period payment: refused.
        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('treasury-dimensions.bill-payment.update', $payment->id), [
                'basis' => 'percentage', 'reset_to_inherited' => '1', 'lines' => [],
            ]);
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertCount(2, TreasuryLineDimension::forCompany(1)->forPayment('bill_payment', (int) $payment->id)->active()->get());

        // But deleting the payment itself still cleans its dimensions up
        // — the delete hook passes allowClosedPeriod, so no rows orphan.
        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('bill.payment.destroy', [$bill->id, $payment->id]));
        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertCount(0, TreasuryLineDimension::forCompany(1)->forPayment('bill_payment', (int) $payment->id)->active()->get());
        $this->assertSame(2, TreasuryLineDimension::where('payment_id', $payment->id)->where('status', 'superseded')->count());
        $this->assertSame(0, BillPayment::where('bill_id', $bill->id)->count());
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** The whole chain: bill 60,000 → allocate 40/35/25 → pay in full
     *  (treasury inherits) → sync to GL → approve LOG 10,000 and ADM
     *  21,000 budgets for November 2026. */
    private function seedEndToEnd(): array
    {
        $bill = $this->makeBill();
        $this->allocateBill($bill, [1, 2, 3], ['40.000000', '35.000000', '25.000000']);
        DB::table('transaction_lines')->insert([
            'id' => 1, 'account_id' => 1, 'reference' => 'Bill', 'reference_id' => $bill->id,
            'reference_sub_id' => 1, 'date' => '2026-11-07', 'debit' => '60000.00', 'credit' => 0,
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs(User::findOrFail(1))->post(route('bill.payment', $bill->id), [
            'date' => '2026-11-07', 'amount' => '60000.00', 'account_id' => 1,
        ]);
        $payment = BillPayment::where('bill_id', $bill->id)->firstOrFail();
        $this->posting->syncForBill($bill, 1, 1);

        $log = $this->budgets->createBudget(1, [
            'cost_centre_id' => 1, 'fiscal_year' => 2026, 'period' => 11, 'budget_amount' => '10000.00',
        ], 1);
        $this->budgets->approveBudget($log, 1, 1);
        $adm = $this->budgets->createBudget(1, [
            'cost_centre_id' => 2, 'fiscal_year' => 2026, 'period' => 11, 'budget_amount' => '21000.00',
        ], 1);
        $this->budgets->approveBudget($adm, 1, 1);

        return [$bill, $payment];
    }

    private function makeBill(int $amount = 60000): Bill
    {
        $billId = DB::table('bills')->insertGetId([
            'bill_id' => 1, 'vender_id' => 1, 'bill_date' => '2026-11-07', 'due_date' => '2026-11-07',
            'status' => 0, 'type' => 'Bill', 'user_type' => 'vendor', 'category_id' => 0,
            'order_number' => 0, 'suppress_inventory' => 1, 'stock_on_delivery' => 0,
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_services')->insert([
            'id' => 1, 'name' => 'Utilities', 'type' => 'service',
            'expense_chartaccount_id' => 1, 'quantity' => 0, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('chart_of_accounts')->insert([
            'id' => 1, 'code' => '621', 'name' => 'Utilities', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('chart_of_accounts')->insert([
            'id' => 2, 'code' => '421', 'name' => 'Suppliers', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('chart_of_accounts')->insert([
            'id' => 3, 'code' => '121', 'name' => 'Bank', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('bill_products')->insert([
            'id' => 1, 'bill_id' => $billId, 'product_id' => 1, 'quantity' => 1,
            'tax' => null, 'discount' => 0, 'price' => $amount, 'description' => 'Utilities',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('venders')->insert([
            'id' => 1, 'name' => 'Supplier', 'email' => null, 'contact' => '',
            'notification_email' => null, 'notification_phone' => null,
            'notify_payment_email' => 0, 'notify_payment_sms' => 0, 'notify_payment_whatsapp' => 0,
            'notify_bill_email' => 0, 'notify_account_email' => 0,
            'balance' => 0, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('bank_accounts')->insert([
            'id' => 1, 'holder_name' => 'Main', 'bank_name' => 'Bank',
            'account_number' => '1', 'opening_balance' => 100000.00,
            'chart_account_id' => 3, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Bill::findOrFail($billId);
    }

    private function allocateBill(Bill $bill, array $centreIds, array $percentages): void
    {
        $lines = [];
        foreach ($centreIds as $i => $centreId) {
            $lines[] = ['cost_centre_id' => $centreId, 'percentage' => $percentages[$i], 'fixed_amount' => null, 'sort_order' => $i];
        }
        $this->allocations->createPosted('bill', $bill->id, 'bill_product', 1, 'percentage', $lines, 1, 1);
    }

    /** Close a fiscal year the way the app recognises it: a journal
     *  entry whose reference starts "FY-<year>-CLOSE". */
    private function closeYear(int $year): void
    {
        DB::table('journal_entries')->insert([
            'id' => 99, 'date' => $year.'-12-31', 'reference' => 'FY-'.$year.'-CLOSE-FIN-69',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
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
        Schema::create('branches', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
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
        Schema::create('bills', function ($table) {
            $table->bigIncrements('id');
            $table->integer('bill_id')->default(0);
            $table->integer('vender_id')->default(0);
            $table->date('bill_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('send_date')->nullable();
            $table->integer('status')->default(0);
            $table->string('type', 20)->default('Bill');
            $table->string('user_type', 20)->nullable();
            $table->integer('category_id')->default(0);
            $table->integer('order_number')->default(0);
            $table->integer('purchase_id')->nullable();
            $table->integer('proforma_id')->nullable();
            $table->boolean('suppress_inventory')->default(0);
            $table->boolean('stock_on_delivery')->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('bill_products', function ($table) {
            $table->bigIncrements('id');
            $table->integer('bill_id');
            $table->integer('product_id');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->string('tax')->nullable();
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('price', 18, 2)->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('bill_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->integer('chart_account_id')->nullable();
            $table->decimal('price', 18, 2)->default(0);
            $table->text('description')->nullable();
            $table->string('type', 20)->nullable();
            $table->integer('ref_id');
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
        Schema::create('bank_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->string('holder_name')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->decimal('opening_balance', 18, 2)->default(0);
            $table->integer('chart_account_id')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('venders', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('contact')->nullable();
            $table->string('notification_email')->nullable();
            $table->string('notification_phone')->nullable();
            $table->boolean('notify_payment_email')->default(0);
            $table->boolean('notify_payment_sms')->default(0);
            $table->boolean('notify_payment_whatsapp')->default(0);
            $table->boolean('notify_bill_email')->default(0);
            $table->boolean('notify_account_email')->default(0);
            $table->decimal('balance', 18, 2)->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('transactions', function ($table) {
            $table->bigIncrements('id');
            $table->integer('account')->nullable();
            $table->integer('user_id')->nullable();
            $table->string('user_type', 20)->nullable();
            $table->string('type', 20)->nullable();
            $table->decimal('amount', 18, 2)->default(0);
            $table->text('description')->nullable();
            $table->date('date')->nullable();
            $table->integer('created_by')->default(0);
            $table->integer('payment_id')->nullable();
            $table->string('category', 50)->nullable();
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
        Schema::create('taxes', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->decimal('rate', 10, 2)->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('debit_notes', function ($table) {
            $table->bigIncrements('id');
            $table->integer('bill')->default(0);
            $table->decimal('amount', 18, 2)->default(0);
            $table->integer('is_credit')->default(0);
            $table->timestamps();
        });
        Schema::create('product_services', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->string('type', 50)->default('service');
            $table->integer('expense_chartaccount_id')->nullable();
            $table->decimal('quantity', 10, 2)->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('chart_of_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->string('code', 50)->nullable();
            $table->string('name')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('invoices', function ($table) {
            $table->bigIncrements('id');
            $table->integer('invoice_id')->default(0);
            $table->integer('customer_id')->default(0);
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->integer('status')->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('invoice_payments', function ($table) {
            $table->bigIncrements('id');
            $table->integer('invoice_id');
            $table->date('date');
            $table->decimal('amount', 16, 2)->default('0.0');
            $table->integer('account_id')->default(0);
            $table->integer('payment_method')->default(0);
            $table->string('order_id')->nullable();
            $table->string('currency')->nullable();
            $table->string('txn_id')->nullable();
            $table->string('payment_type')->default('Manually');
            $table->string('receipt')->nullable();
            $table->string('add_receipt')->nullable();
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('projects', function ($table) {
            $table->bigIncrements('id');
            $table->string('project_name')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('plans', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
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
            $table->string('name')->nullable();
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
        Schema::create('cost_centre_allocation_templates', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('name', 100);
            $table->string('basis', 20)->default('percentage');
            $table->json('lines');
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

        Schema::create('branch_user', function ($table) {
            $table->bigIncrements('id');
            $table->integer('branch_id');
            $table->integer('user_id');
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
    }

    protected function tearDown(): void
    {
        foreach ([
            'journal_line_dimensions', 'treasury_line_dimensions', 'dimension_audit_events',
            'cost_centre_allocation_templates', 'cost_centre_allocation_lines', 'cost_centre_allocations',
            'cost_centre_budget_delegations', 'cost_centre_budget_approvals', 'cost_centre_commitments', 'cost_centre_budgets',
            'projects', 'journal_items', 'journal_entries', 'transaction_lines', 'transactions',
            'bank_accounts', 'venders', 'invoice_payments', 'invoices', 'debit_notes', 'taxes',
            'chart_of_accounts', 'product_services', 'bill_accounts', 'bill_payments', 'bill_products', 'bills',
            'cost_centres', 'branches', 'branch_user', 'settings', 'users', 'roles', 'permissions',
            'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages',
            'ch_messages', 'email_templates',
        ] as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }

        parent::tearDown();
    }
}
