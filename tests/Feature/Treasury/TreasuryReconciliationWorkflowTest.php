<?php

namespace Tests\Feature\Treasury;

use App\Models\BankReconciliationMatch;
use App\Models\BankReconciliationSession;
use App\Models\BankStatementLine;
use App\Models\TreasuryEntry;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryMissingMovement;
use App\Models\TreasuryOperation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Treasury Phase 7 — reconciliation hardening, HTTP level.
 *
 * Covers: the show page renders the close panel, the missing
 * movements section and the Treasury-match form; close / reopen
 * through the controller; missing-movement propose → confirm
 * through the controller; Treasury-operation match through the
 * controller; API sync with cursor resume; duplicate-file blocking
 * at import (CA11); permission denial and cross-company 403s.
 *
 * Users: 1 = Company (all permissions), 2 = Operator (manage
 * transaction + manage treasury reconciliation), 3 = Approver
 * (manage transaction + manage + approve treasury reconciliation),
 * 4 = Other Company, 6 = No Treasury Permission.
 */
class TreasuryReconciliationWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['manage transaction', 'view treasury', 'manage treasury reconciliation', 'approve treasury reconciliation'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Operator', 'email' => 'operator@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Maputo', 'created_by' => 1],
        ]);

        User::findOrFail(1)->givePermissionTo(['manage transaction', 'view treasury', 'manage treasury reconciliation', 'approve treasury reconciliation']);
        User::findOrFail(2)->givePermissionTo(['manage transaction', 'manage treasury reconciliation']);
        User::findOrFail(3)->givePermissionTo(['manage transaction', 'manage treasury reconciliation', 'approve treasury reconciliation']);
        User::findOrFail(4)->givePermissionTo(['manage transaction', 'view treasury', 'manage treasury reconciliation', 'approve treasury reconciliation']);
        User::findOrFail(6)->givePermissionTo(['manage transaction']);

        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'code' => 121, 'name' => 'Bank', 'created_by' => 1],
            ['id' => 2, 'code' => 62, 'name' => 'Bank fees', 'created_by' => 1],
            ['id' => 3, 'code' => 76, 'name' => 'Interest income', 'created_by' => 1],
        ]);

        DB::table('bank_accounts')->insert([
            'id' => 1, 'holder_name' => 'Hoya Hoya Lda', 'bank_name' => 'BIM',
            'account_number' => '123456789', 'chart_account_id' => 1,
            'opening_balance' => 0, 'contact_number' => '', 'bank_address' => '',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        TreasuryFinancialAccount::create([
            'bank_account_id' => 1, 'created_by' => 1, 'code' => 'BIM-001',
            'type' => 'bank', 'currency' => 'MZN', 'status' => 'active',
            'negative_balance_policy' => 'block',
        ]);
        DB::table('treasury_opening_balances')->insert([
            'financial_account_id' => 1, 'created_by' => 1, 'currency' => 'MZN',
            'amount' => '200000.0000', 'amount_base' => '200000.0000',
            'cut_off_date' => '2026-11-01', 'batch_reference' => 'BATCH-1',
            'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['treasury_statement_sync_cursors', 'treasury_missing_movements', 'bank_reconciliation_matches', 'bank_statement_lines', 'bank_reconciliation_sessions', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'dimension_audit_events', 'journal_items', 'journal_entries', 'transaction_lines', 'transactions', 'bank_accounts', 'chart_of_accounts', 'branches', 'branch_user', 'settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Show page
    // ------------------------------------------------------------------

    public function test_show_page_renders_close_panel_missing_movements_and_treasury_match(): void
    {
        $session = $this->makeCloseSession();
        $operation = TreasuryOperation::where('reference', 'COMP-RCT-2026-0001')->firstOrFail();

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('bank-reconciliation.show', $session));

        $response->assertOk();
        $response->assertSee('Close');
        $response->assertSee('Statement balance');
        $response->assertSee('209,950.00');
        $response->assertSee('Missing movements');
        $response->assertSee('Link Treasury operation');
        $response->assertSee('COMP-RCT-2026-0001');
        $this->assertSame('10000.0000', (string) $operation->amount_base);
    }

    public function test_show_page_without_manage_transaction_redirects(): void
    {
        $session = $this->makeCloseSession();
        $user = User::findOrFail(6);
        $user->revokePermissionTo('manage transaction');

        $response = $this->actingAs($user)->get(route('bank-reconciliation.show', $session));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');
    }

    // ------------------------------------------------------------------
    // Close / reopen
    // ------------------------------------------------------------------

    public function test_close_then_reopen_via_http(): void
    {
        $session = $this->makeCloseSession();

        // User 2 holds manage but not approve: close is refused.
        $this->actingAs(User::findOrFail(2))
            ->post(route('bank-reconciliation.close', $session), [
                'statement_balance' => '209950', 'book_balance' => '210000',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertFalse($session->fresh()->isClosed());

        // Approver closes with the reconciling figures.
        $this->actingAs(User::findOrFail(3))
            ->post(route('bank-reconciliation.close', $session), [
                'statement_balance' => '209950', 'book_balance' => '210000',
            ])
            ->assertRedirect(route('bank-reconciliation.show', $session))
            ->assertSessionHas('success');
        $closed = $session->fresh();
        $this->assertTrue($closed->isClosed());
        $this->assertSame(3, (int) $closed->closed_by);

        // Reopen without a reason is refused by validation.
        $this->actingAs(User::findOrFail(3))
            ->post(route('bank-reconciliation.reopen', $session), ['reopen_reason' => ''])
            ->assertSessionHasErrors('reopen_reason');
        $this->assertTrue($session->fresh()->isClosed());

        // Reopen with a reason works.
        $this->actingAs(User::findOrFail(3))
            ->post(route('bank-reconciliation.reopen', $session), ['reopen_reason' => 'Bank sent a corrected file.'])
            ->assertRedirect(route('bank-reconciliation.show', $session))
            ->assertSessionHas('success');
        $reopened = $session->fresh();
        $this->assertFalse($reopened->isClosed());
        $this->assertSame('Bank sent a corrected file.', $reopened->reopen_reason);
    }

    public function test_close_with_unexplained_difference_is_refused_via_http(): void
    {
        $session = $this->makeCloseSession();

        $this->actingAs(User::findOrFail(3))
            ->post(route('bank-reconciliation.close', $session), [
                'statement_balance' => '209950', 'book_balance' => '209000',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertFalse($session->fresh()->isClosed());
    }

    // ------------------------------------------------------------------
    // Missing movements
    // ------------------------------------------------------------------

    public function test_propose_and_confirm_missing_movement_via_http(): void
    {
        $session = $this->makeCloseSession();
        $feeLine = BankStatementLine::where('session_id', $session->id)->where('description', 'Bank fee')->firstOrFail();

        // Operator opens the propose form.
        $this->actingAs(User::findOrFail(2))
            ->get(route('treasury-reconciliation.missing-movement-form', [$session, $feeLine]))
            ->assertOk()
            ->assertSee('Propose missing movement');

        // Operator proposes the fee.
        $this->actingAs(User::findOrFail(2))
            ->post(route('treasury-reconciliation.missing-movement-propose', [$session, $feeLine]), [
                'category' => 'fee', 'offset_account_id' => 2, 'reason' => 'Monthly account fee',
            ])
            ->assertRedirect(route('bank-reconciliation.show', $session))
            ->assertSessionHas('success');
        $movement = TreasuryMissingMovement::where('statement_line_id', $feeLine->id)->firstOrFail();
        $this->assertSame('proposed', $movement->state);

        // Operator cannot confirm (no approve permission).
        $this->actingAs(User::findOrFail(2))
            ->post(route('treasury-reconciliation.missing-movement-confirm', $movement))
            ->assertRedirect()
            ->assertSessionHas('error', 'Permission denied.');
        $this->assertSame('proposed', $movement->fresh()->state);

        // Approver confirms: exactly one journal, Dr fee / Cr bank.
        $this->actingAs(User::findOrFail(3))
            ->post(route('treasury-reconciliation.missing-movement-confirm', $movement))
            ->assertRedirect(route('bank-reconciliation.show', $session))
            ->assertSessionHas('success');
        $this->assertSame('confirmed', $movement->fresh()->state);
        $this->assertSame(1, DB::table('journal_entries')->count());
        $items = DB::table('journal_items')->orderBy('id')->get();
        $this->assertSame(2, (int) $items[0]->account);
        $this->assertEquals(50.0, (float) $items[0]->debit);
        $this->assertSame(1, (int) $items[1]->account);
        $this->assertEquals(50.0, (float) $items[1]->credit);
    }

    // ------------------------------------------------------------------
    // Treasury-operation match
    // ------------------------------------------------------------------

    public function test_match_treasury_operation_via_http(): void
    {
        $session = $this->makeSession();
        $line = BankStatementLine::create([
            'session_id' => $session->id, 'row_index' => 1, 'txn_date' => '2026-11-05',
            'description' => 'Transfer in', 'amount' => '10000.0000', 'balance' => '210000.0000',
        ]);
        $operation = $this->makeOperation('10000.0000', 'COMP-RCT-2026-0001');

        $this->actingAs(User::findOrFail(2))
            ->post(route('treasury-reconciliation.treasury-match', $session), [
                'statement_line_id' => $line->id,
                'treasury_operation_id' => $operation->id,
                'matched_amount' => '4000',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $match = BankReconciliationMatch::where('statement_line_id', $line->id)->firstOrFail();
        $this->assertSame($operation->id, (int) $match->treasury_operation_id);
        $this->assertSame('4000.0000', (string) $match->matched_amount);

        // Over-allocation is refused with an error, not a 500.
        $this->actingAs(User::findOrFail(2))
            ->post(route('treasury-reconciliation.treasury-match', $session), [
                'statement_line_id' => $line->id,
                'treasury_operation_id' => $operation->id,
                'matched_amount' => '9000',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertSame(1, BankReconciliationMatch::where('statement_line_id', $line->id)->count());
    }

    // ------------------------------------------------------------------
    // API sync
    // ------------------------------------------------------------------

    public function test_api_sync_imports_rows_and_resumes_on_the_cursor(): void
    {
        $rows = [
            ['external_id' => 'ext-001', 'txn_date' => '2026-11-05', 'description' => 'First', 'amount' => '1000', 'balance' => '201000'],
            ['external_id' => 'ext-002', 'txn_date' => '2026-11-06', 'description' => 'Second', 'amount' => '2000', 'balance' => '203000'],
            ['external_id' => 'ext-003', 'txn_date' => '2026-11-07', 'description' => 'Third', 'amount' => '3000', 'balance' => '206000'],
        ];
        $file = UploadedFile::fake()->createWithContent('rows.json', json_encode(['rows' => $rows]));

        $this->actingAs(User::findOrFail(2))
            ->post(route('treasury-reconciliation.api-sync'), [
                'bank_account_id' => 1, 'source_key' => 'bim-api', 'rows_file' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame(3, BankStatementLine::count());
        $this->assertSame('ext-003', DB::table('treasury_statement_sync_cursors')->value('cursor_value'));

        // Same feed again, plus one new row: only the new row imports.
        $rows[] = ['external_id' => 'ext-004', 'txn_date' => '2026-11-08', 'description' => 'Fourth', 'amount' => '4000', 'balance' => '210000'];
        $file2 = UploadedFile::fake()->createWithContent('rows.json', json_encode(['rows' => $rows]));

        $this->actingAs(User::findOrFail(2))
            ->post(route('treasury-reconciliation.api-sync'), [
                'bank_account_id' => 1, 'source_key' => 'bim-api', 'rows_file' => $file2,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame(4, BankStatementLine::count());
        $this->assertSame(1, BankStatementLine::where('external_id', 'ext-004')->count());
        $this->assertSame('ext-004', DB::table('treasury_statement_sync_cursors')->value('cursor_value'));
    }

    public function test_api_sync_form_needs_the_manage_permission(): void
    {
        $this->actingAs(User::findOrFail(6))
            ->get(route('treasury-reconciliation.api-sync-form'))
            ->assertRedirect()
            ->assertSessionHas('error', 'Permission denied.');
    }

    // ------------------------------------------------------------------
    // CA11 at import: duplicate file refused
    // ------------------------------------------------------------------

    public function test_duplicate_statement_file_is_blocked_at_import(): void
    {
        Storage::fake('local');
        $payload = function () {
            return [
                'bank_account_id' => 1,
                'statement' => UploadedFile::fake()->createWithContent('statement.csv', "date,description,amount\n2026-11-05,Transfer in,10000\n"),
            ];
        };

        $this->actingAs(User::findOrFail(1))
            ->post(route('bank-reconciliation.store'), $payload())
            ->assertRedirect();
        $this->assertSame(1, BankReconciliationSession::count());

        // The exact same file again: refused, no second session.
        $this->actingAs(User::findOrFail(1))
            ->post(route('bank-reconciliation.store'), $payload())
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertSame(1, BankReconciliationSession::count());
    }

    // ------------------------------------------------------------------
    // Cross-company
    // ------------------------------------------------------------------

    public function test_cross_company_session_is_forbidden(): void
    {
        $session = $this->makeCloseSession();

        $this->actingAs(User::findOrFail(4))
            ->get(route('bank-reconciliation.show', $session))
            ->assertForbidden();

        $this->actingAs(User::findOrFail(4))
            ->post(route('bank-reconciliation.close', $session), [
                'statement_balance' => '209950', 'book_balance' => '210000',
            ])
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function makeSession(array $overrides = []): BankReconciliationSession
    {
        return BankReconciliationSession::create(array_merge([
            'bank_account_id' => 1,
            'preset_key' => 'custom',
            'status' => 'draft',
            'original_filename' => 'statement.csv',
            'stored_path' => 'statements/statement.csv',
            'period_from' => '2026-11-01',
            'period_to' => '2026-11-30',
            'date_tolerance_days' => 3,
            'created_by' => 1,
        ], $overrides));
    }

    protected function makeOperation(string $amount, string $reference, string $state = 'confirmed'): TreasuryOperation
    {
        $operation = TreasuryOperation::create([
            'reference' => $reference, 'type' => 'receipt', 'company_id' => 1,
            'financial_account_id' => 1, 'currency' => 'MZN', 'amount_original' => $amount,
            'exchange_rate' => '1.0000000000', 'amount_base' => $amount,
            'posting_date' => '2026-11-05', 'effective_date' => '2026-11-05', 'value_date' => '2026-11-05',
            'state' => $state, 'created_by' => 1,
        ]);

        if ($state === 'confirmed') {
            TreasuryEntry::create([
                'operation_id' => $operation->id, 'financial_account_id' => 1,
                'direction' => 'inflow', 'amount_original' => $amount, 'amount_base' => $amount,
                'exchange_rate' => '1.0000000000', 'confirmed_at' => now(), 'created_by' => 1,
            ]);
        }

        return $operation;
    }

    /**
     * Opening 200,000 + confirmed 10,000 receipt = book 210,000.
     * Statement: +10,000 (balance 210,000, matched) then a −50 fee
     * (balance 209,950, unmatched). Statement 209,950 = book
     * 210,000 + outstanding (−50).
     */
    protected function makeCloseSession(): BankReconciliationSession
    {
        $session = $this->makeSession();
        $line1 = BankStatementLine::create([
            'session_id' => $session->id, 'row_index' => 1, 'txn_date' => '2026-11-05',
            'description' => 'Transfer in', 'amount' => '10000.0000', 'balance' => '210000.0000',
        ]);
        BankStatementLine::create([
            'session_id' => $session->id, 'row_index' => 2, 'txn_date' => '2026-11-06',
            'description' => 'Bank fee', 'amount' => '-50.0000', 'balance' => '209950.0000',
        ]);
        $operation = $this->makeOperation('10000.0000', 'COMP-RCT-2026-0001');
        BankReconciliationMatch::create([
            'session_id' => $session->id, 'statement_line_id' => $line1->id,
            'transaction_id' => null, 'treasury_operation_id' => $operation->id,
            'matched_amount' => '10000.0000', 'match_type' => 'manual',
        ]);

        return $session->fresh();
    }

    // ------------------------------------------------------------------
    // Schema (minimal, in-memory — mirrors the real migrations,
    // including the Phase 7 columns)
    // ------------------------------------------------------------------

    protected function createTables(): void
    {
        Schema::create('branches', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
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
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('from')->nullable();
            $table->string('slug')->nullable();
            $table->integer('created_by');
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
        Schema::create('chart_of_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('dimension_audit_events', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->string('entity_type', 50);
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 30);
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->integer('actor_id')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
        });
        Schema::create('treasury_financial_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('bank_account_id');
            $table->integer('created_by');
            $table->string('code', 50);
            $table->string('type', 20)->default('bank');
            $table->string('currency', 3)->default('MZN');
            $table->boolean('currency_locked')->default(false);
            $table->bigInteger('branch_id')->nullable();
            $table->string('nib_or_iban', 50)->nullable();
            $table->bigInteger('owner_user_id')->nullable();
            $table->decimal('cash_limit', 20, 4)->nullable();
            $table->decimal('minimum_balance', 20, 4)->nullable();
            $table->string('negative_balance_policy', 20)->default('block');
            $table->decimal('negative_balance_limit', 20, 4)->nullable();
            $table->bigInteger('negative_balance_approver_user_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
        Schema::create('treasury_opening_balances', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('financial_account_id');
            $table->integer('created_by');
            $table->string('currency', 3)->default('MZN');
            $table->decimal('amount', 20, 4)->default(0);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->date('cut_off_date');
            $table->string('batch_reference', 100);
            $table->bigInteger('operator_user_id')->nullable();
            $table->text('attachments')->nullable();
            $table->string('status', 20)->default('draft');
            $table->boolean('includes_liabilities')->default(false);
            $table->boolean('includes_receivables')->default(false);
            $table->bigInteger('reversal_of_id')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('treasury_operations', function ($table) {
            $table->bigIncrements('id');
            $table->string('reference', 60)->nullable();
            $table->string('type', 20);
            $table->integer('company_id');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('financial_account_id')->nullable();
            $table->string('counterparty_type', 20)->nullable();
            $table->bigInteger('counterparty_id')->nullable();
            $table->string('currency', 3)->default('MZN');
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->date('posting_date')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('value_date')->nullable();
            $table->string('state', 20)->default('draft');
            $table->string('source_type', 20)->nullable();
            $table->bigInteger('source_id')->nullable();
            $table->bigInteger('reversal_of_id')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('treasury_entries', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('operation_id');
            $table->bigInteger('financial_account_id');
            $table->string('direction', 10);
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->bigInteger('reversal_of_id')->nullable();
            $table->bigInteger('journal_id')->nullable();
            $table->integer('created_by');
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
        Schema::create('treasury_statement_sync_cursors', function ($table) {
            $table->id();
            $table->unsignedBigInteger('bank_account_id');
            $table->string('source_key', 64);
            $table->string('cursor_value', 255)->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->unique(['bank_account_id', 'source_key'], 'tssc_acct_src_uq');
        });
        Schema::create('journal_entries', function ($table) {
            $table->id();
            $table->date('date');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->integer('journal_id')->default(0);
            $table->unsignedTinyInteger('status')->default(0);
            $table->integer('accounting_period')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('journal_items', function ($table) {
            $table->id();
            $table->integer('journal')->default(0);
            $table->integer('account')->default(0);
            $table->text('description')->nullable();
            $table->float('debit', 15, 2)->default(0.0);
            $table->float('credit', 15, 2)->default(0.0);
            $table->timestamps();
        });
        Schema::create('transaction_lines', function ($table) {
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
        Schema::create('transactions', function ($table) {
            $table->bigIncrements('id');
            $table->integer('account')->nullable();
            $table->string('type')->nullable();
            $table->decimal('amount', 20, 4)->default(0);
            $table->text('description')->nullable();
            $table->date('date')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('customer_id')->nullable();
            $table->integer('payment_id')->nullable();
            $table->timestamps();
        });
    }
}
