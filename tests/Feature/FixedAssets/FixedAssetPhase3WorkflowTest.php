<?php

namespace Tests\Feature\FixedAssets;

use App\Exceptions\FixedAssetException;
use App\Models\FixedAsset;
use App\Models\FixedAssetAcquisition;
use App\Models\FixedAssetApproval;
use App\Models\FixedAssetDepreciationEntry;
use App\Models\FixedAssetDepreciationRun;
use App\Models\JournalItem;
use App\Models\JournalLineDimension;
use App\Models\User;
use App\Services\Accounting\ChartAccountResolver;
use App\Services\Accounting\JournalEntryPoster;
use App\Services\FixedAssetAcquisitionService;
use App\Services\FixedAssetDepreciationService;
use App\Services\FixedAssetNumberingService;
use App\Services\FixedAssetService;
use App\Services\FixedAssetSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Fixed Assets Phase 3 — Depreciation (straight-line), HTTP
 * level (plan §5, §10; spec §13, §14).
 *
 * Covers: preview (amounts shown, nothing persisted) → run
 * created → reviewed → submitted for approval → approved →
 * posted with ONE balanced journal (Dr depreciation expense
 * 631 / Cr accumulated depreciation 328); no self-approval;
 * duplicate-period guard; closed-fiscal-year block; final
 * period closes the asset to its residual exactly; cost-centre
 * dimensions (single centre + mid-period transfer split);
 * per-asset schedule view (planned vs posted); monthly totals
 * report; permission and cross-company denial.
 *
 * Users: 1 = Company (all fixed-asset permissions), 2 =
 * Operator (manage fixed assets only), 3 = Approver (manage
 * + approve acquisitions + approve transfers + post fixed
 * asset depreciation), 4 = Other Company (all fixed-asset
 * permissions), 5 = Custodian user (no fixed-asset
 * permission, linked to employee Ana), 6 = No Permission.
 * "Today" is frozen at 2026-11-07. Employees: 1 = Ana
 * (user 5), 2 = Bruno (user 3). Locations: 1 = WH1, 2 = WH2.
 * Cost centres: 1 = LOG, 2 = ADM. Chart: 1 = 321 asset,
 * 2 = 328 accumulated depreciation, 3 = 421 AP, 4 = 471
 * clearing, 5 = 631 depreciation expense.
 *
 * The capitalized asset from createCapitalizedAsset() costs
 * 47,000 (50,000 − 8,000 recoverable VAT + 5,000 transport),
 * 5-year life → 60 monthly rows of 783.3333, first period
 * 2026-11-01. A November run therefore posts 783.33.
 */
class FixedAssetPhase3WorkflowTest extends TestCase
{
    protected FixedAssetService $service;
    protected FixedAssetNumberingService $numbering;
    protected FixedAssetAcquisitionService $acquisitions;
    protected FixedAssetDepreciationService $depreciation;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-07 09:00:00'));
        $this->createTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['manage transaction', 'manage fixed assets', 'create fixed assets', 'edit fixed assets', 'delete fixed assets', 'approve fixed asset acquisitions', 'approve fixed asset transfers', 'approve fixed asset disposals', 'post fixed asset depreciation', 'manage fixed asset categories', 'manage fixed asset settings', 'view fixed asset audit trail'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Hoya Hoya', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Operator', 'email' => 'operator@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Uthara', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 5, 'name' => 'Custodian Ana', 'email' => 'ana@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        $all = ['manage transaction', 'manage fixed assets', 'create fixed assets', 'edit fixed assets', 'delete fixed assets', 'approve fixed asset acquisitions', 'approve fixed asset transfers', 'approve fixed asset disposals', 'post fixed asset depreciation', 'manage fixed asset categories', 'manage fixed asset settings', 'view fixed asset audit trail'];
        User::findOrFail(1)->givePermissionTo($all);
        User::findOrFail(2)->givePermissionTo(['manage transaction', 'manage fixed assets']);
        User::findOrFail(3)->givePermissionTo(['manage transaction', 'manage fixed assets', 'approve fixed asset acquisitions', 'approve fixed asset transfers', 'post fixed asset depreciation']);
        User::findOrFail(4)->givePermissionTo($all);
        User::findOrFail(5)->givePermissionTo(['manage transaction']);
        User::findOrFail(6)->givePermissionTo(['manage transaction']);

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Main Branch', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'name' => 'Tangible fixed assets', 'code' => 321, 'type' => 3, 'sub_type' => 2, 'is_enabled' => 1, 'created_by' => 1],
            ['id' => 2, 'name' => 'Accumulated depreciation', 'code' => 328, 'type' => 3, 'sub_type' => 2, 'is_enabled' => 1, 'created_by' => 1],
            ['id' => 3, 'name' => 'Accounts payable', 'code' => 421, 'type' => 4, 'sub_type' => 1, 'is_enabled' => 1, 'created_by' => 1],
            ['id' => 4, 'name' => 'Asset clearing account', 'code' => 471, 'type' => 4, 'sub_type' => 1, 'is_enabled' => 1, 'created_by' => 1],
            ['id' => 5, 'name' => 'Depreciation expense', 'code' => 631, 'type' => 6, 'sub_type' => 1, 'is_enabled' => 1, 'created_by' => 1],
        ]);
        DB::table('cost_centres')->insert([
            ['id' => 1, 'company_id' => 1, 'created_by' => 1, 'code' => 'LOG', 'name' => 'Logistics', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'company_id' => 1, 'created_by' => 1, 'code' => 'ADM', 'name' => 'Administration', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('employees')->insert([
            ['id' => 1, 'name' => 'Ana', 'user_id' => 5, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Bruno', 'user_id' => 3, 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->service = new FixedAssetService();
        $this->numbering = new FixedAssetNumberingService();
        $this->acquisitions = new FixedAssetAcquisitionService(
            $this->service,
            $this->numbering,
            new JournalEntryPoster(),
            new ChartAccountResolver(),
        );
        $this->depreciation = new FixedAssetDepreciationService(
            $this->service,
            $this->acquisitions,
            new JournalEntryPoster(),
            new ChartAccountResolver(),
        );

        $this->service->createCategory([
            'code' => 'IT', 'name' => 'IT Equipment', 'classification' => 'tangible',
            'default_useful_life' => 5, 'default_life_unit' => 'year',
            'depreciation_method' => 'straight_line', 'residual_value_type' => 'none',
            'asset_account_id' => 1, 'accum_depreciation_account_id' => 2,
            'depreciation_expense_account_id' => 5,
        ], 1, 1);
        $this->service->createLocation(['code' => 'WH1', 'name' => 'Warehouse 1', 'branch_id' => 1], 1, 1);
        $this->service->createLocation(['code' => 'WH2', 'name' => 'Warehouse 2', 'branch_id' => 1], 1, 1);

        FixedAssetSettings::save(1, [FixedAssetSettings::KEY_CLEARING_ACCOUNT_CODE => '471']);
    }

        protected function tearDown(): void
    {
        Carbon::setTestNow();
        \App\Models\Utility::$rates = null;
        \App\Models\Utility::$data = null;

        foreach (['purchase_delivery_note_items', 'purchase_delivery_notes', 'bill_products', 'bills', 'purchase_products', 'purchases', 'product_services', 'venders', 'transaction_lines', 'bank_accounts', 'journal_items', 'journal_entries', 'fixed_asset_accounting_entries', 'fixed_asset_assignments', 'fixed_asset_transfers', 'fixed_asset_depreciation_runs', 'fixed_asset_depreciation_entries', 'journal_line_dimensions', 'dimension_audit_events', 'fixed_asset_depreciation_schedules', 'fixed_asset_capitalizations', 'fixed_asset_acquisition_costs', 'departments', 'employees', 'fixed_asset_acquisitions', 'fixed_asset_audit_events', 'fixed_asset_status_history', 'fixed_asset_approvals', 'fixed_asset_documents', 'fixed_assets', 'fixed_asset_locations', 'fixed_asset_categories', 'cost_centres', 'chart_of_accounts', 'email_templates', 'ch_messages', 'languages', 'plans', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'permissions', 'roles', 'users', 'settings', 'branch_user', 'branches'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function formPayload(array $overrides = []): array
    {
        return array_merge([
            'category_id' => 1,
            'name' => 'Laptop Dell XPS',
            'acquisition_method' => 'purchase',
            'source_type' => 'direct',
            'currency' => 'MZN',
            'exchange_rate' => '1',
            'purchase_date' => '2026-11-07',
            'cost_centre_id' => 1,
            'useful_life' => 5,
            'life_unit' => 'year',
            'components' => [
                ['component_type' => 'purchase_price', 'gross_amount' => '50000', 'vat_amount' => '8000', 'vat_recoverable' => '1'],
                ['component_type' => 'transport', 'gross_amount' => '5000', 'vat_amount' => '0'],
            ],
        ], $overrides);
    }

    protected function createDraftViaForm(int $userId = 1, array $overrides = []): FixedAssetAcquisition
    {
        $response = $this->actingAs(User::findOrFail($userId))->post(route('fixed-assets.store'), $this->formPayload($overrides));
        $response->assertRedirect();

        return FixedAssetAcquisition::orderByDesc('id')->firstOrFail();
    }

    /** Full Phase 1 flow via HTTP: draft → review → approval → capitalize. */
    protected function createCapitalizedAsset(): FixedAsset
    {
        $acquisition = $this->createDraftViaForm();
        $asset = $acquisition->asset;

        $this->actingAs(User::findOrFail(1))->post(route('fixed-asset-acquisitions.submit-review', $acquisition->id))->assertRedirect();
        $this->actingAs(User::findOrFail(1))->post(route('fixed-asset-acquisitions.submit-approval', $acquisition->id))->assertRedirect();
        $this->actingAs(User::findOrFail(3))->post(route('fixed-asset-acquisitions.decide', $acquisition->id), ['decision' => 'approved', 'reason' => 'OK'])->assertRedirect();
        $this->actingAs(User::findOrFail(1))->post(route('fixed-asset-acquisitions.capitalize', $acquisition->id))->assertRedirect();

        $this->assertSame('available', $asset->fresh()->status);
        $this->assertSame(60, \App\Models\FixedAssetDepreciationSchedule::where('asset_id', $asset->id)->count());

        return $asset->fresh();
    }

    /** Drive a run for one period to POSTED over HTTP; returns it. */
    protected function postPeriod(string $period, int $submitterId = 1, int $approverId = 3): FixedAssetDepreciationRun
    {
        $this->actingAs(User::findOrFail($submitterId))
            ->post(route('fixed-asset-depreciation.store'), ['period' => $period])
            ->assertRedirect();
        $run = FixedAssetDepreciationRun::orderByDesc('id')->firstOrFail();

        $this->actingAs(User::findOrFail($submitterId))
            ->post(route('fixed-asset-depreciation.submit-review', $run->id))
            ->assertRedirect();
        $this->actingAs(User::findOrFail($submitterId))
            ->post(route('fixed-asset-depreciation.submit-approval', $run->id))
            ->assertRedirect();
        $this->actingAs(User::findOrFail($approverId))
            ->post(route('fixed-asset-depreciation.decide', $run->id), ['comment' => 'OK'])
            ->assertRedirect();
        $this->actingAs(User::findOrFail($submitterId))
            ->post(route('fixed-asset-depreciation.post', $run->id))
            ->assertRedirect();

        $this->assertSame('posted', $run->fresh()->status);

        return $run->fresh();
    }

    /** Drive a transfer to "received" over HTTP (Phase 2 flow), then force its received date. */
    protected function receiveTransferOn(FixedAsset $asset, string $receivedDate, int $toCostCentreId = 2): void
    {
        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-transfers.store'), [
                'asset_id' => $asset->id,
                'to_location_id' => 2,
                'to_custodian_id' => 2,
                'to_cost_centre_id' => $toCostCentreId,
                'reason' => 'Moved to Warehouse 2',
            ])
            ->assertRedirect();
        $transfer = \App\Models\FixedAssetTransfer::orderByDesc('id')->firstOrFail();

        $this->actingAs(User::findOrFail(3))
            ->post(route('fixed-asset-transfers.decide', $transfer->id), ['decision' => 'approved', 'reason' => 'OK'])
            ->assertRedirect();
        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-transfers.handover', $transfer->id))
            ->assertRedirect();
        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-transfers.receive', $transfer->id))
            ->assertRedirect();

        $this->assertSame('received', $transfer->fresh()->status);

        // The service stamps received_at = now() (frozen 2026-11-07);
        // the split test needs a mid-month receipt, so move it.
        DB::table('fixed_asset_transfers')->where('id', $transfer->id)->update(['received_at' => $receivedDate.' 09:00:00']);
    }

    // ------------------------------------------------------------------
    // Preview
    // ------------------------------------------------------------------

    public function test_preview_shows_amounts_without_persisting_anything(): void
    {
        $asset = $this->createCapitalizedAsset();

        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-depreciation.preview'), ['period' => '2026-11']);

        $response->assertOk();
        $response->assertSee($asset->code);
        $response->assertSee('783.33');

        $this->assertSame(0, FixedAssetDepreciationRun::count());
        $this->assertSame(0, FixedAssetDepreciationEntry::count());
        $this->assertSame(0, DB::table('journal_entries')->where('reference', 'like', 'FA-DEP-%')->count());
    }

    public function test_preview_for_a_period_with_nothing_due_shows_empty(): void
    {
        $this->createCapitalizedAsset();

        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-depreciation.preview'), ['period' => '2026-10']);

        $response->assertOk();
        $response->assertSee('No assets have depreciation due');
    }

    // ------------------------------------------------------------------
    // Full workflow over HTTP
    // ------------------------------------------------------------------

    public function test_full_workflow_posts_one_balanced_journal(): void
    {
        $asset = $this->createCapitalizedAsset();

        $run = $this->postPeriod('2026-11');

        $this->assertSame('783.33', (string) $run->total_amount);
        $this->assertSame(1, $run->asset_count);

        $entry = FixedAssetDepreciationEntry::where('run_id', $run->id)->firstOrFail();
        $this->assertSame('posted', $entry->status);
        $this->assertSame('783.33', (string) $entry->posted_amount);
        $this->assertSame(1, (int) $entry->cost_centre_id);

        // ONE journal per run: Dr depreciation expense 631 /
        // Cr accumulated depreciation 328, balanced.
        $journal = DB::table('journal_entries')->where('id', $run->journal_id)->first();
        $this->assertNotNull($journal);
        $this->assertSame('FA-DEP-2026-11', $journal->reference);
        $this->assertSame('2026-11-30', $journal->date);
        $items = DB::table('journal_items')->where('journal', $journal->id)->orderBy('id')->get();
        $this->assertCount(2, $items);
        $this->assertSame(5, (int) $items[0]->account);
        $this->assertSame('783.33', (string) $items[0]->debit);
        $this->assertSame(2, (int) $items[1]->account);
        $this->assertSame('783.33', (string) $items[1]->credit);

        // Schedule row is posted; book value moved by the amount.
        $row = \App\Models\FixedAssetDepreciationSchedule::where('asset_id', $asset->id)
            ->whereDate('period', '2026-11-01')->firstOrFail();
        $this->assertSame('posted', $row->status);
        $this->assertSame('46216.67', (string) $asset->fresh()->current_book_value);

        // Accounting bridge + dimension row (cost centre LOG,
        // asset stamped).
        $bridge = DB::table('fixed_asset_accounting_entries')
            ->where('asset_id', $asset->id)
            ->where('transaction_type', 'depreciation')
            ->first();
        $this->assertNotNull($bridge);
        $this->assertSame((int) $run->journal_id, (int) $bridge->journal_id);

        $dimension = JournalLineDimension::where('line_type', 'journal_item')
            ->where('line_id', (int) $items[0]->id)
            ->where('status', 'active')
            ->firstOrFail();
        $this->assertSame(1, (int) $dimension->cost_centre_id);
        $this->assertSame((int) $asset->id, (int) $dimension->asset_id);
        $this->assertSame('783.33', (string) $dimension->allocated_amount);
    }

    public function test_self_approval_is_refused(): void
    {
        $this->createCapitalizedAsset();

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-depreciation.store'), ['period' => '2026-11'])
            ->assertRedirect();
        $run = FixedAssetDepreciationRun::orderByDesc('id')->firstOrFail();

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-depreciation.submit-review', $run->id))
            ->assertRedirect();
        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-depreciation.submit-approval', $run->id))
            ->assertRedirect();

        // User 1 requested the run, so user 1 cannot approve it.
        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-depreciation.decide', $run->id), ['comment' => 'OK']);
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame('reviewed', $run->fresh()->status);
        $this->assertSame(0, DB::table('journal_entries')->where('reference', 'like', 'FA-DEP-%')->count());
    }

    public function test_decide_denied_without_the_post_permission(): void
    {
        $this->createCapitalizedAsset();

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-depreciation.store'), ['period' => '2026-11'])
            ->assertRedirect();
        $run = FixedAssetDepreciationRun::orderByDesc('id')->firstOrFail();

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-depreciation.submit-review', $run->id))
            ->assertRedirect();
        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-depreciation.submit-approval', $run->id))
            ->assertRedirect();

        // User 2 has manage fixed assets but not post depreciation.
        $response = $this->actingAs(User::findOrFail(2))
            ->post(route('fixed-asset-depreciation.decide', $run->id), ['comment' => 'OK']);
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame('reviewed', $run->fresh()->status);
    }

    public function test_post_before_approval_is_refused(): void
    {
        $this->createCapitalizedAsset();

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-depreciation.store'), ['period' => '2026-11'])
            ->assertRedirect();
        $run = FixedAssetDepreciationRun::orderByDesc('id')->firstOrFail();

        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-depreciation.post', $run->id));
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame('calculated', $run->fresh()->status);
        $this->assertSame(0, DB::table('journal_entries')->where('reference', 'like', 'FA-DEP-%')->count());
    }

    // ------------------------------------------------------------------
    // Guards
    // ------------------------------------------------------------------

    public function test_second_run_for_the_same_period_is_refused(): void
    {
        $this->createCapitalizedAsset();
        $this->postPeriod('2026-11');

        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-depreciation.store'), ['period' => '2026-11']);
        $response->assertRedirect();
        $response->assertSessionHas('error');

        $this->assertSame(1, FixedAssetDepreciationRun::where('status', '!=', 'cancelled')->count());
        $this->assertSame(1, DB::table('journal_entries')->where('reference', 'like', 'FA-DEP-%')->count());
    }

    public function test_cancelled_run_frees_the_period(): void
    {
        $this->createCapitalizedAsset();

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-depreciation.store'), ['period' => '2026-11'])
            ->assertRedirect();
        $run = FixedAssetDepreciationRun::orderByDesc('id')->firstOrFail();

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-depreciation.cancel', $run->id), ['reason' => 'Wrong month'])
            ->assertRedirect();
        $this->assertSame('cancelled', $run->fresh()->status);

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-depreciation.store'), ['period' => '2026-11'])
            ->assertRedirect();
        $this->assertSame(1, FixedAssetDepreciationRun::where('status', '!=', 'cancelled')->count());
    }

    public function test_closed_fiscal_year_blocks_posting(): void
    {
        $this->createCapitalizedAsset();

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-depreciation.store'), ['period' => '2026-12'])
            ->assertRedirect();
        $run = FixedAssetDepreciationRun::orderByDesc('id')->firstOrFail();

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-depreciation.submit-review', $run->id))
            ->assertRedirect();
        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-depreciation.submit-approval', $run->id))
            ->assertRedirect();
        $this->actingAs(User::findOrFail(3))
            ->post(route('fixed-asset-depreciation.decide', $run->id), ['comment' => 'OK'])
            ->assertRedirect();
        $this->assertSame('approved', $run->fresh()->status);

        // Close the year (the same FY-CLOSE rule capitalization uses).
        DB::table('journal_entries')->insert([
            'journal_id' => 99, 'date' => '2026-12-31', 'reference' => 'FY-2026-CLOSE-1',
            'description' => 'Year-end closing', 'status' => 1, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-depreciation.post', $run->id));
        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame('approved', $run->fresh()->status);
        $this->assertSame(0, DB::table('journal_entries')->where('reference', 'like', 'FA-DEP-%')->count());
    }

    public function test_final_period_closes_the_asset_to_its_residual_exactly(): void
    {
        // 50,000 over 3 months: 16,666.6666 × 2 + final 16,666.66.
        // (100,000 would need TWO approval levels, so it stays
        // unapproved after a single decide() and never capitalizes.)
        $acquisition = $this->createDraftViaForm(1, [
            'useful_life' => 3,
            'life_unit' => 'month',
            'components' => [
                ['component_type' => 'purchase_price', 'gross_amount' => '50000', 'vat_amount' => '0'],
            ],
        ]);
        $asset = $acquisition->asset;
        $this->actingAs(User::findOrFail(1))->post(route('fixed-asset-acquisitions.submit-review', $acquisition->id))->assertRedirect();
        $this->actingAs(User::findOrFail(1))->post(route('fixed-asset-acquisitions.submit-approval', $acquisition->id))->assertRedirect();
        $this->actingAs(User::findOrFail(3))->post(route('fixed-asset-acquisitions.decide', $acquisition->id), ['decision' => 'approved', 'reason' => 'OK'])->assertRedirect();
        $this->actingAs(User::findOrFail(1))->post(route('fixed-asset-acquisitions.capitalize', $acquisition->id))->assertRedirect();

        $this->assertSame(3, \App\Models\FixedAssetDepreciationSchedule::where('asset_id', $asset->id)->count());

        $this->postPeriod('2026-11');
        $this->postPeriod('2026-12');
        $run = $this->postPeriod('2027-01');

        $this->assertSame('16666.66', (string) $run->total_amount);

        $posted = FixedAssetDepreciationEntry::where('asset_id', $asset->id)
            ->where('status', 'posted')
            ->sum('posted_amount');
        $this->assertSame('50000.00', number_format((float) $posted, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $asset->fresh()->current_book_value, 2, '.', ''));
        $this->assertSame('fully_depreciated', $asset->fresh()->status);

        // A fourth period has nothing left to post.
        $response = $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-depreciation.store'), ['period' => '2027-02']);
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    // ------------------------------------------------------------------
    // Cost-centre split
    // ------------------------------------------------------------------

    public function test_mid_period_transfer_splits_the_period_by_days(): void
    {
        $asset = $this->createCapitalizedAsset();
        $this->assertSame(1, (int) $asset->fresh()->cost_centre_id);

        // Transfer received on 2026-11-16: 15 days LOG (Nov 1–15),
        // 15 days ADM (Nov 16–30) → 50% / 50% of 783.33.
        $this->receiveTransferOn($asset, '2026-11-16', 2);
        $this->assertSame(2, (int) $asset->fresh()->cost_centre_id);

        $run = $this->postPeriod('2026-11');

        $journal = DB::table('journal_entries')->where('id', $run->journal_id)->first();
        $this->assertNotNull($journal);
        $debitItem = DB::table('journal_items')
            ->where('journal', $journal->id)
            ->where('debit', '>', 0)
            ->first();
        $this->assertNotNull($debitItem);

        $rows = JournalLineDimension::where('line_type', 'journal_item')
            ->where('line_id', (int) $debitItem->id)
            ->where('status', 'active')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $rows);
        $this->assertSame(1, (int) $rows[0]->cost_centre_id);
        $this->assertSame('50.000000', (string) $rows[0]->percentage);
        $this->assertSame('391.67', (string) $rows[0]->allocated_amount);
        $this->assertSame(2, (int) $rows[1]->cost_centre_id);
        $this->assertSame('50.000000', (string) $rows[1]->percentage);
        $this->assertSame('391.66', (string) $rows[1]->allocated_amount);
        $this->assertSame((int) $asset->id, (int) $rows[0]->asset_id);
        $this->assertSame((int) $asset->id, (int) $rows[1]->asset_id);

        // The entry records the period-end centre.
        $entry = FixedAssetDepreciationEntry::where('run_id', $run->id)->firstOrFail();
        $this->assertSame(2, (int) $entry->cost_centre_id);
    }

    // ------------------------------------------------------------------
    // Views + report + access
    // ------------------------------------------------------------------

    public function test_asset_show_page_schedule_shows_planned_vs_posted(): void
    {
        $asset = $this->createCapitalizedAsset();
        $this->postPeriod('2026-11');

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('fixed-assets.show', $asset->id));

        $response->assertOk();
        $response->assertSee('2026-11-01');
        $response->assertSee('783.3333');
        $response->assertSee('783.33');
        $response->assertSee('Posted');
        $response->assertSee('Planned');
    }

    public function test_monthly_report_shows_period_ytd_and_remaining(): void
    {
        $this->createCapitalizedAsset();
        $this->postPeriod('2026-11');

        $response = $this->actingAs(User::findOrFail(1))
            ->get(route('fixed-asset-depreciation.report', ['period' => '2026-11']));

        $response->assertOk();
        $response->assertSee('783.33');
        $response->assertSee('Year-to-Date Posted');
        $response->assertSee('Remaining Planned');
    }

    public function test_run_show_denied_for_another_company(): void
    {
        $this->createCapitalizedAsset();
        $run = $this->postPeriod('2026-11');

        $response = $this->actingAs(User::findOrFail(4))
            ->get(route('fixed-asset-depreciation.show', $run->id));
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_depreciation_indexes_load(): void
    {
        $this->createCapitalizedAsset();
        $this->postPeriod('2026-11');

        $this->actingAs(User::findOrFail(1))->get(route('fixed-asset-depreciation.index'))->assertOk();
        $this->actingAs(User::findOrFail(1))->get(route('fixed-asset-depreciation.create'))->assertOk();
        $this->actingAs(User::findOrFail(1))->get(route('fixed-asset-depreciation.report', ['period' => '2026-11']))->assertOk();
    }

    public function test_phase3_routes_resolve(): void
    {
        $this->assertTrue(Route::has('fixed-asset-depreciation.index'));
        $this->assertTrue(Route::has('fixed-asset-depreciation.create'));
        $this->assertTrue(Route::has('fixed-asset-depreciation.preview'));
        $this->assertTrue(Route::has('fixed-asset-depreciation.store'));
        $this->assertTrue(Route::has('fixed-asset-depreciation.report'));
        $this->assertTrue(Route::has('fixed-asset-depreciation.show'));
        $this->assertTrue(Route::has('fixed-asset-depreciation.submit-review'));
        $this->assertTrue(Route::has('fixed-asset-depreciation.submit-approval'));
        $this->assertTrue(Route::has('fixed-asset-depreciation.decide'));
        $this->assertTrue(Route::has('fixed-asset-depreciation.post'));
        $this->assertTrue(Route::has('fixed-asset-depreciation.cancel'));
    }

    protected function createTables(): void
    {
        Schema::create('branches', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('branch_user', function ($table) {
            $table->bigIncrements('id');
            $table->integer('branch_id');
            $table->integer('user_id');
            $table->timestamps();
        });
        Schema::create('settings', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->text('value')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
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
        Schema::create('roles', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('guard_name');
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
        Schema::create('model_has_roles', function ($table) {
            $table->integer('role_id');
            $table->string('model_type');
            $table->integer('model_id');
            $table->index(['model_id', 'model_type']);
        });
        Schema::create('role_has_permissions', function ($table) {
            $table->integer('permission_id');
            $table->integer('role_id');
            $table->index(['role_id', 'permission_id']);
        });
        Schema::create('plans', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('languages', function ($table) {
            $table->increments('id');
            $table->string('code')->nullable();
            $table->string('full_name')->nullable();
            $table->timestamps();
        });
        Schema::create('ch_messages', function ($table) {
            $table->increments('id');
            $table->integer('to_id')->nullable();
            $table->integer('seen')->default(0);
            $table->timestamps();
        });
        Schema::create('email_templates', function ($table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('from')->nullable();
            $table->string('slug')->nullable();
            $table->integer('created_by');
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
        // Minimal Cost Centre master (the real module's table).
        Schema::create('cost_centres', function ($table) {
            $table->bigIncrements('id');
            $table->integer('company_id');
            $table->integer('created_by');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('code', 50);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('manager_user_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->integer('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
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
        Schema::create('departments', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('employees', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('user_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('fixed_asset_assignments', function ($table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('custodian_id');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedBigInteger('cost_centre_id')->nullable();
            $table->unsignedBigInteger('previous_custodian_id')->nullable();
            $table->unsignedBigInteger('previous_location_id')->nullable();
            $table->unsignedBigInteger('previous_cost_centre_id')->nullable();
            $table->string('status', 30)->default('requested');
            $table->string('condition', 100)->nullable();
            $table->text('accessories')->nullable();
            $table->integer('requested_by');
            $table->integer('approved_by')->nullable();
            $table->integer('handed_over_by')->nullable();
            $table->integer('accepted_by')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('handed_over_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('refused_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('refusal_reason')->nullable();
            $table->text('return_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('fixed_asset_depreciation_runs', function ($table) {
            $table->id();
            $table->integer('created_by');
            $table->date('period');
            $table->string('status', 20)->default('calculated');
            $table->decimal('total_amount', 20, 4)->default('0');
            $table->integer('asset_count')->default(0);
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->integer('requested_by')->nullable();
            $table->integer('reviewed_by')->nullable();
            $table->integer('approved_by')->nullable();
            $table->integer('posted_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            // Plain index, NOT unique: a cancelled run must not
            // block a replacement run for the same period (mirrors
            // the 2026_11_07_230000 migration).
            $table->index(['created_by', 'period'], 'fadr_creator_period_idx');
            $table->index(['created_by', 'status'], 'fadr_creator_status_idx');
        });
        Schema::create('fixed_asset_depreciation_entries', function ($table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('run_id');
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('schedule_id');
            $table->date('period');
            $table->decimal('planned_amount', 20, 4);
            $table->decimal('posted_amount', 20, 4)->nullable();
            $table->unsignedBigInteger('cost_centre_id')->nullable();
            $table->string('status', 20)->default('calculated');
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'asset_id', 'period'], 'fade_asset_period_uq');
            $table->index(['run_id'], 'fade_run_idx');
            $table->index(['created_by', 'status'], 'fade_creator_status_idx');
        });
        // Minimal journal_line_dimensions + dimension audit events
        // (the real Cost Centre Phase 4 tables — the depreciation
        // posting tags expense lines through them).
        Schema::create('journal_line_dimensions', function ($table) {
            $table->id();
            $table->integer('company_id');
            $table->integer('created_by');
            $table->string('line_type', 20);
            $table->unsignedBigInteger('line_id');
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('cost_centre_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->decimal('allocated_amount', 18, 2);
            $table->decimal('percentage', 9, 6)->nullable();
            $table->string('currency_code', 10)->nullable();
            $table->string('source', 20)->default('manual');
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->string('reversal_reason')->nullable();
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
        Schema::create('fixed_asset_transfers', function ($table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('from_location_id')->nullable();
            $table->unsignedBigInteger('to_location_id')->nullable();
            $table->unsignedBigInteger('from_custodian_id')->nullable();
            $table->unsignedBigInteger('to_custodian_id')->nullable();
            $table->unsignedBigInteger('from_cost_centre_id')->nullable();
            $table->unsignedBigInteger('to_cost_centre_id')->nullable();
            $table->string('status', 30)->default('requested');
            $table->string('previous_status', 30)->nullable();
            $table->integer('requested_by');
            $table->integer('approved_by')->nullable();
            $table->integer('handed_over_by')->nullable();
            $table->integer('received_by')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('handed_over_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->date('effective_date')->nullable();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }
}
