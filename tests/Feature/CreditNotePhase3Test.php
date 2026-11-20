<?php

namespace Tests\Feature;

use App\Models\CreditNote;
use App\Models\CustomerCreditLedger;
use App\Models\CreditNoteRefund;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\JournalEntry;
use App\Models\TreasuryEntry;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryOperation;
use App\Models\User;
use App\Services\CreditNoteRefundService;
use App\Services\CustomerCreditService;
use App\Services\TreasuryAccountService;
use App\Services\TreasurySettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Credit Notes Phase 3 (2026-11-20) — customer credit ledger +
 * auto-apply + 14-day expiry (client answer 2026-11-20: 2 weeks).
 *
 * Rules under test:
 * - an approved credit note kept as credit becomes a LOT in
 *   customer_credit_ledger (oldest-first use, expires 14 days
 *   after it was added); a pending note adds nothing until approved.
 * - auto-apply at send / payment time: credit settles the invoice
 *   as InvoicePayment rows (payment_type 'Credit'), no cash or bank
 *   moves, no Transaction rows, no ledger lines.
 * - update/destroy move only the lot's unconsumed remainder;
 *   deleting a credit payment gives the credit back, exactly once.
 *
 * (The triple-star opener above is intentional: it keeps this
 * replacement text out of the docblock regex on a re-run.)
 */
class CreditNotePhase3Test extends TestCase
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
        $this->user->givePermissionTo(['create credit note', 'edit credit note', 'delete credit note', 'manage credit note', 'approve credit note', 'manage credit note refund', 'create payment invoice']);
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

        foreach (['customer_credit_ledger', 'transactions', 'webhook_settings', 'credit_note_refunds', 'treasury_cash_sessions', 'treasury_petty_cash_funds', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'bank_accounts', 'chart_of_accounts', 'transaction_lines', 'journal_items', 'journal_entries', 'credit_notes', 'invoice_payments', 'invoice_products', 'invoices', 'product_services', 'taxes', 'customers', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'settings', 'languages', 'email_templates', 'ch_messages', 'plans', 'branches', 'branch_user'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Lot creation — approved notes only, 14-day expiry, idempotent
    // ------------------------------------------------------------------

    public function test_approved_credit_note_creates_lot_with_14_day_expiry(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($invoice->id, 100, 'credit', 1);
        $note = CreditNote::find($noteId);

        $lot = $this->creditService()->addCredit($this->user->id, $note, $this->user->id);

        $this->assertNotNull($lot);
        $this->assertEquals(CustomerCreditLedger::TYPE_ADDED, $lot->type);
        $this->assertEquals(100, (float) $lot->amount);
        $this->assertEquals(100, (float) $lot->remaining);
        $this->assertEquals($noteId, (int) $lot->credit_note_id);
        $this->assertEquals($this->customerId, (int) $lot->customer_id);
        // Expiry is 14 days after the credit was added (client answer Q2).
        $this->assertEquals(14, $lot->created_at->diffInDays($lot->expires_at));
        $this->assertEquals(100, $this->customerCreditBalance());
    }

    public function test_add_credit_is_idempotent_for_same_note(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($invoice->id, 100, 'credit', 1);
        $note = CreditNote::find($noteId);

        $first = $this->creditService()->addCredit($this->user->id, $note, $this->user->id);
        $second = $this->creditService()->addCredit($this->user->id, $note, $this->user->id);

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, CustomerCreditLedger::where('credit_note_id', $noteId)->where('type', 'added')->count());
        $this->assertEquals(100, $this->customerCreditBalance());
    }

    public function test_refund_disposition_note_adds_no_lot(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($invoice->id, 100, 'refund', 1);
        $note = CreditNote::find($noteId);

        $lot = $this->creditService()->addCredit($this->user->id, $note, $this->user->id);

        $this->assertNull($lot);
        $this->assertEquals(0, CustomerCreditLedger::count());
        $this->assertEquals(0, $this->customerCreditBalance());
    }

    // ------------------------------------------------------------------
    // Auto-apply — oldest first, no cash/bank movement
    // ------------------------------------------------------------------

    public function test_auto_apply_covers_invoice_fully_and_marks_paid(): void
    {
        $paid = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($paid->id, 100, 'credit', 1);
        $this->creditService()->addCredit($this->user->id, CreditNote::find($noteId), $this->user->id);

        $invoice = $this->makeInvoice(Invoice::STATUS_SENT, 80, 0);
        $balanceBefore = (float) Customer::find($this->customerId)->balance;

        $applied = $this->creditService()->applyAvailableCredit($this->user->id, $invoice, $this->user->id);

        $this->assertEquals(80, $applied);
        $this->assertEquals(Invoice::STATUS_PAID, (int) $invoice->fresh()->status);
        $this->assertEquals(0, (float) $invoice->fresh()->getDue());
        $this->assertDatabaseHas('invoice_payments', [
            'invoice_id' => $invoice->id, 'amount' => 80,
            'payment_type' => CustomerCreditService::PAYMENT_TYPE_CREDIT, 'account_id' => 0,
        ]);
        $this->assertEquals(20, (float) $this->lotForNote($noteId)->remaining);
        $this->assertEquals(20, $this->customerCreditBalance());
        $this->assertEquals($balanceBefore - 80, (float) Customer::find($this->customerId)->balance);
        // No cash moved: no Transaction row and no ledger lines at all.
        $this->assertEquals(0, DB::table('transactions')->count());
        $this->assertEquals(0, DB::table('transaction_lines')->count());
    }

    public function test_auto_apply_uses_oldest_lot_first(): void
    {
        $paid = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $olderId = $this->makeNote($paid->id, 50, 'credit', 1);
        $newerId = $this->makeNote($paid->id, 100, 'credit', 1);
        $this->creditService()->addCredit($this->user->id, CreditNote::find($olderId), $this->user->id);
        $this->creditService()->addCredit($this->user->id, CreditNote::find($newerId), $this->user->id);

        $invoice = $this->makeInvoice(Invoice::STATUS_SENT, 120, 0);

        $applied = $this->creditService()->applyAvailableCredit($this->user->id, $invoice, $this->user->id);

        $this->assertEquals(120, $applied);
        $this->assertEquals(Invoice::STATUS_PAID, (int) $invoice->fresh()->status);
        // Oldest lot fully consumed, newer lot keeps the rest.
        $this->assertEquals(0, (float) $this->lotForNote($olderId)->remaining);
        $this->assertEquals(30, (float) $this->lotForNote($newerId)->remaining);
        $this->assertEquals(30, $this->customerCreditBalance());
        // One `used` row per lot, with the right amounts.
        $this->assertEquals(-50, (float) CustomerCreditLedger::where('credit_note_id', $olderId)->where('type', 'used')->value('amount'));
        $this->assertEquals(-70, (float) CustomerCreditLedger::where('credit_note_id', $newerId)->where('type', 'used')->value('amount'));
        $this->assertEquals(2, InvoicePayment::where('invoice_id', $invoice->id)->where('payment_type', 'Credit')->count());
    }

    public function test_auto_apply_partial_when_credit_less_than_due(): void
    {
        $paid = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($paid->id, 30, 'credit', 1);
        $this->creditService()->addCredit($this->user->id, CreditNote::find($noteId), $this->user->id);

        $invoice = $this->makeInvoice(Invoice::STATUS_SENT, 100, 0);

        $applied = $this->creditService()->applyAvailableCredit($this->user->id, $invoice, $this->user->id);

        $this->assertEquals(30, $applied);
        $this->assertEquals(Invoice::STATUS_PARTIALLY_PAID, (int) $invoice->fresh()->status);
        $this->assertEquals(70, (float) $invoice->fresh()->getDue());
        $this->assertEquals(0, (float) $this->lotForNote($noteId)->remaining);
        $this->assertEquals(0, $this->customerCreditBalance());
    }

    public function test_auto_apply_returns_zero_when_no_credit(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_SENT, 100, 0);

        $applied = $this->creditService()->applyAvailableCredit($this->user->id, $invoice, $this->user->id);

        $this->assertEquals(0, $applied);
        $this->assertEquals(Invoice::STATUS_SENT, (int) $invoice->fresh()->status);
        $this->assertEquals(0, InvoicePayment::where('invoice_id', $invoice->id)->count());
    }

    // ------------------------------------------------------------------
    // Expiry — lazy enforcement + materialised expiry rows
    // ------------------------------------------------------------------

    public function test_expired_lot_is_not_usable(): void
    {
        $paid = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($paid->id, 100, 'credit', 1);
        $this->creditService()->addCredit($this->user->id, CreditNote::find($noteId), $this->user->id);
        $this->expireLot($noteId);

        $this->assertEquals(0, $this->creditService()->usableCredit($this->user->id, $this->customerId));

        $invoice = $this->makeInvoice(Invoice::STATUS_SENT, 50, 0);
        $applied = $this->creditService()->applyAvailableCredit($this->user->id, $invoice, $this->user->id);

        $this->assertEquals(0, $applied);
        $this->assertEquals(Invoice::STATUS_SENT, (int) $invoice->fresh()->status);
        $this->assertEquals(0, InvoicePayment::where('invoice_id', $invoice->id)->count());
    }

    public function test_expire_due_credit_materialises_expiry_row(): void
    {
        $paid = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($paid->id, 100, 'credit', 1);
        $this->creditService()->addCredit($this->user->id, CreditNote::find($noteId), $this->user->id);
        $this->expireLot($noteId);

        $expired = $this->creditService()->expireDueCredit($this->user->id);

        $this->assertEquals(1, $expired);
        $this->assertEquals(0, (float) $this->lotForNote($noteId)->remaining);
        $this->assertEquals(0, $this->customerCreditBalance());
        $this->assertDatabaseHas('customer_credit_ledger', [
            'credit_note_id' => $noteId, 'type' => 'expired', 'amount' => -100, 'remaining' => 0,
        ]);
        // Running it again expires nothing.
        $this->assertEquals(0, $this->creditService()->expireDueCredit($this->user->id));
    }

    public function test_expire_command_expires_due_lots(): void
    {
        $paid = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($paid->id, 60, 'credit', 1);
        $this->creditService()->addCredit($this->user->id, CreditNote::find($noteId), $this->user->id);
        $this->expireLot($noteId);

        $exit = Artisan::call('credit:expire');

        $this->assertEquals(0, $exit);
        $this->assertDatabaseHas('customer_credit_ledger', [
            'credit_note_id' => $noteId, 'type' => 'expired', 'amount' => -60,
        ]);
        $this->assertEquals(0, $this->customerCreditBalance());
    }

    // ------------------------------------------------------------------
    // Update / destroy — lot-aware, remainder only
    // ------------------------------------------------------------------

    public function test_update_note_adjusts_lot_by_delta(): void
    {
        $paid = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($paid->id, 100, 'credit', 1);
        $note = CreditNote::find($noteId);
        $this->creditService()->addCredit($this->user->id, $note, $this->user->id);

        $note->amount = 60;
        $note->save();
        $this->creditService()->adjustLotForUpdatedNote($note, 100);

        $this->assertEquals(60, (float) $this->lotForNote($noteId)->amount);
        $this->assertEquals(60, (float) $this->lotForNote($noteId)->remaining);
        $this->assertEquals(60, $this->customerCreditBalance());
    }

    public function test_update_note_clamps_remaining_at_zero_when_reduced_below_used(): void
    {
        $paid = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($paid->id, 100, 'credit', 1);
        $note = CreditNote::find($noteId);
        $this->creditService()->addCredit($this->user->id, $note, $this->user->id);

        // Use 40 first: remaining 60, balance 60.
        $invoice = $this->makeInvoice(Invoice::STATUS_SENT, 40, 0);
        $this->creditService()->applyAvailableCredit($this->user->id, $invoice, $this->user->id);

        // Shrink the note to 50: remaining 60 - 50 = 10.
        $note->amount = 50;
        $note->save();
        $this->creditService()->adjustLotForUpdatedNote($note, 100);
        $this->assertEquals(10, (float) $this->lotForNote($noteId)->remaining);
        $this->assertEquals(10, $this->customerCreditBalance());

        // Shrink it to 30 (below the 40 already used): remaining 0,
        // balance 0 — the used 40 stays used.
        $note->amount = 30;
        $note->save();
        $this->creditService()->adjustLotForUpdatedNote($note, 50);
        $this->assertEquals(0, (float) $this->lotForNote($noteId)->remaining);
        $this->assertEquals(0, $this->customerCreditBalance());
        $this->assertEquals(1, CustomerCreditLedger::where('credit_note_id', $noteId)->where('type', 'used')->count());
    }

    public function test_destroy_note_reverses_only_unconsumed_remainder(): void
    {
        $paid = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($paid->id, 100, 'credit', 1);
        $note = CreditNote::find($noteId);
        $this->creditService()->addCredit($this->user->id, $note, $this->user->id);

        $invoice = $this->makeInvoice(Invoice::STATUS_SENT, 40, 0);
        $this->creditService()->applyAvailableCredit($this->user->id, $invoice, $this->user->id);

        $this->creditService()->reverseLotForDestroyedNote($note);

        $this->assertEquals(0, (float) $this->lotForNote($noteId)->remaining);
        $this->assertEquals(0, $this->customerCreditBalance());
        $this->assertDatabaseHas('customer_credit_ledger', [
            'credit_note_id' => $noteId, 'type' => 'reversed', 'amount' => -60, 'remaining' => 0,
        ]);
        // The used 40 stays used; its invoice stays paid.
        $this->assertEquals(1, CustomerCreditLedger::where('credit_note_id', $noteId)->where('type', 'used')->count());
        $this->assertEquals(Invoice::STATUS_PAID, (int) $invoice->fresh()->status);
    }

    public function test_delete_credit_payment_restores_lot_once(): void
    {
        $paid = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($paid->id, 100, 'credit', 1);
        $this->creditService()->addCredit($this->user->id, CreditNote::find($noteId), $this->user->id);

        $invoice = $this->makeInvoice(Invoice::STATUS_SENT, 40, 0);
        $this->creditService()->applyAvailableCredit($this->user->id, $invoice, $this->user->id);
        $payment = InvoicePayment::where('invoice_id', $invoice->id)->where('payment_type', 'Credit')->first();
        $this->assertNotNull($payment);
        $this->assertEquals(60, (float) $this->lotForNote($noteId)->remaining);

        $this->creditService()->reverseCreditUseForPayment($payment);
        $this->assertEquals(100, (float) $this->lotForNote($noteId)->remaining);
        $this->assertEquals(100, $this->customerCreditBalance());

        // A second reversal for the same payment must not credit twice.
        $this->creditService()->reverseCreditUseForPayment($payment);
        $this->assertEquals(100, (float) $this->lotForNote($noteId)->remaining);
        $this->assertEquals(100, $this->customerCreditBalance());
        $this->assertEquals(1, CustomerCreditLedger::where('payment_id', $payment->id)->where('type', 'reversed')->count());
    }

    // ------------------------------------------------------------------
    // HTTP — approval gating + payment-time apply + list columns
    // ------------------------------------------------------------------

    public function test_note_created_with_approval_on_adds_no_lot_until_approved(): void
    {
        $this->turnApprovalOn();
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);

        $response = $this->post(route('invoice.credit.note', $invoice->id), [
            'amount' => 100, 'date' => now()->toDateString(),
            'description' => 'Keep as credit', 'disposition' => 'credit',
        ]);
        $response->assertRedirect();

        $note = CreditNote::where('invoice', $invoice->id)->first();
        $this->assertEquals(CreditNote::STATUS_PENDING, (int) $note->status);
        $this->assertEquals(0, CustomerCreditLedger::count());
        $this->assertEquals(0, $this->customerCreditBalance());

        $response = $this->post(route('credit.note.approve', [$invoice->id, $note->id]));
        $response->assertRedirect();

        $this->assertEquals(1, CustomerCreditLedger::where('credit_note_id', $note->id)->where('type', 'added')->count());
        $this->assertEquals(100, $this->customerCreditBalance());
    }

    public function test_note_created_with_approval_off_adds_lot_at_once(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);

        $response = $this->post(route('invoice.credit.note', $invoice->id), [
            'amount' => 100, 'date' => now()->toDateString(),
            'description' => 'Keep as credit', 'disposition' => 'credit',
        ]);
        $response->assertRedirect();

        $note = CreditNote::where('invoice', $invoice->id)->first();
        $this->assertEquals(CreditNote::STATUS_APPROVED, (int) $note->status);
        $this->assertEquals(1, CustomerCreditLedger::where('credit_note_id', $note->id)->where('type', 'added')->count());
        $this->assertEquals(100, $this->customerCreditBalance());
    }

    public function test_payment_applies_credit_after_cash(): void
    {
        $paid = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($paid->id, 40, 'credit', 1);
        $this->creditService()->addCredit($this->user->id, CreditNote::find($noteId), $this->user->id);

        $invoice = $this->makeInvoice(Invoice::STATUS_SENT, 100, 0);

        $response = $this->post(route('invoice.payment', $invoice->id), [
            'date' => now()->toDateString(), 'amount' => 60, 'account_id' => $this->bankAccountId,
        ]);
        $response->assertRedirect();

        $this->assertEquals(Invoice::STATUS_PAID, (int) $invoice->fresh()->status);
        $this->assertEquals(0, (float) $invoice->fresh()->getDue());
        $this->assertDatabaseHas('invoice_payments', [
            'invoice_id' => $invoice->id, 'amount' => 40, 'payment_type' => 'Credit',
        ]);
        $this->assertEquals(0, (float) $this->lotForNote($noteId)->remaining);
        $this->assertEquals(0, $this->customerCreditBalance());
        // The cash payment posted its balanced pair; the credit
        // payment posted nothing to the ledger.
        $creditPaymentId = InvoicePayment::where('invoice_id', $invoice->id)->where('payment_type', 'Credit')->value('id');
        $this->assertEquals(0, DB::table('transaction_lines')->where('reference_sub_id', $creditPaymentId)->count());
        $this->assertEquals(1, DB::table('transactions')->count());
    }

    public function test_credit_note_list_shows_status_type_refund_and_credit_used(): void
    {
        $paid = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $noteId = $this->makeNote($paid->id, 100, 'credit', 1);
        $this->creditService()->addCredit($this->user->id, CreditNote::find($noteId), $this->user->id);
        $invoice = $this->makeInvoice(Invoice::STATUS_SENT, 40, 0);
        $this->creditService()->applyAvailableCredit($this->user->id, $invoice, $this->user->id);

        $response = $this->get(route('credit.note'));

        $response->assertOk();
        $response->assertSee('Approved');
        $response->assertSee('Keep as credit');
        $response->assertSee('Credit Used');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function creditService(): CustomerCreditService
    {
        return app(CustomerCreditService::class);
    }

    private function customerCreditBalance(): float
    {
        return round((float) Customer::find($this->customerId)->credit_balance, 2);
    }

    private function lotForNote(int $noteId): ?CustomerCreditLedger
    {
        return CustomerCreditLedger::where('credit_note_id', $noteId)->where('type', 'added')->first();
    }

    /** Move a lot into the past so it is expired (created 15 days ago). */
    private function expireLot(int $noteId): void
    {
        DB::table('customer_credit_ledger')
            ->where('credit_note_id', $noteId)
            ->where('type', 'added')
            ->update([
                'created_at' => now()->subDays(15),
                'expires_at' => now()->subDay(),
            ]);
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
        foreach (['create credit note', 'edit credit note', 'delete credit note', 'manage credit note', 'approve credit note', 'manage credit note refund', 'create payment invoice'] as $name) {
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
        });
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

        Schema::create('transactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('user_id')->nullable();
            $table->string('user_type')->nullable();
            $table->integer('account')->nullable();
            $table->string('type')->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->text('description')->nullable();
            $table->date('date')->nullable();
            $table->integer('created_by')->default(0);
            $table->integer('payment_id')->default(0);
            $table->string('category')->nullable();
            $table->timestamps();
        });

        // createPayment ends by notifying via webhook settings.
        Schema::create('webhook_settings', function (Blueprint $table) {
            $table->id();
            $table->string('module')->nullable();
            $table->string('url')->nullable();
            $table->string('method')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

    }
}
