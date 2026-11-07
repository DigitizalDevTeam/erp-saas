<?php

namespace Tests\Unit;

use App\Exceptions\TreasuryException;
use App\Models\BillingContract;
use App\Models\BillingContractOccurrence;
use App\Models\Invoice;
use App\Models\TreasuryFinancialAccount;
use App\Models\TreasuryForecastItem;
use App\Models\Utility;
use App\Services\BillingContractService;
use App\Services\BillingInvoiceService;
use App\Services\BillingOccurrenceService;
use App\Services\InvoiceCreationService;
use App\Services\TreasuryForecastService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Contract Management Phase 3 — CA13 with real contracts
 * (Treasury plan CA13; Contract plan §9).
 *
 * Real contract projections now come from ACTIVE billing
 * contracts: each pending occurrence becomes one forecast item
 * (source_type contract, source_id = occurrence id, inflow,
 * amount = occurrence amount, expected on the billing date).
 * When the occurrence's invoice is issued, the projection is
 * replaced by the invoice item — the closing never
 * double-counts. Manual contract estimates (source_id null)
 * stay as estimates and are never touched by this sync.
 *
 * The account is funded with 200,000 MZN. "Today" is frozen at
 * 2026-11-07, so the 90-day forecast window covers the
 * 2027-01-01 and 2027-02-01 billing dates. The contract bills
 * 5,000 per month, 2027-01-01 → 2027-03-31.
 *
 * NOTE on tax: the occurrence amount is ex-tax. A no-tax
 * contract's projection (5,000) equals its invoice total
 * (5,000), so the closing is unchanged on replacement. A
 * taxed contract's projection understates the invoice total
 * by the tax until the invoice is issued — that is the stored
 * contract value, not an error.
 */
class TreasuryContractForecastTest extends TestCase
{
    protected TreasuryForecastService $forecast;
    protected BillingInvoiceService $billing;
    protected BillingContractService $contracts;

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
        DB::table('customers')->insert([
            ['id' => 1, 'customer_id' => 1, 'name' => 'Hoya Hoya Lda', 'email' => 'hoya@test.local', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'customer_id' => 2, 'name' => 'Other Customer', 'email' => 'other@test.local', 'created_by' => 4, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('taxes')->insert([
            ['id' => 1, 'name' => 'IVA 16%', 'rate' => 16, 'created_by' => 1],
        ]);
        DB::table('product_service_categories')->insert([
            ['id' => 1, 'name' => 'Services', 'type' => 'income', 'chart_account_id' => 2, 'color' => '#000000', 'created_by' => 1],
        ]);
        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'name' => 'Clientes', 'code' => 411, 'type' => 1, 'sub_type' => 1, 'is_enabled' => 1, 'created_by' => 1],
            ['id' => 2, 'name' => 'Prestacao de servicos', 'code' => 721, 'type' => 4, 'sub_type' => 1, 'is_enabled' => 1, 'created_by' => 1],
        ]);
        DB::table('product_services')->insert([
            ['id' => 1, 'name' => 'Monthly support', 'sku' => 'SVC-001', 'sale_price' => 5000, 'purchase_price' => 0, 'quantity' => 0, 'tax_id' => '1', 'category_id' => 1, 'unit_id' => 0, 'type' => 'service', 'sale_chartaccount_id' => 2, 'expense_chartaccount_id' => 0, 'affect_stock' => 0, 'created_by' => 1],
            ['id' => 2, 'name' => 'Setup', 'sku' => 'SVC-002', 'sale_price' => 1000, 'purchase_price' => 0, 'quantity' => 0, 'tax_id' => '1', 'category_id' => 1, 'unit_id' => 0, 'type' => 'service', 'sale_chartaccount_id' => 2, 'expense_chartaccount_id' => 0, 'affect_stock' => 0, 'created_by' => 1],
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

        $this->forecast = new TreasuryForecastService();
        $this->contracts = new BillingContractService(new BillingOccurrenceService());
        $this->billing = new BillingInvoiceService(
            new InvoiceCreationService(),
            new BillingOccurrenceService()
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Utility::$rates = null;
        Utility::$data = null;
        $this->resetUtilitySettingsCache();

        foreach (['billing_renewals', 'billing_reminders', 'billing_delivery_attempts', 'email_logs', 'credit_notes', 'invoice_payments', 'transaction_lines', 'invoice_products', 'invoices', 'billing_contract_audit_events', 'billing_contract_revisions', 'billing_contract_occurrences', 'billing_contract_lines', 'billing_contracts', 'stock_reports', 'product_expiry_lots', 'product_services', 'product_service_categories', 'chart_of_accounts', 'settings', 'taxes', 'customers', 'treasury_forecast_items', 'pay_slips', 'treasury_entries', 'treasury_operations', 'treasury_opening_balances', 'treasury_financial_accounts', 'dimension_audit_events', 'bank_accounts', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        Storage::disk('local')->deleteDirectory('billing-invoice-pdfs');

        parent::tearDown();
    }

    protected function resetUtilitySettingsCache(): void
    {
        $ref = new \ReflectionClass(Utility::class);
        $prop = $ref->getProperty('getsettingsid');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    // ------------------------------------------------------------------
    // CA13 — real contract projections
    // ------------------------------------------------------------------

    public function test_ca13_real_contract_projection_counts_before_issue(): void
    {
        $contract = $this->activeContract(['tax_id' => null]);
        $occurrences = BillingContractOccurrence::where('contract_id', $contract->id)
            ->orderBy('period_start')->get();
        $this->assertCount(3, $occurrences);

        $counts = $this->forecast->refreshForecast(1);

        // One projection per pending occurrence (Jan, Feb, Mar).
        $this->assertSame(3, $counts['contracts']);

        $items = TreasuryForecastItem::where('created_by', 1)
            ->where('source_type', 'contract')
            ->whereNotNull('source_id')
            ->orderBy('expected_date')
            ->get();
        $this->assertCount(3, $items);
        $this->assertSame($occurrences[0]->id, (int) $items[0]->source_id);
        $this->assertSame(0, bccomp((string) $items[0]->amount, '5000.0000', 4));
        $this->assertSame('2027-01-01', $items[0]->expected_date->format('Y-m-d'));
        $this->assertSame('inflow', $items[0]->direction);
        $this->assertSame($contract->reference, $items[0]->document);
        $this->assertNull($items[0]->replacement_reference);

        // The 90-day window covers Jan + Feb only: 200,000 + 10,000.
        $calendar = $this->forecast->forecast(1, 90, 'baseline', 'day');
        $this->assertSame('210000.0000', $calendar['totals']['closing']);
    }

    public function test_ca13_projection_replaced_by_invoice_closing_unchanged(): void
    {
        $contract = $this->activeContract(['tax_id' => null]);
        $occurrence = BillingContractOccurrence::where('contract_id', $contract->id)
            ->orderBy('period_start')->firstOrFail();

        $this->forecast->refreshForecast(1);
        $before = $this->forecast->forecast(1, 90, 'baseline', 'day');
        $this->assertSame('210000.0000', $before['totals']['closing']);

        // Issue the January invoice (no tax: total = occurrence amount).
        $result = $this->billing->generateForOccurrence($occurrence, 2, Carbon::parse('2027-01-10'), false);
        $invoice = $result['invoice'];
        $this->assertEqualsWithDelta(5000, (float) $invoice->getTotal(), 0.0001);

        $counts = $this->forecast->refreshForecast(1);
        $this->assertSame(0, $counts['contracts']);

        // The January projection is replaced; February and March stay.
        $replaced = TreasuryForecastItem::where('created_by', 1)
            ->where('source_type', 'contract')
            ->where('source_id', $occurrence->id)
            ->firstOrFail();
        $this->assertSame($contract->reference, $replaced->replacement_reference);
        $this->assertTrue($replaced->isReplaced());

        $live = TreasuryForecastItem::where('created_by', 1)
            ->where('source_type', 'contract')
            ->whereNotNull('source_id')
            ->whereNull('replacement_reference')
            ->count();
        $this->assertSame(2, $live);

        // Exactly one invoice item carries the January amount now.
        $invoiceItems = TreasuryForecastItem::where('created_by', 1)
            ->where('source_type', 'invoice')
            ->get();
        $this->assertCount(1, $invoiceItems);
        $this->assertSame($invoice->id, (int) $invoiceItems->first()->source_id);
        $this->assertSame(0, bccomp((string) $invoiceItems->first()->amount, '5000.0000', 4));

        // Closing unchanged: the invoice replaced the projection 1:1.
        $after = $this->forecast->forecast(1, 90, 'baseline', 'day');
        $this->assertSame('210000.0000', $after['totals']['closing']);
        $this->assertSame($before['totals']['closing'], $after['totals']['closing']);
    }

    public function test_ca13_taxed_contract_projection_is_ex_tax(): void
    {
        $contract = $this->activeContract(['tax_id' => 1]);
        $occurrence = BillingContractOccurrence::where('contract_id', $contract->id)
            ->orderBy('period_start')->firstOrFail();

        $this->forecast->refreshForecast(1);
        $before = $this->forecast->forecast(1, 90, 'baseline', 'day');
        $this->assertSame('210000.0000', $before['totals']['closing']);

        // The invoice totals 5,800 (5,000 + 16% IVA): the projection
        // counted 5,000, so the closing rises by the 800 tax.
        $this->billing->generateForOccurrence($occurrence, 2, Carbon::parse('2027-01-10'), false);
        $this->forecast->refreshForecast(1);

        $after = $this->forecast->forecast(1, 90, 'baseline', 'day');
        $this->assertSame('210800.0000', $after['totals']['closing']);
    }

    public function test_ca13_refresh_is_idempotent_for_projections(): void
    {
        $this->activeContract(['tax_id' => null]);

        $this->forecast->refreshForecast(1);
        $this->forecast->refreshForecast(1);
        $this->forecast->refreshForecast(1);

        $this->assertSame(3, TreasuryForecastItem::where('created_by', 1)
            ->where('source_type', 'contract')
            ->whereNotNull('source_id')
            ->count());
    }

    public function test_ca13_suspended_contract_projections_drop(): void
    {
        $contract = $this->activeContract(['tax_id' => null]);
        $this->forecast->refreshForecast(1);
        $this->assertSame(3, TreasuryForecastItem::where('created_by', 1)
            ->where('source_type', 'contract')->whereNotNull('source_id')->count());

        $this->contracts->suspend($contract->fresh(), 2, 'Paused');
        $this->forecast->refreshForecast(1);

        $this->assertSame(0, TreasuryForecastItem::where('created_by', 1)
            ->where('source_type', 'contract')->whereNotNull('source_id')->count());

        // Resume brings them back.
        $this->contracts->resume($contract->fresh(), 2, 'bill');
        $this->forecast->refreshForecast(1);
        $this->assertSame(3, TreasuryForecastItem::where('created_by', 1)
            ->where('source_type', 'contract')->whereNotNull('source_id')->count());
    }

    public function test_ca13_manual_estimate_untouched_by_real_sync(): void
    {
        $this->activeContract(['tax_id' => null]);

        $manual = $this->forecast->createManualProjection(1, [
            'source_type' => 'contract', 'direction' => 'inflow', 'amount' => '7000',
            'expected_date' => '2027-01-15', 'document' => 'CTR-MANUAL-001',
            'description' => 'Contract estimate — handshake deal',
        ], 2);

        $this->forecast->refreshForecast(1);

        // The manual estimate (source_id null) survives the sync
        // untouched, and it is NOT a real projection.
        $kept = TreasuryForecastItem::find($manual->id);
        $this->assertNotNull($kept);
        $this->assertNull($kept->source_id);
        $this->assertNull($kept->replacement_reference);
        $this->assertSame(3, TreasuryForecastItem::where('created_by', 1)
            ->where('source_type', 'contract')->whereNotNull('source_id')->count());

        // Manual estimates are still editable and deletable.
        $this->forecast->updateManualProjection(1, $manual->id, ['amount' => '8000']);
        $this->assertSame(0, bccomp((string) TreasuryForecastItem::find($manual->id)->amount, '8000.0000', 4));
    }

    public function test_ca13_real_projection_is_system_managed(): void
    {
        $this->activeContract(['tax_id' => null]);
        $this->forecast->refreshForecast(1);

        $item = TreasuryForecastItem::where('created_by', 1)
            ->where('source_type', 'contract')
            ->whereNotNull('source_id')
            ->firstOrFail();

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessageMatches('/managed by the billing contract/');
        $this->forecast->updateManualProjection(1, $item->id, ['amount' => '6000']);
    }

    public function test_ca13_real_projection_cannot_be_deleted_by_hand(): void
    {
        $this->activeContract(['tax_id' => null]);
        $this->forecast->refreshForecast(1);

        $item = TreasuryForecastItem::where('created_by', 1)
            ->where('source_type', 'contract')
            ->whereNotNull('source_id')
            ->firstOrFail();

        $this->expectException(TreasuryException::class);
        $this->expectExceptionMessageMatches('/managed by the billing contract/');
        $this->forecast->deleteManualProjection(1, $item->id);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function activeContract(array $overrides = []): BillingContract
    {
        $contract = $this->contracts->createContract(1, $this->contractData($overrides), 1);
        $this->contracts->submitForApproval($contract, 2);

        return $contract->fresh();
    }

    protected function contractData(array $overrides = []): array
    {
        // array_key_exists (not ??): an explicit null tax_id means
        // "no tax on the line", not "fall back to IVA 16%".
        $taxId = array_key_exists('tax_id', $overrides) ? $overrides['tax_id'] : 1;
        unset($overrides['tax_id']);

        return array_merge([
            'customer_id' => 1,
            'title' => 'Monthly support',
            'description' => null,
            'start_date' => '2027-01-01',
            'end_date' => '2027-03-31',
            'currency' => 'MZN',
            'exchange_rate' => '1',
            'billing_frequency' => 'monthly',
            'custom_interval' => null,
            'custom_unit' => null,
            'anchor_day' => 1,
            'billing_mode' => 'advance',
            'payment_terms_days' => 15,
            'approval_mode' => 'automatic',
            'proration_enabled' => false,
            'partial_period_rule' => 'full',
            'renewal_notice_days' => null,
            'auto_renew' => false,
            'delivery_auto_email' => false,
            'delivery_to' => null,
            'delivery_cc' => null,
            'notes' => null,
            'branch_id' => null,
            'lines' => [
                ['description' => 'Monthly support', 'product_id' => 1, 'quantity' => '1', 'unit_price' => '5000', 'discount_amount' => '0', 'tax_id' => $taxId, 'chart_account_id' => 2],
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

        Schema::create('settings', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by');
            $table->timestamps();
            $table->unique(['name', 'created_by']);
        });

        Schema::create('product_service_categories', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('type')->default(0);
            $table->integer('chart_account_id')->default(0);
            $table->string('color')->default('#fc544b');
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('chart_of_accounts', function ($table) {
            $table->id();
            $table->string('name');
            $table->integer('code')->default(0);
            $table->integer('type')->default(0);
            $table->integer('sub_type')->default(0);
            $table->integer('is_enabled')->default(1);
            $table->text('description')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('product_services', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('sku');
            $table->decimal('sale_price', 16, 2)->default('0.0');
            $table->decimal('purchase_price', 16, 2)->default('0.0');
            $table->float('quantity')->default('0.0');
            $table->string('tax_id', '50')->nullable();
            $table->integer('category_id')->default('0');
            $table->integer('unit_id')->default('0');
            $table->string('type');
            $table->integer('sale_chartaccount_id')->default('0');
            $table->integer('expense_chartaccount_id')->default('0');
            $table->integer('affect_stock')->default(0);
            $table->text('description')->nullable();
            $table->string('pro_image')->nullable();
            $table->integer('created_by')->default('0');
            $table->softDeletes();
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

        Schema::create('invoices', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('customer_id');
            $table->date('issue_date');
            $table->date('due_date');
            $table->date('send_date')->nullable();
            $table->integer('category_id');
            $table->text('ref_number')->nullable();
            $table->string('client_po_number')->nullable();
            $table->date('client_po_date')->nullable();
            $table->text('client_po_details')->nullable();
            $table->string('quotation_reference')->nullable();
            $table->integer('billing_address_id')->nullable();
            $table->integer('shipping_address_id')->nullable();
            $table->integer('quotation_id')->nullable();
            $table->integer('proforma_id')->nullable();
            $table->integer('purchase_id')->nullable();
            $table->timestamp('stock_deducted_at')->nullable();
            $table->string('stock_deducted_via')->nullable();
            $table->integer('status')->default('0');
            $table->integer('shipping_display')->default('1');
            $table->integer('discount_apply')->default('0');
            $table->integer('created_by')->default('0');
            $table->timestamps();
        });

        Schema::create('invoice_products', function ($table) {
            $table->bigIncrements('id');
            $table->integer('invoice_id');
            $table->integer('product_id');
            $table->integer('product_variant_id')->nullable();
            $table->integer('quantity');
            $table->string('tax', '50')->nullable();
            $table->float('discount')->default('0.00');
            $table->decimal('price', 16, 2)->default('0.0');
            $table->text('description')->nullable();
            $table->text('identifiers')->nullable();
            $table->text('measurement_json')->nullable();
            $table->text('selected_options_json')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_payments', function ($table) {
            $table->bigIncrements('id');
            $table->integer('invoice_id');
            $table->date('date');
            $table->decimal('amount', 16, 2)->default('0.0');
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

        Schema::create('stock_reports', function ($table) {
            $table->id();
            $table->integer('product_id')->default('0');
            $table->integer('quantity')->default('0');
            $table->string('type');
            $table->integer('type_id')->default('0');
            $table->text('description')->nullable();
            $table->integer('warehouse_loss_id')->nullable();
            $table->integer('created_by')->default('0');
            $table->timestamps();
        });

        Schema::create('product_expiry_lots', function ($table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->unsignedBigInteger('purchase_product_id')->nullable();
            $table->string('source', 20)->default('purchase');
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity_in', 16, 4)->default(0);
            $table->decimal('quantity_remaining', 16, 4)->default(0);
            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('credit_notes', function ($table) {
            $table->bigIncrements('id');
            $table->integer('invoice')->default('0');
            $table->integer('customer')->default('0');
            $table->decimal('amount', 15, 2)->default('0.00');
            $table->date('date');
            $table->text('description')->nullable();
            $table->integer('is_debit')->default(0);
            $table->timestamps();
        });

        Schema::create('email_logs', function ($table) {
            $table->id();
            $table->unsignedBigInteger('created_by')->default(0);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('recipient');
            $table->string('subject')->nullable();
            $table->string('source')->nullable();
            $table->string('mailable_class')->nullable();
            $table->enum('status', ['success', 'failed']);
            $table->text('error_message')->nullable();
            $table->json('meta')->nullable();
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
            $table->bigInteger('renewed_from_id')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'reference'], 'bc_company_ref_uq');
        });

        Schema::create('billing_contract_lines', function ($table) {
            $table->bigIncrements('id');
            $table->bigInteger('contract_id');
            $table->bigInteger('product_id')->nullable();
            $table->string('description', 250);
            $table->decimal('quantity', 20, 4)->default('1.0000');
            $table->decimal('unit_price', 20, 4)->default('1.0000');
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

        Schema::create('billing_delivery_attempts', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('contract_id');
            $table->bigInteger('occurrence_id');
            $table->bigInteger('invoice_id');
            $table->string('channel', 20)->default('email');
            $table->text('recipients_to')->nullable();
            $table->text('recipients_cc')->nullable();
            $table->string('status', 20)->default('queued');
            $table->integer('attempt_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->text('last_error')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique('invoice_id', 'bda_invoice_uq');
            $table->index(['created_by', 'contract_id'], 'bda_contract_idx');
            $table->index(['created_by', 'status', 'next_retry_at'], 'bda_retry_idx');
        });

        Schema::create('billing_reminders', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('contract_id');
            $table->bigInteger('occurrence_id');
            $table->bigInteger('invoice_id');
            $table->string('status', 20)->default('scheduled');
            $table->integer('sent_count')->default(0);
            $table->integer('attempt_count')->default(0);
            $table->date('next_reminder_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->text('recipients_to')->nullable();
            $table->text('recipients_cc')->nullable();
            $table->timestamps();
            $table->unique(['invoice_id'], 'br_invoice_uq');
            $table->index(['contract_id'], 'br_contract_idx');
            $table->index(['created_by', 'status', 'next_reminder_at'], 'br_due_idx');
        });

        Schema::create('billing_renewals', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->bigInteger('contract_id');
            $table->bigInteger('new_contract_id');
            $table->date('effective_date');
            $table->string('type', 20)->default('manual');
            $table->string('status', 20)->default('pending');
            $table->timestamp('notice_sent_at')->nullable();
            $table->timestamps();
            $table->unique(['new_contract_id'], 'bren_new_contract_uq');
            $table->index(['contract_id'], 'bren_contract_idx');
        });
    }
}
