<?php

namespace Tests\Feature;

use App\Models\BankReconciliationMatch;
use App\Models\BankReconciliationSession;
use App\Models\BankStatementLine;
use App\Models\TreasuryMissingMovement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Bank reconciliation delete (client follow-up 2026-11-17).
 *
 * Danilo asked "How do I remove bank reconciliation" — there was no
 * delete at all: no route, no controller method, no button. An open
 * session could neither be deleted nor closed (close needs statement
 * lines and a zero difference), so a test import like "BCI — TEST"
 * sat on the list forever.
 *
 * destroy() deletes an OPEN session plus its lines, matches and
 * proposed/rejected/reversed missing movements, and the uploaded
 * statement file. It refuses a closed session and any session with
 * a CONFIRMED missing movement (that posted Treasury operations),
 * and it refuses another company's session.
 */
class BankReconciliationDeleteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::create(['name' => 'manage transaction', 'guard_name' => 'web']);

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Operator', 'email' => 'operator@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['manage transaction']);
        User::findOrFail(2)->givePermissionTo(['manage transaction']);
        User::findOrFail(4)->givePermissionTo(['manage transaction']);

        DB::table('bank_accounts')->insert([
            'id' => 1, 'holder_name' => 'Hoya Hoya Lda', 'bank_name' => 'BIM',
            'account_number' => '123456789', 'chart_account_id' => 1,
            'opening_balance' => 0, 'contact_number' => '', 'bank_address' => '',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['treasury_missing_movements', 'bank_reconciliation_matches', 'bank_statement_lines', 'bank_reconciliation_sessions', 'bank_accounts', 'branches', 'branch_user', 'settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_delete_removes_an_open_session_and_its_statement_file(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('bank-statements/1/test.csv', "date,amount\n2026-11-01,100\n");
        $session = $this->makeSession(['stored_path' => 'bank-statements/1/test.csv']);

        $response = $this->actingAs(User::findOrFail(1))
            ->delete(route('bank-reconciliation.destroy', $session));

        $response->assertRedirect(route('bank-reconciliation.index'));
        $response->assertSessionHas('success');
        $this->assertSame(0, BankReconciliationSession::count());
        Storage::disk('local')->assertMissing('bank-statements/1/test.csv');
    }

    public function test_delete_removes_lines_and_matches_with_the_session(): void
    {
        $session = $this->makeSession();
        $line = BankStatementLine::create([
            'session_id' => $session->id, 'row_index' => 0,
            'txn_date' => '2026-11-05', 'description' => 'Transfer', 'amount' => '100.0000',
        ]);
        BankReconciliationMatch::create([
            'session_id' => $session->id, 'statement_line_id' => $line->id,
            'transaction_id' => null, 'match_type' => 'auto',
        ]);

        $this->actingAs(User::findOrFail(1))
            ->delete(route('bank-reconciliation.destroy', $session))
            ->assertRedirect(route('bank-reconciliation.index'));

        $this->assertSame(0, BankReconciliationSession::count());
        $this->assertSame(0, BankStatementLine::count());
        $this->assertSame(0, BankReconciliationMatch::count());
    }

    public function test_delete_removes_proposed_missing_movements_with_the_session(): void
    {
        $session = $this->makeSession();
        $line = BankStatementLine::create([
            'session_id' => $session->id, 'row_index' => 0,
            'txn_date' => '2026-11-05', 'description' => 'Fee', 'amount' => '-50.0000',
        ]);
        TreasuryMissingMovement::create([
            'session_id' => $session->id, 'statement_line_id' => $line->id,
            'category' => 'fee', 'offset_account_id' => 1,
            'amount_original' => '50.0000', 'amount_base' => '50.0000',
            'state' => TreasuryMissingMovement::STATE_PROPOSED, 'created_by' => 1,
        ]);

        $this->actingAs(User::findOrFail(1))
            ->delete(route('bank-reconciliation.destroy', $session))
            ->assertRedirect(route('bank-reconciliation.index'));

        $this->assertSame(0, BankReconciliationSession::count());
        $this->assertSame(0, TreasuryMissingMovement::count());
    }

    public function test_delete_refuses_a_session_with_a_confirmed_missing_movement(): void
    {
        $session = $this->makeSession();
        $line = BankStatementLine::create([
            'session_id' => $session->id, 'row_index' => 0,
            'txn_date' => '2026-11-05', 'description' => 'Fee', 'amount' => '-50.0000',
        ]);
        TreasuryMissingMovement::create([
            'session_id' => $session->id, 'statement_line_id' => $line->id,
            'category' => 'fee', 'offset_account_id' => 1,
            'amount_original' => '50.0000', 'amount_base' => '50.0000',
            'state' => TreasuryMissingMovement::STATE_CONFIRMED, 'created_by' => 1,
        ]);

        $response = $this->actingAs(User::findOrFail(1))
            ->delete(route('bank-reconciliation.destroy', $session));

        $response->assertSessionHas('error');
        $this->assertSame(1, BankReconciliationSession::count());
        $this->assertSame(1, TreasuryMissingMovement::count());
    }

    public function test_delete_refuses_a_closed_session(): void
    {
        $session = $this->makeSession(['status' => BankReconciliationSession::STATUS_CLOSED]);

        $response = $this->actingAs(User::findOrFail(1))
            ->delete(route('bank-reconciliation.destroy', $session));

        $response->assertSessionHas('error');
        $this->assertSame(1, BankReconciliationSession::count());
    }

    public function test_delete_refuses_another_companys_session(): void
    {
        $session = $this->makeSession(['created_by' => 4]);

        $this->actingAs(User::findOrFail(1))
            ->delete(route('bank-reconciliation.destroy', $session))
            ->assertForbidden();

        $this->assertSame(1, BankReconciliationSession::count());
    }

    public function test_delete_needs_the_manage_transaction_permission(): void
    {
        $session = $this->makeSession();

        $response = $this->actingAs(User::findOrFail(6))
            ->delete(route('bank-reconciliation.destroy', $session));

        $response->assertSessionHas('error');
        $this->assertSame(1, BankReconciliationSession::count());
    }

    public function test_index_shows_the_delete_button_for_open_sessions(): void
    {
        $open = $this->makeSession();
        $closed = $this->makeSession([
            'status' => BankReconciliationSession::STATUS_CLOSED,
            'original_filename' => 'closed.csv',
            'stored_path' => 'bank-statements/1/closed.csv',
        ]);

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('bank-reconciliation.index'));

        $response->assertOk();
        $response->assertSee('delete-form-'.$open->id, false);
        $response->assertDontSee('delete-form-'.$closed->id, false);
    }

    protected function makeSession(array $overrides = []): BankReconciliationSession
    {
        return BankReconciliationSession::create(array_merge([
            'bank_account_id' => 1,
            'preset_key' => 'custom',
            'status' => 'draft',
            'original_filename' => 'statement.csv',
            'stored_path' => 'bank-statements/1/statement.csv',
            'period_from' => '2026-11-01',
            'period_to' => '2026-11-30',
            'date_tolerance_days' => 3,
            'created_by' => 1,
        ], $overrides));
    }

    protected function createTables(): void
    {
        Schema::create('branches', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('default_warehouse_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('branch_user', function ($table) {
            $table->bigIncrements('id');
            $table->integer('branch_id');
            $table->integer('user_id');
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
            $table->index(['role_id', 'permission_id']);
        });
        Schema::create('plans', function ($table) {
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
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->text('content')->nullable();
            $table->timestamps();
        });
        Schema::create('bank_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->string('holder_name');
            $table->string('bank_name');
            $table->string('account_number');
            $table->integer('chart_account_id')->default(0);
            $table->float('opening_balance', 15, 2)->default(0);
            $table->string('contact_number');
            $table->text('bank_address');
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('bank_reconciliation_sessions', function ($table) {
            $table->id();
            $table->unsignedBigInteger('bank_account_id');
            $table->string('preset_key', 64)->default('custom');
            $table->string('status', 32)->default('draft');
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('file_hash', 64)->nullable();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->unsignedSmallInteger('date_tolerance_days')->default(3);
            $table->json('column_mapping')->nullable();
            $table->decimal('statement_balance', 20, 4)->nullable();
            $table->decimal('book_balance', 20, 4)->nullable();
            $table->decimal('outstanding_items', 20, 4)->nullable();
            $table->decimal('explained_differences', 20, 4)->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->dateTime('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->unsignedInteger('created_by');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->unique(['bank_account_id', 'file_hash'], 'brs_acct_hash_uq');
        });
        Schema::create('bank_statement_lines', function ($table) {
            $table->id();
            $table->foreignId('session_id')->constrained('bank_reconciliation_sessions')->cascadeOnDelete();
            $table->unsignedInteger('row_index');
            $table->string('external_id', 128)->nullable();
            $table->date('txn_date')->nullable();
            $table->text('description')->nullable();
            $table->decimal('amount', 18, 4)->nullable();
            $table->decimal('balance', 18, 4)->nullable();
            $table->text('parse_error')->nullable();
            $table->json('raw_row')->nullable();
            $table->timestamps();
        });
        Schema::create('bank_reconciliation_matches', function ($table) {
            $table->id();
            $table->foreignId('session_id')->constrained('bank_reconciliation_sessions')->cascadeOnDelete();
            $table->foreignId('statement_line_id')->constrained('bank_statement_lines')->cascadeOnDelete();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('treasury_operation_id')->nullable();
            $table->decimal('matched_amount', 20, 4)->nullable();
            $table->string('match_type', 24)->default('auto');
            $table->string('match_note', 255)->nullable();
            $table->timestamps();
            $table->unique(['session_id', 'transaction_id']);
        });
        Schema::create('treasury_missing_movements', function ($table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('statement_line_id');
            $table->string('category', 32);
            $table->unsignedBigInteger('offset_account_id');
            $table->string('currency', 8)->default('MZN');
            $table->decimal('amount_original', 20, 4);
            $table->decimal('exchange_rate', 20, 10)->default('1.0000000000');
            $table->decimal('amount_base', 20, 4);
            $table->string('state', 24)->default('proposed');
            $table->unsignedBigInteger('operation_id')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->unsignedBigInteger('reversal_operation_id')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamps();
        });
    }
}
