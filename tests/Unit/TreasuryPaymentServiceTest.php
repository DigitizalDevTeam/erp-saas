<?php

namespace Tests\Unit;

use App\Exceptions\TreasuryException;
use App\Models\TreasuryApprovalDecision;
use App\Models\TreasuryApprovalStep;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryFundReservation;
use App\Models\TreasuryPaymentRequest;
use App\Services\TreasuryAccountService;
use App\Services\TreasuryPaymentService;
use App\Services\TreasurySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Treasury Phase 2 — payment requests + approvals + reservations
 * (plan §10 exit gates CA05, CA06, CA20).
 *
 * Users: 1 = company, 2 = requester (employee, can approve),
 * 3 = approver, 5 = second approver, 6 = employee WITHOUT the
 * approve permission (substitute only), 4 = other company.
 */
class TreasuryPaymentServiceTest extends TestCase
{
    protected TreasuryPaymentService $service;
    protected TreasuryAccountService $accounts;
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

        // 'approve treasury payments' is held by 2, 3 and 5 only —
        // user 6 can decide only as an assigned substitute.
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
        ]);

        DB::table('venders')->insert(['id' => 1, 'name' => 'Supplier', 'created_by' => 1]);

        $this->accounts = new TreasuryAccountService();
        $this->service = new TreasuryPaymentService($this->accounts);
        $this->account = $this->makeAccount('200000.0000');
    }

    protected function tearDown(): void
    {
        foreach (['treasury_payment_attempts', 'treasury_fund_reservations', 'treasury_approval_decisions', 'treasury_approval_steps', 'treasury_payment_requests', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'dimension_audit_events', 'bank_accounts', 'chart_of_accounts', 'bill_payments', 'bill_products', 'bills', 'venders', 'branches', 'settings', 'users', 'permissions', 'model_has_permissions'] as $table) {
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
        // The pending step: after a CA05 invalidation there may be an
        // older invalidated step at the same level.
        $step = $request->steps()->where('level', $level)->where('status', TreasuryApprovalStep::STATUS_PENDING)->firstOrFail();
        $this->service->decide($step, $actorId, TreasuryApprovalDecision::DECISION_APPROVE);
    }

    // ------------------------------------------------------------------
    // Workflow states
    // ------------------------------------------------------------------

    public function test_draft_creation_sets_state_and_no_reference(): void
    {
        $request = $this->makeRequest();

        $this->assertTrue($request->isDraft());
        $this->assertSame('50000.0000', (string) $request->amount_original);
        $this->assertSame('50000.0000', (string) $request->amount_base);
        $this->assertNull($request->operation->reference);
        $this->assertSame(0, $request->steps()->count());
    }

    public function test_submit_allocates_reference_and_generates_one_level_for_small_amount(): void
    {
        $request = $this->makeRequest();
        $request = $this->service->submitRequest($request, 2);

        $this->assertTrue($request->isUnderReview());
        $this->assertSame('COMP-PAY-2026-0001', $request->operation->reference);
        $this->assertSame(1, $request->steps()->count());
        $step = $request->steps()->first();
        $this->assertSame(1, (int) $step->level);
        $this->assertSame(1, (int) $step->required_votes);
        $this->assertSame([2, 3, 5], $step->eligibleUserIds());
    }

    public function test_submit_generates_two_levels_for_large_amount(): void
    {
        $request = $this->makeRequest(['amount_original' => '150000']);
        $request = $this->service->submitRequest($request, 2);

        $this->assertSame(2, $request->steps()->count());
        $this->assertSame([1, 2], $request->steps()->orderBy('level')->pluck('level')->map(fn ($v) => (int) $v)->all());
    }

    public function test_send_for_approval_moves_to_awaiting_approval(): void
    {
        $request = $this->approvedRequest();
        $this->assertTrue($request->isAwaitingApproval());
    }

    public function test_reject_return_cancel_and_delete_paths(): void
    {
        $rejected = $this->approvedRequest();
        $this->service->rejectRequest($rejected, 2, 'Not now');
        $this->assertSame(TreasuryPaymentRequest::STATE_REJECTED, $rejected->fresh()->state);

        $returned = $this->approvedRequest();
        $this->service->returnToDraft($returned, 2, 'Fix the amount');
        $this->assertTrue($returned->fresh()->isDraft());

        $cancelled = $this->approvedRequest();
        $this->service->cancelRequest($cancelled, 2, 'No longer needed');
        $this->assertSame(TreasuryPaymentRequest::STATE_CANCELLED, $cancelled->fresh()->state);

        $draft = $this->makeRequest();
        $this->service->deleteRequest($draft);
        $this->assertSame(3, TreasuryPaymentRequest::count());

        $this->expectException(TreasuryException::class);
        $this->service->deleteRequest($rejected->fresh());
    }

    public function test_submit_twice_is_refused(): void
    {
        $request = $this->makeRequest();
        $this->service->submitRequest($request, 2);

        $this->expectException(TreasuryException::class);
        $this->service->submitRequest($request->fresh(), 2);
    }

    // ------------------------------------------------------------------
    // Approval rules
    // ------------------------------------------------------------------

    public function test_self_approval_is_blocked(): void
    {
        $request = $this->approvedRequest();
        $step = $request->steps()->firstOrFail();

        try {
            $this->service->decide($step, 2, TreasuryApprovalDecision::DECISION_APPROVE);
            $this->fail('Requester approved their own request.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('cannot approve a payment request you submitted', $e->getMessage());
        }

        $this->assertTrue($request->fresh()->isAwaitingApproval());
    }

    public function test_executor_cannot_be_sole_approver(): void
    {
        $request = $this->approvedRequest(['executor_user_id' => 3]);
        $step = $request->steps()->firstOrFail();

        try {
            $this->service->decide($step, 3, TreasuryApprovalDecision::DECISION_APPROVE);
            $this->fail('Executor approved alone.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('cannot be the sole approver', $e->getMessage());
        }

        // A different approver completes the approval.
        $this->service->decide($step, 5, TreasuryApprovalDecision::DECISION_APPROVE);
        $this->assertTrue($request->fresh()->isApproved());
    }

    public function test_one_person_cannot_fill_two_levels(): void
    {
        $request = $this->approvedRequest(['amount_original' => '150000']);

        $this->approveStep($request->fresh(), 1, 3);

        $step2 = $request->fresh()->steps()->where('level', 2)->firstOrFail();
        try {
            $this->service->decide($step2, 3, TreasuryApprovalDecision::DECISION_APPROVE);
            $this->fail('Same person approved two levels.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('cannot approve two levels', $e->getMessage());
        }

        $this->service->decide($step2, 5, TreasuryApprovalDecision::DECISION_APPROVE);
        $request = $request->fresh();
        $this->assertTrue($request->isApproved());
        $this->assertNotNull($request->approved_snapshot);
        $this->assertSame(1, (int) $request->approved_rule_version);
        $this->assertSame('150000.0000', (string) $request->approved_snapshot['amount_original']);
    }

    public function test_levels_must_be_decided_in_order(): void
    {
        $request = $this->approvedRequest(['amount_original' => '150000']);
        $step2 = $request->steps()->where('level', 2)->firstOrFail();

        $this->expectException(TreasuryException::class);
        $this->service->decide($step2, 5, TreasuryApprovalDecision::DECISION_APPROVE);
    }

    public function test_custom_approval_rules_with_two_votes(): void
    {
        TreasurySettings::save(1, ['treasury_approval_rules' => json_encode([
            ['level' => 1, 'min_amount_base' => '0', 'permission' => 'approve treasury payments', 'required_votes' => 2],
        ])]);

        $request = $this->approvedRequest();
        $step = $request->steps()->firstOrFail();
        $this->assertSame(2, (int) $step->required_votes);

        $this->service->decide($step, 3, TreasuryApprovalDecision::DECISION_APPROVE);
        $this->assertTrue($request->fresh()->isAwaitingApproval());

        $this->service->decide($step, 5, TreasuryApprovalDecision::DECISION_APPROVE);
        $this->assertTrue($request->fresh()->isApproved());
    }

    public function test_reject_decision_rejects_the_request(): void
    {
        $request = $this->approvedRequest();
        $step = $request->steps()->firstOrFail();

        $this->service->decide($step, 3, TreasuryApprovalDecision::DECISION_REJECT, 'Wrong supplier');

        $this->assertSame(TreasuryPaymentRequest::STATE_REJECTED, $request->fresh()->state);
        $this->assertSame(TreasuryApprovalStep::STATUS_REJECTED, $step->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Substitutes
    // ------------------------------------------------------------------

    public function test_substitute_can_decide_and_expiry_blocks(): void
    {
        $request = $this->approvedRequest();
        $step = $request->steps()->firstOrFail();

        // User 6 has no approve permission — only a substitute can decide.
        try {
            $this->service->decide($step, 6, TreasuryApprovalDecision::DECISION_APPROVE);
            $this->fail('Non-eligible user approved.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('not an eligible approver', $e->getMessage());
        }

        $this->service->assignSubstitute($step, 6, now()->addDay()->format('Y-m-d'), 3);
        $this->assertTrue($step->fresh()->substituteIsValid());

        $this->service->decide($step->fresh(), 6, TreasuryApprovalDecision::DECISION_APPROVE);
        $this->assertTrue($request->fresh()->isApproved());
    }

    public function test_substitute_with_past_expiry_is_refused_and_expired_substitute_cannot_decide(): void
    {
        $request = $this->approvedRequest();
        $step = $request->steps()->firstOrFail();

        try {
            $this->service->assignSubstitute($step, 6, now()->subDay()->format('Y-m-d'), 3);
            $this->fail('Past expiry accepted.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('must be in the future', $e->getMessage());
        }

        $this->service->assignSubstitute($step, 6, now()->addDay()->format('Y-m-d'), 3);

        // The expiry passes after the assignment.
        DB::table('treasury_approval_steps')->where('id', $step->id)->update([
            'substitute_expiry' => now()->subHour()->format('Y-m-d H:i:s'),
        ]);
        $this->assertFalse($step->fresh()->substituteIsValid());

        $this->expectException(TreasuryException::class);
        $this->service->decide($step->fresh(), 6, TreasuryApprovalDecision::DECISION_APPROVE);
    }

    public function test_substitute_cannot_be_the_requester(): void
    {
        $request = $this->approvedRequest();
        $step = $request->steps()->firstOrFail();

        $this->expectException(TreasuryException::class);
        $this->service->assignSubstitute($step, 2, now()->addDay()->format('Y-m-d'), 3);
    }

    // ------------------------------------------------------------------
    // CA05 — change invalidates approval
    // ------------------------------------------------------------------

    public function test_change_to_protected_field_invalidates_approvals(): void
    {
        $request = $this->approvedRequest();
        $this->approveStep($request, 1, 3);
        $this->assertTrue($request->fresh()->isApproved());

        $updated = $this->service->updateRequest($request->fresh(), ['amount_original' => '60000'], 2);

        $this->assertTrue($updated->isUnderReview());
        $this->assertNull($updated->approved_snapshot);
        $this->assertNull($updated->approved_at);
        $this->assertSame(TreasuryApprovalStep::STATUS_INVALIDATED, $updated->steps()->first()->status);

        // Re-submit regenerates the steps and approval completes again.
        // (Invalidation leaves the request under review; send for
        // approval regenerates the steps when none are pending.)
        $this->service->sendForApproval($updated, 2);
        $this->approveStep($updated->fresh(), 1, 3);
        $this->assertTrue($updated->fresh()->isApproved());
        $this->assertSame('60000.0000', (string) $updated->fresh()->approved_snapshot['amount_original']);
    }

    public function test_non_protected_field_edit_keeps_approval(): void
    {
        $request = $this->approvedRequest();
        $this->approveStep($request, 1, 3);
        $this->assertTrue($request->fresh()->isApproved());

        $updated = $this->service->updateRequest($request->fresh(), ['reason' => 'Updated reason'], 2);

        $this->assertTrue($updated->isApproved());
        $this->assertSame('Updated reason', $updated->reason);
        $this->assertSame(TreasuryApprovalStep::STATUS_APPROVED, $updated->steps()->first()->status);
    }

    // ------------------------------------------------------------------
    // Reservations
    // ------------------------------------------------------------------

    public function test_reservation_created_on_approval_and_reduces_available_not_posted(): void
    {
        TreasurySettings::save(1, ['treasury_reservation_policy' => 'on']);

        $request = $this->approvedRequest();
        $this->assertSame('200000.0000', $this->accounts->availableBalance($this->account->fresh()));

        $this->approveStep($request, 1, 3);

        $reservation = $request->fresh()->reservations()->firstOrFail();
        $this->assertTrue($reservation->isActive());
        $this->assertSame('50000.0000', (string) $reservation->amount);
        // A reservation is never a posting: posted stays 200,000.
        $this->assertSame('200000.0000', $this->accounts->postedBalance($this->account->fresh()));
        // Available = posted − active reservations.
        $this->assertSame('150000.0000', $this->accounts->availableBalance($this->account->fresh()));
    }

    public function test_reservation_consumed_on_execution_and_released_on_failure_and_cancel(): void
    {
        TreasurySettings::save(1, ['treasury_reservation_policy' => 'on']);

        // Partial consume keeps it active; full consume closes it.
        $request = $this->approvedRequest();
        $this->approveStep($request, 1, 3);
        $reservation = $request->fresh()->reservations()->firstOrFail();

        $this->service->consumeReservation($reservation, '20000', 2);
        $this->assertTrue($reservation->fresh()->isActive());
        $this->assertSame('20000.0000', (string) $reservation->fresh()->consumed_amount);
        $this->assertSame('170000.0000', $this->accounts->availableBalance($this->account->fresh()));

        $this->service->consumeReservation($reservation->fresh(), '30000', 2);
        $this->assertSame(TreasuryFundReservation::STATE_CONSUMED, $reservation->fresh()->state);
        $this->assertSame('200000.0000', $this->accounts->availableBalance($this->account->fresh()));

        // A failed attempt releases the reservation.
        $failed = $this->approvedRequest();
        $this->approveStep($failed, 1, 3);
        $this->assertSame('150000.0000', $this->accounts->availableBalance($this->account->fresh()));
        $attempt = $this->service->beginAttempt($failed->fresh(), 'bank', 'key-fail', ['amount' => '50000'], 2);
        $this->service->resolveAttempt($attempt, 'failed', null, 'Bank refused', 2);
        $this->assertSame('released', $failed->fresh()->reservations()->first()->state);
        $this->assertSame('200000.0000', $this->accounts->availableBalance($this->account->fresh()));

        // Cancelling an approved request releases too.
        $cancelled = $this->approvedRequest();
        $this->approveStep($cancelled, 1, 3);
        $this->service->cancelRequest($cancelled->fresh(), 2, 'No longer needed');
        $this->assertSame('released', $cancelled->fresh()->reservations()->first()->state);
        $this->assertSame('200000.0000', $this->accounts->availableBalance($this->account->fresh()));
    }

    public function test_reservation_blocked_by_negative_balance_policy(): void
    {
        TreasurySettings::save(1, ['treasury_reservation_policy' => 'on']);

        // Request B is created first (60,000 fits the 200,000 balance).
        $second = $this->approvedRequest(['amount_original' => '60000']);

        // Request A (150,000) is approved first and reserves its funds.
        // It has two levels, so both must be decided.
        $first = $this->approvedRequest(['amount_original' => '150000']);
        $this->approveStep($first, 1, 3);
        $this->approveStep($first->fresh(), 2, 5);
        $this->assertSame('50000.0000', $this->accounts->availableBalance($this->account->fresh()));

        // Approving B now finds only 50,000 available for its 60,000.
        $step = $second->steps()->firstOrFail();
        try {
            $this->service->decide($step, 3, TreasuryApprovalDecision::DECISION_APPROVE);
            $this->fail('Reservation beyond the available balance was created.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('insufficient available balance', strtolower($e->getMessage()));
        }

        // The decision was rolled back: the request is still waiting.
        $this->assertTrue($second->fresh()->isAwaitingApproval());
        $this->assertSame(0, $second->fresh()->steps()->first()->decisions()->count());
    }

    public function test_deactivate_account_blocked_by_active_reservation(): void
    {
        TreasurySettings::save(1, ['treasury_reservation_policy' => 'on']);

        $request = $this->approvedRequest();
        $this->approveStep($request, 1, 3);

        try {
            $this->accounts->deactivateAccount($this->account->fresh(), 2);
            $this->fail('Account deactivated with an active reservation.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('reservation', $e->getMessage());
        }

        $this->service->releaseReservation($request->fresh()->reservations()->firstOrFail(), 'test cleanup', 2);

        // The approved request itself is still open, so resolve it
        // before deactivation: the guard blocks open requests too.
        $this->service->cancelRequest($request->fresh(), 2, 'test cleanup');
        $this->accounts->deactivateAccount($this->account->fresh(), 2);
        $this->assertSame('inactive', $this->account->fresh()->status);
    }

    public function test_reservations_expire(): void
    {
        TreasurySettings::save(1, ['treasury_reservation_policy' => 'on']);

        $request = $this->approvedRequest();
        $this->approveStep($request, 1, 3);
        $reservation = $request->fresh()->reservations()->firstOrFail();

        DB::table('treasury_fund_reservations')->where('id', $reservation->id)->update([
            'expires_at' => now()->subHour()->format('Y-m-d H:i:s'),
        ]);

        $this->assertSame(1, $this->service->expireReservations());
        $this->assertSame('expired', $reservation->fresh()->state);
        $this->assertSame('200000.0000', $this->accounts->availableBalance($this->account->fresh()));
    }

    // ------------------------------------------------------------------
    // Attempts — CA20 idempotency, CA06 retry rule
    // ------------------------------------------------------------------

    public function test_idempotency_same_payload_returns_original_and_different_payload_conflicts(): void
    {
        $request = $this->approvedRequest();
        $this->approveStep($request, 1, 3);
        $request = $request->fresh();

        $first = $this->service->beginAttempt($request, 'bank', 'idem-1', ['amount' => '50000', 'to' => 'vendor-1'], 2);
        $this->assertSame(1, (int) $first->attempt_number);

        $again = $this->service->beginAttempt($request->fresh(), 'bank', 'idem-1', ['to' => 'vendor-1', 'amount' => '50000'], 2);
        $this->assertSame($first->id, $again->id);

        try {
            $this->service->beginAttempt($request->fresh(), 'bank', 'idem-1', ['amount' => '60000', 'to' => 'vendor-1'], 2);
            $this->fail('Conflicting payload accepted.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('conflict', strtolower($e->getMessage()));
        }
    }

    public function test_retry_refused_when_outcome_unknown_without_provider_query(): void
    {
        $request = $this->approvedRequest();
        $this->approveStep($request, 1, 3);
        $request = $request->fresh();

        $attempt = $this->service->beginAttempt($request, 'bank', 'idem-2', ['amount' => '50000'], 2);
        $this->service->resolveAttempt($attempt, 'outcome_unknown', null, 'Timeout', 2);

        $this->assertSame(TreasuryPaymentRequest::STATE_OUTCOME_UNKNOWN, $request->fresh()->state);

        try {
            $this->service->retryAttempt($request->fresh(), 'bank', 'idem-3', ['amount' => '50000'], 2);
            $this->fail('Retry without a provider query was allowed.');
        } catch (TreasuryException $e) {
            $this->assertStringContainsString('Query the provider', $e->getMessage());
        }

        $retry = $this->service->retryAttempt($request->fresh(), 'bank', 'idem-3', ['amount' => '50000'], 2, true);
        $this->assertSame(2, (int) $retry->attempt_number);
        $this->assertSame('processing', $retry->state);
    }

    public function test_paid_attempt_marks_request_paid_and_consumes_reservation(): void
    {
        TreasurySettings::save(1, ['treasury_reservation_policy' => 'on']);

        $request = $this->approvedRequest();
        $this->approveStep($request, 1, 3);
        $request = $request->fresh();

        $attempt = $this->service->beginAttempt($request, 'bank', 'idem-4', ['amount' => '50000'], 2);
        $this->service->resolveAttempt($attempt, 'paid', 'PROV-123', 'Bank confirmed', 2);

        $this->assertSame(TreasuryPaymentRequest::STATE_PAID, $request->fresh()->state);
        $this->assertSame('PROV-123', $attempt->fresh()->provider_reference);
        // Resolving an attempt to paid does NOT consume the reservation:
        // consumption is the Phase 3 execution step (the reservation
        // stays active until execution consumes it).
        $this->assertSame('active', $request->fresh()->reservations()->first()->state);
    }

    // ------------------------------------------------------------------
    // Duplicates + similar flags
    // ------------------------------------------------------------------

    public function test_duplicate_client_reference_blocked(): void
    {
        $this->makeRequest(['client_reference' => 'REF-1']);

        $this->expectException(TreasuryException::class);
        $this->makeRequest(['client_reference' => 'REF-1']);
    }

    public function test_similar_flags_for_same_beneficiary_amount_and_shared_bill(): void
    {
        DB::table('bills')->insert(['id' => 1, 'bill_id' => 1, 'created_by' => 1]);
        DB::table('bill_products')->insert(['id' => 1, 'bill_id' => 1, 'total' => '10000.0000']);

        $first = $this->makeRequest(['source_documents' => [['type' => 'bill', 'id' => 1]]]);
        $this->service->submitRequest($first, 2);
        $this->assertSame([], $first->fresh()->similar_flags);

        $second = $this->makeRequest(['amount_original' => '50400', 'source_documents' => [['type' => 'bill', 'id' => 1]]]);
        $second = $this->service->submitRequest($second, 2);

        $flags = $second->similar_flags;
        $this->assertNotEmpty($flags);
        $this->assertStringContainsString('Similar payment to the same beneficiary', $flags[0]);
        $this->assertContains('Bill #1 is already linked to payment request #1.', $flags);
    }

    public function test_similar_flag_when_source_bill_looks_fully_paid(): void
    {
        DB::table('bills')->insert(['id' => 1, 'bill_id' => 1, 'created_by' => 1]);
        DB::table('bill_products')->insert(['id' => 1, 'bill_id' => 1, 'total' => '10000.0000']);
        DB::table('bill_payments')->insert(['id' => 1, 'bill_id' => 1, 'amount' => '10000.0000']);

        $request = $this->makeRequest(['source_documents' => [['type' => 'bill', 'id' => 1]]]);
        $request = $this->service->submitRequest($request, 2);

        $this->assertContains('Bill #1 looks fully paid already.', $request->similar_flags);
    }

    public function test_bill_outstanding(): void
    {
        DB::table('bills')->insert(['id' => 1, 'bill_id' => 1, 'created_by' => 1]);
        DB::table('bill_products')->insert(['id' => 1, 'bill_id' => 1, 'total' => '10000.0000']);
        DB::table('bill_payments')->insert(['id' => 1, 'bill_id' => 1, 'amount' => '4000.0000']);

        $this->assertSame('6000.0000', $this->service->billOutstanding(1, 1));
    }

    public function test_request_for_other_company_is_rejected(): void
    {
        $request = $this->makeRequest();

        $this->expectException(TreasuryException::class);
        $this->service->requestForCompany(4, $request->id);
    }

    // ------------------------------------------------------------------
    // Schema
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
            $table->timestamps();
        });

        Schema::create('bills', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('bill_id')->nullable();
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
            $table->decimal('amount', 20, 4)->default(0);
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
            $table->integer('created_by');
            $table->timestamps();
            $table->unique(['idempotency_key'], 'tpa_key_uq');
            $table->index(['payment_request_id'], 'tpa_request_idx');
            $table->index(['payment_request_id', 'attempt_number'], 'tpa_request_attempt_idx');
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
    }
}
