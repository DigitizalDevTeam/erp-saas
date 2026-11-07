<?php

namespace Tests\Unit;

use App\Models\BillingContract;
use App\Models\BillingContractOccurrence;
use App\Models\BillingReminder;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Utility;
use App\Services\BillingContractService;
use App\Services\BillingInvoiceService;
use App\Services\BillingOccurrenceService;
use App\Services\BillingPaymentService;
use App\Services\BillingReminderService;
use App\Services\InvoiceCreationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Contract Management Phase 3 — overdue-invoice reminders
 * (plan §4, §6; AC12).
 *
 * One reminder row per overdue invoice. Cadence per company:
 * first reminder 3 days after the due date, then every 7 days,
 * at most 3 reminders. A reminder is an email through the same
 * CustomerInvoiceSend mailable with the SAME stored PDF — a
 * failed reminder retries the same invoice/PDF, never a new
 * invoice. Reminders stop when the invoice is settled.
 *
 * Users: 1 = company (contract creator), 2 = operator, 3 =
 * approver, 4 = other company. "Today" is frozen at 2027-01-10
 * unless a test moves it. Chart accounts: 1 = 411 receivables,
 * 2 = 721 services revenue. Products are services
 * (affect_stock = 0) so no stock moves.
 */
class BillingReminderServiceTest extends TestCase
{
    protected BillingInvoiceService $billing;
    protected BillingContractService $contracts;
    protected BillingReminderService $reminders;

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

        $this->contracts = new BillingContractService(new BillingOccurrenceService());
        $this->billing = new BillingInvoiceService(
            new InvoiceCreationService(),
            new BillingOccurrenceService()
        );
        $this->reminders = new BillingReminderService(
            new BillingPaymentService(),
            $this->billing
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Utility::$rates = null;
        Utility::$data = null;
        $this->resetUtilitySettingsCache();

        foreach (['billing_renewals', 'billing_reminders', 'billing_delivery_attempts', 'email_logs', 'credit_notes', 'invoice_payments', 'transaction_lines', 'invoice_products', 'invoices', 'billing_contract_audit_events', 'billing_contract_revisions', 'billing_contract_occurrences', 'billing_contract_lines', 'billing_contracts', 'stock_reports', 'product_expiry_lots', 'product_services', 'product_service_categories', 'chart_of_accounts', 'settings', 'taxes', 'customers', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Storage::disk('local')->deleteDirectory('billing-invoice-pdfs');

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
    // Settings + scheduling
    // ------------------------------------------------------------------

    public function test_reminder_settings_defaults(): void
    {
        $settings = $this->reminders->reminderSettings(1);

        $this->assertSame(3, $settings['first_days']);
        $this->assertSame(7, $settings['interval_days']);
        $this->assertSame(3, $settings['max']);
    }

    public function test_sync_creates_reminder_for_overdue_invoice(): void
    {
        $contract = $this->activeContract();
        $invoice = $this->issueFirstInvoice($contract);

        // Invoice due 2027-01-25; not overdue on 2027-01-20.
        $this->assertSame(['created' => 0, 'stopped' => 0], $this->reminders->syncReminders(1, Carbon::parse('2027-01-20')));
        $this->assertSame(0, BillingReminder::count());

        // Overdue on 2027-01-26: one row, first reminder 3 days after due.
        $synced = $this->reminders->syncReminders(1, Carbon::parse('2027-01-26'));
        $this->assertSame(1, $synced['created']);
        $this->assertSame(1, BillingReminder::count());

        $reminder = BillingReminder::firstOrFail();
        $this->assertSame($invoice->id, (int) $reminder->invoice_id);
        $this->assertSame($contract->id, (int) $reminder->contract_id);
        $this->assertSame(BillingReminder::STATUS_SCHEDULED, $reminder->status);
        $this->assertSame('2027-01-28', $reminder->next_reminder_at->format('Y-m-d'));
        $this->assertSame(0, (int) $reminder->sent_count);
    }

    public function test_sync_is_idempotent(): void
    {
        $contract = $this->activeContract();
        $this->issueFirstInvoice($contract);

        $this->reminders->syncReminders(1, Carbon::parse('2027-01-26'));
        $this->reminders->syncReminders(1, Carbon::parse('2027-01-27'));

        $this->assertSame(1, BillingReminder::count());
    }

    // ------------------------------------------------------------------
    // Cadence
    // ------------------------------------------------------------------

    public function test_cadence_first_at_plus_3_then_plus_7_plus_14_max_3(): void
    {
        $this->useLogMailer();
        $contract = $this->activeContract();
        $invoice = $this->issueFirstInvoice($contract);

        // Too early: the first reminder is due 2027-01-28, not 2027-01-27.
        Carbon::setTestNow(Carbon::parse('2027-01-27 09:00:00'));
        $early = $this->reminders->sendDueReminders(1, 2, Carbon::parse('2027-01-27'));
        $this->assertSame(0, $early['sent']);
        $this->assertSame(0, BillingReminder::firstOrFail()->sent_count);

        // Reminder 1 at +3 (2027-01-28).
        Carbon::setTestNow(Carbon::parse('2027-01-28 09:00:00'));
        $first = $this->reminders->sendDueReminders(1, 2, Carbon::parse('2027-01-28'));
        $this->assertSame(1, $first['sent']);
        $reminder = BillingReminder::firstOrFail();
        $this->assertSame(1, (int) $reminder->sent_count);
        $this->assertSame(BillingReminder::STATUS_SENT, $reminder->status);
        $this->assertSame('2027-02-04', $reminder->next_reminder_at->format('Y-m-d'));
        $this->assertSame(1, Invoice::count());

        // Reminder 2 at +7 after the first (2027-02-04).
        Carbon::setTestNow(Carbon::parse('2027-02-04 09:00:00'));
        $second = $this->reminders->sendDueReminders(1, 2, Carbon::parse('2027-02-04'));
        $this->assertSame(1, $second['sent']);
        $reminder = $reminder->fresh();
        $this->assertSame(2, (int) $reminder->sent_count);
        $this->assertSame('2027-02-11', $reminder->next_reminder_at->format('Y-m-d'));

        // Reminder 3 at +14 after the first (2027-02-11): the max.
        Carbon::setTestNow(Carbon::parse('2027-02-11 09:00:00'));
        $third = $this->reminders->sendDueReminders(1, 2, Carbon::parse('2027-02-11'));
        $this->assertSame(1, $third['sent']);
        $reminder = $reminder->fresh();
        $this->assertSame(3, (int) $reminder->sent_count);
        $this->assertSame(BillingReminder::STATUS_STOPPED, $reminder->status);
        $this->assertNull($reminder->next_reminder_at);

        // Nothing more is ever sent.
        Carbon::setTestNow(Carbon::parse('2027-02-18 09:00:00'));
        $fourth = $this->reminders->sendDueReminders(1, 2, Carbon::parse('2027-02-18'));
        $this->assertSame(0, $fourth['sent']);
        $this->assertSame(3, (int) $reminder->fresh()->sent_count);
        $this->assertSame(1, Invoice::count());
    }

    public function test_reminder_email_is_a_reminder_not_a_new_invoice(): void
    {
        $this->useLogMailer();
        $contract = $this->activeContract();
        $invoice = $this->issueFirstInvoice($contract);

        Carbon::setTestNow(Carbon::parse('2027-01-28 09:00:00'));
        $this->reminders->sendDueReminders(1, 2, Carbon::parse('2027-01-28'));

        $this->assertSame(1, Invoice::count());

        $log = DB::table('email_logs')->where('source', 'billing_contract_reminder')->first();
        $this->assertNotNull($log);
        $this->assertSame('hoya@test.local', $log->recipient);
        $this->assertStringContainsString('Payment reminder', $log->subject);
        $this->assertStringContainsString((string) $invoice->invoice_id, $log->subject);
    }

    // ------------------------------------------------------------------
    // Stop when paid
    // ------------------------------------------------------------------

    public function test_reminders_stop_when_invoice_is_paid(): void
    {
        $this->useLogMailer();
        $contract = $this->activeContract();
        $invoice = $this->issueFirstInvoice($contract);

        // One reminder sent at +3.
        Carbon::setTestNow(Carbon::parse('2027-01-28 09:00:00'));
        $this->reminders->sendDueReminders(1, 2, Carbon::parse('2027-01-28'));
        $this->assertSame(1, (int) BillingReminder::firstOrFail()->sent_count);

        // Pay in full; the next sync stops the reminder.
        InvoicePayment::create([
            'invoice_id' => $invoice->id, 'date' => '2027-01-30', 'amount' => 5800,
            'account_id' => 0, 'payment_method' => 0, 'payment_type' => 'Manually',
        ]);
        Carbon::setTestNow(Carbon::parse('2027-02-04 09:00:00'));
        $result = $this->reminders->sendDueReminders(1, 2, Carbon::parse('2027-02-04'));

        $this->assertSame(0, $result['sent']);
        $this->assertSame(BillingReminder::STATUS_STOPPED, BillingReminder::firstOrFail()->status);
        $this->assertSame(1, (int) BillingReminder::firstOrFail()->sent_count);
    }

    public function test_send_rechecks_balance_before_sending(): void
    {
        $this->useLogMailer();
        $contract = $this->activeContract();
        $invoice = $this->issueFirstInvoice($contract);
        $this->reminders->syncReminders(1, Carbon::parse('2027-01-26'));

        // Pay in full AFTER the reminder row was created but BEFORE
        // it is sent: the send must stop, not send.
        InvoicePayment::create([
            'invoice_id' => $invoice->id, 'date' => '2027-01-27', 'amount' => 5800,
            'account_id' => 0, 'payment_method' => 0, 'payment_type' => 'Manually',
        ]);

        Carbon::setTestNow(Carbon::parse('2027-01-28 09:00:00'));
        $reminder = BillingReminder::firstOrFail();
        $result = $this->reminders->sendReminder($reminder, 2);

        $this->assertSame('stopped', $result['status']);
        $this->assertSame(0, (int) $reminder->fresh()->sent_count);
    }

    // ------------------------------------------------------------------
    // Failed reminder retries the same invoice/PDF
    // ------------------------------------------------------------------

    public function test_failed_reminder_retries_same_invoice_and_pdf(): void
    {
        $contract = $this->activeContract();
        $invoice = $this->issueFirstInvoice($contract);
        $firstHash = md5($this->billing->storedPdfBytes($invoice));

        // No mail driver configured: the send fails (real SMTP attempt).
        Carbon::setTestNow(Carbon::parse('2027-01-28 09:00:00'));
        $result = $this->reminders->sendDueReminders(1, 2, Carbon::parse('2027-01-28'));
        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['failed']);

        $reminder = BillingReminder::firstOrFail();
        $this->assertSame(BillingReminder::STATUS_FAILED, $reminder->status);
        $this->assertSame(1, (int) $reminder->attempt_count);
        $this->assertNotNull($reminder->next_retry_at);
        $this->assertNotEmpty($reminder->last_error);
        $this->assertSame(0, (int) $reminder->sent_count);

        // Too early: the 5-minute backoff has not elapsed.
        $early = $this->reminders->sendDueReminders(1, 2, Carbon::parse('2027-01-28'));
        $this->assertSame(0, $early['sent']);

        // Move past the backoff, switch to the log driver, retry:
        // the SAME invoice and the SAME PDF.
        Carbon::setTestNow(Carbon::parse('2027-01-28 09:06:00'));
        $this->useLogMailer();
        $retried = $this->reminders->sendDueReminders(1, 2, Carbon::parse('2027-01-28'));
        $this->assertSame(1, $retried['sent']);

        $this->assertSame(1, Invoice::count());
        $this->assertSame($firstHash, md5($this->billing->storedPdfBytes($invoice)));

        $reminder = $reminder->fresh();
        $this->assertSame(BillingReminder::STATUS_SENT, $reminder->status);
        $this->assertSame(1, (int) $reminder->sent_count);
        $this->assertSame(0, (int) $reminder->attempt_count);
        $this->assertNull($reminder->next_retry_at);
    }

    public function test_no_recipient_fails_without_retry(): void
    {
        $contract = $this->activeContract([
            'delivery_auto_email' => true,
            'delivery_to' => null,
        ]);
        DB::table('customers')->where('id', 1)->update(['email' => null]);
        $this->issueFirstInvoice($contract);

        Carbon::setTestNow(Carbon::parse('2027-01-28 09:00:00'));
        $result = $this->reminders->sendDueReminders(1, 2, Carbon::parse('2027-01-28'));

        $this->assertSame(0, $result['sent']);
        $reminder = BillingReminder::firstOrFail();
        $this->assertSame(BillingReminder::STATUS_FAILED, $reminder->status);
        $this->assertNull($reminder->next_retry_at);
        $this->assertStringContainsString('No recipient', $reminder->last_error);
        $this->assertSame(1, Invoice::count());
    }

    public function test_manual_send_ignores_the_cadence(): void
    {
        $this->useLogMailer();
        $contract = $this->activeContract();
        $this->issueFirstInvoice($contract);
        $this->reminders->syncReminders(1, Carbon::parse('2027-01-26'));

        // Manual send one day before the first reminder is due.
        Carbon::setTestNow(Carbon::parse('2027-01-27 09:00:00'));
        $reminder = BillingReminder::firstOrFail();
        $result = $this->reminders->sendReminderNow($reminder, 2);

        $this->assertSame('sent', $result['status']);
        $reminder = $reminder->fresh();
        $this->assertSame(1, (int) $reminder->sent_count);
        // The cadence continues from the manual send: next at +7.
        $this->assertSame('2027-02-04', $reminder->next_reminder_at->format('Y-m-d'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Switch the mailer to the log driver (same pattern as Phase 2 AC08). */
    protected function useLogMailer(): void
    {
        DB::table('settings')->updateOrInsert(
            ['name' => 'mail_driver', 'created_by' => 1],
            ['value' => 'log']
        );
        $this->resetUtilitySettingsCache();
    }

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

    protected function issueFirstInvoice(BillingContract $contract): Invoice
    {
        $occurrence = $this->firstOccurrence($contract);
        $result = $this->billing->generateForOccurrence($occurrence, 2, Carbon::parse('2027-01-10'), false);

        return $result['invoice'];
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
    }
}
