<?php

namespace Tests\Unit;

use App\Exceptions\TreasuryException;
use App\Models\TreasuryCashSession;
use App\Models\TreasuryFinancialAccount;
use App\Services\TreasuryAccountService;
use App\Services\TreasuryCashSessionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Treasury Phase 5 — cash registers (plan §5, §6, §10, CA09).
 *
 * Users: 1 = company (closer), 2 = operator, 3 = approver (holds
 * both Phase 5 permissions), 4 = other company. The register is
 * funded with 10,000 MZN; chart accounts are 121 (register bank),
 * 122 (central bank), 684 (shortage), 764 (surplus).
 */
class TreasuryCashSessionServiceTest extends TestCase
{
    protected TreasuryAccountService $accounts;
    protected TreasuryCashSessionService $service;
    protected TreasuryFinancialAccount $register;
    protected TreasuryFinancialAccount $central;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Operator', 'email' => 'operator@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
        ]);

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Maputo', 'created_by' => 1],
        ]);

        DB::table('permissions')->insert([
            ['id' => 1, 'name' => 'manage treasury cash sessions', 'guard_name' => 'web'],
            ['id' => 2, 'name' => 'approve treasury cash variances', 'guard_name' => 'web'],
        ]);
        DB::table('model_has_permissions')->insert([
            ['permission_id' => 1, 'model_type' => 'App\\Models\\User', 'model_id' => 1],
            ['permission_id' => 1, 'model_type' => 'App\\Models\\User', 'model_id' => 3],
            ['permission_id' => 2, 'model_type' => 'App\\Models\\User', 'model_id' => 3],
        ]);

        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'code' => 121, 'name' => 'Bank register', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 2, 'code' => 122, 'name' => 'Bank central', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 3, 'code' => 684, 'name' => 'Cash shortage', 'type' => 6, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 4, 'code' => 764, 'name' => 'Cash surplus', 'type' => 7, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 5, 'code' => 61, 'name' => 'Expenses', 'type' => 6, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 6, 'code' => 123, 'name' => 'Transit', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
        ]);

        $this->accounts = new TreasuryAccountService();
        $this->service = new TreasuryCashSessionService($this->accounts);
        $this->register = $this->makeAccount('10000.0000', 'REG-001', 'MZN', 'cash_register', 1);
        $this->central = $this->makeAccount('50000.0000', 'BIM-002', 'MZN', 'bank', 2);
    }

    protected function tearDown(): void
    {
        foreach (['treasury_cash_counts', 'treasury_cash_movements', 'treasury_cash_sessions', 'treasury_transfers', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'pos_shifts', 'dimension_audit_events', 'bank_accounts', 'chart_of_accounts', 'branches', 'settings', 'users', 'permissions', 'model_has_permissions', 'journal_entries', 'journal_items', 'transaction_lines'] as $table) {
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

    protected function openSession(array $overrides = [], int $actorId = 1): TreasuryCashSession
    {
        return $this->service->openSession(1, array_merge([
            'register_id' => $this->register->id,
            'operator_user_id' => 2,
            'opening_float' => '10000',
        ], $overrides), $actorId);
    }

    /** Session with expected 10,300 (float 10,000 + inflow 500 − outflow 200). */
    protected function sessionWithMovements(): TreasuryCashSession
    {
        $session = $this->openSession();
        $this->service->recordMovement(1, $session->id, ['type' => 'inflow', 'amount' => '500'], 2);
        $this->service->recordMovement(1, $session->id, ['type' => 'outflow', 'amount' => '200'], 2);

        return $session->fresh();
    }

    protected function countTo(TreasuryCashSession $session, string $total): TreasuryCashSession
    {
        // Build a denomination count that sums exactly to $total.
        $remaining = (float) $total;
        $counts = [];
        foreach ([1000, 500, 200, 100, 50, 20, 10, 5, 2, 1] as $denomination) {
            $n = (int) floor($remaining / $denomination);
            if ($n > 0) {
                $counts[] = ['denomination' => (string) $denomination, 'count' => $n];
                $remaining -= $n * $denomination;
            }
        }
        $this->assertSame(0.0, $remaining, 'Test helper cannot express ' . $total . ' in MZN denominations.');

        return $this->service->recordCount(1, $session->id, $counts, 2);
    }

    protected function closeWithVariance(TreasuryCashSession $session, array $options = []): TreasuryCashSession
    {
        return $this->service->closeSession(1, $session->id, 1, array_merge([
            'variance_reason' => 'Till short after the lunch rush.',
            'variance_approved_by' => 3,
        ], $options));
    }

    protected function journalItems(int $journalId)
    {
        return DB::table('journal_items')->where('journal', $journalId)->orderBy('id')->get();
    }

    protected function makeClosedShift(array $overrides = []): int
    {
        return DB::table('pos_shifts')->insertGetId(array_merge([
            'branch_id' => 1, 'user_id' => 2, 'opened_at' => now()->subHours(8), 'closed_at' => now(),
            'opening_balance' => 0, 'closing_balance' => 0,
            'cash_total' => 2000, 'card_total' => 500, 'credit_total' => 300,
            'difference' => 0, 'tips' => 0, 'notes' => null, 'sales_count' => 10, 'sales_total' => 2800,
            'report_warehouse_ids' => null, 'status' => 'closed', 'operational_shift' => 'morning',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    // ------------------------------------------------------------------
    // Open
    // ------------------------------------------------------------------

    public function test_open_session_happy_path(): void
    {
        $session = $this->openSession();

        $this->assertTrue($session->isOpen());
        $this->assertSame('10000.0000', (string) $session->opening_float);
        $this->assertSame('10000.0000', (string) $session->expected_balance);
        $this->assertSame('0.0000', (string) $session->opening_float_difference);
        $this->assertSame('MZN', $session->currency);
        $this->assertSame(2, (int) $session->operator_user_id);
        $this->assertNotNull($session->opened_at);
    }

    public function test_open_session_float_differs_from_register_balance_is_flagged_not_corrected(): void
    {
        $session = $this->openSession(['opening_float' => '9500']);

        $this->assertSame('9500.0000', (string) $session->opening_float);
        $this->assertSame('-500.0000', (string) $session->opening_float_difference);
        $this->assertSame('9500.0000', (string) $session->expected_balance);
        // The register balance itself is untouched.
        $this->assertSame('10000.0000', $this->accounts->postedBalance($this->register->fresh()));
    }

    public function test_open_second_session_on_same_register_is_refused(): void
    {
        $this->openSession();

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('already has an open session');

        $this->openSession();
    }

    public function test_open_session_on_bank_account_is_refused(): void
    {
        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('cash register');

        $this->openSession(['register_id' => $this->central->id]);
    }

    public function test_open_session_with_operator_from_another_company_is_refused(): void
    {
        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('does not belong to this company');

        $this->openSession(['operator_user_id' => 4]);
    }

    // ------------------------------------------------------------------
    // Movements + expected balance
    // ------------------------------------------------------------------

    public function test_movements_update_expected_balance_and_card_mobile_stay_separate(): void
    {
        $session = $this->openSession();
        $this->service->recordMovement(1, $session->id, ['type' => 'inflow', 'amount' => '500'], 2);
        $this->service->recordMovement(1, $session->id, ['type' => 'outflow', 'amount' => '200'], 2);
        $this->service->recordMovement(1, $session->id, ['type' => 'inflow', 'method' => 'card', 'amount' => '300'], 2);
        $this->service->recordMovement(1, $session->id, ['type' => 'inflow', 'method' => 'mobile', 'amount' => '100'], 2);

        $session = $session->fresh();

        $this->assertSame('10300.0000', (string) $session->expected_balance);
        $this->assertSame('300.0000', (string) $session->card_total);
        $this->assertSame('100.0000', (string) $session->mobile_total);
        // Only the cash movements moved the register's posted balance.
        $this->assertSame('10300.0000', $this->accounts->postedBalance($this->register->fresh()));
        $this->assertSame(4, $session->movements()->count());
    }

    public function test_cash_movements_write_entries_but_no_journal(): void
    {
        $session = $this->sessionWithMovements();

        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->assertSame(2, DB::table('treasury_entries')->where('financial_account_id', $this->register->id)->count());
        $this->assertNotNull($session->operation_id);
    }

    public function test_record_movement_handover_type_is_refused(): void
    {
        $session = $this->openSession();

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('handover');

        $this->service->recordMovement(1, $session->id, ['type' => 'handover', 'amount' => '100'], 2);
    }

    public function test_record_movement_on_closed_session_is_refused(): void
    {
        $session = $this->openSession();
        $this->countTo($session, '10000');
        $this->service->closeSession(1, $session->id, 1);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('open session');

        $this->service->recordMovement(1, $session->id, ['type' => 'inflow', 'amount' => '100'], 2);
    }

    // ------------------------------------------------------------------
    // Count → variance (CA09)
    // ------------------------------------------------------------------

    public function test_count_shortage_computes_variance_and_preserves_count(): void
    {
        $session = $this->sessionWithMovements();

        $session = $this->countTo($session, '10250');

        $this->assertSame('10250.0000', (string) $session->counted_balance);
        $this->assertSame('-50.0000', (string) $session->variance_amount);
        $this->assertSame('10300.0000', (string) $session->expected_balance);
        $this->assertSame(3, $session->counts()->count());
    }

    public function test_count_surplus_computes_variance(): void
    {
        $session = $this->sessionWithMovements();

        $session = $this->countTo($session, '10350');

        $this->assertSame('10350.0000', (string) $session->counted_balance);
        $this->assertSame('50.0000', (string) $session->variance_amount);
    }

    public function test_count_replaces_draft_rows_and_recomputes_variance(): void
    {
        $session = $this->sessionWithMovements();
        $this->countTo($session, '10250');

        $session = $this->countTo($session, '10300');

        $this->assertSame('10300.0000', (string) $session->counted_balance);
        $this->assertSame('0.0000', (string) $session->variance_amount);
        $this->assertSame(3, $session->counts()->count());
        $this->assertSame(2, DB::table('dimension_audit_events')->where('entity_type', 'treasury_cash_session')->where('action', 'count_recorded')->count());
    }

    public function test_count_on_closed_session_is_refused(): void
    {
        $session = $this->openSession();
        $this->countTo($session, '10000');
        $this->service->closeSession(1, $session->id, 1);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('open session');

        $this->countTo($session, '10000');
    }

    // ------------------------------------------------------------------
    // Close + variance approval gate
    // ------------------------------------------------------------------

    public function test_close_without_count_is_refused(): void
    {
        $session = $this->openSession();

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('Record the denomination count');

        $this->service->closeSession(1, $session->id, 1);
    }

    public function test_close_with_unapproved_variance_is_refused(): void
    {
        $session = $this->sessionWithMovements();
        $this->countTo($session, '10250');

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('needs a reason');

        $this->service->closeSession(1, $session->id, 1);
    }

    public function test_close_with_variance_reason_but_no_approver_is_refused(): void
    {
        $session = $this->sessionWithMovements();
        $this->countTo($session, '10250');

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('needs approval');

        $this->service->closeSession(1, $session->id, 1, ['variance_reason' => 'Short.']);
    }

    public function test_close_with_operator_as_variance_approver_is_refused(): void
    {
        $session = $this->sessionWithMovements();
        $this->countTo($session, '10250');

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('session operator');

        $this->service->closeSession(1, $session->id, 1, [
            'variance_reason' => 'Short.', 'variance_approved_by' => 2,
        ]);
    }

    public function test_close_with_closer_as_variance_approver_is_refused(): void
    {
        $session = $this->sessionWithMovements();
        $this->countTo($session, '10250');

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('closing the session');

        $this->service->closeSession(1, $session->id, 1, [
            'variance_reason' => 'Short.', 'variance_approved_by' => 1,
        ]);
    }

    public function test_close_with_variance_approver_without_permission_is_refused(): void
    {
        $session = $this->sessionWithMovements();
        $this->countTo($session, '10250');

        // User 2 is the operator and holds no variance permission; use
        // user 4 (other company) as an approver without the permission.
        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('does not hold the approve treasury cash variances permission');

        $this->service->closeSession(1, $session->id, 1, [
            'variance_reason' => 'Short.', 'variance_approved_by' => 4,
        ]);
    }

    // ------------------------------------------------------------------
    // Close + variance adjustment as a SEPARATE journal (CA09)
    // ------------------------------------------------------------------

    public function test_close_with_shortage_posts_separate_variance_journal(): void
    {
        $session = $this->sessionWithMovements();
        $this->countTo($session, '10250');

        $closed = $this->closeWithVariance($session);

        $this->assertTrue($closed->isClosed());
        $this->assertNotNull($closed->closed_at);
        // The count and the variance are both preserved on the session.
        $this->assertSame('10250.0000', (string) $closed->counted_balance);
        $this->assertSame('-50.0000', (string) $closed->variance_amount);
        $this->assertSame('-50.0000', (string) $closed->variance_posted_amount);
        $this->assertSame('Till short after the lunch rush.', $closed->variance_reason);
        $this->assertSame(3, (int) $closed->variance_approved_by);
        $this->assertNotNull($closed->variance_approved_at);

        // One journal, separate from the session's own operation: the
        // adjustment operation reference carries the -ADJ- marker.
        $this->assertSame(1, DB::table('journal_entries')->count());
        $journal = DB::table('journal_entries')->first();
        $operation = DB::table('treasury_operations')->where('id', $closed->variance_operation_id)->first();
        $this->assertStringContainsString('-ADJ-', $operation->reference);
        $this->assertSame((int) $journal->id, (int) $closed->variance_journal_id);

        $items = $this->journalItems((int) $journal->id);
        $this->assertCount(2, $items);
        // Shortage: Dr variance expense 684 / Cr cash 121.
        $this->assertSame(3, (int) $items[0]->account);
        $this->assertSame(50.0, (float) $items[0]->debit);
        $this->assertSame(1, (int) $items[1]->account);
        $this->assertSame(50.0, (float) $items[1]->credit);

        // The register balance moved down by the shortage.
        $this->assertSame('10250.0000', $this->accounts->postedBalance($this->register->fresh()));
    }

    public function test_close_with_surplus_posts_variance_journal(): void
    {
        $session = $this->sessionWithMovements();
        $this->countTo($session, '10350');

        $closed = $this->closeWithVariance($session);

        $this->assertSame('50.0000', (string) $closed->variance_amount);
        $this->assertSame('50.0000', (string) $closed->variance_posted_amount);

        $journal = DB::table('journal_entries')->first();
        $items = $this->journalItems((int) $journal->id);
        $this->assertCount(2, $items);
        // Surplus: Dr cash 121 / Cr variance gain 764.
        $this->assertSame(1, (int) $items[0]->account);
        $this->assertSame(50.0, (float) $items[0]->debit);
        $this->assertSame(4, (int) $items[1]->account);
        $this->assertSame(50.0, (float) $items[1]->credit);

        $this->assertSame('10350.0000', $this->accounts->postedBalance($this->register->fresh()));
    }

    public function test_close_with_no_variance_posts_no_journal(): void
    {
        $session = $this->sessionWithMovements();
        $this->countTo($session, '10300');

        $closed = $this->service->closeSession(1, $session->id, 1);

        $this->assertTrue($closed->isClosed());
        $this->assertSame('0.0000', (string) $closed->variance_amount);
        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->assertNull($closed->variance_operation_id);
    }

    public function test_close_in_closed_period_is_blocked_without_adjustment(): void
    {
        $session = $this->sessionWithMovements();
        $this->countTo($session, '10250');

        DB::table('journal_entries')->insert([
            'date' => date('Y-m-d'), 'reference' => 'FY-' . date('Y') . '-CLOSE-001',
            'description' => 'Year-end close', 'journal_id' => 0, 'status' => 1,
            'accounting_period' => 0, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('is closed');

        $this->closeWithVariance($session);
    }

    public function test_close_in_closed_period_with_authorised_adjustment_closes(): void
    {
        $session = $this->sessionWithMovements();
        $this->countTo($session, '10250');

        DB::table('journal_entries')->insert([
            'date' => date('Y-m-d'), 'reference' => 'FY-' . date('Y') . '-CLOSE-001',
            'description' => 'Year-end close', 'journal_id' => 0, 'status' => 1,
            'accounting_period' => 0, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $closed = $this->closeWithVariance($session, [
            'adjustment' => ['authorised_by' => 3, 'reason' => 'Close after year-end, posted to today.'],
        ]);

        $this->assertTrue($closed->isClosed());
        $this->assertSame(1, DB::table('journal_entries')->where('reference', 'like', '%-ADJ-%')->count());
    }

    // ------------------------------------------------------------------
    // Reopen + lock
    // ------------------------------------------------------------------

    public function test_reopen_rules_require_reason_permission_and_non_operator(): void
    {
        $session = $this->openSession();
        $this->countTo($session, '10000');
        $closed = $this->service->closeSession(1, $session->id, 1);

        // No reason.
        try {
            $this->service->reopenSession(1, $closed->id, 1, '');
            $this->fail('Reopen without a reason should be refused.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('needs a reason', $e->getMessage());
        }

        // Operator cannot reopen their own session.
        try {
            $this->service->reopenSession(1, $closed->id, 2, 'Miscount suspected.');
            $this->fail('Operator reopen should be refused.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('operator cannot reopen', $e->getMessage());
        }

        $reopened = $this->service->reopenSession(1, $closed->id, 1, 'Miscount suspected.');

        $this->assertTrue($reopened->isOpen());
        $this->assertNull($reopened->closed_at);
        $this->assertSame('Miscount suspected.', $reopened->reopen_reason);
        $this->assertSame(1, (int) $reopened->reopened_by);
        $this->assertNotNull($reopened->reopened_at);
        // The count and the variance already posted stay as they are.
        $this->assertSame('10000.0000', (string) $reopened->counted_balance);
        $this->assertSame('0.0000', (string) $reopened->variance_amount);
        $this->assertSame(1, DB::table('dimension_audit_events')->where('entity_type', 'treasury_cash_session')->where('action', 'reopened')->count());
    }

    public function test_reopen_then_recount_posts_only_the_delta(): void
    {
        $session = $this->sessionWithMovements();
        $this->countTo($session, '10250');
        $closed = $this->closeWithVariance($session);
        $this->assertSame(1, DB::table('journal_entries')->count());

        $reopened = $this->service->reopenSession(1, $closed->id, 1, 'Recount after a till check.');
        // A sub-ledger-precision outflow moves the expected balance by 0.001.
        $this->service->recordMovement(1, $reopened->id, ['type' => 'outflow', 'amount' => '0.001'], 2);
        $this->countTo($reopened, '10250');

        $reclosed = $this->closeWithVariance($reopened);

        // Variance is now −49.999, but the delta (+0.001) rounds to
        // 0.00 — no second journal, and the variance is treated as posted.
        $this->assertSame('-49.9990', (string) $reclosed->variance_amount);
        $this->assertSame('-49.9990', (string) $reclosed->variance_posted_amount);
        $this->assertSame(1, DB::table('journal_entries')->count());
        $this->assertSame('10250.0000', (string) $reclosed->counted_balance);
    }

    public function test_lock_is_terminal(): void
    {
        $session = $this->openSession();
        $this->countTo($session, '10000');
        $closed = $this->service->closeSession(1, $session->id, 1);

        $locked = $this->service->lockSession(1, $closed->id, 1, 'Month-end pack filed.');

        $this->assertTrue($locked->isLocked());
        $this->assertSame('Month-end pack filed.', $locked->lock_reason);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('Only a closed session can be reopened');

        $this->service->reopenSession(1, $locked->id, 1, 'Try to reopen a locked session.');
    }

    // ------------------------------------------------------------------
    // Handover
    // ------------------------------------------------------------------

    public function test_handover_creates_a_linked_transfer(): void
    {
        $session = $this->openSession();

        $movement = $this->service->handover(1, $session->id, [
            'destination_account_id' => $this->central->id,
            'amount' => '5000',
            'reason' => 'End-of-day cash to the safe.',
        ], 2);

        $this->assertSame('handover', $movement->type);
        $this->assertNotNull($movement->transfer_id);
        $this->assertSame('5000.0000', (string) $movement->amount);

        $transfer = DB::table('treasury_transfers')->where('id', $movement->transfer_id)->first();
        $this->assertSame('confirmed', $transfer->state);
        $this->assertSame(0, bccomp((string) $transfer->amount_debited, '5000.0000', 4));

        // The money moved once: register down, central up, total unchanged.
        $this->assertSame('5000.0000', $this->accounts->postedBalance($this->register->fresh()));
        $this->assertSame('55000.0000', $this->accounts->postedBalance($this->central->fresh()));
        $this->assertSame('5000.0000', (string) $session->fresh()->expected_balance);
    }

    public function test_handover_more_than_cash_on_hand_is_refused(): void
    {
        $session = $this->openSession();

        $this->expectException(TreasuryException::class);

        $this->service->handover(1, $session->id, [
            'destination_account_id' => $this->central->id,
            'amount' => '20000',
        ], 2);
    }

    public function test_handover_to_a_transit_account_is_refused(): void
    {
        $transit = $this->makeAccount('0.0000', 'TRF-001', 'MZN', 'funds_in_transit', 6);
        $session = $this->openSession();

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('transit');

        $this->service->handover(1, $session->id, [
            'destination_account_id' => $transit->id,
            'amount' => '1000',
        ], 2);
    }

    // ------------------------------------------------------------------
    // POS shift feed
    // ------------------------------------------------------------------

    public function test_pos_shift_feeds_session_once(): void
    {
        $shiftId = $this->makeClosedShift();

        $session = $this->openSession(['shift_id' => $shiftId]);

        $this->assertSame('12000.0000', (string) $session->expected_balance);
        $this->assertSame('2000.0000', (string) $session->shift_cash_total);
        $this->assertSame('500.0000', (string) $session->shift_card_total);
        $this->assertSame('300.0000', (string) $session->shift_credit_total);
        // The shift's card takings live in the shift snapshot for
        // display; card_total only accumulates card movements
        // recorded on the session itself.
        $this->assertSame('0.0000', (string) $session->card_total);
        $this->assertSame(1, $session->movements()->where('type', 'inflow')->where('amount', '2000.0000')->count());
        $this->assertSame('12000.0000', $this->accounts->postedBalance($this->register->fresh()));

        // Close the first session, then try to feed the same shift
        // into a second session — the shift feeds once, ever.
        $this->countTo($session, '12000');
        $this->service->closeSession(1, $session->id, 1);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('already fed a cash session');

        $this->openSession(['shift_id' => $shiftId]);
    }

    public function test_open_session_with_open_shift_is_refused(): void
    {
        $shiftId = $this->makeClosedShift(['status' => 'open', 'closed_at' => null]);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('Only a closed POS shift');

        $this->openSession(['shift_id' => $shiftId]);
    }

    // ------------------------------------------------------------------
    // Company scope (CA16)
    // ------------------------------------------------------------------

    public function test_cross_company_access_is_denied(): void
    {
        $session = $this->openSession();

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('not found for this company');

        $this->service->sessionForCompany(4, $session->id);
    }

    public function test_open_session_on_another_companys_register_is_denied(): void
    {
        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessage('not found for this company');

        $this->service->openSession(4, [
            'register_id' => $this->register->id,
            'operator_user_id' => 2,
            'opening_float' => '10000',
        ], 4);
    }

    // ------------------------------------------------------------------
    // Schema (mirrors migrations 110000; trimmed to what Phase 5 needs)
    // ------------------------------------------------------------------

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

        Schema::create('treasury_cash_sessions', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('register_id');
            $table->bigInteger('operator_user_id');
            $table->bigInteger('shift_id')->nullable();
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_float', 20, 4)->default(0);
            $table->decimal('opening_float_difference', 20, 4)->default(0);
            $table->decimal('expected_balance', 20, 4)->default(0);
            $table->decimal('counted_balance', 20, 4)->nullable();
            $table->decimal('variance_amount', 20, 4)->nullable();
            $table->decimal('variance_posted_amount', 20, 4)->default(0);
            $table->text('variance_reason')->nullable();
            $table->bigInteger('variance_approved_by')->nullable();
            $table->timestamp('variance_approved_at')->nullable();
            $table->bigInteger('variance_operation_id')->nullable();
            $table->bigInteger('variance_journal_id')->nullable();
            $table->decimal('card_total', 20, 4)->default(0);
            $table->decimal('mobile_total', 20, 4)->default(0);
            $table->decimal('shift_cash_total', 20, 4)->nullable();
            $table->decimal('shift_card_total', 20, 4)->nullable();
            $table->decimal('shift_credit_total', 20, 4)->nullable();
            $table->decimal('shift_difference', 20, 4)->nullable();
            $table->decimal('shift_closing_balance', 20, 4)->nullable();
            $table->bigInteger('operation_id')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->bigInteger('reopened_by')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->text('lock_reason')->nullable();
            $table->bigInteger('locked_by')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->string('state', 20)->default('open');
            $table->timestamps();
            $table->unique(['shift_id'], 'tcs_shift_uq');
            $table->index(['created_by', 'state'], 'tcs_company_state_idx');
            $table->index(['register_id'], 'tcs_register_idx');
            $table->index(['operator_user_id'], 'tcs_operator_idx');
            $table->index(['operation_id'], 'tcs_operation_idx');
        });

        Schema::create('treasury_cash_counts', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('cash_session_id');
            $table->integer('created_by');
            $table->decimal('denomination', 20, 4);
            $table->integer('count')->default(0);
            $table->decimal('amount', 20, 4)->default(0);
            $table->timestamps();
            $table->index(['cash_session_id'], 'tcc_session_idx');
        });

        Schema::create('treasury_cash_movements', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('cash_session_id');
            $table->integer('created_by');
            $table->bigInteger('recorded_by')->nullable();
            $table->string('type', 20);
            $table->string('method', 20)->default('cash');
            $table->decimal('amount', 20, 4)->default(0);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->string('reference', 100)->nullable();
            $table->text('reason')->nullable();
            $table->bigInteger('transfer_id')->nullable();
            $table->bigInteger('entry_id')->nullable();
            $table->bigInteger('operation_id')->nullable();
            $table->timestamps();
            $table->index(['cash_session_id'], 'tcm_session_idx');
            $table->index(['transfer_id'], 'tcm_transfer_idx');
        });

        Schema::create('pos_shifts', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('user_id')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_balance', 20, 4)->default(0);
            $table->decimal('closing_balance', 20, 4)->default(0);
            $table->decimal('cash_total', 20, 4)->default(0);
            $table->decimal('card_total', 20, 4)->default(0);
            $table->decimal('credit_total', 20, 4)->default(0);
            $table->decimal('difference', 20, 4)->default(0);
            $table->decimal('tips', 20, 4)->default(0);
            $table->text('notes')->nullable();
            $table->integer('sales_count')->default(0);
            $table->decimal('sales_total', 20, 4)->default(0);
            $table->text('report_warehouse_ids')->nullable();
            $table->string('status', 20)->default('open');
            $table->string('operational_shift', 20)->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
    }
}
