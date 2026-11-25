<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Utility;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Ledger journal numbers (2026-11-25) — the ledger formatted a
 * journal row's internal id as its number (#Lan01371), while the
 * journal list shows journal_id (#Lan00007), so the entries
 * could not be found in the list. getAccountData must carry the
 * real journal number for Journal rows.
 */
class LedgerJournalNumberTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->createTables();
        Permission::create(['name' => 'ledger report', 'guard_name' => 'web']);
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
        $this->user = User::create([
            'name' => 'Test Company',
            'email' => 'company@test.local',
            'password' => bcrypt('password'),
            'type' => 'company',
            'created_by' => 0,
            'is_enable_login' => 1,
            'is_active' => 1,
            'lang' => 'en',
            'email_verified_at' => now(),
        ]);
        $this->user->default_company_id = $this->user->id;
        $this->user->plan = '1';
        $this->user->save();
        $this->user->givePermissionTo(['ledger report']);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['transaction_lines', 'journal_entries', 'chart_of_accounts', 'invoices', 'bills', 'revenues', 'payments', 'customers', 'venders', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'settings', 'languages', 'email_templates', 'ch_messages', 'plans', 'branches', 'branch_user'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_ledger_journal_row_carries_the_real_journal_number(): void
    {
        $accountId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'Advance Tax Payments', 'code' => 4412, 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Entry whose internal id (1355) differs from its journal
        // number (7) — exactly the Colosso case.
        DB::table('journal_entries')->insert([
            'id' => 1355, 'journal_id' => 7, 'date' => '2026-01-12',
            'reference' => 'JE-IMPORT-007', 'status' => 1, 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('transaction_lines')->insert([
            'created_by' => $this->user->id, 'account_id' => $accountId,
            'date' => '2026-01-12', 'reference' => 'Journal', 'reference_id' => 1355,
            'debit' => 14000, 'credit' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = Utility::getAccountData($accountId, '2026-01-01', '2026-01-31');

        $this->assertCount(1, $rows);
        $this->assertSame('Journal', $rows[0]->reference);
        $this->assertSame(1355, (int) $rows[0]->reference_id);
        $this->assertSame(7, (int) $rows[0]->journal_number);
    }

    public function test_account_detail_page_renders_journal_rows_with_real_journal_number(): void
    {
        // The account detail page errored (Undefined array key
        // "invoice") and showed no transactions at all, because
        // its tables read getAccountData's flat rows as grouped
        // arrays. It must render the journal row under the real
        // journal number, the one the journal list shows.
        $accountId = DB::table('chart_of_accounts')->insertGetId([
            'name' => 'Advance Tax Payments', 'code' => 4412, 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('journal_entries')->insert([
            'id' => 1355, 'journal_id' => 7, 'date' => '2026-01-12',
            'reference' => 'JE-IMPORT-007', 'status' => 1, 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('transaction_lines')->insert([
            'created_by' => $this->user->id, 'account_id' => $accountId,
            'date' => '2026-01-12', 'reference' => 'Journal', 'reference_id' => 1355,
            'debit' => 14000, 'credit' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->get(route('chart-of-account.show', [$accountId, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31']));

        $response->assertOk();
        $response->assertSee('#Lan00007', false);
        $response->assertSee('2026-01-12', false);
        $response->assertDontSee('#Lan01355', false);
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
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('name_pt_br')->nullable();
            $table->string('name_fr')->nullable();
            $table->string('name_es')->nullable();
            $table->string('name_zh')->nullable();
            $table->integer('code')->nullable();
            $table->integer('type')->default(0);
            $table->integer('sub_type')->default(0);
            $table->integer('parent')->default(0);
            $table->integer('is_enabled')->default(1);
            $table->text('description')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('transaction_lines', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('created_by')->nullable();
            $table->integer('account_id')->nullable();
            $table->date('date')->nullable();
            $table->string('reference')->nullable();
            $table->integer('reference_id')->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('journal_id')->nullable();
            $table->date('date')->nullable();
            $table->string('reference')->nullable();
            $table->integer('status')->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('invoices', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('customer_id')->nullable();
            $table->integer('invoice_id')->nullable();
            $table->timestamps();
        });
        Schema::create('bills', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vender_id')->nullable();
            $table->integer('bill_id')->nullable();
            $table->timestamps();
        });
        Schema::create('revenues', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('customer_id')->nullable();
            $table->timestamps();
        });
        Schema::create('payments', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('vender_id')->nullable();
            $table->timestamps();
        });
        Schema::create('customers', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('venders', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }
}
