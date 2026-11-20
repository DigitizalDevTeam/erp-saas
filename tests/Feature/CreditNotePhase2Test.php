<?php

namespace Tests\Feature;

use App\Models\CreditNote;
use App\Models\CreditNoteRefund;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\TreasuryEntry;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryOperation;
use App\Models\User;
use App\Services\CreditNoteRefundService;
use App\Services\TreasuryAccountService;
use App\Services\TreasurySettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Credit Notes Phase 2 (2026-11-19) — Treasury Reembolso + approval
 * option (client answers 2026-11-19).
 *
 * Refund rules under test:
 * - one refund per note, full note amount only, disposition 'refund',
 *   note must be approved (approval setting ON -> pending until approved).
 * - source: any active company bank account (transfer) or petty cash /
 *   cash register (cash); cash may be recorded against an open till
 *   session. Currency must be the base currency.
 * - posting: confirmed TYPE_PAYMENT operation (source credit_note),
 *   outflow entry, journal Dr 411 / Cr bank chart, Treasury balance drops.
 * - transfer needs its transfer receipt attached; cash slip is a
 *   numbered printable PDF (operation reference).
 */
class CreditNotePhase2Test extends TestCase
{
    private User $user;
    private int $customerId;
    private int $productId;
    private int $nextInvoiceNumber = 2001;
    private int $bankChartId;
    private int $receivableChartId;
    private int $bankAccountId;
    private int $treasuryAccountId;
    private int $cashRegisterAccountId;
    private int $cashBankAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->createTables();
        $this->seedPermissions();
        $this->seedBaseRows();
        $this->user = $this->makeCompanyUser();
        $this->user->givePermissionTo(['create credit note', 'edit credit note', 'delete credit note', 'manage credit note', 'approve credit note', 'manage credit note refund']);
        $this->actingAs($this->user);

        $this->productId = DB::table('product_services')->insertGetId([
            'name' => 'Widget', 'sku' => 'W1', 'sale_price' => 100,
            'purchase_price' => 50, 'quantity' => 100, 'type' => 'product',
            'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->customerId = DB::table('customers')->insertGetId([
            'customer_id' => 1, 'name' => 'Test Customer', 'email' => 'customer@test.local',
            'created_by' => $this->user->id, 'balance' => 0, 'credit_balance' => 0,
            'is_walk_in' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->receivableChartId = $this->makeChartAccount(411, 'Accounts receivable');
        $this->bankChartId = $this->makeChartAccount(121, 'Bank');
        $this->bankAccountId = $this->makeBankAccount($this->bankChartId, 'BCI', '1001');
        $this->treasuryAccountId = $this->makeTreasuryAccount($this->bankAccountId, 'BCI-001', TreasuryFinancialAccount::TYPE_BANK, 10000);
        $this->cashBankAccountId = $this->makeBankAccount($this->bankChartId, 'Till', 'CASH-1');
        $this->cashRegisterAccountId = $this->makeTreasuryAccount($this->cashBankAccountId, 'TILL-001', TreasuryFinancialAccount::TYPE_CASH_REGISTER, 5000);
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['customer_credit_ledger', 'credit_note_refunds', 'treasury_cash_sessions', 'treasury_petty_cash_funds', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'bank_accounts', 'chart_of_accounts', 'transaction_lines', 'journal_items', 'journal_entries', 'credit_notes', 'invoice_payments', 'invoice_products', 'invoices', 'product_services', 'taxes', 'customers', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'settings', 'languages', 'email_templates', 'ch_messages', 'plans', 'branches', 'branch_user'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Bank transfer refund — happy path
    // ------------------------------------------------------------------

    public function test_bank_transfer_refund_posts_operation_entry_and_journal(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($invoice->id, 400, 'refund', 1);

        $refund = $this->refundService()->refundNote($this->user->id, $noteId, $this->user->id, [
            'method' => 'transfer',
            'financial_account_id' => $this->treasuryAccountId,
            'transfer_receipt' => 'transfer-proof.pdf',
        ]);

        $this->assertNotNull($refund->id);
        $this->assertSame($noteId, (int) $refund->credit_note_id);
        $this->assertSame('transfer', $refund->method);
        $this->assertSame('transfer-proof.pdf', $refund->transfer_receipt);

        $operation = TreasuryOperation::find($refund->treasury_operation_id);
        $this->assertNotNull($operation);
        $this->assertSame(TreasuryOperation::TYPE_PAYMENT, $operation->type);
        $this->assertSame(TreasuryOperation::STATE_CONFIRMED, $operation->state);
        $this->assertSame('credit_note', $operation->source_type);
        $this->assertSame($noteId, (int) $operation->source_id);
        $this->assertSame('customer', $operation->counterparty_type);
        $this->assertSame($this->customerId, (int) $operation->counterparty_id);
        $this->assertMatchesRegularExpression('/^TEST-PAY-\d{4}-\d{4}$/', (string) $operation->reference);
        $this->assertEquals(400, $operation->amount_original);

        $entry = TreasuryEntry::where('operation_id', $operation->id)->first();
        $this->assertNotNull($entry);
        $this->assertSame(TreasuryEntry::DIRECTION_OUTFLOW, $entry->direction);
        $this->assertEquals(400, $entry->amount_base);
        $this->assertSame($refund->journal_id, (int) $entry->journal_id);

        // Treasury balance drops by the refund.
        $account = TreasuryFinancialAccount::find($this->treasuryAccountId);
        $this->assertEquals('9600.0000', (new TreasuryAccountService())->postedBalance($account));

        // Journal: Dr 411 / Cr bank chart.
        $journal = JournalEntry::find($refund->journal_id);
        $this->assertNotNull($journal);
        $items = DB::table('journal_items')->where('journal', $journal->id)->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertSame($this->receivableChartId, (int) $items[0]->account);
        $this->assertEquals(400, $items[0]->debit);
        $this->assertSame($this->bankChartId, (int) $items[1]->account);
        $this->assertEquals(400, $items[1]->credit);

        // A refund never touches the customer's credit balance.
        $this->assertEquals(0, Customer::find($this->customerId)->credit_balance);
    }

    public function test_second_refund_for_same_note_is_refused(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($invoice->id, 400, 'refund', 1);

        $this->refundService()->refundNote($this->user->id, $noteId, $this->user->id, [
            'method' => 'transfer',
            'financial_account_id' => $this->treasuryAccountId,
            'transfer_receipt' => 'transfer-proof.pdf',
        ]);

        $this->expectExceptionMessage('already been refunded');
        $this->refundService()->refundNote($this->user->id, $noteId, $this->user->id, [
            'method' => 'transfer',
            'financial_account_id' => $this->treasuryAccountId,
            'transfer_receipt' => 'transfer-proof-2.pdf',
        ]);
    }

    public function test_transfer_refund_without_transfer_receipt_is_refused(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($invoice->id, 400, 'refund', 1);

        $this->expectExceptionMessage('transfer receipt');
        $this->refundService()->refundNote($this->user->id, $noteId, $this->user->id, [
            'method' => 'transfer',
            'financial_account_id' => $this->treasuryAccountId,
        ]);
    }

    public function test_keep_as_credit_note_cannot_be_refunded(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($invoice->id, 400, 'credit', 1);

        $this->expectExceptionMessage('Keep as customer credit');
        $this->refundService()->refundNote($this->user->id, $noteId, $this->user->id, [
            'method' => 'transfer',
            'financial_account_id' => $this->treasuryAccountId,
            'transfer_receipt' => 'transfer-proof.pdf',
        ]);
    }

    public function test_refund_of_another_companys_note_is_refused(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($invoice->id, 400, 'refund', 1);
        CreditNote::where('id', $noteId)->update(['created_by' => 9999]);

        $this->expectExceptionMessage('does not belong to this company');
        $this->refundService()->refundNote($this->user->id, $noteId, $this->user->id, [
            'method' => 'transfer',
            'financial_account_id' => $this->treasuryAccountId,
            'transfer_receipt' => 'transfer-proof.pdf',
        ]);
    }

    public function test_refund_blocked_when_account_would_go_negative(): void
    {
        // Empty bank account, policy block: a 400 refund must refuse.
        $emptyBankId = $this->makeBankAccount($this->bankChartId, 'Empty', 'EMPTY-1');
        $emptyAccountId = $this->makeTreasuryAccount($emptyBankId, 'EMPTY-001', TreasuryFinancialAccount::TYPE_BANK, 0);

        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($invoice->id, 400, 'refund', 1);

        $this->expectExceptionMessage('negative');
        $this->refundService()->refundNote($this->user->id, $noteId, $this->user->id, [
            'method' => 'transfer',
            'financial_account_id' => $emptyAccountId,
            'transfer_receipt' => 'transfer-proof.pdf',
        ]);
    }

    public function test_refund_blocked_when_fiscal_year_closed(): void
    {
        DB::table('journal_entries')->insert([
            'journal_id' => 99, 'date' => now()->toDateString(),
            'reference' => 'FY-' . now()->year . '-CLOSE-001',
            'description' => 'Year close', 'status' => 1,
            'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($invoice->id, 400, 'refund', 1);

        $this->expectExceptionMessage('Fiscal year');
        $this->refundService()->refundNote($this->user->id, $noteId, $this->user->id, [
            'method' => 'transfer',
            'financial_account_id' => $this->treasuryAccountId,
            'transfer_receipt' => 'transfer-proof.pdf',
        ]);
    }

    // ------------------------------------------------------------------
    // Cash refunds — petty cash and the till
    // ------------------------------------------------------------------

    public function test_cash_refund_from_petty_cash_uses_fund_account(): void
    {
        $fundId = DB::table('treasury_petty_cash_funds')->insertGetId([
            'created_by' => $this->user->id, 'custodian_user_id' => $this->user->id,
            'financial_account_id' => $this->cashRegisterAccountId, 'branch_id' => null,
            'limit_amount' => 1000, 'replenishment_frequency' => 'on_demand',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($invoice->id, 250, 'refund', 1);

        $refund = $this->refundService()->refundNote($this->user->id, $noteId, $this->user->id, [
            'method' => 'cash',
            'petty_cash_fund_id' => $fundId,
        ]);

        $this->assertSame($fundId, (int) $refund->petty_cash_fund_id);
        $this->assertSame($this->cashRegisterAccountId, (int) $refund->financial_account_id);
        $operation = TreasuryOperation::find($refund->treasury_operation_id);
        $this->assertSame($this->cashRegisterAccountId, (int) $operation->financial_account_id);

        $account = TreasuryFinancialAccount::find($this->cashRegisterAccountId);
        $this->assertEquals('4750.0000', (new TreasuryAccountService())->postedBalance($account));
    }

    public function test_cash_refund_at_open_till_session_uses_register_account(): void
    {
        $sessionId = DB::table('treasury_cash_sessions')->insertGetId([
            'created_by' => $this->user->id, 'branch_id' => null,
            'register_id' => $this->cashRegisterAccountId,
            'operator_user_id' => $this->user->id, 'shift_id' => null,
            'currency' => 'MZN', 'opened_at' => now(), 'closed_at' => null,
            'opening_float' => 5000, 'state' => 'open',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($invoice->id, 250, 'refund', 1);

        $refund = $this->refundService()->refundNote($this->user->id, $noteId, $this->user->id, [
            'method' => 'cash',
            'financial_account_id' => $this->cashRegisterAccountId,
            'cash_session_id' => $sessionId,
            'signed_receipt' => 'signed.pdf',
        ]);

        $this->assertSame($sessionId, (int) $refund->cash_session_id);
        $this->assertSame('signed.pdf', $refund->signed_receipt);
        $operation = TreasuryOperation::find($refund->treasury_operation_id);
        $this->assertSame($this->cashRegisterAccountId, (int) $operation->financial_account_id);
    }

    public function test_cash_refund_with_closed_session_is_refused(): void
    {
        $sessionId = DB::table('treasury_cash_sessions')->insertGetId([
            'created_by' => $this->user->id, 'branch_id' => null,
            'register_id' => $this->cashRegisterAccountId,
            'operator_user_id' => $this->user->id, 'shift_id' => null,
            'currency' => 'MZN', 'opened_at' => now(), 'closed_at' => now(),
            'opening_float' => 5000, 'state' => 'closed',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($invoice->id, 250, 'refund', 1);

        $this->expectExceptionMessage('open cash session');
        $this->refundService()->refundNote($this->user->id, $noteId, $this->user->id, [
            'method' => 'cash',
            'financial_account_id' => $this->cashRegisterAccountId,
            'cash_session_id' => $sessionId,
        ]);
    }

    public function test_cash_refund_from_bank_account_is_refused(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($invoice->id, 250, 'refund', 1);

        $this->expectExceptionMessage('cash account');
        $this->refundService()->refundNote($this->user->id, $noteId, $this->user->id, [
            'method' => 'cash',
            'financial_account_id' => $this->treasuryAccountId,
        ]);
    }

    // ------------------------------------------------------------------
    // Approval option (Q1)
    // ------------------------------------------------------------------

    public function test_note_created_approved_when_approval_setting_off(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);

        $response = $this->post(route('invoice.credit.note', $invoice->id), [
            'amount' => 400, 'date' => now()->toDateString(),
            'description' => 'Returned goods', 'disposition' => 'refund',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $note = CreditNote::where('invoice', $invoice->id)->first();
        $this->assertSame(1, (int) $note->status);
    }

    public function test_note_created_pending_when_approval_setting_on_and_refund_needs_approval(): void
    {
        $this->turnApprovalOn();

        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);

        $response = $this->post(route('invoice.credit.note', $invoice->id), [
            'amount' => 400, 'date' => now()->toDateString(),
            'description' => 'Returned goods', 'disposition' => 'refund',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $note = CreditNote::where('invoice', $invoice->id)->first();
        $this->assertSame(0, (int) $note->status);

        // Pending notes cannot be refunded.
        $this->expectExceptionMessage('approved');
        $this->refundService()->refundNote($this->user->id, $note->id, $this->user->id, [
            'method' => 'transfer',
            'financial_account_id' => $this->treasuryAccountId,
            'transfer_receipt' => 'transfer-proof.pdf',
        ]);
    }

    public function test_approve_then_refund_when_approval_setting_on(): void
    {
        $this->turnApprovalOn();

        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $this->post(route('invoice.credit.note', $invoice->id), [
            'amount' => 400, 'date' => now()->toDateString(),
            'description' => 'Returned goods', 'disposition' => 'refund',
        ]);
        $note = CreditNote::where('invoice', $invoice->id)->first();

        $response = $this->post(route('credit.note.approve', [$invoice->id, $note->id]));
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame(1, (int) $note->fresh()->status);
        $this->assertSame($this->user->id, (int) $note->fresh()->approved_by);

        $refund = $this->refundService()->refundNote($this->user->id, $note->id, $this->user->id, [
            'method' => 'transfer',
            'financial_account_id' => $this->treasuryAccountId,
            'transfer_receipt' => 'transfer-proof.pdf',
        ]);
        $this->assertNotNull($refund->id);
    }

    public function test_reject_marks_note_rejected_and_blocks_refund(): void
    {
        $this->turnApprovalOn();

        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $this->post(route('invoice.credit.note', $invoice->id), [
            'amount' => 400, 'date' => now()->toDateString(),
            'description' => 'Returned goods', 'disposition' => 'refund',
        ]);
        $note = CreditNote::where('invoice', $invoice->id)->first();

        $response = $this->post(route('credit.note.reject', [$invoice->id, $note->id]));
        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame(2, (int) $note->fresh()->status);

        $this->expectExceptionMessage('approved');
        $this->refundService()->refundNote($this->user->id, $note->id, $this->user->id, [
            'method' => 'transfer',
            'financial_account_id' => $this->treasuryAccountId,
            'transfer_receipt' => 'transfer-proof.pdf',
        ]);
    }

    public function test_approve_without_permission_is_refused(): void
    {
        $this->turnApprovalOn();
        $this->user->revokePermissionTo('approve credit note');

        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($invoice->id, 400, 'refund', 0);

        $response = $this->post(route('credit.note.approve', [$invoice->id, $noteId]));
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Permission denied.');
        $this->assertSame(0, (int) CreditNote::find($noteId)->status);
    }

    // ------------------------------------------------------------------
    // HTTP refund form + printable slip
    // ------------------------------------------------------------------

    public function test_refund_form_and_store_via_http_with_transfer_receipt_file(): void
    {
        Storage::fake('local');
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($invoice->id, 400, 'refund', 1);

        $response = $this->get(route('credit.note.refund.form', [$invoice->id, $noteId]));
        $response->assertOk();
        $response->assertSee('Refund', false);

        $response = $this->post(route('credit.note.refund.store', [$invoice->id, $noteId]), [
            'method' => 'transfer',
            'financial_account_id' => $this->treasuryAccountId,
            'transfer_receipt' => UploadedFile::fake()->create('transfer-proof.pdf', 10, 'application/pdf'),
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('success');

        $refund = CreditNoteRefund::where('credit_note_id', $noteId)->first();
        $this->assertNotNull($refund);
        $this->assertNotNull($refund->transfer_receipt);
        $this->assertStringContainsString('transfer-proof.pdf', (string) $refund->transfer_receipt);
    }

    public function test_refund_slip_pdf_downloads(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($invoice->id, 400, 'refund', 1);
        $refund = $this->refundService()->refundNote($this->user->id, $noteId, $this->user->id, [
            'method' => 'transfer',
            'financial_account_id' => $this->treasuryAccountId,
            'transfer_receipt' => 'transfer-proof.pdf',
        ]);

        $response = $this->get(route('credit.note.refund.slip', $refund->id));
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function refundService(): CreditNoteRefundService
    {
        return new CreditNoteRefundService();
    }

    private function turnApprovalOn(): void
    {
        DB::table('settings')->insert([
            'name' => TreasurySettings::KEY_CREDIT_NOTE_APPROVAL_REQUIRED,
            'value' => '1', 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeCompanyUser(): User
    {
        $user = User::create([
            'name' => 'Test Company',
            'email' => 'company@test.local',
            'password' => bcrypt('password'),
            'type' => 'company',
            'created_by' => 0,
            'is_enable_login' => 1,
            'is_active' => 1,
            'lang' => 'en',
            'email_verified_at' => now(),
        ]);
        // Explicit attributes the mass-assignment guard drops.
        $user->default_company_id = $user->id;
        $user->plan = '1';
        $user->save();

        return $user;
    }

    private function makeInvoice(int $status, float $total, float $paid): Invoice
    {
        $id = DB::table('invoices')->insertGetId([
            'invoice_id' => $this->nextInvoiceNumber++,
            'customer_id' => $this->customerId,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'category_id' => 1,
            'status' => $status,
            'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('invoice_products')->insert([
            'invoice_id' => $id, 'product_id' => $this->productId,
            'quantity' => 1, 'tax' => '', 'discount' => 0, 'price' => $total,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($paid > 0) {
            DB::table('invoice_payments')->insert([
                'invoice_id' => $id, 'date' => now()->toDateString(), 'amount' => $paid,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return Invoice::find($id);
    }

    private function makeNote(int $invoiceId, float $amount, ?string $disposition, int $status = 1): int
    {
        return DB::table('credit_notes')->insertGetId([
            'uid' => 1, 'invoice' => $invoiceId, 'customer' => $this->customerId,
            'amount' => $amount, 'date' => now()->toDateString(),
            'description' => 'Setup note', 'disposition' => $disposition,
            'status' => $status,
            'created_by' => $this->user->id, 'is_debit' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeChartAccount(int $code, string $name): int
    {
        return DB::table('chart_of_accounts')->insertGetId([
            'name' => $name, 'code' => $code, 'type' => 0, 'sub_type' => 0,
            'is_enabled' => 1, 'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeBankAccount(int $chartId, string $holder, string $number): int
    {
        return DB::table('bank_accounts')->insertGetId([
            'holder_name' => $holder, 'bank_name' => $holder,
            'account_number' => $number, 'chart_account_id' => $chartId,
            'opening_balance' => 0, 'contact_number' => '', 'bank_address' => '',
            'created_by' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeTreasuryAccount(int $bankAccountId, string $code, string $type, float $opening): int
    {
        $id = DB::table('treasury_financial_accounts')->insertGetId([
            'bank_account_id' => $bankAccountId, 'created_by' => $this->user->id,
            'code' => $code, 'type' => $type, 'currency' => 'MZN',
            'currency_locked' => 0, 'branch_id' => null,
            'negative_balance_policy' => TreasuryFinancialAccount::POLICY_BLOCK,
            'status' => TreasuryFinancialAccount::STATUS_ACTIVE,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($opening > 0) {
            DB::table('treasury_opening_balances')->insert([
                'financial_account_id' => $id, 'created_by' => $this->user->id,
                'currency' => 'MZN', 'amount' => $opening, 'amount_base' => $opening,
                'exchange_rate' => 1, 'cut_off_date' => now()->toDateString(),
                'batch_reference' => 'TEST-OPEN-' . $code, 'status' => 'approved',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $id;
    }

    private function seedPermissions(): void
    {
        foreach (['create credit note', 'edit credit note', 'delete credit note', 'manage credit note', 'approve credit note', 'manage credit note refund'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function seedBaseRows(): void
    {
        DB::table('languages')->insert([
            ['id' => 1, 'code' => 'en', 'full_name' => 'English', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'code' => 'pt', 'full_name' => 'Portuguese', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('settings')->insert([
            ['name' => 'default_language', 'value' => 'en', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('plans')->insert([
            ['id' => 1, 'name' => 'Test', 'storage_limit' => -1, 'max_customers' => -1,
             'crm' => 1, 'project' => 1, 'hrm' => 1, 'account' => 1, 'pos' => 1, 'stock' => 1,
             'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function createTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
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
            $table->string('plan')->nullable();
            $table->integer('storage_limit')->default(0);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });
        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->integer('permission_id');
            $table->string('model_type');
            $table->integer('model_id');
            $table->index(['model_id', 'model_type']);
        });
        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->integer('role_id');
            $table->string('model_type');
            $table->integer('model_id');
            $table->index(['model_id', 'model_type']);
        });
        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->integer('permission_id');
            $table->integer('role_id');
            $table->index(['role_id', 'permission_id']);
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('languages', function (Blueprint $table) {
            $table->increments('id');
            $table->string('code')->nullable();
            $table->string('full_name')->nullable();
            $table->timestamps();
        });
        Schema::create('email_templates', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('from')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('ch_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('from_id')->nullable();
            $table->integer('to_id')->nullable();
            $table->text('body')->nullable();
            $table->timestamps();
        });
        Schema::create('plans', function (Blueprint $table) {
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
        Schema::create('branches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('default_warehouse_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('branch_user', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('branch_id');
            $table->integer('user_id');
            $table->timestamps();
        });
        Schema::create('customers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('customer_id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('contact')->nullable();
            $table->string('avatar', 100)->default('');
            $table->integer('created_by')->default(0);
            $table->integer('is_active')->default(1);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('billing_name')->nullable();
            $table->string('billing_country')->nullable();
            $table->string('billing_state')->nullable();
            $table->string('billing_city')->nullable();
            $table->string('billing_phone')->nullable();
            $table->string('billing_zip')->nullable();
            $table->text('billing_address')->nullable();
            $table->string('shipping_name')->nullable();
            $table->string('shipping_country')->nullable();
            $table->string('shipping_state')->nullable();
            $table->string('shipping_city')->nullable();
            $table->string('shipping_phone')->nullable();
            $table->string('shipping_zip')->nullable();
            $table->text('shipping_address')->nullable();
            $table->string('lang')->default('en');
            $table->decimal('balance', 15, 2)->default('0.00');
            $table->decimal('credit_balance', 15, 2)->default('0.00');
            $table->boolean('is_walk_in')->default(0);
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('invoices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('customer_id');
            $table->date('issue_date');
            $table->date('due_date');
            $table->date('send_date')->nullable();
            $table->integer('category_id');
            $table->text('ref_number')->nullable();
            $table->integer('status')->default(0);
            $table->integer('shipping_display')->default(1);
            $table->integer('discount_apply')->default(0);
            $table->integer('created_by')->default(0);
            $table->string('document_type')->nullable();
            $table->timestamps();
        });
        Schema::create('invoice_products', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('invoice_id');
            $table->integer('product_id');
            $table->integer('quantity');
            $table->string('tax', 50)->nullable();
            $table->float('discount')->default(0);
            $table->decimal('price', 16, 2)->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('invoice_id');
            $table->date('date');
            $table->decimal('amount', 16, 2)->default(0);
            $table->integer('account_id')->default(0);
            $table->integer('payment_method')->default(0);
            $table->string('order_id')->nullable();
            $table->string('currency')->nullable();
            $table->string('txn_id')->nullable();
            $table->string('payment_type')->default('Manually');
            $table->string('receipt')->nullable();
            $table->string('add_receipt')->nullable();
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
        });
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('uid')->default(1);
            $table->integer('invoice')->default(0);
            $table->integer('customer')->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->date('date');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->boolean('is_debit')->default(0);
            $table->string('disposition', 20)->nullable();
            $table->string('attachment')->nullable();
            $table->text('data')->nullable();
            $table->tinyInteger('status')->default(1);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
        Schema::create('credit_note_refunds', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('credit_note_id')->unique();
            $table->unsignedBigInteger('invoice_id')->default(0);
            $table->unsignedBigInteger('customer_id')->default(0);
            $table->unsignedBigInteger('treasury_operation_id')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->string('method', 20);
            $table->unsignedBigInteger('financial_account_id');
            $table->unsignedBigInteger('petty_cash_fund_id')->nullable();
            $table->unsignedBigInteger('cash_session_id')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('currency', 3)->default('MZN');
            $table->string('transfer_receipt')->nullable();
            $table->string('signed_receipt')->nullable();
            $table->string('reference', 60)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('taxes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->text('rate');
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('product_services', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('sku');
            $table->decimal('sale_price', 16, 2)->default(0);
            $table->decimal('purchase_price', 16, 2)->default(0);
            $table->float('quantity')->default(0);
            $table->string('tax_id', 50)->nullable();
            $table->integer('category_id')->default(0);
            $table->integer('unit_id')->default(0);
            $table->string('type');
            $table->integer('sale_chartaccount_id')->default(0);
            $table->integer('expense_chartaccount_id')->default(0);
            $table->text('description')->nullable();
            $table->string('pro_image')->nullable();
            $table->integer('created_by')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('holder_name');
            $table->string('bank_name');
            $table->string('account_number');
            $table->integer('chart_account_id')->default(0);
            $table->float('opening_balance', 15, 2)->default('0.00');
            $table->string('contact_number')->nullable();
            $table->text('bank_address')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('chart_of_accounts', function (Blueprint $table) {
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
        Schema::create('treasury_financial_accounts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('bank_account_id');
            $table->integer('created_by');
            $table->string('code', 50);
            $table->string('type', 20);
            $table->string('currency', 3)->default('MZN');
            $table->boolean('currency_locked')->default(0);
            $table->bigInteger('branch_id')->nullable();
            $table->string('nib_or_iban')->nullable();
            $table->bigInteger('owner_user_id')->nullable();
            $table->decimal('cash_limit', 20, 4)->nullable();
            $table->decimal('minimum_balance', 20, 4)->nullable();
            $table->string('negative_balance_policy', 20)->default('block');
            $table->decimal('negative_balance_limit', 20, 4)->nullable();
            $table->bigInteger('negative_balance_approver_user_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
        Schema::create('treasury_opening_balances', function (Blueprint $table) {
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
        Schema::create('treasury_operations', function (Blueprint $table) {
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
        Schema::create('treasury_entries', function (Blueprint $table) {
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
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('date');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->integer('journal_id')->default(0);
            $table->string('source', 20)->nullable();
            $table->integer('status')->default(0);
            $table->integer('accounting_period')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('journal_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('journal')->default(0);
            $table->integer('account')->default(0);
            $table->text('description')->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('transaction_lines', function (Blueprint $table) {
            $table->bigIncrements('id');
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
        Schema::create('treasury_petty_cash_funds', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('custodian_user_id');
            $table->bigInteger('financial_account_id');
            $table->bigInteger('branch_id')->nullable();
            $table->decimal('limit_amount', 20, 4)->nullable();
            $table->string('replenishment_frequency', 20)->default('on_demand');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
        Schema::create('treasury_cash_sessions', function (Blueprint $table) {
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
            $table->string('state', 20)->default('open');
            $table->timestamps();

        // Phase 3 (migration 310016): the controller's store/update now
        // write lots through CustomerCreditService, so the fixture must
        // carry the table like the real schema.
        Schema::create('customer_credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('credit_note_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->string('type', 20);
            $table->decimal('amount', 15, 2);
            $table->decimal('remaining', 15, 2)->default(0);
            $table->dateTime('expires_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->index('customer_id', 'ccl_customer_idx');
            $table->index('credit_note_id', 'ccl_note_idx');
            $table->index('expires_at', 'ccl_expires_idx');
        });
        });
    }
}
