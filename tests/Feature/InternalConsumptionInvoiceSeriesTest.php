<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Pos;
use App\Models\PosPayment;
use App\Models\PosProduct;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\Transaction;
use App\Models\TransactionLines;
use App\Models\User;
use App\Models\Utility;
use App\Services\InvoiceCreationService;
use App\Services\PosToInvoiceService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Internal Consumption separate invoice series (option B,
 * Riz 2026-11-03; expense account 611 confirmed 2026-11-08).
 *
 * Covers: #INT numbering from 1 independent of sales, sales
 * numbering excluding internal invoices, Dr 611 / Cr purchase
 * expense ledger with no sales income / AR / bank payment, old
 * internal invoices keeping their sales-series number, #INT
 * rendering on the show page, duplicate staying in the #INT
 * series, the sales path untouched, and loud failure without 611.
 *
 * Fixture: company user 1, chart 611 (Cost of Inventories Sold
 * or Consumed), 612 (purchase expense), 711 (sales income),
 * 411 (clients/AR), 111 (bank); product "Staff Meal" 500 with
 * sale account 711 + expense account 612; bank opening 100,000.
 */
class InternalConsumptionInvoiceSeriesTest extends TestCase
{
    protected ChartOfAccount $account611;
    protected ChartOfAccount $accountExpense;
    protected ChartOfAccount $accountIncome;
    protected ChartOfAccount $accountReceivable;
    protected ChartOfAccount $accountBank;
    protected ProductService $meal;
    protected BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->createTables();
        $this->seedBase();
    }

    protected function tearDown(): void
    {
        Utility::$rates = null;
        Utility::$data = null;

        foreach (['custom_field_values', 'custom_fields', 'invoice_bank_transfers', 'proformas', 'quotations', 'road_manifests', 'delivery_notes', 'pos_shifts', 'pos_tables', 'customer_shipping_addresses', 'customer_billing_addresses', 'product_service_units', 'transactions', 'bank_accounts', 'pos_payments', 'pos_products', 'pos', 'credit_notes', 'invoice_payments', 'transaction_lines', 'invoice_products', 'invoices', 'product_services', 'product_service_categories', 'chart_of_accounts', 'taxes', 'customers', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates', 'settings', 'branches', 'branch_user'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Numbering
    // ------------------------------------------------------------------

    public function test_internal_order_creates_int_invoice_numbered_from_one(): void
    {
        $pos = $this->makePos(Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION, 1, [[$this->meal, 2, 500]]);

        $invoice = app(PosToInvoiceService::class)->createInvoiceFromPos($pos);

        $this->assertNotNull($invoice);
        $this->assertSame(Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION, $invoice->document_type);
        $this->assertTrue($invoice->isInternal());
        $this->assertSame(1, (int) $invoice->invoice_id);
        $this->assertSame('#INT00001', $invoice->ref_number);
        $this->assertSame('#INT00001', $invoice->displayNumber());
        $this->assertSame(4, (int) $invoice->status);
        $this->assertSame(0, $invoice->getDue());
    }

    public function test_second_internal_invoice_increments_int_series(): void
    {
        $first = app(PosToInvoiceService::class)->createInvoiceFromPos(
            $this->makePos(Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION, 1, [[$this->meal, 1, 500]])
        );
        $second = app(PosToInvoiceService::class)->createInvoiceFromPos(
            $this->makePos(Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION, 2, [[$this->meal, 1, 500]])
        );

        $this->assertSame(1, (int) $first->invoice_id);
        $this->assertSame(2, (int) $second->invoice_id);
        $this->assertSame('#INT00001', $first->ref_number);
        $this->assertSame('#INT00002', $second->ref_number);
        $this->assertSame('#INT00002', $second->displayNumber());
    }

    public function test_sales_numbering_excludes_internal_invoices(): void
    {
        // Internal first: #INT 1 (invoice_id 1 in its own series).
        $internal = app(PosToInvoiceService::class)->createInvoiceFromPos(
            $this->makePos(Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION, 1, [[$this->meal, 1, 500]])
        );
        $this->assertSame(1, (int) $internal->invoice_id);

        // Then two cash sales: they must NOT skip to 2 and 3 —
        // the sales series starts at 1 as if no internal existed.
        $sale1 = app(PosToInvoiceService::class)->createInvoiceFromPos(
            $this->makePos(Pos::DOCUMENT_TYPE_CASH_SALE, 2, [[$this->meal, 1, 500]])
        );
        $sale2 = app(PosToInvoiceService::class)->createInvoiceFromPos(
            $this->makePos(Pos::DOCUMENT_TYPE_CASH_SALE, 3, [[$this->meal, 1, 500]])
        );

        $this->assertSame(1, (int) $sale1->invoice_id);
        $this->assertSame(2, (int) $sale2->invoice_id);
        $this->assertSame('POS-2', $sale1->ref_number);
        $this->assertSame('#Fatura00001', $sale1->displayNumber());

        // The shared invoice path (manual + contract invoices)
        // agrees: next sales number is 3, not 4.
        $this->assertSame(3, app(InvoiceCreationService::class)->nextInvoiceNumber(1));
        $this->assertSame(1, Invoice::where('document_type', Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION)->count());
        $this->assertSame(2, Invoice::whereNull('document_type')->count());
    }

    public function test_old_internal_invoice_keeps_sales_series_number(): void
    {
        // Legacy row: an internal order invoiced BEFORE the series
        // existed, so it sits in the sales series (no marker).
        $legacyPos = $this->makePos(Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION, 7, [[$this->meal, 1, 500]]);
        $legacy = Invoice::create([
            'invoice_id' => 57,
            'pos_id' => $legacyPos->id,
            'document_type' => null,
            'customer_id' => 0,
            'issue_date' => date('Y-m-d'),
            'due_date' => date('Y-m-d'),
            'ref_number' => 'POS-7',
            'status' => 4,
            'category_id' => 1,
            'created_by' => 1,
        ]);

        // A new internal order still starts the #INT series at 1.
        $internal = app(PosToInvoiceService::class)->createInvoiceFromPos(
            $this->makePos(Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION, 8, [[$this->meal, 1, 500]])
        );
        $this->assertSame(1, (int) $internal->invoice_id);
        $this->assertSame('#INT00001', $internal->displayNumber());

        // The legacy row is untouched: still #Fatura00057.
        $this->assertSame('#Fatura00057', $legacy->fresh()->displayNumber());
        $this->assertSame(57, (int) $legacy->fresh()->invoice_id);

        // And sales numbering still counts the legacy row: next
        // sales invoice is 58, not 1 and not 59.
        $sale = app(PosToInvoiceService::class)->createInvoiceFromPos(
            $this->makePos(Pos::DOCUMENT_TYPE_CASH_SALE, 9, [[$this->meal, 1, 500]])
        );
        $this->assertSame(58, (int) $sale->invoice_id);
    }

    // ------------------------------------------------------------------
    // Ledger
    // ------------------------------------------------------------------

    public function test_internal_invoice_posts_dr_611_and_no_sales_ar_or_bank(): void
    {
        $pos = $this->makePos(Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION, 1, [[$this->meal, 2, 500], [$this->meal, 1, 200]]);
        $invoice = app(PosToInvoiceService::class)->createInvoiceFromPos($pos);
        $this->assertNotNull($invoice);

        // Invoice total: 2 x 500 + 1 x 200 = 1200.
        $lines = TransactionLines::where('reference_id', $invoice->id)->get();

        // Dr 611 (Cost of Inventories Sold or Consumed) = 1200.
        $this->assertSame(1200.0, (float) $lines->where('account_id', $this->account611->id)->sum('debit'));
        $this->assertSame(0.0, (float) $lines->where('account_id', $this->account611->id)->sum('credit'));
        // Cr the product's purchase expense account (612) = 1200.
        $this->assertSame(1200.0, (float) $lines->where('account_id', $this->accountExpense->id)->sum('credit'));
        $this->assertSame(0.0, (float) $lines->where('account_id', $this->accountExpense->id)->sum('debit'));

        // No sales income, no AR balancing line, no bank payment.
        $this->assertSame(0.0, (float) $lines->where('account_id', $this->accountIncome->id)->sum('credit'));
        $this->assertSame(0, $lines->where('reference', 'Invoice Receivable')->count());
        $this->assertSame(0, $lines->where('reference', 'Invoice Payment')->count());
        $this->assertSame(0, $lines->where('reference', 'Invoice Payment AR')->count());
        $this->assertSame(0, $lines->where('account_id', $this->accountReceivable->id)->count());
        $this->assertSame(0, $lines->where('account_id', $this->accountBank->id)->count());

        // No InvoicePayment, no Transaction row, bank untouched.
        $this->assertSame(0, InvoicePayment::where('invoice_id', $invoice->id)->count());
        $this->assertSame(0, Transaction::where('payment_id', $invoice->id)->count());
        $this->assertSame(100000.0, (float) $this->bank->fresh()->opening_balance);

        // Balanced: total debits == total credits.
        $this->assertSame((float) $lines->sum('debit'), (float) $lines->sum('credit'));
    }

    public function test_internal_invoice_without_611_fails_loudly(): void
    {
        $this->account611->delete();

        $pos = $this->makePos(Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION, 1, [[$this->meal, 1, 500]]);
        $invoice = app(PosToInvoiceService::class)->createInvoiceFromPos($pos);

        // No partial invoice, no ledger lines.
        $this->assertNull($invoice);
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, TransactionLines::count());
    }

    public function test_internal_line_without_expense_account_credits_611_itself(): void
    {
        $noExpenseProduct = ProductService::create([
            'name' => 'No Expense Account Meal', 'sku' => 'MEAL-2', 'sale_price' => 400, 'purchase_price' => 250,
            'tax_id' => 0, 'category_id' => 1, 'unit_id' => 0, 'type' => 'Product',
            'sale_chartaccount_id' => $this->accountIncome->id, 'expense_chartaccount_id' => 0,
            'affect_stock' => 0, 'created_by' => 1,
        ]);

        $pos = $this->makePos(Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION, 1, [[$noExpenseProduct, 1, 400]]);
        $invoice = app(PosToInvoiceService::class)->createInvoiceFromPos($pos);
        $this->assertNotNull($invoice);

        $lines = TransactionLines::where('reference_id', $invoice->id)->get();
        $this->assertSame(400.0, (float) $lines->where('account_id', $this->account611->id)->sum('debit'));
        $this->assertSame(400.0, (float) $lines->where('account_id', $this->account611->id)->sum('credit'));
        $this->assertSame((float) $lines->sum('debit'), (float) $lines->sum('credit'));
    }

    public function test_sales_invoice_still_posts_income_ar_and_bank_payment(): void
    {
        $pos = $this->makePos(Pos::DOCUMENT_TYPE_CASH_SALE, 1, [[$this->meal, 2, 500]]);
        $invoice = app(PosToInvoiceService::class)->createInvoiceFromPos($pos);
        $this->assertNotNull($invoice);
        $this->assertSame(1, (int) $invoice->invoice_id);
        $this->assertSame('POS-1', $invoice->ref_number);
        $this->assertFalse($invoice->isInternal());

        $lines = TransactionLines::where('reference_id', $invoice->id)->get();

        // Cr sales income 711 = 1000; Dr AR (Invoice Receivable) = 1000.
        $this->assertSame(1000.0, (float) $lines->where('account_id', $this->accountIncome->id)->sum('credit'));
        $this->assertSame(1000.0, (float) $lines->where('reference', 'Invoice Receivable')->sum('debit'));
        // Payment: Dr bank chart 111 = 1000, Cr AR = 1000.
        $this->assertSame(1000.0, (float) $lines->where('reference', 'Invoice Payment')->sum('debit'));
        $this->assertSame(1000.0, (float) $lines->where('reference', 'Invoice Payment AR')->sum('credit'));

        $payment = InvoicePayment::where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(1000.0, (float) $payment->amount);
        $this->assertSame(101000.0, (float) $this->bank->fresh()->opening_balance);
        $this->assertSame(4, (int) $invoice->status);
    }

    // ------------------------------------------------------------------
    // Display / duplicate / show page
    // ------------------------------------------------------------------

    public function test_show_page_renders_int_number(): void
    {
        $invoice = app(PosToInvoiceService::class)->createInvoiceFromPos(
            $this->makePos(Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION, 1, [[$this->meal, 1, 500]])
        );
        $this->assertNotNull($invoice);

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('invoice.show', Crypt::encrypt($invoice->id)));

        $response->assertOk();
        $response->assertSee('#INT00001');
    }

    public function test_duplicate_of_internal_invoice_stays_in_int_series(): void
    {
        $invoice = app(PosToInvoiceService::class)->createInvoiceFromPos(
            $this->makePos(Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION, 1, [[$this->meal, 1, 500]])
        );
        $this->assertNotNull($invoice);

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('invoice.duplicate', $invoice->id));
        $response->assertRedirect();

        $duplicate = Invoice::where('document_type', Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION)
            ->orderByDesc('id')->firstOrFail();
        $this->assertSame(2, (int) $duplicate->invoice_id);
        $this->assertSame('#INT00002', $duplicate->ref_number);
        $this->assertSame('#INT00002', $duplicate->displayNumber());
        // Sales numbering is untouched by the duplicate.
        $this->assertSame(1, app(InvoiceCreationService::class)->nextInvoiceNumber(1));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function makePos(string $documentType, int $posNumber, array $lines): Pos
    {
        $pos = Pos::create([
            'pos_id' => $posNumber,
            'customer_id' => 0,
            'pos_date' => date('Y-m-d'),
            'payment_method' => $documentType === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION ? PosPayment::METHOD_INTERNAL : 'cash',
            'document_type' => $documentType,
            'category_id' => 0,
            'status' => 0,
            'shipping_display' => 0,
            'created_by' => 1,
        ]);

        $total = 0;
        foreach ($lines as [$product, $qty, $price]) {
            PosProduct::create([
                'pos_id' => $pos->id,
                'product_id' => $product->id,
                'quantity' => $qty,
                'price' => $price,
                'tax' => '',
                'discount' => 0,
            ]);
            $total += $qty * $price;
        }

        PosPayment::create([
            'pos_id' => $pos->id,
            'payment_method' => $documentType === Pos::DOCUMENT_TYPE_INTERNAL_CONSUMPTION ? PosPayment::METHOD_INTERNAL : 'cash',
            'amount' => $total,
            'discount' => 0,
            'date' => date('Y-m-d'),
        ]);

        return $pos->fresh();
    }

    protected function seedBase(): void
    {
        $user = User::create([
            'name' => 'Company Owner',
            'email' => 'owner@test.local',
            'password' => bcrypt('password'),
            'type' => 'company',
            'created_by' => 1,
            'default_company_id' => 1,
            'is_enable_login' => 1,
            'lang' => 'en',
            'email_verified_at' => now(),
        ]);
        $user->plan = 1;
        $user->storage_limit = 100;
        $user->save();
        $this->actingAs($user);

        DB::table('settings')->insert([
            ['name' => 'invoice_prefix', 'value' => '#Fatura', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'pos_internal_prefix', 'value' => '#INT', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'currency_symbol', 'value' => 'MZN', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'site_currency', 'value' => 'MZN', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('plans')->insert([
            ['id' => 1, 'name' => 'Test Plan', 'storage_limit' => 100, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('languages')->insert([
            ['id' => 1, 'code' => 'en', 'full_name' => 'English', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->account611 = ChartOfAccount::create(['name' => 'Cost of Inventories Sold or Consumed', 'code' => 611, 'type' => 6, 'sub_type' => 0, 'is_enabled' => 1, 'created_by' => 1]);
        $this->accountExpense = ChartOfAccount::create(['name' => 'Purchases', 'code' => 612, 'type' => 6, 'sub_type' => 0, 'is_enabled' => 1, 'created_by' => 1]);
        $this->accountIncome = ChartOfAccount::create(['name' => 'Sales Income', 'code' => 711, 'type' => 7, 'sub_type' => 0, 'is_enabled' => 1, 'created_by' => 1]);
        $this->accountReceivable = ChartOfAccount::create(['name' => 'Clients', 'code' => 411, 'type' => 4, 'sub_type' => 0, 'is_enabled' => 1, 'created_by' => 1]);
        $this->accountBank = ChartOfAccount::create(['name' => 'Bank', 'code' => 111, 'type' => 1, 'sub_type' => 0, 'is_enabled' => 1, 'created_by' => 1]);

        ProductServiceCategory::create(['name' => 'Income', 'type' => 1, 'created_by' => 1]);

        $this->meal = ProductService::create([
            'name' => 'Staff Meal', 'sku' => 'MEAL-1', 'sale_price' => 500, 'purchase_price' => 300,
            'tax_id' => 0, 'category_id' => 1, 'unit_id' => 0, 'type' => 'Product',
            'sale_chartaccount_id' => $this->accountIncome->id,
            'expense_chartaccount_id' => $this->accountExpense->id,
            'affect_stock' => 0, 'created_by' => 1,
        ]);
        $this->meal->quantity = 100;
        $this->meal->save();

        $this->bank = BankAccount::create([
            'holder_name' => 'Test Bank', 'bank_name' => 'Test', 'account_number' => '123',
            'chart_account_id' => $this->accountBank->id, 'opening_balance' => 100000, 'created_by' => 1,
        ]);

        foreach (['show invoice', 'duplicate invoice'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }
        $user->givePermissionTo(['show invoice', 'duplicate invoice']);
    }

    protected function createTables(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('branch_user', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('branch_id');
            $table->integer('user_id');
            $table->timestamps();
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
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
            $table->string('plan')->nullable();
            $table->integer('storage_limit')->default(0);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->integer('permission_id');
            $table->string('model_type');
            $table->integer('model_id');
            $table->index(['model_id', 'model_type']);
        });
        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->integer('role_id');
            $table->string('model_type');
            $table->integer('model_id');
            $table->index(['model_id', 'model_type']);
        });
        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->integer('permission_id');
            $table->integer('role_id');
            $table->index(['role_id', 'permission_id']);
        });
        Schema::create('plans', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('storage_limit')->default(0);
            $table->timestamps();
        });
        Schema::create('languages', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code')->nullable();
            $table->string('full_name')->nullable();
            $table->timestamps();
        });
        Schema::create('ch_messages', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('to_id')->nullable();
            $table->integer('seen')->default(0);
            $table->timestamps();
        });
        Schema::create('email_templates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('from')->nullable();
            $table->string('slug')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('customers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('customer_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('taxes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->decimal('rate', 10, 2)->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('product_service_categories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('type')->default(0);
            $table->integer('chart_account_id')->default(0);
            $table->string('color')->default('#fc544b');
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('chart_of_accounts', function (Blueprint $table) {
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
        Schema::create('product_services', function (Blueprint $table) {
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
        Schema::create('product_service_units', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('pos', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('pos_id')->default(0);
            $table->integer('customer_id')->default(0);
            $table->integer('warehouse_id')->nullable();
            $table->integer('branch_id')->nullable();
            $table->integer('table_id')->nullable();
            $table->integer('shift_id')->nullable();
            $table->date('pos_date')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('document_type')->nullable();
            $table->string('order_type')->nullable();
            $table->integer('category_id')->default(0);
            $table->integer('status')->default(0);
            $table->integer('shipping_display')->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('pos_products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('pos_id');
            $table->unsignedBigInteger('product_id');
            $table->integer('quantity')->default(0);
            $table->decimal('price', 16, 2)->default('0.0');
            $table->string('tax')->nullable();
            $table->decimal('discount', 16, 2)->default('0.0');
            $table->timestamps();
        });
        Schema::create('pos_payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('pos_id');
            $table->string('payment_method')->nullable();
            $table->decimal('amount', 16, 2)->default('0.0');
            $table->decimal('discount', 16, 2)->default('0.0');
            $table->date('date')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_tables', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('pos_shifts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('invoices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('pos_id')->nullable();
            $table->string('document_type', 20)->nullable();
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
        Schema::create('invoice_products', function (Blueprint $table) {
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
        Schema::create('invoice_payments', function (Blueprint $table) {
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
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('account')->default(0);
            $table->integer('user_id')->default(0);
            $table->string('user_type')->nullable();
            $table->string('type')->nullable();
            $table->decimal('amount', 16, 2)->default('0.0');
            $table->text('description')->nullable();
            $table->date('date')->nullable();
            $table->integer('created_by')->default(0);
            $table->integer('payment_id')->default(0);
            $table->string('category')->nullable();
            $table->timestamps();
        });
        Schema::create('transaction_lines', function (Blueprint $table) {
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
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('holder_name')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->integer('chart_account_id')->default(0);
            $table->decimal('opening_balance', 16, 2)->default('0.0');
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('invoice')->default('0');
            $table->integer('customer')->default('0');
            $table->decimal('amount', 15, 2)->default('0.00');
            $table->date('date');
            $table->text('description')->nullable();
            $table->integer('is_debit')->default(0);
            $table->timestamps();
        });
        Schema::create('customer_billing_addresses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('customer_id')->default(0);
            $table->timestamps();
        });
        Schema::create('customer_shipping_addresses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('customer_id')->default(0);
            $table->timestamps();
        });
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('invoice_id')->default(0);
            $table->timestamps();
        });
        Schema::create('road_manifests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
        });
        Schema::create('quotations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
        });
        Schema::create('proformas', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
        });
        Schema::create('invoice_bank_transfers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('invoice_id')->default(0);
            $table->string('order_id')->nullable();
            $table->decimal('amount', 16, 2)->default('0.0');
            $table->string('status')->nullable();
            $table->date('date')->nullable();
            $table->string('receipt')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('module')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('field_id')->default(0);
            $table->integer('record_id')->default(0);
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }
}
