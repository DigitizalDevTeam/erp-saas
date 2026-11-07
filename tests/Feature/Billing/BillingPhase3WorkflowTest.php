<?php

namespace Tests\Feature\Billing;

use App\Mail\CustomerInvoiceSend;
use App\Models\BillingContract;
use App\Models\BillingContractOccurrence;
use App\Models\BillingReminder;
use App\Models\BillingRenewal;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use App\Models\Utility;
use App\Services\BillingContractService;
use App\Services\BillingOccurrenceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Contract Management Phase 3 — payments, reminders, renewals,
 * HTTP level (plan §6, §9; AC12).
 *
 * Covers: payments view (incl. partial-payment state), reminders
 * view + manual reminder send, renewal form + create, ending-soon
 * filter, the billing:send-reminders command, permission denial
 * and cross-company denial.
 *
 * Users: 1 = Company (all billing permissions), 2 = Operator
 * (manage only), 4 = Other Company (both billing permissions),
 * 6 = No Billing Permission. "Today" is frozen at 2027-01-10.
 */
class BillingPhase3WorkflowTest extends TestCase
{
    protected BillingContractService $contracts;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2027-01-10 09:00:00'));
        $this->createTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['manage transaction', 'manage billing contracts', 'approve billing contracts', 'create invoice', 'show invoice'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Operator', 'email' => 'operator@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['manage transaction', 'manage billing contracts', 'approve billing contracts', 'create invoice', 'show invoice']);
        User::findOrFail(2)->givePermissionTo(['manage transaction', 'manage billing contracts']);
        User::findOrFail(3)->givePermissionTo(['manage transaction', 'approve billing contracts']);
        User::findOrFail(4)->givePermissionTo(['manage transaction', 'manage billing contracts', 'approve billing contracts']);
        User::findOrFail(6)->givePermissionTo(['manage transaction']);

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
        ]);

        Mail::fake();

        $this->contracts = new BillingContractService(new BillingOccurrenceService());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Utility::$rates = null;
        Utility::$data = null;

        foreach (['billing_renewals', 'billing_reminders', 'billing_delivery_attempts', 'email_logs', 'webhook_settings', 'credit_notes', 'invoice_payments', 'transaction_lines', 'invoice_products', 'invoices', 'billing_contract_audit_events', 'billing_contract_revisions', 'billing_contract_occurrences', 'billing_contract_lines', 'billing_contracts', 'stock_reports', 'product_expiry_lots', 'product_services', 'product_service_categories', 'chart_of_accounts', 'taxes', 'customers', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates', 'settings', 'branches', 'branch_user'] as $table) {
            Schema::dropIfExists($table);
        }

        Storage::disk('local')->deleteDirectory('billing-invoice-pdfs');

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Payments view
    // ------------------------------------------------------------------

    public function test_payments_view_renders_with_partial_payment(): void
    {
        $contract = $this->activeContract();
        $invoice = $this->issueFirstInvoice($contract);
        $this->recordPayment($invoice, 2000, '2027-01-12');

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('billing-contracts.payments', $contract->id));

        $response->assertOk();
        $response->assertSee('#Fatura00001');
        $response->assertSee('Partially paid');
        $response->assertSee('3,800.00');
        $response->assertSee('2,000.00');
    }

    public function test_payments_view_denied_without_permission(): void
    {
        $contract = $this->activeContract();

        $this->actingAs(User::findOrFail(6))
            ->get(route('billing-contracts.payments', $contract->id))
            ->assertRedirect();
    }

    public function test_payments_view_denied_for_other_company(): void
    {
        $contract = $this->activeContract();

        $this->actingAs(User::findOrFail(4))
            ->get(route('billing-contracts.payments', $contract->id))
            ->assertRedirect(route('billing-contracts.index'));
    }

    // ------------------------------------------------------------------
    // Reminders view + manual send
    // ------------------------------------------------------------------

    public function test_reminders_view_renders_overdue_invoice(): void
    {
        $contract = $this->activeContract();
        $this->issueFirstInvoice($contract);

        // Move past the due date (2027-01-25): the view syncs the
        // reminder row on load.
        Carbon::setTestNow(Carbon::parse('2027-01-28 09:00:00'));

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('billing-contracts.reminders', $contract->id));

        $response->assertOk();
        $response->assertSee('#Fatura00001');
        $response->assertSee('Scheduled');
        $response->assertSee('2027-01-28');
        $this->assertSame(1, BillingReminder::count());
    }

    public function test_remind_now_sends_a_reminder_email(): void
    {
        $contract = $this->activeContract();
        $occurrence = $this->firstOccurrence($contract);
        $this->issueFirstInvoice($contract);

        Carbon::setTestNow(Carbon::parse('2027-01-28 09:00:00'));

        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('billing-contracts.remind-now', [$contract->id, $occurrence->id]));

        $response->assertRedirect();
        $this->assertSame(1, BillingReminder::count());
        $this->assertSame(1, (int) BillingReminder::firstOrFail()->sent_count);
        $this->assertSame(1, Invoice::count());

        Mail::assertSent(CustomerInvoiceSend::class, function (CustomerInvoiceSend $mail) {
            return $mail->isReminder === true;
        });
    }

    public function test_remind_now_denied_without_permission(): void
    {
        $contract = $this->activeContract();
        $occurrence = $this->firstOccurrence($contract);
        $this->issueFirstInvoice($contract);

        Carbon::setTestNow(Carbon::parse('2027-01-28 09:00:00'));

        $this->actingAs(User::findOrFail(6))
            ->post(route('billing-contracts.remind-now', [$contract->id, $occurrence->id]))
            ->assertRedirect();

        $this->assertSame(0, BillingReminder::count());
    }

    public function test_remind_now_for_not_overdue_invoice_is_refused(): void
    {
        $contract = $this->activeContract();
        $occurrence = $this->firstOccurrence($contract);
        $this->issueFirstInvoice($contract);

        // Today is 2027-01-10; the invoice is not due until 2027-01-25.
        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('billing-contracts.remind-now', [$contract->id, $occurrence->id]));

        $response->assertRedirect();
        $this->assertSame(0, BillingReminder::count());
    }

    // ------------------------------------------------------------------
    // Renewals
    // ------------------------------------------------------------------

    public function test_renew_form_and_create_draft(): void
    {
        $contract = $this->activeContract();

        $form = $this->actingAs(User::findOrFail(1))
            ->get(route('billing-contracts.renew-form', $contract->id));
        $form->assertOk();
        $form->assertSee('2030-01-01');
        $form->assertSee('2032-12-31');

        $response = $this->actingAs(User::findOrFail(1))->post(
            route('billing-contracts.renew', $contract->id),
            ['title' => 'Monthly support (renewed)', 'start_date' => '2030-01-01', 'end_date' => '2032-12-31']
        );

        $new = BillingContract::where('reference', 'CNT-2030-0001')->firstOrFail();
        $response->assertRedirect(route('billing-contracts.show', $new->id));
        $this->assertSame(BillingContract::STATE_DRAFT, $new->state);
        $this->assertSame($contract->id, (int) $new->renewed_from_id);
        $this->assertSame('Monthly support (renewed)', $new->title);
        $this->assertSame(1, BillingRenewal::where('contract_id', $contract->id)
            ->where('status', BillingRenewal::STATUS_PENDING)->count());

        // The show page links the renewal draft.
        $show = $this->actingAs(User::findOrFail(1))->get(route('billing-contracts.show', $contract->id));
        $show->assertOk();
        $show->assertSee('CNT-2030-0001');
    }

    public function test_renew_denied_without_permission(): void
    {
        $contract = $this->activeContract();

        $this->actingAs(User::findOrFail(6))
            ->post(route('billing-contracts.renew', $contract->id), [
                'title' => 'Renewed', 'start_date' => '2030-01-01', 'end_date' => '2032-12-31',
            ])
            ->assertRedirect();

        $this->assertSame(1, BillingContract::count());
        $this->assertSame(0, BillingRenewal::count());
    }

    public function test_renew_denied_for_other_company(): void
    {
        $contract = $this->activeContract();

        $this->actingAs(User::findOrFail(4))
            ->get(route('billing-contracts.renew-form', $contract->id))
            ->assertRedirect(route('billing-contracts.index'));

        $this->assertSame(1, BillingContract::count());
    }

    public function test_abandon_renewal_marks_it_not_renewed(): void
    {
        $contract = $this->activeContract();
        $this->actingAs(User::findOrFail(1))->post(
            route('billing-contracts.renew', $contract->id),
            ['title' => 'Monthly support (renewed)', 'start_date' => '2030-01-01', 'end_date' => '2032-12-31']
        );
        $renewal = BillingRenewal::where('contract_id', $contract->id)->firstOrFail();

        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('billing-contracts.abandon-renewal', [$contract->id, $renewal->id]));

        $response->assertRedirect(route('billing-contracts.show', $contract->id));
        $this->assertSame(BillingRenewal::STATUS_NOT_RENEWED, $renewal->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Ending soon
    // ------------------------------------------------------------------

    public function test_index_ending_soon_filter(): void
    {
        $soon = $this->activeContract(['end_date' => '2027-02-05']);
        $later = $this->activeContract(['end_date' => '2027-03-15']);

        $all = $this->actingAs(User::findOrFail(1))->get(route('billing-contracts.index'));
        $all->assertOk();
        $all->assertSee($soon->reference);
        $all->assertSee($later->reference);
        $all->assertSee('Contracts ending soon (30 days)');

        $filtered = $this->actingAs(User::findOrFail(1))
            ->get(route('billing-contracts.index', ['ending_soon' => 1]));
        $filtered->assertOk();
        $filtered->assertSee($soon->reference);
        $filtered->assertDontSee($later->reference);
    }

    // ------------------------------------------------------------------
    // Command
    // ------------------------------------------------------------------

    public function test_send_reminders_command_sends_due_reminders(): void
    {
        $contract = $this->activeContract();
        $this->issueFirstInvoice($contract);

        Carbon::setTestNow(Carbon::parse('2027-01-28 09:00:00'));

        $exit = Artisan::call('billing:send-reminders', ['--date' => '2027-01-28']);

        $this->assertSame(0, $exit);
        $this->assertSame(1, BillingReminder::count());
        $this->assertSame(1, (int) BillingReminder::firstOrFail()->sent_count);
        $this->assertSame(1, Invoice::count());

        Mail::assertSent(CustomerInvoiceSend::class, function (CustomerInvoiceSend $mail) {
            return $mail->isReminder === true;
        });
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

    protected function issueFirstInvoice(BillingContract $contract): Invoice
    {
        $occurrence = $this->firstOccurrence($contract);

        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('billing-contracts.generate-occurrence', [$contract->id, $occurrence->id]));
        $response->assertRedirect(route('billing-contracts.occurrence-show', [$contract->id, $occurrence->id]));

        return Invoice::firstOrFail();
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
        Schema::create('webhook_settings', function ($table) {
            $table->id();
            $table->string('module')->nullable();
            $table->string('url')->nullable();
            $table->string('method')->nullable();
            $table->integer('created_by')->default(0);
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
