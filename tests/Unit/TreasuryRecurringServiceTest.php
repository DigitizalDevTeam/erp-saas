<?php

namespace Tests\Unit;

use App\Exceptions\TreasuryException;
use App\Models\TreasuryRecurringOccurrence;
use App\Models\TreasuryRecurringRule;
use App\Services\TreasuryRecurringService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Treasury Phase 8 — recurring commitments (plan §5, §6, §10,
 * CA14).
 *
 * Users: 1 = company (rule creator), 2 = operator, 3 = approver,
 * 4 = other company. "Today" is frozen at 2026-11-07 so the
 * generation window is deterministic.
 */
class TreasuryRecurringServiceTest extends TestCase
{
    protected TreasuryRecurringService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-07 09:00:00'));
        $this->createTables();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Operator', 'email' => 'operator@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
        ]);

        $this->service = new TreasuryRecurringService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach (['treasury_recurring_occurrences', 'treasury_recurring_rules', 'settings', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Rules
    // ------------------------------------------------------------------

    public function test_create_rule_defaults_to_active_version_1_base_currency(): void
    {
        $rule = $this->service->createRule(1, $this->ruleData(), 2);

        $this->assertSame('active', $rule->state);
        $this->assertSame(1, $rule->version);
        $this->assertSame('MZN', $rule->currency);
        $this->assertSame('10000.0000', (string) $rule->amount);
        $this->assertSame('10000.0000', (string) $rule->amount_base);
        $this->assertSame(1, (int) $rule->company_id);
        $this->assertSame(1, (int) $rule->created_by);
    }

    public function test_create_rule_validation(): void
    {
        $this->expectException(TreasuryException::class);
        $this->service->createRule(1, $this->ruleData(['amount' => '0']), 2);
    }

    public function test_custom_rule_needs_interval_and_unit(): void
    {
        $this->expectException(TreasuryException::class);
        $this->service->createRule(1, $this->ruleData([
            'frequency' => 'custom', 'custom_interval' => null, 'custom_unit' => null,
        ]), 2);
    }

    // ------------------------------------------------------------------
    // CA14 — one occurrence per rule and period, however often the job runs
    // ------------------------------------------------------------------

    public function test_ca14_job_run_twice_creates_one_occurrence_per_rule_and_period(): void
    {
        $rule = $this->service->createRule(1, $this->ruleData([
            'start_date' => '2026-11-01', 'frequency' => 'monthly',
        ]), 2);

        $first = $this->service->generateOccurrences(1);
        $second = $this->service->generateOccurrences(1);

        // Window: 2026-10-08 .. 2027-02-05 → Nov, Dec, Jan, Feb.
        $this->assertSame(4, $first['generated']);
        $this->assertSame(0, $second['generated']);
        $this->assertSame(4, $second['skipped']);

        $occurrences = TreasuryRecurringOccurrence::where('rule_id', $rule->id)->orderBy('scheduled_date')->get();
        $this->assertCount(4, $occurrences);
        $this->assertSame(['2026-11', '2026-12', '2027-01', '2027-02'], $occurrences->pluck('period_key')->all());
        $this->assertSame(['2026-11-01', '2026-12-01', '2027-01-01', '2027-02-01'], $occurrences->map(fn ($o) => $o->scheduled_date->format('Y-m-d'))->all());
        $this->assertSame(['10000.0000', '10000.0000', '10000.0000', '10000.0000'], $occurrences->map(fn ($o) => number_format((float) $o->amount, 4, '.', ''))->all());
    }

    public function test_anchor_day_clamps_31_jan_to_28_feb_then_back_to_31_mar(): void
    {
        $rule = $this->service->createRule(1, $this->ruleData([
            'start_date' => '2026-01-31', 'frequency' => 'monthly', 'due_day' => 31,
        ]), 2);

        $result = $this->service->generateForRule(
            $rule,
            Carbon::parse('2026-01-15'),
            Carbon::parse('2026-03-31')
        );

        $this->assertSame(3, $result['generated']);
        $dates = TreasuryRecurringOccurrence::where('rule_id', $rule->id)
            ->orderBy('scheduled_date')
            ->pluck('scheduled_date')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))
            ->all();
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], $dates);
    }

    public function test_quarterly_and_custom_intervals(): void
    {
        $quarterly = $this->service->createRule(1, $this->ruleData([
            'beneficiary' => 'Quarterly fee', 'start_date' => '2026-01-15', 'frequency' => 'quarterly',
        ]), 2);
        $result = $this->service->generateForRule($quarterly, Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'));
        $this->assertSame(4, $result['generated']);

        $custom = $this->service->createRule(1, $this->ruleData([
            'beneficiary' => 'Fortnightly', 'start_date' => '2026-11-07', 'frequency' => 'custom',
            'custom_interval' => 14, 'custom_unit' => 'days',
        ]), 2);
        $result = $this->service->generateForRule($custom, Carbon::parse('2026-11-07'), Carbon::parse('2026-12-10'));
        $this->assertSame(3, $result['generated']);
        $dates = TreasuryRecurringOccurrence::where('rule_id', $custom->id)
            ->orderBy('scheduled_date')->pluck('scheduled_date')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m-d'))->all();
        $this->assertSame(['2026-11-07', '2026-11-21', '2026-12-05'], $dates);
    }

    public function test_ended_rule_generates_nothing(): void
    {
        $rule = $this->service->createRule(1, $this->ruleData(['start_date' => '2026-11-01']), 2);
        $this->service->endRule(1, $rule->id);
        $this->service->generateOccurrences(1);
        $this->assertSame(0, TreasuryRecurringOccurrence::where('rule_id', $rule->id)->count());
    }

    // ------------------------------------------------------------------
    // Changes apply to future unconfirmed occurrences only
    // ------------------------------------------------------------------

    public function test_rule_change_regenerates_future_unconfirmed_only(): void
    {
        $rule = $this->service->createRule(1, $this->ruleData([
            'start_date' => '2026-11-01', 'frequency' => 'monthly', 'amount' => '10000',
        ]), 2);
        $this->service->generateOccurrences(1);

        $november = TreasuryRecurringOccurrence::where('rule_id', $rule->id)->where('period_key', '2026-11')->firstOrFail();
        $this->service->confirmOccurrence(1, $november->id, 2);

        $this->service->updateRule(1, $rule->id, ['amount' => '12000']);

        $occurrences = TreasuryRecurringOccurrence::where('rule_id', $rule->id)->orderBy('scheduled_date')->get();
        $this->assertCount(4, $occurrences);

        // The confirmed November occurrence keeps its amount.
        $this->assertSame('10000.0000', number_format((float) $occurrences->firstWhere('period_key', '2026-11')->amount, 4, '.', ''));
        $this->assertSame('confirmed', $occurrences->firstWhere('period_key', '2026-11')->state);
        // Future generated occurrences carry the new amount.
        $this->assertSame('12000.0000', number_format((float) $occurrences->firstWhere('period_key', '2026-12')->amount, 4, '.', ''));
        $this->assertSame('12000.0000', number_format((float) $occurrences->firstWhere('period_key', '2027-01')->amount, 4, '.', ''));
        $this->assertSame('12000.0000', number_format((float) $occurrences->firstWhere('period_key', '2027-02')->amount, 4, '.', ''));
        $this->assertSame(2, $rule->fresh()->version);
    }

    public function test_non_schedule_change_leaves_occurrences_alone(): void
    {
        $rule = $this->service->createRule(1, $this->ruleData(['start_date' => '2026-11-01']), 2);
        $this->service->generateOccurrences(1);
        $before = TreasuryRecurringOccurrence::where('rule_id', $rule->id)->pluck('id')->all();

        $this->service->updateRule(1, $rule->id, ['beneficiary' => 'New name']);

        $this->assertSame($before, TreasuryRecurringOccurrence::where('rule_id', $rule->id)->pluck('id')->all());
    }

    public function test_pause_and_end_keep_earlier_occurrences(): void
    {
        $rule = $this->service->createRule(1, $this->ruleData(['start_date' => '2026-11-01']), 2);
        $this->service->generateOccurrences(1);
        $this->assertSame(4, TreasuryRecurringOccurrence::where('rule_id', $rule->id)->count());

        $this->service->pauseRule(1, $rule->id);
        $this->service->generateOccurrences(1);
        $this->assertSame(4, TreasuryRecurringOccurrence::where('rule_id', $rule->id)->count());

        $this->service->resumeRule(1, $rule->id);
        $this->service->endRule(1, $rule->id);
        $this->service->generateOccurrences(1);
        $this->assertSame(4, TreasuryRecurringOccurrence::where('rule_id', $rule->id)->count());
        $this->assertSame('ended', $rule->fresh()->state);

        $this->expectException(TreasuryException::class);
        $this->service->updateRule(1, $rule->id, ['amount' => '1']);
    }

    // ------------------------------------------------------------------
    // Occurrence flow
    // ------------------------------------------------------------------

    public function test_occurrence_confirm_paid_skip_flow_and_invalid_transitions(): void
    {
        $rule = $this->service->createRule(1, $this->ruleData(['start_date' => '2026-11-01']), 2);
        $this->service->generateOccurrences(1);

        $occurrences = TreasuryRecurringOccurrence::where('rule_id', $rule->id)->orderBy('scheduled_date')->get();

        $confirmed = $this->service->confirmOccurrence(1, $occurrences[0]->id, 2);
        $this->assertSame('confirmed', $confirmed->state);
        $this->assertSame(2, (int) $confirmed->confirmed_by);

        $paid = $this->service->markPaid(1, $occurrences[0]->id);
        $this->assertSame('paid', $paid->state);
        $this->assertNotNull($paid->paid_at);

        $skipped = $this->service->skipOccurrence(1, $occurrences[1]->id, 'Not needed this month');
        $this->assertSame('skipped', $skipped->state);
        $this->assertSame('Not needed this month', $skipped->skip_reason);

        // A paid occurrence cannot be paid again.
        $this->expectException(TreasuryException::class);
        $this->service->markPaid(1, $occurrences[0]->id);
    }

    public function test_self_confirm_refused_when_rule_needs_approval(): void
    {
        $rule = $this->service->createRule(1, $this->ruleData([
            'start_date' => '2026-11-01', 'approval_required' => true,
        ]), 2);
        $this->service->generateOccurrences(1);
        $occurrence = TreasuryRecurringOccurrence::where('rule_id', $rule->id)->firstOrFail();

        // The rule creator (user 1) cannot confirm its own occurrence.
        $this->expectException(TreasuryException::class);
        $this->service->confirmOccurrence(1, $occurrence->id, 1);

        // Another user can.
        $confirmed = $this->service->confirmOccurrence(1, $occurrence->id, 3);
        $this->assertSame('confirmed', $confirmed->state);
    }

    // ------------------------------------------------------------------
    // Company scope
    // ------------------------------------------------------------------

    public function test_cross_company_access_denied(): void
    {
        $rule = $this->service->createRule(1, $this->ruleData(['start_date' => '2026-11-01']), 2);
        $this->service->generateOccurrences(1);
        $occurrence = TreasuryRecurringOccurrence::where('rule_id', $rule->id)->firstOrFail();

        $this->expectException(TreasuryException::class);
        $this->service->ruleForCompany(4, $rule->id);

        try {
            $this->service->occurrenceForCompany(4, $occurrence->id);
            $this->fail('Expected a TreasuryException for the other company.');
        } catch (TreasuryException) {
            $this->assertTrue(true);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function ruleData(array $overrides = []): array
    {
        return array_merge([
            'beneficiary' => 'Landlord Lda',
            'description' => 'Monthly office rent',
            'category' => 'Rent',
            'direction' => 'outflow',
            'amount' => '10000',
            'amount_type' => 'fixed',
            'start_date' => '2026-11-01',
            'end_date' => null,
            'frequency' => 'monthly',
            'custom_interval' => null,
            'custom_unit' => null,
            'due_day' => null,
            'planned_account_id' => null,
            'approval_required' => false,
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

        Schema::create('settings', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('treasury_recurring_rules', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('beneficiary', 150);
            $table->string('description', 250)->nullable();
            $table->string('category', 100)->nullable();
            $table->string('direction', 10)->default('outflow');
            $table->string('currency', 3)->default('MZN');
            $table->decimal('amount', 20, 4);
            $table->decimal('amount_base', 20, 4);
            $table->string('amount_type', 20)->default('fixed');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('frequency', 20);
            $table->integer('custom_interval')->nullable();
            $table->string('custom_unit', 20)->nullable();
            $table->integer('due_day')->nullable();
            $table->bigInteger('planned_account_id')->nullable();
            $table->boolean('approval_required')->default(false);
            $table->string('state', 20)->default('active');
            $table->integer('version')->default(1);
            $table->timestamps();
        });

        Schema::create('treasury_recurring_occurrences', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('rule_id');
            $table->string('period_key', 20);
            $table->date('scheduled_date');
            $table->decimal('amount', 20, 4);
            $table->decimal('amount_base', 20, 4);
            $table->string('currency', 3)->default('MZN');
            $table->string('direction', 10)->default('outflow');
            $table->string('state', 20)->default('generated');
            $table->timestamp('generated_at')->nullable();
            $table->bigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->string('skip_reason', 500)->nullable();
            $table->integer('created_by');
            $table->timestamps();
            $table->unique(['rule_id', 'period_key'], 'tro_rule_period_uq');
        });
    }
}
