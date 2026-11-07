<?php

namespace Tests\Unit;

use App\Models\BillingContract;
use App\Models\BillingContractLine;
use App\Models\BillingContractOccurrence;
use App\Services\BillingOccurrenceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Contract Management Phase 1 — billing occurrence math (plan §2,
 * §6, AC01/AC04/AC05/AC10).
 *
 * Users: 1 = company. "Today" is frozen at 2027-01-10 so the
 * open-ended generation window is deterministic.
 */
class BillingOccurrenceServiceTest extends TestCase
{
    protected BillingOccurrenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2027-01-10 09:00:00'));
        $this->createTables();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);
        DB::table('customers')->insert([
            ['id' => 1, 'customer_id' => 1, 'name' => 'Hoya Hoya Lda', 'email' => 'hoya@test.local', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->service = new BillingOccurrenceService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach (['billing_contract_occurrences', 'billing_contract_lines', 'billing_contracts', 'customers', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // AC04 — anchor clamp + leap day
    // ------------------------------------------------------------------

    public function test_anchor_day_31_clamps_february_then_returns_to_31_march(): void
    {
        $contract = $this->makeContract([
            'start_date' => '2027-01-31', 'end_date' => '2027-12-31',
            'billing_frequency' => 'monthly', 'anchor_day' => 31,
        ]);

        $periods = $this->service->periods(
            $contract,
            Carbon::parse('2027-01-01'),
            Carbon::parse('2027-04-30')
        );

        $starts = array_map(fn ($p) => $p[0]->format('Y-m-d'), array_slice($periods, 0, 3));
        $ends = array_map(fn ($p) => $p[1]->format('Y-m-d'), array_slice($periods, 0, 3));

        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31'], $starts);
        $this->assertSame(['2027-02-28', '2027-03-31', '2027-04-30'], $ends);
    }

    public function test_leap_day_anchor_29_feb_returns_in_leap_years(): void
    {
        $contract = $this->makeContract([
            'start_date' => '2028-02-29', 'end_date' => '2032-12-31',
            'billing_frequency' => 'annual', 'anchor_day' => 29,
        ]);

        $periods = $this->service->periods(
            $contract,
            Carbon::parse('2028-01-01'),
            Carbon::parse('2032-12-31')
        );

        $starts = array_map(fn ($p) => $p[0]->format('Y-m-d'), array_slice($periods, 0, 3));

        $this->assertSame(['2028-02-29', '2029-02-28', '2030-02-28'], $starts);
    }

    public function test_quarterly_anchor_31_clamps_april_and_october(): void
    {
        $contract = $this->makeContract([
            'start_date' => '2027-01-31', 'end_date' => '2027-12-31',
            'billing_frequency' => 'quarterly', 'anchor_day' => 31,
        ]);

        $periods = $this->service->periods(
            $contract,
            Carbon::parse('2027-01-01'),
            Carbon::parse('2027-12-31')
        );

        $starts = array_map(fn ($p) => $p[0]->format('Y-m-d'), $periods);

        $this->assertSame(['2027-01-31', '2027-04-30', '2027-07-31', '2027-10-31'], $starts);
    }

    // ------------------------------------------------------------------
    // AC05 — advance vs arrears, AC01 — reference scenario
    // ------------------------------------------------------------------

    public function test_advance_bills_at_period_start_due_plus_terms(): void
    {
        $contract = $this->makeContract([
            'start_date' => '2027-01-01', 'end_date' => '2027-03-31',
            'billing_frequency' => 'monthly', 'anchor_day' => 1,
            'billing_mode' => 'advance', 'payment_terms_days' => 15,
        ]);

        $periods = $this->service->periods(
            $contract,
            Carbon::parse('2027-01-01'),
            Carbon::parse('2027-03-31')
        );

        $first = $this->service->occurrenceForPeriod($contract, $periods[0][0], $periods[0][1]);

        $this->assertSame('2027-01-01', $first['billing_date']->format('Y-m-d'));
        $this->assertSame('2027-01-16', $first['due_date']->format('Y-m-d'));
        $this->assertSame('5000.0000', $first['amount']);
        $this->assertFalse($first['partial']);
    }

    public function test_arrears_bills_at_period_end_due_plus_terms(): void
    {
        $contract = $this->makeContract([
            'start_date' => '2027-01-01', 'end_date' => '2027-03-31',
            'billing_frequency' => 'monthly', 'anchor_day' => 1,
            'billing_mode' => 'arrears', 'payment_terms_days' => 15,
        ]);

        $periods = $this->service->periods(
            $contract,
            Carbon::parse('2027-01-01'),
            Carbon::parse('2027-03-31')
        );

        $first = $this->service->occurrenceForPeriod($contract, $periods[0][0], $periods[0][1]);

        $this->assertSame('2027-02-01', $first['billing_date']->format('Y-m-d'));
        $this->assertSame('2027-02-16', $first['due_date']->format('Y-m-d'));
    }

    public function test_reference_scenario_36_monthly_occurrences(): void
    {
        $contract = $this->makeContract([
            'reference' => 'CNT-2027-0001',
            'start_date' => '2027-01-01', 'end_date' => '2029-12-31',
            'billing_frequency' => 'monthly', 'anchor_day' => 1,
            'billing_mode' => 'advance', 'payment_terms_days' => 15,
            'state' => 'active',
        ]);

        $result = $this->service->generateForContract($contract, 1);

        $this->assertSame(36, $result['generated']);
        $this->assertSame(0, $result['skipped']);

        $occurrences = BillingContractOccurrence::where('contract_id', $contract->id)
            ->orderBy('period_start')->get();

        $this->assertCount(36, $occurrences);
        $this->assertSame('2027-01-01', $occurrences->first()->period_start->format('Y-m-d'));
        $this->assertSame('2027-01-01', $occurrences->first()->billing_date->format('Y-m-d'));
        $this->assertSame('2027-01-16', $occurrences->first()->due_date->format('Y-m-d'));
        $this->assertSame('2029-12-01', $occurrences->last()->period_start->format('Y-m-d'));
        $this->assertSame('2029-12-01', $occurrences->last()->billing_date->format('Y-m-d'));
        $this->assertSame('2029-12-16', $occurrences->last()->due_date->format('Y-m-d'));
        $this->assertSame(['5000.0000'], $occurrences->pluck('amount')->map(fn ($a) => number_format((float) $a, 4, '.', ''))->unique()->all());
        $this->assertSame('CNT-2027-0001:2027-01:invoice', $occurrences->first()->occurrence_key);
        $this->assertSame('CNT-2027-0001:2029-12:invoice', $occurrences->last()->occurrence_key);
    }

    // ------------------------------------------------------------------
    // Idempotency + partial-period rules
    // ------------------------------------------------------------------

    public function test_generate_twice_creates_one_occurrence_per_period(): void
    {
        $contract = $this->makeContract([
            'start_date' => '2027-01-01', 'end_date' => '2027-06-30',
            'billing_frequency' => 'monthly', 'anchor_day' => 1,
            'state' => 'active',
        ]);

        $first = $this->service->generateForContract($contract, 1);
        $second = $this->service->generateForContract($contract, 1);

        $this->assertSame(6, $first['generated']);
        $this->assertSame(0, $second['generated']);
        $this->assertSame(6, $second['skipped']);
        $this->assertSame(6, BillingContractOccurrence::where('contract_id', $contract->id)->count());
    }

    public function test_partial_first_period_full_charge_by_default(): void
    {
        $contract = $this->makeContract([
            'start_date' => '2027-01-15', 'end_date' => '2027-03-31',
            'billing_frequency' => 'monthly', 'anchor_day' => 1,
            'partial_period_rule' => 'full',
        ]);

        $periods = $this->service->periods(
            $contract,
            Carbon::parse('2027-01-01'),
            Carbon::parse('2027-03-31')
        );

        $first = $this->service->occurrenceForPeriod($contract, $periods[0][0], $periods[0][1]);

        $this->assertTrue($first['partial']);
        $this->assertSame(17, $first['covered_days']);
        $this->assertSame(31, $first['full_days']);
        $this->assertSame('5000.0000', $first['amount']);
        // An advance invoice is never issued before the contract starts.
        $this->assertSame('2027-01-15', $first['billing_date']->format('Y-m-d'));
    }

    public function test_partial_first_period_prorated_by_days(): void
    {
        $contract = $this->makeContract([
            'start_date' => '2027-01-15', 'end_date' => '2027-03-31',
            'billing_frequency' => 'monthly', 'anchor_day' => 1,
            'partial_period_rule' => 'prorate', 'proration_enabled' => true,
        ]);

        $periods = $this->service->periods(
            $contract,
            Carbon::parse('2027-01-01'),
            Carbon::parse('2027-03-31')
        );

        $first = $this->service->occurrenceForPeriod($contract, $periods[0][0], $periods[0][1]);
        $second = $this->service->occurrenceForPeriod($contract, $periods[1][0], $periods[1][1]);

        // 5000 * 17 / 31 = 2741.9355 (rounded to 4dp).
        $this->assertSame('2741.9355', $first['amount']);
        $this->assertSame('5000.0000', $second['amount']);
    }

    public function test_partial_first_period_skipped_by_rule(): void
    {
        $contract = $this->makeContract([
            'start_date' => '2027-01-15', 'end_date' => '2027-03-31',
            'billing_frequency' => 'monthly', 'anchor_day' => 1,
            'partial_period_rule' => 'skip',
            'state' => 'active',
        ]);

        $result = $this->service->generateForContract($contract, 1);

        $this->assertSame(2, $result['generated']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(
            ['2027-02-01', '2027-03-01'],
            BillingContractOccurrence::where('contract_id', $contract->id)->orderBy('period_start')->pluck('period_start')->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))->all()
        );
    }

    public function test_partial_final_period_prorated(): void
    {
        $contract = $this->makeContract([
            'start_date' => '2027-01-01', 'end_date' => '2027-03-15',
            'billing_frequency' => 'monthly', 'anchor_day' => 1,
            'partial_period_rule' => 'prorate', 'proration_enabled' => true,
        ]);

        $periods = $this->service->periods(
            $contract,
            Carbon::parse('2027-01-01'),
            Carbon::parse('2027-03-15')
        );

        $last = $this->service->occurrenceForPeriod($contract, $periods[2][0], $periods[2][1]);

        // March: 5000 * 15 / 31 = 2419.3548.
        $this->assertTrue($last['partial']);
        $this->assertSame('2419.3548', $last['amount']);
    }

    public function test_custom_frequency_every_two_weeks(): void
    {
        $contract = $this->makeContract([
            'start_date' => '2027-01-01', 'end_date' => '2027-02-28',
            'billing_frequency' => 'custom', 'custom_interval' => 2, 'custom_unit' => 'weeks',
            'anchor_day' => 1,
        ]);

        $periods = $this->service->periods(
            $contract,
            Carbon::parse('2027-01-01'),
            Carbon::parse('2027-02-28')
        );

        $starts = array_map(fn ($p) => $p[0]->format('Y-m-d'), $periods);

        $this->assertSame(['2027-01-01', '2027-01-15', '2027-01-29', '2027-02-12', '2027-02-26'], $starts);
    }

    public function test_occurrence_key_unique_constraint_enforced(): void
    {
        $contract = $this->makeContract([
            'start_date' => '2027-01-01', 'end_date' => '2027-03-31',
            'billing_frequency' => 'monthly', 'anchor_day' => 1,
            'state' => 'active',
        ]);

        $this->service->generateForContract($contract, 1);

        $this->expectException(\Illuminate\Database\QueryException::class);
        BillingContractOccurrence::create([
            'contract_id' => $contract->id,
            'created_by' => 1,
            'occurrence_key' => 'CNT-2027-0001:2027-01:invoice',
            'period_key' => '2027-01',
            'period_start' => '2027-01-01',
            'period_end' => '2027-02-01',
            'billing_date' => '2027-01-01',
            'due_date' => '2027-01-16',
            'amount' => '5000.0000',
            'currency' => 'MZN',
            'state' => 'pending',
            'purpose' => 'invoice',
        ]);
    }

    public function test_open_ended_contract_generates_twelve_months(): void
    {
        $contract = $this->makeContract([
            'start_date' => '2027-01-01', 'end_date' => null,
            'billing_frequency' => 'monthly', 'anchor_day' => 1,
            'state' => 'active',
        ]);

        $end = $this->service->generationEnd($contract);

        $this->assertSame('2028-01-10', $end->format('Y-m-d'));

        $result = $this->service->generateForContract($contract, 1);

        // Jan 2027 .. Jan 2028 = 13 periods (window is today + 12mo).
        $this->assertSame(13, $result['generated']);
    }

    public function test_generate_refused_for_draft_contract(): void
    {
        $contract = $this->makeContract([
            'start_date' => '2027-01-01', 'end_date' => '2027-03-31',
            'billing_frequency' => 'monthly', 'anchor_day' => 1,
            'state' => 'draft',
        ]);

        $this->expectException(\App\Exceptions\BillingContractException::class);
        $this->service->generateForContract($contract, 1);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function makeContract(array $overrides = []): BillingContract
    {
        $contract = BillingContract::create(array_merge([
            'created_by' => 1,
            'customer_id' => 1,
            'reference' => 'CNT-2027-0001',
            'title' => 'Monthly support',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
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
            'state' => 'draft',
        ], $overrides));

        BillingContractLine::create([
            'contract_id' => $contract->id,
            'product_id' => null,
            'description' => 'Monthly support',
            'quantity' => '1.0000',
            'unit_price' => '5000.0000',
            'discount_amount' => '0.0000',
            'tax_id' => null,
            'amount' => '5000.0000',
            'created_by' => 1,
        ]);

        return $contract->fresh();
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
    }
}
