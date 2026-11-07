<?php

namespace Tests\Feature\Treasury;

use App\Models\TreasuryReportExport;
use App\Models\User;
use App\Services\TreasuryReportExportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Treasury Phase 9 — dashboard, reports, exports, audit trail, HTTP level.
 *
 * Covers: the dashboard renders with live KPIs, the reports hub lists
 * all eight reports, every report renders, report filters, the CSV
 * export streams, the queued XLSX/PDF exports land as downloadable
 * files behind signed links, the audit trail filters + company
 * isolation, permission denial, and cross-company denial (CA16).
 *
 * Users: 1 = Company (view treasury reports), 2 = Operator (view
 * treasury reports), 4 = Other Company (view treasury reports),
 * 6 = No Treasury Permission. "Today" is frozen at 2026-11-07.
 * Opening balance 200,000 MZN + one confirmed 10,000 receipt, so
 * the posted balance on the dashboard is 210,000.
 */
class TreasuryReportsWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-07 09:00:00'));
        $this->createTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['manage transaction', 'view treasury', 'view treasury reports'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Operator', 'email' => 'operator@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['manage transaction', 'view treasury', 'view treasury reports']);
        User::findOrFail(2)->givePermissionTo(['manage transaction', 'view treasury', 'view treasury reports']);
        User::findOrFail(4)->givePermissionTo(['manage transaction', 'view treasury', 'view treasury reports']);
        User::findOrFail(6)->givePermissionTo(['manage transaction']);

        DB::table('branches')->insert(['id' => 1, 'name' => 'Main Branch', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('bank_accounts')->insert([
            'id' => 1, 'holder_name' => 'Hoya Hoya Lda', 'bank_name' => 'BIM',
            'account_number' => '123456789', 'chart_account_id' => 1,
            'opening_balance' => 0, 'contact_number' => '', 'bank_address' => '',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_financial_accounts')->insert([
            'id' => 1, 'bank_account_id' => 1, 'created_by' => 1, 'code' => 'BIM-001',
            'type' => 'bank', 'currency' => 'MZN', 'status' => 'active',
            'negative_balance_policy' => 'block', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_opening_balances')->insert([
            'financial_account_id' => 1, 'created_by' => 1, 'currency' => 'MZN',
            'amount' => '200000.0000', 'amount_base' => '200000.0000',
            'cut_off_date' => '2026-11-01', 'batch_reference' => 'BATCH-1',
            'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->seedReportData();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Storage::disk('local')->deleteDirectory('treasury-exports');

        foreach (['treasury_report_exports', 'treasury_statement_sync_cursors', 'treasury_missing_movements', 'bank_reconciliation_matches', 'bank_statement_lines', 'bank_reconciliation_sessions', 'treasury_payment_batch_items', 'treasury_payment_batches', 'treasury_petty_cash_funds', 'treasury_cash_movements', 'treasury_cash_counts', 'treasury_cash_sessions', 'treasury_advance_expense_lines', 'treasury_advances', 'treasury_transfers', 'treasury_payment_attempts', 'treasury_payment_requests', 'treasury_cash_flow_alerts', 'treasury_recurring_occurrences', 'treasury_recurring_rules', 'treasury_forecast_items', 'pay_slips', 'credit_notes', 'invoice_payments', 'invoice_products', 'invoices', 'customers', 'taxes', 'transaction_lines', 'transactions', 'journal_items', 'journal_entries', 'treasury_settlement_allocations', 'treasury_receipts', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'dimension_audit_events', 'bank_accounts', 'chart_of_accounts', 'branches', 'branch_user', 'settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    /** One row of everything the eight reports read. */
    protected function seedReportData(): void
    {
        DB::table('customers')->insert([
            'id' => 1, 'customer_id' => 1, 'name' => 'Customer One',
            'email' => 'customer@test.local', 'created_by' => 1, 'balance' => '0.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('invoices')->insert([
            'id' => 1, 'customer_id' => 1, 'invoice_id' => 1,
            'issue_date' => '2026-11-01', 'due_date' => '2026-11-17',
            'status' => 2, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('invoice_products')->insert([
            'invoice_id' => 1, 'product_id' => 1, 'price' => '5000.00',
            'quantity' => 1, 'tax' => '', 'discount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('treasury_operations')->insert([
            'id' => 1, 'reference' => 'COMP-RCT-2026-0001', 'type' => 'receipt',
            'company_id' => 1, 'financial_account_id' => 1, 'currency' => 'MZN',
            'amount_original' => '10000.0000', 'amount_base' => '10000.0000',
            'posting_date' => '2026-11-07', 'effective_date' => '2026-11-07', 'value_date' => '2026-11-07',
            'state' => 'confirmed', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_entries')->insert([
            'operation_id' => 1, 'financial_account_id' => 1, 'direction' => 'inflow',
            'amount_original' => '10000.0000', 'amount_base' => '10000.0000',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_receipts')->insert([
            'id' => 1, 'operation_id' => 1, 'customer_id' => 1, 'method' => 'transfer',
            'fees' => '0.0000', 'withholding' => '0.0000', 'discount' => '0.0000',
            'state' => 'confirmed', 'receipt_number' => 'RCT-0001',
            'confirmed_by' => 1, 'confirmed_at' => '2026-11-07 09:00:00',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $requestId = DB::table('treasury_payment_requests')->insertGetId([
            'created_by' => 1, 'branch_id' => 1, 'source_account_id' => 1,
            'beneficiary_type' => 'vendor', 'beneficiary_name' => 'Fornecedor X',
            'amount_original' => '50000.0000', 'currency' => 'MZN',
            'amount_base' => '50000.0000', 'paid_amount_original' => '20000.0000',
            'paid_amount_base' => '20000.0000', 'due_date' => '2026-11-20',
            'client_reference' => 'INV-99', 'state' => 'approved', 'requested_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_payment_attempts')->insert([
            'payment_request_id' => $requestId, 'attempt_number' => 1,
            'idempotency_key' => 'KEY-002', 'provider' => 'manual',
            'state' => 'paid', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('treasury_advances')->insert([
            'created_by' => 1, 'branch_id' => 1, 'source_account_id' => 1,
            'recipient_type' => 'employee', 'recipient_id' => 2, 'recipient_name' => 'Ana',
            'purpose' => 'Travel', 'currency' => 'MZN',
            'amount_requested' => '5000.0000', 'amount_requested_base' => '5000.0000',
            'amount_paid' => '5000.0000', 'amount_paid_base' => '5000.0000',
            'amount_substantiated' => '4200.0000', 'amount_substantiated_base' => '4200.0000',
            'amount_returned' => '800.0000', 'amount_returned_base' => '800.0000',
            'amount_outstanding' => '3000.0000', 'amount_outstanding_base' => '3000.0000',
            'due_date' => '2026-11-20', 'state' => 'disbursed', 'requested_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('treasury_transfers')->insert([
            'created_by' => 1, 'branch_id' => 1, 'source_account_id' => 1,
            'destination_account_id' => 1, 'source_currency' => 'MZN',
            'destination_currency' => 'USD', 'timing' => 'immediate',
            'amount_debited' => '2.0000', 'exchange_rate' => '0.0157480315',
            'rate_source' => 'manual', 'rate_date' => '2026-11-07',
            'base_exchange_rate' => '1.0000000000',
            'amount_credited' => '0.0314', 'amount_debited_base' => '2.0000',
            'amount_credited_base' => '1.9938', 'fee_amount' => '0.0000',
            'fee_base' => '0.0000', 'exchange_difference_base' => '0.0062',
            'state' => 'confirmed', 'confirmed_at' => '2026-11-07 10:00:00',
            'created_at' => '2026-11-07 10:00:00', 'updated_at' => now(),
        ]);

        $sessionId = DB::table('bank_reconciliation_sessions')->insertGetId([
            'bank_account_id' => 1, 'preset_key' => 'custom', 'status' => 'draft',
            'period_from' => '2026-11-01', 'period_to' => '2026-11-07',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $line1 = DB::table('bank_statement_lines')->insertGetId([
            'session_id' => $sessionId, 'row_index' => 1, 'txn_date' => '2026-11-05',
            'description' => 'Receipt 10,000', 'amount' => '10000.0000', 'balance' => '210000.0000',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $line2 = DB::table('bank_statement_lines')->insertGetId([
            'session_id' => $sessionId, 'row_index' => 2, 'txn_date' => '2026-11-06',
            'description' => 'Bank fee', 'amount' => '-50.0000', 'balance' => '209950.0000',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('bank_reconciliation_matches')->insert([
            'session_id' => $sessionId, 'statement_line_id' => $line1,
            'treasury_operation_id' => 1, 'matched_amount' => '10000.0000',
            'match_type' => 'auto', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_missing_movements')->insert([
            'session_id' => $sessionId, 'statement_line_id' => $line2,
            'category' => 'fee', 'offset_account_id' => 611, 'currency' => 'MZN',
            'amount_original' => '-50.0000', 'exchange_rate' => '1.0000000000',
            'amount_base' => '-50.0000', 'state' => 'proposed',
            'reason' => 'Bank fee', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('dimension_audit_events')->insert([
            ['company_id' => 1, 'entity_type' => 'treasury_receipt', 'entity_id' => 1, 'action' => 'confirmed', 'actor_id' => 2, 'occurred_at' => '2026-11-07 10:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => 1, 'entity_type' => 'treasury_transfer', 'entity_id' => 1, 'action' => 'confirmed', 'actor_id' => 1, 'occurred_at' => '2026-11-07 09:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => 4, 'entity_type' => 'treasury_receipt', 'entity_id' => 1, 'action' => 'confirmed', 'actor_id' => 2, 'occurred_at' => '2026-11-07 10:00:00', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    // ------------------------------------------------------------------
    // Dashboard + reports hub
    // ------------------------------------------------------------------

    public function test_dashboard_renders_with_live_kpis(): void
    {
        $response = $this->actingAs(User::findOrFail(1))->get(route('treasury-dashboard.index'));

        $response->assertOk();
        $response->assertSee('Treasury Dashboard');
        $response->assertSee('BIM-001');
        $response->assertSee('210,000.00');
        $response->assertSee('5,000.00');
    }

    public function test_reports_index_lists_all_eight_reports(): void
    {
        $response = $this->actingAs(User::findOrFail(1))->get(route('treasury-reports.index'));

        $response->assertOk();
        $response->assertSee('Treasury Reports');
        foreach (['Treasury Operations', 'Receipts', 'Payments', 'Advances', 'Transfers', 'Cash Flow', 'Reconciliation', 'Exchange Differences'] as $title) {
            $response->assertSee($title);
        }
    }

    public function test_each_report_renders(): void
    {
        $titles = [
            'operations' => 'Treasury Operations',
            'receipts' => 'Receipts',
            'payments' => 'Payments',
            'advances' => 'Advances',
            'transfers' => 'Transfers',
            'cash-flow' => 'Cash Flow',
            'reconciliation' => 'Reconciliation',
            'fx' => 'Exchange Differences',
        ];

        foreach ($titles as $key => $title) {
            $response = $this->actingAs(User::findOrFail(1))->get(route('treasury-reports.show', $key));
            $response->assertOk("Report {$key} did not render.");
            $response->assertSee($title);
        }
    }

    public function test_reports_show_seeded_rows(): void
    {
        $this->actingAs(User::findOrFail(1))->get(route('treasury-reports.show', 'operations'))
            ->assertOk()->assertSee('COMP-RCT-2026-0001');
        $this->actingAs(User::findOrFail(1))->get(route('treasury-reports.show', 'receipts'))
            ->assertOk()->assertSee('RCT-0001');
        $this->actingAs(User::findOrFail(1))->get(route('treasury-reports.show', 'payments'))
            ->assertOk()->assertSee('INV-99');
        $this->actingAs(User::findOrFail(1))->get(route('treasury-reports.show', 'advances'))
            ->assertOk()->assertSee('Ana');
        $this->actingAs(User::findOrFail(1))->get(route('treasury-reports.show', 'fx'))
            ->assertOk()->assertSee('MZN → USD');
        $this->actingAs(User::findOrFail(1))->get(route('treasury-reports.show', 'reconciliation'))
            ->assertOk()->assertSee('Hoya Hoya Lda');
    }

    public function test_operations_report_filter(): void
    {
        $this->actingAs(User::findOrFail(1))
            ->get(route('treasury-reports.show', ['report' => 'operations', 'type' => 'payment']))
            ->assertOk()->assertDontSee('COMP-RCT-2026-0001');

        $this->actingAs(User::findOrFail(1))
            ->get(route('treasury-reports.show', ['report' => 'operations', 'type' => 'receipt']))
            ->assertOk()->assertSee('COMP-RCT-2026-0001');
    }

    // ------------------------------------------------------------------
    // Exports
    // ------------------------------------------------------------------

    public function test_operations_csv_export_streams(): void
    {
        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('treasury-reports.show', ['report' => 'operations', 'export' => 'csv']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('COMP-RCT-2026-0001', $response->streamedContent());
    }

    public function test_xlsx_export_queues_and_downloads(): void
    {
        $this->actingAs(User::findOrFail(1))
            ->post(route('treasury-reports.export-request', 'operations'), ['format' => 'xlsx'])
            ->assertRedirect(route('treasury-reports.index'));

        $export = TreasuryReportExport::where('created_by', 1)->where('report', 'operations')->firstOrFail();
        $this->assertSame('ready', $export->status);
        $this->assertNotNull($export->file_path);
        $this->assertTrue(Storage::disk('local')->exists($export->file_path));

        $url = (new TreasuryReportExportService())->downloadUrl($export);
        $this->actingAs(User::findOrFail(1))->get($url)
            ->assertOk()
            ->assertHeader('Content-Disposition');
    }

    public function test_pdf_export_queues_and_downloads(): void
    {
        $this->actingAs(User::findOrFail(1))
            ->post(route('treasury-reports.export-request', 'receipts'), ['format' => 'pdf'])
            ->assertRedirect(route('treasury-reports.index'));

        $export = TreasuryReportExport::where('created_by', 1)->where('report', 'receipts')->firstOrFail();
        $this->assertSame('ready', $export->status);
        $this->assertTrue(Storage::disk('local')->exists($export->file_path));

        $url = (new TreasuryReportExportService())->downloadUrl($export);
        $this->actingAs(User::findOrFail(1))->get($url)->assertOk();
    }

    // ------------------------------------------------------------------
    // Audit trail
    // ------------------------------------------------------------------

    public function test_audit_trail_filters_and_company_isolation(): void
    {
        $response = $this->actingAs(User::findOrFail(1))->get(route('treasury-audit-trail.index'));
        $response->assertOk();
        $response->assertSee('Treasury Audit Trail');
        $response->assertSee('treasury_receipt');
        $this->assertSame(2, $response->viewData('events')->total());

        $filtered = $this->actingAs(User::findOrFail(1))
            ->get(route('treasury-audit-trail.index', ['type' => 'treasury_transfer']));
        $filtered->assertOk();
        $this->assertSame(1, $filtered->viewData('events')->total());

        $byActor = $this->actingAs(User::findOrFail(1))
            ->get(route('treasury-audit-trail.index', ['actor_id' => 2]));
        $byActor->assertOk();
        $this->assertSame(1, $byActor->viewData('events')->total());

        $byDate = $this->actingAs(User::findOrFail(1))
            ->get(route('treasury-audit-trail.index', ['from' => '2026-11-08']));
        $byDate->assertOk();
        $this->assertSame(0, $byDate->viewData('events')->total());

        // The other company's event never leaks into company 1.
        $this->actingAs(User::findOrFail(4))->get(route('treasury-audit-trail.index'))
            ->assertOk();
        $other = $this->actingAs(User::findOrFail(4))->get(route('treasury-audit-trail.index'));
        $this->assertSame(1, $other->viewData('events')->total());
    }

    // ------------------------------------------------------------------
    // Permissions + cross-company (CA16)
    // ------------------------------------------------------------------

    public function test_permission_denied_without_view_treasury_reports(): void
    {
        $this->actingAs(User::findOrFail(6))->get(route('treasury-dashboard.index'))
            ->assertRedirect()->assertSessionHas('error', 'Permission denied.');
        $this->actingAs(User::findOrFail(6))->get(route('treasury-reports.index'))
            ->assertRedirect()->assertSessionHas('error', 'Permission denied.');
        $this->actingAs(User::findOrFail(6))->get(route('treasury-audit-trail.index'))
            ->assertRedirect()->assertSessionHas('error', 'Permission denied.');
    }

    public function test_cross_company_cannot_see_company_data(): void
    {
        $this->actingAs(User::findOrFail(4))->get(route('treasury-dashboard.index'))
            ->assertOk()->assertDontSee('COMP-RCT-2026-0001')->assertDontSee('210,000.00');

        $this->actingAs(User::findOrFail(4))->get(route('treasury-reports.show', 'operations'))
            ->assertOk()->assertDontSee('COMP-RCT-2026-0001');
    }

    public function test_cross_company_cannot_download_company_export(): void
    {
        $this->actingAs(User::findOrFail(1))
            ->post(route('treasury-reports.export-request', 'operations'), ['format' => 'xlsx'])
            ->assertRedirect(route('treasury-reports.index'));

        $export = TreasuryReportExport::where('created_by', 1)->firstOrFail();
        $this->assertSame('ready', $export->status);

        $url = (new TreasuryReportExportService())->downloadUrl($export);
        $this->actingAs(User::findOrFail(4))->get($url)->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Tables
    // ------------------------------------------------------------------

    protected function createTables(): void
    {

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
            $table->index(['role_id', 'permission_id']);
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
            $table->string('name');
            $table->string('from')->nullable();
            $table->string('slug')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('bank_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->string('holder_name');
            $table->string('bank_name');
            $table->string('account_number');
            $table->integer('chart_account_id')->default(0);
            $table->float('opening_balance', 15, 2)->default(0);
            $table->string('contact_number');
            $table->text('bank_address');
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('chart_of_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('dimension_audit_events', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 30);
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->integer('actor_id')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
        });
        Schema::create('treasury_financial_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('bank_account_id');
            $table->integer('created_by');
            $table->string('code', 50);
            $table->string('type', 20)->default('bank');
            $table->string('currency', 3)->default('MZN');
            $table->boolean('currency_locked')->default(false);
            $table->bigInteger('branch_id')->nullable();
            $table->string('nib_or_iban', 50)->nullable();
            $table->bigInteger('owner_user_id')->nullable();
            $table->decimal('cash_limit', 20, 4)->nullable();
            $table->decimal('minimum_balance', 20, 4)->nullable();
            $table->string('negative_balance_policy', 20)->default('block');
            $table->decimal('negative_balance_limit', 20, 4)->nullable();
            $table->bigInteger('negative_balance_approver_user_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
        Schema::create('treasury_opening_balances', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('financial_account_id');
            $table->integer('created_by');
            $table->string('currency', 3)->default('MZN');
            $table->decimal('amount', 20, 4)->default(0);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->date('cut_off_date')->nullable();
            $table->string('batch_reference', 100)->nullable();
            $table->bigInteger('operator_user_id')->nullable();
            $table->text('attachments')->nullable();
            $table->string('status', 20)->default('draft');
            $table->boolean('includes_liabilities')->default(false);
            $table->boolean('includes_receivables')->default(false);
            $table->bigInteger('reversal_of_id')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('treasury_operations', function ($table) {
            $table->bigIncrements('id');
            $table->string('reference', 60)->nullable();
            $table->string('type', 20)->default('receipt');
            $table->integer('company_id');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('financial_account_id')->nullable();
            $table->string('counterparty_type', 20)->nullable();
            $table->bigInteger('counterparty_id')->nullable();
            $table->string('currency', 3)->default('MZN');
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->date('posting_date')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('value_date')->nullable();
            $table->string('state', 20)->default('draft');
            $table->string('source_type', 30)->nullable();
            $table->bigInteger('source_id')->nullable();
            $table->bigInteger('reversal_of_id')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('treasury_entries', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('operation_id');
            $table->bigInteger('financial_account_id');
            $table->string('direction', 10);
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->bigInteger('reversal_of_id')->nullable();
            $table->bigInteger('journal_id')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('treasury_receipts', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('operation_id');
            $table->bigInteger('customer_id')->nullable();
            $table->string('method', 20)->default('transfer');
            $table->string('external_transaction_id', 100)->nullable();
            $table->date('value_date')->nullable();
            $table->string('cheque_bank', 100)->nullable();
            $table->string('cheque_number', 50)->nullable();
            $table->decimal('fees', 20, 4)->default(0);
            $table->decimal('withholding', 20, 4)->default(0);
            $table->decimal('discount', 20, 4)->default(0);
            $table->string('state', 20)->default('draft');
            $table->bigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('receipt_number', 60)->nullable();
            $table->string('classification', 50)->nullable();
            $table->text('classification_reason')->nullable();
            $table->text('evidence')->nullable();
            $table->bigInteger('reversal_of_id')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('treasury_settlement_allocations', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('receipt_id')->nullable();
            $table->bigInteger('payment_id')->nullable();
            $table->string('target_type', 20)->default('invoice');
            $table->bigInteger('target_id');
            $table->decimal('allocated_amount', 20, 4)->default(0);
            $table->decimal('allocated_amount_base', 20, 4)->default(0);
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('state', 20)->default('active');
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('journal_entries', function ($table) {
            $table->bigIncrements('id');
            $table->date('date')->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('description')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('journal_items', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('journal_entry_id');
            $table->bigInteger('account_id');
            $table->decimal('debit', 16, 2)->default(0);
            $table->decimal('credit', 16, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('transaction_lines', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('transaction_id');
            $table->bigInteger('account_id');
            $table->integer('type')->default(0);
            $table->decimal('amount', 16, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('transactions', function ($table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->nullable();
            $table->integer('account')->nullable();
            $table->string('type')->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->date('date')->nullable();
            $table->string('description')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('customers', function ($table) {
            $table->bigIncrements('id');
            $table->integer('customer_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('contact')->nullable();
            $table->string('avatar', 100)->default('');
            $table->integer('created_by')->default(0);
            $table->integer('is_active')->default(1);
            $table->timestamp('email_verified_at')->nullable();
            $table->decimal('balance', 15, 2)->default('0.00');
            $table->timestamps();
        });
        Schema::create('taxes', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->decimal('rate', 10, 2)->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('invoices', function ($table) {
            $table->bigIncrements('id');
            $table->integer('customer_id')->nullable();
            $table->bigInteger('invoice_id')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('ref_number')->nullable();
            $table->integer('status')->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('invoice_products', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('invoice_id');
            $table->integer('product_id')->default(0);
            $table->decimal('price', 16, 2)->default(0);
            $table->decimal('quantity', 16, 2)->default(0);
            $table->string('tax')->nullable();
            $table->decimal('discount', 16, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('invoice_payments', function ($table) {
            $table->bigIncrements('id');
            $table->integer('invoice_id');
            $table->date('date')->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->integer('account_id')->default(0);
            $table->integer('payment_method')->default(0);
            $table->string('payment_type')->default('Manually');
            $table->timestamps();
        });
        Schema::create('credit_notes', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('invoice')->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->integer('is_debit')->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('pay_slips', function ($table) {
            $table->bigIncrements('id');
            $table->integer('employee_id');
            $table->integer('net_payble');
            $table->string('salary_month');
            $table->integer('status');
            $table->integer('basic_salary');
            $table->text('allowance');
            $table->text('commission');
            $table->text('loan');
            $table->text('saturation_deduction');
            $table->text('other_payment');
            $table->text('overtime');
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('treasury_forecast_items', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('source_type', 20);
            $table->bigInteger('source_id')->nullable();
            $table->string('direction', 10);
            $table->decimal('amount', 20, 4);
            $table->decimal('amount_base', 20, 4);
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->date('expected_date');
            $table->string('scenario', 20)->default('baseline');
            $table->decimal('probability', 5, 2)->nullable();
            $table->string('document', 150)->nullable();
            $table->string('replacement_reference', 60)->nullable();
            $table->string('description', 200)->nullable();
            $table->string('state', 20)->default('forecast');
            $table->timestamps();
        });
        Schema::create('treasury_recurring_rules', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('beneficiary', 150);
            $table->string('description', 250)->nullable();
            $table->string('category', 100)->nullable();
            $table->string('direction', 10)->default('outflow');
            $table->string('currency', 3)->default('MZN');
            $table->decimal('amount', 20, 4);
            $table->decimal('amount_base', 20, 4);
            $table->string('amount_type', 20)->default('fixed');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('frequency', 20);
            $table->integer('custom_interval')->nullable();
            $table->string('custom_unit', 20)->nullable();
            $table->integer('due_day')->nullable();
            $table->bigInteger('planned_account_id')->nullable();
            $table->boolean('approval_required')->default(false);
            $table->string('state', 20)->default('active');
            $table->integer('version')->default(1);
            $table->timestamps();
        });
        Schema::create('treasury_recurring_occurrences', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('rule_id');
            $table->string('period_key', 20);
            $table->date('scheduled_date');
            $table->decimal('amount', 20, 4);
            $table->decimal('amount_base', 20, 4);
            $table->string('currency', 3)->default('MZN');
            $table->string('direction', 10)->default('outflow');
            $table->string('state', 20)->default('generated');
            $table->timestamp('generated_at')->nullable();
            $table->bigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->string('skip_reason', 500)->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('treasury_cash_flow_alerts', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('type', 30);
            $table->string('severity', 20)->default('warning');
            $table->string('title', 200);
            $table->text('detail')->nullable();
            $table->string('source_type', 30)->nullable();
            $table->bigInteger('source_id')->nullable();
            $table->string('state', 20)->default('active');
            $table->timestamp('occurred_at')->nullable();
            $table->bigInteger('acknowledged_by')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
        });
        Schema::create('treasury_payment_requests', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('operation_id')->nullable();
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('source_account_id');
            $table->string('beneficiary_type', 20);
            $table->bigInteger('beneficiary_id')->nullable();
            $table->string('beneficiary_name', 150)->nullable();
            $table->string('beneficiary_account', 100)->nullable();
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->decimal('paid_amount_original', 20, 4)->default(0);
            $table->decimal('paid_amount_base', 20, 4)->default(0);
            $table->date('due_date')->nullable();
            $table->date('planned_date')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->text('reason')->nullable();
            $table->bigInteger('cost_centre_id')->nullable();
            $table->bigInteger('project_id')->nullable();
            $table->json('source_documents')->nullable();
            $table->string('client_reference', 100)->nullable();
            $table->json('similar_flags')->nullable();
            $table->string('state', 20)->default('draft');
            $table->integer('requested_by');
            $table->bigInteger('executor_user_id')->nullable();
            $table->json('approved_snapshot')->nullable();
            $table->integer('approved_rule_version')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('treasury_payment_attempts', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('payment_request_id');
            $table->integer('attempt_number')->default(1);
            $table->string('idempotency_key', 100);
            $table->string('provider', 50)->nullable();
            $table->string('provider_reference', 100)->nullable();
            $table->string('state', 20)->default('processing');
            $table->text('result')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('treasury_transfers', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('operation_id')->nullable();
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('source_account_id');
            $table->bigInteger('destination_account_id');
            $table->bigInteger('transit_account_id')->nullable();
            $table->string('source_currency', 3)->default('MZN');
            $table->string('destination_currency', 3)->default('MZN');
            $table->string('timing', 20)->default('immediate');
            $table->decimal('amount_debited', 20, 4)->default(0);
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->decimal('base_exchange_rate', 20, 10)->nullable();
            $table->decimal('amount_credited', 20, 4)->default(0);
            $table->decimal('amount_debited_base', 20, 4)->default(0);
            $table->decimal('amount_credited_base', 20, 4)->default(0);
            $table->decimal('fee_amount', 20, 4)->default(0);
            $table->decimal('fee_base', 20, 4)->default(0);
            $table->bigInteger('fee_account_id')->nullable();
            $table->decimal('exchange_difference_base', 20, 4)->default(0);
            $table->bigInteger('exchange_difference_account_id')->nullable();
            $table->string('state', 20)->default('draft');
            $table->text('evidence')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('treasury_advances', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('operation_id')->nullable();
            $table->bigInteger('source_account_id');
            $table->string('recipient_type', 20);
            $table->bigInteger('recipient_id');
            $table->string('recipient_name', 150)->nullable();
            $table->string('purpose', 250)->nullable();
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->decimal('amount_requested', 20, 4)->default(0);
            $table->decimal('amount_requested_base', 20, 4)->default(0);
            $table->decimal('amount_approved', 20, 4)->default(0);
            $table->decimal('amount_approved_base', 20, 4)->default(0);
            $table->decimal('amount_paid', 20, 4)->default(0);
            $table->decimal('amount_paid_base', 20, 4)->default(0);
            $table->decimal('amount_substantiated', 20, 4)->default(0);
            $table->decimal('amount_substantiated_base', 20, 4)->default(0);
            $table->decimal('amount_returned', 20, 4)->default(0);
            $table->decimal('amount_returned_base', 20, 4)->default(0);
            $table->decimal('amount_outstanding', 20, 4)->default(0);
            $table->decimal('amount_outstanding_base', 20, 4)->default(0);
            $table->decimal('reimbursement_amount', 20, 4)->default(0);
            $table->decimal('reimbursement_amount_base', 20, 4)->default(0);
            $table->string('reimbursement_state', 20)->default('none');
            $table->bigInteger('reimbursement_approved_by')->nullable();
            $table->timestamp('reimbursement_approved_at')->nullable();
            $table->timestamp('reimbursement_paid_at')->nullable();
            $table->bigInteger('reimbursement_journal_id')->nullable();
            $table->bigInteger('reimbursement_operation_id')->nullable();
            $table->text('reimbursement_reason')->nullable();
            $table->date('due_date')->nullable();
            $table->string('state', 20)->default('draft');
            $table->bigInteger('requested_by');
            $table->bigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->bigInteger('disbursement_journal_id')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->bigInteger('closed_by')->nullable();
            $table->timestamps();
        });
        Schema::create('treasury_advance_expense_lines', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('advance_id');
            $table->integer('created_by');
            $table->date('expense_date');
            $table->string('counterparty', 150)->nullable();
            $table->string('category', 100)->nullable();
            $table->string('document', 150)->nullable();
            $table->decimal('amount', 20, 4)->default(0);
            $table->bigInteger('cost_centre_id')->nullable();
            $table->text('evidence')->nullable();
            $table->string('state', 20)->default('pending');
            $table->timestamps();
        });
        Schema::create('treasury_cash_sessions', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('register_id');
            $table->bigInteger('operator_user_id');
            $table->bigInteger('shift_id')->nullable();
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_float', 20, 4)->default(0);
            $table->decimal('opening_float_difference', 20, 4)->default(0);
            $table->decimal('expected_balance', 20, 4)->default(0);
            $table->decimal('counted_balance', 20, 4)->nullable();
            $table->decimal('variance_amount', 20, 4)->nullable();
            $table->decimal('variance_posted_amount', 20, 4)->default(0);
            $table->text('variance_reason')->nullable();
            $table->bigInteger('variance_approved_by')->nullable();
            $table->timestamp('variance_approved_at')->nullable();
            $table->bigInteger('variance_operation_id')->nullable();
            $table->bigInteger('variance_journal_id')->nullable();
            $table->decimal('card_total', 20, 4)->default(0);
            $table->decimal('mobile_total', 20, 4)->default(0);
            $table->decimal('shift_cash_total', 20, 4)->nullable();
            $table->decimal('shift_card_total', 20, 4)->nullable();
            $table->decimal('shift_credit_total', 20, 4)->nullable();
            $table->decimal('shift_difference', 20, 4)->nullable();
            $table->decimal('shift_closing_balance', 20, 4)->nullable();
            $table->bigInteger('operation_id')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->bigInteger('reopened_by')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->text('lock_reason')->nullable();
            $table->bigInteger('locked_by')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('state', 20)->default('open');
            $table->timestamps();
        });
        Schema::create('treasury_cash_counts', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('cash_session_id');
            $table->integer('created_by');
            $table->decimal('denomination', 20, 4);
            $table->integer('count')->default(0);
            $table->decimal('amount', 20, 4)->default(0);
            $table->timestamps();
        });
        Schema::create('treasury_cash_movements', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('cash_session_id');
            $table->integer('created_by');
            $table->bigInteger('recorded_by')->nullable();
            $table->string('type', 20);
            $table->string('method', 20)->default('cash');
            $table->decimal('amount', 20, 4)->default(0);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->string('reference', 100)->nullable();
            $table->text('reason')->nullable();
            $table->bigInteger('transfer_id')->nullable();
            $table->bigInteger('entry_id')->nullable();
            $table->timestamps();
        });
        Schema::create('treasury_petty_cash_funds', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('custodian_user_id');
            $table->bigInteger('financial_account_id');
            $table->bigInteger('branch_id')->nullable();
            $table->decimal('limit_amount', 20, 4)->nullable();
            $table->string('replenishment_frequency', 20)->default('on_demand');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
        Schema::create('treasury_payment_batches', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->string('reference', 60)->nullable();
            $table->string('provider', 50)->nullable();
            $table->string('state', 20)->default('draft');
            $table->text('note')->nullable();
            $table->bigInteger('executed_by')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('treasury_payment_batch_items', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('batch_id');
            $table->bigInteger('payment_request_id');
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->string('currency', 3)->default('MZN');
            $table->string('state', 20)->default('pending');
            $table->bigInteger('attempt_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('bank_reconciliation_sessions', function ($table) {
            $table->id();
            $table->unsignedBigInteger('bank_account_id');
            $table->string('preset_key', 64)->default('custom');
            $table->string('status', 32)->default('draft');
            $table->string('original_filename')->nullable();
            $table->string('stored_path')->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->unsignedSmallInteger('date_tolerance_days')->default(3);
            $table->json('column_mapping')->nullable();
            $table->decimal('statement_balance', 20, 4)->nullable();
            $table->decimal('book_balance', 20, 4)->nullable();
            $table->decimal('outstanding_items', 20, 4)->nullable();
            $table->decimal('explained_differences', 20, 4)->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->dateTime('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->unsignedInteger('created_by');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
        });
        Schema::create('bank_statement_lines', function ($table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedInteger('row_index');
            $table->string('external_id', 128)->nullable();
            $table->date('txn_date')->nullable();
            $table->text('description')->nullable();
            $table->decimal('amount', 18, 4)->nullable();
            $table->decimal('balance', 18, 4)->nullable();
            $table->text('parse_error')->nullable();
            $table->json('raw_row')->nullable();
            $table->timestamps();
        });
        Schema::create('bank_reconciliation_matches', function ($table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('statement_line_id');
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('treasury_operation_id')->nullable();
            $table->decimal('matched_amount', 20, 4)->nullable();
            $table->string('match_type', 24)->default('auto');
            $table->string('match_note', 255)->nullable();
            $table->timestamps();
        });
        Schema::create('treasury_missing_movements', function ($table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('statement_line_id');
            $table->string('category', 32);
            $table->unsignedBigInteger('offset_account_id');
            $table->string('currency', 8)->default('MZN');
            $table->decimal('amount_original', 20, 4);
            $table->decimal('exchange_rate', 20, 10)->default('1.0000000000');
            $table->decimal('amount_base', 20, 4);
            $table->string('state', 24)->default('proposed');
            $table->unsignedBigInteger('operation_id')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->unsignedBigInteger('reversal_operation_id')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('treasury_statement_sync_cursors', function ($table) {
            $table->id();
            $table->unsignedBigInteger('bank_account_id');
            $table->string('source_key', 64);
            $table->string('cursor_value', 255)->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
        Schema::create('treasury_report_exports', function ($table) {
            $table->id();
            $table->integer('created_by');
            $table->string('report', 40);
            $table->string('format', 10);
            $table->json('filters')->nullable();
            $table->string('file_path', 255)->nullable();
            $table->string('token', 64);
            $table->string('status', 20)->default('queued');
            $table->text('error_message')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }
}
