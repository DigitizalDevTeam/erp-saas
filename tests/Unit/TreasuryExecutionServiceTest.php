<?php

namespace Tests\Unit;

use App\Exceptions\TreasuryException;
use App\Models\TreasuryApprovalDecision;
use App\Models\TreasuryApprovalStep;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryFundReservation;
use App\Models\TreasuryPaymentRequest;
use App\Services\TreasuryAccountService;
use App\Services\TreasuryExecutionService;
use App\Services\TreasuryPaymentService;
use App\Services\TreasurySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Treasury Phase 3 — manual payment execution (plan §10 exit
 * gates CA06, CA07, CA17, CA18, CA20).
 *
 * Users: 1 = company, 2 = requester, 3 = approver, 5 = second
 * approver, 4 = other company. The source account is funded with
 * 200,000 MZN by default; the bank chart account is 121, the
 * accounts-payable chart account is 421.
 */
class TreasuryExecutionServiceTest extends TestCase
{
    protected TreasuryPaymentService $service;
    protected TreasuryAccountService $accounts;
    protected TreasuryExecutionService $execution;
    protected TreasuryFinancialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Requester', 'email' => 'requester@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 5, 'name' => 'Second Approver', 'email' => 'approver2@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Maputo', 'created_by' => 1],
        ]);

        DB::table('permissions')->insert([
            ['id' => 1, 'name' => 'approve treasury payments', 'guard_name' => 'web'],
        ]);
        foreach ([2, 3, 5] as $userId) {
            DB::table('model_has_permissions')->insert([
                'permission_id' => 1, 'model_type' => 'App\\Models\\User', 'model_id' => $userId,
            ]);
        }

        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'code' => 121, 'name' => 'Bank', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 2, 'code' => 421, 'name' => 'Accounts Payable', 'type' => 2, 'sub_type' => 1, 'created_by' => 1],
        ]);

        DB::table('venders')->insert(['id' => 1, 'name' => 'Supplier', 'created_by' => 1, 'balance' => '0.0000']);

        $this->accounts = new TreasuryAccountService();
        $this->service = new TreasuryPaymentService($this->accounts);
        $this->execution = new TreasuryExecutionService($this->accounts, $this->service);
        $this->account = $this->makeAccount('200000.0000');
    }

    protected function tearDown(): void
    {
        foreach (['treasury_payment_batch_items', 'treasury_payment_batches', 'treasury_settlement_allocations', 'treasury_payment_attempts', 'treasury_fund_reservations', 'treasury_approval_decisions', 'treasury_approval_steps', 'treasury_payment_requests', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'dimension_audit_events', 'bank_accounts', 'chart_of_accounts', 'bill_payments', 'bill_products', 'bills', 'venders', 'branches', 'settings', 'users', 'permissions', 'model_has_permissions', 'journal_entries', 'journal_items', 'transaction_lines', 'transactions'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function makeAccount(string $balance, string $code = 'BIM-001'): TreasuryFinancialAccount
    {
        $bankId = DB::table('bank_accounts')->insertGetId([
            'holder_name' => 'Hoya Hoya Lda', 'bank_name' => 'BIM', 'account_number' => '123456789',
            'chart_account_id' => 1, 'opening_balance' => 0, 'contact_number' => '', 'bank_address' => '',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $account = TreasuryFinancialAccount::create([
            'bank_account_id' => $bankId, 'created_by' => 1, 'code' => $code,
            'type' => 'bank', 'currency' => 'MZN', 'status' => 'active',
            'negative_balance_policy' => 'block',
        ]);

        DB::table('treasury_opening_balances')->insert([
            'financial_account_id' => $account->id, 'created_by' => 1, 'currency' => 'MZN',
            'amount' => $balance, 'amount_base' => $balance, 'cut_off_date' => '2026-11-01',
            'batch_reference' => 'BATCH-1', 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $account->fresh();
    }

    protected function requestData(array $overrides = []): array
    {
        return array_merge([
            'source_account_id' => $this->account->id,
            'beneficiary_type' => 'vendor',
            'beneficiary_id' => 1,
            'amount_original' => '50000',
            'currency' => 'MZN',
            'reason' => 'Supplier payment',
        ], $overrides);
    }

    protected function makeRequest(array $overrides = [], int $requesterId = 2): TreasuryPaymentRequest
    {
        return $this->service->createRequest(1, $this->requestData($overrides), $requesterId);
    }

    protected function approvedRequest(array $overrides = [], int $requesterId = 2): TreasuryPaymentRequest
    {
        $request = $this->makeRequest($overrides, $requesterId);
        $this->service->submitRequest($request, $requesterId);
        $this->service->sendForApproval($request->fresh(), $requesterId);

        return $request->fresh();
    }

    protected function approveStep(TreasuryPaymentRequest $request, int $level, int $actorId): void
    {
        $step = $request->steps()->where('level', $level)->where('status', TreasuryApprovalStep::STATUS_PENDING)->firstOrFail();
        $this->service->decide($step, $actorId, TreasuryApprovalDecision::DECISION_APPROVE);
    }

    protected function approveRequest(TreasuryPaymentRequest $request): TreasuryPaymentRequest
    {
        $request = $request->fresh();
        $levels = $request->steps()->where('status', TreasuryApprovalStep::STATUS_PENDING)->orderBy('level')->get();
        $approvers = [3, 5];
        foreach ($levels as $index => $step) {
            $this->approveStep($request->fresh(), (int) $step->level, $approvers[$index]);
        }

        return $request->fresh();
    }

    protected function makeBill(int $id, string $total, int $venderId = 1): void
    {
        DB::table('bills')->insert([
            'id' => $id, 'bill_id' => $id, 'created_by' => 1, 'vender_id' => $venderId,
            'status' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('bill_products')->insert(['id' => $id, 'bill_id' => $id, 'total' => $total]);
    }

    protected function executeApproved(array $overrides = [], string $amount = '50000', array $options = []): array
    {
        $request = $this->approvedRequest($overrides);
        $request = $this->approveRequest($request);

        return $this->execution->execute(1, $request->id, $amount, 2, array_merge(['idempotency_key' => 'exec-' . $request->id], $options));
    }

    // ------------------------------------------------------------------
    // Happy path + posting
    // ------------------------------------------------------------------

    public function test_execute_full_payment_posts_journal_entry_and_settles_bill(): void
    {
        $this->makeBill(1, '50000.0000');
        $result = $this->executeApproved(['source_documents' => [['type' => 'bill', 'id' => 1]]]);

        $request = $result['request'];
        $this->assertTrue($request->isPaid());
        $this->assertSame('50000.0000', (string) $request->paid_amount_base);
        $this->assertSame('0.0000', $request->remainingAmountBase());
        $this->assertFalse($result['replayed']);

        // One journal: debit AP 421, credit bank 121, balanced.
        $journal = $result['journal'];
        $this->assertNotNull($journal);
        $items = DB::table('journal_items')->where('journal', $journal->id)->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertSame(2, (int) $items[0]->account); // 421
        $this->assertSame('50000.00', number_format((float) $items[0]->debit, 2, '.', ''));
        $this->assertSame(1, (int) $items[1]->account); // 121
        $this->assertSame('50000.00', number_format((float) $items[1]->credit, 2, '.', ''));

        // One entry: the bank outflow, linked to the journal.
        $entry = $result['entry'];
        $this->assertNotNull($entry);
        $this->assertSame('outflow', $entry->direction);
        $this->assertSame('50000.0000', (string) $entry->amount_base);
        $this->assertSame($journal->id, (int) $entry->journal_id);
        $this->assertSame('confirmed', $result['request']->operation->fresh()->state);

        // Balances: posted drops by the outflow.
        $this->assertSame('150000.0000', $this->accounts->postedBalance($this->account->fresh()));
        $this->assertSame('150000.0000', $this->accounts->availableBalance($this->account->fresh()));

        // The attempt carries the movement it posted.
        $attempt = $result['attempt'];
        $this->assertSame('paid', $attempt->state);
        $this->assertSame($journal->id, (int) $attempt->journal_id);
        $this->assertSame($entry->id, (int) $attempt->entry_id);
        $this->assertSame('50000.0000', (string) $attempt->amount_base);

        // The bill is settled: BillPayment + allocation + status paid.
        $this->assertSame('0.0000', $this->service->billOutstanding(1, 1));
        $this->assertSame(4, (int) DB::table('bills')->where('id', 1)->value('status'));
        $payment = DB::table('bill_payments')->where('bill_id', 1)->first();
        $this->assertNotNull($payment);
        $this->assertSame('50000.0000', number_format((float) $payment->amount, 4, '.', ''));
        $allocation = DB::table('treasury_settlement_allocations')->where('payment_id', $attempt->id)->first();
        $this->assertNotNull($allocation);
        $this->assertSame('bill', $allocation->target_type);
        $this->assertSame(1, (int) $allocation->target_id);
        $this->assertSame('50000.0000', number_format((float) $allocation->allocated_amount_base, 4, '.', ''));
        $this->assertSame($journal->id, (int) $allocation->journal_id);
        $this->assertSame((int) $payment->id, (int) $allocation->invoice_payment_id);

        // The vendor ledger sees the payment (Transaction row +
        // vendor balance down by the payment).
        $this->assertSame(1, DB::table('transactions')->where('payment_id', $payment->id)->count());
        $this->assertSame('-50000.0000', number_format((float) DB::table('venders')->where('id', 1)->value('balance'), 4, '.', ''));
    }

    public function test_execute_with_reservation_consumes_it(): void
    {
        TreasurySettings::save(1, ['treasury_reservation_policy' => 'on']);

        $request = $this->approvedRequest();
        $request = $this->approveRequest($request);
        $reservation = $request->fresh()->reservations()->firstOrFail();
        $this->assertSame('active', $reservation->state);
        $this->assertSame('150000.0000', $this->accounts->availableBalance($this->account->fresh()));

        $result = $this->execution->execute(1, $request->id, '50000', 2, ['idempotency_key' => 'exec-res-1']);

        $this->assertTrue($result['request']->isPaid());
        $this->assertSame('consumed', $reservation->fresh()->state);
        $this->assertSame('50000.0000', (string) $reservation->fresh()->consumed_amount);
        // Posted drops by the outflow and the reservation is gone,
        // so available == posted again.
        $this->assertSame('150000.0000', $this->accounts->postedBalance($this->account->fresh()));
        $this->assertSame('150000.0000', $this->accounts->availableBalance($this->account->fresh()));
    }

    public function test_partial_payments_track_paid_vs_remaining(): void
    {
        $this->makeBill(1, '50000.0000');
        $request = $this->approvedRequest(['source_documents' => [['type' => 'bill', 'id' => 1]]]);
        $request = $this->approveRequest($request);

        $first = $this->execution->execute(1, $request->id, '20000', 2, ['idempotency_key' => 'exec-part-1']);
        $request = $first['request'];
        $this->assertTrue($request->isProcessing());
        $this->assertTrue($request->isPartiallyPaid());
        $this->assertSame('20000.0000', (string) $request->paid_amount_base);
        $this->assertSame('30000.0000', $request->remainingAmountBase());
        $this->assertSame('30000.0000', $this->service->billOutstanding(1, 1));
        $this->assertSame(3, (int) DB::table('bills')->where('id', 1)->value('status'));
        $this->assertSame('180000.0000', $this->accounts->postedBalance($this->account->fresh()));

        $second = $this->execution->execute(1, $request->id, '30000', 2, ['idempotency_key' => 'exec-part-2']);
        $request = $second['request'];
        $this->assertTrue($request->isPaid());
        $this->assertSame('50000.0000', (string) $request->paid_amount_base);
        $this->assertSame('0.0000', $request->remainingAmountBase());
        $this->assertSame('0.0000', $this->service->billOutstanding(1, 1));
        $this->assertSame(4, (int) DB::table('bills')->where('id', 1)->value('status'));
        $this->assertSame(2, DB::table('treasury_entries')->where('operation_id', $request->operation_id)->count());
        $this->assertSame(2, DB::table('journal_entries')->count());
    }

    public function test_partial_bill_allocation_is_fifo_across_bills(): void
    {
        $this->makeBill(1, '30000.0000');
        $this->makeBill(2, '40000.0000');
        $result = $this->executeApproved([
            'amount_original' => '60000',
            'source_documents' => [['type' => 'bill', 'id' => 1], ['type' => 'bill', 'id' => 2]],
        ], '60000');

        $this->assertTrue($result['request']->isPaid());
        $this->assertCount(2, $result['allocations']);
        $this->assertSame('30000.0000', $result['allocations'][0]['allocated_base']);
        $this->assertSame('30000.0000', $result['allocations'][1]['allocated_base']);
        $this->assertSame('0.0000', $result['manual_portion_base']);
        $this->assertSame('0.0000', $this->service->billOutstanding(1, 1));
        $this->assertSame('10000.0000', $this->service->billOutstanding(1, 2));
        $this->assertSame(4, (int) DB::table('bills')->where('id', 1)->value('status'));
        $this->assertSame(3, (int) DB::table('bills')->where('id', 2)->value('status'));
    }

    public function test_manual_payment_without_bills_posts_without_allocations(): void
    {
        $result = $this->executeApproved();

        $this->assertTrue($result['request']->isPaid());
        $this->assertSame([], $result['allocations']);
        $this->assertSame('50000.0000', $result['manual_portion_base']);
        $this->assertSame(0, DB::table('treasury_settlement_allocations')->count());
        $this->assertSame(0, DB::table('bill_payments')->count());
        $this->assertNotNull($result['journal']);
    }

    public function test_execute_more_than_remaining_is_refused(): void
    {
        $request = $this->approvedRequest();
        $request = $this->approveRequest($request);
        $this->execution->execute(1, $request->id, '20000', 2, ['idempotency_key' => 'exec-over-1']);

        try {
            $this->execution->execute(1, $request->fresh()->id, '40000', 2, ['idempotency_key' => 'exec-over-2']);
            $this->fail('Execution above the remaining amount was allowed.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('remaining', strtolower($e->getMessage()));
        }

        $this->assertSame('20000.0000', (string) $request->fresh()->paid_amount_base);
    }

    // ------------------------------------------------------------------
    // Recheck failures — each refuses with a reason and leaves the
    // request approved (and the reservation untouched).
    // ------------------------------------------------------------------

    public function test_recheck_insufficient_availability_refuses_and_keeps_request_approved(): void
    {
        $small = $this->makeAccount('40000.0000', 'BIM-002');
        $request = $this->approvedRequest(['source_account_id' => $small->id]);
        $request = $this->approveRequest($request);

        try {
            $this->execution->execute(1, $request->id, '50000', 2, ['idempotency_key' => 'exec-avail-1']);
            $this->fail('Execution beyond the available balance was allowed.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('available balance', strtolower($e->getMessage()));
        }

        $this->assertTrue($request->fresh()->isApproved());
        $this->assertSame(0, DB::table('treasury_payment_attempts')->count());
        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->assertSame(0, DB::table('treasury_entries')->count());
    }

    public function test_recheck_closed_period_refuses_and_keeps_reservation_active(): void
    {
        TreasurySettings::save(1, ['treasury_reservation_policy' => 'on']);
        DB::table('journal_entries')->insert([
            'date' => '2026-01-15', 'reference' => 'FY-2026-CLOSE-1', 'description' => 'Year-end close',
            'journal_id' => 900, 'status' => 1, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $request = $this->approvedRequest();
        $request = $this->approveRequest($request);
        $reservation = $request->fresh()->reservations()->firstOrFail();

        try {
            $this->execution->execute(1, $request->id, '50000', 2, ['idempotency_key' => 'exec-closed-1']);
            $this->fail('Execution into a closed period was allowed.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('closed', strtolower($e->getMessage()));
        }

        $this->assertTrue($request->fresh()->isApproved());
        $this->assertSame('active', $reservation->fresh()->state);
        $this->assertSame(0, DB::table('treasury_payment_attempts')->count());
    }

    public function test_closed_period_authorised_adjustment_posts_to_open_period(): void
    {
        DB::table('journal_entries')->insert([
            'date' => '2026-01-15', 'reference' => 'FY-2026-CLOSE-1', 'description' => 'Year-end close',
            'journal_id' => 900, 'status' => 1, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $request = $this->approvedRequest();
        $request = $this->approveRequest($request);

        $result = $this->execution->execute(1, $request->id, '50000', 2, [
            'idempotency_key' => 'exec-adj-1',
            'adjustment' => ['authorised_by' => 5, 'reason' => 'Year-end close posted late.'],
        ]);

        $this->assertTrue($result['request']->isPaid());
        $this->assertNotNull($result['adjustment']);
        $this->assertSame(date('Y-m-d'), $result['posting_date']);
        $this->assertSame(date('Y-m-d'), $result['journal']->date);
        $this->assertStringContainsString('authorised adjustment', strtolower($result['attempt']->result ?? ''));
    }

    public function test_closed_period_adjustment_needs_a_different_approver(): void
    {
        DB::table('journal_entries')->insert([
            'date' => '2026-01-15', 'reference' => 'FY-2026-CLOSE-1', 'description' => 'Year-end close',
            'journal_id' => 900, 'status' => 1, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $request = $this->approvedRequest();
        $request = $this->approveRequest($request);

        try {
            $this->execution->execute(1, $request->id, '50000', 2, [
                'idempotency_key' => 'exec-adj-2',
                'adjustment' => ['authorised_by' => 2, 'reason' => 'Self-authorised.'],
            ]);
            $this->fail('A self-authorised adjustment was allowed.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('different user', strtolower($e->getMessage()));
        }

        $this->assertTrue($request->fresh()->isApproved());
    }

    public function test_recheck_stale_approval_snapshot_refuses(): void
    {
        $request = $this->approvedRequest();
        $request = $this->approveRequest($request);

        // The amount changed after approval without invalidating
        // the workflow (simulates a stale snapshot): the recheck
        // must catch it and refuse.
        DB::table('treasury_payment_requests')->where('id', $request->id)->update(['amount_original' => '60000.0000']);

        try {
            $this->execution->execute(1, $request->id, '50000', 2, ['idempotency_key' => 'exec-stale-1']);
            $this->fail('Execution on a stale approval was allowed.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('approval is no longer valid', strtolower($e->getMessage()));
        }

        $this->assertTrue($request->fresh()->isApproved());
    }

    public function test_recheck_missing_beneficiary_refuses(): void
    {
        $request = $this->approvedRequest();
        $request = $this->approveRequest($request);
        DB::table('venders')->where('id', 1)->delete();

        try {
            $this->execution->execute(1, $request->id, '50000', 2, ['idempotency_key' => 'exec-ben-1']);
            $this->fail('Execution with a deleted beneficiary was allowed.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('beneficiary not found', strtolower($e->getMessage()));
        }

        $this->assertTrue($request->fresh()->isApproved());
    }

    public function test_recheck_fully_paid_bill_refuses(): void
    {
        $this->makeBill(1, '10000.0000');
        DB::table('bill_payments')->insert(['bill_id' => 1, 'amount' => '10000.0000']);
        $request = $this->approvedRequest(['source_documents' => [['type' => 'bill', 'id' => 1]]]);
        $request = $this->approveRequest($request);

        try {
            $this->execution->execute(1, $request->id, '50000', 2, ['idempotency_key' => 'exec-bill-1']);
            $this->fail('Execution against a fully paid bill was allowed.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('already fully paid', strtolower($e->getMessage()));
        }

        $this->assertTrue($request->fresh()->isApproved());
    }

    public function test_recheck_executor_cannot_be_sole_approver(): void
    {
        $request = $this->approvedRequest();
        $request = $this->approveRequest($request);

        // The executor is set to the only approver after approval.
        DB::table('treasury_payment_requests')->where('id', $request->id)->update(['executor_user_id' => 3]);

        try {
            $this->execution->execute(1, $request->id, '50000', 3, ['idempotency_key' => 'exec-exec-1', 'executor_user_id' => 3]);
            $this->fail('Execution by the sole approver was allowed.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('sole approver', strtolower($e->getMessage()));
        }
    }

    public function test_execute_draft_request_is_refused(): void
    {
        $request = $this->makeRequest();

        try {
            $this->execution->execute(1, $request->id, '50000', 2, ['idempotency_key' => 'exec-draft-1']);
            $this->fail('Execution of a draft was allowed.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('can only start for an approved request', strtolower($e->getMessage()));
        }
    }

    public function test_execute_for_other_company_is_rejected(): void
    {
        $request = $this->approvedRequest();
        $request = $this->approveRequest($request);

        try {
            $this->execution->execute(4, $request->id, '50000', 2, ['idempotency_key' => 'exec-cross-1']);
            $this->fail('Cross-company execution was allowed.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('not found for this company', strtolower($e->getMessage()));
        }
    }

    // ------------------------------------------------------------------
    // Idempotency (CA20) + concurrency + CA18
    // ------------------------------------------------------------------

    public function test_idempotency_same_key_returns_original_without_second_movement(): void
    {
        $request = $this->approvedRequest();
        $request = $this->approveRequest($request);

        $first = $this->execution->execute(1, $request->id, '50000', 2, ['idempotency_key' => 'exec-idem-1']);
        $second = $this->execution->execute(1, $request->id, '50000', 2, ['idempotency_key' => 'exec-idem-1']);

        $this->assertTrue($second['replayed']);
        $this->assertSame($first['attempt']->id, $second['attempt']->id);
        $this->assertSame($first['journal']->id, $second['journal']->id);
        $this->assertSame(1, DB::table('journal_entries')->count());
        $this->assertSame(1, DB::table('treasury_entries')->count());
        $this->assertSame(1, DB::table('treasury_payment_attempts')->count());
        $this->assertSame('150000.0000', $this->accounts->postedBalance($this->account->fresh()));
    }

    public function test_idempotency_conflicting_payload_is_rejected(): void
    {
        $request = $this->approvedRequest();
        $request = $this->approveRequest($request);
        $this->execution->execute(1, $request->id, '20000', 2, ['idempotency_key' => 'exec-idem-2']);

        try {
            $this->execution->execute(1, $request->id, '30000', 2, ['idempotency_key' => 'exec-idem-2']);
            $this->fail('A conflicting payload with the same key was accepted.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('conflict', strtolower($e->getMessage()));
        }

        $this->assertSame('20000.0000', (string) $request->fresh()->paid_amount_base);
        $this->assertSame(1, DB::table('journal_entries')->count());
    }

    public function test_second_execution_after_full_payment_is_refused(): void
    {
        $request = $this->approvedRequest();
        $request = $this->approveRequest($request);
        $this->execution->execute(1, $request->id, '50000', 2, ['idempotency_key' => 'exec-twice-1']);

        try {
            $this->execution->execute(1, $request->id, '50000', 2, ['idempotency_key' => 'exec-twice-2']);
            $this->fail('A second full execution of a paid request was allowed.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('can only start for an approved request', strtolower($e->getMessage()));
        }

        $this->assertSame(1, DB::table('journal_entries')->count());
        $this->assertSame('150000.0000', $this->accounts->postedBalance($this->account->fresh()));
    }

    public function test_accounting_failure_leaves_no_movement_and_retry_succeeds(): void
    {
        // No accounts-payable account configured: the poster step
        // fails, and the whole execution rolls back (CA18).
        DB::table('chart_of_accounts')->where('id', 2)->delete();

        $request = $this->approvedRequest();
        $request = $this->approveRequest($request);

        try {
            $this->execution->execute(1, $request->id, '50000', 2, ['idempotency_key' => 'exec-ca18-1']);
            $this->fail('Execution without an AP account was allowed.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('accounts payable', strtolower($e->getMessage()));
        }

        $this->assertSame(0, DB::table('treasury_payment_attempts')->count());
        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->assertSame(0, DB::table('treasury_entries')->count());
        $this->assertTrue($request->fresh()->isApproved());

        // The duplicate-safe retry with the same key succeeds and
        // posts exactly one movement.
        DB::table('chart_of_accounts')->insert([
            'id' => 2, 'code' => 421, 'name' => 'Accounts Payable', 'type' => 2, 'sub_type' => 1, 'created_by' => 1,
        ]);
        $result = $this->execution->execute(1, $request->id, '50000', 2, ['idempotency_key' => 'exec-ca18-1']);

        $this->assertFalse($result['replayed']);
        $this->assertTrue($result['request']->isPaid());
        $this->assertSame(1, DB::table('journal_entries')->count());
        $this->assertSame(1, DB::table('treasury_payment_attempts')->count());
    }

    // ------------------------------------------------------------------
    // Outcome unknown (CA06)
    // ------------------------------------------------------------------

    public function test_outcome_unknown_requires_provider_query_before_retry(): void
    {
        $request = $this->approvedRequest();
        $request = $this->approveRequest($request);

        $attempt = $this->service->beginAttempt($request->fresh(), 'manual', 'exec-ca06-1', ['amount' => '50000'], 2);
        $this->execution->reportTimeout(1, $attempt->id, 2, 'Bank transfer timed out.');

        $this->assertSame('outcome_unknown', $attempt->fresh()->state);
        $this->assertSame('outcome_unknown', $request->fresh()->state);

        // No query yet: execution is refused.
        try {
            $this->execution->execute(1, $request->id, '50000', 2, ['idempotency_key' => 'exec-ca06-2']);
            $this->fail('Retry without a provider query was allowed.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('query the provider', strtolower($e->getMessage()));
        }

        // Queried, provider says it did NOT go through: retry with
        // a new attempt settles the payment.
        $result = $this->execution->execute(1, $request->id, '50000', 2, [
            'idempotency_key' => 'exec-ca06-2',
            'provider_queried' => true,
        ]);

        $this->assertTrue($result['request']->isPaid());
        $this->assertSame(2, (int) $result['attempt']->attempt_number);
        $this->assertSame(2, DB::table('treasury_payment_attempts')->count());
    }

    public function test_confirm_provider_paid_posts_through_the_original_attempt(): void
    {
        $request = $this->approvedRequest();
        $request = $this->approveRequest($request);

        $attempt = $this->service->beginAttempt($request->fresh(), 'manual', 'exec-ca06-3', ['amount' => '50000'], 2);
        $this->execution->reportTimeout(1, $attempt->id, 2, 'Bank transfer timed out.');

        $result = $this->execution->confirmProviderPaid(1, $attempt->id, 2, 'BANK-REF-1', 'Bank confirmed the transfer.');

        $this->assertTrue($result['request']->isPaid());
        $this->assertSame($attempt->id, $result['attempt']->id);
        $this->assertSame(1, (int) $result['attempt']->attempt_number);
        $this->assertSame('BANK-REF-1', $result['attempt']->provider_reference);
        $this->assertNotNull($result['journal']);
        $this->assertSame(1, DB::table('treasury_payment_attempts')->count());
        $this->assertSame(1, DB::table('journal_entries')->count());
        $this->assertSame('150000.0000', $this->accounts->postedBalance($this->account->fresh()));
    }

    // ------------------------------------------------------------------
    // Preview + Phase 2 rough-edge fix
    // ------------------------------------------------------------------

    public function test_preview_shows_recheck_results_before_confirm(): void
    {
        $this->makeBill(1, '50000.0000');
        $request = $this->approvedRequest(['source_documents' => [['type' => 'bill', 'id' => 1]]]);
        $request = $this->approveRequest($request);

        $preview = $this->execution->previewExecution(1, $request->id, '50000', 2);

        $this->assertTrue($preview['can_execute']);
        $this->assertSame('50000.0000', $preview['amount_base']);
        $this->assertSame('50000.0000', $preview['allocated_to_bills_base']);
        $this->assertSame('0.0000', $preview['manual_portion_base']);
        foreach ($preview['checks'] as $check) {
            $this->assertTrue($check['passed'], 'Check ' . $check['key'] . ' failed: ' . $check['detail']);
        }

        // With a closed period the preview blocks execution.
        DB::table('journal_entries')->insert([
            'date' => '2026-01-15', 'reference' => 'FY-2026-CLOSE-1', 'description' => 'Year-end close',
            'journal_id' => 900, 'status' => 1, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $blocked = $this->execution->previewExecution(1, $request->id, '50000', 2);
        $this->assertFalse($blocked['can_execute']);
        $period = collect($blocked['checks'])->firstWhere('key', 'period');
        $this->assertFalse($period['passed']);
    }

    public function test_cancelled_request_client_reference_cannot_be_reused(): void
    {
        $request = $this->makeRequest(['client_reference' => 'REF-1']);
        $this->service->cancelRequest($request, 2, 'No longer needed.');

        // The DB unique index blocks reuse in ANY state, so the
        // service must refuse with a clean TreasuryException —
        // not a raw QueryException.
        try {
            $this->makeRequest(['client_reference' => 'REF-1']);
            $this->fail('A cancelled request reference was reused.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('already exists', strtolower($e->getMessage()));
        }
    }

    // ------------------------------------------------------------------
    // Schema (Phase 2 tables + the poster/ledger tables Phase 3
    // writes + the Phase 3 execution columns and batch tables)
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
    }

    public function test_concurrent_execution_is_refused(): void
    {
        $request = $this->approveRequest($this->approvedRequest());

        // A first execution is already in flight (processing attempt).
        $this->service->beginAttempt($request->fresh(), 'manual', 'concurrent-1', [
            'amount_original' => '50000', 'currency' => 'MZN',
            'posting_date' => '2026-11-02', 'provider' => 'manual',
        ], 2);

        try {
            $this->execution->execute(1, $request->id, '50000', 2, ['idempotency_key' => 'concurrent-2']);
            $this->fail('A second concurrent execution was allowed.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('already in progress', strtolower($e->getMessage()));
        }

        // beginAttempt moves the request to PROCESSING while the
        // first execution is in flight; the refused second execution
        // leaves it there, with no money moved.
        $this->assertTrue($request->fresh()->isProcessing());
        $this->assertSame(0, DB::table('journal_entries')->count());
        $this->assertSame(0, DB::table('treasury_entries')->count());
    }

    public function test_second_execution_after_paid_is_refused(): void
    {
        $result = $this->executeApproved();
        $request = $result['request'];

        try {
            $this->execution->execute(1, $request->id, '50000', 2, ['idempotency_key' => 'after-paid-1']);
            $this->fail('A paid request was executed again.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('can only start for an approved request', strtolower($e->getMessage()));
        }

        $this->assertSame(1, DB::table('journal_entries')->count());
    }
}
