<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\CostCentreAllocation;
use App\Models\DimensionAuditEvent;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\TreasuryLineDimension;
use App\Models\User;
use App\Services\CostCentreAllocationService;
use App\Services\CostCentreService;
use App\Services\TreasuryDimensionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TreasuryDimensionWorkflowTest extends TestCase
{
    protected TreasuryDimensionService $service;
    protected CostCentreAllocationService $allocations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['manage treasury dimensions', 'create payment bill', 'delete payment bill'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['manage treasury dimensions', 'create payment bill', 'delete payment bill']);
        User::findOrFail(2)->givePermissionTo(['manage treasury dimensions']);

        $this->allocations = new CostCentreAllocationService();
        $this->service = new TreasuryDimensionService();
    }

    protected function tearDown(): void
    {
        foreach (['treasury_line_dimensions', 'dimension_audit_events', 'cost_centre_allocation_templates', 'cost_centre_allocation_lines', 'cost_centre_allocations', 'projects', 'journal_entries', 'transaction_lines', 'transactions', 'bank_accounts', 'venders', 'invoice_payments', 'invoices', 'debit_notes', 'taxes', 'chart_of_accounts', 'product_services', 'bill_accounts', 'bill_products', 'bill_payments', 'bills', 'cost_centres', 'branches', 'settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Schema (minimal, in-memory — mirrors the real migrations)
    // ------------------------------------------------------------------

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
            $table->string('reference')->nullable();
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

        // Tables the admin layout / view composers touch when a Blade
        // page renders (same set Phase 0's workflow test creates).
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
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeCentres(): array
    {
        $service = new CostCentreService();
        $adm = $service->create(['code' => 'ADM', 'name' => 'Administration'], 1, 1);
        $ops = $service->create(['code' => 'OPS', 'name' => 'Operations'], 1, 1);
        $tec = $service->create(['code' => 'TEC', 'name' => 'Technology'], 1, 1);

        return [$adm, $ops, $tec];
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

    private function allocateBill(Bill $bill, array $centres, array $percentages): void
    {
        $lines = [];
        foreach ($centres as $i => $centre) {
            $lines[] = ['cost_centre_id' => (int) $centre->id, 'percentage' => $percentages[$i], 'fixed_amount' => null, 'sort_order' => $i];
        }
        $this->allocations->createPosted('bill', $bill->id, 'bill_product', 1, 'percentage', $lines, 1, 1);
    }

    private function makePayment(Bill $bill, string $amount): BillPayment
    {
        $payment = new BillPayment();
        $payment->bill_id = $bill->id;
        $payment->date = '2026-11-07';
        $payment->amount = $amount;
        $payment->account_id = 1;
        $payment->payment_method = 0;
        $payment->save();

        return $payment;
    }

    private function split(array $centres, array $percentages): array
    {
        $lines = [];
        foreach ($centres as $i => $centre) {
            $lines[] = ['cost_centre_id' => (int) $centre->id, 'percentage' => $percentages[$i], 'fixed_amount' => null, 'sort_order' => $i];
        }

        return $lines;
    }

    private function activeRows(string $type, int $paymentId)
    {
        return TreasuryLineDimension::forCompany(1)->forPayment($type, $paymentId)->active()->orderBy('id')->get();
    }

    // ------------------------------------------------------------------
    // Index + permission
    // ------------------------------------------------------------------

    public function test_index_requires_permission(): void
    {
        $response = $this->actingAs(User::findOrFail(6))->get(route('treasury-dimensions.index'));

        $response->assertRedirect();
    }

    public function test_index_lists_payments_with_dimensions(): void
    {
        [$adm, $ops] = $this->makeCentres();
        $bill = $this->makeBill();
        $this->allocateBill($bill, [$adm, $ops], ['60.000000', '40.000000']);
        $payment = $this->makePayment($bill, '30000.00');
        $this->service->inheritForBillPayment($payment, 1, 1);

        $response = $this->actingAs(User::findOrFail(1))->get(route('treasury-dimensions.index'));

        $response->assertOk();
        $response->assertSee('18,000.00');
        $response->assertSee('12,000.00');
        $response->assertSee('ADM');
        $response->assertSee('OPS');
    }

    // ------------------------------------------------------------------
    // Edit + override + reset (bill payment)
    // ------------------------------------------------------------------

    public function test_edit_form_shows_inherited_preview(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        $bill = $this->makeBill();
        $this->allocateBill($bill, [$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']);
        $payment = $this->makePayment($bill, '30000.00');

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('treasury-dimensions.bill-payment.edit', $payment->id));

        $response->assertOk();
        $response->assertSee('12,000.00');
        $response->assertSee('10,500.00');
        $response->assertSee('7,500.00');
        $response->assertSee('Inherited from the bill');
    }

    public function test_override_via_http(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        $bill = $this->makeBill();
        $this->allocateBill($bill, [$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']);
        $payment = $this->makePayment($bill, '30000.00');
        $this->service->inheritForBillPayment($payment, 1, 1);

        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('treasury-dimensions.bill-payment.update', $payment->id), [
                'basis' => 'percentage',
                'lines' => [
                    ['cost_centre_id' => (int) $adm->id, 'percentage' => '50.000000'],
                    ['cost_centre_id' => (int) $ops->id, 'percentage' => '50.000000'],
                ],
            ]);

        $response->assertRedirect(route('treasury-dimensions.index'));
        $rows = $this->activeRows('bill_payment', (int) $payment->id);
        $this->assertCount(2, $rows);
        $this->assertSame(['15000.00', '15000.00'], $rows->pluck('allocated_amount')->map(fn ($v) => number_format((float) $v, 2, '.', ''))->all());
        $this->assertTrue($rows->every(fn ($r) => $r->source === 'manual'));
    }

    public function test_reset_to_inherited_via_http(): void
    {
        [$adm, $ops] = $this->makeCentres();
        $bill = $this->makeBill();
        $this->allocateBill($bill, [$adm, $ops], ['60.000000', '40.000000']);
        $payment = $this->makePayment($bill, '30000.00');
        $this->service->inheritForBillPayment($payment, 1, 1);
        $this->service->applyDimensions('bill_payment', (int) $payment->id,
            $this->split([$adm, $ops], ['50.000000', '50.000000']), 'percentage', 1, 1);

        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('treasury-dimensions.bill-payment.update', $payment->id), [
                'basis' => 'percentage',
                'reset_to_inherited' => '1',
                'lines' => [],
            ]);

        $response->assertRedirect(route('treasury-dimensions.index'));
        $rows = $this->activeRows('bill_payment', (int) $payment->id);
        $this->assertCount(2, $rows);
        $this->assertSame(['18000.00', '12000.00'], $rows->pluck('allocated_amount')->map(fn ($v) => number_format((float) $v, 2, '.', ''))->all());
        $this->assertTrue($rows->every(fn ($r) => $r->source === 'inherited'));
    }

    public function test_validate_endpoint_rejects_ninety_nine_percent(): void
    {
        [$adm, $ops] = $this->makeCentres();
        $bill = $this->makeBill(1000);
        $payment = $this->makePayment($bill, '1000.00');

        $response = $this->actingAs(User::findOrFail(1))
            ->postJson(route('treasury-dimensions.validate'), [
                'payment_type' => 'bill_payment',
                'payment_id' => (int) $payment->id,
                'basis' => 'percentage',
                'lines' => [
                    ['cost_centre_id' => (int) $adm->id, 'percentage' => '50.000000'],
                    ['cost_centre_id' => (int) $ops->id, 'percentage' => '49.000000'],
                ],
            ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('ok', false);
    }

    // ------------------------------------------------------------------
    // Receipt tagging (analysis only)
    // ------------------------------------------------------------------

    public function test_receipt_tag_via_http_never_changes_invoice(): void
    {
        [$adm, $ops] = $this->makeCentres();
        $invoiceId = DB::table('invoices')->insertGetId([
            'invoice_id' => 1, 'customer_id' => 0, 'issue_date' => '2026-11-07',
            'due_date' => '2026-11-07', 'status' => 0, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $receipt = InvoicePayment::create([
            'invoice_id' => $invoiceId, 'date' => '2026-11-07', 'amount' => '5000.00',
            'account_id' => 1, 'payment_method' => 0,
        ]);
        $invoiceBefore = Invoice::findOrFail($invoiceId)->toArray();

        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('treasury-dimensions.receipt.update', $receipt->id), [
                'basis' => 'percentage',
                'lines' => [
                    ['cost_centre_id' => (int) $adm->id, 'percentage' => '70.000000'],
                    ['cost_centre_id' => (int) $ops->id, 'percentage' => '30.000000'],
                ],
            ]);

        $response->assertRedirect(route('treasury-dimensions.index', ['type' => 'receipt']));
        $rows = $this->activeRows('receipt', (int) $receipt->id);
        $this->assertCount(2, $rows);
        $this->assertSame(['3500.00', '1500.00'], $rows->pluck('allocated_amount')->map(fn ($v) => number_format((float) $v, 2, '.', ''))->all());
        // The invoice row is untouched: tagging never creates or changes a debt.
        $this->assertSame($invoiceBefore, Invoice::findOrFail($invoiceId)->toArray());
        $this->assertSame(0, DB::table('transactions')->count());
        $this->assertSame(0, DB::table('transaction_lines')->count());
    }

    // ------------------------------------------------------------------
    // Full happy path: settle a bill via HTTP — cash once, expense once
    // ------------------------------------------------------------------

    public function test_bill_payment_creation_auto_inherits_and_bank_moves_once(): void
    {
        [$adm, $ops] = $this->makeCentres();
        $bill = $this->makeBill();
        $this->allocateBill($bill, [$adm, $ops], ['60.000000', '40.000000']);

        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('bill.payment', $bill->id), [
                'date' => '2026-11-07',
                'amount' => '30000.00',
                'account_id' => 1,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // Exactly one bank movement: opening balance fell by exactly 30,000.
        $this->assertSame('70000.00', number_format((float) DB::table('bank_accounts')->where('id', 1)->value('opening_balance'), 2, '.', ''));
        $this->assertSame(1, DB::table('transactions')
            ->where('type', 'Partial')->where('amount', '30000.00')->where('category', 'Bill')->count());
        $this->assertSame(2, DB::table('transaction_lines')->count());

        // The expense is recognised once, at the bill — the payment adds
        // no second allocation, only dimension rows on the payment.
        $this->assertSame(1, CostCentreAllocation::posted()->count());
        $payment = BillPayment::where('bill_id', $bill->id)->firstOrFail();
        $rows = $this->activeRows('bill_payment', (int) $payment->id);
        $this->assertCount(2, $rows);
        $this->assertSame(['18000.00', '12000.00'], $rows->pluck('allocated_amount')->map(fn ($v) => number_format((float) $v, 2, '.', ''))->all());
        $this->assertTrue($rows->every(fn ($r) => $r->source === 'inherited'));
    }

    // ------------------------------------------------------------------
    // Rejection / return path: deleting a payment supersedes dimensions
    // ------------------------------------------------------------------

    public function test_payment_deletion_supersedes_dimensions(): void
    {
        [$adm, $ops] = $this->makeCentres();
        $bill = $this->makeBill();
        $this->allocateBill($bill, [$adm, $ops], ['60.000000', '40.000000']);

        $this->actingAs(User::findOrFail(1))->post(route('bill.payment', $bill->id), [
            'date' => '2026-11-07', 'amount' => '30000.00', 'account_id' => 1,
        ]);
        $payment = BillPayment::where('bill_id', $bill->id)->firstOrFail();
        $this->assertCount(2, $this->activeRows('bill_payment', (int) $payment->id));
        $this->assertSame('70000.00', number_format((float) DB::table('bank_accounts')->where('id', 1)->value('opening_balance'), 2, '.', ''));

        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('bill.payment.destroy', [$bill->id, $payment->id]));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        // Dimensions superseded; bank balance restored; no bill payment left.
        $this->assertCount(0, $this->activeRows('bill_payment', (int) $payment->id));
        $this->assertSame(2, TreasuryLineDimension::where('payment_id', $payment->id)->where('status', 'superseded')->count());
        $this->assertSame('100000.00', number_format((float) DB::table('bank_accounts')->where('id', 1)->value('opening_balance'), 2, '.', ''));
        $this->assertSame(0, BillPayment::where('bill_id', $bill->id)->count());
        // The bill's allocation still stands: deleting a payment does not
        // un-recognise the expense.
        $this->assertSame(1, CostCentreAllocation::posted()->count());
    }
}
