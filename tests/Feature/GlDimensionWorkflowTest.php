<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\DimensionAuditEvent;
use App\Models\JournalEntry;
use App\Models\JournalItem;
use App\Models\JournalLineDimension;
use App\Models\User;
use App\Services\CostCentreAllocationService;
use App\Services\CostCentreService;
use App\Services\DimensionPostingService;
use App\Services\TreasuryDimensionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class GlDimensionWorkflowTest extends TestCase
{
    protected DimensionPostingService $posting;
    protected CostCentreAllocationService $allocations;
    protected TreasuryDimensionService $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['manage gl dimensions', 'view cost centre reports', 'export cost centre reports'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['manage gl dimensions', 'view cost centre reports', 'export cost centre reports']);
        User::findOrFail(2)->givePermissionTo(['view cost centre reports']);

        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'code' => '621', 'name' => 'Utilities', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'code' => '421', 'name' => 'Suppliers', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->allocations = new CostCentreAllocationService();
        $this->posting = new DimensionPostingService();
        $this->treasury = new TreasuryDimensionService();
    }

    protected function tearDown(): void
    {
        foreach (['journal_line_dimensions', 'treasury_line_dimensions', 'dimension_audit_events', 'cost_centre_allocation_templates', 'cost_centre_allocation_lines', 'cost_centre_allocations', 'projects', 'journal_items', 'journal_entries', 'transaction_lines', 'transactions', 'bank_accounts', 'venders', 'invoice_payments', 'invoices', 'debit_notes', 'taxes', 'chart_of_accounts', 'product_services', 'bill_accounts', 'bill_products', 'bill_payments', 'bills', 'cost_centres', 'branches', 'settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Schema (same minimal schema as the Treasury workflow test, plus
    // journal_items and journal_line_dimensions)
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

    private function split(array $centres, array $percentages): array
    {
        $lines = [];
        foreach ($centres as $i => $centre) {
            $lines[] = ['cost_centre_id' => (int) $centre->id, 'percentage' => $percentages[$i], 'fixed_amount' => null, 'sort_order' => $i];
        }

        return $lines;
    }

    /** A balanced manual journal: debit 621 60000, credit 421 60000. Returns [journal, debitItem, creditItem]. */
    private function makeJournal(int $journalNo = 1, string $date = '2026-11-07', int $createdBy = 1): array
    {
        $journalId = DB::table('journal_entries')->insertGetId([
            'date' => $date, 'reference' => 'JV-'.$journalNo, 'description' => 'Test journal '.$journalNo,
            'journal_id' => $journalNo, 'status' => 1, 'created_by' => $createdBy,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $debitId = DB::table('journal_items')->insertGetId([
            'journal' => $journalId, 'account' => 1, 'description' => 'Utilities',
            'debit' => 60000, 'credit' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $creditId = DB::table('journal_items')->insertGetId([
            'journal' => $journalId, 'account' => 2, 'description' => 'Suppliers',
            'debit' => 0, 'credit' => 60000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [JournalEntry::findOrFail($journalId), JournalItem::findOrFail($debitId), JournalItem::findOrFail($creditId)];
    }

    private function makeReversalJournal(JournalEntry $journal, int $journalNo = 2, string $date = '2026-11-08'): JournalEntry
    {
        $journalId = DB::table('journal_entries')->insertGetId([
            'date' => $date, 'reference' => 'JV-'.$journalNo, 'description' => 'Reversal of JV-'.$journal->journal_id,
            'journal_id' => $journalNo, 'status' => 1, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $items = JournalItem::where('journal', $journal->id)->orderBy('id')->get();
        foreach ($items as $item) {
            DB::table('journal_items')->insert([
                'journal' => $journalId, 'account' => $item->account, 'description' => $item->description,
                'debit' => $item->credit, 'credit' => $item->debit,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return JournalEntry::findOrFail($journalId);
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
        DB::table('bill_products')->insert([
            'id' => 1, 'bill_id' => $billId, 'product_id' => 1, 'quantity' => 1,
            'tax' => null, 'discount' => 0, 'price' => $amount, 'description' => 'Utilities',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('transaction_lines')->insert([
            'id' => 1, 'account_id' => 1, 'reference' => 'Bill', 'reference_id' => $billId,
            'reference_sub_id' => 1, 'date' => '2026-11-07', 'debit' => $amount, 'credit' => 0,
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Bill::findOrFail($billId);
    }

    private function makePayment(Bill $bill, string $amount, string $date = '2026-11-07'): BillPayment
    {
        $payment = new BillPayment();
        $payment->bill_id = $bill->id;
        $payment->date = $date;
        $payment->amount = $amount;
        $payment->account_id = 1;
        $payment->payment_method = 0;
        $payment->save();

        return $payment;
    }

    // ------------------------------------------------------------------
    // GL dimensions — list + tag
    // ------------------------------------------------------------------

    public function test_index_requires_permission(): void
    {
        $response = $this->actingAs(User::findOrFail(6))->get(route('gl-dimensions.index'));

        $response->assertRedirect();
    }

    public function test_index_lists_journals_with_coverage(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        [$journal, $debitItem] = $this->makeJournal();
        $this->posting->attachToLine('journal_item', (int) $debitItem->id,
            $this->split([$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']), 'percentage', 1, 1);

        $response = $this->actingAs(User::findOrFail(1))->get(route('gl-dimensions.index'));

        $response->assertOk();
        $response->assertSee('JV-1');
        // One of the two journal lines is tagged.
        $response->assertSee('1 / 2');
        $response->assertSee('Untagged');
    }

    public function test_edit_form_shows_lines_and_splits(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        [$journal, $debitItem] = $this->makeJournal();
        $this->posting->attachToLine('journal_item', (int) $debitItem->id,
            $this->split([$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']), 'percentage', 1, 1);

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('gl-dimensions.journal.edit', $journal->id));

        $response->assertOk();
        // The form pre-fills the saved split as percentages; the allocated
        // amounts are computed client-side, so they are not in the HTML.
        $response->assertSee('60,000.00');
        $response->assertSee('40.000000');
        $response->assertSee('35.000000');
        $response->assertSee('25.000000');
        $response->assertSee('ADM');
    }

    public function test_tag_lines_via_http(): void
    {
        [$adm, $ops] = $this->makeCentres();
        [$journal, $debitItem] = $this->makeJournal();

        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('gl-dimensions.journal.update', $journal->id), [
                'lines' => [
                    [
                        'line_id' => (int) $debitItem->id,
                        'basis' => 'percentage',
                        'lines' => [
                            ['cost_centre_id' => (int) $adm->id, 'percentage' => '60.000000'],
                            ['cost_centre_id' => (int) $ops->id, 'percentage' => '40.000000'],
                        ],
                    ],
                ],
            ]);

        $response->assertRedirect(route('gl-dimensions.index'));
        $rows = JournalLineDimension::forCompany(1)->active()->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['36000.00', '24000.00'],
            $rows->pluck('allocated_amount')->map(fn ($v) => number_format((float) $v, 2, '.', ''))->all());
        $this->assertSame(1, DimensionAuditEvent::where('entity_type', 'journal_line_dimension')->where('action', 'tagged')->count());
    }

    public function test_update_rejects_ninety_nine_percent(): void
    {
        [$adm, $ops] = $this->makeCentres();
        [$journal, $debitItem] = $this->makeJournal();

        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('gl-dimensions.journal.update', $journal->id), [
                'lines' => [
                    [
                        'line_id' => (int) $debitItem->id,
                        'basis' => 'percentage',
                        'lines' => [
                            ['cost_centre_id' => (int) $adm->id, 'percentage' => '50.000000'],
                            ['cost_centre_id' => (int) $ops->id, 'percentage' => '49.000000'],
                        ],
                    ],
                ],
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertCount(0, JournalLineDimension::forCompany(1)->active()->get());
    }

    public function test_validate_endpoint_rejects_ninety_nine_percent(): void
    {
        [$adm, $ops] = $this->makeCentres();
        [$journal, $debitItem] = $this->makeJournal();

        $response = $this->actingAs(User::findOrFail(1))
            ->postJson(route('gl-dimensions.validate'), [
                'line_type' => 'journal_item',
                'line_id' => (int) $debitItem->id,
                'basis' => 'percentage',
                'lines' => [
                    ['cost_centre_id' => (int) $adm->id, 'percentage' => '50.000000'],
                    ['cost_centre_id' => (int) $ops->id, 'percentage' => '49.000000'],
                ],
            ]);

        $response->assertUnprocessable();
        $response->assertJsonPath('ok', false);
    }

    public function test_edit_of_another_companys_journal_is_forbidden(): void
    {
        [$journal] = $this->makeJournal(1, '2026-11-07', 2);

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('gl-dimensions.journal.edit', $journal->id));

        $response->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Reports
    // ------------------------------------------------------------------

    public function test_expense_report_renders_with_accrual_label(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        [, $debitItem] = $this->makeJournal();
        $this->posting->attachToLine('journal_item', (int) $debitItem->id,
            $this->split([$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']), 'percentage', 1, 1);

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-reports.expense', ['from' => '2026-11-01', 'to' => '2026-11-30']));

        $response->assertOk();
        $response->assertSee('Accrual basis');
        $response->assertSee('24,000.00');
        $response->assertSee('21,000.00');
        $response->assertSee('15,000.00');
    }

    public function test_cash_report_renders_with_cash_label(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        $bill = $this->makeBill();
        $this->allocations->createPosted('bill', $bill->id, 'bill_product', 1, 'percentage',
            $this->split([$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']), 1, 1);
        $payment = $this->makePayment($bill, '30000.00');
        $this->treasury->inheritForBillPayment($payment, 1, 1);

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-reports.cash', ['from' => '2026-11-01', 'to' => '2026-11-30']));

        $response->assertOk();
        $response->assertSee('Cash basis');
        $response->assertSee('12,000.00');
        $response->assertSee('10,500.00');
        $response->assertSee('7,500.00');
    }

    public function test_reports_require_the_view_permission(): void
    {
        $response = $this->actingAs(User::findOrFail(6))
            ->get(route('cost-centre-reports.expense'));

        $response->assertRedirect();
    }

    public function test_reconciliation_report_shows_a_mismatch(): void
    {
        [$adm, $ops] = $this->makeCentres();
        [, $debitItem] = $this->makeJournal();
        $this->posting->attachToLine('journal_item', (int) $debitItem->id,
            $this->split([$adm, $ops], ['50.000000', '50.000000']), 'percentage', 1, 1);
        JournalLineDimension::forCompany(1)->where('cost_centre_id', (int) $ops->id)
            ->update(['allocated_amount' => '20000.00']);

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-reports.reconciliation', ['from' => '2026-11-01', 'to' => '2026-11-30']));

        $response->assertOk();
        $response->assertSee('Difference');
        // Difference is GL − dimensioned: 60,000 − 50,000 = +10,000.
        $response->assertSee('10,000.00');
        $response->assertSee('60,000.00');
    }

    public function test_unassigned_report_lists_an_undimensioned_line(): void
    {
        $this->makeJournal();

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-reports.unassigned', ['from' => '2026-11-01', 'to' => '2026-11-30']));

        $response->assertOk();
        $response->assertSee('Expense lines with no dimensions');
        $response->assertSee('journal_item');
        $response->assertSee('60,000.00');
        $response->assertSee('No dimensions');
    }

    public function test_csv_export_downloads(): void
    {
        [$adm, $ops] = $this->makeCentres();
        [, $debitItem] = $this->makeJournal();
        $this->posting->attachToLine('journal_item', (int) $debitItem->id,
            $this->split([$adm, $ops], ['60.000000', '40.000000']), 'percentage', 1, 1);

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('cost-centre-reports.expense', ['type' => 'expense', 'from' => '2026-11-01', 'to' => '2026-11-30', 'export' => 'csv']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('ADM', $csv);
        $this->assertStringContainsString('36000.00', $csv);
    }

    public function test_csv_export_requires_the_export_permission(): void
    {
        $response = $this->actingAs(User::findOrFail(2))
            ->get(route('cost-centre-reports.expense', ['export' => 'csv']));

        $response->assertRedirect();
    }

    // ------------------------------------------------------------------
    // Reversal
    // ------------------------------------------------------------------

    public function test_reverse_via_http(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        [$journal, $debitItem] = $this->makeJournal();
        $this->posting->attachToLine('journal_item', (int) $debitItem->id,
            $this->split([$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']), 'percentage', 1, 1);
        $reversal = $this->makeReversalJournal($journal);

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('gl-dimensions.reverse'));

        $response->assertOk();
        $response->assertSee('JV-2');

        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('gl-dimensions.reverse.store'), [
                'original_journal_id' => (int) $journal->id,
                'reversal_journal_id' => (int) $reversal->id,
                'reason' => 'Wrong account',
            ]);

        $response->assertRedirect(route('gl-dimensions.index'));
        $reversed = JournalLineDimension::forCompany(1)->forJournal((int) $reversal->id)->active()->orderBy('id')->get();
        $this->assertCount(3, $reversed);
        $this->assertSame(['-24000.00', '-21000.00', '-15000.00'],
            $reversed->pluck('allocated_amount')->map(fn ($v) => number_format((float) $v, 2, '.', ''))->all());
        // The original rows are still there, untouched.
        $this->assertCount(3, JournalLineDimension::forCompany(1)->forJournal((int) $journal->id)->active()->get());
    }
}
