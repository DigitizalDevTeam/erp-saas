<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\ChartOfAccount;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\DocumentVoidLog;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\Transaction;
use App\Models\TransactionLines;
use App\Models\User;
use App\Models\Utility;
use App\Services\DocumentVoidService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Client issues — Issue 7: invoice void / cancel & credit note.
 *
 * Covers: the void keeps payment rows (marked voided) instead of
 * deleting them, the payment's Transaction row is kept, the bank
 * balance returns to its pre-payment value, the customer balance
 * ends at the credit-note amount (refund owed), due is 0 on a
 * voided invoice, no credit note for an unpaid void, a credit note
 * for exactly the paid amount on a partial payment, sequential
 * unique credit-note numbers across voids, manual-mode checkbox
 * behaviour, and the Deleted status label.
 *
 * Fixture: company user 1, customer with balance, product Burger
 * (qty 100, sale 500), bank account opening 10,000. Invoices are
 * built directly (Sent) with the same balance effects the app
 * applies on creation/payment, so the void is tested in isolation.
 */
class ClientIssuesVoidTest extends TestCase
{
    protected User $user;
    protected Customer $customer;
    protected ProductService $burger;
    protected BankAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Utility::$rates = null;
        Utility::$data = null;
        self::resetSettingsCache();
        $this->createTables();
        $this->seedBase();
    }

    protected function tearDown(): void
    {
        Utility::$rates = null;
        Utility::$data = null;
        self::resetSettingsCache();

        foreach (['stock_reports', 'document_void_logs', 'credit_notes', 'transaction_lines', 'transactions', 'invoice_payments', 'invoice_products', 'invoices', 'bank_accounts', 'product_services', 'product_service_categories', 'chart_of_accounts', 'customers', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'settings', 'branches', 'branch_user', 'ch_messages', 'email_templates', 'taxes'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Unpaid void: no credit note, stock + balances restored
    // ------------------------------------------------------------------

    public function test_void_unpaid_invoice_creates_no_credit_note(): void
    {
        $invoice = $this->makeInvoice(1, 2, 500, 0);

        $log = DocumentVoidService::voidInvoice($invoice, ['reason' => 'test void unpaid']);

        $invoice->refresh();
        $this->assertSame(5, (int) $invoice->status);
        $this->assertSame('Deleted', Invoice::$statues[5]);
        $this->assertSame(0, $invoice->getDue());
        $this->assertSame(0, CreditNote::count());
        $this->assertFalse((bool) $log->accounting_note_created);
        $this->assertNull($log->accounting_note_id);
        $this->assertTrue((bool) $log->stock_restored);
        // Stock: 100 - 2 deducted at "creation", restored by the void.
        $this->assertSame(100.0, (float) $this->burger->fresh()->quantity);
        // Customer balance: +1000 at creation, -1000 (remaining due) at void.
        $this->assertSame(0.0, (float) $this->customer->fresh()->balance);
        // Bank untouched (no payment ever happened).
        $this->assertSame(10000.0, (float) $this->bank->fresh()->opening_balance);
        // Ledger lines for the invoice are removed.
        $this->assertSame(0, TransactionLines::where('reference_id', $invoice->id)->count());
    }

    // ------------------------------------------------------------------
    // Paid void: credit note for the full amount, payment kept
    // ------------------------------------------------------------------

    public function test_void_paid_invoice_creates_credit_note_and_keeps_payment(): void
    {
        $invoice = $this->makeInvoice(1, 2, 500, 1000);

        $log = DocumentVoidService::voidInvoice($invoice, ['reason' => 'test void paid']);

        $invoice->refresh();
        $this->assertSame(5, (int) $invoice->status);
        $this->assertSame(0, $invoice->getDue());

        $note = CreditNote::first();
        $this->assertNotNull($note);
        $this->assertSame(1000.0, (float) $note->amount);
        $this->assertSame($invoice->id, (int) $note->invoice);
        $this->assertSame(1, (int) $note->uid);
        $this->assertTrue((bool) $log->accounting_note_created);
        $this->assertSame((int) $note->id, (int) $log->accounting_note_id);

        // Payment history is kept, marked voided — not deleted.
        $payment = InvoicePayment::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($payment);
        $this->assertNotNull($payment->voided_at);

        // The payment's Transaction row is kept too.
        $this->assertSame(1, Transaction::where('payment_id', $payment->id)->count());

        // Bank: 10000 + 1000 (payment) - 1000 (void reversal) = 10000.
        $this->assertSame(10000.0, (float) $this->bank->fresh()->opening_balance);

        // Customer balance: +1000 (invoice) - 1000 (payment) + 1000
        // (credit note = refund owed) = 1000.
        $this->assertSame(1000.0, (float) $this->customer->fresh()->balance);

        // Stock restored.
        $this->assertSame(100.0, (float) $this->burger->fresh()->quantity);
    }

    // ------------------------------------------------------------------
    // Partial payment: credit note for exactly the paid amount
    // ------------------------------------------------------------------

    public function test_void_partially_paid_invoice_creates_credit_note_for_paid_amount(): void
    {
        $invoice = $this->makeInvoice(1, 2, 500, 400);

        DocumentVoidService::voidInvoice($invoice, ['reason' => 'test void partial']);

        $note = CreditNote::first();
        $this->assertNotNull($note);
        $this->assertSame(400.0, (float) $note->amount);

        // Bank: 10000 + 400 - 400 = 10000.
        $this->assertSame(10000.0, (float) $this->bank->fresh()->opening_balance);

        // Customer: +1000 (invoice) - 400 (payment) - 600 (remaining
        // due credited back) + 400 (credit note) = 400 (refund owed).
        $this->assertSame(400.0, (float) $this->customer->fresh()->balance);

        $invoice->refresh();
        $this->assertSame(0, $invoice->getDue());
        $this->assertNotNull(InvoicePayment::where('invoice_id', $invoice->id)->first()->voided_at);
    }

    // ------------------------------------------------------------------
    // Sequential unique credit-note numbers
    // ------------------------------------------------------------------

    public function test_two_voids_get_different_credit_note_numbers(): void
    {
        $first = $this->makeInvoice(1, 1, 500, 500);
        $second = $this->makeInvoice(2, 1, 500, 500);

        DocumentVoidService::voidInvoice($first, ['reason' => 'void one']);
        DocumentVoidService::voidInvoice($second, ['reason' => 'void two']);

        $uids = CreditNote::orderBy('id')->pluck('uid')->all();
        $this->assertSame([1, 2], array_map('intval', $uids));

        $numbers = CreditNote::orderBy('id')->get()
            ->map(fn ($cn) => $this->user->creditNoteNumberFormat($cn->uid))->all();
        $this->assertSame(['#NC00001', '#NC00002'], $numbers);
    }

    public function test_voiding_an_already_voided_invoice_throws(): void
    {
        $invoice = $this->makeInvoice(1, 1, 500, 0);

        DocumentVoidService::voidInvoice($invoice, ['reason' => 'first void']);

        $this->expectException(\RuntimeException::class);
        DocumentVoidService::voidInvoice($invoice, ['reason' => 'second void']);
    }

    // ------------------------------------------------------------------
    // Manual accounting mode keeps the checkbox behaviour
    // ------------------------------------------------------------------

    public function test_manual_mode_with_checkbox_creates_full_credit_note_for_unpaid(): void
    {
        DB::table('settings')->insert([
            'name' => 'document_void_credit_note', 'value' => 'manual',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        self::resetSettingsCache();

        $invoice = $this->makeInvoice(1, 2, 500, 0);

        DocumentVoidService::voidInvoice($invoice, [
            'reason' => 'manual void',
            'create_credit_note' => 1,
        ]);

        $note = CreditNote::first();
        $this->assertNotNull($note);
        $this->assertSame(1000.0, (float) $note->amount);
    }

    public function test_manual_mode_without_checkbox_creates_no_credit_note(): void
    {
        DB::table('settings')->insert([
            'name' => 'document_void_credit_note', 'value' => 'manual',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        self::resetSettingsCache();

        $invoice = $this->makeInvoice(1, 1, 500, 500);

        DocumentVoidService::voidInvoice($invoice, ['reason' => 'manual void']);

        $this->assertSame(0, CreditNote::count());
    }

    /**
     * Utility::getSettingById() caches settings rows per company id in
     * a private static that survives across tests in one process —
     * without this reset, a settings row inserted by one test is
     * invisible to the next (it keeps reading the previous test's
     * rows from memory, after its tables were dropped).
     */
    protected static function resetSettingsCache(): void
    {
        $prop = new \ReflectionProperty(Utility::class, 'getsettingsid');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    // ------------------------------------------------------------------
    // Fixture
    // ------------------------------------------------------------------

    /**
     * Build a Sent invoice for $qty x $price with $paid paid on it,
     * applying the same balance effects the app applies on creation
     * (customer +total) and payment (bank +paid, customer -paid).
     * Stock is pre-deducted (product qty reduced) with
     * stock_deducted_at set, as the creation path leaves it.
     */
    protected function makeInvoice(int $invoiceNumber, int $qty, float $price, float $paid): Invoice
    {
        $total = $qty * $price;

        $invoice = new Invoice();
        $invoice->invoice_id = $invoiceNumber;
        $invoice->customer_id = $this->customer->id;
        $invoice->status = Invoice::STATUS_SENT;
        $invoice->issue_date = '2026-11-10';
        $invoice->due_date = '2026-11-17';
        $invoice->send_date = '2026-11-10';
        $invoice->category_id = 1;
        $invoice->created_by = 1;
        $invoice->save();

        DB::table('invoice_products')->insert([
            'invoice_id' => $invoice->id,
            'product_id' => $this->burger->id,
            'quantity' => $qty,
            'tax' => null,
            'discount' => 0,
            'price' => $price,
            'description' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Simulate the creation-time deduction.
        $this->burger->quantity = (float) $this->burger->quantity - $qty;
        $this->burger->save();
        $invoice->stock_deducted_at = now();
        $invoice->stock_deducted_via = 'invoice';
        $invoice->save();

        // Customer balance +total at creation.
        Utility::updateUserBalance('customer', $this->customer->id, $total, 'debit');

        // Ledger lines as the release posts them.
        DB::table('transaction_lines')->insert([
            ['account_id' => 711, 'reference' => 'Invoice', 'reference_id' => $invoice->id,
                'reference_sub_id' => $this->burger->id, 'date' => '2026-11-10',
                'credit' => $total, 'debit' => 0, 'created_by' => 1,
                'created_at' => now(), 'updated_at' => now()],
        ]);

        if ($paid > 0) {
            $payment = new InvoicePayment();
            $payment->invoice_id = $invoice->id;
            $payment->date = '2026-11-16';
            $payment->amount = $paid;
            $payment->account_id = $this->bank->id;
            $payment->payment_method = 0;
            $payment->save();

            DB::table('transaction_lines')->insert([
                ['account_id' => 111, 'reference' => 'Invoice Payment', 'reference_id' => $invoice->id,
                    'reference_sub_id' => $payment->id, 'date' => '2026-11-16',
                    'credit' => 0, 'debit' => $paid, 'created_by' => 1,
                    'created_at' => now(), 'updated_at' => now()],
            ]);

            DB::table('transactions')->insert([
                'user_id' => 1, 'user_type' => 'customer', 'account' => $this->bank->id,
                'type' => 'Payment', 'amount' => $paid, 'description' => 'test payment',
                'date' => '2026-11-16', 'created_by' => 1, 'payment_id' => $payment->id,
                'category' => 'Invoice Payment', 'created_at' => now(), 'updated_at' => now(),
            ]);

            // Bank +paid, customer -paid (as payment creation does).
            Utility::bankAccountBalance($this->bank->id, $paid, 'credit');
            Utility::updateUserBalance('customer', $this->customer->id, $paid, 'credit');
        }

        return $invoice->fresh();
    }

    protected function seedBase(): void
    {
        $this->user = User::create([
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
        $this->user->plan = 1;
        $this->user->storage_limit = 100;
        $this->user->save();
        $this->actingAs($this->user);

        DB::table('settings')->insert([
            ['name' => 'invoice_prefix', 'value' => '#Fatura', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'credit_note_prefix', 'value' => '#NC', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'currency_symbol', 'value' => 'MZN', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'site_currency', 'value' => 'MZN', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('plans')->insert([
            ['id' => 1, 'name' => 'Test Plan', 'storage_limit' => 100, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('languages')->insert([
            ['id' => 1, 'code' => 'en', 'full_name' => 'English', 'created_at' => now(), 'updated_at' => now()],
        ]);

        ChartOfAccount::create(['name' => 'Bank', 'code' => 111, 'type' => 1, 'sub_type' => 0, 'is_enabled' => 1, 'created_by' => 1]);
        ChartOfAccount::create(['name' => 'Sales Income', 'code' => 711, 'type' => 7, 'sub_type' => 0, 'is_enabled' => 1, 'created_by' => 1]);
        ChartOfAccount::create(['name' => 'Clients', 'code' => 411, 'type' => 4, 'sub_type' => 0, 'is_enabled' => 1, 'created_by' => 1]);

        ProductServiceCategory::create(['name' => 'Income', 'type' => 1, 'created_by' => 1]);

        $this->customer = Customer::create([
            'name' => 'Test Client Co', 'email' => 'testclient@example.com',
            'customer_id' => 1, 'created_by' => 1, 'balance' => 0,
        ]);

        $this->burger = ProductService::create([
            'name' => 'Burger', 'sku' => '000001', 'sale_price' => 500, 'purchase_price' => 300,
            'tax_id' => 0, 'category_id' => 1, 'unit_id' => 0, 'type' => 'Product',
            'sale_chartaccount_id' => 2, 'expense_chartaccount_id' => 1,
            'affect_stock' => 1, 'created_by' => 1,
        ]);
        $this->burger->quantity = 100;
        $this->burger->save();

        $this->bank = BankAccount::create([
            'holder_name' => 'Test Bank', 'bank_name' => 'Test', 'account_number' => '123',
            'chart_account_id' => 1, 'opening_balance' => 10000, 'created_by' => 1,
        ]);

        foreach (['show invoice', 'delete invoice'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }
        $this->user->givePermissionTo(['show invoice', 'delete invoice']);
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
            $table->decimal('balance', 15, 2)->default(0);
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
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('holder_name')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->integer('chart_account_id')->default(0);
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('invoices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('customer_id');
            $table->date('issue_date');
            $table->date('due_date');
            $table->date('send_date')->nullable();
            $table->integer('category_id');
            $table->text('ref_number')->nullable();
            $table->integer('status')->default('0');
            $table->integer('shipping_display')->default('1');
            $table->integer('discount_apply')->default('0');
            $table->string('document_type')->nullable();
            $table->unsignedBigInteger('pos_id')->nullable();
            $table->timestamp('stock_deducted_at')->nullable();
            $table->string('stock_deducted_via')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 500)->nullable();
            $table->integer('created_by')->default('0');
            $table->timestamps();
        });
        Schema::create('invoice_products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('invoice_id');
            $table->integer('product_id');
            $table->integer('quantity');
            $table->string('tax', '50')->nullable();
            $table->float('discount')->default('0.00');
            $table->decimal('price', 16, 2)->default('0.0');
            $table->text('description')->nullable();
            $table->text('identifiers')->nullable();
            $table->text('measurement_json')->nullable();
            $table->text('selected_options_json')->nullable();
            $table->integer('product_variant_id')->nullable();
            $table->timestamps();
        });
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('invoice_id');
            $table->date('date')->nullable();
            $table->decimal('amount', 15, 2)->default('0.0');
            $table->integer('account_id')->default(0);
            $table->integer('payment_method')->default(0);
            $table->string('order_id')->nullable();
            $table->string('currency')->nullable();
            $table->string('txn_id')->nullable();
            $table->string('payment_type')->nullable();
            $table->string('receipt')->nullable();
            $table->string('add_receipt')->nullable();
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->integer('pos_shift_id')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
        });
        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id');
            $table->string('user_type');
            $table->integer('account');
            $table->string('type')->nullable();
            $table->decimal('amount', 16, 2)->default('0.0');
            $table->text('description')->nullable();
            $table->date('date');
            $table->integer('created_by')->default('0');
            $table->integer('payment_id')->default('0');
            $table->string('category');
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
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('uid')->default(1);
            $table->integer('invoice')->default('0');
            $table->integer('customer')->default('0');
            $table->decimal('amount', 15, 2)->default('0.00');
            $table->date('date');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->boolean('is_debit')->default(0);
            $table->timestamps();
        });
        Schema::create('document_void_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by')->default(0);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('document_type', 40);
            $table->unsignedBigInteger('document_id');
            $table->string('document_number')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->boolean('stock_restored')->default(false);
            $table->boolean('accounting_note_created')->default(false);
            $table->unsignedBigInteger('accounting_note_id')->nullable();
            $table->string('accounting_note_type', 40)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
        Schema::create('stock_reports', function (Blueprint $table) {
            $table->id();
            $table->integer('product_id')->default('0');
            $table->integer('quantity')->default('0');
            $table->string('type');
            $table->integer('type_id')->default('0');
            $table->text('description')->nullable();
            $table->integer('created_by')->default('0');
            $table->timestamps();
        });
    }
}
