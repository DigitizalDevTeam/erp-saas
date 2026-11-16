<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\User;
use App\Models\Utility;
use App\Models\WarehouseProduct;
use App\Models\warehouse;
use App\Services\DocumentVoidService;
use App\Services\InvoiceCreationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Client issues — Issue 8: inventory & stock.
 *
 * A draft invoice deducts NOTHING. Stock moves when the invoice
 * is issued (Draft -> Sent), once, to the global quantity AND the
 * resolved warehouse; a void restores both to baseline. With
 * timing 'delivery_note' the deduction stays on Delivery Note
 * creation. The warehouse is recorded on the invoice so a void
 * restores to the SAME warehouse.
 *
 * Fixture: company user 1, branch "Main Branch" (default warehouse
 * North Warehouse id 1), Burger global qty 100, warehouse qty 50.
 */
class ClientIssuesStockTest extends TestCase
{
    protected User $user;
    protected Customer $customer;
    protected ProductService $burger;
    protected BankAccount $bank;
    protected warehouse $warehouse;

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

        foreach (['stock_reports', 'document_void_logs', 'credit_notes', 'transaction_lines', 'transactions', 'invoice_payments', 'invoice_products', 'delivery_notes', 'customer_billing_addresses', 'customer_shipping_addresses', 'invoices', 'warehouse_products', 'warehouses', 'branches', 'branch_user', 'product_expiry_lots', 'bank_accounts', 'product_services', 'product_service_categories', 'chart_of_accounts', 'customers', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'settings', 'ch_messages', 'email_templates', 'taxes'] as $table) {
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
    // Draft deducts nothing; send deducts once
    // ------------------------------------------------------------------

    public function test_draft_invoice_creates_no_stock_movement(): void
    {
        $invoice = $this->createDraft(2);

        $this->assertSame(100.0, $this->globalQty());
        $this->assertSame(50.0, $this->warehouseQty(1));
        $this->assertNull($invoice->fresh()->stock_deducted_at);
        $this->assertNull($invoice->fresh()->stock_deducted_warehouse_id);
    }

    public function test_send_deducts_global_and_warehouse_exactly_once(): void
    {
        $invoice = $this->createDraft(2);
        $service = app(InvoiceCreationService::class);

        $this->assertTrue($service->deductStockOnSend($invoice->fresh(), 1));
        $this->assertSame(98.0, $this->globalQty());
        $this->assertSame(48.0, $this->warehouseQty(1));
        $fresh = $invoice->fresh();
        $this->assertNotNull($fresh->stock_deducted_at);
        $this->assertSame('invoice', $fresh->stock_deducted_via);
        $this->assertSame(1, (int) $fresh->stock_deducted_warehouse_id);

        // A second send attempt must not deduct again.
        $this->assertFalse($service->deductStockOnSend($invoice->fresh(), 1));
        $this->assertSame(98.0, $this->globalQty());
        $this->assertSame(48.0, $this->warehouseQty(1));
    }

    public function test_send_via_http_route_deducts_stock(): void
    {
        $invoice = $this->createDraft(2);

        $response = $this->get(route('invoice.sent', $invoice->id));

        $response->assertSessionHas('success');
        $this->assertSame(Invoice::STATUS_SENT, (int) $invoice->fresh()->status);
        $this->assertSame(98.0, $this->globalQty());
        $this->assertSame(48.0, $this->warehouseQty(1));
    }

    // ------------------------------------------------------------------
    // Void restores both
    // ------------------------------------------------------------------

    public function test_void_restores_global_and_warehouse_to_baseline(): void
    {
        $invoice = $this->createDraft(2);
        app(InvoiceCreationService::class)->deductStockOnSend($invoice->fresh(), 1);
        $this->assertSame(98.0, $this->globalQty());
        $this->assertSame(48.0, $this->warehouseQty(1));

        DocumentVoidService::voidInvoice($invoice->fresh(), ['reason' => 'void after send']);

        $this->assertSame(100.0, $this->globalQty());
        $this->assertSame(50.0, $this->warehouseQty(1));
    }

    public function test_void_restores_to_recorded_warehouse_not_the_default(): void
    {
        // A second warehouse; the branch default stays warehouse 1.
        $second = warehouse::create([
            'name' => 'South Warehouse', 'branch_id' => 1,
            'address' => '', 'city' => '', 'city_zip' => '', 'created_by' => 1,
        ]);
        WarehouseProduct::create([
            'warehouse_id' => $second->id, 'product_id' => $this->burger->id,
            'product_variant_id' => null, 'quantity' => 30, 'created_by' => 1,
        ]);

        // Simulate a deduction taken from warehouse 2.
        $invoice = $this->createDraft(2);
        \App\Services\InvoiceSaleDeductionService::deductLine(
            $this->burger->id, 2, 1, $invoice->id,
            (int) DB::table('invoice_products')->where('invoice_id', $invoice->id)->value('id'),
            $this->customer->id, '#Fatura00001', null, null, $second->id
        );
        $invoice->stock_deducted_at = now();
        $invoice->stock_deducted_via = 'invoice';
        $invoice->stock_deducted_warehouse_id = $second->id;
        $invoice->save();
        $this->assertSame(98.0, $this->globalQty());
        $this->assertSame(28.0, $this->warehouseQty($second->id));
        $this->assertSame(50.0, $this->warehouseQty(1));

        DocumentVoidService::voidInvoice($invoice->fresh(), ['reason' => 'void from warehouse 2']);

        $this->assertSame(100.0, $this->globalQty());
        $this->assertSame(30.0, $this->warehouseQty($second->id));
        $this->assertSame(50.0, $this->warehouseQty(1));
    }

    // ------------------------------------------------------------------
    // No warehouse: global-only
    // ------------------------------------------------------------------

    public function test_no_warehouse_means_global_only_deduction(): void
    {
        $other = User::create([
            'name' => 'Other Company', 'email' => 'other@test.local',
            'password' => bcrypt('password'), 'type' => 'company',
            'created_by' => 2, 'is_enable_login' => 1, 'lang' => 'en',
            'email_verified_at' => now(),
        ]);
        // A product owned by creator 2 (deduction is company-scoped:
        // it only touches products created by the invoice's company).
        $otherBurger = ProductService::create([
            'name' => 'Burger 2', 'sku' => '000002', 'sale_price' => 500, 'purchase_price' => 300,
            'tax_id' => 0, 'category_id' => 1, 'unit_id' => 0, 'type' => 'Product',
            'sale_chartaccount_id' => 2, 'expense_chartaccount_id' => 1,
            'affect_stock' => 1, 'created_by' => $other->id,
        ]);
        $otherBurger->quantity = 100;
        $otherBurger->save();

        // No branches / warehouses for creator 2.
        $invoice = $this->createDraft(2, $other->id, $otherBurger->id);

        $this->assertTrue(app(InvoiceCreationService::class)->deductStockOnSend($invoice->fresh(), $other->id));
        $this->assertSame(98.0, (float) $otherBurger->fresh()->quantity);
        $this->assertNull($invoice->fresh()->stock_deducted_warehouse_id);
    }

    // ------------------------------------------------------------------
    // Delivery-note timing
    // ------------------------------------------------------------------

    public function test_delivery_note_timing_deducts_only_on_dn_creation(): void
    {
        $this->setTiming('delivery_note');

        $invoice = $this->createDraft(2);

        // Sending does NOT deduct under delivery-note timing.
        $this->assertFalse(app(InvoiceCreationService::class)->deductStockOnSend($invoice->fresh(), 1));
        $this->assertSame(100.0, $this->globalQty());
        $this->assertSame(50.0, $this->warehouseQty(1));

        // Creating the delivery note deducts (global + warehouse).
        $response = $this->post(route('invoice.delivery-note.create', Crypt::encrypt($invoice->id)));

        // (Exact URL cannot be asserted: Crypt::encrypt is
        // non-deterministic, so the ciphertext differs each call.)
        $response->assertRedirectContains('/delivery-note/');
        $this->assertSame(98.0, $this->globalQty());
        $this->assertSame(48.0, $this->warehouseQty(1));
        $fresh = $invoice->fresh();
        $this->assertSame('delivery_note', $fresh->stock_deducted_via);
        $this->assertSame(1, (int) $fresh->stock_deducted_warehouse_id);

        // And a void still restores both.
        DocumentVoidService::voidInvoice($fresh, ['reason' => 'void after dn']);
        $this->assertSame(100.0, $this->globalQty());
        $this->assertSame(50.0, $this->warehouseQty(1));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function setTiming(string $timing): void
    {
        DB::table('settings')->where('name', 'stock_deduction_timing')->update(['value' => $timing]);
        self::resetSettingsCache();
    }

    /** A draft invoice for $qty Burgers; deducts nothing. */
    protected function createDraft(int $qty, int $creatorId = 1, ?int $productId = null): Invoice
    {
        return app(InvoiceCreationService::class)->createInvoice($creatorId, [
            'customer_id' => $this->customer->id,
            'issue_date' => '2026-11-10',
            'due_date' => '2026-12-31',
            'category_id' => 1,
            'skip_date_validation' => true,
            'items' => [[
                'item' => $productId ?? $this->burger->id, 'quantity' => $qty,
                'tax' => null, 'discount' => 0, 'price' => 500,
                'description' => null,
            ]],
        ]);
    }

    protected function globalQty(): float
    {
        return (float) $this->burger->fresh()->quantity;
    }

    protected function warehouseQty(int $warehouseId): float
    {
        return (float) WarehouseProduct::where('warehouse_id', $warehouseId)
            ->where('product_id', $this->burger->id)
            ->value('quantity');
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
            ['name' => 'stock_deduction_timing', 'value' => 'invoice', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'customer_invoice_sent', 'value' => '0', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
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

        $branch = Branch::create([
            'name' => 'Main Branch', 'created_by' => 1,
        ]);
        $this->warehouse = warehouse::create([
            'name' => 'North Warehouse', 'branch_id' => $branch->id,
            'address' => '', 'city' => '', 'city_zip' => '', 'created_by' => 1,
        ]);
        $branch->default_warehouse_id = $this->warehouse->id;
        $branch->save();

        WarehouseProduct::create([
            'warehouse_id' => $this->warehouse->id, 'product_id' => $this->burger->id,
            'product_variant_id' => null, 'quantity' => 50, 'created_by' => 1,
        ]);

        $this->bank = BankAccount::create([
            'holder_name' => 'Test Bank', 'bank_name' => 'Test', 'account_number' => '123',
            'chart_account_id' => 1, 'opening_balance' => 10000, 'created_by' => 1,
        ]);

        foreach (['create invoice', 'edit invoice', 'show invoice', 'send invoice', 'delete invoice'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }
        $this->user->givePermissionTo(['create invoice', 'edit invoice', 'show invoice', 'send invoice', 'delete invoice']);
    }

    protected function createTables(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('default_warehouse_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('branch_user', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('branch_id');
            $table->integer('user_id');
            $table->timestamps();
        });
        Schema::create('warehouses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('city_zip')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('warehouse_products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('warehouse_id')->default(0);
            $table->integer('product_id')->default(0);
            $table->integer('product_variant_id')->nullable();
            $table->decimal('quantity', 16, 4)->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('product_expiry_lots', function (Blueprint $table) {
            $table->bigIncrements('id');
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
            $table->decimal('credit_limit', 15, 2)->default(0);
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
            $table->unsignedBigInteger('stock_deducted_warehouse_id')->nullable();
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
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice_id')->unique();
            $table->string('delivery_note_no')->unique();
            $table->date('issue_date');
            $table->unsignedTinyInteger('status')->default(0);
            $table->timestamp('stock_deducted_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
        // The DN controller eager-loads the invoice's billing +
        // shipping addresses.
        Schema::create('customer_billing_addresses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('customer_id')->default(0);
            $table->string('name')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });
        Schema::create('customer_shipping_addresses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('customer_id')->default(0);
            $table->string('name')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });
        Schema::create('stock_reports', function (Blueprint $table) {
            $table->id();
            $table->integer('product_id')->default('0');
            $table->integer('quantity')->default('0');
            $table->string('type');
            $table->integer('type_id')->default('0');
            $table->integer('warehouse_loss_id')->nullable();
            $table->text('description')->nullable();
            $table->integer('created_by')->default('0');
            $table->timestamps();
        });
    }
}
