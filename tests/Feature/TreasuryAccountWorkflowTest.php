<?php

namespace Tests\Feature;

use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryOpeningBalance;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TreasuryAccountWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['view treasury', 'manage treasury accounts', 'reverse treasury operations'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Viewer', 'email' => 'viewer@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 5, 'name' => 'Other Employee', 'email' => 'otheremployee@test.local', 'type' => 'employee', 'created_by' => 4, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Maputo', 'created_by' => 1],
        ]);

        User::findOrFail(1)->givePermissionTo(['view treasury', 'manage treasury accounts', 'reverse treasury operations']);
        User::findOrFail(2)->givePermissionTo(['view treasury']);
        User::findOrFail(4)->givePermissionTo(['view treasury', 'manage treasury accounts', 'reverse treasury operations']);
        User::findOrFail(5)->givePermissionTo(['view treasury', 'manage treasury accounts', 'reverse treasury operations']);
    }

    protected function tearDown(): void
    {
        foreach (['treasury_opening_balances', 'treasury_financial_accounts', 'dimension_audit_events', 'bank_accounts', 'chart_of_accounts', 'branches', 'branch_user', 'settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Schema (minimal, in-memory — mirrors the real migrations)
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
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function accountPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'BIM-001',
            'type' => 'bank',
            'currency' => 'MZN',
            'account_holder' => 'Hoya Hoya Lda',
            'institution' => 'BIM',
            'account_number' => '123456789',
            'chart_account_id' => 0,
            'branch_id' => 1,
            'owner_user_id' => 2,
            'negative_balance_policy' => 'block',
        ], $overrides);
    }

    protected function createAccountViaHttp(int $userId = 1, array $overrides = [])
    {
        return $this->actingAs(User::findOrFail($userId))
            ->post(route('treasury-accounts.store'), $this->accountPayload($overrides));
    }

    protected function createBalanceViaHttp(int $accountId, array $overrides = [])
    {
        return $this->actingAs(User::findOrFail(1))->post(
            route('treasury-opening-balances.store'),
            array_merge([
                'financial_account_id' => $accountId,
                'amount' => '10000',
                'cut_off_date' => '2026-01-01',
            ], $overrides)
        );
    }

    // ------------------------------------------------------------------
    // Accounts
    // ------------------------------------------------------------------

    public function test_accounts_index_happy_path(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));

        $response = $this->actingAs(User::findOrFail(1))->get(route('treasury-accounts.index'));

        $response->assertOk();
        $response->assertSee('BIM-001');
        $response->assertSee('Hoya Hoya Lda');
    }

    public function test_create_account_happy_path(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));

        $account = TreasuryFinancialAccount::where('created_by', 1)->firstOrFail();
        $this->assertSame('BIM-001', $account->code);
        $this->assertSame('MZN', $account->currency);
        $this->assertSame('Hoya Hoya Lda', $account->bankAccount->holder_name);
    }

    public function test_create_account_duplicate_code_rejected(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));

        $this->createAccountViaHttp()->assertSessionHas('error');
        $this->assertSame(1, TreasuryFinancialAccount::where('created_by', 1)->count());
    }

    public function test_accounts_index_permission_denied(): void
    {
        $response = $this->actingAs(User::findOrFail(6))->get(route('treasury-accounts.index'));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');
    }

    public function test_create_account_permission_denied_for_viewer(): void
    {
        $response = $this->actingAs(User::findOrFail(2))->post(
            route('treasury-accounts.store'),
            $this->accountPayload()
        );

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');
        $this->assertSame(0, TreasuryFinancialAccount::count());
    }

    public function test_edit_other_company_account_forbidden(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $account = TreasuryFinancialAccount::where('created_by', 1)->firstOrFail();

        $response = $this->actingAs(User::findOrFail(4))->get(route('treasury-accounts.edit', $account->id));

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');
    }

    public function test_deactivate_blocked_with_draft_balance(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $account = TreasuryFinancialAccount::where('created_by', 1)->firstOrFail();
        $this->createBalanceViaHttp($account->id);

        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('treasury-accounts.deactivate', $account->id));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame('active', $account->fresh()->status);
    }

    public function test_statement_renders_with_balances(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $account = TreasuryFinancialAccount::where('created_by', 1)->firstOrFail();
        $this->createBalanceViaHttp($account->id);
        $balance = TreasuryOpeningBalance::where('created_by', 1)->firstOrFail();
        $this->actingAs(User::findOrFail(1))
            ->post(route('treasury-opening-balances.approve', $balance->id))
            ->assertRedirect();

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('treasury-accounts.statement', $account->id));

        $response->assertOk();
        $response->assertSee('10,000.00');
    }

    // ------------------------------------------------------------------
    // Opening balances
    // ------------------------------------------------------------------

    public function test_opening_balance_draft_approve_happy_path(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $account = TreasuryFinancialAccount::where('created_by', 1)->firstOrFail();

        $this->createBalanceViaHttp($account->id)->assertRedirect(route('treasury-opening-balances.index'));
        $balance = TreasuryOpeningBalance::where('created_by', 1)->firstOrFail();
        $this->assertSame('draft', $balance->status);

        $this->actingAs(User::findOrFail(1))
            ->post(route('treasury-opening-balances.approve', $balance->id))
            ->assertRedirect(route('treasury-opening-balances.index'));

        $this->assertSame('approved', $balance->fresh()->status);
        $this->assertTrue((bool) $account->fresh()->currency_locked);
    }

    public function test_approve_without_rate_rejected_after_base_change(): void
    {
        // Base currency USD while the draft is created, then MZN.
        DB::table('settings')->updateOrInsert(
            ['name' => 'treasury_base_currency', 'created_by' => 1],
            ['value' => 'USD', 'created_at' => now(), 'updated_at' => now()]
        );
        $this->createAccountViaHttp(1, ['currency' => 'USD'])->assertRedirect(route('treasury-accounts.index'));
        $account = TreasuryFinancialAccount::where('created_by', 1)->firstOrFail();
        $this->createBalanceViaHttp($account->id)->assertRedirect(route('treasury-opening-balances.index'));
        $balance = TreasuryOpeningBalance::where('created_by', 1)->firstOrFail();

        DB::table('settings')->updateOrInsert(
            ['name' => 'treasury_base_currency', 'created_by' => 1],
            ['value' => 'MZN', 'created_at' => now(), 'updated_at' => now()]
        );

        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('treasury-opening-balances.approve', $balance->id));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame('draft', $balance->fresh()->status);
    }

    public function test_reverse_approved_balance_via_http(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $account = TreasuryFinancialAccount::where('created_by', 1)->firstOrFail();
        $this->createBalanceViaHttp($account->id);
        $balance = TreasuryOpeningBalance::where('created_by', 1)->firstOrFail();
        $this->actingAs(User::findOrFail(1))
            ->post(route('treasury-opening-balances.approve', $balance->id))
            ->assertRedirect();

        $response = $this->actingAs(User::findOrFail(1))->post(
            route('treasury-opening-balances.reverse', $balance->id),
            ['reversal_reason' => 'Wrong cut-off date']
        );

        $response->assertRedirect(route('treasury-opening-balances.index'));
        $this->assertSame('reversed', $balance->fresh()->status);
        $reversal = TreasuryOpeningBalance::where('reversal_of_id', $balance->id)->firstOrFail();
        $this->assertSame('-10000.0000', (string) $reversal->amount);
    }

    public function test_reverse_without_permission_denied(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));
        $account = TreasuryFinancialAccount::where('created_by', 1)->firstOrFail();
        $this->createBalanceViaHttp($account->id);
        $balance = TreasuryOpeningBalance::where('created_by', 1)->firstOrFail();
        $this->actingAs(User::findOrFail(1))
            ->post(route('treasury-opening-balances.approve', $balance->id))
            ->assertRedirect();

        $response = $this->actingAs(User::findOrFail(2))->post(
            route('treasury-opening-balances.reverse', $balance->id),
            ['reversal_reason' => 'No permission']
        );

        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');
        $this->assertSame('approved', $balance->fresh()->status);
    }

    public function test_csv_import_creates_drafts(): void
    {
        $this->createAccountViaHttp()->assertRedirect(route('treasury-accounts.index'));

        $csv = "code,amount,cut_off_date,currency\nBIM-001,5000,2026-01-01,MZN\nBIM-001,7000,2026-01-01,MZN\n";
        $file = UploadedFile::fake()->createWithContent('opening.csv', $csv);

        $response = $this->actingAs(User::findOrFail(1))->post(
            route('treasury-opening-balances.import.store'),
            ['batch_reference' => 'IMP-1', 'file' => $file]
        );

        $response->assertRedirect(route('treasury-opening-balances.index'));
        $this->assertSame(2, TreasuryOpeningBalance::where('created_by', 1)->count());
        $this->assertSame('IMP-1', TreasuryOpeningBalance::first()->batch_reference);
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    public function test_settings_save_happy_path(): void
    {
        $response = $this->actingAs(User::findOrFail(1))->get(route('treasury.settings'));
        $response->assertOk();
        $response->assertSee('MZN');

        $response = $this->actingAs(User::findOrFail(1))->post(route('treasury.settings.save'), [
            'treasury_base_currency' => 'USD',
            'treasury_negative_balance_policy' => 'approval',
            'treasury_reservation_policy' => 'off',
            'treasury_reference_prefix' => 'HOYA',
            'treasury_retention_months' => '24',
            'treasury_notification_channels' => 'email',
            'treasury_approval_default' => 'manager',
            'treasury_forecast_horizons' => '7,30',
            'treasury_statement_date_tolerance_days' => '5',
        ]);

        $response->assertRedirect(route('treasury.settings'));
        $this->assertSame('USD', DB::table('settings')->where('created_by', 1)->where('name', 'treasury_base_currency')->value('value'));
        $this->assertSame('approval', DB::table('settings')->where('created_by', 1)->where('name', 'treasury_negative_balance_policy')->value('value'));
        $this->assertSame('7,30', DB::table('settings')->where('created_by', 1)->where('name', 'treasury_forecast_horizons')->value('value'));
    }
}
