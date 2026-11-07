<?php

namespace Tests\Unit;

use App\Exceptions\CostCentreException;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\CostCentre;
use App\Models\DimensionAuditEvent;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\TreasuryLineDimension;
use App\Services\CostCentreAllocationService;
use App\Services\CostCentreService;
use App\Services\TreasuryDimensionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TreasuryDimensionServiceTest extends TestCase
{
    protected TreasuryDimensionService $service;
    protected CostCentreAllocationService $allocations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        $this->allocations = new CostCentreAllocationService();
        $this->service = new TreasuryDimensionService();
    }

    protected function tearDown(): void
    {
        foreach (['treasury_line_dimensions', 'dimension_audit_events', 'cost_centre_allocation_templates', 'cost_centre_allocation_lines', 'cost_centre_allocations', 'projects', 'journal_entries', 'transaction_lines', 'transactions', 'bank_accounts', 'invoice_payments', 'invoices', 'debit_notes', 'taxes', 'chart_of_accounts', 'product_services', 'bill_accounts', 'bill_products', 'bill_payments', 'bills', 'cost_centres', 'branches', 'settings', 'users'] as $table) {
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

        Schema::create('bills', function ($table) {
            $table->bigIncrements('id');
            $table->integer('bill_id')->default(0);
            $table->integer('vender_id')->default(0);
            $table->date('bill_date')->nullable();
            $table->date('due_date')->nullable();
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

    private function makeBill(int $amount = 60000, string $type = 'Bill'): Bill
    {
        $billId = DB::table('bills')->insertGetId([
            'bill_id' => 1, 'vender_id' => 0, 'bill_date' => '2026-11-07', 'due_date' => '2026-11-07',
            'status' => 0, 'type' => $type, 'user_type' => 'vendor', 'category_id' => 0,
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
        DB::table('bill_products')->insert([
            'id' => 1, 'bill_id' => $billId, 'product_id' => 1, 'quantity' => 1,
            'tax' => null, 'discount' => 0, 'price' => $amount, 'description' => 'Utilities',
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

    private function makePayment(Bill $bill, string $amount, string $date = '2026-11-07'): BillPayment
    {
        // BillPayment::$fillable does not include amount — set it directly,
        // the same way BillController::createPayment does.
        $payment = new BillPayment();
        $payment->bill_id = $bill->id;
        $payment->date = $date;
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

    private function amounts($rows): array
    {
        return $rows->map(fn ($r) => (string) $r->allocated_amount)->all();
    }

    // ------------------------------------------------------------------
    // Inheritance — a payment settles a bill (default)
    // ------------------------------------------------------------------

    public function test_inherit_half_payment_splits_pro_rata(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        $bill = $this->makeBill(60000);
        $this->allocateBill($bill, [$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']);
        $payment = $this->makePayment($bill, '30000.00');

        $result = $this->service->inheritForBillPayment($payment, 1, 1);

        $rows = $this->activeRows('bill_payment', (int) $payment->id);
        $this->assertCount(3, $rows);
        $this->assertSame(['12000.00', '10500.00', '7500.00'], $this->amounts($rows));
        $this->assertTrue($rows->every(fn ($r) => $r->source === 'inherited'));
        $this->assertSame('30000.00', $result['inherited_total']);
        // The expense stays recognised at the bill: allocations untouched.
        $this->assertSame('60000.00', (string) \App\Models\CostCentreAllocation::posted()->first()->source_amount);
        // Audit trail written.
        $this->assertSame(1, DimensionAuditEvent::where('entity_type', 'treasury_line_dimension')
            ->where('entity_id', $payment->id)->where('action', 'inherited')->count());
    }

    public function test_inherit_full_payment_inherits_full_split(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        $bill = $this->makeBill(60000);
        $this->allocateBill($bill, [$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']);
        $payment = $this->makePayment($bill, '60000.00');

        $this->service->inheritForBillPayment($payment, 1, 1);

        $this->assertSame(['24000.00', '21000.00', '15000.00'],
            $this->amounts($this->activeRows('bill_payment', (int) $payment->id)));
    }

    public function test_inherit_is_idempotent(): void
    {
        [$adm, $ops] = $this->makeCentres();
        $bill = $this->makeBill(60000);
        $this->allocateBill($bill, [$adm, $ops], ['60.000000', '40.000000']);
        $payment = $this->makePayment($bill, '30000.00');

        $first = $this->service->inheritForBillPayment($payment, 1, 1);
        $second = $this->service->inheritForBillPayment($payment, 1, 1);

        $this->assertFalse($first['already_tagged']);
        $this->assertTrue($second['already_tagged']);
        $this->assertCount(2, $this->activeRows('bill_payment', (int) $payment->id));
        $this->assertSame(1, DimensionAuditEvent::where('action', 'inherited')->count());
    }

    public function test_inherit_partial_allocation_covers_only_allocated_share(): void
    {
        [$adm, $ops] = $this->makeCentres();
        $bill = $this->makeBill(60000);
        // Line 1 (40,000) allocated 100% to ADM; add a second line of
        // 20,000 with no allocation at all.
        DB::table('bill_products')->insert([
            'id' => 2, 'bill_id' => $bill->id, 'product_id' => 1, 'quantity' => 1,
            'tax' => null, 'discount' => 0, 'price' => 20000, 'description' => 'Unallocated',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('bill_products')->where('id', 1)->update(['price' => 40000]);
        $this->allocations->createPosted('bill', $bill->id, 'bill_product', 1, 'percentage',
            [['cost_centre_id' => (int) $adm->id, 'percentage' => '100.000000', 'fixed_amount' => null, 'sort_order' => 0]], 1, 1);

        $payment = $this->makePayment($bill, '30000.00');
        $result = $this->service->inheritForBillPayment($payment, 1, 1);

        // Half the bill settles: half of the allocated line (20,000),
        // nothing for the unallocated line.
        $rows = $this->activeRows('bill_payment', (int) $payment->id);
        $this->assertCount(1, $rows);
        $this->assertSame(['20000.00'], $this->amounts($rows));
        $this->assertSame('20000.00', $result['inherited_total']);
        $warnings = $this->service->warningsForPayment('bill_payment', (int) $payment->id, 1);
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('20000.00', $warnings[0]);
        $this->assertStringContainsString('30000.00', $warnings[0]);
    }

    public function test_inherit_with_no_allocations_creates_nothing(): void
    {
        $this->makeCentres();
        $bill = $this->makeBill(60000);
        $payment = $this->makePayment($bill, '30000.00');

        $result = $this->service->inheritForBillPayment($payment, 1, 1);

        $this->assertCount(0, $this->activeRows('bill_payment', (int) $payment->id));
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_inherit_overpayment_caps_at_bill_split(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        $bill = $this->makeBill(60000);
        $this->allocateBill($bill, [$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']);
        $payment = $this->makePayment($bill, '70000.00');

        $result = $this->service->inheritForBillPayment($payment, 1, 1);

        // At most the bill's full split is inherited.
        $this->assertSame(['24000.00', '21000.00', '15000.00'],
            $this->amounts($this->activeRows('bill_payment', (int) $payment->id)));
        $this->assertNotEmpty($result['warnings']);
    }

    public function test_inherit_skips_inactive_centre_with_warning(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        $bill = $this->makeBill(60000);
        $this->allocateBill($bill, [$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']);
        (new CostCentreService())->deactivate($tec->fresh(), 1, 1);
        $payment = $this->makePayment($bill, '60000.00');

        $result = $this->service->inheritForBillPayment($payment, 1, 1);

        // TEC's share is skipped entirely — not folded into the others.
        $rows = $this->activeRows('bill_payment', (int) $payment->id);
        $this->assertCount(2, $rows);
        $this->assertSame(['24000.00', '21000.00'], $this->amounts($rows));
        $this->assertSame('45000.00', $result['inherited_total']);
        $this->assertNotEmpty($result['warnings']);
    }

    // ------------------------------------------------------------------
    // Override — one payment, many centres
    // ------------------------------------------------------------------

    public function test_override_replaces_inherited_rows(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        $bill = $this->makeBill(60000);
        $this->allocateBill($bill, [$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']);
        $payment = $this->makePayment($bill, '30000.00');
        $this->service->inheritForBillPayment($payment, 1, 1);

        $result = $this->service->applyDimensions(
            'bill_payment', (int) $payment->id,
            $this->split([$adm, $ops], ['50.000000', '50.000000']),
            'percentage', 1, 1
        );

        $rows = $this->activeRows('bill_payment', (int) $payment->id);
        $this->assertCount(2, $rows);
        $this->assertSame(['15000.00', '15000.00'], $this->amounts($rows));
        $this->assertTrue($rows->every(fn ($r) => $r->source === 'manual'));
        // The old inherited set is superseded, never edited in place.
        $this->assertSame(3, TreasuryLineDimension::where('payment_id', $payment->id)->where('status', 'superseded')->count());
        $this->assertSame(1, DimensionAuditEvent::where('action', 'overridden')->count());
        $this->assertNotEmpty($result['warnings']); // Differs from the bill.
    }

    public function test_override_with_fixed_amounts(): void
    {
        [$adm, $ops] = $this->makeCentres();
        $bill = $this->makeBill(60000);
        $this->allocateBill($bill, [$adm, $ops], ['60.000000', '40.000000']);
        $payment = $this->makePayment($bill, '30000.00');

        $this->service->applyDimensions('bill_payment', (int) $payment->id, [
            ['cost_centre_id' => (int) $adm->id, 'percentage' => null, 'fixed_amount' => '18000.00', 'sort_order' => 0],
            ['cost_centre_id' => (int) $ops->id, 'percentage' => null, 'fixed_amount' => '12000.00', 'sort_order' => 1],
        ], 'fixed_amount', 1, 1);

        $rows = $this->activeRows('bill_payment', (int) $payment->id);
        $this->assertSame(['18000.00', '12000.00'], $this->amounts($rows));
        $this->assertTrue($rows->every(fn ($r) => $r->percentage === null));
    }

    public function test_override_rejects_ninety_nine_percent(): void
    {
        [$adm, $ops] = $this->makeCentres();
        $bill = $this->makeBill(1000);
        $payment = $this->makePayment($bill, '1000.00');

        $this->expectException(CostCentreException::class);
        $this->service->applyDimensions('bill_payment', (int) $payment->id,
            $this->split([$adm, $ops], ['50.000000', '49.000000']), 'percentage', 1, 1);
    }

    public function test_override_rejects_foreign_centre(): void
    {
        [$adm] = $this->makeCentres();
        $foreign = (new CostCentreService())->create(['code' => 'FOR', 'name' => 'Foreign'], 4, 4);
        $bill = $this->makeBill(1000);
        $payment = $this->makePayment($bill, '1000.00');

        $this->expectException(CostCentreException::class);
        $this->service->applyDimensions('bill_payment', (int) $payment->id,
            $this->split([$adm, $foreign], ['50.000000', '50.000000']), 'percentage', 1, 1);
    }

    public function test_override_with_project_tags_rows(): void
    {
        [$adm] = $this->makeCentres();
        $projectId = DB::table('projects')->insertGetId([
            'project_name' => 'Hotel Refurb', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $bill = $this->makeBill(1000);
        $payment = $this->makePayment($bill, '1000.00');

        $this->service->applyDimensions('bill_payment', (int) $payment->id,
            [['cost_centre_id' => (int) $adm->id, 'percentage' => '100.000000', 'fixed_amount' => null, 'sort_order' => 0]],
            'percentage', 1, 1, (int) $projectId);

        $rows = $this->activeRows('bill_payment', (int) $payment->id);
        $this->assertSame((int) $projectId, (int) $rows->first()->project_id);
    }

    // ------------------------------------------------------------------
    // Bank movement once / tagging never moves money
    // ------------------------------------------------------------------

    public function test_tagging_never_moves_money(): void
    {
        [$adm, $ops] = $this->makeCentres();
        $bill = $this->makeBill(60000);
        $this->allocateBill($bill, [$adm, $ops], ['60.000000', '40.000000']);
        $payment = $this->makePayment($bill, '30000.00');
        DB::table('bank_accounts')->insert([
            'id' => 1, 'holder_name' => 'Main', 'bank_name' => 'Bank',
            'account_number' => '1', 'opening_balance' => 100000.00,
            'chart_account_id' => 1, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // A bank movement already exists from the payment flow itself.
        DB::table('transaction_lines')->insert([
            'account_id' => 1, 'reference' => 'Bill Payment Bank', 'reference_id' => $bill->id,
            'reference_sub_id' => $payment->id, 'date' => '2026-11-07',
            'debit' => 0, 'credit' => 30000.00, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->service->inheritForBillPayment($payment, 1, 1);
        $this->service->applyDimensions('bill_payment', (int) $payment->id,
            $this->split([$adm, $ops], ['50.000000', '50.000000']), 'percentage', 1, 1);

        // Tagging + overriding moved nothing: one bank movement, unchanged balance.
        $this->assertSame('100000.00', number_format((float) DB::table('bank_accounts')->where('id', 1)->value('opening_balance'), 2, '.', ''));
        $this->assertSame(1, DB::table('transaction_lines')->count());
        $this->assertSame(0, DB::table('transactions')->count());
    }

    public function test_receipt_tagging_never_touches_invoice_or_bank(): void
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
        DB::table('bank_accounts')->insert([
            'id' => 1, 'holder_name' => 'Main', 'bank_name' => 'Bank',
            'account_number' => '1', 'opening_balance' => 100000.00,
            'chart_account_id' => 1, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $invoiceBefore = Invoice::findOrFail($invoiceId)->toArray();

        $this->service->tagReceipt($receipt, $this->split([$adm, $ops], ['70.000000', '30.000000']), 'percentage', 1, 1);

        $rows = $this->activeRows('receipt', (int) $receipt->id);
        $this->assertCount(2, $rows);
        $this->assertSame(['3500.00', '1500.00'], $this->amounts($rows));
        // Nothing else moved: invoice row, bank balance, ledger all identical.
        $this->assertSame($invoiceBefore, Invoice::findOrFail($invoiceId)->toArray());
        $this->assertSame('100000.00', number_format((float) DB::table('bank_accounts')->where('id', 1)->value('opening_balance'), 2, '.', ''));
        $this->assertSame(0, DB::table('transaction_lines')->count());
        $this->assertSame(0, DB::table('transactions')->count());
        $this->assertSame(1, DimensionAuditEvent::where('action', 'tagged')->count());
    }

    // ------------------------------------------------------------------
    // Warnings (warn, never block)
    // ------------------------------------------------------------------

    public function test_warning_when_dimensions_required_but_missing(): void
    {
        $this->makeCentres();
        $bill = $this->makeBill(1000);
        $payment = $this->makePayment($bill, '1000.00');

        // Optional (default): no warning for a missing dimension.
        $this->assertSame([], $this->service->warningsForPayment('bill_payment', (int) $payment->id, 1));

        // Required: the same payment now warns.
        DB::table('settings')->insert([
            'name' => 'dimension_on_cash_transactions', 'value' => 'required',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $warnings = $this->service->warningsForPayment('bill_payment', (int) $payment->id, 1);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('requires dimensions', $warnings[0]);
    }

    public function test_warning_when_override_differs_from_bill(): void
    {
        [$adm, $ops] = $this->makeCentres();
        $bill = $this->makeBill(60000);
        $this->allocateBill($bill, [$adm, $ops], ['60.000000', '40.000000']);
        $payment = $this->makePayment($bill, '30000.00');
        $this->service->inheritForBillPayment($payment, 1, 1);

        // Inherited split matches the bill: no differs warning.
        $this->assertSame([], $this->service->warningsForPayment('bill_payment', (int) $payment->id, 1));

        // Override to a different split: the warning appears.
        $this->service->applyDimensions('bill_payment', (int) $payment->id,
            $this->split([$adm, $ops], ['50.000000', '50.000000']), 'percentage', 1, 1);
        $warnings = $this->service->warningsForPayment('bill_payment', (int) $payment->id, 1);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('differ', $warnings[0]);
    }

    public function test_receipts_never_warn(): void
    {
        [$adm] = $this->makeCentres();
        $invoiceId = DB::table('invoices')->insertGetId([
            'invoice_id' => 1, 'customer_id' => 0, 'issue_date' => '2026-11-07',
            'due_date' => '2026-11-07', 'status' => 0, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $receipt = InvoicePayment::create([
            'invoice_id' => $invoiceId, 'date' => '2026-11-07', 'amount' => '5000.00',
            'account_id' => 1, 'payment_method' => 0,
        ]);
        DB::table('settings')->insert([
            'name' => 'dimension_on_cash_transactions', 'value' => 'required',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame([], $this->service->warningsForPayment('receipt', (int) $receipt->id, 1));
    }

    // ------------------------------------------------------------------
    // Removal + guards
    // ------------------------------------------------------------------

    public function test_remove_for_payment_supersedes_rows(): void
    {
        [$adm, $ops] = $this->makeCentres();
        $bill = $this->makeBill(60000);
        $this->allocateBill($bill, [$adm, $ops], ['60.000000', '40.000000']);
        $payment = $this->makePayment($bill, '30000.00');
        $this->service->inheritForBillPayment($payment, 1, 1);

        $this->service->removeForPayment('bill_payment', (int) $payment->id, 1, 1, 'Bill payment deleted.');

        $this->assertCount(0, $this->activeRows('bill_payment', (int) $payment->id));
        $this->assertSame(2, TreasuryLineDimension::where('payment_id', $payment->id)->where('status', 'superseded')->count());
        $this->assertSame(1, DimensionAuditEvent::where('action', 'removed')->count());
    }

    public function test_cross_company_payment_is_rejected(): void
    {
        $this->makeCentres();
        $billId = DB::table('bills')->insertGetId([
            'bill_id' => 9, 'vender_id' => 0, 'bill_date' => '2026-11-07', 'due_date' => '2026-11-07',
            'status' => 0, 'type' => 'Bill', 'user_type' => 'vendor', 'category_id' => 0,
            'order_number' => 0, 'suppress_inventory' => 1, 'stock_on_delivery' => 0,
            'created_by' => 4, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $payment = $this->makePayment(Bill::findOrFail($billId), '1000.00');

        $this->expectException(CostCentreException::class);
        $this->service->inheritForBillPayment($payment, 1, 1);
    }

    public function test_closed_period_blocks_override_but_only_warns_on_inherit(): void
    {
        [$adm] = $this->makeCentres();
        $bill = $this->makeBill(1000);
        $this->allocations->createPosted('bill', $bill->id, 'bill_product', 1, 'percentage',
            [['cost_centre_id' => (int) $adm->id, 'percentage' => '100.000000', 'fixed_amount' => null, 'sort_order' => 0]], 1, 1);
        $payment = $this->makePayment($bill, '1000.00');
        DB::table('journal_entries')->insert([
            'reference' => 'FY-2026-CLOSE-1', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Inheritance does not throw — the payment already happened — it warns.
        $result = $this->service->inheritForBillPayment($payment, 1, 1);
        $this->assertCount(0, $this->activeRows('bill_payment', (int) $payment->id));
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('closed', strtolower($result['warnings'][0]));

        // An explicit override in a closed period is refused.
        $this->expectException(CostCentreException::class);
        $this->service->applyDimensions('bill_payment', (int) $payment->id,
            [['cost_centre_id' => (int) $adm->id, 'percentage' => '100.000000', 'fixed_amount' => null, 'sort_order' => 0]],
            'percentage', 1, 1);
    }
}
