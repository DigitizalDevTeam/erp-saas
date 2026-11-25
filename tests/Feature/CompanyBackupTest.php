<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CompanyBackupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Company backup (2026-11-25) — "Download Your Backup" page under
 * Settings. Admin role only; the SQL dump contains data from the
 * companies the logged-in admin belongs to, and nothing from any
 * other company.
 *
 * Fixture: company A (owner id 2) and company B (owner id 3); the
 * admin (id 4) is a member of A only. Every assertion checks that
 * A's rows are in the dump and B's rows are not.
 */
class CompanyBackupTest extends TestCase
{
    private User $ownerA;
    private User $ownerB;
    private User $admin;
    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->createTables();
        $this->seedBaseRows();
        $this->seedCompaniesAndUsers();
        $this->seedCompanyData();
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['company_user', 'journal_items', 'journal_entries', 'invoice_products', 'invoices', 'customers', 'product_services', 'failed_jobs', 'password_resets', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'settings', 'languages', 'email_templates', 'ch_messages', 'plans', 'branches', 'branch_user', 'form_builders', 'form_field_responses', 'form_responses'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_dump_includes_only_the_admins_companies_data(): void
    {
        $dump = $this->dumpFor($this->admin);

        // Company A's data is in.
        $this->assertStringContainsString('INV-A-001', $dump);
        $this->assertStringContainsString('Alpha line', $dump);
        $this->assertStringContainsString('Customer Alpha', $dump);
        $this->assertStringContainsString('Product Alpha', $dump);
        $this->assertStringContainsString('JE-ALPHA', $dump);
        $this->assertStringContainsString('Alpha item', $dump);
        $this->assertStringContainsString('Company A Ltd', $dump);
        $this->assertStringContainsString('admin-a@test.local', $dump);
        $this->assertStringContainsString('Company A', $dump);

        // Company B's data is nowhere in the dump.
        $this->assertStringNotContainsString('INV-B-001', $dump);
        $this->assertStringNotContainsString('Beta line', $dump);
        $this->assertStringNotContainsString('Customer Beta', $dump);
        $this->assertStringNotContainsString('Product Beta', $dump);
        $this->assertStringNotContainsString('JE-BETA', $dump);
        $this->assertStringNotContainsString('Beta item', $dump);
        $this->assertStringNotContainsString('Company B Ltd', $dump);
        $this->assertStringNotContainsString('admin-b@test.local', $dump);
        $this->assertStringNotContainsString('Company B', $dump);

        // Super-admin rows are not the admin's companies either.
        $this->assertStringNotContainsString('Global Site', $dump);
        $this->assertStringNotContainsString('superadmin@test.local', $dump);

        // Shared reference data is included whole.
        $this->assertStringContainsString('INSERT INTO `languages`', $dump);
        $this->assertStringContainsString('English', $dump);

        // Ephemeral / system tables carry schema only, never rows.
        $this->assertStringContainsString('Table structure for table `failed_jobs`', $dump);
        $this->assertStringNotContainsString('INSERT INTO `failed_jobs`', $dump);
        $this->assertStringContainsString('Table structure for table `password_resets`', $dump);
        $this->assertStringNotContainsString('INSERT INTO `password_resets`', $dump);

        // The dump says what it left out.
        $this->assertStringContainsString('Schema only (no rows dumped):', $dump);
        $this->assertStringContainsString('failed_jobs', $dump);
    }

    public function test_dump_scopes_child_rows_through_their_parent(): void
    {
        $dump = $this->dumpFor($this->admin);

        // The A invoice's lines and the A journal's items are in;
        // the B invoice's lines and the B journal's items are not.
        $this->assertStringContainsString('Alpha line', $dump);
        $this->assertStringNotContainsString('Beta line', $dump);
        $this->assertStringContainsString('Alpha item', $dump);
        $this->assertStringNotContainsString('Beta item', $dump);
    }

    public function test_dump_scopes_form_responses_through_form_builders(): void
    {
        $dump = $this->dumpFor($this->admin);

        // The form tables scope through form_builders (the real parent
        // table — a table called `forms` never existed).
        $this->assertStringContainsString('Alpha form', $dump);
        $this->assertStringNotContainsString('Beta form', $dump);
        $this->assertStringContainsString('Alpha response', $dump);
        $this->assertStringNotContainsString('Beta response', $dump);
    }

    public function test_dump_survives_a_missing_parent_table(): void
    {
        // Temp-site failure (2026-11-25): the `forms` parent table did
        // not exist, so the row query for form_field_responses threw
        // and the streamed download stopped mid-file, truncated, with
        // no error shown. One unreadable table must never kill the dump.
        Schema::dropIfExists('invoices');

        $dump = $this->dumpFor($this->admin);

        // The dump runs to its footer instead of dying mid-stream.
        $this->assertStringContainsString('Backup summary (rows included per table)', $dump);
        $this->assertStringContainsString('Schema only (no rows dumped):', $dump);
        // The orphaned child table goes schema-only and is named.
        $this->assertStringContainsString('Table structure for table `invoice_products`', $dump);
        $this->assertStringNotContainsString('INSERT INTO `invoice_products`', $dump);
        $this->assertStringContainsString('Skipped rows for `invoice_products`', $dump);
        $footer = substr($dump, (int) strpos($dump, 'Backup summary'));
        $this->assertStringContainsString('invoice_products', $footer);
        // Tables after the broken one still dump their data.
        $this->assertStringContainsString('Product Alpha', $dump);
        $this->assertStringContainsString('JE-ALPHA', $dump);
    }

    public function test_page_is_reachable_for_admin_with_permission(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get(route('company-backup.index'));

        $response->assertOk();
        $response->assertSee('Download Your Backup', false);
        $response->assertSee(route('company-backup.download'), false);
        $response->assertSee('Company A', false);
        $response->assertDontSee('Company B', false);
    }

    public function test_page_denies_users_without_the_permission(): void
    {
        $this->actingAs($this->adminB);

        $response = $this->get(route('company-backup.index'));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');
    }

    public function test_download_denies_users_without_the_permission(): void
    {
        $this->actingAs($this->adminB);

        $response = $this->get(route('company-backup.download'));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');
    }

    public function test_backfill_grants_backup_permission_to_admin_roles_only(): void
    {
        DB::table('roles')->insert([
            ['id' => 10, 'name' => 'Administrador', 'guard_name' => 'web', 'created_by' => $this->ownerA->id, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 11, 'name' => 'Cashier', 'guard_name' => 'web', 'created_by' => $this->ownerA->id, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 12, 'name' => 'company', 'guard_name' => 'web', 'created_by' => $this->ownerA->id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        \App\Services\AdminRolePermissionBackfill::grantCompanyBackupPermission();

        $permissionIds = DB::table('permissions')
            ->whereIn('name', ['download company backup', 'menu.settings.backup'])
            ->pluck('id')
            ->all();
        $this->assertCount(2, $permissionIds);

        foreach ([10, 12] as $roleId) {
            $held = DB::table('role_has_permissions')->where('role_id', $roleId)->pluck('permission_id')->all();
            $this->assertEqualsCanonicalizing($permissionIds, $held, "Role {$roleId} should hold both backup permissions.");
        }

        $this->assertSame(0, DB::table('role_has_permissions')->where('role_id', 11)->count(), 'Cashier must not get the backup permission.');
    }

    public function test_download_streams_a_sql_file_for_the_admin(): void
    {
        $this->actingAs($this->admin);

        $response = $this->get(route('company-backup.download'));

        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition', '');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('company-backup-', $disposition);
        $this->assertStringContainsString('.sql', $disposition);
    }

    private function dumpFor(User $admin): string
    {
        $service = new CompanyBackupService($admin);
        $out = '';
        foreach ($service->generate() as $chunk) {
            $out .= $chunk;
        }

        return $out;
    }

    private function seedCompaniesAndUsers(): void
    {
        Permission::create(['name' => 'download company backup', 'guard_name' => 'web']);

        $superAdmin = $this->makeUser('Super Admin', 'superadmin@test.local', 'super admin', 0);
        $this->ownerA = $this->makeUser('Company A', 'owner-a@test.local', 'company', $superAdmin->id);
        $this->ownerA->default_company_id = $this->ownerA->id;
        $this->ownerA->save();
        $this->ownerB = $this->makeUser('Company B', 'owner-b@test.local', 'company', $superAdmin->id);
        $this->ownerB->default_company_id = $this->ownerB->id;
        $this->ownerB->save();

        $this->admin = $this->makeUser('Admin A', 'admin-a@test.local', 'Admin', $this->ownerA->id);
        $this->admin->default_company_id = $this->ownerA->id;
        $this->admin->save();
        $this->admin->givePermissionTo('download company backup');

        $this->adminB = $this->makeUser('Admin B', 'admin-b@test.local', 'Admin', $this->ownerB->id);
        $this->adminB->default_company_id = $this->ownerB->id;
        $this->adminB->save();

        DB::table('company_user')->insert([
            ['company_id' => $this->ownerA->id, 'user_id' => $this->admin->id, 'role' => 'Administrador', 'is_default' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $this->ownerB->id, 'user_id' => $this->adminB->id, 'role' => 'Administrador', 'is_default' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('roles')->insert([
            ['id' => 1, 'name' => 'Administrador', 'guard_name' => 'web', 'created_by' => $this->ownerA->id, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Administrador', 'guard_name' => 'web', 'created_by' => $this->ownerB->id, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('model_has_roles')->insert([
            ['role_id' => 1, 'model_type' => User::class, 'model_id' => $this->admin->id],
            ['role_id' => 2, 'model_type' => User::class, 'model_id' => $this->adminB->id],
        ]);
    }

    private function seedCompanyData(): void
    {
        $a = $this->ownerA->id;
        $b = $this->ownerB->id;

        DB::table('invoices')->insert([
            ['id' => 1, 'reference' => 'INV-A-001', 'customer_id' => 1, 'status' => 0, 'created_by' => $a, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'reference' => 'INV-B-001', 'customer_id' => 2, 'status' => 0, 'created_by' => $b, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('invoice_products')->insert([
            ['id' => 1, 'invoice_id' => 1, 'product_id' => 1, 'quantity' => 2, 'price' => 100, 'description' => 'Alpha line', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'invoice_id' => 2, 'product_id' => 2, 'quantity' => 3, 'price' => 200, 'description' => 'Beta line', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('customers')->insert([
            ['id' => 1, 'name' => 'Customer Alpha', 'email' => 'alpha@test.local', 'created_by' => $a, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Customer Beta', 'email' => 'beta@test.local', 'created_by' => $b, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('product_services')->insert([
            ['id' => 1, 'name' => 'Product Alpha', 'sku' => 'ALPHA-1', 'created_by' => $a, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Product Beta', 'sku' => 'BETA-1', 'created_by' => $b, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('journal_entries')->insert([
            ['id' => 1, 'date' => '2026-11-24', 'reference' => 'JE-ALPHA', 'description' => 'Alpha entry', 'created_by' => $a, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'date' => '2026-11-24', 'reference' => 'JE-BETA', 'description' => 'Beta entry', 'created_by' => $b, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('journal_items')->insert([
            ['id' => 1, 'journal' => 1, 'account' => 611, 'debit' => 100, 'credit' => 0, 'description' => 'Alpha item', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'journal' => 2, 'account' => 611, 'debit' => 200, 'credit' => 0, 'description' => 'Beta item', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('settings')->insert([
            ['name' => 'company_name', 'value' => 'Company A Ltd', 'created_by' => $a, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'company_name', 'value' => 'Company B Ltd', 'created_by' => $b, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'site_name', 'value' => 'Global Site', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('failed_jobs')->insert([
            ['id' => 1, 'uuid' => 'uuid-1', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()],
        ]);
        DB::table('form_builders')->insert([
            ['id' => 1, 'name' => 'Alpha form', 'created_by' => $a, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Beta form', 'created_by' => $b, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('form_field_responses')->insert([
            ['id' => 1, 'form_id' => 1, 'subject_id' => 1, 'name_id' => 1, 'email_id' => 1, 'user_id' => 1, 'pipeline_id' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'form_id' => 2, 'subject_id' => 2, 'name_id' => 2, 'email_id' => 2, 'user_id' => 2, 'pipeline_id' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('form_responses')->insert([
            ['id' => 1, 'form_id' => 1, 'response' => 'Alpha response', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'form_id' => 2, 'response' => 'Beta response', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('password_resets')->insert([
            ['email' => 'admin-b@test.local', 'token' => 'secret-token', 'created_at' => now()],
        ]);
    }

    private function makeUser(string $name, string $email, string $type, int $createdBy): User
    {
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('password'),
            'type' => $type,
            'created_by' => $createdBy,
            'is_enable_login' => 1,
            'is_active' => 1,
            'lang' => 'en',
            'email_verified_at' => now(),
        ]);

        return $user;
    }

    private function seedBaseRows(): void
    {
        DB::table('languages')->insert([
            ['id' => 1, 'code' => 'en', 'full_name' => 'English', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'code' => 'pt', 'full_name' => 'Portuguese', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('settings')->insert([
            ['name' => 'default_language', 'value' => 'en', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('plans')->insert([
            ['id' => 1, 'name' => 'Test', 'storage_limit' => -1, 'max_customers' => -1,
             'crm' => 1, 'project' => 1, 'hrm' => 1, 'account' => 1, 'pos' => 1, 'stock' => 1,
             'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function createTables(): void
    {
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
            $table->integer('created_by')->nullable();
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
        Schema::create('company_user', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('company_id');
            $table->integer('user_id');
            $table->string('role')->nullable();
            $table->integer('is_default')->default(0);
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('languages', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code')->nullable();
            $table->string('full_name')->nullable();
            $table->timestamps();
        });
        Schema::create('email_templates', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('from')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('ch_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('from_id')->nullable();
            $table->integer('to_id')->nullable();
            $table->text('body')->nullable();
            $table->timestamps();
        });
        Schema::create('plans', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('storage_limit')->default(0);
            $table->integer('max_customers')->default(-1);
            $table->tinyInteger('crm')->default(1);
            $table->tinyInteger('project')->default(1);
            $table->tinyInteger('hrm')->default(1);
            $table->tinyInteger('account')->default(1);
            $table->tinyInteger('pos')->default(1);
            $table->tinyInteger('stock')->default(1);
            $table->timestamps();
        });
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
        Schema::create('invoices', function (Blueprint $table) {
            $table->increments('id');
            $table->string('reference')->nullable();
            $table->integer('customer_id')->nullable();
            $table->integer('status')->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('invoice_products', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('invoice_id');
            $table->integer('product_id')->nullable();
            $table->integer('quantity')->default(0);
            $table->decimal('price', 15, 2)->default(0);
            $table->string('description')->nullable();
            $table->timestamps();
        });
        Schema::create('customers', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('product_services', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->increments('id');
            $table->date('date')->nullable();
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('journal_items', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('journal')->nullable();
            $table->integer('account')->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('description')->nullable();
            $table->timestamps();
        });
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid')->nullable();
            $table->string('queue')->nullable();
            $table->longText('payload')->nullable();
            $table->longText('exception')->nullable();
            $table->timestamp('failed_at')->nullable();
        });
        Schema::create('form_builders', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('form_field_responses', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('form_id');
            $table->integer('subject_id')->nullable();
            $table->integer('name_id')->nullable();
            $table->integer('email_id')->nullable();
            $table->integer('user_id')->nullable();
            $table->integer('pipeline_id')->nullable();
            $table->timestamps();
        });
        Schema::create('form_responses', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('form_id');
            $table->text('response')->nullable();
            $table->timestamps();
        });
        Schema::create('password_resets', function (Blueprint $table) {
            $table->string('email')->nullable();
            $table->string('token')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }
}
