<?php

namespace Tests\Unit;

use App\Exceptions\BillingContractException;
use App\Models\BillingContract;
use App\Models\BillingDeliveryAttempt;
use App\Models\BillingContractOccurrence;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Utility;
use App\Services\BillingContractService;
use App\Services\BillingInvoiceService;
use App\Services\BillingOccurrenceService;
use App\Services\BillingPaymentService;
use App\Services\BillingRenewalService;
use App\Services\BillingReportService;
use App\Services\InvoiceCreationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Contract Management Phase 4 — billing dashboard + reports,
 * service level (plan §9, §10).
 *
 * Covers: dashboard KPIs (active contracts, forecast estimate,
 * issued, overdue, failed deliveries, stuck generations),
 * forecast / invoiced / collections / overdue / delivery
 * failures / calendar / audit trail / expiring / renewals-due
 * reports, reconciliation of invoiced subtotals to occurrence
 * amounts and of forecast planned amounts to pending
 * occurrences, tenant isolation (AC11) and filter normalisation.
 *
 * Users: 1 = company (contract creator), 2 = operator, 3 =
 * approver, 4 = other company. "Today" is frozen at 2027-01-10.
 * Chart accounts: 1 = 411 receivables, 2 = 721 services revenue.
 * Products are services (affect_stock = 0) so no stock moves.
 */
class BillingReportServiceTest extends TestCase
{
    protected BillingInvoiceService $billing;
    protected BillingContractService $contracts;
    protected BillingPaymentService $payments;
    protected BillingReportService $reports;
    protected BillingRenewalService $renewals;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2027-01-10 09:00:00'));
        $this->createTables();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Operator', 'email' => 'operator@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
        ]);
        DB::table('customers')->insert([
            ['id' => 1, 'customer_id' => 1, 'name' => 'Hoya Hoya Lda', 'email' => 'hoya@test.local', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'customer_id' => 2, 'name' => 'Other Customer', 'email' => 'other@test.local', 'created_by' => 4, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('taxes')->insert([
            ['id' => 1, 'name' => 'IVA 16%', 'rate' => 16, 'created_by' => 1],
            ['id' => 2, 'name' => 'IVA 16%', 'rate' => 16, 'created_by' => 4],
        ]);
        DB::table('product_service_categories')->insert([
            ['id' => 1, 'name' => 'Services', 'type' => 'income', 'chart_account_id' => 2, 'color' => '#000000', 'created_by' => 1],
            ['id' => 2, 'name' => 'Services', 'type' => 'income', 'chart_account_id' => 2, 'color' => '#000000', 'created_by' => 4],
        ]);
        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'name' => 'Clientes', 'code' => 411, 'type' => 1, 'sub_type' => 1, 'is_enabled' => 1, 'created_by' => 1],
            ['id' => 2, 'name' => 'Prestacao de servicos', 'code' => 721, 'type' => 4, 'sub_type' => 1, 'is_enabled' => 1, 'created_by' => 1],
        ]);
        DB::table('product_services')->insert([
            ['id' => 1, 'name' => 'Monthly support', 'sku' => 'SVC-001', 'sale_price' => 5000, 'purchase_price' => 0, 'quantity' => 0, 'tax_id' => '1', 'category_id' => 1, 'unit_id' => 0, 'type' => 'service', 'sale_chartaccount_id' => 2, 'expense_chartaccount_id' => 0, 'affect_stock' => 0, 'created_by' => 1],
            ['id' => 3, 'name' => 'Monthly support', 'sku' => 'SVC-001', 'sale_price' => 5000, 'purchase_price' => 0, 'quantity' => 0, 'tax_id' => '2', 'category_id' => 2, 'unit_id' => 0, 'type' => 'service', 'sale_chartaccount_id' => 2, 'expense_chartaccount_id' => 0, 'affect_stock' => 0, 'created_by' => 4],
            ['id' => 2, 'name' => 'Setup', 'sku' => 'SVC-002', 'sale_price' => 1000, 'purchase_price' => 0, 'quantity' => 0, 'tax_id' => '1', 'category_id' => 1, 'unit_id' => 0, 'type' => 'service', 'sale_chartaccount_id' => 2, 'expense_chartaccount_id' => 0, 'affect_stock' => 0, 'created_by' => 1],
        ]);

        $this->contracts = new BillingContractService(new BillingOccurrenceService());
        $this->billing = new BillingInvoiceService(
            new InvoiceCreationService(),
            new BillingOccurrenceService()
        );
        $this->payments = new BillingPaymentService();
        $this->reports = new BillingReportService();
        $this->renewals = new BillingRenewalService($this->contracts);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Utility::$rates = null;
        Utility::$data = null;
        $this->resetUtilitySettingsCache();

        foreach (['billing_report_exports', 'billing_renewals', 'billing_reminders', 'billing_delivery_attempts', 'email_logs', 'credit_notes', 'invoice_payments', 'transaction_lines', 'invoice_products', 'invoices', 'billing_contract_audit_events', 'billing_contract_revisions', 'billing_contract_occurrences', 'billing_contract_lines', 'billing_contracts', 'stock_reports', 'product_expiry_lots', 'product_services', 'product_service_categories', 'chart_of_accounts', 'settings', 'taxes', 'customers', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Storage::disk('local')->deleteDirectory('billing-invoice-pdfs');
        Storage::disk('local')->deleteDirectory('billing-exports');

        parent::tearDown();
    }

    protected function resetUtilitySettingsCache(): void
    {
        $ref = new \ReflectionClass(Utility::class);
        $prop = $ref->getProperty('getsettingsid');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    // ------------------------------------------------------------------
    // Dashboard
    // ------------------------------------------------------------------

    public function test_dashboard_kpis_for_active_contract(): void
    {
        $this->activeContract();

        $data = $this->reports->dashboard(1);

        $this->assertSame(1, $data['active_contracts']);
        // January pending occurrence: 5,000 ex-tax, still an estimate.
        $this->assertSame(['MZN' => '5000.0000'], $data['forecast_this_month']);
        $this->assertSame([], $data['issued_this_month']);
        $this->assertSame([], $data['overdue_balance']);
        $this->assertSame(0, $data['overdue_count']);
        $this->assertSame(0, $data['failed_deliveries']);
        $this->assertSame(0, $data['stuck_generated']);
        $this->assertTrue($data['expiring_soon']->isEmpty());
    }

    public function test_dashboard_issued_and_overdue_after_invoice_and_time_passes(): void
    {
        $contract = $this->activeContract();
        $this->issueFirstInvoice($contract);

        // The invoice (5,800 total, due 2027-01-25) is now overdue.
        Carbon::setTestNow(Carbon::parse('2027-01-28 09:00:00'));

        $data = $this->reports->dashboard(1);

        $this->assertSame(['MZN' => '5000.0000'], $data['issued_this_month']);
        $this->assertSame(['MZN' => '5800.0000'], $data['overdue_balance']);
        $this->assertSame(1, $data['overdue_count']);
        // The January occurrence is invoiced, so nothing is forecast
        // for January any more (February bills in February).
        $this->assertSame([], $data['forecast_this_month']);
    }

    public function test_dashboard_counts_failed_deliveries_and_stuck_generated(): void
    {
        $contract = $this->activeContract();
        $occurrence = $this->firstOccurrence($contract);
        $this->issueFirstInvoice($contract);

        BillingDeliveryAttempt::create([
            'created_by' => 1,
            'contract_id' => $contract->id,
            'occurrence_id' => $occurrence->id,
            'invoice_id' => Invoice::firstOrFail()->id,
            'channel' => 'email',
            'status' => BillingDeliveryAttempt::STATUS_FAILED,
            'attempt_count' => 4,
            'next_retry_at' => null,
            'last_error' => 'SMTP refused',
        ]);
        BillingContractOccurrence::create([
            'contract_id' => $contract->id,
            'created_by' => 1,
            'occurrence_key' => $contract->reference . ':2099-01:invoice',
            'period_key' => '2099-01',
            'period_start' => '2099-01-01',
            'period_end' => '2099-02-01',
            'billing_date' => '2099-01-01',
            'due_date' => '2099-01-16',
            'amount' => '5000.0000',
            'currency' => 'MZN',
            'state' => BillingContractOccurrence::STATE_GENERATED,
            'purpose' => 'invoice',
        ]);

        $data = $this->reports->dashboard(1);

        $this->assertSame(1, $data['failed_deliveries']);
        $this->assertSame(1, $data['stuck_generated']);
    }

    // ------------------------------------------------------------------
    // Forecast report
    // ------------------------------------------------------------------

    public function test_forecast_report_reconciles_planned_and_issued(): void
    {
        $contract = $this->activeContract();
        $this->issueFirstInvoice($contract);

        $filters = $this->reports->normalizeFilters(['from' => '2027-01-01', 'to' => '2027-03-31']);
        $data = $this->reports->report(1, BillingReportService::REPORT_FORECAST, $filters);

        $this->assertSame('Forecast Revenue (Estimate)', $data['title']);
        $this->assertTrue($data['reconciliation']['matches']);
        $this->assertSame(['MZN' => '10000.0000'], $data['reconciliation']['occurrence_planned_sum']);
        $this->assertSame(['MZN' => '5000.0000'], $data['reconciliation']['occurrence_issued_sum']);

        $rows = collect($data['rows'])->keyBy(fn ($row) => $row[0] . '|' . $row[1]);
        // January: planned 0 (invoiced), issued 5,000.
        $this->assertSame('0.00', $rows['2027-01|MZN'][2]);
        $this->assertSame('5,000.00', $rows['2027-01|MZN'][3]);
        // February + March: planned 5,000 each, issued 0.
        $this->assertSame('5,000.00', $rows['2027-02|MZN'][2]);
        $this->assertSame('0.00', $rows['2027-02|MZN'][3]);
        $this->assertSame('5,000.00', $rows['2027-03|MZN'][2]);
    }

    public function test_forecast_report_excludes_suspended_contract_coverage(): void
    {
        $contract = $this->activeContract();
        $this->contracts->suspend($contract, 2, 'Season closed');

        $filters = $this->reports->normalizeFilters(['from' => '2027-01-01', 'to' => '2027-03-31']);
        $data = $this->reports->report(1, BillingReportService::REPORT_FORECAST, $filters);

        $this->assertSame([], $data['rows']);
        $this->assertTrue($data['reconciliation']['matches']);
    }

    public function test_forecast_report_is_company_scoped(): void
    {
        $contract = $this->activeContract();
        $this->issueFirstInvoice($contract);
        $this->activeContractForCompany(4, 2);

        $filters = $this->reports->normalizeFilters(['from' => '2027-01-01', 'to' => '2027-03-31']);

        $mine = $this->reports->report(1, BillingReportService::REPORT_FORECAST, $filters);
        $theirs = $this->reports->report(4, BillingReportService::REPORT_FORECAST, $filters);

        // Company 1: January issued, February + March planned.
        $this->assertCount(3, $mine['rows']);
        $this->assertSame('10,000.00', $mine['totals']['MZN — Planned (estimate)']);
        $this->assertSame('5,000.00', $mine['totals']['MZN — Issued']);
        // Company 4: all three months still planned (15,000).
        $this->assertCount(3, $theirs['rows']);
        $this->assertSame('15,000.00', $theirs['totals']['MZN — Planned (estimate)']);
    }

    // ------------------------------------------------------------------
    // Invoiced report
    // ------------------------------------------------------------------

    public function test_invoiced_report_reconciles_subtotals_to_occurrences(): void
    {
        $contract = $this->activeContract();
        $this->issueFirstInvoice($contract);
        Carbon::setTestNow(Carbon::parse('2027-02-05 09:00:00'));
        $this->billing->generateDueForContract($contract->fresh(), 2, Carbon::parse('2027-02-05'), false);

        $data = $this->reports->report(1, BillingReportService::REPORT_INVOICED, $this->reports->normalizeFilters([]));

        $this->assertCount(2, $data['rows']);
        $this->assertSame('10,000.00', $data['totals']['MZN — Subtotal']);
        $this->assertSame('1,600.00', $data['totals']['MZN — Tax']);
        $this->assertSame('11,600.00', $data['totals']['MZN — Total']);
        $this->assertTrue($data['reconciliation']['matches']);
        $this->assertSame(['MZN' => '10000.0000'], $data['reconciliation']['occurrence_sum']);
        $this->assertSame(['MZN' => '10000.0000'], $data['reconciliation']['invoice_subtotal_sum']);
        // Coverage period shows the inclusive final day.
        $this->assertSame('2027-01-01 → 2027-01-31', $data['rows'][0][2]);
    }

    // ------------------------------------------------------------------
    // Collections + overdue reports
    // ------------------------------------------------------------------

    public function test_collections_row_reconciles_with_partial_payment(): void
    {
        $contract = $this->activeContract();
        $invoice = $this->issueFirstInvoice($contract);
        $this->recordPayment($invoice, 2000, '2027-01-12');

        $data = $this->reports->report(1, BillingReportService::REPORT_COLLECTIONS, $this->reports->normalizeFilters([]));

        $this->assertCount(1, $data['rows']);
        $row = $data['rows'][0];
        $this->assertSame('5,800.00', $row[6]); // total
        $this->assertSame('2,000.00', $row[7]); // paid
        $this->assertSame('3,800.00', $row[8]); // outstanding
        $this->assertSame('0.00', $row[9]); // adjustments
        $this->assertSame('Partially paid', $row[10]);
        // paid + outstanding + adjustments = total, exactly.
        $this->assertSame(5800.0, (float) str_replace(',', '', $row[7]) + (float) str_replace(',', '', $row[8]) + (float) str_replace(',', '', $row[9]));
    }

    public function test_overdue_report_lists_only_overdue_invoices(): void
    {
        $contract = $this->activeContract();
        $this->issueFirstInvoice($contract);

        // Not overdue yet on 2027-01-10 (due 2027-01-25).
        $data = $this->reports->report(1, BillingReportService::REPORT_OVERDUE, $this->reports->normalizeFilters([]));
        $this->assertSame([], $data['rows']);

        Carbon::setTestNow(Carbon::parse('2027-01-28 09:00:00'));
        $data = $this->reports->report(1, BillingReportService::REPORT_OVERDUE, $this->reports->normalizeFilters([]));

        $this->assertCount(1, $data['rows']);
        $this->assertSame('3', $data['rows'][0][4]); // days overdue
        $this->assertSame('5,800.00', $data['rows'][0][8]);
        $this->assertSame('1', $data['totals']['Overdue invoices']);
    }

    public function test_overdue_report_is_company_scoped(): void
    {
        $this->activeContract();
        $this->issueFirstInvoice(BillingContract::where('created_by', 1)->firstOrFail());
        $other = $this->activeContractForCompany(4, 2);
        $this->billing->generateForOccurrence(
            $this->firstOccurrence($other),
            4,
            Carbon::parse('2027-01-10'),
            false
        );

        Carbon::setTestNow(Carbon::parse('2027-01-28 09:00:00'));

        $mine = $this->reports->report(1, BillingReportService::REPORT_OVERDUE, $this->reports->normalizeFilters([]));
        $theirs = $this->reports->report(4, BillingReportService::REPORT_OVERDUE, $this->reports->normalizeFilters([]));

        $this->assertCount(1, $mine['rows']);
        $this->assertCount(1, $theirs['rows']);
        // Same invoice number on both (per-company sequences), but
        // the customers differ — no row leaks across companies.
        $this->assertSame('Hoya Hoya Lda', $mine['rows'][0][1]);
        $this->assertSame('Other Customer', $theirs['rows'][0][1]);
        $this->assertSame(2, Invoice::count());
    }

    // ------------------------------------------------------------------
    // Delivery failures + calendar + audit trail + expiring
    // ------------------------------------------------------------------

    public function test_delivery_failures_report_shows_final_and_retrying(): void
    {
        $contract = $this->activeContract();
        $occurrence = $this->firstOccurrence($contract);
        $invoice = $this->issueFirstInvoice($contract);

        BillingDeliveryAttempt::create([
            'created_by' => 1,
            'contract_id' => $contract->id,
            'occurrence_id' => $occurrence->id,
            'invoice_id' => $invoice->id,
            'channel' => 'email',
            'status' => BillingDeliveryAttempt::STATUS_FAILED,
            'attempt_count' => 4,
            'next_retry_at' => null,
            'last_error' => 'SMTP refused the connection',
        ]);

        $data = $this->reports->report(1, BillingReportService::REPORT_DELIVERY_FAILURES, $this->reports->normalizeFilters([]));

        $this->assertCount(1, $data['rows']);
        $this->assertSame('Final — resend manually', $data['rows'][0][4]);
        $this->assertSame('SMTP refused the connection', $data['rows'][0][6]);
    }

    public function test_calendar_report_labels_due_and_overdue(): void
    {
        $this->activeContract();

        $filters = $this->reports->normalizeFilters(['from' => '2027-01-01', 'to' => '2027-02-28']);
        $data = $this->reports->report(1, BillingReportService::REPORT_CALENDAR, $filters);

        $this->assertCount(2, $data['rows']);
        // Today is 2027-01-10: January (billing 2027-01-01) is overdue,
        // February (billing 2027-02-01) is due.
        $this->assertSame('Overdue', $data['rows'][0][6]);
        $this->assertSame('Due', $data['rows'][1][6]);
    }

    public function test_audit_trail_report_lists_lifecycle_events_with_actor(): void
    {
        $contract = $this->activeContract();

        $data = $this->reports->report(1, BillingReportService::REPORT_AUDIT_TRAIL, $this->reports->normalizeFilters([]));

        $actions = array_column($data['rows'], 3);
        $this->assertContains('created', $actions);
        $this->assertContains('activated', $actions);
        $activated = collect($data['rows'])->first(fn ($row) => $row[3] === 'activated');
        $this->assertSame($contract->reference, $activated[1]);
        $this->assertSame('Operator', $activated[2]);
        $this->assertStringContainsString('state: draft → active', $activated[5]);

        // Filter by contract: the other company's events never leak in.
        $other = $this->activeContractForCompany(4, 2);
        $filtered = $this->reports->report(1, BillingReportService::REPORT_AUDIT_TRAIL, $this->reports->normalizeFilters(['contract_id' => $other->id]));
        $this->assertSame([], $filtered['rows']);
    }

    public function test_expiring_report_window(): void
    {
        $soon = $this->activeContract(['end_date' => '2027-02-05']);
        $this->activeContract(['end_date' => '2027-03-15']);

        $data = $this->reports->report(1, BillingReportService::REPORT_EXPIRING, $this->reports->normalizeFilters([]));

        $this->assertCount(1, $data['rows']);
        $this->assertSame($soon->reference, $data['rows'][0][0]);
        $this->assertSame('26', $data['rows'][0][3]); // days left from 2027-01-10
        $this->assertSame('5,000.00', $data['rows'][0][6]); // period amount
        $this->assertSame('No renewal', $data['rows'][0][7]);
    }

    public function test_renewals_due_report_shows_pending_renewal(): void
    {
        $contract = $this->activeContract(['end_date' => '2027-02-05']);
        $this->renewals->renew($contract, 1, [
            'title' => 'Monthly support (renewed)',
            'start_date' => '2027-02-06',
            'end_date' => '2030-02-05',
        ]);

        $data = $this->reports->report(1, BillingReportService::REPORT_RENEWALS_DUE, $this->reports->normalizeFilters([]));

        $this->assertCount(1, $data['rows']);
        $this->assertSame('Pending', $data['rows'][0][4]);
        $this->assertStringStartsWith('CNT-2027-', $data['rows'][0][5]);
    }

    // ------------------------------------------------------------------
    // Filters
    // ------------------------------------------------------------------

    public function test_normalize_filters_drops_invalid_dates_and_swaps_range(): void
    {
        $filters = $this->reports->normalizeFilters([
            'from' => '2027-03-01',
            'to' => '2027-01-01',
            'days' => '999',
            'currency' => 'MZN',
        ]);

        $this->assertSame('2027-01-01', $filters['from']);
        $this->assertSame('2027-03-01', $filters['to']);
        $this->assertSame(365, $filters['days']);

        $filters = $this->reports->normalizeFilters(['from' => 'not-a-date', 'frequency' => 'weekly']);
        $this->assertNull($filters['from']);
        $this->assertNull($filters['frequency']);
    }

    public function test_unknown_report_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->reports->report(1, 'no-such-report', $this->reports->normalizeFilters([]));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Active contract for another company (creator 4, customer 2). */
    protected function activeContractForCompany(int $creatorId, int $customerId): BillingContract
    {
        $data = $this->contractData([
            'customer_id' => $customerId,
            'lines' => [
                ['description' => 'Monthly support', 'product_id' => 3, 'quantity' => '1', 'unit_price' => '5000', 'discount_amount' => '0', 'tax_id' => 2, 'chart_account_id' => 2],
            ],
        ]);
        $contract = $this->contracts->createContract($creatorId, $data, $creatorId);
        $this->contracts->submitForApproval($contract, $creatorId);

        return $contract->fresh();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function activeContract(array $overrides = []): BillingContract
    {
        $contract = $this->contracts->createContract(1, $this->contractData($overrides), 1);
        $this->contracts->submitForApproval($contract, 2);

        return $contract->fresh();
    }

    protected function firstOccurrence(BillingContract $contract): BillingContractOccurrence
    {
        return BillingContractOccurrence::where('contract_id', $contract->id)
            ->orderBy('period_start')
            ->firstOrFail();
    }

    /** Issue the first occurrence's invoice (no email) and return it. */
    protected function issueFirstInvoice(BillingContract $contract): Invoice
    {
        $occurrence = $this->firstOccurrence($contract);
        $result = $this->billing->generateForOccurrence($occurrence, 2, Carbon::parse('2027-01-10'), false);

        return $result['invoice'];
    }

    protected function recordPayment(Invoice $invoice, float $amount, string $date): InvoicePayment
    {
        return InvoicePayment::create([
            'invoice_id' => $invoice->id,
            'date' => $date,
            'amount' => $amount,
            'account_id' => 0,
            'payment_method' => 0,
            'payment_type' => 'Manually',
        ]);
    }

    protected function contractData(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => 1,
            'title' => 'Monthly support',
            'description' => null,
            'start_date' => '2027-01-01',
            'end_date' => '2029-12-31',
            'currency' => 'MZN',
            'exchange_rate' => '1',
            'billing_frequency' => 'monthly',
            'custom_interval' => null,
            'custom_unit' => null,
            'anchor_day' => 1,
            'billing_mode' => 'advance',
            'payment_terms_days' => 15,
            'approval_mode' => 'automatic',
            'proration_enabled' => false,
            'partial_period_rule' => 'full',
            'renewal_notice_days' => null,
            'auto_renew' => false,
            'delivery_auto_email' => false,
            'delivery_to' => null,
            'delivery_cc' => null,
            'notes' => null,
            'branch_id' => null,
            'lines' => [
                ['description' => 'Monthly support', 'product_id' => 1, 'quantity' => '1', 'unit_price' => '5000', 'discount_amount' => '0', 'tax_id' => 1, 'chart_account_id' => 2],
            ],
        ], $overrides);
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

        Schema::create('customers', function ($table) {
            $table->bigIncrements('id');
            $table->integer('customer_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('taxes', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->decimal('rate', 10, 2)->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('settings', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by');
            $table->timestamps();
            $table->unique(['name', 'created_by']);
        });

        Schema::create('product_service_categories', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('type')->default(0);
            $table->integer('chart_account_id')->default(0);
            $table->string('color')->default('#fc544b');
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('chart_of_accounts', function ($table) {
            $table->id();
            $table->string('name');
            $table->integer('code')->default(0);
            $table->integer('type')->default(0);
            $table->integer('sub_type')->default(0);
            $table->integer('is_enabled')->default(1);
            $table->text('description')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('product_services', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('sku');
            $table->decimal('sale_price', 16, 2)->default('0.0');
            $table->decimal('purchase_price', 16, 2)->default('0.0');
            $table->float('quantity')->default('0.0');
            $table->string('tax_id', '50')->nullable();
            $table->integer('category_id')->default('0');
            $table->integer('unit_id')->default('0');
            $table->string('type');
            $table->integer('sale_chartaccount_id')->default('0');
            $table->integer('expense_chartaccount_id')->default('0');
            $table->integer('affect_stock')->default(0);
            $table->text('description')->nullable();
            $table->string('pro_image')->nullable();
            $table->integer('created_by')->default('0');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('invoices', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('customer_id');
            $table->date('issue_date');
            $table->date('due_date');
            $table->date('send_date')->nullable();
            $table->integer('category_id');
            $table->text('ref_number')->nullable();
            $table->string('client_po_number')->nullable();
            $table->date('client_po_date')->nullable();
            $table->text('client_po_details')->nullable();
            $table->string('quotation_reference')->nullable();
            $table->integer('billing_address_id')->nullable();
            $table->integer('shipping_address_id')->nullable();
            $table->integer('quotation_id')->nullable();
            $table->integer('proforma_id')->nullable();
            $table->integer('purchase_id')->nullable();
            $table->timestamp('stock_deducted_at')->nullable();
            $table->string('stock_deducted_via')->nullable();
            $table->integer('status')->default('0');
            $table->integer('shipping_display')->default('1');
            $table->integer('discount_apply')->default('0');
            $table->integer('created_by')->default('0');
            $table->timestamps();
        });

        Schema::create('invoice_products', function ($table) {
            $table->bigIncrements('id');
            $table->integer('invoice_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('quantity');
            $table->string('tax', '50')->nullable();
            $table->float('discount')->default('0.00');
            $table->decimal('price', 16, 2)->default('0.0');
            $table->text('description')->nullable();
            $table->text('identifiers')->nullable();
            $table->text('measurement_json')->nullable();
            $table->text('selected_options_json')->nullable();
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

        Schema::create('transaction_lines', function ($table) {
            $table->id();
            $table->integer('account_id');
            $table->string('reference');
            $table->integer('reference_id');
            $table->integer('reference_sub_id');
            $table->date('date');
            $table->decimal('credit', 15, 2)->default('0.00');
            $table->decimal('debit', 15, 2)->default('0.00');
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('stock_reports', function ($table) {
            $table->id();
            $table->integer('product_id')->default('0');
            $table->integer('quantity')->default('0');
            $table->string('type');
            $table->integer('type_id')->default('0');
            $table->text('description')->nullable();
            $table->integer('warehouse_loss_id')->nullable();
            $table->integer('created_by')->default('0');
            $table->timestamps();
        });

        Schema::create('product_expiry_lots', function ($table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->unsignedBigInteger('purchase_product_id')->nullable();
            $table->string('source', 20)->default('purchase');
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity_in', 16, 4)->default(0);
            $table->decimal('quantity_remaining', 16, 4)->default(0);
            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('credit_notes', function ($table) {
            $table->bigIncrements('id');
            $table->integer('invoice')->default('0');
            $table->integer('customer')->default('0');
            $table->decimal('amount', 15, 2)->default('0.00');
            $table->date('date');
            $table->text('description')->nullable();
            $table->integer('is_debit')->default(0);
            $table->timestamps();
        });

        Schema::create('email_logs', function ($table) {
            $table->id();
            $table->unsignedBigInteger('created_by')->default(0);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('recipient');
            $table->string('subject')->nullable();
            $table->string('source')->nullable();
            $table->string('mailable_class')->nullable();
            $table->enum('status', ['success', 'failed']);
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('billing_contracts', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->integer('branch_id')->nullable();
            $table->bigInteger('customer_id');
            $table->string('reference', 30);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->default('1.0000000000');
            $table->string('billing_frequency', 20)->default('monthly');
            $table->integer('custom_interval')->nullable();
            $table->string('custom_unit', 10)->nullable();
            $table->integer('anchor_day');
            $table->string('billing_mode', 10)->default('advance');
            $table->integer('payment_terms_days')->default(15);
            $table->string('approval_mode', 10)->default('approval');
            $table->boolean('proration_enabled')->default(false);
            $table->string('partial_period_rule', 10)->default('full');
            $table->string('state', 20)->default('draft');
            $table->bigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->text('suspended_reason')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->integer('renewal_notice_days')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->boolean('delivery_auto_email')->default(false);
            $table->text('delivery_to')->nullable();
            $table->text('delivery_cc')->nullable();
            $table->text('notes')->nullable();
            $table->bigInteger('current_revision_id')->nullable();
            $table->bigInteger('renewed_from_id')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'reference'], 'bc_company_ref_uq');
        });

        Schema::create('billing_contract_lines', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('contract_id');
            $table->bigInteger('product_id')->nullable();
            $table->string('description', 250);
            $table->decimal('quantity', 20, 4)->default('1.0000');
            $table->decimal('unit_price', 20, 4)->default('1.0000');
            $table->decimal('discount_amount', 20, 4)->default('0.0000');
            $table->bigInteger('tax_id')->nullable();
            $table->bigInteger('chart_account_id')->nullable();
            $table->bigInteger('cost_centre_id')->nullable();
            $table->decimal('amount', 20, 4)->default('0.0000');
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('billing_contract_occurrences', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('contract_id');
            $table->integer('created_by');
            $table->string('occurrence_key', 80);
            $table->string('period_key', 20);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('billing_date');
            $table->date('due_date');
            $table->decimal('amount', 20, 4)->default('0.0000');
            $table->string('currency', 3)->default('MZN');
            $table->string('state', 20)->default('pending');
            $table->string('purpose', 20)->default('invoice');
            $table->bigInteger('invoice_id')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->text('skip_reason')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'contract_id', 'occurrence_key'], 'bco_occ_key_uq');
        });

        Schema::create('billing_contract_revisions', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('contract_id');
            $table->integer('revision_number');
            $table->date('effective_date');
            $table->text('reason')->nullable();
            $table->json('snapshot')->nullable();
            $table->integer('created_by');
            $table->timestamps();
            $table->unique(['contract_id', 'revision_number'], 'bcr_contract_rev_uq');
        });

        Schema::create('billing_contract_audit_events', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('contract_id');
            $table->bigInteger('occurrence_id')->nullable();
            $table->bigInteger('actor_id')->nullable();
            $table->string('action', 50);
            $table->text('reason')->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('billing_delivery_attempts', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('contract_id');
            $table->bigInteger('occurrence_id');
            $table->bigInteger('invoice_id');
            $table->string('channel', 20)->default('email');
            $table->text('recipients_to')->nullable();
            $table->text('recipients_cc')->nullable();
            $table->string('status', 20)->default('queued');
            $table->integer('attempt_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique('invoice_id', 'bda_invoice_uq');
            $table->index(['created_by', 'contract_id'], 'bda_contract_idx');
            $table->index(['created_by', 'status', 'next_retry_at'], 'bda_retry_idx');
        });

        Schema::create('billing_reminders', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('contract_id');
            $table->bigInteger('occurrence_id');
            $table->bigInteger('invoice_id');
            $table->string('status', 20)->default('scheduled');
            $table->integer('sent_count')->default(0);
            $table->integer('attempt_count')->default(0);
            $table->date('next_reminder_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->text('recipients_to')->nullable();
            $table->text('recipients_cc')->nullable();
            $table->timestamps();
            $table->unique(['invoice_id'], 'br_invoice_uq');
            $table->index(['contract_id'], 'br_contract_idx');
            $table->index(['created_by', 'status', 'next_reminder_at'], 'br_due_idx');
        });

        Schema::create('billing_renewals', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('contract_id');
            $table->bigInteger('new_contract_id');
            $table->date('effective_date');
            $table->string('type', 20)->default('manual');
            $table->string('status', 20)->default('pending');
            $table->timestamp('notice_sent_at')->nullable();
            $table->timestamps();
            $table->unique(['new_contract_id'], 'bren_new_contract_uq');
            $table->index(['contract_id'], 'bren_contract_idx');
        });

        Schema::create('billing_report_exports', function ($table) {
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
            $table->unique('token', 'bre_token_uq');
            $table->index(['created_by', 'status'], 'bre_creator_state_idx');
        });
    }
}
