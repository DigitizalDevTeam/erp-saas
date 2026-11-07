<?php

namespace Tests\Unit;

use App\Exceptions\TreasuryException;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryOpeningBalance;
use App\Services\TreasuryAccountService;
use App\Services\TreasurySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TreasuryAccountServiceTest extends TestCase
{
    protected TreasuryAccountService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Manager', 'email' => 'manager@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
        ]);

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Maputo', 'created_by' => 1],
        ]);

        $this->service = new TreasuryAccountService();
    }

    protected function tearDown(): void
    {
        foreach (['treasury_opening_balances', 'treasury_financial_accounts', 'dimension_audit_events', 'bank_accounts', 'branches', 'settings', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    protected function createTables(): void
    {
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

        Schema::create('branches', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by')->nullable();
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
            $table->unique(['bank_account_id'], 'tfa_bank_account_uq');
            $table->unique(['created_by', 'code'], 'tfa_company_code_uq');
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
        ], $overrides);
    }

    protected function createAccount(int $creatorId = 1, array $overrides = []): TreasuryFinancialAccount
    {
        return $this->service->createAccount($creatorId, $this->accountPayload($overrides), 1);
    }

    protected function createBalance(TreasuryFinancialAccount $account, array $overrides = []): TreasuryOpeningBalance
    {
        return $this->service->createOpeningBalance(
            (int) $account->created_by,
            $account->id,
            array_merge([
                'amount' => '10000.0000',
                'cut_off_date' => '2026-01-01',
            ], $overrides),
            1
        );
    }

    // ------------------------------------------------------------------
    // Accounts
    // ------------------------------------------------------------------

    public function test_create_account_reuses_bank_account_fields(): void
    {
        $account = $this->createAccount();

        $this->assertSame('BIM-001', $account->code);
        $this->assertSame('MZN', $account->currency);
        $this->assertFalse((bool) $account->currency_locked);
        $this->assertSame('active', $account->status);

        $bank = $account->bankAccount;
        $this->assertSame('Hoya Hoya Lda', $bank->holder_name);
        $this->assertSame('BIM', $bank->bank_name);
        $this->assertSame('123456789', $bank->account_number);
        $this->assertEquals(0, $bank->opening_balance);
    }

    public function test_account_code_unique_per_company(): void
    {
        $this->createAccount(1);

        $this->expectException(TreasuryException::class);
        $this->createAccount(1);
    }

    public function test_account_code_reusable_across_companies(): void
    {
        $this->createAccount(1);

        $other = $this->createAccount(4, ['branch_id' => null, 'owner_user_id' => null]);

        $this->assertSame('BIM-001', $other->code);
        $this->assertSame(4, (int) $other->created_by);
    }

    public function test_create_account_rejects_bad_type_and_currency(): void
    {
        $this->expectException(TreasuryException::class);
        $this->createAccount(1, ['type' => 'vault']);
    }

    public function test_create_account_rejects_bad_currency(): void
    {
        $this->expectException(TreasuryException::class);
        $this->createAccount(1, ['currency' => 'MZN1']);
    }

    public function test_negative_policy_defaults_to_company_setting(): void
    {
        TreasurySettings::save(1, ['treasury_negative_balance_policy' => 'allow']);

        $account = $this->createAccount(1, ['negative_balance_policy' => null]);
        // Explicit null in the payload falls back to the company setting.
        $this->assertSame('allow', $account->negative_balance_policy);
    }

    public function test_update_holder_records_audit_row(): void
    {
        $account = $this->createAccount();

        $this->service->updateAccount($account, ['account_holder' => 'New Holder Lda'], 1);

        $this->assertSame('New Holder Lda', $account->fresh()->bankAccount->holder_name);
        $this->assertSame(2, DB::table('dimension_audit_events')->where('entity_type', 'treasury_financial_account')->count());
    }

    // ------------------------------------------------------------------
    // Currency lock
    // ------------------------------------------------------------------

    public function test_currency_change_allowed_before_first_approval(): void
    {
        $account = $this->createAccount();

        $updated = $this->service->updateAccount($account, ['currency' => 'USD'], 1);

        $this->assertSame('USD', $updated->currency);
    }

    public function test_currency_locked_after_first_approval(): void
    {
        $account = $this->createAccount();
        $balance = $this->createBalance($account);

        $this->service->approveOpeningBalance($balance, 1);

        $this->assertTrue((bool) $account->fresh()->currency_locked);

        $this->expectException(TreasuryException::class);
        $this->service->updateAccount($account->fresh(), ['currency' => 'USD'], 1);
    }

    // ------------------------------------------------------------------
    // Deactivation
    // ------------------------------------------------------------------

    public function test_deactivate_blocked_with_draft_opening_balance(): void
    {
        $account = $this->createAccount();
        $this->createBalance($account);

        $this->expectException(TreasuryException::class);
        $this->service->deactivateAccount($account, 1);
    }

    public function test_deactivate_allowed_after_draft_deleted(): void
    {
        $account = $this->createAccount();
        $balance = $this->createBalance($account);
        $this->service->deleteOpeningBalance($balance);

        $deactivated = $this->service->deactivateAccount($account, 1);

        $this->assertSame('inactive', $deactivated->status);
    }

    public function test_deactivate_allowed_with_approved_balance(): void
    {
        $account = $this->createAccount();
        $balance = $this->createBalance($account);
        $this->service->approveOpeningBalance($balance, 1);

        $deactivated = $this->service->deactivateAccount($account, 1);

        $this->assertSame('inactive', $deactivated->status);
    }

    // ------------------------------------------------------------------
    // Opening balances: draft → approve
    // ------------------------------------------------------------------

    public function test_opening_balance_draft_approve_and_posted_balance(): void
    {
        $account = $this->createAccount();
        $balance = $this->createBalance($account, ['amount' => '25000.5000']);

        $this->assertSame('draft', $balance->status);
        $this->assertSame('0.0000', $this->service->postedBalance($account));

        $approved = $this->service->approveOpeningBalance($balance, 1);

        $this->assertSame('approved', $approved->status);
        $this->assertSame('25000.5000', (string) $approved->amount_base);
        $this->assertSame('25000.5000', $this->service->postedBalance($account->fresh()));
        $this->assertSame('25000.5000', $this->service->availableBalance($account->fresh()));
    }

    public function test_opening_balance_currency_must_match_account(): void
    {
        $account = $this->createAccount(1, ['currency' => 'MZN']);

        $this->expectException(TreasuryException::class);
        $this->service->createOpeningBalance(1, $account->id, [
            'currency' => 'USD',
            'amount' => '100',
            'cut_off_date' => '2026-01-01',
        ], 1);
    }

    public function test_foreign_currency_balance_converts_with_rate(): void
    {
        $account = $this->createAccount(1, ['currency' => 'USD', 'code' => 'USD-001']);

        $balance = $this->createBalance($account, [
            'amount' => '100.0000',
            'exchange_rate' => '63.5000000000',
            'rate_source' => 'bank_rate',
            'rate_date' => '2026-01-01',
        ]);

        $this->assertSame('6350.0000', (string) $balance->amount_base);
        $this->assertSame('0.0000', $this->service->postedBalance($account->fresh()));

        $this->service->approveOpeningBalance($balance, 1);
        $this->assertSame('6350.0000', $this->service->postedBalance($account->fresh()));
    }

    public function test_missing_exchange_rate_refuses_creation(): void
    {
        $account = $this->createAccount(1, ['currency' => 'USD', 'code' => 'USD-001']);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessageMatches('/exchange rate/i');
        $this->createBalance($account, ['amount' => '100.0000']);
    }

    public function test_missing_rate_source_refuses_creation(): void
    {
        $account = $this->createAccount(1, ['currency' => 'USD', 'code' => 'USD-001']);

        $this->expectException(TreasuryException::class);
        $this->createBalance($account, [
            'amount' => '100.0000',
            'exchange_rate' => '63.5000000000',
            'rate_date' => '2026-01-01',
        ]);
    }

    public function test_approval_refuses_when_base_currency_changed_and_rate_missing(): void
    {
        // Draft created while the base currency was USD (rate 1, no
        // rate stored); the company then switches its base to MZN.
        TreasurySettings::save(1, ['treasury_base_currency' => 'USD']);
        $account = $this->createAccount(1, ['currency' => 'USD', 'code' => 'USD-001']);
        $balance = $this->createBalance($account, ['amount' => '100.0000']);
        TreasurySettings::save(1, ['treasury_base_currency' => 'MZN']);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessageMatches('/exchange rate/i');
        $this->service->approveOpeningBalance($balance, 1);
    }

    public function test_approved_balance_cannot_be_edited_or_deleted(): void
    {
        $account = $this->createAccount();
        $balance = $this->createBalance($account);
        $this->service->approveOpeningBalance($balance, 1);

        try {
            $this->service->updateOpeningBalance($balance->fresh(), ['amount' => '1']);
            $this->fail('Editing an approved balance should throw.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('cannot be edited', $e->getMessage());
        }

        $this->expectException(TreasuryException::class);
        $this->service->deleteOpeningBalance($balance->fresh());
    }

    // ------------------------------------------------------------------
    // Reversal
    // ------------------------------------------------------------------

    public function test_reverse_approved_balance_nets_to_zero(): void
    {
        $account = $this->createAccount();
        $balance = $this->createBalance($account, ['amount' => '10000.0000']);
        $this->service->approveOpeningBalance($balance, 1);

        $reversal = $this->service->reverseOpeningBalance($balance->fresh(), 'Wrong cut-off date', 1);

        $this->assertSame('reversed', $balance->fresh()->status);
        $this->assertSame('approved', $reversal->status);
        $this->assertSame('-10000.0000', (string) $reversal->amount);
        $this->assertSame('-10000.0000', (string) $reversal->amount_base);
        $this->assertSame($balance->id, (int) $reversal->reversal_of_id);
        $this->assertSame('0.0000', $this->service->postedBalance($account->fresh()));
    }

    public function test_reverse_requires_reason_and_approved_status(): void
    {
        $account = $this->createAccount();
        $balance = $this->createBalance($account);
        $this->service->approveOpeningBalance($balance, 1);

        $this->expectException(TreasuryException::class);
        $this->service->reverseOpeningBalance($balance->fresh(), '   ', 1);
    }

    public function test_reverse_draft_is_refused(): void
    {
        $account = $this->createAccount();
        $balance = $this->createBalance($account);

        $this->expectException(TreasuryException::class);
        $this->service->reverseOpeningBalance($balance, 'No reason', 1);
    }

    // ------------------------------------------------------------------
    // Negative-balance policy (D3)
    // ------------------------------------------------------------------

    public function test_negative_balance_block_policy(): void
    {
        $account = $this->createAccount(1, ['negative_balance_policy' => 'block']);

        $this->assertSame('allowed', $this->service->checkNegativeBalance($account, '0.0000'));
        $this->assertSame('allowed', $this->service->checkNegativeBalance($account, '100.0000'));
        $this->assertSame('blocked', $this->service->checkNegativeBalance($account, '-0.0001'));
    }

    public function test_negative_balance_allow_policy_with_limit(): void
    {
        $account = $this->createAccount(1, [
            'negative_balance_policy' => 'allow',
            'negative_balance_limit' => '1000.0000',
            'negative_balance_approver_user_id' => 2,
        ]);

        $this->assertSame('allowed', $this->service->checkNegativeBalance($account, '-500.0000'));
        $this->assertSame('allowed', $this->service->checkNegativeBalance($account, '-1000.0000'));
        $this->assertSame('blocked', $this->service->checkNegativeBalance($account, '-1000.0001'));
    }

    public function test_negative_balance_approval_policy(): void
    {
        $account = $this->createAccount(1, [
            'negative_balance_policy' => 'approval',
            'negative_balance_limit' => '1000.0000',
            'negative_balance_approver_user_id' => 2,
        ]);

        $this->assertSame('needs_approval', $this->service->checkNegativeBalance($account, '-500.0000'));
        $this->assertSame('blocked', $this->service->checkNegativeBalance($account, '-1500.0000'));
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    public function test_settings_defaults_and_per_company_isolation(): void
    {
        $defaults = TreasurySettings::forCompany(1);
        $this->assertSame('MZN', $defaults['treasury_base_currency']);
        $this->assertSame('block', $defaults['treasury_negative_balance_policy']);
        $this->assertSame('7,15,30,90', $defaults['treasury_forecast_horizons']);

        TreasurySettings::save(1, ['treasury_base_currency' => 'USD', 'treasury_retention_months' => '24']);

        $this->assertSame('USD', TreasurySettings::baseCurrency(1));
        $this->assertSame('MZN', TreasurySettings::baseCurrency(4));
        $this->assertSame('24', TreasurySettings::get(1, 'treasury_retention_months'));
    }

    public function test_settings_reject_invalid_values(): void
    {
        $saved = TreasurySettings::save(1, [
            'treasury_base_currency' => 'XX',
            'treasury_negative_balance_policy' => 'whatever',
            'treasury_forecast_horizons' => 'abc,,0',
        ]);

        $this->assertSame('MZN', $saved['treasury_base_currency']);
        $this->assertSame('block', $saved['treasury_negative_balance_policy']);
        $this->assertSame('7,15,30,90', $saved['treasury_forecast_horizons']);
    }

    // ------------------------------------------------------------------
    // Import
    // ------------------------------------------------------------------

    public function test_import_creates_drafts_under_one_batch(): void
    {
        $account = $this->createAccount();

        $created = $this->service->importOpeningBalances(1, [
            ['code' => 'BIM-001', 'amount' => '5000', 'cut_off_date' => '2026-01-01'],
            ['code' => 'BIM-001', 'amount' => '7000', 'cut_off_date' => '2026-01-01'],
        ], 1, 'BATCH-1');

        $this->assertCount(2, $created);
        $this->assertSame('BATCH-1', $created[0]->batch_reference);
        $this->assertSame('BATCH-1', $created[1]->batch_reference);
        $this->assertSame('draft', $created[0]->status);
        $this->assertSame('0.0000', $this->service->postedBalance($account->fresh()));
    }

    public function test_import_refuses_reused_batch_reference(): void
    {
        $this->createAccount();
        $this->service->importOpeningBalances(1, [
            ['code' => 'BIM-001', 'amount' => '5000', 'cut_off_date' => '2026-01-01'],
        ], 1, 'BATCH-1');

        $this->expectException(TreasuryException::class);
        $this->service->importOpeningBalances(1, [
            ['code' => 'BIM-001', 'amount' => '5000', 'cut_off_date' => '2026-01-01'],
        ], 1, 'BATCH-1');
    }

    public function test_import_is_all_or_nothing(): void
    {
        $this->createAccount();

        try {
            $this->service->importOpeningBalances(1, [
                ['code' => 'BIM-001', 'amount' => '5000', 'cut_off_date' => '2026-01-01'],
                ['code' => 'NOPE-999', 'amount' => '5000', 'cut_off_date' => '2026-01-01'],
            ], 1, 'BATCH-2');
            $this->fail('Import with an unknown account code should throw.');
        } catch (TreasuryException $e) {
            $this->assertSame(0, TreasuryOpeningBalance::count());
        }
    }

    // ------------------------------------------------------------------
    // Statement
    // ------------------------------------------------------------------

    public function test_statement_pre_period_and_running_balance(): void
    {
        $account = $this->createAccount();
        $first = $this->createBalance($account, ['amount' => '10000.0000', 'cut_off_date' => '2026-01-01']);
        $second = $this->createBalance($account, ['amount' => '5000.0000', 'cut_off_date' => '2026-02-01']);
        $this->service->approveOpeningBalance($first, 1);
        $this->service->approveOpeningBalance($second, 1);

        $statement = $this->service->statement($account->fresh(), '2026-02-01', '2026-02-28');

        $this->assertSame('10000.0000', $statement['pre_period_balance']);
        $this->assertCount(1, $statement['lines']);
        $this->assertSame('15000.0000', $statement['lines'][0]['running_balance']);
        $this->assertSame('15000.0000', $statement['closing_balance']);
    }
}
