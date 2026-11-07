<?php

namespace Tests\Unit;

use App\Exceptions\TreasuryException;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryTransfer;
use App\Services\TreasuryAccountService;
use App\Services\TreasuryTransferService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Treasury Phase 4 — internal transfers with FX (plan §10 exit
 * gates CA08, CA19, CA17).
 *
 * Users: 1 = company, 2 = operator, 3 = adjustment authoriser,
 * 4 = other company. The source account is funded with 200,000
 * MZN by default; chart accounts are 121 (source bank), 122
 * (destination bank), 123 (transit), 61 (fees), 694 (FX loss),
 * 784 (FX gain).
 */
class TreasuryTransferServiceTest extends TestCase
{
    protected TreasuryAccountService $accounts;
    protected TreasuryTransferService $service;
    protected TreasuryFinancialAccount $source;
    protected TreasuryFinancialAccount $destination;
    protected TreasuryFinancialAccount $transit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Operator', 'email' => 'operator@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Authoriser', 'email' => 'authoriser@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
        ]);

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Maputo', 'created_by' => 1],
        ]);

        DB::table('permissions')->insert([
            ['id' => 1, 'name' => 'manage treasury transfers', 'guard_name' => 'web'],
        ]);
        DB::table('model_has_permissions')->insert([
            'permission_id' => 1, 'model_type' => 'App\\Models\\User', 'model_id' => 3,
        ]);

        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'code' => 121, 'name' => 'Bank source', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 2, 'code' => 122, 'name' => 'Bank destination', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 3, 'code' => 123, 'name' => 'Transit', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 4, 'code' => 61, 'name' => 'Bank fees', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 5, 'code' => 694, 'name' => 'FX losses', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 6, 'code' => 784, 'name' => 'FX gains', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
        ]);

        $this->accounts = new TreasuryAccountService();
        $this->service = new TreasuryTransferService($this->accounts);
        $this->source = $this->makeAccount('200000.0000', 'BIM-001', 'MZN', 'bank', 1);
        $this->destination = $this->makeAccount('0.0000', 'BIM-002', 'MZN', 'bank', 2);
        $this->transit = $this->makeAccount('0.0000', 'TRF-001', 'MZN', 'funds_in_transit', 3);
    }

    protected function tearDown(): void
    {
        foreach (['treasury_transfers', 'treasury_payment_batch_items', 'treasury_payment_batches', 'treasury_settlement_allocations', 'treasury_payment_attempts', 'treasury_fund_reservations', 'treasury_approval_decisions', 'treasury_approval_steps', 'treasury_payment_requests', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'dimension_audit_events', 'bank_accounts', 'chart_of_accounts', 'bill_payments', 'bill_products', 'bills', 'venders', 'branches', 'settings', 'users', 'permissions', 'model_has_permissions', 'journal_entries', 'journal_items', 'transaction_lines', 'transactions'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function makeAccount(string $balance, string $code, string $currency = 'MZN', string $type = 'bank', int $chartId = 1, string $balanceCurrency = 'MZN', ?string $balanceBase = null): TreasuryFinancialAccount
    {
        $bankId = DB::table('bank_accounts')->insertGetId([
            'holder_name' => 'Hoya Hoya Lda', 'bank_name' => 'BIM', 'account_number' => '123456789',
            'chart_account_id' => $chartId, 'opening_balance' => 0, 'contact_number' => '', 'bank_address' => '',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $account = TreasuryFinancialAccount::create([
            'bank_account_id' => $bankId, 'created_by' => 1, 'code' => $code,
            'type' => $type, 'currency' => $currency, 'status' => 'active',
            'negative_balance_policy' => 'block',
        ]);

        DB::table('treasury_opening_balances')->insert([
            'financial_account_id' => $account->id, 'created_by' => 1, 'currency' => $balanceCurrency,
            'amount' => $balance, 'amount_base' => $balanceBase ?? $balance, 'cut_off_date' => '2026-11-01',
            'batch_reference' => 'BATCH-' . $code, 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $account->fresh();
    }

    protected function transferData(array $overrides = []): array
    {
        return array_merge([
            'source_account_id' => $this->source->id,
            'destination_account_id' => $this->destination->id,
            'amount_debited' => '50000',
        ], $overrides);
    }

    protected function makeTransfer(array $overrides = []): TreasuryTransfer
    {
        return $this->service->createTransfer(1, $this->transferData($overrides), 2);
    }

    protected function confirmTransfer(TreasuryTransfer $transfer, array $options = []): array
    {
        return $this->service->confirmTransfer(1, $transfer->id, 2, $options);
    }

    protected function journalItems(int $journalId)
    {
        return DB::table('journal_items')->where('journal', $journalId)->orderBy('id')->get();
    }

    /** Sum of posted balances across accounts, in base currency. */
    protected function postedSum(TreasuryFinancialAccount ...$accounts): string
    {
        $total = '0.0000';
        foreach ($accounts as $account) {
            $total = bcadd($total, $this->accounts->postedBalance($account->fresh()), 4);
        }

        return $total;
    }

    // ------------------------------------------------------------------
    // Create / draft validation
    // ------------------------------------------------------------------

    public function test_create_immediate_same_currency_draft_has_no_reference(): void
    {
        $transfer = $this->makeTransfer();

        $this->assertTrue($transfer->isDraft());
        $this->assertNull($transfer->operation->reference);
        $this->assertSame('50000.0000', (string) $transfer->amount_debited);
        $this->assertSame('50000.0000', (string) $transfer->amount_credited);
        $this->assertSame('MZN', $transfer->source_currency);
        $this->assertSame('MZN', $transfer->destination_currency);
        $this->assertSame('1.0000000000', (string) $transfer->exchange_rate);
        $this->assertSame('draft', $transfer->operation->state);
    }

    public function test_create_same_source_and_destination_is_refused(): void
    {
        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('must be different');

        $this->makeTransfer(['destination_account_id' => $this->source->id]);
    }

    public function test_create_delayed_without_transit_is_refused(): void
    {
        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('needs a transit account');

        $this->makeTransfer(['timing' => 'delayed']);
    }

    public function test_create_delayed_with_non_transit_account_is_refused(): void
    {
        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('must be a funds-in-transit account');

        $this->makeTransfer(['timing' => 'delayed', 'transit_account_id' => $this->destination->id]);
    }

    public function test_create_delayed_with_wrong_currency_transit_is_refused(): void
    {
        $usdTransit = $this->makeAccount('0.0000', 'TRF-USD', 'USD', 'funds_in_transit', 3, 'USD', '0.0000');

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('must use the source currency');

        $this->makeTransfer(['timing' => 'delayed', 'transit_account_id' => $usdTransit->id]);
    }

    public function test_create_cross_currency_without_rate_is_refused(): void
    {
        $usd = $this->makeAccount('2000.0000', 'BIM-USD', 'USD', 'bank', 2, 'USD', '127000.0000');

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('exchange rate is required');

        $this->makeTransfer(['destination_account_id' => $usd->id]);
    }

    public function test_create_cross_currency_without_rate_source_is_refused(): void
    {
        $usd = $this->makeAccount('2000.0000', 'BIM-USD', 'USD', 'bank', 2, 'USD', '127000.0000');

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('rate source is required');

        $this->makeTransfer([
            'destination_account_id' => $usd->id,
            'exchange_rate' => '63.5',
        ]);
    }

    public function test_create_both_foreign_without_base_rate_is_refused(): void
    {
        $usd = $this->makeAccount('2000.0000', 'BIM-USD', 'USD', 'bank', 2, 'USD', '127000.0000');
        $eur = $this->makeAccount('0.0000', 'BIM-EUR', 'EUR', 'bank', 2, 'EUR', '0.0000');

        // Neither account uses the base currency (MZN): the
        // source → base rate is required on top of the FX rate.
        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('base exchange rate is required');

        // Direct service call: source USD, destination EUR.
        $this->service->createTransfer(1, [
            'source_account_id' => $usd->id,
            'destination_account_id' => $eur->id,
            'amount_debited' => '100',
            'exchange_rate' => '0.92',
            'rate_source' => 'bank',
        ], 2);
    }

    public function test_update_draft_recomputes_credited_amount(): void
    {
        $transfer = $this->makeTransfer();
        $updated = $this->service->updateTransfer($transfer, $this->transferData(['amount_debited' => '10000']), 2);

        $this->assertTrue($updated->isDraft());
        $this->assertSame('10000.0000', (string) $updated->amount_debited);
        $this->assertSame('10000.0000', (string) $updated->amount_credited);
        $this->assertNull($updated->operation->reference);
    }

    public function test_cancel_draft_never_consumes_a_reference(): void
    {
        $draft = $this->makeTransfer();
        $this->service->cancelTransfer($draft, 2);

        $this->assertTrue($draft->fresh()->isCancelled());
        $this->assertSame('cancelled', $draft->fresh()->operation->state);

        // The next confirmed transfer takes sequence 0001, not 0002.
        $next = $this->makeTransfer();
        $result = $this->confirmTransfer($next);

        $this->assertSame('COMP-TRF-2026-0001', (string) $result['operation']->reference);
    }

    // ------------------------------------------------------------------
    // Confirm — immediate, same currency
    // ------------------------------------------------------------------

    public function test_confirm_immediate_same_currency_posts_balanced_journal_and_moves_balances(): void
    {
        $transfer = $this->makeTransfer();
        $result = $this->confirmTransfer($transfer);

        $confirmed = $result['transfer'];
        $this->assertTrue($confirmed->isConfirmed());
        $this->assertSame('COMP-TRF-2026-0001', (string) $result['operation']->reference);
        $this->assertSame('confirmed', $result['operation']->state);
        $this->assertSame('50000.0000', (string) $confirmed->amount_credited);
        $this->assertSame('0.0000', (string) $confirmed->exchange_difference_base);

        // One journal: Dr destination 122, Cr source 121, balanced.
        $items = $this->journalItems($result['journal']->id);
        $this->assertCount(2, $items);
        $this->assertSame(2, (int) $items[0]->account); // 122
        $this->assertSame('50000.00', number_format((float) $items[0]->debit, 2, '.', ''));
        $this->assertSame(1, (int) $items[1]->account); // 121
        $this->assertSame('50000.00', number_format((float) $items[1]->credit, 2, '.', ''));

        // Two entries, one per leg, linked to the journal.
        $this->assertCount(2, $result['entries']);
        $this->assertSame('outflow', $result['entries'][0]->direction);
        $this->assertSame((int) $this->source->id, (int) $result['entries'][0]->financial_account_id);
        $this->assertSame('inflow', $result['entries'][1]->direction);
        $this->assertSame((int) $this->destination->id, (int) $result['entries'][1]->financial_account_id);
        $this->assertSame($result['journal']->id, (int) $result['entries'][0]->journal_id);

        // Balances moved, sum unchanged (CA08).
        $this->assertSame('150000.0000', $this->accounts->postedBalance($this->source->fresh()));
        $this->assertSame('50000.0000', $this->accounts->postedBalance($this->destination->fresh()));
        $this->assertSame('200000.0000', $this->postedSum($this->source, $this->destination));

        // Currencies lock on first confirmed movement.
        $this->assertTrue((bool) $this->source->fresh()->currency_locked);
        $this->assertTrue((bool) $this->destination->fresh()->currency_locked);
    }

    public function test_confirm_twice_is_refused(): void
    {
        $transfer = $this->makeTransfer();
        $this->confirmTransfer($transfer);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('Only a draft transfer can be confirmed');

        $this->confirmTransfer($transfer->fresh());
    }

    public function test_confirm_insufficient_funds_is_refused_and_leaves_draft(): void
    {
        $transfer = $this->makeTransfer(['amount_debited' => '250000']);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('Insufficient available balance');

        try {
            $this->confirmTransfer($transfer);
        } finally {
            $this->assertTrue($transfer->fresh()->isDraft());
            $this->assertNull($transfer->fresh()->operation->reference);
            $this->assertSame(0, DB::table('journal_entries')->count());
            $this->assertSame(0, DB::table('treasury_entries')->count());
        }
    }

    public function test_second_transfer_beyond_available_is_refused(): void
    {
        $first = $this->makeTransfer(['amount_debited' => '150000']);
        $this->confirmTransfer($first);

        // Only 50,000 is left; a second 60,000 transfer cannot move.
        $second = $this->makeTransfer(['amount_debited' => '60000']);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('Insufficient available balance');

        $this->confirmTransfer($second);
    }

    public function test_confirm_with_fee_posts_separate_fee_line(): void
    {
        $transfer = $this->makeTransfer(['amount_debited' => '10000', 'fee_amount' => '100']);
        $result = $this->confirmTransfer($transfer);

        // Journal: Dr destination 10000, Cr source 10000, Dr fee 61
        // 100, Cr source 100 — four lines, balanced.
        $items = $this->journalItems($result['journal']->id);
        $this->assertCount(4, $items);
        $this->assertSame(4, (int) $items[2]->account); // 61 fee
        $this->assertSame('100.00', number_format((float) $items[2]->debit, 2, '.', ''));
        $this->assertSame(1, (int) $items[3]->account); // 121
        $this->assertSame('100.00', number_format((float) $items[3]->credit, 2, '.', ''));

        // Source paid principal + fee; destination got the principal.
        $this->assertSame('189900.0000', $this->accounts->postedBalance($this->source->fresh()));
        $this->assertSame('10000.0000', $this->accounts->postedBalance($this->destination->fresh()));
        $this->assertSame(4, (int) $result['transfer']->fresh()->fee_account_id);
    }

    // ------------------------------------------------------------------
    // Confirm — FX
    // ------------------------------------------------------------------

    public function test_confirm_cross_currency_usd_to_mzn(): void
    {
        $usd = $this->makeAccount('2000.0000', 'BIM-USD', 'USD', 'bank', 1, 'USD', '127000.0000');

        $transfer = $this->service->createTransfer(1, [
            'source_account_id' => $usd->id,
            'destination_account_id' => $this->destination->id,
            'amount_debited' => '100',
            'exchange_rate' => '63.5',
            'rate_source' => 'bank',
            'rate_date' => '2026-11-07',
        ], 2);

        $result = $this->service->confirmTransfer(1, $transfer->id, 2);

        // 100 USD × 63.5 = 6,350 MZN credited; no difference.
        $this->assertSame('6350.0000', (string) $result['transfer']->fresh()->amount_credited);
        $this->assertSame('6350.0000', (string) $result['transfer']->fresh()->amount_credited_base);
        $this->assertSame('0.0000', (string) $result['transfer']->fresh()->exchange_difference_base);
        $this->assertSame('120650.0000', $this->accounts->postedBalance($usd->fresh()));
        $this->assertSame('6350.0000', $this->accounts->postedBalance($this->destination->fresh()));

        $items = $this->journalItems($result['journal']->id);
        $this->assertCount(2, $items);
        $this->assertSame('6350.00', number_format((float) $items[0]->debit, 2, '.', ''));

        // CA19: the historical rate is preserved on the transfer.
        $stored = $result['transfer']->fresh();
        $this->assertSame('63.5000000000', (string) $stored->exchange_rate);
        $this->assertSame('bank', $stored->rate_source);
        $this->assertSame('2026-11-07', $stored->rate_date->format('Y-m-d'));
    }

    public function test_confirm_mzn_to_usd_posts_realised_loss_line(): void
    {
        $usd = $this->makeAccount('0.0000', 'BIM-USD', 'USD', 'bank', 2, 'USD', '0.0000');

        // 2 MZN at 0.0157480315 → 0.0314 USD; credited base 1.9938
        // against 2.0000 debited: the 0.0062 residual posts as a
        // 0.01 loss line on 694 (the ledger rounds to 2 decimals).
        $transfer = $this->service->createTransfer(1, [
            'source_account_id' => $this->source->id,
            'destination_account_id' => $usd->id,
            'amount_debited' => '2',
            'exchange_rate' => '0.0157480315',
            'rate_source' => 'bank',
        ], 2);

        $result = $this->service->confirmTransfer(1, $transfer->id, 2);

        $confirmed = $result['transfer']->fresh();
        $this->assertSame('0.0314', (string) $confirmed->amount_credited);
        $this->assertSame('1.9938', (string) $confirmed->amount_credited_base);
        $this->assertSame('0.0062', (string) $confirmed->exchange_difference_base);
        $this->assertSame(5, (int) $confirmed->exchange_difference_account_id);

        $items = $this->journalItems($result['journal']->id);
        $this->assertCount(3, $items);
        $this->assertSame(2, (int) $items[0]->account); // 122 destination
        $this->assertSame('1.99', number_format((float) $items[0]->debit, 2, '.', ''));
        $this->assertSame(1, (int) $items[1]->account); // 121 source
        $this->assertSame('2.00', number_format((float) $items[1]->credit, 2, '.', ''));
        $this->assertSame(5, (int) $items[2]->account); // 694 loss
        $this->assertSame('0.01', number_format((float) $items[2]->debit, 2, '.', ''));

        $this->assertSame('199998.0000', $this->accounts->postedBalance($this->source->fresh()));
        $this->assertSame('1.9938', $this->accounts->postedBalance($usd->fresh()));
    }

    public function test_confirm_both_foreign_derives_destination_base_rate(): void
    {
        $usd = $this->makeAccount('2000.0000', 'BIM-USD', 'USD', 'bank', 1, 'USD', '127000.0000');
        $eur = $this->makeAccount('0.0000', 'BIM-EUR', 'EUR', 'bank', 2, 'EUR', '0.0000');

        // 100 USD → 92 EUR at 0.92; USD → MZN base rate 63.5.
        // Destination → base is derived (63.5 ÷ 0.92), never typed.
        // The derived rate truncates at 10 decimals, so the
        // credited base is 6349.9999 against 6350.0000 debited:
        // the 0.0001 residual is the recorded exchange difference
        // (it rounds to zero in the 2-decimal journal, so no
        // difference line is posted there).
        $transfer = $this->service->createTransfer(1, [
            'source_account_id' => $usd->id,
            'destination_account_id' => $eur->id,
            'amount_debited' => '100',
            'exchange_rate' => '0.92',
            'rate_source' => 'central bank',
            'base_exchange_rate' => '63.5',
        ], 2);

        $result = $this->service->confirmTransfer(1, $transfer->id, 2);

        $this->assertSame('92.0000', (string) $result['transfer']->fresh()->amount_credited);
        $this->assertSame('6350.0000', (string) $result['transfer']->fresh()->amount_debited_base);
        $this->assertSame('6349.9999', (string) $result['transfer']->fresh()->amount_credited_base);
        $this->assertSame('0.0001', (string) $result['transfer']->fresh()->exchange_difference_base);
        $this->assertSame('120650.0000', $this->accounts->postedBalance($usd->fresh()));
        $this->assertSame('6349.9999', $this->accounts->postedBalance($eur->fresh()));
    }

    public function test_confirm_missing_rate_is_blocked_never_guessed(): void
    {
        $usd = $this->makeAccount('0.0000', 'BIM-USD', 'USD', 'bank', 2, 'USD', '0.0000');
        $transfer = $this->service->createTransfer(1, [
            'source_account_id' => $this->source->id,
            'destination_account_id' => $usd->id,
            'amount_debited' => '100',
            'exchange_rate' => '0.0157480315',
            'rate_source' => 'bank',
        ], 2);

        // The stored rate is wiped after creation: confirm must
        // refuse instead of guessing one (D2).
        DB::table('treasury_transfers')->where('id', $transfer->id)->update(['exchange_rate' => null]);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('never guess a rate');

        $this->confirmTransfer($transfer->fresh());
    }

    // ------------------------------------------------------------------
    // Delayed transfers — CA08 (never double-count)
    // ------------------------------------------------------------------

    public function test_delayed_confirm_moves_money_to_transit_only(): void
    {
        $transfer = $this->makeTransfer(['timing' => 'delayed', 'transit_account_id' => $this->transit->id]);
        $result = $this->confirmTransfer($transfer);

        $this->assertTrue($result['transfer']->isInTransit());
        $this->assertSame('COMP-TRF-2026-0001', (string) $result['operation']->reference);
        $this->assertSame('confirmed', $result['operation']->state);

        // Source −50,000, transit +50,000, destination 0 — the sum
        // is unchanged: the money is in exactly one place (CA08).
        $this->assertSame('150000.0000', $this->accounts->postedBalance($this->source->fresh()));
        $this->assertSame('50000.0000', $this->accounts->postedBalance($this->transit->fresh()));
        $this->assertSame('0.0000', $this->accounts->postedBalance($this->destination->fresh()));
        $this->assertSame('200000.0000', $this->postedSum($this->source, $this->transit, $this->destination));

        // One journal: Dr transit 123 / Cr source 121.
        $items = $this->journalItems($result['journal']->id);
        $this->assertCount(2, $items);
        $this->assertSame(3, (int) $items[0]->account); // 123
        $this->assertSame('50000.00', number_format((float) $items[0]->debit, 2, '.', ''));
    }

    public function test_clear_delayed_transfer_nets_transit_to_zero(): void
    {
        $transfer = $this->makeTransfer(['timing' => 'delayed', 'transit_account_id' => $this->transit->id]);
        $this->confirmTransfer($transfer);

        $result = $this->service->clearTransfer(1, $transfer->id, 2);

        $this->assertTrue($result['transfer']->isConfirmed());
        $this->assertSame('150000.0000', $this->accounts->postedBalance($this->source->fresh()));
        $this->assertSame('0.0000', $this->accounts->postedBalance($this->transit->fresh()));
        $this->assertSame('50000.0000', $this->accounts->postedBalance($this->destination->fresh()));
        $this->assertSame('200000.0000', $this->postedSum($this->source, $this->transit, $this->destination));

        // Clear journal: Dr destination 122 / Cr transit 123.
        $items = $this->journalItems($result['journal']->id);
        $this->assertCount(2, $items);
        $this->assertSame(2, (int) $items[0]->account); // 122
        $this->assertSame(3, (int) $items[1]->account); // 123
    }

    public function test_clear_delayed_fx_transfer_posts_difference_at_clear(): void
    {
        $usd = $this->makeAccount('0.0000', 'BIM-USD', 'USD', 'bank', 2, 'USD', '0.0000');

        $transfer = $this->service->createTransfer(1, [
            'source_account_id' => $this->source->id,
            'destination_account_id' => $usd->id,
            'timing' => 'delayed',
            'transit_account_id' => $this->transit->id,
            'amount_debited' => '2',
            'exchange_rate' => '0.0157480315',
            'rate_source' => 'bank',
        ], 2);

        $confirmed = $this->service->confirmTransfer(1, $transfer->id, 2);

        // At confirm the transit leg is valued at the source
        // amount: no difference yet, transit holds 2.0000.
        $this->assertSame('2.0000', $this->accounts->postedBalance($this->transit->fresh()));
        $this->assertCount(2, $this->journalItems($confirmed['journal']->id));

        $cleared = $this->service->clearTransfer(1, $transfer->id, 2);

        // At clear the destination leg is valued (1.9938): the
        // 0.0062 residual posts as a 0.01 loss line, and transit
        // nets to exactly zero.
        $this->assertSame('0.0000', $this->accounts->postedBalance($this->transit->fresh()));
        $this->assertSame('1.9938', $this->accounts->postedBalance($usd->fresh()));
        $items = $this->journalItems($cleared['journal']->id);
        $this->assertCount(3, $items);
        $this->assertSame(5, (int) $items[2]->account); // 694 loss
        $this->assertSame('0.01', number_format((float) $items[2]->debit, 2, '.', ''));
    }

    public function test_clear_draft_is_refused(): void
    {
        $transfer = $this->makeTransfer(['timing' => 'delayed', 'transit_account_id' => $this->transit->id]);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('Only an in-transit transfer can be cleared');

        $this->service->clearTransfer(1, $transfer->id, 2);
    }

    public function test_clear_twice_is_refused(): void
    {
        $transfer = $this->makeTransfer(['timing' => 'delayed', 'transit_account_id' => $this->transit->id]);
        $this->confirmTransfer($transfer);
        $this->service->clearTransfer(1, $transfer->id, 2);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('Only an in-transit transfer can be cleared');

        $this->service->clearTransfer(1, $transfer->fresh()->id, 2);
    }

    // ------------------------------------------------------------------
    // Return
    // ------------------------------------------------------------------

    public function test_return_in_transit_restores_source_and_transit(): void
    {
        $transfer = $this->makeTransfer([
            'timing' => 'delayed', 'transit_account_id' => $this->transit->id,
            'amount_debited' => '50000', 'fee_amount' => '100',
        ]);
        $this->confirmTransfer($transfer);

        $result = $this->service->returnTransfer(1, $transfer->id, 2, 'Destination rejected the transfer');

        $this->assertTrue($result['transfer']->isReturned());
        $this->assertSame('Destination rejected the transfer', $result['transfer']->return_reason);

        // Source got the principal back (the fee stays charged),
        // transit is zero, destination never saw the money.
        $this->assertSame('199900.0000', $this->accounts->postedBalance($this->source->fresh()));
        $this->assertSame('0.0000', $this->accounts->postedBalance($this->transit->fresh()));
        $this->assertSame('0.0000', $this->accounts->postedBalance($this->destination->fresh()));

        // Return journal: Dr source 121 / Cr transit 123.
        $items = $this->journalItems($result['journal']->id);
        $this->assertCount(2, $items);
        $this->assertSame(1, (int) $items[0]->account); // 121
        $this->assertSame(3, (int) $items[1]->account); // 123
    }

    public function test_return_draft_is_refused(): void
    {
        $transfer = $this->makeTransfer(['timing' => 'delayed', 'transit_account_id' => $this->transit->id]);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('Only an in-transit transfer can be returned');

        $this->service->returnTransfer(1, $transfer->id, 2, 'No reason');
    }

    public function test_return_confirmed_is_refused(): void
    {
        $transfer = $this->makeTransfer();
        $this->confirmTransfer($transfer);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('Only an in-transit transfer can be returned');

        $this->service->returnTransfer(1, $transfer->id, 2, 'Too late');
    }

    // ------------------------------------------------------------------
    // Closed period (CA17), preview, scope, statement
    // ------------------------------------------------------------------

    public function test_confirm_in_closed_period_is_blocked(): void
    {
        DB::table('journal_entries')->insert([
            'date' => '2025-12-31', 'reference' => 'FY-2025-CLOSE-1', 'description' => 'Year-end close',
            'journal_id' => 1, 'status' => 1, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $transfer = $this->makeTransfer();

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('closed');

        $this->service->confirmTransfer(1, $transfer->id, 2, ['posting_date' => '2025-06-01']);
    }

    public function test_confirm_in_closed_period_with_authorised_adjustment_posts_today(): void
    {
        DB::table('journal_entries')->insert([
            'date' => '2025-12-31', 'reference' => 'FY-2025-CLOSE-1', 'description' => 'Year-end close',
            'journal_id' => 1, 'status' => 1, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $transfer = $this->makeTransfer();

        // User 3 (not the executor) holds the transfer permission.
        $result = $this->service->confirmTransfer(1, $transfer->id, 2, [
            'posting_date' => '2025-06-01',
            'adjustment' => ['authorised_by' => 3, 'reason' => 'Late 2025 transfer posted to the open period'],
        ]);

        $this->assertTrue($result['transfer']->isConfirmed());
        $this->assertSame(date('Y-m-d'), $result['posting_date']);
        $this->assertNotNull($result['adjustment']);
    }

    public function test_preview_confirm_reports_failed_availability_check(): void
    {
        $transfer = $this->makeTransfer(['amount_debited' => '250000']);
        $preview = $this->service->previewConfirm(1, $transfer->id, 2);

        $this->assertFalse($preview['can_confirm']);
        $availability = collect($preview['checks'])->firstWhere('key', 'availability');
        $this->assertFalse($availability['passed']);
        $this->assertSame('250000.0000', $preview['amounts']['amount_debited']);
    }

    public function test_preview_confirm_passes_for_a_good_transfer(): void
    {
        $transfer = $this->makeTransfer();
        $preview = $this->service->previewConfirm(1, $transfer->id, 2);

        $this->assertTrue($preview['can_confirm']);
        $this->assertSame('200000.0000', $preview['posted_balance']);
        $this->assertSame('200000.0000', $preview['available_balance']);
    }

    public function test_transfer_for_other_company_is_refused(): void
    {
        $transfer = $this->makeTransfer();

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('not found for this company');

        $this->service->transferForCompany(4, $transfer->id);
    }

    public function test_account_statement_names_the_transfer_not_a_receipt(): void
    {
        $transfer = $this->makeTransfer();
        $result = $this->confirmTransfer($transfer);
        $reference = (string) $result['operation']->reference;

        $statement = $this->accounts->statement($this->destination->fresh(), null, null);

        $this->assertSame('50000.0000', $statement['closing_balance']);
        $descriptions = array_column($statement['lines'], 'description');
        $this->assertContains('Treasury transfer ' . $reference, $descriptions);
    }

    public function test_update_confirmed_transfer_is_refused(): void
    {
        $transfer = $this->makeTransfer();
        $this->confirmTransfer($transfer);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('Only a draft transfer can be edited');

        $this->service->updateTransfer($transfer->fresh(), $this->transferData(['amount_debited' => '10000']), 2);
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
            $table->string('name');
            $table->integer('code')->default(0);
            $table->integer('type')->default(0);
            $table->integer('sub_type')->default(0);
            $table->integer('is_enabled')->default(1);
            $table->text('description')->nullable();
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

        Schema::create('venders', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->default(0);
            $table->decimal('balance', 20, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('bills', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('bill_id')->nullable();
            $table->bigInteger('vender_id')->nullable();
            $table->integer('status')->default(0);
            $table->timestamp('voided_at')->nullable();
            $table->date('send_date')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('bill_products', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('bill_id');
            $table->decimal('total', 20, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('bill_payments', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('bill_id');
            $table->date('date')->nullable();
            $table->decimal('amount', 20, 4)->default(0);
            $table->integer('account_id')->nullable();
            $table->integer('payment_method')->default(0);
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
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
            $table->integer('user_id')->nullable();
            $table->string('user_type')->nullable();
            $table->integer('account')->nullable();
            $table->string('type')->nullable();
            $table->decimal('amount', 16, 2)->default('0.0');
            $table->text('description')->nullable();
            $table->date('date')->nullable();
            $table->integer('created_by')->default(0);
            $table->integer('payment_id')->default(0);
            $table->string('category')->nullable();
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
            $table->unique(['created_by', 'reference'], 'top_company_ref_uq');
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

        Schema::create('treasury_payment_requests', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('operation_id')->nullable();
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('source_account_id');
            $table->string('beneficiary_type', 20);
            $table->bigInteger('beneficiary_id')->nullable();
            $table->string('beneficiary_name', 150)->nullable();
            $table->string('beneficiary_account', 150)->nullable();
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->decimal('paid_amount_original', 20, 4)->default(0);
            $table->decimal('paid_amount_base', 20, 4)->default(0);
            $table->date('due_date')->nullable();
            $table->date('planned_date')->nullable();
            $table->string('priority', 20)->default('normal');
            $table->text('reason')->nullable();
            $table->bigInteger('cost_centre_id')->nullable();
            $table->bigInteger('project_id')->nullable();
            $table->json('source_documents')->nullable();
            $table->string('client_reference', 100)->nullable();
            $table->json('similar_flags')->nullable();
            $table->string('state', 20)->default('draft');
            $table->integer('requested_by');
            $table->integer('executor_user_id')->nullable();
            $table->json('approved_snapshot')->nullable();
            $table->integer('approved_rule_version')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'client_reference'], 'tpr_client_ref_uq');
            $table->index(['created_by', 'state'], 'tpr_company_state_idx');
            $table->index(['source_account_id'], 'tpr_account_idx');
            $table->index(['beneficiary_type', 'beneficiary_id'], 'tpr_beneficiary_idx');
            $table->index(['operation_id'], 'tpr_operation_idx');
            $table->index(['requested_by'], 'tpr_requested_idx');
        });

        Schema::create('treasury_payment_attempts', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('payment_request_id');
            $table->integer('attempt_number')->default(1);
            $table->string('idempotency_key', 100);
            $table->string('provider', 50)->nullable();
            $table->string('provider_reference', 100)->nullable();
            $table->string('state', 20)->default('processing');
            $table->text('result')->nullable();
            $table->string('payload_hash', 64);
            $table->decimal('amount_original', 20, 4)->nullable();
            $table->decimal('amount_base', 20, 4)->nullable();
            $table->string('currency', 3)->nullable();
            $table->bigInteger('journal_id')->nullable();
            $table->bigInteger('entry_id')->nullable();
            $table->integer('created_by');
            $table->timestamps();
            $table->unique(['idempotency_key'], 'tpa_key_uq');
            $table->index(['payment_request_id'], 'tpa_request_idx');
            $table->index(['payment_request_id', 'attempt_number'], 'tpa_request_attempt_idx');
        });

        Schema::create('treasury_settlement_allocations', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('receipt_id')->nullable();
            $table->bigInteger('payment_id')->nullable();
            $table->string('target_type', 20)->default('invoice');
            $table->bigInteger('target_id');
            $table->decimal('allocated_amount', 20, 4)->default(0);
            $table->decimal('allocated_amount_base', 20, 4)->default(0);
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->string('state', 20)->default('active');
            $table->string('source', 20)->default('receipt');
            $table->bigInteger('approved_by')->nullable();
            $table->bigInteger('invoice_payment_id')->nullable();
            $table->bigInteger('journal_id')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('treasury_approval_steps', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('payment_request_id');
            $table->integer('rule_version')->default(1);
            $table->integer('level')->default(1);
            $table->string('permission', 100)->default('approve treasury payments');
            $table->integer('required_votes')->default(1);
            $table->json('eligible_user_ids')->nullable();
            $table->integer('substitute_user_id')->nullable();
            $table->timestamp('substitute_expiry')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['payment_request_id'], 'tas_request_idx');
            $table->index(['payment_request_id', 'level'], 'tas_request_level_idx');
        });

        Schema::create('treasury_approval_decisions', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('payment_request_id');
            $table->bigInteger('approval_step_id');
            $table->integer('actor_id');
            $table->string('decision', 20);
            $table->text('reason')->nullable();
            $table->integer('substitute_user_id')->nullable();
            $table->timestamp('substitute_expiry')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['payment_request_id'], 'tad_request_idx');
            $table->index(['approval_step_id'], 'tad_step_idx');
            $table->index(['actor_id'], 'tad_actor_idx');
        });

        Schema::create('treasury_fund_reservations', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('financial_account_id');
            $table->bigInteger('payment_request_id');
            $table->decimal('amount', 20, 4)->default(0);
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->string('currency', 3)->default('MZN');
            $table->timestamp('expires_at')->nullable();
            $table->decimal('consumed_amount', 20, 4)->default(0);
            $table->decimal('released_amount', 20, 4)->default(0);
            $table->string('state', 20)->default('active');
            $table->text('reason')->nullable();
            $table->integer('created_by');
            $table->timestamps();
            $table->index(['financial_account_id'], 'tfr_account_idx');
            $table->index(['payment_request_id'], 'tfr_request_idx');
            $table->index(['financial_account_id', 'state'], 'tfr_account_state_idx');
        });

        Schema::create('treasury_payment_batches', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->string('reference', 60)->nullable();
            $table->string('provider', 50)->nullable();
            $table->string('state', 20)->default('draft');
            $table->text('note')->nullable();
            $table->bigInteger('executed_by')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'reference'], 'tpb_company_ref_uq');
            $table->index(['created_by', 'state'], 'tpb_company_state_idx');
        });

        Schema::create('treasury_payment_batch_items', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('batch_id');
            $table->bigInteger('payment_request_id');
            $table->decimal('amount_original', 20, 4)->default(0);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->string('currency', 3)->default('MZN');
            $table->string('state', 20)->default('pending');
            $table->bigInteger('attempt_id')->nullable();
            $table->text('failure_reason')->nullable();
            $table->integer('created_by');
            $table->timestamps();
            $table->unique(['batch_id', 'payment_request_id'], 'tpbi_batch_request_uq');
            $table->index(['batch_id'], 'tpbi_batch_idx');
            $table->index(['payment_request_id'], 'tpbi_request_idx');
            $table->index(['attempt_id'], 'tpbi_attempt_idx');
        });

        Schema::create('treasury_transfers', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('operation_id')->nullable();
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('source_account_id');
            $table->bigInteger('destination_account_id');
            $table->bigInteger('transit_account_id')->nullable();
            $table->string('source_currency', 3)->default('MZN');
            $table->string('destination_currency', 3)->default('MZN');
            $table->string('timing', 20)->default('immediate');
            $table->decimal('amount_debited', 20, 4)->default(0);
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->decimal('base_exchange_rate', 20, 10)->nullable();
            $table->decimal('amount_credited', 20, 4)->default(0);
            $table->decimal('amount_debited_base', 20, 4)->default(0);
            $table->decimal('amount_credited_base', 20, 4)->default(0);
            $table->decimal('fee_amount', 20, 4)->default(0);
            $table->decimal('fee_base', 20, 4)->default(0);
            $table->bigInteger('fee_account_id')->nullable();
            $table->decimal('exchange_difference_base', 20, 4)->default(0);
            $table->bigInteger('exchange_difference_account_id')->nullable();
            $table->string('state', 20)->default('draft');
            $table->text('evidence')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamps();
        });
    }
}
