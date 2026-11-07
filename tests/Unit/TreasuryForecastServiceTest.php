<?php

namespace Tests\Unit;

use App\Exceptions\TreasuryException;
use App\Models\Invoice;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryForecastItem;
use App\Services\TreasuryForecastService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Treasury Phase 8 — cash-flow forecast (plan §5, §8, §10, CA13).
 *
 * Users: 1 = company, 2 = operator, 4 = other company. The
 * account is funded with 200,000 MZN. "Today" is frozen at
 * 2026-11-07 (a Saturday) so dates are deterministic.
 */
class TreasuryForecastServiceTest extends TestCase
{
    protected TreasuryForecastService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-07 09:00:00'));
        $this->createTables();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Operator', 'email' => 'operator@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
        ]);

        $bankId = DB::table('bank_accounts')->insertGetId([
            'holder_name' => 'Hoya Hoya Lda', 'bank_name' => 'BIM', 'account_number' => '123456789',
            'chart_account_id' => 1, 'opening_balance' => 0, 'contact_number' => '', 'bank_address' => '',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        TreasuryFinancialAccount::create([
            'bank_account_id' => $bankId, 'created_by' => 1, 'code' => 'BIM-001',
            'type' => 'bank', 'currency' => 'MZN', 'status' => 'active',
            'negative_balance_policy' => 'block',
        ]);

        DB::table('treasury_opening_balances')->insert([
            'financial_account_id' => 1, 'created_by' => 1, 'currency' => 'MZN',
            'amount' => '200000.0000', 'amount_base' => '200000.0000',
            'cut_off_date' => '2026-11-01', 'batch_reference' => 'BATCH-1',
            'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->service = new TreasuryForecastService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach (['treasury_forecast_items', 'pay_slips', 'credit_notes', 'invoice_payments', 'invoice_products', 'invoices', 'customers', 'taxes', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'dimension_audit_events', 'bank_accounts', 'chart_of_accounts', 'settings', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Forecast math
    // ------------------------------------------------------------------

    public function test_refresh_builds_opening_balance_item_and_calendar_totals(): void
    {
        $counts = $this->service->refreshForecast(1);

        $this->assertSame(1, $counts['balances']);
        $this->assertSame(0, $counts['invoices']);
        $this->assertSame(0, $counts['payroll']);

        $calendar = $this->service->forecast(1, 30, 'baseline', 'day');

        $this->assertSame('200000.0000', $calendar['opening_balance']);
        // No forecast movements: the opening balance is the
        // starting point, not an inflow — the balance item is not
        // counted twice.
        $this->assertSame('0.0000', $calendar['totals']['inflow']);
        $this->assertSame('0.0000', $calendar['totals']['outflow']);
        $this->assertSame('200000.0000', $calendar['totals']['closing']);
        $this->assertNull($calendar['first_day_below_threshold']);
        $this->assertCount(30, $calendar['periods']);
    }

    public function test_open_invoice_appears_at_outstanding_amount_on_its_due_date(): void
    {
        $invoice = $this->makeInvoice(2, '2026-11-20', [['price' => '5000', 'quantity' => 2]]);
        $this->service->refreshForecast(1);

        $item = TreasuryForecastItem::where('created_by', 1)
            ->where('source_type', 'invoice')
            ->where('source_id', $invoice->id)
            ->firstOrFail();

        $this->assertSame('10000.0000', number_format((float) $item->amount_base, 4, '.', ''));
        $this->assertSame('inflow', $item->direction);
        $this->assertSame('2026-11-20', $item->expected_date->format('Y-m-d'));

        $calendar = $this->service->forecast(1, 30, 'baseline', 'day');
        $this->assertSame('10000.0000', $calendar['totals']['inflow']);
        $this->assertSame('210000.0000', $calendar['totals']['closing']);

        $day20 = collect($calendar['periods'])->firstWhere('key', '2026-11-20');
        $this->assertSame('10000.0000', $day20['inflow']);
    }

    public function test_partial_payment_leaves_only_the_outstanding_amount(): void
    {
        $invoice = $this->makeInvoice(2, '2026-11-20', [['price' => '5000', 'quantity' => 2]]);
        DB::table('invoice_payments')->insert([
            'invoice_id' => $invoice->id, 'date' => '2026-11-07', 'amount' => '4000.00',
            'account_id' => 0, 'payment_method' => 0, 'payment_type' => 'Manually',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->service->refreshForecast(1);

        $item = TreasuryForecastItem::where('created_by', 1)
            ->where('source_type', 'invoice')
            ->firstOrFail();
        $this->assertSame('6000.0000', number_format((float) $item->amount_base, 4, '.', ''));

        $calendar = $this->service->forecast(1, 30, 'baseline', 'day');
        $this->assertSame('206000.0000', $calendar['totals']['closing']);
    }

    public function test_paid_invoice_and_cancelled_invoice_are_excluded(): void
    {
        $this->makeInvoice(4, '2026-11-20', [['price' => '5000', 'quantity' => 1]]);
        $this->makeInvoice(5, '2026-11-20', [['price' => '5000', 'quantity' => 1]]);
        $this->service->refreshForecast(1);

        $this->assertSame(0, TreasuryForecastItem::where('created_by', 1)->where('source_type', 'invoice')->count());
    }

    public function test_unpaid_payslip_is_an_outflow_on_the_last_day_of_its_month(): void
    {
        DB::table('pay_slips')->insert([
            ['employee_id' => 2, 'net_payble' => 15000, 'salary_month' => '2026-11', 'status' => 0, 'basic_salary' => 15000, 'allowance' => '{}', 'commission' => '{}', 'loan' => '{}', 'saturation_deduction' => '{}', 'other_payment' => '{}', 'overtime' => '{}', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['employee_id' => 2, 'net_payble' => 9000, 'salary_month' => '2026-10', 'status' => 1, 'basic_salary' => 9000, 'allowance' => '{}', 'commission' => '{}', 'loan' => '{}', 'saturation_deduction' => '{}', 'other_payment' => '{}', 'overtime' => '{}', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->service->refreshForecast(1);

        $items = TreasuryForecastItem::where('created_by', 1)->where('source_type', 'payroll')->get();
        $this->assertCount(1, $items);
        $this->assertSame('15000.0000', number_format((float) $items[0]->amount_base, 4, '.', ''));
        $this->assertSame('outflow', $items[0]->direction);
        $this->assertSame('2026-11-30', $items[0]->expected_date->format('Y-m-d'));

        $calendar = $this->service->forecast(1, 30, 'baseline', 'day');
        $this->assertSame('15000.0000', $calendar['totals']['outflow']);
        $this->assertSame('185000.0000', $calendar['totals']['closing']);
    }

    public function test_manual_projection_persists_across_refresh_and_counts(): void
    {
        $this->service->createManualProjection(1, [
            'source_type' => 'manual', 'direction' => 'outflow', 'amount' => '25000',
            'expected_date' => '2026-11-10', 'description' => 'Rent estimate',
        ], 2);

        $this->service->refreshForecast(1);
        $this->service->refreshForecast(1);

        $this->assertSame(1, TreasuryForecastItem::where('created_by', 1)->where('source_type', 'manual')->count());

        $calendar = $this->service->forecast(1, 30, 'baseline', 'day');
        $this->assertSame('25000.0000', $calendar['totals']['outflow']);
        $this->assertSame('175000.0000', $calendar['totals']['closing']);
    }

    public function test_system_items_are_regenerated_but_manual_items_survive(): void
    {
        $this->makeInvoice(2, '2026-11-20', [['price' => '5000', 'quantity' => 1]]);
        $this->service->createManualProjection(1, [
            'source_type' => 'manual', 'direction' => 'inflow', 'amount' => '1000',
            'expected_date' => '2026-11-12',
        ], 2);

        $this->service->refreshForecast(1);
        $first = TreasuryForecastItem::where('created_by', 1)->where('source_type', 'invoice')->firstOrFail()->id;
        $manual = TreasuryForecastItem::where('created_by', 1)->where('source_type', 'manual')->firstOrFail()->id;

        $this->service->refreshForecast(1);

        $this->assertNotSame($first, TreasuryForecastItem::where('created_by', 1)->where('source_type', 'invoice')->firstOrFail()->id);
        $this->assertSame($manual, TreasuryForecastItem::where('created_by', 1)->where('source_type', 'manual')->firstOrFail()->id);
    }

    // ------------------------------------------------------------------
    // CA13 — invoice replaces its contract projection
    // ------------------------------------------------------------------

    public function test_ca13_invoice_replaces_its_contract_projection_without_double_counting(): void
    {
        $this->service->createManualProjection(1, [
            'source_type' => 'contract', 'direction' => 'inflow', 'amount' => '5000',
            'expected_date' => '2026-12-01', 'document' => 'CTR-2027-001',
            'description' => 'Contract estimate — monthly fee',
        ], 2);

        // Before the invoice is issued, the estimate counts.
        $this->service->refreshForecast(1);
        $calendar = $this->service->forecast(1, 30, 'baseline', 'day');
        $this->assertSame('205000.0000', $calendar['totals']['closing']);

        // The invoice is issued with the same reference.
        $this->makeInvoice(2, '2026-12-01', [['price' => '5000', 'quantity' => 1]], 'CTR-2027-001');

        $counts = $this->service->refreshForecast(1);
        $this->assertSame(1, $counts['replaced']);

        $projection = TreasuryForecastItem::where('created_by', 1)->where('source_type', 'contract')->firstOrFail();
        $this->assertSame('CTR-2027-001', $projection->replacement_reference);
        $this->assertTrue($projection->isReplaced());

        $calendar = $this->service->forecast(1, 30, 'baseline', 'day');
        // 200,000 opening + 5,000 invoice only — the estimate no
        // longer counts, so nothing is double-counted.
        $this->assertSame('205000.0000', $calendar['totals']['closing']);
        $this->assertSame(1, TreasuryForecastItem::where('created_by', 1)->where('source_type', 'invoice')->count());
    }

    public function test_ca13_partial_payment_of_replacing_invoice_leaves_only_outstanding(): void
    {
        $this->service->createManualProjection(1, [
            'source_type' => 'contract', 'direction' => 'inflow', 'amount' => '10000',
            'expected_date' => '2026-11-15', 'document' => 'CTR-2027-002',
        ], 2);

        $invoice = $this->makeInvoice(2, '2026-11-15', [['price' => '10000', 'quantity' => 1]], 'CTR-2027-002');
        DB::table('invoice_payments')->insert([
            'invoice_id' => $invoice->id, 'date' => '2026-11-07', 'amount' => '4000.00',
            'account_id' => 0, 'payment_method' => 0, 'payment_type' => 'Manually',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->service->refreshForecast(1);

        $projection = TreasuryForecastItem::where('created_by', 1)->where('source_type', 'contract')->firstOrFail();
        $this->assertSame('CTR-2027-002', $projection->replacement_reference);

        $calendar = $this->service->forecast(1, 30, 'baseline', 'day');
        $this->assertSame('206000.0000', $calendar['totals']['closing']);
    }

    // ------------------------------------------------------------------
    // Scenarios + threshold + grouping
    // ------------------------------------------------------------------

    public function test_scenarios_shift_and_scale_without_storing(): void
    {
        $this->makeInvoice(2, '2026-11-20', [['price' => '10000', 'quantity' => 1]]);
        DB::table('pay_slips')->insert([
            'employee_id' => 2, 'net_payble' => 15000, 'salary_month' => '2026-11', 'status' => 0, 'basic_salary' => 15000, 'allowance' => '{}', 'commission' => '{}', 'loan' => '{}', 'saturation_deduction' => '{}', 'other_payment' => '{}', 'overtime' => '{}', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->service->refreshForecast(1);

        $baseline = $this->service->forecast(1, 30, 'baseline', 'day');
        $late = $this->service->forecast(1, 30, 'late_receipts', 'day');
        $higher = $this->service->forecast(1, 30, 'higher_expenses', 'day');
        $earlier = $this->service->forecast(1, 30, 'earlier_payments', 'day');

        // Same totals in every scenario — only timing / size moves.
        $this->assertSame('195000.0000', $baseline['totals']['closing']);
        $this->assertSame($baseline['totals']['closing'], $late['totals']['closing']);
        $this->assertSame('16500.0000', $higher['totals']['outflow']);
        $this->assertSame('193500.0000', $higher['totals']['closing']);

        $lateDay20 = collect($late['periods'])->firstWhere('key', '2026-11-20');
        $lateDay27 = collect($late['periods'])->firstWhere('key', '2026-11-27');
        $this->assertSame('0.0000', $lateDay20['inflow']);
        $this->assertSame('10000.0000', $lateDay27['inflow']);

        $earlierDay23 = collect($earlier['periods'])->firstWhere('key', '2026-11-23');
        $earlierDay30 = collect($earlier['periods'])->firstWhere('key', '2026-11-30');
        $this->assertSame('15000.0000', $earlierDay23['outflow']);
        $this->assertSame('0.0000', $earlierDay30['outflow']);

        // Nothing was stored: the items still carry the baseline dates.
        $invoiceItem = TreasuryForecastItem::where('created_by', 1)->where('source_type', 'invoice')->firstOrFail();
        $this->assertSame('2026-11-20', $invoiceItem->expected_date->format('Y-m-d'));
    }

    public function test_first_day_below_threshold_flags_the_day_the_forecast_drops(): void
    {
        DB::table('pay_slips')->insert([
            'employee_id' => 2, 'net_payble' => 15000, 'salary_month' => '2026-11', 'status' => 0, 'basic_salary' => 15000, 'allowance' => '{}', 'commission' => '{}', 'loan' => '{}', 'saturation_deduction' => '{}', 'other_payment' => '{}', 'overtime' => '{}', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('settings')->insert([
            'name' => 'treasury_cashflow_threshold_base', 'value' => '190000', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->service->refreshForecast(1);

        $firstBelow = $this->service->firstDayBelowThreshold(1, 30, 'baseline');

        $this->assertNotNull($firstBelow);
        $this->assertSame('2026-11-30', $firstBelow['date']);
        $this->assertSame('185000.0000', $firstBelow['closing']);

        // Default threshold 0: a forecast that never goes negative
        // has no first day below.
        DB::table('settings')->where('created_by', 1)->where('name', 'treasury_cashflow_threshold_base')->delete();
        $this->assertNull($this->service->firstDayBelowThreshold(1, 30, 'baseline'));
    }

    public function test_week_and_month_grouping(): void
    {
        $this->makeInvoice(2, '2026-11-20', [['price' => '10000', 'quantity' => 1]]);
        $this->service->refreshForecast(1);

        $week = $this->service->forecast(1, 30, 'baseline', 'week');
        $this->assertSame('210000.0000', $week['totals']['closing']);
        $weekOf16 = collect($week['periods'])->firstWhere('key', '2026-11-16');
        $this->assertNotNull($weekOf16);
        $this->assertSame('10000.0000', $weekOf16['inflow']);

        $month = $this->service->forecast(1, 30, 'baseline', 'month');
        $this->assertCount(2, $month['periods']);
        $this->assertSame('November 2026', $month['periods'][0]['label']);
        $this->assertSame('10000.0000', $month['periods'][0]['inflow']);
    }

    public function test_actuals_are_shown_separately_and_never_double_counted(): void
    {
        $operationId = DB::table('treasury_operations')->insertGetId([
            'reference' => 'COMP-RCT-2026-0001', 'type' => 'receipt', 'company_id' => 1,
            'financial_account_id' => 1, 'currency' => 'MZN', 'amount_original' => '3000.0000',
            'amount_base' => '3000.0000', 'exchange_rate' => '1.0000000000',
            'posting_date' => '2026-11-08', 'effective_date' => '2026-11-08',
            'value_date' => '2026-11-08', 'state' => 'confirmed', 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('treasury_entries')->insert([
            'operation_id' => $operationId, 'financial_account_id' => 1, 'direction' => 'inflow',
            'amount_original' => '3000.0000', 'amount_base' => '3000.0000', 'currency' => 'MZN',
            'exchange_rate' => '1.0000000000', 'created_by' => 1,
            'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->service->refreshForecast(1);
        $calendar = $this->service->forecast(1, 30, 'baseline', 'day');

        // The confirmed receipt is already in the opening balance
        // (postedBalance 203,000) and appears in actuals — it is
        // NOT also a forecast item.
        $this->assertSame('203000.0000', $calendar['opening_balance']);
        $this->assertSame('3000.0000', $calendar['actuals']['inflow']);
        $this->assertCount(1, $calendar['actuals']['items']);
        $this->assertSame('COMP-RCT-2026-0001', $calendar['actuals']['items'][0]['reference']);
        $this->assertSame('203000.0000', $calendar['totals']['closing']);
    }

    // ------------------------------------------------------------------
    // Validation + company scope
    // ------------------------------------------------------------------

    public function test_manual_projection_validation(): void
    {
        $this->expectException(TreasuryException::class);
        $this->service->createManualProjection(1, [
            'source_type' => 'manual', 'direction' => 'outflow', 'amount' => '0',
            'expected_date' => '2026-11-10',
        ], 2);
    }

    public function test_system_items_cannot_be_edited_or_deleted(): void
    {
        $this->service->refreshForecast(1);
        $balance = TreasuryForecastItem::where('created_by', 1)->where('source_type', 'balance')->firstOrFail();

        $this->expectException(TreasuryException::class);
        $this->service->updateManualProjection(1, $balance->id, ['amount' => '1']);
    }

    public function test_other_company_data_is_never_in_this_forecast(): void
    {
        $this->makeInvoice(2, '2026-11-20', [['price' => '10000', 'quantity' => 1]], null, 4);
        $this->service->refreshForecast(1);

        $this->assertSame(0, TreasuryForecastItem::where('created_by', 1)->where('source_type', 'invoice')->count());
        $this->assertSame('200000.0000', $this->service->forecast(1, 30, 'baseline', 'day')['totals']['closing']);

        $this->expectException(TreasuryException::class);
        $item = TreasuryForecastItem::where('created_by', 1)->where('source_type', 'balance')->firstOrFail();
        $this->service->projectionForCompany(4, $item->id);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function makeInvoice(int $status, string $dueDate, array $lines, ?string $refNumber = null, int $creatorId = 1): Invoice
    {
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'Cliente', 'created_by' => $creatorId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        static $seq = 1000;
        $invoice = Invoice::create([
            'customer_id' => $customerId, 'invoice_id' => ++$seq,
            'issue_date' => '2026-11-01', 'due_date' => $dueDate, 'status' => $status,
            'ref_number' => $refNumber, 'created_by' => $creatorId,
        ]);

        foreach ($lines as $line) {
            DB::table('invoice_products')->insert([
                'invoice_id' => $invoice->id, 'product_id' => 0, 'price' => $line['price'],
                'quantity' => $line['quantity'], 'tax' => '', 'discount' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $invoice->fresh();
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
            $table->string('code')->nullable();
            $table->string('name')->nullable();
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
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->string('rate_source', 20)->nullable();
            $table->date('rate_date')->nullable();
            $table->date('posting_date')->nullable();
            $table->date('effective_date')->nullable();
            $table->date('value_date')->nullable();
            $table->string('state', 20)->default('draft');
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
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->integer('created_by');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customers', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
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

        Schema::create('invoices', function ($table) {
            $table->bigIncrements('id');
            $table->integer('customer_id')->nullable();
            $table->bigInteger('invoice_id')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('ref_number')->nullable();
            $table->integer('status')->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('invoice_products', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('invoice_id');
            $table->integer('product_id')->default(0);
            $table->decimal('price', 16, 2)->default(0);
            $table->decimal('quantity', 16, 2)->default(0);
            $table->string('tax')->nullable();
            $table->decimal('discount', 16, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('invoice_payments', function ($table) {
            $table->bigIncrements('id');
            $table->integer('invoice_id');
            $table->date('date')->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->integer('account_id')->default(0);
            $table->integer('payment_method')->default(0);
            $table->string('payment_type')->default('Manually');
            $table->timestamps();
        });

        Schema::create('credit_notes', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('invoice')->nullable();
            $table->decimal('amount', 16, 2)->default(0);
            $table->integer('is_debit')->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('pay_slips', function ($table) {
            $table->bigIncrements('id');
            $table->integer('employee_id');
            $table->integer('net_payble');
            $table->string('salary_month');
            $table->integer('status');
            $table->integer('basic_salary');
            $table->text('allowance');
            $table->text('commission');
            $table->text('loan');
            $table->text('saturation_deduction');
            $table->text('other_payment');
            $table->text('overtime');
            $table->integer('created_by');
            $table->timestamps();
        });

        Schema::create('treasury_forecast_items', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('source_type', 20);
            $table->bigInteger('source_id')->nullable();
            $table->string('direction', 10);
            $table->decimal('amount', 20, 4);
            $table->decimal('amount_base', 20, 4);
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->date('expected_date');
            $table->string('scenario', 20)->default('baseline');
            $table->decimal('probability', 5, 2)->nullable();
            $table->string('document', 150)->nullable();
            $table->string('replacement_reference', 60)->nullable();
            $table->string('description', 200)->nullable();
            $table->string('state', 20)->default('forecast');
            $table->timestamps();
        });
    }
}
