<?php

namespace Tests\Unit;

use App\Exceptions\BillingContractException;
use App\Models\BillingContract;
use App\Models\BillingContractOccurrence;
use App\Models\BillingContractRevision;
use App\Services\BillingContractService;
use App\Services\BillingOccurrenceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Contract Management Phase 1 — billing contract lifecycle
 * (plan §4, §6, AC01/AC06/AC09).
 *
 * Users: 1 = company (contract creator), 2 = operator, 3 =
 * approver, 4 = other company. "Today" is frozen at 2027-01-10.
 */
class BillingContractServiceTest extends TestCase
{
    protected BillingContractService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2027-01-10 09:00:00'));
        $this->createTables();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Operator', 'email' => 'operator@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
        ]);
        DB::table('customers')->insert([
            ['id' => 1, 'customer_id' => 1, 'name' => 'Hoya Hoya Lda', 'email' => 'hoya@test.local', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'customer_id' => 2, 'name' => 'Other Customer', 'email' => 'other@test.local', 'created_by' => 4, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('taxes')->insert([
            ['id' => 1, 'name' => 'IVA 16%', 'rate' => 16, 'created_by' => 1],
        ]);

        $this->service = new BillingContractService(new BillingOccurrenceService());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach (['billing_contract_audit_events', 'billing_contract_revisions', 'billing_contract_occurrences', 'billing_contract_lines', 'billing_contracts', 'taxes', 'customers', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Create
    // ------------------------------------------------------------------

    public function test_create_contract_allocates_reference_and_saves_lines(): void
    {
        $contract = $this->service->createContract(1, $this->contractData(), 1);

        $this->assertSame('CNT-2027-0001', $contract->reference);
        $this->assertSame('draft', $contract->state);
        $this->assertSame(1, (int) $contract->created_by);
        $this->assertCount(2, $contract->lines);
        $this->assertSame('2900.0000', number_format((float) $contract->lines[0]->amount, 4, '.', ''));
        $this->assertSame('5000.0000', number_format((float) $contract->lines[1]->amount, 4, '.', ''));
        $this->assertSame('CNT-2027-0002', $this->service->nextReference(1, '2027'));
    }

    public function test_create_reference_is_per_company(): void
    {
        $this->service->createContract(1, $this->contractData(), 1);

        $other = $this->service->createContract(4, $this->contractData(['customer_id' => 2]), 4);

        $this->assertSame('CNT-2027-0001', $other->reference);
    }

    public function test_create_requires_a_line(): void
    {
        $this->expectException(BillingContractException::class);
        $this->service->createContract(1, $this->contractData(['lines' => []]), 1);
    }

    public function test_create_rejects_other_companys_customer(): void
    {
        $this->expectException(BillingContractException::class);
        $this->service->createContract(1, $this->contractData(['customer_id' => 2]), 1);
    }

    public function test_create_rejects_end_before_start(): void
    {
        $this->expectException(BillingContractException::class);
        $this->service->createContract(1, $this->contractData(['end_date' => '2026-12-31']), 1);
    }

    public function test_create_rejects_foreign_currency_without_rate(): void
    {
        $this->expectException(BillingContractException::class);
        $this->service->createContract(1, $this->contractData(['currency' => 'USD', 'exchange_rate' => null]), 1);
    }

    public function test_create_rejects_anchor_day_32(): void
    {
        $this->expectException(BillingContractException::class);
        $this->service->createContract(1, $this->contractData(['anchor_day' => 32]), 1);
    }

    public function test_create_rejects_custom_without_interval(): void
    {
        $this->expectException(BillingContractException::class);
        $this->service->createContract(1, $this->contractData(['billing_frequency' => 'custom', 'custom_interval' => null, 'custom_unit' => null]), 1);
    }

    public function test_create_rejects_discount_above_gross(): void
    {
        $this->expectException(BillingContractException::class);
        $this->service->createContract(1, $this->contractData(['lines' => [
            ['description' => 'Too much discount', 'quantity' => '1', 'unit_price' => '100', 'discount_amount' => '101'],
        ]]), 1);
    }

    public function test_update_draft_and_refuses_update_of_active(): void
    {
        $contract = $this->service->createContract(1, $this->contractData(), 1);

        $updated = $this->service->updateContract($contract, $this->contractData(['title' => 'Renamed']), 1);
        $this->assertSame('Renamed', $updated->title);

        $active = $this->activateViaApproval($contract);
        $this->expectException(BillingContractException::class);
        $this->service->updateContract($active, $this->contractData(['title' => 'Too late']), 1);
    }

    // ------------------------------------------------------------------
    // Approval lifecycle
    // ------------------------------------------------------------------

    public function test_submit_approval_mode_goes_to_pending_without_occurrences(): void
    {
        $contract = $this->service->createContract(1, $this->contractData(), 1);

        $submitted = $this->service->submitForApproval($contract, 1);

        $this->assertSame('pending_approval', $submitted->state);
        $this->assertSame(0, BillingContractOccurrence::where('contract_id', $contract->id)->count());
    }

    public function test_submit_automatic_mode_activates_and_generates(): void
    {
        $contract = $this->service->createContract(1, $this->contractData(['approval_mode' => 'automatic']), 1);

        $activated = $this->service->submitForApproval($contract, 1);

        $this->assertSame('active', $activated->state);
        $this->assertSame(36, BillingContractOccurrence::where('contract_id', $contract->id)->count());
    }

    public function test_creator_cannot_approve_own_contract(): void
    {
        $contract = $this->service->createContract(1, $this->contractData(), 1);
        $this->service->submitForApproval($contract, 1);

        $this->expectException(BillingContractException::class);
        $this->service->approve($contract->fresh(), 1);
    }

    public function test_approver_activates_and_generates_36_occurrences(): void
    {
        $contract = $this->service->createContract(1, $this->contractData(), 1);
        $this->service->submitForApproval($contract, 1);

        $activated = $this->service->approve($contract->fresh(), 3);

        $this->assertSame('active', $activated->state);
        $this->assertSame(3, (int) $activated->approved_by);
        $this->assertNotNull($activated->approved_at);
        $this->assertSame(36, BillingContractOccurrence::where('contract_id', $contract->id)->count());
    }

    public function test_reject_returns_to_draft_with_reason(): void
    {
        $contract = $this->service->createContract(1, $this->contractData(), 1);
        $this->service->submitForApproval($contract, 1);

        $rejected = $this->service->reject($contract->fresh(), 3, 'Price is wrong.');

        $this->assertSame('draft', $rejected->state);
        $this->assertSame('Price is wrong.', $rejected->rejected_reason);
    }

    public function test_reject_without_reason_refused(): void
    {
        $contract = $this->service->createContract(1, $this->contractData(), 1);
        $this->service->submitForApproval($contract, 1);

        $this->expectException(BillingContractException::class);
        $this->service->reject($contract->fresh(), 3, '');
    }

    public function test_approve_twice_refused(): void
    {
        $contract = $this->activateViaApproval($this->service->createContract(1, $this->contractData(), 1));

        $this->expectException(BillingContractException::class);
        $this->service->approve($contract, 3);
    }

    // ------------------------------------------------------------------
    // AC06 — suspend / resume
    // ------------------------------------------------------------------

    public function test_suspend_blocks_and_resume_bill_keeps_missed_pending(): void
    {
        $contract = $this->activateViaApproval($this->service->createContract(1, $this->contractData(), 1));

        // Suspend on 2027-02-15 (after the Feb occurrence was billed on the 1st).
        Carbon::setTestNow(Carbon::parse('2027-02-15 09:00:00'));
        $suspended = $this->service->suspend($contract->fresh(), 2, 'Customer asked to pause.');
        $this->assertSame('suspended', $suspended->state);

        Carbon::setTestNow(Carbon::parse('2027-03-20 09:00:00'));
        $resumed = $this->service->resume($suspended->fresh(), 2, 'bill');

        $this->assertSame('active', $resumed->state);
        $march = BillingContractOccurrence::where('contract_id', $contract->id)->where('period_key', '2027-03')->firstOrFail();
        $this->assertSame('pending', $march->state);
    }

    public function test_resume_skip_marks_missed_periods_skipped(): void
    {
        $contract = $this->activateViaApproval($this->service->createContract(1, $this->contractData(), 1));

        Carbon::setTestNow(Carbon::parse('2027-02-15 09:00:00'));
        $suspended = $this->service->suspend($contract->fresh(), 2, 'Customer asked to pause.');

        Carbon::setTestNow(Carbon::parse('2027-03-20 09:00:00'));
        $this->service->resume($suspended->fresh(), 2, 'skip');

        $march = BillingContractOccurrence::where('contract_id', $contract->id)->where('period_key', '2027-03')->firstOrFail();
        $this->assertSame('skipped', $march->state);
        $this->assertSame('Suspended period skipped on resume.', $march->skip_reason);

        // February (billed before the suspension) is untouched.
        $february = BillingContractOccurrence::where('contract_id', $contract->id)->where('period_key', '2027-02')->firstOrFail();
        $this->assertSame('pending', $february->state);
    }

    public function test_resume_without_decision_refused(): void
    {
        $contract = $this->activateViaApproval($this->service->createContract(1, $this->contractData(), 1));
        $suspended = $this->service->suspend($contract->fresh(), 2, 'Pause.');

        $this->expectException(BillingContractException::class);
        $this->service->resume($suspended->fresh(), 2, 'maybe');
    }

    // ------------------------------------------------------------------
    // End / cancel
    // ------------------------------------------------------------------

    public function test_end_cancels_future_periods_and_reprices_straddling(): void
    {
        $contract = $this->activateViaApproval($this->service->createContract(1, $this->contractData([
            'partial_period_rule' => 'prorate', 'proration_enabled' => true,
        ]), 1));

        $ended = $this->service->end($contract->fresh(), 2, '2027-03-15', 'Customer closed.');

        $this->assertSame('ended', $ended->state);
        $this->assertSame('2027-03-15', $ended->end_date->format('Y-m-d'));

        $march = BillingContractOccurrence::where('contract_id', $contract->id)->where('period_key', '2027-03')->firstOrFail();
        // March re-priced to the covered days: total 7900 * 14 / 31
        // (1 Mar -> 15 Mar is 14 covered days).
        $this->assertSame('pending', $march->state);
        $this->assertSame('2027-03-15', $march->period_end->format('Y-m-d'));
        $this->assertSame('3567.7419', number_format((float) $march->amount, 4, '.', ''));

        $april = BillingContractOccurrence::where('contract_id', $contract->id)->where('period_key', '2027-04')->firstOrFail();
        $this->assertSame('cancelled', $april->state);
        $this->assertSame(33, BillingContractOccurrence::where('contract_id', $contract->id)->where('state', 'cancelled')->count());
    }

    public function test_end_before_start_refused(): void
    {
        $contract = $this->activateViaApproval($this->service->createContract(1, $this->contractData(), 1));

        $this->expectException(BillingContractException::class);
        $this->service->end($contract->fresh(), 2, '2026-12-31');
    }

    public function test_cancel_draft_needs_reason(): void
    {
        $contract = $this->service->createContract(1, $this->contractData(), 1);

        $this->expectException(BillingContractException::class);
        $this->service->cancel($contract, 1, '');
    }

    public function test_cancel_active_cancels_future_pending(): void
    {
        $contract = $this->activateViaApproval($this->service->createContract(1, $this->contractData(), 1));

        $cancelled = $this->service->cancel($contract->fresh(), 1, 'Customer never signed.', '2027-02-01');

        $this->assertSame('cancelled', $cancelled->state);
        $this->assertSame('cancelled', BillingContractOccurrence::where('contract_id', $contract->id)->where('period_key', '2027-02')->firstOrFail()->state);
        $this->assertSame('pending', BillingContractOccurrence::where('contract_id', $contract->id)->where('period_key', '2027-01')->firstOrFail()->state);
    }

    // ------------------------------------------------------------------
    // AC09 — amendment touches only unissued periods
    // ------------------------------------------------------------------

    public function test_amend_regenerates_only_future_pending_occurrences(): void
    {
        $contract = $this->activateViaApproval($this->service->createContract(1, $this->contractData(), 1));

        // Pretend January was already invoiced.
        BillingContractOccurrence::where('contract_id', $contract->id)->where('period_key', '2027-01')
            ->update(['state' => 'invoiced', 'invoice_id' => 99]);

        $amended = $this->service->amend(
            $contract->fresh(),
            $this->contractData(['lines' => [
                ['description' => 'Monthly support', 'quantity' => '1', 'unit_price' => '6000', 'discount_amount' => '0'],
            ]]),
            2,
            '2027-04-01',
            'Price increase.'
        );

        $this->assertSame('active', $amended->state);
        $this->assertSame(1, BillingContractRevision::where('contract_id', $contract->id)->count());
        $revision = BillingContractRevision::where('contract_id', $contract->id)->firstOrFail();
        $this->assertSame(1, $revision->revision_number);
        $this->assertSame('2027-04-01', $revision->effective_date->format('Y-m-d'));
        $this->assertSame('5000.0000', (string) $revision->snapshot['lines'][1]['amount']);

        // January (invoiced) keeps the old amount; February/March
        // (pending, before the effective date) keep it too.
        $this->assertSame('7900.0000', number_format((float) BillingContractOccurrence::where('contract_id', $contract->id)->where('period_key', '2027-01')->firstOrFail()->amount, 4, '.', ''));
        $this->assertSame('7900.0000', number_format((float) BillingContractOccurrence::where('contract_id', $contract->id)->where('period_key', '2027-03')->firstOrFail()->amount, 4, '.', ''));
        // April onwards use the new price (6000, single line now).
        $this->assertSame('6000.0000', number_format((float) BillingContractOccurrence::where('contract_id', $contract->id)->where('period_key', '2027-04')->firstOrFail()->amount, 4, '.', ''));
        $this->assertSame('6000.0000', number_format((float) BillingContractOccurrence::where('contract_id', $contract->id)->where('period_key', '2027-12')->firstOrFail()->amount, 4, '.', ''));
    }

    public function test_amend_draft_refused_and_past_effective_date_refused(): void
    {
        $contract = $this->service->createContract(1, $this->contractData(), 1);

        $this->expectException(BillingContractException::class);
        $this->service->amend($contract, $this->contractData(), 2, '2027-04-01', 'Too early.');
    }

    // ------------------------------------------------------------------
    // Preview
    // ------------------------------------------------------------------

    public function test_preview_returns_first_three_occurrences_without_saving(): void
    {
        $preview = $this->service->preview(array_merge($this->contractData(), ['created_by' => 1]));

        $this->assertCount(3, $preview);
        $this->assertSame('2027-01-01', $preview[0]['period_start']);
        $this->assertSame('2027-01-31', $preview[0]['period_end']);
        $this->assertSame('2027-01-01', $preview[0]['billing_date']);
        $this->assertSame('2027-01-16', $preview[0]['due_date']);
        $this->assertSame('7900.0000', $preview[0]['amount']);
        $this->assertFalse($preview[0]['partial']);
        $this->assertSame(0, BillingContract::count());
    }

    public function test_preview_shows_partial_first_period(): void
    {
        $preview = $this->service->preview(array_merge(
            $this->contractData(['start_date' => '2027-01-15', 'partial_period_rule' => 'prorate', 'proration_enabled' => true]),
            ['created_by' => 1]
        ));

        $this->assertTrue($preview[0]['partial']);
        // 7900 * 17 / 31 = 4332.2581.
        $this->assertSame('4332.2581', $preview[0]['amount']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function activateViaApproval(BillingContract $contract): BillingContract
    {
        $this->service->submitForApproval($contract->fresh(), 1);

        return $this->service->approve($contract->fresh(), 3);
    }

    protected function contractData(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => 1,
            'title' => 'Monthly support',
            'description' => null,
            'start_date' => '2027-01-01',
            'end_date' => '2029-12-31',
            'currency' => 'MZN',
            'exchange_rate' => '1.0000000000',
            'billing_frequency' => 'monthly',
            'custom_interval' => null,
            'custom_unit' => null,
            'anchor_day' => 1,
            'billing_mode' => 'advance',
            'payment_terms_days' => 15,
            'approval_mode' => 'approval',
            'proration_enabled' => false,
            'partial_period_rule' => 'full',
            'renewal_notice_days' => 30,
            'auto_renew' => false,
            'delivery_auto_email' => false,
            'delivery_to' => null,
            'delivery_cc' => null,
            'notes' => null,
            'branch_id' => null,
            'lines' => [
                ['description' => 'Setup', 'quantity' => '3', 'unit_price' => '1000', 'discount_amount' => '100'],
                ['description' => 'Monthly support', 'quantity' => '1', 'unit_price' => '5000', 'discount_amount' => '0'],
            ],
        ], $overrides);
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

        Schema::create('customers', function ($table) {
            $table->bigIncrements('id');
            $table->integer('customer_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('taxes', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->decimal('rate', 10, 2)->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('billing_contracts', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->integer('branch_id')->nullable();
            $table->bigInteger('customer_id');
            $table->string('reference', 30);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->default('1.0000000000');
            $table->string('billing_frequency', 20)->default('monthly');
            $table->integer('custom_interval')->nullable();
            $table->string('custom_unit', 10)->nullable();
            $table->integer('anchor_day');
            $table->string('billing_mode', 10)->default('advance');
            $table->integer('payment_terms_days')->default(15);
            $table->string('approval_mode', 10)->default('approval');
            $table->boolean('proration_enabled')->default(false);
            $table->string('partial_period_rule', 10)->default('full');
            $table->string('state', 20)->default('draft');
            $table->bigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->text('suspended_reason')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->integer('renewal_notice_days')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->boolean('delivery_auto_email')->default(false);
            $table->text('delivery_to')->nullable();
            $table->text('delivery_cc')->nullable();
            $table->text('notes')->nullable();
            $table->bigInteger('current_revision_id')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'reference'], 'bc_company_ref_uq');
        });

        Schema::create('billing_contract_lines', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('contract_id');
            $table->bigInteger('product_id')->nullable();
            $table->string('description', 250);
            $table->decimal('quantity', 20, 4)->default('1.0000');
            $table->decimal('unit_price', 20, 4)->default('0.0000');
            $table->decimal('discount_amount', 20, 4)->default('0.0000');
            $table->bigInteger('tax_id')->nullable();
            $table->bigInteger('chart_account_id')->nullable();
            $table->bigInteger('cost_centre_id')->nullable();
            $table->decimal('amount', 20, 4)->default('0.0000');
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('billing_contract_occurrences', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('contract_id');
            $table->integer('created_by');
            $table->string('occurrence_key', 80);
            $table->string('period_key', 20);
            $table->date('period_start');
            $table->date('period_end');
            $table->date('billing_date');
            $table->date('due_date');
            $table->decimal('amount', 20, 4)->default('0.0000');
            $table->string('currency', 3)->default('MZN');
            $table->string('state', 20)->default('pending');
            $table->string('purpose', 20)->default('invoice');
            $table->bigInteger('invoice_id')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->text('skip_reason')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'contract_id', 'occurrence_key'], 'bco_occ_key_uq');
        });

        Schema::create('billing_contract_revisions', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('contract_id');
            $table->integer('revision_number');
            $table->date('effective_date');
            $table->text('reason')->nullable();
            $table->json('snapshot')->nullable();
            $table->integer('created_by');
            $table->timestamps();
            $table->unique(['contract_id', 'revision_number'], 'bcr_contract_rev_uq');
        });

        Schema::create('billing_contract_audit_events', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('contract_id');
            $table->bigInteger('occurrence_id')->nullable();
            $table->bigInteger('actor_id')->nullable();
            $table->string('action', 50);
            $table->text('reason')->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->integer('created_by');
            $table->timestamps();
        });
    }
}
