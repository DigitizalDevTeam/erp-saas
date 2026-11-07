<?php

namespace Tests\Unit;

use App\Exceptions\CostCentreException;
use App\Models\Bill;
use App\Models\CostCentre;
use App\Models\DimensionAuditEvent;
use App\Models\JournalEntry;
use App\Models\JournalItem;
use App\Models\JournalLineDimension;
use App\Services\CostCentreAllocationService;
use App\Services\CostCentreService;
use App\Services\DimensionPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DimensionPostingServiceTest extends TestCase
{
    protected DimensionPostingService $service;
    protected CostCentreAllocationService $allocations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'code' => '621', 'name' => 'Utilities', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'code' => '421', 'name' => 'Suppliers', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->allocations = new CostCentreAllocationService();
        $this->service = new DimensionPostingService();
    }

    protected function tearDown(): void
    {
        foreach (['journal_line_dimensions', 'treasury_line_dimensions', 'dimension_audit_events', 'cost_centre_allocation_templates', 'cost_centre_allocation_lines', 'cost_centre_allocations', 'projects', 'journal_items', 'journal_entries', 'transaction_lines', 'transactions', 'bank_accounts', 'invoice_payments', 'invoices', 'debit_notes', 'taxes', 'chart_of_accounts', 'product_services', 'bill_accounts', 'bill_products', 'bill_payments', 'bills', 'cost_centres', 'branches', 'settings', 'users'] as $table) {
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
    private function makeJournal(int $journalNo = 1, string $date = '2026-11-07'): array
    {
        $journalId = DB::table('journal_entries')->insertGetId([
            'date' => $date, 'reference' => 'JV-'.$journalNo, 'description' => 'Test journal '.$journalNo,
            'journal_id' => $journalNo, 'status' => 1, 'created_by' => 1,
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

    /** The equal-and-opposite reversal journal of $journal. */
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
        // The bill's posted transaction line (what BillController writes).
        DB::table('transaction_lines')->insert([
            'id' => 1, 'account_id' => 1, 'reference' => 'Bill', 'reference_id' => $billId,
            'reference_sub_id' => 1, 'date' => '2026-11-07', 'debit' => $amount, 'credit' => 0,
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Bill::findOrFail($billId);
    }

    private function amounts($rows): array
    {
        return $rows->map(fn ($r) => number_format((float) $r->allocated_amount, 2, '.', ''))->all();
    }

    // ------------------------------------------------------------------
    // Manual tagging
    // ------------------------------------------------------------------

    public function test_attach_to_journal_item_splits_the_line_amount(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        [$journal, $debitItem] = $this->makeJournal();

        $result = $this->service->attachToLine(
            'journal_item', (int) $debitItem->id,
            $this->split([$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']),
            'percentage', 1, 1
        );

        $this->assertSame('60000.00', $result['total']);
        $this->assertSame(['24000.00', '21000.00', '15000.00'], $this->amounts(collect($result['rows'])->map(fn ($r) => (object) $r)));
        $this->assertTrue(collect($result['rows'])->every(fn ($r) => $r['source'] === 'manual'));

        $stored = JournalLineDimension::forCompany(1)->active()->get();
        $this->assertCount(3, $stored);
        $this->assertTrue($stored->every(fn ($r) => $r->currency_code === 'MZN'));
        $this->assertTrue($stored->every(fn ($r) => (int) $r->journal_id === (int) $journal->id));

        // The journal itself is untouched.
        $this->assertSame('60000.00', number_format((float) JournalItem::find($debitItem->id)->debit, 2, '.', ''));
        $this->assertSame(1, DimensionAuditEvent::where('entity_type', 'journal_line_dimension')->where('action', 'tagged')->count());
    }

    public function test_attach_to_credit_line_produces_negative_amounts(): void
    {
        [$adm, $ops] = $this->makeCentres();
        [, , $creditItem] = $this->makeJournal();

        $result = $this->service->attachToLine(
            'journal_item', (int) $creditItem->id,
            $this->split([$adm, $ops], ['40.000000', '60.000000']),
            'percentage', 1, 1
        );

        $this->assertSame('-60000.00', $result['total']);
        $this->assertSame(['-24000.00', '-36000.00'], $this->amounts(collect($result['rows'])->map(fn ($r) => (object) $r)));
    }

    public function test_attach_to_journal_mirror_normalises_to_the_journal_item(): void
    {
        [$adm, $ops] = $this->makeCentres();
        [$journal, $debitItem] = $this->makeJournal();
        // The TransactionLines mirror JournalEntryPoster writes.
        $mirrorId = DB::table('transaction_lines')->insertGetId([
            'account_id' => 1, 'reference' => 'Journal', 'reference_id' => $journal->id,
            'reference_sub_id' => $debitItem->id, 'date' => '2026-11-07',
            'debit' => 60000, 'credit' => 0, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->service->attachToLine(
            'transaction_line', (int) $mirrorId,
            $this->split([$adm, $ops], ['50.000000', '50.000000']),
            'percentage', 1, 1
        );

        // Stored against the JournalItem, never against the mirror —
        // the same money is dimensioned once, not twice.
        $this->assertSame('journal_item', $result['line_type']);
        $this->assertSame((int) $debitItem->id, $result['line_id']);
        $this->assertSame(0, JournalLineDimension::forCompany(1)->forLine('transaction_line', (int) $mirrorId)->active()->count());
        $this->assertSame(2, JournalLineDimension::forCompany(1)->forLine('journal_item', (int) $debitItem->id)->active()->count());
    }

    public function test_attach_rejects_a_split_that_does_not_sum(): void
    {
        [$adm, $ops] = $this->makeCentres();
        [, $debitItem] = $this->makeJournal();

        $this->expectException(CostCentreException::class);
        $this->service->attachToLine(
            'journal_item', (int) $debitItem->id,
            $this->split([$adm, $ops], ['50.000000', '49.000000']),
            'percentage', 1, 1
        );
    }

    public function test_attach_rejects_a_centre_from_another_company(): void
    {
        [$adm] = $this->makeCentres();
        // A centre owned by company 2.
        $foreign = (new CostCentreService())->create(['code' => 'EXT', 'name' => 'External'], 2, 2);
        [, $debitItem] = $this->makeJournal();

        $this->expectException(CostCentreException::class);
        $this->service->attachToLine(
            'journal_item', (int) $debitItem->id,
            $this->split([$adm, $foreign], ['50.000000', '50.000000']),
            'percentage', 1, 1
        );
    }

    public function test_attach_rejects_a_deactivated_centre(): void
    {
        [$adm, $ops] = $this->makeCentres();
        CostCentre::findOrFail((int) $ops->id)->update(['status' => 'inactive']);
        [, $debitItem] = $this->makeJournal();

        $this->expectException(CostCentreException::class);
        $this->service->attachToLine(
            'journal_item', (int) $debitItem->id,
            $this->split([$adm, $ops], ['50.000000', '50.000000']),
            'percentage', 1, 1
        );
    }

    public function test_retag_supersedes_the_old_rows(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        [, $debitItem] = $this->makeJournal();

        $this->service->attachToLine('journal_item', (int) $debitItem->id,
            $this->split([$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']), 'percentage', 1, 1);
        $this->service->attachToLine('journal_item', (int) $debitItem->id,
            $this->split([$adm, $ops], ['50.000000', '50.000000']), 'percentage', 1, 1);

        $this->assertCount(2, JournalLineDimension::forCompany(1)->active()->get());
        $this->assertCount(3, JournalLineDimension::forCompany(1)->where('status', 'superseded')->get());
        $this->assertSame(['30000.00', '30000.00'], $this->amounts(JournalLineDimension::forCompany(1)->active()->orderBy('id')->get()));
    }

    public function test_attach_to_a_zero_line_throws(): void
    {
        [$adm] = $this->makeCentres();
        [$journal] = $this->makeJournal();
        $zeroId = DB::table('journal_items')->insertGetId([
            'journal' => $journal->id, 'account' => 1, 'description' => 'Zero',
            'debit' => 0, 'credit' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(CostCentreException::class);
        $this->service->attachToLine('journal_item', (int) $zeroId,
            $this->split([$adm], ['100.000000']), 'percentage', 1, 1);
    }

    // ------------------------------------------------------------------
    // Sync from a Phase 1 allocation
    // ------------------------------------------------------------------

    public function test_sync_from_allocation_copies_the_posted_split(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        $bill = $this->makeBill();
        $this->allocations->createPosted('bill', $bill->id, 'bill_product', 1, 'percentage',
            $this->split([$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']), 1, 1);
        $allocation = $this->allocations->postedForLine('bill', $bill->id, 'bill_product', 1, 1);

        $result = $this->service->syncFromAllocation($allocation, 1, 1);

        $this->assertTrue($result['synced']);
        $this->assertSame(3, $result['rows']);
        $rows = JournalLineDimension::forCompany(1)->active()->orderBy('id')->get();
        $this->assertSame(['24000.00', '21000.00', '15000.00'], $this->amounts($rows));
        $this->assertTrue($rows->every(fn ($r) => $r->source === 'inherited'));
        $this->assertTrue($rows->every(fn ($r) => $r->line_type === 'transaction_line' && (int) $r->line_id === 1));

        // Idempotent: a second sync leaves exactly 3 active rows.
        $this->service->syncFromAllocation($allocation, 1, 1);
        $this->assertCount(3, JournalLineDimension::forCompany(1)->active()->get());
    }

    public function test_sync_skips_a_line_with_a_manual_tag(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        $bill = $this->makeBill();
        $this->allocations->createPosted('bill', $bill->id, 'bill_product', 1, 'percentage',
            $this->split([$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']), 1, 1);
        $allocation = $this->allocations->postedForLine('bill', $bill->id, 'bill_product', 1, 1);

        // A manual tag on the same transaction line wins.
        $this->service->attachToLine('transaction_line', 1,
            $this->split([$adm, $ops], ['50.000000', '50.000000']), 'percentage', 1, 1);

        $result = $this->service->syncFromAllocation($allocation, 1, 1);

        $this->assertFalse($result['synced']);
        $this->assertNotSame([], $result['warnings']);
        $this->assertSame(['30000.00', '30000.00'], $this->amounts(JournalLineDimension::forCompany(1)->active()->orderBy('id')->get()));
    }

    public function test_sync_for_bill_syncs_every_posted_allocation(): void
    {
        [$adm, $ops] = $this->makeCentres();
        $bill = $this->makeBill();
        $this->allocations->createPosted('bill', $bill->id, 'bill_product', 1, 'percentage',
            $this->split([$adm, $ops], ['60.000000', '40.000000']), 1, 1);

        $result = $this->service->syncForBill($bill, 1, 1);

        $this->assertSame(1, $result['allocations']);
        $this->assertSame(1, $result['synced']);
        $this->assertSame(2, $result['rows']);
    }

    // ------------------------------------------------------------------
    // Reversal
    // ------------------------------------------------------------------

    public function test_reverse_mirrors_dimensions_with_negated_amounts(): void
    {
        [$adm, $ops, $tec] = $this->makeCentres();
        [$journal, $debitItem] = $this->makeJournal();
        $this->service->attachToLine('journal_item', (int) $debitItem->id,
            $this->split([$adm, $ops, $tec], ['40.000000', '35.000000', '25.000000']), 'percentage', 1, 1);
        $reversal = $this->makeReversalJournal($journal);

        $result = $this->service->reverseDimensionsForJournal((int) $journal->id, (int) $reversal->id, 1, 1, 'Wrong account');

        $this->assertSame(3, $result['reversed_rows']);
        $this->assertFalse($result['already_done']);

        // The original rows stay active and readable.
        $original = JournalLineDimension::forCompany(1)->forJournal((int) $journal->id)->active()->orderBy('id')->get();
        $this->assertCount(3, $original);
        $this->assertSame(['24000.00', '21000.00', '15000.00'], $this->amounts($original));

        // The reversal rows negate them and link back.
        $reversed = JournalLineDimension::forCompany(1)->forJournal((int) $reversal->id)->active()->orderBy('id')->get();
        $this->assertCount(3, $reversed);
        $this->assertSame(['-24000.00', '-21000.00', '-15000.00'], $this->amounts($reversed));
        $this->assertTrue($reversed->every(fn ($r) => $r->source === 'reversal'));
        $this->assertSame(
            $original->pluck('id')->all(),
            $reversed->pluck('reversal_of_id')->map(fn ($v) => (int) $v)->all()
        );

        // Net dimensions across both journals are zero, like the net GL.
        $net = JournalLineDimension::forCompany(1)->active()->sum('allocated_amount');
        $this->assertSame('0.00', number_format((float) $net, 2, '.', ''));

        // Idempotent: a second reversal writes nothing.
        $again = $this->service->reverseDimensionsForJournal((int) $journal->id, (int) $reversal->id, 1, 1, 'Wrong account');
        $this->assertTrue($again['already_done']);
        $this->assertSame(0, $again['reversed_rows']);
        $this->assertCount(6, JournalLineDimension::forCompany(1)->active()->get());
    }

    public function test_reverse_without_a_matching_line_warns_and_skips(): void
    {
        [$adm, $ops] = $this->makeCentres();
        [$journal, $debitItem] = $this->makeJournal();
        $this->service->attachToLine('journal_item', (int) $debitItem->id,
            $this->split([$adm, $ops], ['50.000000', '50.000000']), 'percentage', 1, 1);
        // A "reversal" journal whose lines do not mirror the original.
        $otherId = DB::table('journal_entries')->insertGetId([
            'date' => '2026-11-08', 'reference' => 'JV-9', 'description' => 'Unrelated',
            'journal_id' => 9, 'status' => 1, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('journal_items')->insert([
            'journal' => $otherId, 'account' => 1, 'description' => 'Other',
            'debit' => 100, 'credit' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->service->reverseDimensionsForJournal((int) $journal->id, (int) $otherId, 1, 1, 'Wrong account');

        $this->assertSame(0, $result['reversed_rows']);
        $this->assertNotSame([], $result['warnings']);
        $this->assertCount(2, JournalLineDimension::forCompany(1)->active()->get());
    }

    public function test_reverse_requires_a_reason(): void
    {
        [$adm] = $this->makeCentres();
        [$journal, $debitItem] = $this->makeJournal();
        $this->service->attachToLine('journal_item', (int) $debitItem->id,
            $this->split([$adm], ['100.000000']), 'percentage', 1, 1);
        $reversal = $this->makeReversalJournal($journal);

        $this->expectException(CostCentreException::class);
        $this->service->reverseDimensionsForJournal((int) $journal->id, (int) $reversal->id, 1, 1, '   ');
    }

    // ------------------------------------------------------------------
    // Removal
    // ------------------------------------------------------------------

    public function test_remove_for_line_supersedes_dimensions(): void
    {
        [$adm, $ops] = $this->makeCentres();
        [, $debitItem] = $this->makeJournal();
        $this->service->attachToLine('journal_item', (int) $debitItem->id,
            $this->split([$adm, $ops], ['50.000000', '50.000000']), 'percentage', 1, 1);

        $this->service->removeForLine('journal_item', (int) $debitItem->id, 1, 1, 'Line deleted.');

        $this->assertCount(0, JournalLineDimension::forCompany(1)->active()->get());
        $this->assertCount(2, JournalLineDimension::forCompany(1)->where('status', 'superseded')->get());
    }
}
