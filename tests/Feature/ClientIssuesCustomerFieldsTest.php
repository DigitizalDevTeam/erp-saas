<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\CustomerBillingAddress;
use App\Models\CustomerShippingAddress;
use App\Models\Invoice;
use App\Models\Pos;
use App\Models\ProductService;
use App\Models\ProductServiceCategory;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Client issues — Issue 4: customer fields.
 *
 * The customer form is split into Company Information (company name,
 * general email, company phone, billing address, company KYC) and
 * Contact Person Information (contact name, position, personal email,
 * phone, identification). contacts[0] is the contact person; further
 * entries are additional contacts. contacts JSON gains `email` and
 * `identification` per contact (backward compatible: old rows without
 * those keys still load).
 *
 * Duplicate names fixed:
 *  - Invoice "Billed To" / "Shipped To" fall back to the customer name
 *    when the invoice has no billing/shipping address row (walk-in
 *    invoices still show "Walk-in-customer").
 *  - The customer-facing invoice (customer_invoice.blade.php) reads the
 *    invoice's billing/shipping address rows instead of the legacy
 *    customers billing and shipping columns, which the current form
 *    no longer writes (NULL for every repeater-era customer).
 *  - Customer detail "Shipping Info" shows the customer name, not the
 *    free-text name stored on the shipping address row (verified on
 *    temp: Hoya #CLI00001's shipping row holds the company name).
 *  - Invoice "Branch:" already shows the POS branch name
 *    ($invoice->pos->branch->name) in the admin view and all print
 *    templates; verified against temp data — Hoya's duplication is
 *    because branch 41 is literally named after the company.
 */
class ClientIssuesCustomerFieldsTest extends TestCase
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

        foreach (['price_levels', 'company_payment_settings', 'custom_field_values', 'custom_fields', 'proposals', 'proformas', 'quotations', 'road_manifests', 'delivery_notes', 'pos_shifts', 'pos_tables', 'pos', 'product_service_units', 'credit_notes', 'invoice_bank_transfers', 'invoice_payments', 'invoice_products', 'invoices', 'bank_accounts', 'product_services', 'chart_of_accounts', 'taxes', 'product_service_categories', 'customer_shipping_addresses', 'customer_billing_addresses', 'customers', 'email_templates', 'ch_messages', 'languages', 'plans', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'permissions', 'roles', 'users', 'settings', 'branch_user', 'branches'] as $table) {
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
    // Create: contact person fields stored
    // ------------------------------------------------------------------

    public function test_create_customer_stores_contact_person_email_and_identification(): void
    {
        $response = $this->post('/customer', [
            'name' => 'Acme Lda',
            'contact' => '841234567',
            'email' => 'geral@acme.co.mz',
            'tax_number' => '400123456',
            'payment_type' => 'cash',
            'contacts' => [
                ['role' => 'Finance Director', 'name' => 'Ana Sousa', 'phone' => '849876543', 'email' => 'ana@acme.co.mz', 'identification' => 'BI 123456789'],
                ['role' => 'Manager', 'name' => 'Bruno Lima', 'phone' => '848765432', 'email' => 'bruno@acme.co.mz', 'identification' => 'Passport AB123456'],
            ],
            'billing_name' => ['Acme Lda'],
            'billing_phone' => ['841234567'],
            'billing_address' => ['Av. da Revolução 123'],
            'billing_city' => ['Inhambane'],
            'billing_state' => ['Inhambane'],
            'billing_country' => ['Mozambique'],
            'billing_zip' => ['1300'],
            'shipping_name' => ['Acme Warehouse'],
            'shipping_phone' => ['841234568'],
            'shipping_address' => ['Zona Industrial 45'],
            'shipping_city' => ['Inhambane'],
            'shipping_state' => ['Inhambane'],
            'shipping_country' => ['Mozambique'],
            'shipping_zip' => ['1300'],
        ]);

        $response->assertRedirect(route('customer.index'));
        $response->assertSessionHas('success');

        $saved = Customer::where('email', 'geral@acme.co.mz')->firstOrFail();
        $this->assertSame('Acme Lda', $saved->name);
        $contacts = json_decode($saved->contacts, true);
        $this->assertCount(2, $contacts);
        $this->assertSame('Ana Sousa', $contacts[0]['name']);
        $this->assertSame('Finance Director', $contacts[0]['role']);
        $this->assertSame('ana@acme.co.mz', $contacts[0]['email']);
        $this->assertSame('BI 123456789', $contacts[0]['identification']);
        $this->assertSame('Bruno Lima', $contacts[1]['name']);
        $this->assertSame('bruno@acme.co.mz', $contacts[1]['email']);
        $this->assertSame('Passport AB123456', $contacts[1]['identification']);

        $billing = CustomerBillingAddress::where('customer_id', $saved->id)->firstOrFail();
        $this->assertSame('Acme Lda', $billing->name);
        $this->assertSame('Av. da Revolução 123', $billing->address);
        $shipping = CustomerShippingAddress::where('customer_id', $saved->id)->firstOrFail();
        $this->assertSame('Acme Warehouse', $shipping->name);
        $this->assertSame('Zona Industrial 45', $shipping->address);
    }

    public function test_create_customer_keeps_contact_with_only_email_filled(): void
    {
        $this->post('/customer', [
            'name' => 'Solo Email Lda',
            'contact' => '841234567',
            'email' => 'solo@example.com',
            'payment_type' => 'cash',
            'contacts' => [
                ['role' => '', 'name' => '', 'phone' => '', 'email' => 'only@email.co.mz', 'identification' => ''],
            ],
        ])->assertRedirect(route('customer.index'));

        $saved = Customer::where('email', 'solo@example.com')->firstOrFail();
        $contacts = json_decode($saved->contacts, true);
        $this->assertCount(1, $contacts);
        $this->assertSame('only@email.co.mz', $contacts[0]['email']);
    }

    public function test_create_customer_rejects_invalid_contact_email(): void
    {
        $response = $this->post('/customer', [
            'name' => 'Bad Email Lda',
            'contact' => '841234567',
            'email' => 'bad@example.com',
            'payment_type' => 'cash',
            'contacts' => [
                ['role' => 'Director', 'name' => 'Ana', 'phone' => '849876543', 'email' => 'not-an-email', 'identification' => ''],
            ],
        ]);

        $response->assertRedirect(route('customer.index'));
        $response->assertSessionHas('error');
        $this->assertNull(Customer::where('email', 'bad@example.com')->first());
    }

    // ------------------------------------------------------------------
    // Forms render the split sections; edit round-trips new fields
    // ------------------------------------------------------------------

    public function test_create_form_renders_company_and_contact_person_sections(): void
    {
        $response = $this->get(route('customer.create'));

        $response->assertOk();
        $response->assertSee('Company Information');
        $response->assertSee('Company Name');
        $response->assertSee('General Email');
        $response->assertSee('Company Phone');
        $response->assertSee('Contact Person Information');
        $response->assertSee('Position');
        $response->assertSee('Personal Email');
        $response->assertSee('Identification');
        $response->assertSee('Additional Contacts');
        $response->assertSee('contacts[0][email]');
        $response->assertSee('contacts[0][identification]');

        // The form must be submittable: the modal footer with the
        // Create submit button and the closing </form> must survive
        // any rewrite of this blade (a truncated rewrite once dropped
        // the footer, the bank fields and the Additional Emails
        // section, leaving an unsavable form).
        $response->assertSee('modal-footer');
        $response->assertSee('value="Create"', false);
        $response->assertSee('type="submit"', false);
        $response->assertSee('bank_account[account_number]');
        $response->assertSee('bank_account[account_holder]');
        $response->assertSee('Additional Emails for Notifications');
        $response->assertDontSee('truncated');
    }

    public function test_edit_round_trip_keeps_new_contact_fields_and_old_rows_still_load(): void
    {
        // Old-style contacts JSON (no email / identification keys).
        // NOTE: 'contacts' is not mass-assignable (the model's $fillable
        // holds it as a 'contacts' => 'array' keyed entry), so assign
        // it directly like the controller does.
        $old = Customer::create([
            'name' => 'Legacy Co', 'email' => 'legacy@example.com', 'contact' => '841111111',
            'customer_id' => 5, 'created_by' => 1, 'balance' => 0,
        ]);
        $old->contacts = json_encode([['role' => 'Director', 'name' => 'Old Name', 'phone' => '840000000']]);
        $old->save();

        $edit = $this->get(route('customer.edit', $old->id));
        $edit->assertOk();
        $edit->assertSee('Contact Person Information');
        $edit->assertSee('Old Name');
        $edit->assertSee('contacts[0][email]');

        $response = $this->put('/customer/'.$old->id, [
            'name' => 'Legacy Co',
            'contact' => '841111111',
            'email' => 'legacy@example.com',
            'payment_type' => 'credit',
            'credit_terms' => '30 days',
            'contacts' => [
                ['role' => 'Finance Director', 'name' => 'Old Name', 'phone' => '840000000', 'email' => 'old@example.com', 'identification' => 'BI 999'],
                ['role' => 'Assistant', 'name' => 'New Person', 'phone' => '841111112', 'email' => 'new@example.com', 'identification' => ''],
            ],
        ]);

        $response->assertRedirect(route('customer.index'));
        $response->assertSessionHas('success');

        $saved = $old->fresh();
        $contacts = json_decode($saved->contacts, true);
        $this->assertCount(2, $contacts);
        $this->assertSame('old@example.com', $contacts[0]['email']);
        $this->assertSame('BI 999', $contacts[0]['identification']);
        $this->assertSame('New Person', $contacts[1]['name']);
        $this->assertSame('new@example.com', $contacts[1]['email']);
    }

    // ------------------------------------------------------------------
    // Invoice show: Billed To / Shipped To fallbacks + Branch line
    // ------------------------------------------------------------------

    public function test_invoice_show_falls_back_to_customer_name_without_address_rows(): void
    {
        $invoice = $this->makeInvoice(1, 2, 500.0);

        $response = $this->get(route('invoice.show', Crypt::encrypt($invoice->id)));

        $response->assertOk();
        // No billing/shipping rows: both blocks show the customer name,
        // not the bare '-' the audit saw on #FAT00002.
        $response->assertSee('Test Client Co');
        $response->assertDontSee('Walk-in-customer');
    }

    public function test_invoice_show_shows_address_rows_when_present(): void
    {
        $billing = CustomerBillingAddress::create([
            'customer_id' => $this->customer->id, 'name' => 'Billing Dept',
            'address' => 'Av. da Revolução 123', 'city' => 'Inhambane',
            'state' => 'Inhambane', 'country' => 'Mozambique', 'phone' => '841234567', 'zip' => '1300',
        ]);
        $shipping = CustomerShippingAddress::create([
            'customer_id' => $this->customer->id, 'name' => 'Warehouse Dock',
            'address' => 'Zona Industrial 45', 'city' => 'Maxixe',
            'state' => 'Inhambane', 'country' => 'Mozambique', 'phone' => '841234568', 'zip' => '1301',
        ]);
        $invoice = $this->makeInvoice(2, 1, 500.0);
        $invoice->billing_address_id = $billing->id;
        $invoice->shipping_address_id = $shipping->id;
        $invoice->save();

        $response = $this->get(route('invoice.show', Crypt::encrypt($invoice->id)));

        $response->assertOk();
        $response->assertSee('Billing Dept');
        $response->assertSee('Av. da Revolução 123');
        $response->assertSee('Warehouse Dock');
        $response->assertSee('Zona Industrial 45');
    }

    public function test_invoice_show_walk_in_still_shows_walk_in_customer(): void
    {
        $invoice = $this->makeInvoice(3, 1, 500.0);
        $invoice->customer_id = 0;
        $invoice->save();

        $response = $this->get(route('invoice.show', Crypt::encrypt($invoice->id)));

        $response->assertOk();
        $response->assertSee('Walk-in-customer');
    }

    public function test_invoice_show_branch_line_shows_branch_name_not_company_name(): void
    {
        $branch = Branch::create(['name' => 'Downtown Branch', 'created_by' => 1]);
        $pos = Pos::create([
            'pos_id' => 1, 'branch_id' => $branch->id, 'customer_id' => 0,
            'created_by' => 1, 'status' => 1,
        ]);
        $invoice = $this->makeInvoice(4, 1, 500.0);
        $invoice->pos_id = $pos->id;
        $invoice->save();

        $response = $this->get(route('invoice.show', Crypt::encrypt($invoice->id)));

        $response->assertOk();
        // The branch line shows the branch's own name, even though the
        // company user (the issuer) is named "Company Owner".
        $response->assertSee('Downtown Branch');
    }

    // ------------------------------------------------------------------
    // Customer-facing invoice: relations, not legacy columns
    // ------------------------------------------------------------------

    public function test_customer_invoice_link_shows_address_rows_not_legacy_columns(): void
    {
        // Legacy columns hold a stale name, but no address rows exist:
        // the public invoice must fall back to the customer name.
        $this->customer->billing_name = 'LEGACY BILLING NAME';
        $this->customer->shipping_name = 'LEGACY SHIPPING NAME';
        $this->customer->save();
        $invoice = $this->makeInvoice(5, 1, 500.0);

        $response = $this->get(route('invoice.link.copy', Crypt::encrypt($invoice->id)));

        $response->assertOk();
        $response->assertDontSee('LEGACY BILLING NAME');
        $response->assertDontSee('LEGACY SHIPPING NAME');
        $response->assertSee('Test Client Co');
    }

    public function test_customer_invoice_link_shows_address_rows_when_present(): void
    {
        $billing = CustomerBillingAddress::create([
            'customer_id' => $this->customer->id, 'name' => 'Billing Dept',
            'address' => 'Av. da Revolução 123', 'city' => 'Inhambane',
            'state' => 'Inhambane', 'country' => 'Mozambique', 'phone' => '841234567', 'zip' => '1300',
        ]);
        $shipping = CustomerShippingAddress::create([
            'customer_id' => $this->customer->id, 'name' => 'Warehouse Dock',
            'address' => 'Zona Industrial 45', 'city' => 'Maxixe',
            'state' => 'Inhambane', 'country' => 'Mozambique', 'phone' => '841234568', 'zip' => '1301',
        ]);
        $invoice = $this->makeInvoice(6, 1, 500.0);
        $invoice->billing_address_id = $billing->id;
        $invoice->shipping_address_id = $shipping->id;
        $invoice->save();

        $response = $this->get(route('invoice.link.copy', Crypt::encrypt($invoice->id)));

        $response->assertOk();
        $response->assertSee('Billing Dept');
        $response->assertSee('Av. da Revolução 123');
        $response->assertSee('Warehouse Dock');
        $response->assertSee('Zona Industrial 45');
    }

    // ------------------------------------------------------------------
    // Customer show: Shipping Info shows the customer, not the row name
    // ------------------------------------------------------------------

    public function test_customer_show_shipping_info_shows_customer_name(): void
    {
        // Mirrors temp Hoya #CLI00001: the shipping row's free-text name
        // holds the company name; the detail page must show the customer.
        CustomerShippingAddress::create([
            'customer_id' => $this->customer->id, 'name' => 'Hoya Hoya Inhambane 1, Lda',
            'address' => '', 'city' => '', 'state' => '', 'country' => '', 'phone' => '', 'zip' => '',
        ]);

        $response = $this->get(route('customer.show', Crypt::encrypt($this->customer->id)));

        $response->assertOk();
        $response->assertSee('Shipping Info');
        $response->assertSee('Test Client Co');
        $response->assertDontSee('Hoya Hoya Inhambane 1, Lda');
    }

    // ------------------------------------------------------------------
    // Fixture
    // ------------------------------------------------------------------

    protected function makeInvoice(int $invoiceNumber, int $qty, float $price): Invoice
    {
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
            ['name' => 'default_language', 'value' => 'en', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'shipping_display', 'value' => 'on', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'vat_gst_number_switch', 'value' => 'off', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'invoice_qr_display', 'value' => 'off', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('plans')->insert([
            ['id' => 1, 'name' => 'Test Plan', 'storage_limit' => 100, 'max_customers' => -1, 'created_at' => now(), 'updated_at' => now()],
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

        BankAccount::create([
            'holder_name' => 'Test Bank', 'bank_name' => 'Test', 'account_number' => '123',
            'chart_account_id' => 1, 'opening_balance' => 10000, 'created_by' => 1,
        ]);

        foreach (['create customer', 'edit customer', 'show customer', 'show invoice'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }
        $this->user->givePermissionTo(['create customer', 'edit customer', 'show customer', 'show invoice']);
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
            $table->integer('max_customers')->default(0);
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
            $table->string('password')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('contact')->nullable();
            $table->string('avatar', 100)->default('');
            $table->integer('created_by')->default(0);
            $table->integer('is_active')->default(1);
            $table->boolean('is_walk_in')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('billing_name')->nullable();
            $table->string('billing_country')->nullable();
            $table->string('billing_state')->nullable();
            $table->string('billing_city')->nullable();
            $table->string('billing_phone')->nullable();
            $table->string('billing_zip')->nullable();
            $table->text('billing_address')->nullable();
            $table->string('shipping_name')->nullable();
            $table->string('shipping_country')->nullable();
            $table->string('shipping_state')->nullable();
            $table->string('shipping_city')->nullable();
            $table->string('shipping_phone')->nullable();
            $table->string('shipping_zip')->nullable();
            $table->text('shipping_address')->nullable();
            $table->string('payment_type')->nullable();
            $table->string('credit_terms')->nullable();
            $table->decimal('credit_limit', 15, 2)->default(0);
            $table->decimal('credit_balance', 15, 2)->default(0);
            $table->integer('price_level_id')->nullable();
            $table->text('contacts')->nullable();
            $table->text('bank_account')->nullable();
            $table->text('additional_emails')->nullable();
            $table->text('kyc')->nullable();
            $table->string('kyc_document_path')->nullable();
            $table->string('notification_email', 255)->nullable();
            $table->string('notification_phone', 64)->nullable();
            $table->boolean('notify_account_email')->default(true);
            $table->boolean('notify_account_sms')->default(false);
            $table->boolean('notify_account_whatsapp')->default(false);
            $table->boolean('notify_payment_email')->default(true);
            $table->boolean('notify_payment_sms')->default(false);
            $table->boolean('notify_payment_whatsapp')->default(false);
            $table->boolean('notify_invoice_email')->default(true);
            $table->boolean('notify_invoice_sms')->default(false);
            $table->boolean('notify_invoice_whatsapp')->default(false);
            $table->string('lang')->default('en');
            $table->decimal('balance', 15, 2)->default(0);
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('customer_billing_addresses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('customer_id');
            $table->string('name')->nullable();
            $table->string('country')->nullable();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('phone')->nullable();
            $table->string('zip')->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });
        Schema::create('customer_shipping_addresses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('customer_id');
            $table->string('name')->nullable();
            $table->string('country')->nullable();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('phone')->nullable();
            $table->string('zip')->nullable();
            $table->text('address')->nullable();
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
            $table->string('name')->nullable();
            $table->integer('code')->nullable();
            $table->integer('type')->nullable();
            $table->integer('sub_type')->default(0);
            $table->integer('is_enabled')->default(1);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('product_services', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->decimal('sale_price', 15, 2)->default(0);
            $table->decimal('purchase_price', 15, 2)->default(0);
            $table->integer('tax_id')->default(0);
            $table->integer('category_id')->default(0);
            $table->integer('unit_id')->default(0);
            $table->string('type')->nullable();
            $table->integer('sale_chartaccount_id')->default(0);
            $table->integer('expense_chartaccount_id')->default(0);
            $table->integer('affect_stock')->default(0);
            $table->decimal('quantity', 16, 4)->default(0);
            $table->integer('created_by')->default(0);
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
            $table->unsignedBigInteger('billing_address_id')->nullable();
            $table->unsignedBigInteger('shipping_address_id')->nullable();
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
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->decimal('quantity', 16, 4)->default(0);
            $table->text('tax')->nullable();
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('price', 15, 2)->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice_id');
            $table->date('date')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->integer('account_id')->default(0);
            $table->integer('payment_method')->default(0);
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
        });
        Schema::create('invoice_bank_transfers', function (Blueprint $table) {
            $table->id();
            $table->integer('invoice_id');
            $table->integer('order_id')->nullable();
            $table->decimal('amount', 15, 2)->default('0.00');
            $table->string('status')->nullable();
            $table->date('date')->nullable();
            $table->string('receipt')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice')->nullable();
            $table->bigInteger('uid')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->date('date')->nullable();
            $table->boolean('is_debit')->default(false);
            $table->integer('created_by')->default(0);
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
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('order_type')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->unsignedBigInteger('customer_id')->default(0);
            $table->integer('warehouse_id')->default(0);
            $table->date('pos_date')->nullable();
            $table->integer('category_id')->default(0);
            $table->integer('status')->default(0);
            $table->integer('shipping_display')->default(1);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('pos_tables', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->timestamps();
        });
        Schema::create('pos_shifts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamps();
        });
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->timestamps();
        });
        Schema::create('road_manifests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('delivery_note_id')->nullable();
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
        Schema::create('proposals', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('proposal_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->date('issue_date')->nullable();
            $table->integer('status')->default(0);
            $table->integer('is_convert')->default(0);
            $table->timestamps();
        });
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('module')->nullable();
            $table->string('name')->nullable();
            $table->string('type')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('record_id');
            $table->unsignedBigInteger('field_id');
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('company_payment_settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('value');
            $table->integer('created_by');
            $table->timestamps();
            $table->unique(['name', 'created_by']);
        });
        Schema::create('price_levels', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->string('type')->nullable();
            $table->string('value')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
    }
}
