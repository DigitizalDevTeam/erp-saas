<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\User;
use App\Models\Utility;
use App\Services\InvoiceCreationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Client issues — Issue 6: invoice date validation.
 *
 * A new invoice's issue date must be on or after the latest
 * issue date among the company's non-voided sales invoices
 * (internal-consumption invoices do not count), and on or after
 * the company's invoice_start_date setting when set. Drafts count
 * as the reference — the audit's proof was two drafts (Nov 10,
 * then Nov 1) — only voided invoices are ignored.
 * Super admins bypass; the recurring engine bypasses via
 * skip_date_validation (system-generated, current period).
 */
class ClientIssuesDateValidationTest extends TestCase
{
    protected User $user;
    protected Customer $customer;
    protected ProductService $burger;

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

        foreach (['stock_reports', 'transaction_lines', 'invoice_products', 'invoices', 'bank_accounts', 'product_services', 'product_service_categories', 'chart_of_accounts', 'customers', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'settings', 'branches', 'branch_user', 'ch_messages', 'email_templates', 'taxes', 'migrations'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    protected static function resetSettingsCache(): void
    {
        $prop = new \ReflectionProperty(Utility::class, 'getsettingsid');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    // ------------------------------------------------------------------
    // Chronological rule
    // ------------------------------------------------------------------

    public function test_backdated_invoice_rejected_for_company_user(): void
    {
        $this->makeSentInvoice(1, 1, '2026-11-10');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('2026-11-10');

        $this->createViaService(1, '2026-11-01');
    }

    public function test_invoice_on_latest_date_is_accepted(): void
    {
        $this->makeSentInvoice(1, 1, '2026-11-10');

        $invoice = $this->createViaService(1, '2026-11-10');

        $this->assertSame('2026-11-10', (string) $invoice->issue_date);
        $this->assertSame(2, (int) $invoice->invoice_id);
    }

    public function test_invoice_after_latest_date_is_accepted(): void
    {
        $this->makeSentInvoice(1, 1, '2026-11-10');

        $invoice = $this->createViaService(1, '2026-11-15');

        $this->assertSame('2026-11-15', (string) $invoice->issue_date);
    }

    public function test_draft_invoice_blocks_a_new_earlier_date(): void
    {
        $this->makeSentInvoice(1, 1, '2026-11-10');
        // A draft dated later IS the reference date now: invoice
        // numbers are assigned at creation, so a later draft still
        // breaks the sequence if a new invoice is dated before it.
        $this->makeSentInvoice(1, 2, '2026-12-01', Invoice::STATUS_DRAFT);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('2026-12-01');

        $this->createViaService(1, '2026-11-15');
    }

    public function test_backdated_draft_after_a_draft_is_rejected(): void
    {
        // The audit's proof, verbatim: a draft dated Nov 10, then a
        // new draft dated Nov 1 — the second save must be blocked.
        $this->makeSentInvoice(1, 1, '2026-11-10', Invoice::STATUS_DRAFT);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('2026-11-10');

        $this->createViaService(1, '2026-11-01');
    }

    public function test_voided_invoice_does_not_block_a_new_date(): void
    {
        $this->makeSentInvoice(1, 1, '2026-11-10');
        $this->makeSentInvoice(1, 2, '2026-12-01', Invoice::STATUS_CANCELLED);

        $invoice = $this->createViaService(1, '2026-11-15');

        $this->assertSame('2026-11-15', (string) $invoice->issue_date);
    }

    public function test_internal_consumption_invoice_does_not_block_a_new_date(): void
    {
        $this->makeSentInvoice(1, 1, '2026-11-10');
        $this->makeSentInvoice(1, 2, '2026-12-01', Invoice::STATUS_PAID, 'internal_consumption');

        $invoice = $this->createViaService(1, '2026-11-15');

        $this->assertSame('2026-11-15', (string) $invoice->issue_date);
    }

    // ------------------------------------------------------------------
    // invoice_start_date setting
    // ------------------------------------------------------------------

    public function test_start_date_setting_blocks_earlier_invoices(): void
    {
        $this->setStartDate('2026-11-01');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('2026-11-01');

        $this->createViaService(1, '2026-10-15');
    }

    public function test_start_date_setting_allows_invoices_on_the_start_date(): void
    {
        $this->setStartDate('2026-11-01');

        $invoice = $this->createViaService(1, '2026-11-01');

        $this->assertSame('2026-11-01', (string) $invoice->issue_date);
    }

    public function test_no_start_date_means_no_lower_bound(): void
    {
        $invoice = $this->createViaService(1, '2020-01-05');

        $this->assertSame('2020-01-05', (string) $invoice->issue_date);
    }

    // ------------------------------------------------------------------
    // Bypasses
    // ------------------------------------------------------------------

    public function test_skip_flag_accepts_a_backdated_invoice(): void
    {
        $this->makeSentInvoice(1, 1, '2026-11-10');

        // What the controller passes for a super admin, and what the
        // recurring engine passes for system-generated invoices.
        $invoice = $this->createViaService(1, '2026-11-01', ['skip_date_validation' => true]);

        $this->assertSame('2026-11-01', (string) $invoice->issue_date);
    }

    // ------------------------------------------------------------------
    // Update path (HTTP)
    // ------------------------------------------------------------------

    public function test_update_to_an_earlier_date_is_rejected(): void
    {
        $this->makeSentInvoice(1, 1, '2026-11-10');
        $draft = $this->makeSentInvoice(1, 2, '2026-11-12', Invoice::STATUS_DRAFT);
        $line = DB::table('invoice_products')->where('invoice_id', $draft->id)->first();

        $response = $this->put(route('invoice.update', $draft->id), [
            'customer_id' => $this->customer->id,
            'issue_date' => '2026-11-01',
            'due_date' => '2026-11-19',
            'category_id' => 1,
            'items' => [[
                'id' => $line->id, 'item' => $this->burger->id, 'quantity' => 1,
                'tax' => null, 'discount' => 0, 'price' => 500,
                'description' => null, 'itemTaxPrice' => 0,
            ]],
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('2026-11-10', (string) session('error'));
        $this->assertSame('2026-11-12', (string) $draft->fresh()->issue_date);
    }

    public function test_update_to_a_later_date_is_accepted(): void
    {
        $this->makeSentInvoice(1, 1, '2026-11-10');
        $draft = $this->makeSentInvoice(1, 2, '2026-11-12', Invoice::STATUS_DRAFT);
        $line = DB::table('invoice_products')->where('invoice_id', $draft->id)->first();

        $response = $this->put(route('invoice.update', $draft->id), [
            'customer_id' => $this->customer->id,
            'issue_date' => '2026-11-15',
            'due_date' => '2026-11-22',
            'category_id' => 1,
            'items' => [[
                'id' => $line->id, 'item' => $this->burger->id, 'quantity' => 1,
                'tax' => null, 'discount' => 0, 'price' => 500,
                'description' => null, 'itemTaxPrice' => 0,
            ]],
        ]);

        $response->assertRedirect(route('invoice.index'));
        $this->assertSame('2026-11-15', (string) $draft->fresh()->issue_date);
    }

    public function test_super_admin_update_to_an_earlier_date_is_accepted(): void
    {
        // The XSS middleware redirects a super admin to the dev tool
        // when it counts pending migrations — mark every migration
        // file as executed so the request reaches the controller.
        $files = array_merge(
            glob(database_path('migrations/*.php')) ?: [],
            glob(base_path('Modules/LandingPage/Database/Migrations/*.php')) ?: []
        );
        foreach ($files as $file) {
            DB::table('migrations')->insert([
                'migration' => basename($file, '.php'), 'batch' => 1,
            ]);
        }

        $admin = User::create([
            'name' => 'Super Admin', 'email' => 'admin@test.local',
            'password' => bcrypt('password'), 'type' => 'super admin',
            'created_by' => 1, 'is_enable_login' => 1, 'lang' => 'en',
            'email_verified_at' => now(),
        ]);
        $admin->givePermissionTo(['edit invoice']);
        $this->actingAs($admin);

        // The super admin's own invoices (created_by = their id).
        $this->makeSentInvoice($admin->id, 1, '2026-11-10');
        $draft = $this->makeSentInvoice($admin->id, 2, '2026-11-12', Invoice::STATUS_DRAFT);
        $line = DB::table('invoice_products')->where('invoice_id', $draft->id)->first();

        $response = $this->put(route('invoice.update', $draft->id), [
            'customer_id' => $this->customer->id,
            'issue_date' => '2026-11-01',
            'due_date' => '2026-11-19',
            'category_id' => 1,
            'items' => [[
                'id' => $line->id, 'item' => $this->burger->id, 'quantity' => 1,
                'tax' => null, 'discount' => 0, 'price' => 500,
                'description' => null, 'itemTaxPrice' => 0,
            ]],
        ]);

        $response->assertRedirect(route('invoice.index'));
        $this->assertSame('2026-11-01', (string) $draft->fresh()->issue_date);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function setStartDate(string $date): void
    {
        DB::table('settings')->insert([
            'name' => 'invoice_start_date', 'value' => $date,
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        self::resetSettingsCache();
    }

    protected function createViaService(int $creatorId, string $issueDate, array $extra = []): Invoice
    {
        return app(InvoiceCreationService::class)->createInvoice($creatorId, array_merge([
            'customer_id' => $this->customer->id,
            'issue_date' => $issueDate,
            'due_date' => '2026-12-31',
            'category_id' => 1,
            'items' => [[
                'item' => $this->burger->id, 'quantity' => 1,
                'tax' => null, 'discount' => 0, 'price' => 500,
                'description' => null,
            ]],
        ], $extra));
    }

    /** Direct insert: an already-issued (or draft/voided) invoice. */
    protected function makeSentInvoice(int $creatorId, int $number, string $issueDate, int $status = Invoice::STATUS_SENT, ?string $documentType = null): Invoice
    {
        $invoice = new Invoice();
        $invoice->invoice_id = $number;
        $invoice->customer_id = $this->customer->id;
        $invoice->status = $status;
        $invoice->issue_date = $issueDate;
        $invoice->due_date = '2026-12-31';
        $invoice->category_id = 1;
        $invoice->document_type = $documentType;
        $invoice->created_by = $creatorId;
        $invoice->save();

        DB::table('invoice_products')->insert([
            'invoice_id' => $invoice->id,
            'product_id' => $this->burger->id,
            'quantity' => 1, 'tax' => null, 'discount' => 0, 'price' => 500,
            'description' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

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
            // Delivery-note timing: createInvoice must not deduct stock
            // here — the date check is what's under test (stock
            // deduction is Issue 8's territory and needs a bigger
            // fixture: product_expiry_lots and friends).
            ['name' => 'stock_deduction_timing', 'value' => 'delivery_note', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
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

        foreach (['create invoice', 'edit invoice', 'show invoice'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }
        $this->user->givePermissionTo(['create invoice', 'edit invoice', 'show invoice']);
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
            $table->string('client_po_number')->nullable();
            $table->date('client_po_date')->nullable();
            $table->text('client_po_details')->nullable();
            $table->string('quotation_reference')->nullable();
            $table->integer('billing_address_id')->nullable();
            $table->integer('shipping_address_id')->nullable();
            $table->integer('quotation_id')->nullable();
            $table->integer('proforma_id')->nullable();
            $table->integer('purchase_id')->nullable();
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
        // The XSS middleware counts pending migrations for super
        // admins; without this table every super-admin request 500s.
        Schema::create('migrations', function (Blueprint $table) {
            $table->string('migration');
            $table->integer('batch');
        });
    }
}
