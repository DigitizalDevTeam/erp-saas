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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Client issues — Issue 5: multi-document KYC (company vs contact person).
 *
 * - Company KYC is a repeater (type, number, file) stored in
 *   `customers.kyc` as a JSON array; contact-person KYC is a second
 *   repeater in `customers.contact_kyc`. Company files go to
 *   kyc_documents/, contact-person files to kyc_documents/contact/.
 * - Readers (model kycDocuments()/contactKycDocuments(), show page,
 *   edit form) handle BOTH the new array form and the legacy
 *   single-object form ({"document_type","document_number"} +
 *   kyc_document_path) — migration 310008 converts stored rows.
 * - Files are served by an authed, company-scoped route
 *   (customer.kyc.download) addressed by section + index, never by
 *   raw path. Removed / replaced files are deleted on update.
 *
 * Fixture copied from ClientIssuesCustomerFieldsTest (Issue 4),
 * with the `contact_kyc` column added to the customers table.
 */
class ClientIssuesKycTest extends TestCase
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
    // Create: multiple company docs + one contact-person doc
    // ------------------------------------------------------------------

    public function test_create_customer_stores_multiple_company_and_contact_kyc_documents(): void
    {
        Storage::fake();

        $response = $this->post('/customer', [
            'name' => 'KYC Co',
            'contact' => '841234567',
            'email' => 'kyc@acme.co.mz',
            'payment_type' => 'cash',
            'kyc' => [
                ['document_type' => 'Passport', 'document_number' => 'AB123456', 'document' => UploadedFile::fake()->create('passport.pdf', 10, 'application/pdf')],
                ['document_type' => 'Driving Licence', 'document_number' => 'DL987', 'document' => UploadedFile::fake()->create('licence.jpg', 10, 'image/jpeg')],
            ],
            'contact_kyc' => [
                ['document_type' => 'ID Card', 'document_number' => 'BI555', 'document' => UploadedFile::fake()->create('contact-id.png', 10, 'image/png')],
            ],
        ]);

        $response->assertRedirect(route('customer.index'));
        $response->assertSessionHas('success');

        $saved = Customer::where('email', 'kyc@acme.co.mz')->firstOrFail();
        $kyc = json_decode($saved->kyc, true);
        $this->assertCount(2, $kyc);
        $this->assertSame('Passport', $kyc[0]['document_type']);
        $this->assertSame('AB123456', $kyc[0]['document_number']);
        $this->assertStringStartsWith('kyc_documents/', $kyc[0]['file_path']);
        $this->assertTrue(Storage::exists($kyc[0]['file_path']));
        $this->assertSame('Driving Licence', $kyc[1]['document_type']);
        $this->assertTrue(Storage::exists($kyc[1]['file_path']));

        $contactKyc = json_decode($saved->contact_kyc, true);
        $this->assertCount(1, $contactKyc);
        $this->assertSame('ID Card', $contactKyc[0]['document_type']);
        $this->assertSame('BI555', $contactKyc[0]['document_number']);
        $this->assertStringStartsWith('kyc_documents/contact/', $contactKyc[0]['file_path']);
        $this->assertTrue(Storage::exists($contactKyc[0]['file_path']));
    }

    // ------------------------------------------------------------------
    // Edit: keep one doc, replace one, remove the contact doc
    // ------------------------------------------------------------------

    public function test_edit_customer_keeps_replaces_and_removes_kyc_documents(): void
    {
        Storage::fake();

        $keptPath = UploadedFile::fake()->create('old1.pdf', 10, 'application/pdf')->store('kyc_documents');
        $replacedPath = UploadedFile::fake()->create('old2.pdf', 10, 'application/pdf')->store('kyc_documents');
        $contactPath = UploadedFile::fake()->create('oldc.pdf', 10, 'application/pdf')->store('kyc_documents/contact');

        $cust = Customer::create([
            'name' => 'KYC Co', 'email' => 'kyc@acme.co.mz', 'contact' => '841234567',
            'customer_id' => 9, 'created_by' => 1, 'balance' => 0,
        ]);
        $cust->kyc = json_encode([
            ['document_type' => 'Passport', 'document_number' => 'P1', 'file_path' => $keptPath],
            ['document_type' => 'Licence', 'document_number' => 'L1', 'file_path' => $replacedPath],
        ]);
        $cust->contact_kyc = json_encode([
            ['document_type' => 'ID Card', 'document_number' => 'C1', 'file_path' => $contactPath],
        ]);
        $cust->save();

        $response = $this->put('/customer/'.$cust->id, [
            'name' => 'KYC Co',
            'contact' => '841234567',
            'email' => 'kyc@acme.co.mz',
            'payment_type' => 'cash',
            'kyc' => [
                ['document_type' => 'Passport', 'document_number' => 'P1', 'existing_file' => $keptPath],
                ['document_type' => 'Licence', 'document_number' => 'L1-NEW', 'document' => UploadedFile::fake()->create('new.pdf', 10, 'application/pdf')],
            ],
            // contact_kyc omitted entirely: the contact doc is removed.
        ]);

        $response->assertRedirect(route('customer.index'));
        $response->assertSessionHas('success');

        $saved = $cust->fresh();
        $kyc = json_decode($saved->kyc, true);
        $this->assertCount(2, $kyc);
        // Kept row: same path, file still there.
        $this->assertSame($keptPath, $kyc[0]['file_path']);
        $this->assertTrue(Storage::exists($keptPath));
        // Replaced row: new path, old file deleted.
        $this->assertNotSame($replacedPath, $kyc[1]['file_path']);
        $this->assertSame('L1-NEW', $kyc[1]['document_number']);
        $this->assertStringStartsWith('kyc_documents/', $kyc[1]['file_path']);
        $this->assertTrue(Storage::exists($kyc[1]['file_path']));
        $this->assertFalse(Storage::exists($replacedPath));
        // Removed contact doc: list empty, file deleted.
        $this->assertSame([], json_decode($saved->contact_kyc, true));
        $this->assertFalse(Storage::exists($contactPath));
    }

    // ------------------------------------------------------------------
    // Show page: both sections, separately
    // ------------------------------------------------------------------

    public function test_show_page_lists_company_and_contact_kyc_documents_separately(): void
    {
        $cust = Customer::create([
            'name' => 'KYC Co', 'email' => 'kyc@acme.co.mz', 'contact' => '841234567',
            'customer_id' => 9, 'created_by' => 1, 'balance' => 0,
        ]);
        $cust->kyc = json_encode([
            ['document_type' => 'Passport', 'document_number' => 'AB123456', 'file_path' => 'kyc_documents/passport.pdf'],
        ]);
        $cust->contact_kyc = json_encode([
            ['document_type' => 'ID Card', 'document_number' => 'BI555', 'file_path' => 'kyc_documents/contact/id.pdf'],
        ]);
        $cust->save();

        $response = $this->get(route('customer.show', Crypt::encrypt($cust->id)));
        $response->assertOk();
        $response->assertSee('Company KYC Documents');
        $response->assertSee('Passport');
        $response->assertSee('AB123456');
        $response->assertSee(route('customer.kyc.download', [$cust->id, 'company', 0]));
        $response->assertSee('Contact Person KYC Documents');
        $response->assertSee('ID Card');
        $response->assertSee('BI555');
        $response->assertSee(route('customer.kyc.download', [$cust->id, 'contact', 0]));

        // A customer with no documents shows the empty state in both sections.
        $empty = Customer::create([
            'name' => 'No Docs Co', 'email' => 'nodocs@acme.co.mz', 'contact' => '841234568',
            'customer_id' => 10, 'created_by' => 1, 'balance' => 0,
        ]);
        $response = $this->get(route('customer.show', Crypt::encrypt($empty->id)));
        $response->assertOk();
        $response->assertSee('No documents');
    }

    // ------------------------------------------------------------------
    // Download route: serves the file, company-scoped
    // ------------------------------------------------------------------

    public function test_download_kyc_document_serves_file_and_is_company_scoped(): void
    {
        Storage::fake();

        $path = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')->store('kyc_documents');
        $cust = Customer::create([
            'name' => 'KYC Co', 'email' => 'kyc@acme.co.mz', 'contact' => '841234567',
            'customer_id' => 9, 'created_by' => 1, 'balance' => 0,
        ]);
        $cust->kyc = json_encode([
            ['document_type' => 'Passport', 'document_number' => 'P1', 'file_path' => $path],
        ]);
        $cust->save();

        // Owner: file served.
        $this->get(route('customer.kyc.download', [$cust->id, 'company', 0]))->assertOk();

        // Another company: denied.
        $other = User::create([
            'name' => 'Other Company', 'email' => 'other@test.local',
            'password' => bcrypt('password'), 'type' => 'company',
            'created_by' => 1, 'default_company_id' => 2,
            'is_enable_login' => 1, 'lang' => 'en', 'email_verified_at' => now(),
        ]);
        $this->actingAs($other);
        $this->get(route('customer.kyc.download', [$cust->id, 'company', 0]))->assertNotFound();

        // Back as owner: bad section, bad index, and a doc with no file.
        $this->actingAs($this->user);
        $this->get(route('customer.kyc.download', [$cust->id, 'nonsense', 0]))->assertNotFound();
        $this->get(route('customer.kyc.download', [$cust->id, 'company', 5]))->assertNotFound();

        $cust->kyc = json_encode([['document_type' => 'Passport', 'document_number' => 'P1', 'file_path' => null]]);
        $cust->save();
        $this->get(route('customer.kyc.download', [$cust->id, 'company', 0]))->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Migration 310008: legacy single-doc -> array, idempotent, down()
    // ------------------------------------------------------------------

    public function test_migration_converts_legacy_single_document_kyc_to_array(): void
    {
        // Minimal pre-migration schema: no contact_kyc column yet.
        Schema::dropIfExists('customers');
        Schema::create('customers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->text('kyc')->nullable();
            $table->string('kyc_document_path')->nullable();
            $table->timestamps();
        });
        DB::table('customers')->insert([
            ['id' => 1, 'kyc' => json_encode(['document_type' => 'Passport', 'document_number' => 'AB1']), 'kyc_document_path' => 'kyc_documents/old.pdf', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'kyc' => json_encode(['document_type' => null, 'document_number' => null]), 'kyc_document_path' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'kyc' => json_encode([['document_type' => 'ID', 'document_number' => 'X', 'file_path' => 'kyc_documents/x.pdf']]), 'kyc_document_path' => null, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'kyc' => null, 'kyc_document_path' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        require_once base_path('database/migrations/2026_11_17_310008_customer_kyc_documents.php');
        $migration = new \CustomerKycDocuments();
        $migration->up();

        $this->assertTrue(Schema::hasColumn('customers', 'contact_kyc'));
        $this->assertSame(
            [['document_type' => 'Passport', 'document_number' => 'AB1', 'file_path' => 'kyc_documents/old.pdf']],
            json_decode(DB::table('customers')->find(1)->kyc, true)
        );
        // All-null legacy row: no phantom empty document.
        $this->assertSame([], json_decode(DB::table('customers')->find(2)->kyc, true));
        // Already-array row untouched.
        $this->assertSame(
            [['document_type' => 'ID', 'document_number' => 'X', 'file_path' => 'kyc_documents/x.pdf']],
            json_decode(DB::table('customers')->find(3)->kyc, true)
        );
        $this->assertNull(DB::table('customers')->find(4)->kyc);

        // Idempotent: a second run changes nothing.
        $migration->up();
        $this->assertSame(
            [['document_type' => 'Passport', 'document_number' => 'AB1', 'file_path' => 'kyc_documents/old.pdf']],
            json_decode(DB::table('customers')->find(1)->kyc, true)
        );

        // down(): drops contact_kyc, reverts the single-element array
        // to the object form; the multi-doc row stays an array.
        $migration->down();
        $this->assertFalse(Schema::hasColumn('customers', 'contact_kyc'));
        $this->assertSame(
            ['document_type' => 'Passport', 'document_number' => 'AB1'],
            json_decode(DB::table('customers')->find(1)->kyc, true)
        );
    }

    // ------------------------------------------------------------------
    // Legacy single-doc record displays on the show page (no migration)
    // ------------------------------------------------------------------

    public function test_old_single_document_record_displays_on_show_page_without_migration(): void
    {
        $cust = Customer::create([
            'name' => 'Legacy KYC Co', 'email' => 'legacy-kyc@acme.co.mz', 'contact' => '841234567',
            'customer_id' => 9, 'created_by' => 1, 'balance' => 0,
        ]);
        $cust->kyc = json_encode(['document_type' => 'Passport', 'document_number' => 'OLD123']);
        $cust->kyc_document_path = 'kyc_documents/legacy.pdf';
        $cust->save();

        $response = $this->get(route('customer.show', Crypt::encrypt($cust->id)));
        $response->assertOk();
        $response->assertSee('Company KYC Documents');
        $response->assertSee('Passport');
        $response->assertSee('OLD123');
        $response->assertSee(route('customer.kyc.download', [$cust->id, 'company', 0]));
        $response->assertSee('No documents');
    }

    // ------------------------------------------------------------------
    // Forms render the repeaters / prefill existing docs
    // ------------------------------------------------------------------

    public function test_create_form_renders_kyc_repeaters(): void
    {
        $response = $this->get(route('customer.create'));
        $response->assertOk();
        $response->assertSee('Company KYC Documents');
        $response->assertSee('Contact Person KYC Documents');
        $response->assertSee('kyc[0][document_type]');
        $response->assertSee('contact_kyc[0][document_type]');
        $response->assertSee('Add Document');
    }

    public function test_edit_form_prefills_existing_kyc_documents(): void
    {
        $cust = Customer::create([
            'name' => 'KYC Co', 'email' => 'kyc@acme.co.mz', 'contact' => '841234567',
            'customer_id' => 9, 'created_by' => 1, 'balance' => 0,
        ]);
        $cust->kyc = json_encode([
            ['document_type' => 'Passport', 'document_number' => 'P1', 'file_path' => 'kyc_documents/p.pdf'],
        ]);
        $cust->contact_kyc = json_encode([
            ['document_type' => 'ID Card', 'document_number' => 'C1', 'file_path' => null],
        ]);
        $cust->save();

        $response = $this->get(route('customer.edit', $cust->id));
        $response->assertOk();
        $response->assertSee('Company KYC Documents');
        $response->assertSee('Passport');
        $response->assertSee('P1');
        $response->assertSee('kyc[0][existing_file]');
        $response->assertSee('View Existing Document');
        $response->assertSee('Contact Person KYC Documents');
        $response->assertSee('ID Card');
        $response->assertSee('C1');
    }

    // ------------------------------------------------------------------
    // Validation: bad file type rejected
    // ------------------------------------------------------------------

    public function test_invalid_kyc_file_type_is_rejected(): void
    {
        Storage::fake();

        $response = $this->post('/customer', [
            'name' => 'Bad Co',
            'contact' => '841234567',
            'email' => 'bad@acme.co.mz',
            'payment_type' => 'cash',
            'kyc' => [
                ['document_type' => 'Passport', 'document_number' => 'X', 'document' => UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream')],
            ],
        ]);

        $response->assertRedirect(route('customer.index'));
        $response->assertSessionHas('error');
        $this->assertNull(Customer::where('email', 'bad@acme.co.mz')->first());
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
            $table->text('contact_kyc')->nullable();
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
