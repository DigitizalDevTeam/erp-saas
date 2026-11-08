<?php

namespace Tests\Unit;

use App\Exceptions\TreasuryException;
use App\Models\BankReconciliationMatch;
use App\Models\BankReconciliationSession;
use App\Models\BankStatementLine;
use App\Models\JournalItem;
use App\Models\TreasuryEntry;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryMissingMovement;
use App\Models\TreasuryOperation;
use App\Services\TreasuryReconciliationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Treasury Phase 7 — reconciliation hardening (plan §5, §6, §7, §10).
 *
 * Exit gates under test: CA11 (duplicate-file blocking, overlapping
 * imports keep legitimate lines), CA12 (partial / one-to-many /
 * many-to-one matches stay within statement and operation residuals;
 * over-allocation refused), close math (statement = book +
 * outstanding, within 0.01), reopen (authorisation + reason),
 * missing movements (propose → confirm posts exactly one journal,
 * never twice from the same line), API sync cursor resume.
 *
 * Users: 1 = Company, 2 = Operator (manage only), 3 = Approver
 * (manage + approve), 4 = Other Company, 5 = Other Employee.
 * The bank account is BIM-001 with a Treasury financial account and
 * an approved 200,000 opening balance; chart accounts are 121 (bank),
 * 62 (bank fees), 76 (interest income).
 */
class TreasuryReconciliationServiceTest extends TestCase
{
    protected TreasuryReconciliationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Fixed Assets Phase 0 note: this class hard-codes statement
        // dates of 2026-11-05 and the auto-match rule compares them
        // with the entry's confirmed_at (= now()) inside a 3-day
        // tolerance. Without a frozen clock the residual auto-match
        // test starts failing once "today" drifts past 2026-11-08
        // (it failed on clean main 59f712f on 2026-11-08). Freeze
        // "today" to 2026-11-07, the date Phase 7 verified on.
        Carbon::setTestNow(Carbon::parse('2026-11-07 09:00:00'));

        $this->createTables();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Operator', 'email' => 'operator@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 5, 'name' => 'Other Employee', 'email' => 'otheremployee@test.local', 'type' => 'employee', 'created_by' => 4, 'default_company_id' => 4, 'email_verified_at' => now()],
        ]);

        DB::table('permissions')->insert([
            ['id' => 1, 'name' => 'manage treasury reconciliation', 'guard_name' => 'web'],
            ['id' => 2, 'name' => 'approve treasury reconciliation', 'guard_name' => 'web'],
        ]);
        DB::table('model_has_permissions')->insert([
            ['permission_id' => 1, 'model_type' => 'App\\Models\\User', 'model_id' => 2],
            ['permission_id' => 1, 'model_type' => 'App\\Models\\User', 'model_id' => 3],
            ['permission_id' => 2, 'model_type' => 'App\\Models\\User', 'model_id' => 3],
        ]);

        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'code' => 121, 'name' => 'Bank', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 2, 'code' => 62, 'name' => 'Bank fees', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 3, 'code' => 76, 'name' => 'Interest income', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
        ]);

        $this->service = new TreasuryReconciliationService();

        $this->makeBankAccount(1, 1);
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
        Carbon::setTestNow();

        foreach (['treasury_statement_sync_cursors', 'treasury_missing_movements', 'bank_reconciliation_matches', 'bank_statement_lines', 'bank_reconciliation_sessions', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'dimension_audit_events', 'journal_items', 'journal_entries', 'transaction_lines', 'transactions', 'bank_accounts', 'chart_of_accounts', 'settings', 'users', 'permissions', 'model_has_permissions', 'role_has_permissions', 'model_has_roles'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // CA11 — duplicate files + overlapping imports
    // ------------------------------------------------------------------

    public function test_duplicate_file_hash_is_blocked(): void
    {
        $this->makeSession(['file_hash' => 'abc123']);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessageMatches('/already imported/');
        $this->service->assertFileHashAvailable(1, 1, 'abc123');
    }

    public function test_same_hash_allowed_for_another_bank_or_after_excluding_the_session(): void
    {
        $session = $this->makeSession(['file_hash' => 'abc123']);
        $this->makeBankAccount(2, 1);

        // Same hash, different bank account: allowed.
        $this->service->assertFileHashAvailable(1, 2, 'abc123');
        // Same hash, same bank, but the session itself is excluded: allowed.
        $this->service->assertFileHashAvailable(1, 1, 'abc123', $session->id);
        // A different hash on the same bank: allowed.
        $this->service->assertFileHashAvailable(1, 1, 'def456');

        $this->assertTrue(true);
    }

    public function test_overlapping_import_keeps_legitimate_lines(): void
    {
        $first = $this->makeSession();
        $this->makeLine($first, 1, '2026-11-05', 'Transfer in', '5000.0000', '205000.0000');

        $second = $this->makeSession();
        // Same line again (same date, amount, description) + one new line.
        $this->makeLine($second, 1, '2026-11-05', 'Transfer in', '5000.0000', '205000.0000');
        $this->makeLine($second, 2, '2026-11-06', 'Bank fee', '-50.0000', '204950.0000');
        // A line without an amount cannot be compared and is always kept.
        $this->makeLine($second, 3, '2026-11-06', 'No amount yet', null, null);

        $result = $this->service->dedupeSessionLines($second);

        $this->assertSame(['removed' => 1, 'kept' => 2], $result);
        $this->assertSame(2, BankStatementLine::where('session_id', $second->id)->count());
        $this->assertSame(1, BankStatementLine::where('session_id', $first->id)->count());
        $this->assertSame('Bank fee', BankStatementLine::where('session_id', $second->id)->orderBy('id')->first()->description);
    }

    // ------------------------------------------------------------------
    // CA12 — matches to Treasury operations, with residuals
    // ------------------------------------------------------------------

    public function test_match_to_treasury_operation_stores_link_and_amount(): void
    {
        $session = $this->makeSession();
        $line = $this->makeLine($session, 1, '2026-11-05', 'Transfer in', '10000.0000', '210000.0000');
        $operation = $this->makeOperation('10000.0000', 'COMP-RCT-2026-0001');

        $match = $this->service->matchToTreasuryOperation(1, $session->id, $line->id, $operation->id, 2);

        $this->assertSame($operation->id, (int) $match->treasury_operation_id);
        $this->assertSame('10000.0000', (string) $match->matched_amount);
        $this->assertSame('manual', $match->match_type);
        $this->assertSame('0.0000', $this->service->lineResidual($line->fresh()));
        $this->assertSame('0.0000', $this->service->operationResidual($operation, $session));
        $this->assertTrue($match->isTreasuryMatch());
    }

    public function test_partial_match_leaves_remainder(): void
    {
        $session = $this->makeSession();
        $line = $this->makeLine($session, 1, '2026-11-05', 'Transfer in', '10000.0000', '210000.0000');
        $operation = $this->makeOperation('10000.0000', 'COMP-RCT-2026-0001');

        $this->service->matchToTreasuryOperation(1, $session->id, $line->id, $operation->id, 2, '4000');

        $this->assertSame('6000.0000', $this->service->lineResidual($line->fresh()));
        $this->assertSame('6000.0000', $this->service->operationResidual($operation, $session));
    }

    public function test_one_to_many_matches_stay_within_residuals(): void
    {
        $session = $this->makeSession();
        $line = $this->makeLine($session, 1, '2026-11-05', 'Transfer in', '10000.0000', '210000.0000');
        $opA = $this->makeOperation('6000.0000', 'COMP-RCT-2026-0001');
        $opB = $this->makeOperation('4000.0000', 'COMP-RCT-2026-0002');

        $this->service->matchToTreasuryOperation(1, $session->id, $line->id, $opA->id, 2, '6000');
        $this->service->matchToTreasuryOperation(1, $session->id, $line->id, $opB->id, 2, '4000');

        $this->assertSame('0.0000', $this->service->lineResidual($line->fresh()));
        $this->assertSame(2, BankReconciliationMatch::where('statement_line_id', $line->id)->count());

        $opC = $this->makeOperation('1000.0000', 'COMP-RCT-2026-0003');
        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessageMatches('/exceeds the statement line residual/');
        $this->service->matchToTreasuryOperation(1, $session->id, $line->id, $opC->id, 2, '1000');
    }

    public function test_many_to_one_matches_stay_within_residuals(): void
    {
        $session = $this->makeSession();
        $line1 = $this->makeLine($session, 1, '2026-11-05', 'Transfer in (1)', '6000.0000', '206000.0000');
        $line2 = $this->makeLine($session, 2, '2026-11-06', 'Transfer in (2)', '4000.0000', '210000.0000');
        $line3 = $this->makeLine($session, 3, '2026-11-07', 'Transfer in (3)', '1000.0000', '211000.0000');
        $operation = $this->makeOperation('10000.0000', 'COMP-RCT-2026-0001');

        $this->service->matchToTreasuryOperation(1, $session->id, $line1->id, $operation->id, 2, '6000');
        $this->service->matchToTreasuryOperation(1, $session->id, $line2->id, $operation->id, 2, '4000');

        $this->assertSame('0.0000', $this->service->operationResidual($operation, $session));

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessageMatches('/exceeds the operation residual/');
        $this->service->matchToTreasuryOperation(1, $session->id, $line3->id, $operation->id, 2, '1000');
    }

    public function test_over_allocation_is_refused_on_either_side(): void
    {
        $session = $this->makeSession();
        $smallLine = $this->makeLine($session, 1, '2026-11-05', 'Small', '5000.0000', '205000.0000');
        $bigOperation = $this->makeOperation('10000.0000', 'COMP-RCT-2026-0001');

        try {
            $this->service->matchToTreasuryOperation(1, $session->id, $smallLine->id, $bigOperation->id, 2, '6000');
            $this->fail('Matching more than the line residual must be refused.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('statement line residual', $e->getMessage());
        }

        $bigLine = $this->makeLine($session, 2, '2026-11-06', 'Big', '10000.0000', '215000.0000');
        $smallOperation = $this->makeOperation('5000.0000', 'COMP-RCT-2026-0002');

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessageMatches('/exceeds the operation residual/');
        $this->service->matchToTreasuryOperation(1, $session->id, $bigLine->id, $smallOperation->id, 2, '6000');
    }

    public function test_match_refuses_unconfirmed_foreign_or_accountless_operations(): void
    {
        $session = $this->makeSession();
        $line = $this->makeLine($session, 1, '2026-11-05', 'Transfer in', '10000.0000', '210000.0000');

        $draft = $this->makeOperation('10000.0000', 'COMP-RCT-2026-0001', 'draft');
        try {
            $this->service->matchToTreasuryOperation(1, $session->id, $line->id, $draft->id, 2);
            $this->fail('A draft operation must not match.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('confirmed Treasury operation', $e->getMessage());
        }

        // Operation of another company.
        $foreign = TreasuryOperation::create([
            'reference' => 'OTHR-RCT-2026-0001', 'type' => 'receipt', 'company_id' => 4,
            'financial_account_id' => 1, 'currency' => 'MZN', 'amount_original' => '10000.0000',
            'exchange_rate' => '1.0000000000', 'amount_base' => '10000.0000',
            'posting_date' => '2026-11-05', 'effective_date' => '2026-11-05', 'value_date' => '2026-11-05',
            'state' => 'confirmed', 'created_by' => 4,
        ]);
        TreasuryEntry::create([
            'operation_id' => $foreign->id, 'financial_account_id' => 1, 'direction' => 'inflow',
            'amount_original' => '10000.0000', 'amount_base' => '10000.0000',
            'exchange_rate' => '1.0000000000', 'confirmed_at' => now(), 'created_by' => 4,
        ]);
        try {
            $this->service->matchToTreasuryOperation(1, $session->id, $line->id, $foreign->id, 2);
            $this->fail('Another company operation must not match.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('confirmed Treasury operation', $e->getMessage());
        }

        // Confirmed operation with no entry on this bank account.
        $noEntry = TreasuryOperation::create([
            'reference' => 'COMP-RCT-2026-0009', 'type' => 'receipt', 'company_id' => 1,
            'financial_account_id' => 1, 'currency' => 'MZN', 'amount_original' => '10000.0000',
            'exchange_rate' => '1.0000000000', 'amount_base' => '10000.0000',
            'posting_date' => '2026-11-05', 'effective_date' => '2026-11-05', 'value_date' => '2026-11-05',
            'state' => 'confirmed', 'created_by' => 1,
        ]);
        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessageMatches('/no movement on the session bank account/');
        $this->service->matchToTreasuryOperation(1, $session->id, $line->id, $noEntry->id, 2);
    }

    public function test_unmatch_restores_residuals(): void
    {
        $session = $this->makeSession();
        $line = $this->makeLine($session, 1, '2026-11-05', 'Transfer in', '10000.0000', '210000.0000');
        $operation = $this->makeOperation('10000.0000', 'COMP-RCT-2026-0001');

        $this->service->matchToTreasuryOperation(1, $session->id, $line->id, $operation->id, 2, '4000');
        $removed = $this->service->unmatchTreasuryOperation(1, $session->id, $line->id, 2);

        $this->assertSame(1, $removed);
        $this->assertSame('10000.0000', $this->service->lineResidual($line->fresh()));
        $this->assertSame('10000.0000', $this->service->operationResidual($operation, $session));
    }

    public function test_auto_match_does_not_rematch_operation_from_another_session(): void
    {
        $operation = $this->makeOperation('10000.0000', 'COMP-RCT-2026-0001');

        $first = $this->makeSession();
        $line1 = $this->makeLine($first, 1, '2026-11-05', 'Transfer in', '10000.0000', '210000.0000');
        $this->service->matchToTreasuryOperation(1, $first->id, $line1->id, $operation->id, 2);

        // A second statement carries the same movement again: the
        // auto-match must not match the already-used operation.
        $second = $this->makeSession();
        $line2 = $this->makeLine($second, 1, '2026-11-05', 'Transfer in', '10000.0000', '210000.0000');

        $result = (new \App\Services\Accounting\BankReconciliation\ReconciliationMatcher())->runAutoMatch($second->fresh());

        $this->assertSame(0, $result['auto_matched']);
        $this->assertSame(0, BankReconciliationMatch::where('session_id', $second->id)->count());
        $this->assertSame('10000.0000', $this->service->lineResidual($line2->fresh()));
    }

    public function test_auto_match_matches_the_operation_residual_not_its_full_amount(): void
    {
        $operation = $this->makeOperation('10000.0000', 'COMP-RCT-2026-0001');

        $first = $this->makeSession();
        $line1 = $this->makeLine($first, 1, '2026-11-05', 'Transfer in', '10000.0000', '210000.0000');
        $this->service->matchToTreasuryOperation(1, $first->id, $line1->id, $operation->id, 2, '4000');

        // A 10,000 line must NOT auto-match: only 6,000 is left.
        $second = $this->makeSession();
        $line2 = $this->makeLine($second, 1, '2026-11-05', 'Transfer in', '10000.0000', '210000.0000');
        $matcher = new \App\Services\Accounting\BankReconciliation\ReconciliationMatcher();
        $this->assertSame(0, $matcher->runAutoMatch($second->fresh())['auto_matched']);
        $this->assertSame(0, BankReconciliationMatch::where('session_id', $second->id)->count());

        // A 6,000 line matches the residual exactly.
        $third = $this->makeSession();
        $line3 = $this->makeLine($third, 1, '2026-11-05', 'Transfer in', '6000.0000', '206000.0000');
        $this->assertSame(1, $matcher->runAutoMatch($third->fresh())['auto_matched']);
        $match = BankReconciliationMatch::where('session_id', $third->id)->firstOrFail();
        $this->assertSame($operation->id, (int) $match->treasury_operation_id);
        $this->assertSame('6000.0000', (string) $match->matched_amount);
        $this->assertSame('auto', $match->match_type);
    }

    public function test_legacy_transaction_match_counts_as_full_line(): void
    {
        $session = $this->makeSession();
        $line = $this->makeLine($session, 1, '2026-11-05', 'Legacy payment', '7500.0000', '207500.0000');
        $txId = DB::table('transactions')->insertGetId([
            'account' => 1, 'type' => 'Payment', 'amount' => '7500.0000',
            'description' => 'Legacy', 'date' => '2026-11-05', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        BankReconciliationMatch::create([
            'session_id' => $session->id, 'statement_line_id' => $line->id,
            'transaction_id' => $txId, 'matched_amount' => null, 'match_type' => 'manual',
        ]);

        $this->assertSame('0.0000', $this->service->lineResidual($line->fresh()));
    }

    // ------------------------------------------------------------------
    // Close / reopen
    // ------------------------------------------------------------------

    public function test_close_math_reconciles_with_defaults(): void
    {
        $session = $this->makeCloseSession();

        $summary = $this->service->sessionSummary($session);
        $this->assertSame('209950.0000', $summary['statement_balance']);
        $this->assertSame('210000.0000', $summary['book_balance']);
        $this->assertSame('-50.0000', $summary['outstanding_items']);
        $this->assertSame('0.0000', $summary['explained_differences']);
        $this->assertSame('0.0000', $summary['difference']);
        $this->assertSame(1, $summary['unmatched_lines']);

        $result = $this->service->closeSession(1, $session->id, 3);

        $closed = $result['session'];
        $this->assertTrue($closed->isClosed());
        $this->assertSame('209950.0000', (string) $closed->statement_balance);
        $this->assertSame('210000.0000', (string) $closed->book_balance);
        $this->assertSame('-50.0000', (string) $closed->outstanding_items);
        $this->assertSame(3, (int) $closed->closed_by);
        $this->assertNotNull($closed->closed_at);
    }

    public function test_close_is_refused_when_the_difference_is_not_zero(): void
    {
        $session = $this->makeCloseSession();

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessageMatches('/does not reconcile/');
        $this->service->closeSession(1, $session->id, 3, ['book_balance' => '209000']);
    }

    public function test_close_needs_the_approve_permission(): void
    {
        $session = $this->makeCloseSession();

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessageMatches('/approve treasury reconciliation/');
        // User 2 holds manage only — no approve permission.
        $this->service->closeSession(1, $session->id, 2);
    }

    public function test_close_with_explained_missing_movement_reconciles(): void
    {
        $session = $this->makeCloseSession();
        $feeLine = BankStatementLine::where('session_id', $session->id)->where('description', 'Bank fee')->firstOrFail();

        $movement = $this->service->proposeMissingMovement(1, $session->id, $feeLine->id, 2, [
            'category' => 'fee', 'offset_account_id' => 2,
        ]);
        $this->service->confirmMissingMovement(1, $movement->id, 3);

        $summary = $this->service->sessionSummary($session->fresh());
        // Fee is matched now, so outstanding is 0; the book picked up
        // the confirmed fee (210,000 − 50) and the statement is 209,950.
        $this->assertSame('209950.0000', $summary['statement_balance']);
        $this->assertSame('209950.0000', $summary['book_balance']);
        $this->assertSame('0.0000', $summary['outstanding_items']);
        $this->assertSame('-50.0000', $summary['explained_differences']);
        $this->assertSame('0.0000', $summary['difference']);

        $result = $this->service->closeSession(1, $session->id, 3);
        $this->assertSame('-50.0000', (string) $result['session']->explained_differences);
    }

    public function test_reopen_needs_authorisation_and_a_reason(): void
    {
        $session = $this->makeCloseSession();
        $this->service->closeSession(1, $session->id, 3);

        try {
            $this->service->reopenSession(1, $session->id, 2, 'Bank sent a corrected file.');
            $this->fail('Reopen without the approve permission must be refused.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('approve treasury reconciliation', $e->getMessage());
        }

        try {
            $this->service->reopenSession(1, $session->id, 3, '   ');
            $this->fail('Reopen without a reason must be refused.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('reason is required', $e->getMessage());
        }

        $reopened = $this->service->reopenSession(1, $session->id, 3, 'Bank sent a corrected file.');
        $this->assertFalse($reopened->isClosed());
        $this->assertSame('Bank sent a corrected file.', $reopened->reopen_reason);
        $this->assertNotNull($reopened->reopened_at);
        $this->assertNull($reopened->closed_at);
        $this->assertNull($reopened->closed_by);
    }

    public function test_closed_session_refuses_new_matches_and_movements(): void
    {
        $session = $this->makeCloseSession();
        $this->service->closeSession(1, $session->id, 3);
        $feeLine = BankStatementLine::where('session_id', $session->id)->where('description', 'Bank fee')->firstOrFail();

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessageMatches('/closed/');
        $this->service->proposeMissingMovement(1, $session->id, $feeLine->id, 2, [
            'category' => 'fee', 'offset_account_id' => 2,
        ]);
    }

    // ------------------------------------------------------------------
    // Missing movements
    // ------------------------------------------------------------------

    public function test_missing_movement_fee_posts_exactly_one_journal(): void
    {
        $session = $this->makeSession();
        $line = $this->makeLine($session, 1, '2026-11-06', 'Bank fee', '-50.0000', '199950.0000');

        $movement = $this->service->proposeMissingMovement(1, $session->id, $line->id, 2, [
            'category' => 'fee', 'offset_account_id' => 2, 'reason' => 'Monthly account fee',
        ]);
        $this->assertSame('proposed', $movement->state);
        $this->assertSame('-50.0000', (string) $movement->amount_original);
        $this->assertSame('-50.0000', (string) $movement->amount_base);

        $result = $this->service->confirmMissingMovement(1, $movement->id, 3);

        $this->assertSame('confirmed', $result['movement']->state);
        $this->assertSame('COMP-ADJ-' . date('Y') . '-0001', $result['operation']->reference);
        $this->assertSame('outflow', $result['entry']->direction);

        // Exactly one journal, Dr fee 50 / Cr bank 50.
        $this->assertSame(1, DB::table('journal_entries')->count());
        $items = JournalItem::where('journal', $result['journal']->id)->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertSame(2, (int) $items[0]->account);
        $this->assertSame('50.00', (string) $items[0]->debit);
        $this->assertSame(1, (int) $items[1]->account);
        $this->assertSame('50.00', (string) $items[1]->credit);

        // The line is now explained by a missing-movement match.
        $match = BankReconciliationMatch::where('statement_line_id', $line->id)->firstOrFail();
        $this->assertSame('missing_movement', $match->match_type);
        $this->assertSame('50.0000', (string) $match->matched_amount);
        $this->assertSame('0.0000', $this->service->lineResidual($line->fresh()));

        // Confirming again is refused — one journal, ever.
        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessageMatches('/Only a proposed missing movement can be confirmed/');
        $this->service->confirmMissingMovement(1, $movement->id, 3);
    }

    public function test_missing_movement_interest_posts_money_in(): void
    {
        $session = $this->makeSession();
        $line = $this->makeLine($session, 1, '2026-11-06', 'Interest', '25.0000', '200025.0000');

        $movement = $this->service->proposeMissingMovement(1, $session->id, $line->id, 2, [
            'category' => 'interest', 'offset_account_id' => 3,
        ]);
        $result = $this->service->confirmMissingMovement(1, $movement->id, 3);

        $this->assertSame('inflow', $result['entry']->direction);
        $items = JournalItem::where('journal', $result['journal']->id)->orderBy('id')->get();
        $this->assertSame(1, (int) $items[0]->account); // Dr bank 25
        $this->assertSame('25.00', (string) $items[0]->debit);
        $this->assertSame(3, (int) $items[1]->account); // Cr interest 25
        $this->assertSame('25.00', (string) $items[1]->credit);
    }

    public function test_missing_movement_cannot_be_proposed_twice_for_one_line(): void
    {
        $session = $this->makeSession();
        $line = $this->makeLine($session, 1, '2026-11-06', 'Bank fee', '-50.0000', '199950.0000');

        $this->service->proposeMissingMovement(1, $session->id, $line->id, 2, [
            'category' => 'fee', 'offset_account_id' => 2,
        ]);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessageMatches('/already has a live missing-movement proposal/');
        $this->service->proposeMissingMovement(1, $session->id, $line->id, 2, [
            'category' => 'fee', 'offset_account_id' => 2,
        ]);
    }

    public function test_missing_movement_reject_then_repropose_is_allowed(): void
    {
        $session = $this->makeSession();
        $line = $this->makeLine($session, 1, '2026-11-06', 'Bank fee', '-50.0000', '199950.0000');

        $first = $this->service->proposeMissingMovement(1, $session->id, $line->id, 2, [
            'category' => 'fee', 'offset_account_id' => 2,
        ]);
        $rejected = $this->service->rejectMissingMovement(1, $first->id, 2, 'Wrong account.');
        $this->assertSame('rejected', $rejected->state);

        $second = $this->service->proposeMissingMovement(1, $session->id, $line->id, 2, [
            'category' => 'fee', 'offset_account_id' => 2,
        ]);
        $this->assertSame('proposed', $second->state);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(0, DB::table('journal_entries')->count());
    }

    public function test_missing_movement_reverse_posts_the_opposite_journal(): void
    {
        $session = $this->makeSession();
        $line = $this->makeLine($session, 1, '2026-11-06', 'Bank fee', '-50.0000', '199950.0000');

        $movement = $this->service->proposeMissingMovement(1, $session->id, $line->id, 2, [
            'category' => 'fee', 'offset_account_id' => 2,
        ]);
        $confirmed = $this->service->confirmMissingMovement(1, $movement->id, 3);

        $reversed = $this->service->reverseMissingMovement(1, $movement->id, 3, ['reason' => 'Fee refunded by the bank.']);

        $this->assertSame('reversed', $reversed['movement']->state);
        $this->assertSame($movement->id, (int) $reversed['movement']->reversal_of_id);
        $this->assertNotSame($confirmed['journal']->id, $reversed['journal']->id);
        $this->assertSame(2, DB::table('journal_entries')->count());

        // Reversal journal: Dr bank 50 / Cr fee 50 (the opposite).
        $items = JournalItem::where('journal', $reversed['journal']->id)->orderBy('id')->get();
        $this->assertSame(1, (int) $items[0]->account);
        $this->assertSame('50.00', (string) $items[0]->debit);
        $this->assertSame(2, (int) $items[1]->account);
        $this->assertSame('50.00', (string) $items[1]->credit);

        // The original journal is untouched.
        $this->assertSame(2, JournalItem::where('journal', $confirmed['journal']->id)->count());
    }

    public function test_missing_movement_confirm_needs_the_approve_permission(): void
    {
        $session = $this->makeSession();
        $line = $this->makeLine($session, 1, '2026-11-06', 'Bank fee', '-50.0000', '199950.0000');
        $movement = $this->service->proposeMissingMovement(1, $session->id, $line->id, 2, [
            'category' => 'fee', 'offset_account_id' => 2,
        ]);

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessageMatches('/approve treasury reconciliation/');
        // User 2 proposed it but holds manage only — cannot confirm.
        $this->service->confirmMissingMovement(1, $movement->id, 2);
    }

    // ------------------------------------------------------------------
    // API import with sync cursor
    // ------------------------------------------------------------------

    public function test_api_import_cursor_resume_imports_only_new_lines(): void
    {
        $rows = [
            ['external_id' => 'ext-001', 'txn_date' => '2026-11-05', 'description' => 'First', 'amount' => '1000', 'balance' => '201000'],
            ['external_id' => 'ext-002', 'txn_date' => '2026-11-06', 'description' => 'Second', 'amount' => '2000', 'balance' => '203000'],
            ['external_id' => 'ext-003', 'txn_date' => '2026-11-07', 'description' => 'Third', 'amount' => '3000', 'balance' => '206000'],
        ];

        $first = $this->service->importFromApi(1, 1, 'bim-api', $rows);
        $this->assertSame(3, $first['imported']);
        $this->assertSame(0, $first['skipped']);
        $this->assertSame('ext-003', $first['cursor']);
        $this->assertSame('api', $first['session']->preset_key);

        // Second run: the same feed, two seen rows + one new row.
        $second = $this->service->importFromApi(1, 1, 'bim-api', [
            ['external_id' => 'ext-002', 'txn_date' => '2026-11-06', 'description' => 'Second', 'amount' => '2000', 'balance' => '203000'],
            ['external_id' => 'ext-003', 'txn_date' => '2026-11-07', 'description' => 'Third', 'amount' => '3000', 'balance' => '206000'],
            ['external_id' => 'ext-004', 'txn_date' => '2026-11-08', 'description' => 'Fourth', 'amount' => '4000', 'balance' => '210000'],
        ]);
        $this->assertSame(1, $second['imported']);
        $this->assertSame(2, $second['skipped']);
        $this->assertSame('ext-004', $second['cursor']);

        // ext-002 and ext-003 exist exactly once across all sessions.
        $this->assertSame(1, BankStatementLine::where('external_id', 'ext-002')->count());
        $this->assertSame(1, BankStatementLine::where('external_id', 'ext-003')->count());
        $this->assertSame(2, BankReconciliationSession::where('created_by', 1)->count());
    }

    public function test_api_import_cross_company_bank_is_refused(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        // Company 4 tries to sync company 1's bank account.
        $this->service->importFromApi(4, 1, 'bim-api', [
            ['external_id' => 'ext-001', 'txn_date' => '2026-11-05', 'amount' => '1000'],
        ]);
    }

    public function test_match_cross_company_session_is_refused(): void
    {
        $session = $this->makeSession();
        $line = $this->makeLine($session, 1, '2026-11-05', 'Transfer in', '10000.0000', '210000.0000');
        $operation = $this->makeOperation('10000.0000', 'COMP-RCT-2026-0001');

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessageMatches('/does not belong to this company/');
        $this->service->matchToTreasuryOperation(4, $session->id, $line->id, $operation->id, 5);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function makeBankAccount(int $id, int $creatorId): void
    {
        DB::table('bank_accounts')->insert([
            'id' => $id, 'holder_name' => 'Hoya Hoya Lda', 'bank_name' => 'BIM',
            'account_number' => '12345678' . $id, 'chart_account_id' => 1,
            'opening_balance' => 0, 'contact_number' => '', 'bank_address' => '',
            'created_by' => $creatorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

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

    protected function makeLine(BankReconciliationSession $session, int $rowIndex, string $date, string $description, ?string $amount, ?string $balance = null, ?string $externalId = null): BankStatementLine
    {
        return BankStatementLine::create([
            'session_id' => $session->id,
            'row_index' => $rowIndex,
            'external_id' => $externalId,
            'txn_date' => $date,
            'description' => $description,
            'amount' => $amount,
            'balance' => $balance,
        ]);
    }

    protected function makeOperation(string $amount, string $reference, string $state = 'confirmed', string $direction = 'inflow'): TreasuryOperation
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
                'direction' => $direction, 'amount_original' => $amount, 'amount_base' => $amount,
                'exchange_rate' => '1.0000000000', 'confirmed_at' => now(), 'created_by' => 1,
            ]);
        }

        return $operation;
    }

    /**
     * The close fixture: opening 200,000 + a confirmed 10,000 receipt
     * = book 210,000. Statement: +10,000 (balance 210,000, matched to
     * the receipt) then a −50 fee (balance 209,950, unmatched).
     * Statement 209,950 = book 210,000 + outstanding (−50).
     */
    protected function makeCloseSession(): BankReconciliationSession
    {
        $session = $this->makeSession();
        $line1 = $this->makeLine($session, 1, '2026-11-05', 'Transfer in', '10000.0000', '210000.0000');
        $this->makeLine($session, 2, '2026-11-06', 'Bank fee', '-50.0000', '209950.0000');
        $operation = $this->makeOperation('10000.0000', 'COMP-RCT-2026-0001');
        $this->service->matchToTreasuryOperation(1, $session->id, $line1->id, $operation->id, 2);

        return $session->fresh();
    }

    // ------------------------------------------------------------------
    // Schema (minimal, in-memory — mirrors the real migrations,
    // including the Phase 7 columns and the dropped one-match-per-line
    // unique on bank_reconciliation_matches)
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
        Schema::create('settings', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('chart_of_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->integer('type')->nullable();
            $table->integer('sub_type')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('bank_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->string('holder_name')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->bigInteger('chart_account_id')->nullable();
            $table->decimal('opening_balance', 20, 4)->default(0);
            $table->string('contact_number')->nullable();
            $table->string('bank_address')->nullable();
            $table->integer('created_by')->nullable();
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
            // Phase 7 dropped the legacy unique (session_id,
            // statement_line_id): one line may match several movements.
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
        Schema::create('dimension_audit_events', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->string('entity_type');
            $table->integer('entity_id');
            $table->string('action');
            $table->text('before_json')->nullable();
            $table->text('after_json')->nullable();
            $table->integer('actor_id')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });
    }
}
