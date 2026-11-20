<?php

namespace Tests\Feature;

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
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
 * Credit Notes Phase 1 (2026-11-18) — paid-invoice notes, disposition
 * (refund / keep as customer credit), mandatory reason, optional
 * document upload, and the credit_balance first-note bug fix.
 *
 * Cap: a note can settle what is still owed (getDue) and return money
 * already paid, so the cap is getDue() + amount paid — for a paid
 * invoice that is paid minus notes already issued, for an unpaid
 * invoice it is getDue() (unchanged behaviour).
 */
class CreditNotePhase1Test extends TestCase
{
    private User $user;
    private int $customerId;
    private int $productId;
    private int $nextInvoiceNumber = 1001;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->createTables();
        $this->seedPermissions();
        $this->seedBaseRows();
        $this->user = $this->makeCompanyUser();
        $this->user->givePermissionTo(['create credit note', 'edit credit note', 'delete credit note', 'manage credit note']);
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
    }

    protected function tearDown(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['customer_credit_ledger', 'credit_notes', 'invoice_payments', 'invoice_products', 'invoices', 'product_services', 'taxes', 'customers', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'settings', 'languages', 'email_templates', 'ch_messages', 'plans', 'branches', 'branch_user'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Paid invoice: note allowed, capped at paid minus notes issued
    // ------------------------------------------------------------------

    public function test_paid_invoice_credit_note_keep_as_credit_adds_first_note_to_credit_balance(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);

        $response = $this->post(route('invoice.credit.note', $invoice->id), [
            'amount' => 400, 'date' => now()->toDateString(),
            'description' => 'Customer returned goods', 'disposition' => 'credit',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $note = CreditNote::where('invoice', $invoice->id)->first();
        $this->assertNotNull($note);
        $this->assertSame('credit', $note->disposition);
        $this->assertSame('Customer returned goods', $note->description);
        $this->assertEquals(400, $note->amount);
        // The first note must count: the old code only added when a
        // balance already existed, so the first note was lost.
        $this->assertEquals(400, Customer::find($this->customerId)->credit_balance);
    }

    public function test_paid_invoice_credit_note_capped_at_paid_minus_notes_issued(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $this->makeNote($invoice->id, 400, 'credit');

        // Cap is 1000 paid - 400 already issued = 600.
        $response = $this->post(route('invoice.credit.note', $invoice->id), [
            'amount' => 700, 'date' => now()->toDateString(),
            'description' => 'Too much', 'disposition' => 'credit',
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(1, CreditNote::where('invoice', $invoice->id)->count());

        $response = $this->post(route('invoice.credit.note', $invoice->id), [
            'amount' => 600, 'date' => now()->toDateString(),
            'description' => 'Exactly the cap', 'disposition' => 'credit',
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame(2, CreditNote::where('invoice', $invoice->id)->count());
    }

    public function test_unpaid_invoice_credit_note_settles_debt(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_UNPAID, 1000, 0);

        $response = $this->post(route('invoice.credit.note', $invoice->id), [
            'amount' => 1000, 'date' => now()->toDateString(),
            'description' => 'Settle the debt', 'disposition' => 'credit',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertEquals(1000, Customer::find($this->customerId)->credit_balance);
        $this->assertEquals(0, Invoice::find($invoice->id)->getDue());

        // Above the outstanding due is still refused.
        $other = $this->makeInvoice(Invoice::STATUS_UNPAID, 1000, 0);
        $response = $this->post(route('invoice.credit.note', $other->id), [
            'amount' => 1001, 'date' => now()->toDateString(),
            'description' => 'Too much', 'disposition' => 'credit',
        ]);
        $response->assertSessionHas('error');
        $this->assertSame(0, CreditNote::where('invoice', $other->id)->count());
    }

    public function test_partly_paid_invoice_cap_covers_due_plus_paid(): void
    {
        // Total 1000, paid 400: cap is 600 still owed + 400 paid.
        $invoice = $this->makeInvoice(Invoice::STATUS_PARTIALLY_PAID, 1000, 400);

        $response = $this->post(route('invoice.credit.note', $invoice->id), [
            'amount' => 1001, 'date' => now()->toDateString(),
            'description' => 'Too much', 'disposition' => 'credit',
        ]);
        $response->assertSessionHas('error');
        $this->assertSame(0, CreditNote::where('invoice', $invoice->id)->count());

        $response = $this->post(route('invoice.credit.note', $invoice->id), [
            'amount' => 1000, 'date' => now()->toDateString(),
            'description' => 'Settle and return', 'disposition' => 'credit',
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame(1, CreditNote::where('invoice', $invoice->id)->count());
    }

    // ------------------------------------------------------------------
    // Disposition semantics
    // ------------------------------------------------------------------

    public function test_refund_disposition_does_not_touch_credit_balance(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);

        $response = $this->post(route('invoice.credit.note', $invoice->id), [
            'amount' => 300, 'date' => now()->toDateString(),
            'description' => 'Refund to bank', 'disposition' => 'refund',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $note = CreditNote::where('invoice', $invoice->id)->first();
        $this->assertSame('refund', $note->disposition);
        $this->assertEquals(0, Customer::find($this->customerId)->credit_balance);
    }

    public function test_refund_disposition_rejected_when_nothing_paid(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_UNPAID, 1000, 0);

        $response = $this->post(route('invoice.credit.note', $invoice->id), [
            'amount' => 100, 'date' => now()->toDateString(),
            'description' => 'No money to return', 'disposition' => 'refund',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('error', 'A refund requires a paid amount on this invoice.');
        $this->assertSame(0, CreditNote::where('invoice', $invoice->id)->count());

        // Standalone notes (custom flow, no invoice) have no paid
        // amount either, so a refund is refused there too.
        $response = $this->post(route('invoice.custom.credit.note'), [
            'customer' => $this->customerId, 'amount' => 100, 'date' => now()->toDateString(),
            'description' => 'Standalone refund', 'disposition' => 'refund',
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('error', 'A refund requires a paid amount on this invoice.');
        $this->assertSame(0, CreditNote::where('invoice', 0)->count());
    }

    // ------------------------------------------------------------------
    // Reason required, document optional
    // ------------------------------------------------------------------

    public function test_reason_and_disposition_are_required(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_UNPAID, 1000, 0);

        $response = $this->post(route('invoice.credit.note', $invoice->id), [
            'amount' => 100, 'date' => now()->toDateString(), 'disposition' => 'credit',
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('error', 'The description field is required.');
        $this->assertSame(0, CreditNote::where('invoice', $invoice->id)->count());

        $response = $this->post(route('invoice.credit.note', $invoice->id), [
            'amount' => 100, 'date' => now()->toDateString(), 'description' => 'No choice made',
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('error', 'The disposition field is required.');
        $this->assertSame(0, CreditNote::where('invoice', $invoice->id)->count());
    }

    public function test_document_upload_is_stored(): void
    {
        Storage::fake();
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);

        $response = $this->post(route('invoice.credit.note', $invoice->id), [
            'amount' => 100, 'date' => now()->toDateString(),
            'description' => 'Customer request attached', 'disposition' => 'credit',
            'attachment' => UploadedFile::fake()->create('request.pdf', 50, 'application/pdf'),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $note = CreditNote::where('invoice', $invoice->id)->first();
        $this->assertNotNull($note->attachment);
        Storage::disk()->assertExists('uploads/credit-notes/' . $note->attachment);
    }

    // ------------------------------------------------------------------
    // Draft / cancelled stay blocked
    // ------------------------------------------------------------------

    public function test_draft_and_cancelled_invoices_stay_blocked(): void
    {
        $draft = $this->makeInvoice(Invoice::STATUS_DRAFT, 1000, 0);
        $response = $this->post(route('invoice.credit.note', $draft->id), [
            'amount' => 100, 'date' => now()->toDateString(),
            'description' => 'Draft', 'disposition' => 'credit',
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Cannot create note for a draft invoice.');
        $this->assertSame(0, CreditNote::where('invoice', $draft->id)->count());

        $cancelled = $this->makeInvoice(Invoice::STATUS_CANCELLED, 1000, 1000);
        $response = $this->post(route('invoice.credit.note', $cancelled->id), [
            'amount' => 100, 'date' => now()->toDateString(),
            'description' => 'Cancelled', 'disposition' => 'credit',
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Cannot create note for a cancelled invoice.');
        $this->assertSame(0, CreditNote::where('invoice', $cancelled->id)->count());
    }

    // ------------------------------------------------------------------
    // Custom-create flow: paid invoices listed + accepted
    // ------------------------------------------------------------------

    public function test_custom_create_accepts_paid_invoice_and_lists_it(): void
    {
        $paid = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);

        $response = $this->get(route('invoice.custom.credit.notes'));
        $response->assertOk();
        $invoiceSelect = $this->invoiceSelectHtml($response->getContent());
        $this->assertStringContainsString('value="' . $paid->id . '"', $invoiceSelect);
        $this->assertStringContainsString('data-paid="1000"', $invoiceSelect);

        $response = $this->post(route('invoice.custom.credit.note'), [
            'invoice' => $paid->id, 'amount' => 200, 'date' => now()->toDateString(),
            'description' => 'Custom flow on paid invoice', 'disposition' => 'credit',
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('success');
        $note = CreditNote::where('invoice', $paid->id)->first();
        $this->assertNotNull($note);
        $this->assertSame('credit', $note->disposition);
        $this->assertEquals(200, Customer::find($this->customerId)->credit_balance);

        // The debit-note form still never lists paid invoices.
        $response = $this->get(route('create.sale.debit.note'));
        $response->assertOk();
        $this->assertStringNotContainsString('value="' . $paid->id . '"', $this->invoiceSelectHtml($response->getContent()));
    }

    public function test_custom_create_standalone_credit_note_adds_to_credit_balance(): void
    {
        $response = $this->post(route('invoice.custom.credit.note'), [
            'customer' => $this->customerId, 'amount' => 250, 'date' => now()->toDateString(),
            'description' => 'Goodwill credit', 'disposition' => 'credit',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $note = CreditNote::where('invoice', 0)->first();
        $this->assertNotNull($note);
        $this->assertSame('credit', $note->disposition);
        $this->assertEquals(250, Customer::find($this->customerId)->credit_balance);
    }

    // ------------------------------------------------------------------
    // Update / destroy keep credit_balance consistent
    // ------------------------------------------------------------------

    public function test_update_keeps_credit_balance_consistent(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $this->post(route('invoice.credit.note', $invoice->id), [
            'amount' => 400, 'date' => now()->toDateString(),
            'description' => 'First note', 'disposition' => 'credit',
        ]);
        $note = CreditNote::where('invoice', $invoice->id)->first();
        $this->assertEquals(400, Customer::find($this->customerId)->credit_balance);

        // Raising a keep-as-credit note raises the balance by the delta.
        $response = $this->post(route('invoice.edit.credit.note', [$invoice->id, $note->id]), [
            'amount' => 500, 'date' => now()->toDateString(), 'description' => 'Corrected amount',
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertEquals(500, Customer::find($this->customerId)->credit_balance);

        // Above the cap (paid 1000 + old 400) is refused.
        $response = $this->post(route('invoice.edit.credit.note', [$invoice->id, $note->id]), [
            'amount' => 1500, 'date' => now()->toDateString(), 'description' => 'Too much',
        ]);
        $response->assertSessionHas('error');
        $this->assertEquals(500, Customer::find($this->customerId)->credit_balance);

        // The reason stays mandatory on update for credit notes.
        $response = $this->post(route('invoice.edit.credit.note', [$invoice->id, $note->id]), [
            'amount' => 500, 'date' => now()->toDateString(), 'description' => '',
        ]);
        $response->assertSessionHas('error', 'The description field is required.');
    }

    public function test_update_refund_note_leaves_credit_balance_alone(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $this->post(route('invoice.credit.note', $invoice->id), [
            'amount' => 300, 'date' => now()->toDateString(),
            'description' => 'Refund note', 'disposition' => 'refund',
        ]);
        $note = CreditNote::where('invoice', $invoice->id)->first();
        $this->assertEquals(0, Customer::find($this->customerId)->credit_balance);

        $response = $this->post(route('invoice.edit.credit.note', [$invoice->id, $note->id]), [
            'amount' => 500, 'date' => now()->toDateString(), 'description' => 'Bigger refund',
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertEquals(0, Customer::find($this->customerId)->credit_balance);
    }

    public function test_destroy_keeps_credit_balance_consistent(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $this->post(route('invoice.credit.note', $invoice->id), [
            'amount' => 400, 'date' => now()->toDateString(),
            'description' => 'First note', 'disposition' => 'credit',
        ]);
        $note = CreditNote::where('invoice', $invoice->id)->first();
        $this->assertEquals(400, Customer::find($this->customerId)->credit_balance);

        $response = $this->delete(route('invoice.delete.credit.note', [$invoice->id, $note->id]));
        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertNull(CreditNote::find($note->id));
        $this->assertEquals(0, Customer::find($this->customerId)->credit_balance);

        // A refund note never touched the balance, so deleting it
        // leaves the balance alone.
        $this->post(route('invoice.credit.note', $invoice->id), [
            'amount' => 300, 'date' => now()->toDateString(),
            'description' => 'Refund note', 'disposition' => 'refund',
        ]);
        $refundNote = CreditNote::where('invoice', $invoice->id)->where('disposition', 'refund')->first();
        $response = $this->delete(route('invoice.delete.credit.note', [$invoice->id, $refundNote->id]));
        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertEquals(0, Customer::find($this->customerId)->credit_balance);

        // A legacy note without a disposition (void notes, pre-Phase 1
        // notes) never touched the balance either.
        $legacyId = $this->makeNote($invoice->id, 100, null);
        $response = $this->delete(route('invoice.delete.credit.note', [$invoice->id, $legacyId]));
        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertEquals(0, Customer::find($this->customerId)->credit_balance);
    }

    // ------------------------------------------------------------------
    // Debit notes: paid invoices still blocked
    // ------------------------------------------------------------------

    public function test_debit_note_on_paid_invoice_still_blocked(): void
    {
        $invoice = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);

        $response = $this->post(route('create.sale.debit.note'), [
            'invoice' => $invoice->id, 'amount' => 100, 'date' => now()->toDateString(),
            'description' => 'Debit on paid',
        ]);
        $response->assertRedirect();
        $response->assertSessionHas('error', 'Cannot create note for a paid invoice.');
        $this->assertSame(0, CreditNote::where('invoice', $invoice->id)->where('is_debit', 1)->count());

        // The items endpoint blocks paid invoices for debit notes but
        // loads them for credit notes.
        $response = $this->get(route('invoice.get.items', ['invoice_id' => $invoice->id, 'note_type' => 'debit']));
        $response->assertOk();
        $this->assertStringContainsString('Cannot create note for a paid invoice.', $response->getContent());

        $response = $this->get(route('invoice.get.items', ['invoice_id' => $invoice->id]));
        $response->assertOk();
        $this->assertStringContainsString('Widget', $response->getContent());
    }

    // ------------------------------------------------------------------
    // Create form offers Refund only when money was paid
    // ------------------------------------------------------------------

    public function test_create_page_offers_refund_only_when_paid(): void
    {
        $paid = $this->makeInvoice(Invoice::STATUS_PAID, 1000, 1000);
        $response = $this->get('/invoice/' . $paid->id . '/debit-note');
        $response->assertOk();
        $response->assertSee('Refund the money', false);
        $response->assertSee('Keep as customer credit', false);
        $response->assertSee('Reason is required.', false);

        $unpaid = $this->makeInvoice(Invoice::STATUS_UNPAID, 1000, 0);
        $response = $this->get('/invoice/' . $unpaid->id . '/debit-note');
        $response->assertOk();
        $response->assertDontSee('Refund the money', false);
        $response->assertSee('Keep as customer credit', false);
        $response->assertSee('Reason is required.', false);
    }

    public function test_custom_create_page_shows_reason_error_text(): void
    {
        $response = $this->get('/custom-debit-note');
        $response->assertOk();
        $response->assertSee('Reason is required.', false);
    }

    // ------------------------------------------------------------------
    // Fixture
    // ------------------------------------------------------------------

    /**
     * The invoice <select> block of a custom note form, so assertions
     * about listed invoices do not trip over the customer dropdown
     * (which carries the same numeric option values).
     */
    private function invoiceSelectHtml(string $html): string
    {
        if (preg_match('/<select[^>]*id="invoice"[^>]*>(.*?)<\/select>/s', $html, $matches)) {
            return $matches[1];
        }

        return '';
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

    private function makeNote(int $invoiceId, float $amount, ?string $disposition): int
    {
        return DB::table('credit_notes')->insertGetId([
            'uid' => 1, 'invoice' => $invoiceId, 'customer' => $this->customerId,
            'amount' => $amount, 'date' => now()->toDateString(),
            'description' => 'Setup note', 'disposition' => $disposition,
            'created_by' => $this->user->id, 'is_debit' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedPermissions(): void
    {
        foreach (['create credit note', 'edit credit note', 'delete credit note', 'manage credit note'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
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
            // Phase 2 (migration 310015): approval status, mirroring
            // JournalEntry — the controller sets it on every store,
            // so the fixture must carry the columns like the real table.
            $table->tinyInteger('status')->default(1);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
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
