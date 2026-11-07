<?php

namespace Tests\Unit;

use App\Exceptions\TreasuryException;
use App\Models\TreasuryAdvance;
use App\Models\TreasuryAdvanceExpenseLine;
use App\Models\TreasuryFinancialAccount;
use App\Services\TreasuryAccountService;
use App\Services\TreasuryAdvanceService;
use App\Services\TreasurySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Treasury Phase 6 — advances (plan §5, §6, §10, exit gate CA10).
 *
 * Users: 1 = company, 2 = requester, 3 = approver (holds
 * 'manage treasury advances'), 4 = other company, 5 = colleague
 * (same company, no permission). The source account is funded
 * with 200,000 MZN by default; chart accounts are 121 (source
 * bank), 4512 (employee advance asset), 429 (supplier advance
 * asset), 61 and 62 (expense accounts).
 */
class TreasuryAdvanceServiceTest extends TestCase
{
    protected TreasuryAccountService $accounts;
    protected TreasuryAdvanceService $service;
    protected TreasuryFinancialAccount $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Requester', 'email' => 'requester@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 5, 'name' => 'Colleague', 'email' => 'colleague@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Maputo', 'created_by' => 1],
        ]);

        DB::table('permissions')->insert([
            ['id' => 1, 'name' => 'manage treasury advances', 'guard_name' => 'web'],
        ]);
        DB::table('model_has_permissions')->insert([
            'permission_id' => 1, 'model_type' => 'App\\Models\\User', 'model_id' => 3,
        ]);

        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'code' => 121, 'name' => 'Bank source', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 2, 'code' => 4512, 'name' => 'Advances to employees', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 3, 'code' => 429, 'name' => 'Advances to suppliers', 'type' => 1, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 4, 'code' => 61, 'name' => 'Travel expenses', 'type' => 6, 'sub_type' => 1, 'created_by' => 1],
            ['id' => 5, 'code' => 62, 'name' => 'Meals', 'type' => 6, 'sub_type' => 1, 'created_by' => 1],
        ]);

        DB::table('employees')->insert([
            ['id' => 1, 'name' => 'Ana', 'created_by' => 1],
            ['id' => 2, 'name' => 'Bruno', 'created_by' => 4],
        ]);
        DB::table('venders')->insert([
            ['id' => 1, 'name' => 'Fornecedor X', 'created_by' => 1],
        ]);
        DB::table('cost_centres')->insert([
            ['id' => 1, 'company_id' => 1, 'created_by' => 1, 'code' => 'CC-OPS', 'name' => 'Operations', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->accounts = new TreasuryAccountService();
        $this->service = new TreasuryAdvanceService($this->accounts);
        $this->source = $this->makeAccount('200000.0000', 'BIM-001', 'MZN', 'bank', 1);
    }

    protected function tearDown(): void
    {
        foreach (['treasury_advance_expense_lines', 'treasury_advances', 'treasury_line_dimensions', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'dimension_audit_events', 'bank_accounts', 'chart_of_accounts', 'cost_centres', 'venders', 'employees', 'branches', 'settings', 'users', 'permissions', 'model_has_permissions', 'journal_entries', 'journal_items', 'transaction_lines', 'transactions'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function makeAccount(string $balance, string $code, string $currency = 'MZN', string $type = 'bank', int $chartId = 1, ?string $balanceBase = null): TreasuryFinancialAccount
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
            'financial_account_id' => $account->id, 'created_by' => 1, 'currency' => $currency,
            'amount' => $balance, 'amount_base' => $balanceBase ?? $balance, 'cut_off_date' => '2026-11-01',
            'batch_reference' => 'BATCH-' . $code, 'status' => 'approved',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $account->fresh();
    }

    protected function advanceData(array $overrides = []): array
    {
        return array_merge([
            'source_account_id' => $this->source->id,
            'recipient_type' => 'employee',
            'recipient_id' => 1,
            'purpose' => 'Travel to Beira',
            'amount_requested' => '5000',
            'due_date' => date('Y-m-d', strtotime('+14 days')),
        ], $overrides);
    }

    protected function makeAdvance(array $overrides = [], int $requester = 2): TreasuryAdvance
    {
        return $this->service->createAdvance(1, $this->advanceData($overrides), $requester);
    }

    protected function makeDisbursedAdvance(array $overrides = [], int $requester = 2, string $amount = '5000'): TreasuryAdvance
    {
        $advance = $this->makeAdvance(array_merge(['amount_requested' => $amount], $overrides), $requester);
        $this->service->submitAdvance(1, $advance->id, $requester);
        $this->service->approveAdvance(1, $advance->id, 3);
        $result = $this->service->disburseAdvance(1, $advance->id, 3);

        return $result['advance'];
    }

    protected function lineData(array $overrides = []): array
    {
        return array_merge([
            'expense_date' => date('Y-m-d'),
            'counterparty' => 'Hotel Beira',
            'category' => 'Travel',
            'document' => 'INV-001',
            'amount' => '4200',
            'chart_account_id' => 4,
            'evidence' => 'Receipt attached',
        ], $overrides);
    }

    protected function journalItems(int $journalId)
    {
        return DB::table('journal_items')->where('journal', $journalId)->orderBy('id')->get();
    }

    /** Journals whose lines touch a chart account (2dp amounts). */
    protected function journalsTouchingAccount(int $chartAccountId): array
    {
        return DB::table('journal_items')->where('account', $chartAccountId)->pluck('journal')->all();
    }

    // ------------------------------------------------------------------
    // Happy path + posting
    // ------------------------------------------------------------------

    public function test_request_approve_disburse_happy_path_posts_balanced_journal(): void
    {
        $advance = $this->makeAdvance();
        $this->assertSame('draft', $advance->state);
        // A draft operation is created at request time; the
        // reference is only allocated on disbursement.
        $this->assertNotNull($advance->operation_id);
        $this->assertNull($advance->operation->reference);
        $this->assertSame('draft', $advance->operation->state);

        $advance = $this->service->submitAdvance(1, $advance->id, 2);
        $this->assertSame('pending_approval', $advance->state);

        $advance = $this->service->approveAdvance(1, $advance->id, 3);
        $this->assertSame('approved', $advance->state);
        $this->assertSame('5000.0000', (string) $advance->amount_approved);

        $result = $this->service->disburseAdvance(1, $advance->id, 3);
        $advance = $result['advance'];
        $operation = $result['operation'];

        $this->assertSame('disbursed', $advance->state);
        $this->assertSame('COMP-ADV-2026-0001', $operation->reference);
        $this->assertSame('confirmed', $operation->state);
        $this->assertSame('5000.0000', (string) $advance->amount_paid);
        $this->assertSame('5000.0000', (string) $advance->amount_outstanding);

        // The disbursement journal is balanced: Dr 4512 / Cr 121.
        $items = $this->journalItems((int) $advance->disbursement_journal_id);
        $this->assertCount(2, $items);
        $this->assertSame(2, (int) $items[0]->account);
        $this->assertSame(5000.0, (float) $items[0]->debit);
        $this->assertSame(1, (int) $items[1]->account);
        $this->assertSame(5000.0, (float) $items[1]->credit);

        // One outflow entry; the source balance dropped by exactly 5,000.
        $this->assertSame(1, DB::table('treasury_entries')->where('operation_id', $operation->id)->count());
        $this->assertSame('195000.0000', $this->accounts->postedBalance($this->source->fresh()));
    }

    public function test_supplier_advance_uses_supplier_asset_account(): void
    {
        $advance = $this->makeDisbursedAdvance(['recipient_type' => 'supplier', 'recipient_id' => 1]);

        $items = $this->journalItems((int) $advance->disbursement_journal_id);
        $this->assertCount(2, $items);
        $this->assertSame(3, (int) $items[0]->account); // 429
        $this->assertSame(5000.0, (float) $items[0]->debit);
        $this->assertSame('Fornecedor X', $advance->recipient_name);
    }

    public function test_disburse_before_approval_is_blocked(): void
    {
        $draft = $this->makeAdvance();
        $this->expectException(TreasuryException::class);
        $this->service->disburseAdvance(1, $draft->id, 3);
    }

    public function test_disburse_pending_approval_is_blocked(): void
    {
        $advance = $this->makeAdvance();
        $this->service->submitAdvance(1, $advance->id, 2);

        $this->expectException(TreasuryException::class);
        $this->service->disburseAdvance(1, $advance->id, 3);
    }

    public function test_self_approval_is_blocked(): void
    {
        $advance = $this->makeAdvance();
        $this->service->submitAdvance(1, $advance->id, 2);

        $this->expectException(TreasuryException::class);
        $this->service->approveAdvance(1, $advance->id, 2);
    }

    public function test_approve_without_permission_is_blocked(): void
    {
        $advance = $this->makeAdvance();
        $this->service->submitAdvance(1, $advance->id, 2);

        // User 5 is in the company but holds no advance permission.
        $this->expectException(TreasuryException::class);
        $this->service->approveAdvance(1, $advance->id, 5);
    }

    public function test_disburse_with_insufficient_funds_is_blocked(): void
    {
        $advance = $this->makeDisbursedAdvance([], 2, '5000');
        $this->assertSame('disbursed', $advance->state);

        $big = $this->makeAdvance(['amount_requested' => '300000']);
        $this->service->submitAdvance(1, $big->id, 2);
        $this->service->approveAdvance(1, $big->id, 3);

        $this->expectException(TreasuryException::class);
        $this->service->disburseAdvance(1, $big->id, 3);
    }

    public function test_reject_and_return_to_draft(): void
    {
        $advance = $this->makeAdvance();
        $this->service->submitAdvance(1, $advance->id, 2);

        $advance = $this->service->rejectAdvance(1, $advance->id, 3, 'Purpose is unclear.');
        $this->assertSame('rejected', $advance->state);
        $this->assertSame('Purpose is unclear.', $advance->rejection_reason);

        $advance = $this->service->returnAdvanceToDraft(1, $advance->id, 2);
        $this->assertSame('draft', $advance->state);
        $this->assertNull($advance->rejection_reason);

        $advance = $this->service->updateAdvance($advance, $this->advanceData(['purpose' => 'Travel to Beira, clarified']), 2);
        $this->assertSame('Travel to Beira, clarified', $advance->purpose);
    }

    public function test_reject_without_reason_is_blocked(): void
    {
        $advance = $this->makeAdvance();
        $this->service->submitAdvance(1, $advance->id, 2);

        $this->expectException(TreasuryException::class);
        $this->service->rejectAdvance(1, $advance->id, 3, '');
    }

    public function test_recipient_from_another_company_is_blocked(): void
    {
        $this->expectException(TreasuryException::class);
        $this->makeAdvance(['recipient_id' => 2]); // Bruno belongs to company 4.
    }

    public function test_foreign_currency_advance_converts_to_base(): void
    {
        $usd = $this->makeAccount('10000.0000', 'BIM-USD', 'USD', 'bank', 1, '635000.0000');

        $advance = $this->service->createAdvance(1, $this->advanceData([
            'source_account_id' => $usd->id,
            'amount_requested' => '100',
            'exchange_rate' => '63.5',
            'rate_source' => 'manual',
            'rate_date' => '2026-11-01',
        ]), 2);
        $this->assertSame('6350.0000', (string) $advance->amount_requested_base);
        $this->assertSame('63.5000000000', (string) $advance->exchange_rate);

        $this->service->submitAdvance(1, $advance->id, 2);
        $this->service->approveAdvance(1, $advance->id, 3);
        $result = $this->service->disburseAdvance(1, $advance->id, 3);

        // The journal posts the base amount: Dr 4512 6,350 / Cr 121 6,350.
        $items = $this->journalItems((int) $result['advance']->disbursement_journal_id);
        $this->assertSame(6350.0, (float) $items[0]->debit);
        $this->assertSame(6350.0, (float) $items[1]->credit);
        $this->assertSame('628650.0000', $this->accounts->postedBalance($usd->fresh()));
    }

    // ------------------------------------------------------------------
    // Substantiation
    // ------------------------------------------------------------------

    public function test_approve_line_posts_expense_and_moves_advance_to_expense_account(): void
    {
        $advance = $this->makeDisbursedAdvance();

        $line = $this->service->addLine(1, $advance->id, $this->lineData(), 2);
        $this->assertSame('pending', $line->state);

        $result = $this->service->reviewLine(1, $line->id, 'approve', 3);
        $advance = $result['advance'];
        $line = $result['line'];

        $this->assertSame('approved', $line->state);
        $this->assertSame('4200.0000', (string) $advance->amount_substantiated);
        $this->assertSame('800.0000', (string) $advance->amount_outstanding);
        $this->assertSame('partially_substantiated', $advance->state);

        // Dr 61 4,200 / Cr 4512 4,200 — and NO treasury entry (no
        // account moves, the money already left at disbursement).
        $items = $this->journalItems((int) $line->journal_id);
        $this->assertCount(2, $items);
        $this->assertSame(4, (int) $items[0]->account); // 61
        $this->assertSame(4200.0, (float) $items[0]->debit);
        $this->assertSame(2, (int) $items[1]->account); // 4512
        $this->assertSame(4200.0, (float) $items[1]->credit);
        $this->assertSame(1, DB::table('treasury_entries')->count());
        $this->assertSame('195000.0000', $this->accounts->postedBalance($this->source->fresh()));
    }

    public function test_reject_line_needs_reason_and_posts_nothing(): void
    {
        $advance = $this->makeDisbursedAdvance();
        $line = $this->service->addLine(1, $advance->id, $this->lineData(), 2);

        try {
            $this->service->reviewLine(1, $line->id, 'reject', 3);
            $this->fail('Rejecting without a reason must throw.');
        } catch (TreasuryException) {
            // Expected.
        }

        $result = $this->service->reviewLine(1, $line->id, 'reject', 3, 'Receipt is unreadable.');
        $this->assertSame('rejected', $result['line']->state);
        $this->assertSame('5000.0000', (string) $result['advance']->amount_outstanding);
        $this->assertNull($result['line']->journal_id);
        $this->assertSame(1, DB::table('journal_entries')->count()); // only the disbursement journal
    }

    public function test_return_line_then_edit_resubmit_and_approve(): void
    {
        $advance = $this->makeDisbursedAdvance();
        $line = $this->service->addLine(1, $advance->id, $this->lineData(['amount' => '1000']), 2);

        $result = $this->service->reviewLine(1, $line->id, 'return', 3, 'Fix the amount.');
        $this->assertSame('returned', $result['line']->state);
        $this->assertSame('under_review', $result['advance']->state);

        // The recipient cannot resubmit until the line is fixed.
        $line = $this->service->updateLine($result['line'], $this->lineData(['amount' => '1500', 'document' => 'INV-002']), 2);
        $this->assertSame('1500.0000', (string) $line->amount);
        $this->assertSame('returned', $line->state);

        $line = $this->service->resubmitLine(1, $line->id, 2);
        $this->assertSame('pending', $line->state);

        $result = $this->service->reviewLine(1, $line->id, 'approve', 3);
        $this->assertSame('approved', $result['line']->state);
        $this->assertSame('1500.0000', (string) $result['advance']->amount_substantiated);
        $this->assertSame('3500.0000', (string) $result['advance']->amount_outstanding);
    }

    public function test_approving_a_line_twice_is_blocked(): void
    {
        $advance = $this->makeDisbursedAdvance();
        $line = $this->service->addLine(1, $advance->id, $this->lineData(), 2);
        $this->service->reviewLine(1, $line->id, 'approve', 3);

        $this->expectException(TreasuryException::class);
        $this->service->reviewLine(1, $line->id, 'approve', 3);
    }

    public function test_line_with_cost_centre_is_tagged_in_treasury_line_dimensions(): void
    {
        $advance = $this->makeDisbursedAdvance();
        $line = $this->service->addLine(1, $advance->id, $this->lineData(['cost_centre_id' => 1]), 2);
        $this->service->reviewLine(1, $line->id, 'approve', 3);

        $dimension = DB::table('treasury_line_dimensions')->where('payment_type', 'treasury_advance')->where('payment_id', $line->id)->first();
        $this->assertNotNull($dimension);
        $this->assertSame(1, (int) $dimension->cost_centre_id);
        $this->assertSame(4200.0, (float) $dimension->allocated_amount);
        $this->assertSame('manual', $dimension->source);
        $this->assertSame('active', $dimension->status);
    }

    public function test_line_without_cost_centre_tags_nothing(): void
    {
        $advance = $this->makeDisbursedAdvance();
        $line = $this->service->addLine(1, $advance->id, $this->lineData(), 2);
        $this->service->reviewLine(1, $line->id, 'approve', 3);

        $this->assertSame(0, DB::table('treasury_line_dimensions')->count());
    }

    // ------------------------------------------------------------------
    // CA10 + regularisation + reimbursement
    // ------------------------------------------------------------------

    /** CA10: 5,000 advance, 4,200 approved expenses, 800 return clears the balance with NO second expense outflow. */
    public function test_ca10_return_clears_balance_with_no_second_expense_outflow(): void
    {
        $advance = $this->makeDisbursedAdvance();
        $line = $this->service->addLine(1, $advance->id, $this->lineData(['amount' => '4200']), 2);
        $this->service->reviewLine(1, $line->id, 'approve', 3);

        $result = $this->service->returnUnspent(1, $advance->id, '800', 3, 'Unspent travel money');
        $advance = $result['advance'];
        $returnJournal = $result['journal'];

        $this->assertSame('0.0000', (string) $advance->amount_outstanding);
        $this->assertSame('regularised', $advance->state);
        $this->assertSame('800.0000', (string) $advance->amount_returned);

        // The return journal is Dr 121 / Cr 4512 — the expense
        // account 61 appears in exactly ONE journal (the 4,200
        // substantiation), so no second expense outflow exists.
        $expenseJournals = $this->journalsTouchingAccount(4);
        $this->assertCount(1, $expenseJournals);
        $this->assertSame(4200.0, (float) DB::table('journal_items')->where('account', 4)->sum('debit'));

        $returnItems = $this->journalItems((int) $returnJournal->id);
        $this->assertCount(2, $returnItems);
        $this->assertSame(1, (int) $returnItems[0]->account); // 121
        $this->assertSame(800.0, (float) $returnItems[0]->debit);
        $this->assertSame(2, (int) $returnItems[1]->account); // 4512
        $this->assertSame(800.0, (float) $returnItems[1]->credit);

        // Source balance: 200,000 − 5,000 + 800 = 195,800.
        $this->assertSame('195800.0000', $this->accounts->postedBalance($this->source->fresh()));

        // And it closes cleanly.
        $advance = $this->service->closeAdvance(1, $advance->id, 3);
        $this->assertSame('closed', $advance->state);
    }

    public function test_return_more_than_outstanding_is_blocked(): void
    {
        $advance = $this->makeDisbursedAdvance();

        $this->expectException(TreasuryException::class);
        $this->service->returnUnspent(1, $advance->id, '5001', 3);
    }

    public function test_excess_over_advance_becomes_a_separate_reimbursement(): void
    {
        $advance = $this->makeDisbursedAdvance();

        $line1 = $this->service->addLine(1, $advance->id, $this->lineData(['amount' => '3000', 'chart_account_id' => 4]), 2);
        $this->service->reviewLine(1, $line1->id, 'approve', 3);
        $line2 = $this->service->addLine(1, $advance->id, $this->lineData(['amount' => '2300', 'chart_account_id' => 5, 'document' => 'INV-002']), 2);
        $result = $this->service->reviewLine(1, $line2->id, 'approve', 3);
        $advance = $result['advance'];

        $this->assertSame('5300.0000', (string) $advance->amount_substantiated);
        $this->assertSame('0.0000', (string) $advance->amount_outstanding);
        $this->assertSame('300.0000', (string) $advance->reimbursement_amount);
        $this->assertSame('pending', $advance->reimbursement_state);
        $this->assertSame('regularised', $advance->state);

        $advance = $this->service->approveReimbursement(1, $advance->id, 3);
        $this->assertSame('approved', $advance->reimbursement_state);

        $result = $this->service->payReimbursement(1, $advance->id, 3);
        $advance = $result['advance'];
        $this->assertSame('paid', $advance->reimbursement_state);
        $this->assertSame('300.0000', (string) $advance->reimbursement_amount);

        // One Dr line per expense account, Cr source 300 total.
        // The excess sits on the second line (chart 62 — the 2,300
        // line, of which only 2,000 is covered by the advance).
        $items = $this->journalItems((int) $advance->reimbursement_journal_id ?? 0);
        $this->assertCount(2, $items);
        $this->assertSame(5, (int) $items[0]->account); // 62
        $this->assertSame(300.0, (float) $items[0]->debit);
        $this->assertSame(1, (int) $items[1]->account); // 121
        $this->assertSame(300.0, (float) $items[1]->credit);

        // Source balance: 200,000 − 5,000 − 300 = 194,700.
        $this->assertSame('194700.0000', $this->accounts->postedBalance($this->source->fresh()));
    }

    public function test_reject_reimbursement_keeps_the_excess_unpaid(): void
    {
        $advance = $this->makeDisbursedAdvance();
        $line = $this->service->addLine(1, $advance->id, $this->lineData(['amount' => '5300']), 2);
        $this->service->reviewLine(1, $line->id, 'approve', 3);

        $advance = $this->service->rejectReimbursement(1, $advance->id, 3, 'Excess is not company policy.');
        $this->assertSame('rejected', $advance->reimbursement_state);
        $this->assertSame('300.0000', (string) $advance->reimbursement_amount);
        $this->assertSame('regularised', $advance->state);
        $this->assertNull($advance->reimbursement_journal_id);
    }

    public function test_pay_reimbursement_before_approval_is_blocked(): void
    {
        $advance = $this->makeDisbursedAdvance();
        $line = $this->service->addLine(1, $advance->id, $this->lineData(['amount' => '5300']), 2);
        $this->service->reviewLine(1, $line->id, 'approve', 3);

        $this->expectException(TreasuryException::class);
        $this->service->payReimbursement(1, $advance->id, 3);
    }

    // ------------------------------------------------------------------
    // Close rules, overdue, block
    // ------------------------------------------------------------------

    public function test_close_blocked_while_reimbursement_open(): void
    {
        $advance = $this->makeDisbursedAdvance();
        $line = $this->service->addLine(1, $advance->id, $this->lineData(['amount' => '5300']), 2);
        $this->service->reviewLine(1, $line->id, 'approve', 3);

        $advance = $advance->fresh();
        $this->assertSame('regularised', $advance->state);
        $this->assertSame('pending', $advance->reimbursement_state);

        $this->expectException(TreasuryException::class);
        $this->service->closeAdvance(1, $advance->id, 3);
    }

    public function test_close_blocked_while_lines_unresolved(): void
    {
        $advance = $this->makeDisbursedAdvance();
        $this->service->addLine(1, $advance->id, $this->lineData(), 2);

        $this->expectException(TreasuryException::class);
        $this->service->closeAdvance(1, $advance->id, 3);
    }

    public function test_close_blocked_while_balance_outstanding(): void
    {
        $advance = $this->makeDisbursedAdvance();

        $this->expectException(TreasuryException::class);
        $this->service->closeAdvance(1, $advance->id, 3);
    }

    public function test_close_blocked_from_draft(): void
    {
        $advance = $this->makeAdvance();

        $this->expectException(TreasuryException::class);
        $this->service->closeAdvance(1, $advance->id, 3);
    }

    public function test_overdue_flag(): void
    {
        $overdue = $this->makeDisbursedAdvance(['due_date' => date('Y-m-d', strtotime('-1 day'))]);
        $this->assertTrue($overdue->isOverdue());

        $fresh = $this->makeDisbursedAdvance(['due_date' => date('Y-m-d', strtotime('+1 day'))]);
        $this->assertFalse($fresh->isOverdue());

        $undated = $this->makeDisbursedAdvance(['due_date' => null]);
        $this->assertFalse($undated->isOverdue());
    }

    public function test_block_on_outstanding_off_by_default_then_on_when_configured(): void
    {
        // Off by default: a second advance for the same recipient works.
        $first = $this->makeDisbursedAdvance();
        $second = $this->makeAdvance(['purpose' => 'Second trip']);
        $this->assertSame('draft', $second->state);
        $this->assertSame(1, (int) $second->recipient_id);

        // Turn the block on: a third request is refused while the
        // first advance is still outstanding.
        TreasurySettings::save(1, ['treasury_advance_block_on_outstanding' => '1']);
        try {
            $this->makeAdvance(['purpose' => 'Third trip']);
            $this->fail('A new advance must be blocked while one is outstanding.');
        } catch (TreasuryException) {
            // Expected.
        }

        // Regularise the first advance and the block lifts.
        $line = $this->service->addLine(1, $first->id, $this->lineData(['amount' => '5000']), 2);
        $this->service->reviewLine(1, $line->id, 'approve', 3);
        $third = $this->makeAdvance(['purpose' => 'Third trip']);
        $this->assertSame('draft', $third->state);
    }

    public function test_block_does_not_apply_to_a_different_recipient(): void
    {
        $this->makeDisbursedAdvance();
        TreasurySettings::save(1, ['treasury_advance_block_on_outstanding' => '1']);

        $other = $this->makeAdvance(['recipient_type' => 'supplier', 'recipient_id' => 1, 'purpose' => 'Supplier advance']);
        $this->assertSame('draft', $other->state);
    }

    // ------------------------------------------------------------------
    // Company scope + preview
    // ------------------------------------------------------------------

    public function test_cross_company_access_is_denied(): void
    {
        $advance = $this->makeDisbursedAdvance();

        $this->expectException(TreasuryException::class);
        $this->service->advanceForCompany(4, $advance->id);
    }

    public function test_preview_disburse_reports_checks_and_amounts(): void
    {
        $advance = $this->makeAdvance();
        $this->service->submitAdvance(1, $advance->id, 2);
        $this->service->approveAdvance(1, $advance->id, 3);

        $preview = $this->service->previewDisburse(1, $advance->id, 3);
        $this->assertSame('5000.0000', (string) $preview['advance']->amount_approved);
        $this->assertSame('200000.0000', (string) $preview['available_balance']);
        $this->assertTrue($preview['can_disburse']);
        $this->assertTrue(collect($preview['checks'])->every(fn ($check) => $check['passed'] === true));
    }

    public function test_audit_events_are_written(): void
    {
        $advance = $this->makeDisbursedAdvance();

        $events = DB::table('dimension_audit_events')
            ->where('entity_type', 'treasury_advance')
            ->where('entity_id', $advance->id)
            ->pluck('action')
            ->all();
        $this->assertContains('created', $events);
        $this->assertContains('approved', $events);
        $this->assertContains('disbursed', $events);
    }

    // ------------------------------------------------------------------
    // Tables
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

        Schema::create('employees', function ($table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->default(0);
            $table->string('name')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('cost_centres', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('code', 50);
            $table->string('name');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('treasury_line_dimensions', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('payment_type', 20);
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->unsignedBigInteger('cost_centre_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->decimal('allocated_amount', 18, 2);
            $table->decimal('percentage', 9, 6)->nullable();
            $table->string('source', 20)->default('inherited');
            $table->string('status', 20)->default('active');
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

        Schema::create('treasury_advances', function ($table) {
            $table->id();
            $table->bigInteger('operation_id')->nullable();
            $table->integer('created_by');
            $table->bigInteger('branch_id')->nullable();
            $table->bigInteger('source_account_id');
            $table->string('recipient_type', 20);
            $table->bigInteger('recipient_id');
            $table->string('recipient_name', 150)->nullable();
            $table->text('purpose')->nullable();
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->decimal('amount_requested', 20, 4)->default(0);
            $table->decimal('amount_requested_base', 20, 4)->default(0);
            $table->decimal('amount_approved', 20, 4)->nullable();
            $table->decimal('amount_approved_base', 20, 4)->nullable();
            $table->decimal('amount_paid', 20, 4)->default(0);
            $table->decimal('amount_paid_base', 20, 4)->default(0);
            $table->decimal('amount_substantiated', 20, 4)->default(0);
            $table->decimal('amount_substantiated_base', 20, 4)->default(0);
            $table->decimal('amount_returned', 20, 4)->default(0);
            $table->decimal('amount_returned_base', 20, 4)->default(0);
            $table->decimal('amount_outstanding', 20, 4)->default(0);
            $table->decimal('amount_outstanding_base', 20, 4)->default(0);
            $table->decimal('reimbursement_amount', 20, 4)->default(0);
            $table->decimal('reimbursement_amount_base', 20, 4)->default(0);
            $table->string('reimbursement_state', 20)->default('none');
            $table->bigInteger('reimbursement_approved_by')->nullable();
            $table->timestamp('reimbursement_approved_at')->nullable();
            $table->timestamp('reimbursement_paid_at')->nullable();
            $table->bigInteger('reimbursement_journal_id')->nullable();
            $table->bigInteger('reimbursement_operation_id')->nullable();
            $table->text('reimbursement_reason')->nullable();
            $table->date('request_date')->nullable();
            $table->date('due_date')->nullable();
            $table->integer('requested_by');
            $table->integer('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('disbursed_at')->nullable();
            $table->bigInteger('disbursement_journal_id')->nullable();
            $table->string('state', 20)->default('draft');
            $table->text('close_reason')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->integer('closed_by')->nullable();
            $table->timestamps();
            $table->index(['created_by', 'state'], 'ta_company_state_idx');
            $table->index(['source_account_id'], 'ta_source_idx');
            $table->index(['recipient_type', 'recipient_id'], 'ta_recipient_idx');
            $table->index(['operation_id'], 'ta_operation_idx');
        });

        Schema::create('treasury_advance_expense_lines', function ($table) {
            $table->id();
            $table->bigInteger('advance_id');
            $table->date('expense_date');
            $table->string('counterparty', 150)->nullable();
            $table->string('category', 100)->nullable();
            $table->string('document', 150)->nullable();
            $table->decimal('amount', 20, 4)->default(0);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->bigInteger('chart_account_id');
            $table->bigInteger('cost_centre_id')->nullable();
            $table->text('evidence')->nullable();
            $table->string('state', 20)->default('pending');
            $table->integer('submitted_by');
            $table->integer('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->bigInteger('journal_id')->nullable();
            $table->bigInteger('operation_id')->nullable();
            $table->decimal('reimbursement_amount', 20, 4)->default(0);
            $table->decimal('reimbursement_amount_base', 20, 4)->default(0);
            $table->timestamps();
            $table->index(['advance_id'], 'tael_advance_idx');
            $table->index(['advance_id', 'state'], 'tael_adv_state_idx');
            $table->index(['cost_centre_id'], 'tael_centre_idx');
        });
    }
}
