<?php

namespace Tests\Unit;

use App\Exceptions\BillingContractException;
use App\Models\BillingContract;
use App\Models\BillingContractOccurrence;
use App\Models\BillingDeliveryAttempt;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\TransactionLines;
use App\Models\Utility;
use App\Services\BillingContractService;
use App\Services\BillingInvoiceService;
use App\Services\BillingOccurrenceService;
use App\Services\InvoiceCreationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Contract Management Phase 2 — recurring invoice generation
 * (plan §2, §6, §7; AC02/AC03/AC07/AC08/AC12).
 *
 * Users: 1 = company (contract creator), 2 = operator, 3 =
 * approver, 4 = other company. "Today" is frozen at 2027-01-10.
 * Chart accounts: 1 = 411 receivables, 2 = 721 services revenue.
 * Products are services (affect_stock = 0) so no stock moves.
 */
class BillingInvoiceServiceTest extends TestCase
{
    protected BillingInvoiceService $billing;
    protected BillingContractService $contracts;

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
        ]);
        DB::table('product_service_categories')->insert([
            ['id' => 1, 'name' => 'Services', 'type' => 'income', 'chart_account_id' => 2, 'color' => '#000000', 'created_by' => 1],
        ]);
        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'name' => 'Clientes', 'code' => 411, 'type' => 1, 'sub_type' => 1, 'is_enabled' => 1, 'created_by' => 1],
            ['id' => 2, 'name' => 'Prestacao de servicos', 'code' => 721, 'type' => 4, 'sub_type' => 1, 'is_enabled' => 1, 'created_by' => 1],
        ]);
        DB::table('product_services')->insert([
            ['id' => 1, 'name' => 'Monthly support', 'sku' => 'SVC-001', 'sale_price' => 5000, 'purchase_price' => 0, 'quantity' => 0, 'tax_id' => '1', 'category_id' => 1, 'unit_id' => 0, 'type' => 'service', 'sale_chartaccount_id' => 2, 'expense_chartaccount_id' => 0, 'affect_stock' => 0, 'created_by' => 1],
            ['id' => 2, 'name' => 'Setup', 'sku' => 'SVC-002', 'sale_price' => 1000, 'purchase_price' => 0, 'quantity' => 0, 'tax_id' => '1', 'category_id' => 1, 'unit_id' => 0, 'type' => 'service', 'sale_chartaccount_id' => 2, 'expense_chartaccount_id' => 0, 'affect_stock' => 0, 'created_by' => 1],
        ]);

        // Mail is NOT faked: the AC08 test needs a real failing
        // SMTP connection, and every other test passes
        // sendEmail=false.
        $this->contracts = new BillingContractService(new BillingOccurrenceService());
        $this->billing = new BillingInvoiceService(
            new InvoiceCreationService(),
            new BillingOccurrenceService()
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Utility::$rates = null;
        Utility::$data = null;
        $this->resetUtilitySettingsCache();

        foreach (['billing_delivery_attempts', 'email_logs', 'credit_notes', 'invoice_payments', 'transaction_lines', 'invoice_products', 'invoices', 'billing_contract_audit_events', 'billing_contract_revisions', 'billing_contract_occurrences', 'billing_contract_lines', 'billing_contracts', 'stock_reports', 'product_expiry_lots', 'product_services', 'product_service_categories', 'chart_of_accounts', 'settings', 'taxes', 'customers', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        // Stored invoice PDFs are per invoice id; ids restart at 1
        // in every test, so a stale PDF from an earlier test would
        // be reused by storedPdfBytes().
        Storage::disk('local')->deleteDirectory('billing-invoice-pdfs');

        parent::tearDown();
    }

    /** Utility::getSettingById caches per id in a private static — clear it so mail settings do not leak between tests. */
    protected function resetUtilitySettingsCache(): void
    {
        $ref = new \ReflectionClass(Utility::class);
        $prop = $ref->getProperty('getsettingsid');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    // ------------------------------------------------------------------
    // One correct invoice
    // ------------------------------------------------------------------

    public function test_due_occurrence_issues_one_correct_invoice(): void
    {
        $contract = $this->activeContract();
        $occurrence = $this->firstOccurrence($contract);

        $result = $this->billing->generateForOccurrence($occurrence, 2, Carbon::parse('2027-01-10'), false);

        $invoice = $result['invoice'];
        $this->assertFalse($result['already_invoiced']);
        $this->assertSame(1, (int) $invoice->invoice_id);
        $this->assertSame(1, (int) $invoice->customer_id);
        $this->assertSame('2027-01-10', $invoice->issue_date);
        $this->assertSame('2027-01-25', $invoice->due_date);
        $this->assertSame('CNT-2027-0001', $invoice->ref_number);
        $this->assertSame(Invoice::STATUS_SENT, (int) $invoice->status);
        $this->assertSame('2027-01-10', $invoice->send_date);
        $this->assertSame(1, (int) $invoice->category_id);

        $items = $invoice->items()->orderBy('id')->get();
        $this->assertCount(1, $items);
        $this->assertSame(1, (int) $items[0]->product_id);
        $this->assertSame(1, (int) $items[0]->quantity);
        $this->assertSame('5000.00', number_format((float) $items[0]->price, 2, '.', ''));
        $this->assertSame('0', number_format((float) $items[0]->discount, 0, '.', ''));
        $this->assertSame('1', $items[0]->tax);
        $this->assertStringContainsString('2027-01-01', $items[0]->description);

        // Totals: subtotal 5000, tax 16% = 800, total 5800.
        $this->assertSame('5800.00', number_format((float) $invoice->getTotal(), 2, '.', ''));
        $this->assertSame('800.00', number_format((float) $invoice->getTotalTax(), 2, '.', ''));

        // Occurrence moved to invoiced and linked.
        $occurrence = $occurrence->fresh();
        $this->assertSame('invoiced', $occurrence->state);
        $this->assertSame((int) $invoice->id, (int) $occurrence->invoice_id);

        // Ledger: revenue credit 5800 (base + tax, as the manual
        // release posts it) and the AR debit 5800 on 411 (the
        // balancing line carries reference 'Invoice Receivable').
        $lines = TransactionLines::where('reference_id', $invoice->id)->get();
        $credits = $lines->where('credit', '>', 0);
        $debits = $lines->where('debit', '>', 0);
        $this->assertSame('5800.00', number_format((float) $credits->sum('credit'), 2, '.', ''));
        $this->assertSame('5800.00', number_format((float) $debits->sum('debit'), 2, '.', ''));
        $this->assertSame(2, (int) $credits->first()->account_id);
        $this->assertSame(1, (int) $debits->first()->account_id);
    }

    public function test_generate_is_idempotent_second_call_returns_same_invoice(): void
    {
        $contract = $this->activeContract();
        $occurrence = $this->firstOccurrence($contract);

        $first = $this->billing->generateForOccurrence($occurrence, 2, Carbon::parse('2027-01-10'), false);
        $second = $this->billing->generateForOccurrence($occurrence->fresh(), 2, Carbon::parse('2027-01-10'), false);

        $this->assertTrue($second['already_invoiced']);
        $this->assertSame((int) $first['invoice']->id, (int) $second['invoice']->id);
        $this->assertSame(1, Invoice::count());
    }

    public function test_future_occurrence_is_not_due(): void
    {
        $contract = $this->activeContract();
        $occurrence = $this->firstOccurrence($contract);

        $this->expectException(BillingContractException::class);
        $this->billing->generateForOccurrence($occurrence, 2, Carbon::parse('2026-12-31'), false);
    }

    public function test_line_without_product_is_refused_with_clear_message(): void
    {
        $contract = $this->contracts->createContract(1, $this->contractData([
            'lines' => [
                ['description' => 'No product line', 'quantity' => '1', 'unit_price' => '100', 'discount_amount' => '0'],
            ],
        ]), 1);
        $this->contracts->submitForApproval($contract, 2);
        $occurrence = $this->firstOccurrence($contract->fresh());

        try {
            $this->billing->generateForOccurrence($occurrence, 2, Carbon::parse('2027-01-10'), false);
            $this->fail('Expected a BillingContractException.');
        } catch (BillingContractException $e) {
            $this->assertStringContainsString('No product line', $e->getMessage());
        }

        $this->assertSame(0, Invoice::count());
        $this->assertSame('pending', $occurrence->fresh()->state);
    }

    public function test_line_without_revenue_account_is_refused(): void
    {
        DB::table('product_services')->insert([
            'id' => 3, 'name' => 'No account service', 'sku' => 'SVC-003', 'sale_price' => 100, 'purchase_price' => 0,
            'quantity' => 0, 'tax_id' => null, 'category_id' => 1, 'unit_id' => 0, 'type' => 'service',
            'sale_chartaccount_id' => 0, 'expense_chartaccount_id' => 0, 'affect_stock' => 0, 'created_by' => 1,
        ]);
        $contract = $this->contracts->createContract(1, $this->contractData([
            'lines' => [
                ['description' => 'Orphan line', 'product_id' => 3, 'quantity' => '1', 'unit_price' => '100', 'discount_amount' => '0'],
            ],
        ]), 1);
        $this->contracts->submitForApproval($contract, 2);
        $occurrence = $this->firstOccurrence($contract->fresh());

        $this->expectException(BillingContractException::class);
        $this->expectExceptionMessageMatches('/no revenue account/');
        $this->billing->generateForOccurrence($occurrence, 2, Carbon::parse('2027-01-10'), false);
    }

    // ------------------------------------------------------------------
    // AC02 — reference scenario: 36 invoices, 2027-01-01 → 2029-12-31
    // ------------------------------------------------------------------

    public function test_ac02_reference_scenario_issues_exactly_36_invoices(): void
    {
        $contract = $this->activeContract([
            'start_date' => '2027-01-01',
            'end_date' => '2029-12-31',
        ]);

        $issued = 0;
        $cursor = Carbon::parse('2027-01-01');
        for ($month = 0; $month < 36; $month++) {
            $results = $this->billing->generateDueForCompany(1, 2, $cursor->copy(), false);
            $issued += $results['generated'];
            $this->assertSame(0, $results['failed'], 'Month ' . $cursor->format('Y-m') . ' failed: ' . json_encode($results['failures']));
            $cursor->addMonthNoOverflow();
        }

        $this->assertSame(36, $issued);
        $this->assertSame(36, Invoice::count());

        $invoices = Invoice::orderBy('invoice_id')->get();
        foreach ($invoices as $i => $invoice) {
            $expectedIssue = Carbon::parse('2027-01-01')->addMonthsNoOverflow($i);
            $this->assertSame($i + 1, (int) $invoice->invoice_id);
            $this->assertSame($expectedIssue->format('Y-m-d'), $invoice->issue_date);
            $this->assertSame($expectedIssue->copy()->addDays(15)->format('Y-m-d'), $invoice->due_date);
            $this->assertSame('5800.00', number_format((float) $invoice->getTotal(), 2, '.', ''));
            $this->assertSame('CNT-2027-0001', $invoice->ref_number);
        }

        $this->assertSame(36, BillingContractOccurrence::where('state', 'invoiced')->count());
        $this->assertSame(0, BillingContractOccurrence::where('state', 'pending')->count());
    }

    // ------------------------------------------------------------------
    // AC03 — double generation
    // ------------------------------------------------------------------

    public function test_ac03_double_generation_creates_one_invoice(): void
    {
        $contract = $this->activeContract();
        $occurrence = $this->firstOccurrence($contract);

        $first = $this->billing->generateForOccurrence($occurrence, 2, Carbon::parse('2027-01-10'), false);
        $second = $this->billing->generateForOccurrence($occurrence->fresh(), 2, Carbon::parse('2027-01-10'), false);
        $third = $this->billing->generateDueForCompany(1, 2, Carbon::parse('2027-01-10'), false);

        $this->assertSame(1, Invoice::count());
        $this->assertSame((int) $first['invoice']->id, (int) $second['invoice']->id);
        $this->assertSame(0, $third['generated']);
        $this->assertSame(0, $third['failed']);
    }

    public function test_ac03_unique_occurrence_key_blocks_a_duplicate_period(): void
    {
        $contract = $this->activeContract();
        $occurrence = $this->firstOccurrence($contract);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('billing_contract_occurrences')->insert([
            'contract_id' => $contract->id,
            'created_by' => 1,
            'occurrence_key' => $occurrence->occurrence_key,
            'period_key' => $occurrence->period_key,
            'period_start' => $occurrence->period_start,
            'period_end' => $occurrence->period_end,
            'billing_date' => $occurrence->billing_date,
            'due_date' => $occurrence->due_date,
            'amount' => $occurrence->amount,
            'currency' => 'MZN',
            'state' => 'pending',
            'purpose' => 'invoice',
        ]);
    }

    // ------------------------------------------------------------------
    // AC07 — approval mode
    // ------------------------------------------------------------------

    public function test_ac07_approval_mode_sends_nothing_until_approved(): void
    {
        $contract = $this->contracts->createContract(1, $this->contractData([
            'approval_mode' => 'approval',
        ]), 1);
        $this->contracts->submitForApproval($contract, 2);

        $this->assertSame('pending_approval', $contract->fresh()->state);
        // No occurrences exist for an unapproved contract.
        $this->assertSame(0, BillingContractOccurrence::where('contract_id', $contract->id)->count());

        $results = $this->billing->generateDueForCompany(1, 2, Carbon::parse('2027-01-10'), false);
        $this->assertSame(0, $results['generated']);
        $this->assertSame(0, Invoice::count());

        // Approve: occurrences appear and the engine can bill.
        $this->contracts->approve($contract->fresh(), 3);
        $this->assertSame('active', $contract->fresh()->state);
        $results = $this->billing->generateDueForCompany(1, 2, Carbon::parse('2027-01-10'), false);
        $this->assertSame(1, $results['generated']);
        $this->assertSame(1, Invoice::count());
    }

    public function test_ac07_hand_inserted_occurrence_for_unapproved_contract_is_refused(): void
    {
        $contract = $this->contracts->createContract(1, $this->contractData([
            'approval_mode' => 'approval',
        ]), 1);
        $this->contracts->submitForApproval($contract, 2);

        $occurrenceId = DB::table('billing_contract_occurrences')->insertGetId([
            'contract_id' => $contract->id,
            'created_by' => 1,
            'occurrence_key' => 'CNT-2027-0001:2027-01:invoice',
            'period_key' => '2027-01',
            'period_start' => '2027-01-01',
            'period_end' => '2027-02-01',
            'billing_date' => '2027-01-01',
            'due_date' => '2027-01-16',
            'amount' => '5000.0000',
            'currency' => 'MZN',
            'state' => 'pending',
            'purpose' => 'invoice',
        ]);
        $occurrence = BillingContractOccurrence::findOrFail($occurrenceId);

        $this->expectException(BillingContractException::class);
        $this->expectExceptionMessageMatches('/only active contracts bill/');
        $this->billing->generateForOccurrence($occurrence, 2, Carbon::parse('2027-01-10'), false);
    }

    // ------------------------------------------------------------------
    // AC08 — email retry, same invoice / same PDF
    // ------------------------------------------------------------------

    public function test_ac08_failed_email_retries_same_invoice_and_same_pdf(): void
    {
        // SMTP that refuses the connection: the first send fails.
        DB::table('settings')->insert([
            ['name' => 'mail_driver', 'value' => 'smtp', 'created_by' => 1],
            ['name' => 'mail_host', 'value' => '127.0.0.1', 'created_by' => 1],
            ['name' => 'mail_port', 'value' => '1', 'created_by' => 1],
            ['name' => 'mail_from_address', 'value' => 'billing@test.local', 'created_by' => 1],
            ['name' => 'mail_from_name', 'value' => 'Billing', 'created_by' => 1],
        ]);

        $contract = $this->activeContract([
            'delivery_auto_email' => true,
            'delivery_to' => 'hoya@test.local',
        ]);
        $occurrence = $this->firstOccurrence($contract);

        $result = $this->billing->generateForOccurrence($occurrence, 2, Carbon::parse('2027-01-10'), true);
        $invoice = $result['invoice'];

        $this->assertSame('failed', $result['email']);
        $this->assertSame(1, Invoice::count());

        $attempt = BillingDeliveryAttempt::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('failed', $attempt->status);
        $this->assertSame(1, (int) $attempt->attempt_count);
        $this->assertNotNull($attempt->next_retry_at);
        $this->assertNotEmpty($attempt->last_error);
        $this->assertNotNull($attempt->pdf_path);

        // The PDF was stored once at issuance.
        $path = $attempt->pdf_path;
        $this->assertTrue(Storage::disk('local')->exists($path));
        $firstHash = md5(Storage::disk('local')->get($path));
        $this->assertStringStartsWith('billing-invoice-pdfs/', $path);

        // Retry too early: the backoff has not elapsed.
        $early = $this->billing->retryDueDeliveries(1, 2);
        $this->assertSame(0, $early['retried']);

        // Move past the 5-minute backoff, switch the mailer to the
        // log driver, and retry: the SAME invoice and SAME PDF.
        Carbon::setTestNow(Carbon::parse('2027-01-10 09:06:00'));
        DB::table('settings')->where('created_by', 1)->where('name', 'mail_driver')->update(['value' => 'log']);
        $this->resetUtilitySettingsCache();

        $retried = $this->billing->retryDueDeliveries(1, 2);
        $this->assertSame(1, $retried['retried']);
        $this->assertSame(1, $retried['sent']);
        $this->assertSame(0, $retried['failed']);

        $this->assertSame(1, Invoice::count());
        $this->assertSame(1, (int) $invoice->fresh()->invoice_id);
        $this->assertSame($firstHash, md5(Storage::disk('local')->get($path)));

        $attempt = $attempt->fresh();
        $this->assertSame('sent', $attempt->status);
        $this->assertSame(2, (int) $attempt->attempt_count);
        $this->assertSame('hoya@test.local', $attempt->recipients_to);
    }

    public function test_ac08_no_recipient_fails_without_retry(): void
    {
        $contract = $this->activeContract([
            'delivery_auto_email' => true,
            'delivery_to' => null,
        ]);
        // Customer without an email address.
        DB::table('customers')->where('id', 1)->update(['email' => null]);
        $occurrence = $this->firstOccurrence($contract);

        $result = $this->billing->generateForOccurrence($occurrence, 2, Carbon::parse('2027-01-10'), true);

        $this->assertSame('failed', $result['email']);
        $attempt = BillingDeliveryAttempt::where('invoice_id', $result['invoice']->id)->firstOrFail();
        $this->assertSame('failed', $attempt->status);
        $this->assertNull($attempt->next_retry_at);
        $this->assertStringContainsString('No recipient', $attempt->last_error);
        $this->assertSame(1, Invoice::count());
    }

    public function test_email_disabled_contract_issues_without_attempt(): void
    {
        $contract = $this->activeContract(['delivery_auto_email' => false]);
        $occurrence = $this->firstOccurrence($contract);

        $result = $this->billing->generateForOccurrence($occurrence, 2, Carbon::parse('2027-01-10'), true);

        $this->assertSame('disabled', $result['email']);
        $this->assertSame(0, BillingDeliveryAttempt::count());
        $this->assertSame(1, Invoice::count());
    }

    // ------------------------------------------------------------------
    // AC12 — partial payment
    // ------------------------------------------------------------------

    public function test_ac12_partial_payment_does_not_duplicate_the_period(): void
    {
        $contract = $this->activeContract();
        $occurrence = $this->firstOccurrence($contract);

        $result = $this->billing->generateForOccurrence($occurrence, 2, Carbon::parse('2027-01-10'), false);
        $invoice = $result['invoice'];

        InvoicePayment::create([
            'invoice_id' => $invoice->id,
            'date' => '2027-01-12',
            'amount' => 2000,
            'account_id' => 0,
            'payment_method' => 0,
            'payment_type' => 'Manually',
        ]);

        $this->assertSame('3800.00', number_format((float) $invoice->fresh()->getDue(), 2, '.', ''));

        // Re-running the generator creates nothing new.
        $results = $this->billing->generateDueForCompany(1, 2, Carbon::parse('2027-01-10'), false);
        $this->assertSame(0, $results['generated']);
        $this->assertSame(1, Invoice::count());
        $this->assertSame('invoiced', $occurrence->fresh()->state);
    }

    // ------------------------------------------------------------------
    // Scoping / proration / guards
    // ------------------------------------------------------------------

    public function test_due_occurrences_exclude_other_companies_and_future_periods(): void
    {
        $contract = $this->activeContract();
        $other = $this->contracts->createContract(4, $this->contractData([
            'customer_id' => 2,
            'lines' => [
                ['description' => 'Monthly support', 'product_id' => 1, 'quantity' => '1', 'unit_price' => '5000', 'discount_amount' => '0', 'tax_id' => null, 'chart_account_id' => 2],
            ],
        ]), 4);
        $this->contracts->submitForApproval($other, 4);

        $due = $this->billing->dueOccurrences(1, Carbon::parse('2027-01-10'));
        $this->assertCount(1, $due);
        $this->assertSame($contract->id, (int) $due->first()->contract_id);

        $results = $this->billing->generateDueForCompany(1, 2, Carbon::parse('2027-01-10'), false);
        $this->assertSame(1, $results['generated']);
        $this->assertSame(1, Invoice::count());
        $this->assertSame(0, Invoice::where('created_by', 4)->count());
    }

    public function test_prorated_period_invoice_matches_occurrence_amount(): void
    {
        $contract = $this->contracts->createContract(1, $this->contractData([
            'start_date' => '2027-01-15',
            'partial_period_rule' => 'prorate',
            'proration_enabled' => true,
        ]), 1);
        $this->contracts->submitForApproval($contract, 2);
        $occurrence = $this->firstOccurrence($contract->fresh());

        // 17 of 31 days covered: 5000 x 17 / 31 = 2741.9355.
        $this->assertSame('2741.9355', number_format((float) $occurrence->amount, 4, '.', ''));

        $result = $this->billing->generateForOccurrence($occurrence, 2, Carbon::parse('2027-01-15'), false);
        $invoice = $result['invoice'];
        $item = $invoice->items()->first();

        // Subtotal (price x qty - discount) equals the occurrence amount.
        $subtotal = (float) $item->price * (int) $item->quantity - (float) $item->discount;
        $this->assertEqualsWithDelta(2741.9355, $subtotal, 0.0001);
        $this->assertStringContainsString('prorated', $item->description);
        $this->assertStringContainsString('17', $item->description);
    }

    public function test_no_income_category_is_refused(): void
    {
        DB::table('product_service_categories')->delete();
        $contract = $this->activeContract();
        $occurrence = $this->firstOccurrence($contract);

        $this->expectException(BillingContractException::class);
        $this->expectExceptionMessageMatches('/No income category/');
        $this->billing->generateForOccurrence($occurrence, 2, Carbon::parse('2027-01-10'), false);
    }

    public function test_suspended_contract_is_not_billed(): void
    {
        $contract = $this->activeContract();
        $this->contracts->suspend($contract->fresh(), 2, 'Paused');
        $this->assertSame('suspended', $contract->fresh()->state);

        $results = $this->billing->generateDueForCompany(1, 2, Carbon::parse('2027-01-10'), false);
        $this->assertSame(0, $results['generated']);
        $this->assertSame(0, Invoice::count());
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
    }
}
