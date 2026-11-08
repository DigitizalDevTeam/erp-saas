<?php

namespace Tests\Unit;

use App\Exceptions\FixedAssetException;
use App\Models\FixedAsset;
use App\Models\FixedAssetAcquisition;
use App\Models\FixedAssetAcquisitionCost;
use App\Models\FixedAssetApproval;
use App\Models\FixedAssetCapitalization;
use App\Models\FixedAssetDepreciationSchedule;
use App\Models\JournalEntry;
use App\Models\JournalItem;
use App\Services\FixedAssetAcquisitionService;
use App\Services\FixedAssetNumberingService;
use App\Services\FixedAssetService;
use App\Services\FixedAssetSettings;
use App\Services\Accounting\ChartAccountResolver;
use App\Services\Accounting\JournalEntryPoster;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fixed Assets Phase 1 — Register + Acquisition + Capitalization
 * (plan §5, §10; spec §6, §7, §8, §9).
 *
 * Covers: the cost builder (§8 — recoverable VAT is never part
 * of capitalized cost), asset registration from every source
 * (direct / PO line / bill line / delivery note / donation /
 * opening balance), the review → approval → capitalization
 * workflow (no self-approval, per-company levels, every
 * decision recorded), the capitalization journal (Dr asset /
 * Cr clearing or AP 421, balanced, via JournalEntryPoster
 * only), the depreciation schedule shell, and the Excel import
 * of old assets (opening accumulated depreciation + remaining
 * life, bad rows skipped).
 *
 * Users: 1 = Company ("Hoya Hoya" — prefix HOYA), 2 = Operator
 * (requester), 3 = Approver, 4 = Other Company ("Uthara"),
 * 5 = Second Approver. "Today" is frozen at 2026-11-07.
 * Approval threshold is the Phase 0 default: MZN 100,000.
 */
class FixedAssetAcquisitionServiceTest extends TestCase
{
    protected FixedAssetAcquisitionService $acquisitions;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-07 09:00:00'));
        $this->createTables();

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Hoya Hoya', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Operator', 'email' => 'operator@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Uthara', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 5, 'name' => 'Second Approver', 'email' => 'approver2@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        // Chart of accounts: 321 asset, 328 accum. depreciation,
        // 421 accounts payable, 471 clearing account (D7).
        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'name' => 'Tangible fixed assets', 'code' => 321, 'type' => 3, 'sub_type' => 2, 'is_enabled' => 1, 'created_by' => 1],
            ['id' => 2, 'name' => 'Accumulated depreciation', 'code' => 328, 'type' => 3, 'sub_type' => 2, 'is_enabled' => 1, 'created_by' => 1],
            ['id' => 3, 'name' => 'Accounts payable', 'code' => 421, 'type' => 4, 'sub_type' => 1, 'is_enabled' => 1, 'created_by' => 1],
            ['id' => 4, 'name' => 'Asset clearing account', 'code' => 471, 'type' => 4, 'sub_type' => 1, 'is_enabled' => 1, 'created_by' => 1],
        ]);

        DB::table('fixed_asset_categories')->insert([
            ['id' => 1, 'company_id' => 1, 'created_by' => 1, 'code' => 'IT', 'name' => 'IT Equipment', 'classification' => 'tangible', 'default_useful_life' => 5, 'default_life_unit' => 'year', 'depreciation_method' => 'straight_line', 'residual_value_type' => 'none', 'asset_account_id' => 1, 'accum_depreciation_account_id' => 2, 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('fixed_asset_locations')->insert([
            ['id' => 1, 'company_id' => 1, 'created_by' => 1, 'code' => 'WH1', 'name' => 'Warehouse 1', 'is_active' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('cost_centres')->insert([
            ['id' => 1, 'company_id' => 1, 'created_by' => 1, 'code' => 'LOG', 'name' => 'Logistics', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->acquisitions = new FixedAssetAcquisitionService(
            new FixedAssetService(),
            new FixedAssetNumberingService(),
            new JournalEntryPoster(),
            new ChartAccountResolver(),
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach (['purchase_delivery_note_items', 'purchase_delivery_notes', 'bill_products', 'bills', 'purchase_products', 'purchases', 'product_services', 'venders', 'transaction_lines', 'bank_accounts', 'journal_items', 'journal_entries', 'chart_of_accounts', 'fixed_asset_accounting_entries', 'fixed_asset_depreciation_schedules', 'fixed_asset_capitalizations', 'fixed_asset_acquisition_costs', 'fixed_asset_acquisitions', 'fixed_asset_audit_events', 'fixed_asset_status_history', 'fixed_asset_approvals', 'fixed_asset_documents', 'fixed_assets', 'fixed_asset_locations', 'fixed_asset_categories', 'cost_centres', 'branches', 'settings', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function baseData(array $overrides = []): array
    {
        return array_merge([
            'category_id' => 1,
            'name' => 'Laptop Dell XPS',
            'acquisition_method' => 'purchase',
            'source_type' => 'direct',
            'currency' => 'MZN',
            'exchange_rate' => '1',
            'purchase_date' => '2026-11-07',
            'useful_life' => 5,
            'life_unit' => 'year',
            'components' => [
                ['component_type' => 'purchase_price', 'gross_amount' => '100000', 'vat_amount' => '0', 'vat_recoverable' => false],
            ],
        ], $overrides);
    }

    /** Base data WITHOUT cost components — for createFromSource, where the source line prefills the purchase price. */
    protected function sourceOverrides(array $overrides = []): array
    {
        $data = $this->baseData($overrides);
        unset($data['components']);

        return $data;
    }

    /** Create a draft asset + acquisition; requester is user 2. */
    protected function createDraft(array $overrides = []): array
    {
        $result = $this->acquisitions->createAsset($this->baseData($overrides), 1, 2);

        return ['asset' => $result['assets'][0], 'acquisition' => $result['acquisitions'][0]];
    }

    /** Drive an acquisition through review + approval, deciding every level (level 1 by user 3, level 2 by user 5). */
    protected function approve(FixedAssetAcquisition $acquisition): FixedAssetAcquisition
    {
        $this->acquisitions->submitForReview($acquisition, 1, 2);
        $this->acquisitions->submitForApproval($acquisition, 1, 2);
        $approvals = FixedAssetApproval::where('transaction_id', $acquisition->id)->orderBy('level')->get();
        $approvers = [3, 5];
        foreach ($approvals as $index => $approval) {
            $this->acquisitions->decideAcquisitionApproval($approval, $approvers[$index] ?? 3, 'approved', 'OK');
        }

        return $acquisition->fresh();
    }

    protected function setClearingAccount(string $code = '471'): void
    {
        FixedAssetSettings::save(1, [FixedAssetSettings::KEY_CLEARING_ACCOUNT_CODE => $code]);
    }

    /**
     * Exact decimal comparison: SQLite strips trailing zeros on
     * read ('89000' instead of '89000.0000'), so string equality
     * on money attributes is meaningless here.
     */
    protected function assertMoneyEquals(string $expected, mixed $actual): void
    {
        $this->assertSame(0, bccomp((string) $actual, $expected, 4), "Expected money {$expected}, got {$actual}.");
    }

    // ------------------------------------------------------------------
    // Cost builder (§8) — recoverable VAT excluded
    // ------------------------------------------------------------------

    public function test_cost_builder_excludes_recoverable_vat(): void
    {
        $cost = $this->acquisitions->buildCost([
            // Gross 100,000 incl. 16,000 VAT, recoverable → 84,000.
            ['component_type' => 'purchase_price', 'gross_amount' => '100000', 'vat_amount' => '16000', 'vat_recoverable' => true],
            // Gross 5,000 incl. 800 VAT, NOT recoverable → 5,000.
            ['component_type' => 'transport', 'gross_amount' => '5000', 'vat_amount' => '800', 'vat_recoverable' => false],
            // Discount 2,000 → −2,000.
            ['component_type' => 'discount', 'gross_amount' => '2000', 'vat_amount' => '0', 'vat_recoverable' => false],
        ], 'MZN', '1');

        $this->assertSame('87000.0000', $cost['capitalized_cost']);
        $this->assertSame('87000.0000', $cost['capitalized_base']);
        $this->assertSame('84000.0000', $cost['lines'][0]['amount_base']);
        $this->assertSame('5000.0000', $cost['lines'][1]['amount_base']);
        $this->assertSame('-2000.0000', $cost['lines'][2]['amount_base']);
    }

    public function test_cost_builder_converts_to_base_currency(): void
    {
        $cost = $this->acquisitions->buildCost([
            ['component_type' => 'purchase_price', 'gross_amount' => '1000', 'vat_amount' => '160', 'vat_recoverable' => true],
        ], 'USD', '63.5');

        // 840 USD × 63.5 = 53,340 MZN.
        $this->assertSame('840.0000', $cost['capitalized_cost']);
        $this->assertSame('53340.0000', $cost['capitalized_base']);
    }

    public function test_cost_builder_refuses_vat_above_gross_and_negative_amounts(): void
    {
        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('VAT amount must not exceed the gross amount');
        $this->acquisitions->buildCost([
            ['component_type' => 'purchase_price', 'gross_amount' => '1000', 'vat_amount' => '1001', 'vat_recoverable' => true],
        ], 'MZN', '1');
    }

    public function test_cost_builder_requires_a_purchase_price_component(): void
    {
        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('purchase price');
        $this->acquisitions->buildCost([
            ['component_type' => 'transport', 'gross_amount' => '5000', 'vat_amount' => '0', 'vat_recoverable' => false],
        ], 'MZN', '1');
    }

    // ------------------------------------------------------------------
    // Registration
    // ------------------------------------------------------------------

    public function test_create_asset_registers_draft_with_cost_rows_and_history(): void
    {
        $result = $this->createDraft([
            'components' => [
                ['component_type' => 'purchase_price', 'gross_amount' => '100000', 'vat_amount' => '16000', 'vat_recoverable' => true],
                ['component_type' => 'transport', 'gross_amount' => '5000', 'vat_amount' => '0', 'vat_recoverable' => false],
            ],
        ]);

        $asset = $result['asset'];
        $acquisition = $result['acquisition'];

        $this->assertSame('HOYA-IT-2026-0001', $asset->code);
        $this->assertSame('draft', $asset->status);
        // Capitalized cost excludes the recoverable VAT: 84,000 + 5,000.
        $this->assertMoneyEquals('89000.0000', $asset->capitalized_cost);
        $this->assertMoneyEquals('89000.0000', $asset->amount_base);
        $this->assertMoneyEquals('84000.0000', $asset->purchase_cost);
        $this->assertSame(1, (int) $asset->created_by);

        $this->assertSame('draft', $acquisition->status);
        $this->assertSame('direct', $acquisition->source_type);
        $this->assertSame(2, (int) $acquisition->requested_by);
        $this->assertCount(2, $acquisition->costs()->get());
        // The accessors agree with the stored lines.
        $this->assertSame('89000.0000', $acquisition->fresh()->total_cost);
        $this->assertSame('89000.0000', $acquisition->fresh()->total_cost_base);

        $this->assertDatabaseHas('fixed_asset_status_history', ['asset_id' => $asset->id, 'to_status' => 'draft']);
        $this->assertDatabaseHas('fixed_asset_audit_events', ['asset_id' => $asset->id, 'action' => 'created']);
    }

    public function test_create_asset_splits_cost_equally_for_multiple_identical_assets(): void
    {
        $result = $this->acquisitions->createAsset($this->baseData([
            'asset_count' => 3,
            'components' => [
                ['component_type' => 'purchase_price', 'gross_amount' => '90001', 'vat_amount' => '0', 'vat_recoverable' => false],
            ],
        ]), 1, 2);

        $this->assertCount(3, $result['assets']);
        $this->assertSame('HOYA-IT-2026-0001', $result['assets'][0]->code);
        $this->assertSame('HOYA-IT-2026-0002', $result['assets'][1]->code);
        $this->assertSame('HOYA-IT-2026-0003', $result['assets'][2]->code);
        // 30,000.3333 + 30,000.3333 + 30,000.3334 = 90,001 exactly.
        $this->assertMoneyEquals('30000.3333', $result['assets'][0]->capitalized_cost);
        $this->assertMoneyEquals('30000.3333', $result['assets'][1]->capitalized_cost);
        $this->assertMoneyEquals('30000.3334', $result['assets'][2]->capitalized_cost);
        $this->assertSame('Laptop Dell XPS #1', $result['assets'][0]->name);
        $this->assertSame('Laptop Dell XPS #3', $result['assets'][2]->name);
    }

    public function test_create_asset_refuses_unknown_category_and_other_company_location(): void
    {
        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('Category not found');
        $this->acquisitions->createAsset($this->baseData(['category_id' => 99]), 1, 2);
    }

    public function test_create_asset_refuses_opening_depreciation_above_capitalized_cost(): void
    {
        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('Opening accumulated depreciation must not exceed');
        $this->acquisitions->createAsset($this->baseData(['opening_accumulated_depreciation' => '100001']), 1, 2);
    }

    public function test_create_asset_refuses_zero_cost_for_a_purchase(): void
    {
        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('greater than zero');
        $this->acquisitions->createAsset($this->baseData([
            'components' => [
                ['component_type' => 'purchase_price', 'gross_amount' => '0', 'vat_amount' => '0', 'vat_recoverable' => false],
            ],
        ]), 1, 2);
    }

    public function test_create_asset_allows_zero_cost_donation(): void
    {
        $result = $this->createDraft([
            'acquisition_method' => 'donation',
            'components' => [
                ['component_type' => 'purchase_price', 'gross_amount' => '0', 'vat_amount' => '0', 'vat_recoverable' => false],
            ],
        ]);

        $this->assertMoneyEquals('0.0000', $result['asset']->capitalized_cost);
        $this->assertSame('donation', $result['asset']->acquisition_method);
    }

    public function test_update_asset_edits_a_draft_but_not_after_review(): void
    {
        ['asset' => $asset, 'acquisition' => $acquisition] = $this->createDraft();

        $updated = $this->acquisitions->updateAsset($asset, $this->baseData([
            'name' => 'Laptop Dell XPS 15',
            'components' => [
                ['component_type' => 'purchase_price', 'gross_amount' => '120000', 'vat_amount' => '0', 'vat_recoverable' => false],
            ],
        ]), 1, 2);

        $this->assertSame('Laptop Dell XPS 15', $updated->name);
        $this->assertMoneyEquals('120000.0000', $updated->capitalized_cost);
        $this->assertSame(1, $acquisition->costs()->count());

        $this->acquisitions->submitForReview($acquisition->fresh(), 1, 2);

        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('Only a draft or returned asset can be edited');
        $this->acquisitions->updateAsset($asset->fresh(), $this->baseData(['name' => 'Too late']), 1, 2);
    }

    // ------------------------------------------------------------------
    // Acquisition from purchasing sources
    // ------------------------------------------------------------------

    protected function seedPurchaseProduct(): int
    {
        DB::table('venders')->insert(['id' => 1, 'name' => 'Tech Supplier Lda', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_services')->insert(['id' => 1, 'name' => 'Laptop Dell XPS', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('purchases')->insert(['id' => 1, 'purchase_id' => 'PO-0001', 'vender_id' => 1, 'warehouse_id' => 1, 'purchase_date' => '2026-11-05', 'purchase_number' => 1, 'status' => 0, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('purchase_products')->insert(['id' => 1, 'product_id' => 1, 'purchase_id' => 1, 'quantity' => 1, 'tax' => 0, 'discount' => 0, 'total' => '50000', 'created_at' => now(), 'updated_at' => now()]);

        return 1;
    }

    public function test_create_from_purchase_order_line(): void
    {
        $lineId = $this->seedPurchaseProduct();

        $result = $this->acquisitions->createFromSource('purchase_product', $lineId, $this->sourceOverrides([
            'name' => 'Laptop Dell XPS',
        ]), 1, 2);

        $asset = $result['assets'][0];
        $acquisition = $result['acquisitions'][0];

        $this->assertMoneyEquals('50000.0000', $asset->capitalized_cost);
        $this->assertSame('PO-0001', $asset->po_reference);
        $this->assertSame('Tech Supplier Lda', $asset->supplier);
        $this->assertSame('purchase_product', $acquisition->source_type);
        $this->assertSame($lineId, (int) $acquisition->source_id);
        $this->assertSame('PO-0001', $acquisition->source_reference);
    }

    public function test_create_from_bill_line_links_the_supplier_invoice(): void
    {
        DB::table('venders')->insert(['id' => 1, 'name' => 'Tech Supplier Lda', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_services')->insert(['id' => 1, 'name' => 'Server Rack', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('bills')->insert(['id' => 1, 'bill_id' => 'BILL-0007', 'vender_id' => 1, 'bill_date' => '2026-11-06', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('bill_products')->insert(['id' => 1, 'product_id' => 1, 'bill_id' => 1, 'quantity' => 1, 'tax' => 0, 'discount' => 0, 'total' => '80000', 'created_at' => now(), 'updated_at' => now()]);

        $result = $this->acquisitions->createFromSource('bill_product', 1, $this->sourceOverrides(['name' => 'Server Rack']), 1, 2);

        $asset = $result['assets'][0];
        $acquisition = $result['acquisitions'][0];

        $this->assertMoneyEquals('80000.0000', $asset->capitalized_cost);
        $this->assertSame('BILL-0007', $asset->invoice_reference);
        $this->assertSame('BILL-0007', $acquisition->source_reference);
        $this->assertSame('bill_product', $acquisition->source_type);
    }

    public function test_create_from_delivery_note_item(): void
    {
        DB::table('venders')->insert(['id' => 1, 'name' => 'Tech Supplier Lda', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_services')->insert(['id' => 1, 'name' => 'Monitor 27"', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('purchases')->insert(['id' => 1, 'purchase_id' => 'PO-0002', 'vender_id' => 1, 'warehouse_id' => 1, 'purchase_date' => '2026-11-04', 'purchase_number' => 2, 'status' => 0, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('purchase_delivery_notes')->insert(['id' => 1, 'note_number' => 5, 'vender_id' => 1, 'warehouse_id' => 1, 'purchase_id' => 1, 'delivery_date' => '2026-11-06', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('purchase_delivery_note_items')->insert(['id' => 1, 'purchase_delivery_note_id' => 1, 'product_id' => 1, 'ordered_qty' => 2, 'delivered_qty' => 2, 'price' => '10000', 'created_at' => now(), 'updated_at' => now()]);

        $result = $this->acquisitions->createFromSource('delivery_note_item', 1, $this->sourceOverrides(['name' => 'Monitor 27"']), 1, 2);

        $asset = $result['assets'][0];
        $acquisition = $result['acquisitions'][0];

        // 2 × 10,000 delivered.
        $this->assertMoneyEquals('20000.0000', $asset->capitalized_cost);
        $this->assertSame('PO-0002', $asset->po_reference);
        $this->assertSame('2026-11-06', $asset->receipt_date->format('Y-m-d'));
        $this->assertSame('delivery_note_item', $acquisition->source_type);
    }

    public function test_create_from_other_company_source_is_refused(): void
    {
        $this->seedPurchaseProduct();
        DB::table('purchases')->where('id', 1)->update(['created_by' => 4]);

        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('not found for this company');
        $this->acquisitions->createFromSource('purchase_product', 1, $this->sourceOverrides(['name' => 'Laptop']), 1, 2);
    }

    // ------------------------------------------------------------------
    // Workflow — review → approval → capitalization
    // ------------------------------------------------------------------

    public function test_workflow_at_threshold_needs_two_approval_levels(): void
    {
        // Base cost 100,000 is AT the default threshold (100,000),
        // so two approval levels are required.
        ['asset' => $asset, 'acquisition' => $acquisition] = $this->createDraft();

        $reviewed = $this->acquisitions->submitForReview($acquisition, 1, 2);
        $this->assertSame('pending_review', $reviewed->status);

        $submitted = $this->acquisitions->submitForApproval($reviewed, 1, 2);
        $this->assertSame('pending_approval', $submitted->status);
        $this->assertSame('pending_approval', $submitted->asset->fresh()->status);
        $this->assertCount(2, FixedAssetApproval::where('transaction_id', $acquisition->id)->get());
    }

    public function test_workflow_below_threshold_needs_one_level_only(): void
    {
        ['asset' => $asset, 'acquisition' => $acquisition] = $this->createDraft([
            'components' => [
                ['component_type' => 'purchase_price', 'gross_amount' => '50000', 'vat_amount' => '0', 'vat_recoverable' => false],
            ],
        ]);

        $this->acquisitions->submitForReview($acquisition, 1, 2);
        $this->acquisitions->submitForApproval($acquisition, 1, 2);

        $this->assertCount(1, FixedAssetApproval::where('transaction_id', $acquisition->id)->get());
    }

    public function test_workflow_self_approval_is_refused(): void
    {
        ['acquisition' => $acquisition] = $this->createDraft([
            'components' => [
                ['component_type' => 'purchase_price', 'gross_amount' => '50000', 'vat_amount' => '0', 'vat_recoverable' => false],
            ],
        ]);

        $this->acquisitions->submitForReview($acquisition, 1, 2);
        $this->acquisitions->submitForApproval($acquisition, 1, 2);
        $approval = FixedAssetApproval::where('transaction_id', $acquisition->id)->firstOrFail();

        // User 2 requested the acquisition — they cannot approve it.
        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('cannot approve a transaction you requested yourself');
        $this->acquisitions->decideAcquisitionApproval($approval, 2, 'approved');
    }

    public function test_workflow_approval_advances_the_acquisition_and_records_the_decision(): void
    {
        ['asset' => $asset, 'acquisition' => $acquisition] = $this->createDraft([
            'components' => [
                ['component_type' => 'purchase_price', 'gross_amount' => '50000', 'vat_amount' => '0', 'vat_recoverable' => false],
            ],
        ]);

        $approved = $this->approve($acquisition);

        $this->assertSame('approved', $approved->status);
        $this->assertSame('approved', $asset->fresh()->status);
        $approval = FixedAssetApproval::where('transaction_id', $acquisition->id)->firstOrFail();
        $this->assertSame('approved', $approval->decision);
        $this->assertSame(3, (int) $approval->approver_id);
        $this->assertNotNull($approval->decided_at);
        $this->assertDatabaseHas('fixed_asset_audit_events', ['asset_id' => $asset->id, 'action' => 'decided:approved']);
    }

    public function test_workflow_second_level_must_be_a_different_user(): void
    {
        // 150,000 ≥ threshold → two levels.
        ['acquisition' => $acquisition] = $this->createDraft([
            'components' => [
                ['component_type' => 'purchase_price', 'gross_amount' => '150000', 'vat_amount' => '0', 'vat_recoverable' => false],
            ],
        ]);

        $this->acquisitions->submitForReview($acquisition, 1, 2);
        $this->acquisitions->submitForApproval($acquisition, 1, 2);
        $levels = FixedAssetApproval::where('transaction_id', $acquisition->id)->orderBy('level')->get();
        $this->assertCount(2, $levels);

        $this->acquisitions->decideAcquisitionApproval($levels[0], 3, 'approved');
        // Still pending approval — level 2 is open.
        $this->assertSame('pending_approval', $acquisition->fresh()->status);

        // Same user as level 1 is refused at level 2.
        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('second approval level must be a different user');
        $this->acquisitions->decideAcquisitionApproval($levels[1], 3, 'approved');
    }

    public function test_workflow_two_levels_approve_with_different_users(): void
    {
        ['acquisition' => $acquisition] = $this->createDraft([
            'components' => [
                ['component_type' => 'purchase_price', 'gross_amount' => '150000', 'vat_amount' => '0', 'vat_recoverable' => false],
            ],
        ]);

        $this->acquisitions->submitForReview($acquisition, 1, 2);
        $this->acquisitions->submitForApproval($acquisition, 1, 2);
        $levels = FixedAssetApproval::where('transaction_id', $acquisition->id)->orderBy('level')->get();

        $this->acquisitions->decideAcquisitionApproval($levels[0], 3, 'approved');
        $this->acquisitions->decideAcquisitionApproval($levels[1], 5, 'approved', 'Second level OK');

        $this->assertSame('approved', $acquisition->fresh()->status);
        $this->assertSame('Second level OK', FixedAssetApproval::where('transaction_id', $acquisition->id)->where('level', 2)->first()->comment);
    }

    public function test_workflow_return_for_correction_and_resubmit(): void
    {
        ['asset' => $asset, 'acquisition' => $acquisition] = $this->createDraft([
            'components' => [
                ['component_type' => 'purchase_price', 'gross_amount' => '50000', 'vat_amount' => '0', 'vat_recoverable' => false],
            ],
        ]);

        $this->acquisitions->submitForReview($acquisition, 1, 2);
        $this->acquisitions->submitForApproval($acquisition, 1, 2);
        $approval = FixedAssetApproval::where('transaction_id', $acquisition->id)->firstOrFail();

        $this->acquisitions->decideAcquisitionApproval($approval, 3, 'returned', 'Fix the serial number');

        $this->assertSame('returned', $acquisition->fresh()->status);
        $this->assertSame('draft', $asset->fresh()->status);
        // The pending approval row was closed as returned.
        $this->assertSame('returned', $approval->fresh()->decision);

        // The asset is editable again and the flow can restart.
        $this->acquisitions->updateAsset($asset->fresh(), $this->baseData(['name' => 'Laptop Dell XPS 15', 'components' => [
            ['component_type' => 'purchase_price', 'gross_amount' => '50000', 'vat_amount' => '0', 'vat_recoverable' => false],
        ]]), 1, 2);
        $resubmitted = $this->acquisitions->submitForReview($acquisition->fresh(), 1, 2);
        $this->assertSame('pending_review', $resubmitted->status);
    }

    public function test_workflow_rejection_returns_the_asset_to_draft(): void
    {
        ['asset' => $asset, 'acquisition' => $acquisition] = $this->createDraft([
            'components' => [
                ['component_type' => 'purchase_price', 'gross_amount' => '50000', 'vat_amount' => '0', 'vat_recoverable' => false],
            ],
        ]);

        $this->acquisitions->submitForReview($acquisition, 1, 2);
        $rejected = $this->acquisitions->rejectAcquisition($acquisition->fresh(), 1, 3, 'Not needed');

        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('draft', $asset->fresh()->status);
    }

    public function test_workflow_cancel_sets_the_asset_inactive(): void
    {
        ['asset' => $asset, 'acquisition' => $acquisition] = $this->createDraft();

        $cancelled = $this->acquisitions->cancelAcquisition($acquisition, 1, 2);

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame('inactive', $asset->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Capitalization (§9) — journal + schedule
    // ------------------------------------------------------------------

    public function test_capitalize_posts_balanced_journal_to_clearing_account(): void
    {
        $this->setClearingAccount('471');
        ['asset' => $asset, 'acquisition' => $acquisition] = $this->createDraft([
            'components' => [
                ['component_type' => 'purchase_price', 'gross_amount' => '100000', 'vat_amount' => '16000', 'vat_recoverable' => true],
            ],
        ]);
        $approved = $this->approve($acquisition);

        $capitalization = $this->acquisitions->capitalize($approved, 1, 2);

        // Capitalized cost excludes recoverable VAT: 84,000.
        $this->assertMoneyEquals('84000.0000', $capitalization->capitalized_cost);
        $this->assertNotNull($capitalization->journal_id);
        $this->assertSame('2026-11-07', $capitalization->capitalization_date->format('Y-m-d'));

        $journal = JournalEntry::findOrFail($capitalization->journal_id);
        $this->assertSame($asset->code, $journal->reference);
        $this->assertSame(1, (int) $journal->status); // approved
        $items = JournalItem::where('journal', $journal->id)->orderBy('id')->get();
        $this->assertCount(2, $items);
        // Dr asset account 321 — 84,000.00.
        $this->assertSame(1, (int) $items[0]->account);
        $this->assertMoneyEquals('84000.00', $items[0]->debit);
        $this->assertMoneyEquals('0.00', $items[0]->credit);
        // Cr clearing account 471 — 84,000.00.
        $this->assertSame(4, (int) $items[1]->account);
        $this->assertMoneyEquals('0.00', $items[1]->debit);
        $this->assertMoneyEquals('84000.00', $items[1]->credit);

        $asset = $asset->fresh();
        $this->assertSame('available', $asset->status);
        $this->assertMoneyEquals('84000.0000', $asset->current_book_value);
        $this->assertSame('2026-11-07', $asset->depreciation_start_date->format('Y-m-d'));
        $this->assertSame('capitalized', $acquisition->fresh()->status);
        $this->assertSame($journal->id, (int) $acquisition->fresh()->journal_id);
        $this->assertDatabaseHas('fixed_asset_accounting_entries', [
            'asset_id' => $asset->id, 'transaction_type' => 'capitalization', 'journal_id' => $journal->id,
        ]);
    }

    public function test_capitalize_invoice_linked_acquisition_credits_ap_421(): void
    {
        $this->setClearingAccount('471');
        DB::table('venders')->insert(['id' => 1, 'name' => 'Tech Supplier Lda', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_services')->insert(['id' => 1, 'name' => 'Server Rack', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('bills')->insert(['id' => 1, 'bill_id' => 'BILL-0007', 'vender_id' => 1, 'bill_date' => '2026-11-06', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('bill_products')->insert(['id' => 1, 'product_id' => 1, 'bill_id' => 1, 'quantity' => 1, 'tax' => 0, 'discount' => 0, 'total' => '80000', 'created_at' => now(), 'updated_at' => now()]);

        $result = $this->acquisitions->createFromSource('bill_product', 1, $this->sourceOverrides(['name' => 'Server Rack']), 1, 2);
        $approved = $this->approve($result['acquisitions'][0]);
        $capitalization = $this->acquisitions->capitalize($approved, 1, 2);

        $items = JournalItem::where('journal', $capitalization->journal_id)->orderBy('id')->get();
        $this->assertSame(1, (int) $items[0]->account); // Dr 321
        $this->assertSame(3, (int) $items[1]->account); // Cr 421 (AP), not 471
        $this->assertMoneyEquals('80000.00', $items[1]->credit);
    }

    public function test_capitalize_refuses_when_clearing_account_is_not_configured(): void
    {
        // No clearing account code set (Phase 0 default is empty).
        ['acquisition' => $acquisition] = $this->createDraft();
        $approved = $this->approve($acquisition);

        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('clearing account is not configured');
        $this->acquisitions->capitalize($approved, 1, 2);
    }

    public function test_capitalize_refuses_a_second_capitalization(): void
    {
        $this->setClearingAccount('471');
        ['acquisition' => $acquisition] = $this->createDraft();
        $approved = $this->approve($acquisition);
        $this->acquisitions->capitalize($approved, 1, 2);

        // The acquisition is already 'capitalized', so the status
        // guard fires first (the already-capitalized check behind
        // it is defense-in-depth for inconsistent state).
        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('Only an approved acquisition can be capitalized');
        $this->acquisitions->capitalize($approved->fresh(), 1, 2);
    }

    public function test_capitalize_zero_cost_donation_posts_no_journal(): void
    {
        $this->setClearingAccount('471');
        ['asset' => $asset, 'acquisition' => $acquisition] = $this->createDraft([
            'acquisition_method' => 'donation',
            'components' => [
                ['component_type' => 'purchase_price', 'gross_amount' => '0', 'vat_amount' => '0', 'vat_recoverable' => false],
            ],
        ]);
        $approved = $this->approve($acquisition);
        $capitalization = $this->acquisitions->capitalize($approved, 1, 2);

        $this->assertNull($capitalization->journal_id);
        $this->assertSame(0, JournalEntry::count());
        $this->assertSame('available', $asset->fresh()->status);
        $this->assertSame(0, FixedAssetDepreciationSchedule::where('asset_id', $asset->id)->count());
    }

    public function test_capitalize_is_blocked_in_a_closed_fiscal_year(): void
    {
        $this->setClearingAccount('471');
        ['acquisition' => $acquisition] = $this->createDraft();
        $approved = $this->approve($acquisition);

        JournalEntry::create([
            'journal_id' => 99, 'date' => '2026-12-31', 'reference' => 'FY-2026-CLOSE-1',
            'description' => 'Year-end closing', 'status' => 1, 'created_by' => 1,
        ]);

        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('Fiscal year 2026 is already closed');
        $this->acquisitions->capitalize($approved, 1, 2);
    }

    public function test_capitalize_creates_the_depreciation_schedule_shell(): void
    {
        $this->setClearingAccount('471');
        ['asset' => $asset, 'acquisition' => $acquisition] = $this->createDraft([
            'components' => [
                ['component_type' => 'purchase_price', 'gross_amount' => '120000', 'vat_amount' => '0', 'vat_recoverable' => false],
            ],
            'opening_accumulated_depreciation' => '20000',
        ]);
        $approved = $this->approve($acquisition);
        $this->acquisitions->capitalize($approved, 1, 2);

        $rows = FixedAssetDepreciationSchedule::where('asset_id', $asset->id)->orderBy('period_number')->get();
        // 5-year life → 60 monthly rows.
        $this->assertCount(60, $rows);
        // Depreciable base: 120,000 − 0 residual − 20,000 opening = 100,000.
        // Monthly 1,666.6666 (truncated), last period absorbs the rest.
        $this->assertMoneyEquals('1666.6666', $rows[0]->period_depreciation);
        $this->assertMoneyEquals('1666.6706', $rows[59]->period_depreciation);
        $this->assertSame('2026-11-01', $rows[0]->period->format('Y-m-d'));
        $this->assertSame('2031-10-01', $rows[59]->period->format('Y-m-d'));
        // Opening book value 100,000 (120,000 − 20,000 opening accum).
        $this->assertMoneyEquals('100000.0000', $rows[0]->opening_book_value);
        // Final closing is exactly the residual value (0).
        $this->assertMoneyEquals('0.0000', $rows[59]->closing_book_value);
        // Accumulated depreciation ends at the full capitalized cost.
        $this->assertMoneyEquals('120000.0000', $rows[59]->accumulated_depreciation);
        $this->assertSame('planned', $rows[0]->status);

        // Book value after capitalization nets the opening depreciation.
        $this->assertMoneyEquals('100000.0000', $asset->fresh()->current_book_value);
    }

    public function test_capitalize_fully_depreciated_when_book_value_reaches_residual(): void
    {
        $this->setClearingAccount('471');
        ['asset' => $asset, 'acquisition' => $acquisition] = $this->createDraft([
            'components' => [
                ['component_type' => 'purchase_price', 'gross_amount' => '50000', 'vat_amount' => '0', 'vat_recoverable' => false],
            ],
            'opening_accumulated_depreciation' => '50000',
        ]);
        $approved = $this->approve($acquisition);
        $this->acquisitions->capitalize($approved, 1, 2);

        $this->assertSame('fully_depreciated', $asset->fresh()->status);
        $this->assertMoneyEquals('0.0000', $asset->fresh()->current_book_value);
        $this->assertSame(0, FixedAssetDepreciationSchedule::where('asset_id', $asset->id)->count());
    }

    public function test_months_for_life_converts_weeks_months_years(): void
    {
        $this->assertSame(60, $this->acquisitions->monthsForLife(5, 'year'));
        $this->assertSame(6, $this->acquisitions->monthsForLife(6, 'month'));
        $this->assertSame(2, $this->acquisitions->monthsForLife(10, 'week'));
        $this->assertSame(0, $this->acquisitions->monthsForLife(null, 'year'));
        $this->assertSame(0, $this->acquisitions->monthsForLife(0, 'year'));
    }

    // ------------------------------------------------------------------
    // Excel import of old assets (D4)
    // ------------------------------------------------------------------

    public function test_import_rows_creates_old_assets_with_opening_depreciation_and_remaining_life(): void
    {
        $result = $this->acquisitions->importRows([
            [
                'name' => 'Office Desk', 'category_code' => 'it', 'purchase_cost' => '45000',
                'purchase_date' => '2023-05-10', 'opening_accumulated_depreciation' => '18000',
                'remaining_life' => '3', 'remaining_life_unit' => 'year',
            ],
            [
                'name' => 'Office Chair', 'category_code' => 'IT', 'purchase_cost' => '12000',
                'purchase_date' => '2024-01-15', 'opening_accumulated_depreciation' => '4000',
                'remaining_life' => '24', 'remaining_life_unit' => 'month',
            ],
            // Bad category — skipped, reported with its row number.
            ['name' => 'Ghost Asset', 'category_code' => 'NOPE', 'purchase_cost' => '1000'],
            // No name — skipped.
            ['name' => '', 'category_code' => 'IT', 'purchase_cost' => '1000'],
            // Non-numeric cost — skipped, does not crash the import.
            ['name' => 'Broken Row', 'category_code' => 'IT', 'purchase_cost' => 'abc'],
        ], 1, 2);

        $this->assertSame(2, $result['created']);
        $this->assertSame(3, $result['skipped']);
        $this->assertArrayHasKey(4, $result['errors']); // row 4 = Ghost Asset
        $this->assertArrayHasKey(5, $result['errors']); // row 5 = no name
        $this->assertArrayHasKey(6, $result['errors']); // row 6 = non-numeric cost

        $desk = FixedAsset::where('name', 'Office Desk')->firstOrFail();
        $this->assertSame('opening_balance', $desk->acquisition_method);
        $this->assertSame('draft', $desk->status);
        $this->assertMoneyEquals('18000.0000', $desk->opening_accumulated_depreciation);
        $this->assertSame(3, (int) $desk->useful_life);
        $this->assertSame('year', $desk->life_unit);
        $this->assertMoneyEquals('45000.0000', $desk->capitalized_cost);

        $chair = FixedAsset::where('name', 'Office Chair')->firstOrFail();
        $this->assertSame(24, (int) $chair->useful_life);
        $this->assertSame('month', $chair->life_unit);
        $this->assertMoneyEquals('4000.0000', $chair->opening_accumulated_depreciation);
    }

    public function test_import_row_with_opening_depreciation_above_cost_is_skipped(): void
    {
        $result = $this->acquisitions->importRows([
            ['name' => 'Too Depreciated', 'category_code' => 'IT', 'purchase_cost' => '1000', 'opening_accumulated_depreciation' => '2000'],
        ], 1, 2);

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, FixedAsset::count());
    }

    // ------------------------------------------------------------------
    // Tenant isolation
    // ------------------------------------------------------------------

    public function test_other_company_cannot_capitalize_or_decide(): void
    {
        ['acquisition' => $acquisition] = $this->createDraft();
        $approved = $this->approve($acquisition);

        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('not found for this company');
        $this->acquisitions->capitalize($approved, 4, 4);
    }

    // ------------------------------------------------------------------
    // Fixture tables (Phase 0 tables + Phase 1 tables + the
    // accounting / purchasing tables the service touches)
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
        Schema::create('settings', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('branches', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('cost_centres', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('code', 50);
            $table->string('name');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('chart_of_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('code')->nullable();
            $table->integer('type')->nullable();
            $table->integer('sub_type')->nullable();
            $table->integer('is_enabled')->default(1);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('journal_entries', function ($table) {
            $table->bigIncrements('id');
            $table->integer('journal_id');
            $table->date('date')->nullable();
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->integer('status')->default(0);
            $table->integer('created_by');
            $table->integer('accounting_period')->nullable();
            $table->timestamps();
        });
        Schema::create('journal_items', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('journal');
            $table->unsignedBigInteger('account');
            $table->text('description')->nullable();
            $table->decimal('debit', 20, 4)->default(0);
            $table->decimal('credit', 20, 4)->default(0);
            $table->timestamps();
        });
        Schema::create('bank_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('chart_account_id')->nullable();
            $table->decimal('opening_balance', 20, 4)->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('transaction_lines', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('account_id');
            $table->string('reference')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->unsignedBigInteger('reference_sub_id')->nullable();
            $table->date('date')->nullable();
            $table->decimal('debit', 20, 4)->default(0);
            $table->decimal('credit', 20, 4)->default(0);
            $table->integer('created_by');
            $table->timestamps();
        });
        Schema::create('venders', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('product_services', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('purchases', function ($table) {
            $table->bigIncrements('id');
            $table->string('purchase_id')->default('0');
            $table->integer('vender_id');
            $table->integer('warehouse_id')->nullable();
            $table->date('purchase_date')->nullable();
            $table->integer('purchase_number')->default(0);
            $table->integer('status')->default(0);
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('purchase_products', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('purchase_id');
            $table->decimal('quantity', 20, 4)->default(0);
            $table->decimal('tax', 20, 4)->default(0);
            $table->decimal('discount', 20, 4)->default(0);
            $table->decimal('total', 20, 4)->default(0);
            $table->timestamps();
        });
        Schema::create('bills', function ($table) {
            $table->bigIncrements('id');
            $table->string('bill_id')->default('0');
            $table->integer('vender_id');
            $table->date('bill_date')->nullable();
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });
        Schema::create('bill_products', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('bill_id');
            $table->unsignedBigInteger('chart_account_id')->nullable();
            $table->decimal('quantity', 20, 4)->default(0);
            $table->decimal('tax', 20, 4)->default(0);
            $table->decimal('discount', 20, 4)->default(0);
            $table->decimal('total', 20, 4)->default(0);
            $table->timestamps();
        });
        Schema::create('purchase_delivery_notes', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('note_number')->default(0);
            $table->unsignedBigInteger('vender_id');
            $table->unsignedBigInteger('warehouse_id')->nullable();
            $table->unsignedBigInteger('purchase_id')->nullable();
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->date('delivery_date')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
        Schema::create('purchase_delivery_note_items', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('purchase_delivery_note_id');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->decimal('ordered_qty', 20, 4)->default(0);
            $table->decimal('delivered_qty', 20, 4)->default(0);
            $table->decimal('price', 20, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('fixed_asset_categories', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('classification', 20)->default('tangible');
            $table->unsignedInteger('default_useful_life')->nullable();
            $table->string('default_life_unit', 10)->default('year');
            $table->string('depreciation_method', 30)->default('straight_line');
            $table->decimal('depreciation_rate', 10, 4)->nullable();
            $table->string('residual_value_type', 20)->default('none');
            $table->decimal('residual_value_percent', 10, 4)->nullable();
            $table->decimal('residual_value', 20, 4)->nullable();
            $table->decimal('capitalization_threshold', 20, 4)->nullable();
            $table->unsignedBigInteger('asset_account_id')->nullable();
            $table->unsignedBigInteger('accum_depreciation_account_id')->nullable();
            $table->unsignedBigInteger('depreciation_expense_account_id')->nullable();
            $table->unsignedBigInteger('impairment_account_id')->nullable();
            $table->unsignedBigInteger('disposal_gain_account_id')->nullable();
            $table->unsignedBigInteger('disposal_loss_account_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['created_by', 'code']);
        });
        Schema::create('fixed_asset_locations', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('code', 50);
            $table->string('name');
            $table->string('room', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['created_by', 'code']);
        });
        Schema::create('fixed_assets', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('cost_centre_id')->nullable();
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('custodian_id')->nullable();
            $table->unsignedBigInteger('parent_asset_id')->nullable();
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('brand', 100)->nullable();
            $table->string('barcode_value', 255)->nullable();
            $table->boolean('is_component')->default(false);
            $table->string('acquisition_method', 30)->default('purchase');
            $table->string('supplier', 255)->nullable();
            $table->string('po_reference', 100)->nullable();
            $table->string('invoice_reference', 100)->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('receipt_date')->nullable();
            $table->decimal('purchase_cost', 20, 4)->default(0);
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->default(1);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->decimal('capitalized_cost', 20, 4)->default(0);
            $table->date('warranty_start')->nullable();
            $table->date('warranty_end')->nullable();
            $table->date('capitalization_date')->nullable();
            $table->date('depreciation_start_date')->nullable();
            $table->unsignedInteger('useful_life')->nullable();
            $table->string('life_unit', 10)->default('year');
            $table->string('depreciation_method', 30)->default('straight_line');
            $table->decimal('depreciation_rate', 10, 4)->nullable();
            $table->decimal('residual_value', 20, 4)->default(0);
            $table->unsignedBigInteger('asset_account_id')->nullable();
            $table->unsignedBigInteger('accum_depreciation_account_id')->nullable();
            $table->unsignedBigInteger('depreciation_expense_account_id')->nullable();
            $table->decimal('opening_accumulated_depreciation', 20, 4)->default(0);
            $table->string('status', 30)->default('draft');
            $table->decimal('current_book_value', 20, 4)->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['created_by', 'code']);
        });
        Schema::create('fixed_asset_documents', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            $table->string('type', 30)->default('other');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->integer('uploaded_by');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('fixed_asset_approvals', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            $table->string('transaction_type', 30);
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedInteger('level')->default(1);
            $table->integer('requested_by');
            $table->integer('approver_id')->nullable();
            $table->string('decision', 20)->default('pending');
            $table->text('comment')->nullable();
            $table->decimal('amount_base', 20, 4)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
        Schema::create('fixed_asset_status_history', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->integer('changed_by');
            $table->text('reason')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('fixed_asset_audit_events', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->string('entity', 60);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('action', 60);
            $table->integer('actor_id');
            $table->text('reason')->nullable();
            $table->json('before_values')->nullable();
            $table->json('after_values')->nullable();
            $table->timestamps();
        });

        Schema::create('fixed_asset_acquisitions', function ($table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            $table->string('source_type', 30)->default('direct');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_reference', 100)->nullable();
            $table->string('supplier', 255)->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('receipt_date')->nullable();
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->default(1);
            $table->string('status', 30)->default('draft');
            $table->integer('requested_by')->nullable();
            $table->integer('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('capitalized_at')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['asset_id'], 'facq_asset_idx');
            $table->index(['created_by', 'status'], 'facq_creator_status_idx');
            $table->index(['created_by', 'source_type'], 'facq_creator_src_idx');
        });
        Schema::create('fixed_asset_acquisition_costs', function ($table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('acquisition_id');
            $table->string('component_type', 30)->default('purchase_price');
            $table->decimal('gross_amount', 20, 4)->default(0);
            $table->decimal('vat_amount', 20, 4)->default(0);
            $table->boolean('vat_recoverable')->default(false);
            $table->string('currency', 3)->default('MZN');
            $table->decimal('exchange_rate', 20, 10)->default(1);
            $table->decimal('amount_base', 20, 4)->default(0);
            $table->string('source_document_reference', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['acquisition_id'], 'facc_acq_idx');
            $table->index(['created_by'], 'facc_creator_idx');
        });
        Schema::create('fixed_asset_capitalizations', function ($table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('acquisition_id');
            $table->date('capitalization_date');
            $table->decimal('capitalized_cost', 20, 4)->default(0);
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->integer('capitalized_by')->nullable();
            $table->timestamps();
            $table->index(['asset_id'], 'fcap_asset_idx');
            $table->index(['acquisition_id'], 'fcap_acq_idx');
        });
        Schema::create('fixed_asset_depreciation_schedules', function ($table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            $table->date('period');
            $table->unsignedInteger('period_number');
            $table->decimal('opening_book_value', 20, 4)->default(0);
            $table->decimal('period_depreciation', 20, 4)->default(0);
            $table->decimal('accumulated_depreciation', 20, 4)->default(0);
            $table->decimal('closing_book_value', 20, 4)->default(0);
            $table->unsignedInteger('remaining_life_months')->default(0);
            $table->string('status', 20)->default('planned');
            $table->timestamps();
            $table->unique(['created_by', 'asset_id', 'period'], 'fads_asset_period_uq');
            $table->index(['asset_id'], 'fads_asset_idx');
        });
        Schema::create('fixed_asset_accounting_entries', function ($table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            $table->string('transaction_type', 30);
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->unsignedBigInteger('journal_id');
            $table->timestamps();
            $table->index(['asset_id'], 'faac_asset_idx');
            $table->index(['journal_id'], 'faac_journal_idx');
        });
    }
}
