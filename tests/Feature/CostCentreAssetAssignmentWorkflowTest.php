<?php

namespace Tests\Feature;

use App\Exceptions\CostCentreException;
use App\Exceptions\FixedAssetException;
use App\Models\AssetCostCentreAssignment;
use App\Models\FixedAsset;
use App\Models\FixedAssetAcquisition;
use App\Models\FixedAssetApproval;
use App\Models\FixedAssetDepreciationEntry;
use App\Models\FixedAssetDepreciationRun;
use App\Models\FixedAssetMaintenanceCost;
use App\Models\FixedAssetMaintenanceRequest;
use App\Models\JournalEntry;
use App\Models\JournalItem;
use App\Models\JournalLineDimension;
use App\Models\User;
use App\Services\Accounting\ChartAccountResolver;
use App\Services\Accounting\JournalEntryPoster;
use App\Services\AssetAssignmentService;
use App\Services\DimensionPostingService;
use App\Services\DimensionReportService;
use App\Services\FixedAssetAcquisitionService;
use App\Services\FixedAssetDepreciationService;
use App\Services\FixedAssetMaintenanceService;
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
 * Cost Centre Phase 2 — Fixed Assets integration (plan §5/§6/§7, §14 exit gate).
 *
 * Covers: assign a split generation (100.000000%) → supersede
 * (history preserved) → end; overlap refusal; the depreciation
 * engine splitting by the assignments in force for the period
 * (spec example: MZN 3,000,000 / 60 months = 50,000/month at
 * 70% Logistics / 30% Administration = 35,000 / 15,000);
 * mid-period move day-split; mid-life move keeps old
 * depreciation on the old centre; fallback to the asset's own
 * centre when no split rows exist; depreciation-by-asset-and-
 * centre report equals the posted dimension rows; maintenance-
 * by-asset-and-centre report groups by the centre in force on
 * the cost date; tenant isolation; screens render + deny.
 *
 * Users: 1 = Company (all permissions), 2 = Operator (manage
 * fixed assets only), 3 = Approver (manage + approve
 * acquisitions + approve transfers + post depreciation),
 * 4 = Other Company (all permissions), 5 = Custodian user
 * (no fixed-asset permission, linked to employee Ana),
 * 6 = No Permission, 7 = Finance Approver (approve
 * acquisitions — the second level for assets above the
 * MZN 100,000 threshold). "Today" is frozen at 2026-11-07.
 * Employees: 1 = Ana (user 5), 2 = Bruno (user 3).
 * Locations: 1 = WH1, 2 = WH2. Cost centres: 1 = LOG,
 * 2 = ADM. Chart: 1 = 321 asset, 2 = 328 accumulated
 * depreciation, 3 = 421 AP, 4 = 471 clearing, 5 = 631
 * depreciation expense, 6 = 6226 maintenance expense.
 *
 * The capitalized asset from createCapitalizedAsset() costs
 * 47,000 (50,000 − 8,000 recoverable VAT + 5,000 transport),
 * 5-year life → 60 monthly rows of 783.3333, first period
 * 2026-11-01. A November run therefore posts 783.33.
 * createBigAsset() costs 3,000,000, 5-year life → 60 monthly
 * rows of 50,000.00.
 */
class CostCentreAssetAssignmentWorkflowTest extends TestCase
{
    protected FixedAssetService $service;
    protected FixedAssetNumberingService $numbering;
    protected FixedAssetAcquisitionService $acquisitions;
    protected FixedAssetDepreciationService $depreciation;
    protected AssetAssignmentService $assignments;
    protected FixedAssetMaintenanceService $maintenance;
    protected DimensionReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-07 09:00:00'));
        $this->createTables();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['manage transaction', 'manage fixed assets', 'create fixed assets', 'edit fixed assets', 'delete fixed assets', 'approve fixed asset acquisitions', 'approve fixed asset transfers', 'approve fixed asset disposals', 'post fixed asset depreciation', 'manage fixed asset categories', 'manage fixed asset settings', 'view fixed asset audit trail', 'view cost centres', 'manage asset cost centre assignments', 'view cost centre reports'] as $name) {
            Permission::create(['name' => $name, 'guard_name' => 'web']);
        }

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Hoya Hoya', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'Operator', 'email' => 'operator@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 3, 'name' => 'Approver', 'email' => 'approver@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Uthara', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
            ['id' => 5, 'name' => 'Custodian Ana', 'email' => 'ana@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 6, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 7, 'name' => 'Finance Approver', 'email' => 'finance@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
        ]);

        $all = ['manage transaction', 'manage fixed assets', 'create fixed assets', 'edit fixed assets', 'delete fixed assets', 'approve fixed asset acquisitions', 'approve fixed asset transfers', 'approve fixed asset disposals', 'post fixed asset depreciation', 'manage fixed asset categories', 'manage fixed asset settings', 'view fixed asset audit trail', 'view cost centres', 'manage asset cost centre assignments', 'view cost centre reports'];
        User::findOrFail(1)->givePermissionTo($all);
        User::findOrFail(2)->givePermissionTo(['manage transaction', 'manage fixed assets']);
        User::findOrFail(3)->givePermissionTo(['manage transaction', 'manage fixed assets', 'approve fixed asset acquisitions', 'approve fixed asset transfers', 'post fixed asset depreciation']);
        User::findOrFail(4)->givePermissionTo($all);
        User::findOrFail(5)->givePermissionTo(['manage transaction']);
        User::findOrFail(6)->givePermissionTo(['manage transaction']);
        User::findOrFail(7)->givePermissionTo(['manage transaction', 'manage fixed assets', 'approve fixed asset acquisitions']);

        DB::table('branches')->insert([
            ['id' => 1, 'name' => 'Main Branch', 'created_by' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('chart_of_accounts')->insert([
            ['id' => 1, 'name' => 'Tangible fixed assets', 'code' => 321, 'type' => 3, 'sub_type' => 2, 'is_enabled' => 1, 'created_by' => 1],
            ['id' => 2, 'name' => 'Accumulated depreciation', 'code' => 328, 'type' => 3, 'sub_type' => 2, 'is_enabled' => 1, 'created_by' => 1],
            ['id' => 3, 'name' => 'Accounts payable', 'code' => 421, 'type' => 4, 'sub_type' => 1, 'is_enabled' => 1, 'created_by' => 1],
            ['id' => 4, 'name' => 'Asset clearing account', 'code' => 471, 'type' => 4, 'sub_type' => 1, 'is_enabled' => 1, 'created_by' => 1],
            ['id' => 5, 'name' => 'Depreciation expense', 'code' => 631, 'type' => 6, 'sub_type' => 1, 'is_enabled' => 1, 'created_by' => 1],
            ['id' => 6, 'name' => 'Maintenance expense', 'code' => 6226, 'type' => 6, 'sub_type' => 1, 'is_enabled' => 1, 'created_by' => 1],
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
        $this->assignments = new AssetAssignmentService();
        $this->depreciation = new FixedAssetDepreciationService(
            $this->service,
            $this->acquisitions,
            new JournalEntryPoster(),
            new ChartAccountResolver(),
            new DimensionPostingService(),
            $this->assignments,
        );
        $this->maintenance = new FixedAssetMaintenanceService(
            $this->service,
            $this->acquisitions,
            new JournalEntryPoster(),
            new ChartAccountResolver(),
        );
        $this->reports = new DimensionReportService(
            new DimensionPostingService(),
            $this->assignments,
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

        foreach (['asset_cost_centre_assignments', 'fixed_asset_maintenance_requests', 'fixed_asset_maintenance_costs', 'purchase_delivery_note_items', 'purchase_delivery_notes', 'bill_products', 'bills', 'purchase_products', 'purchases', 'product_services', 'venders', 'transaction_lines', 'bank_accounts', 'journal_items', 'journal_entries', 'fixed_asset_accounting_entries', 'fixed_asset_assignments', 'fixed_asset_transfers', 'fixed_asset_depreciation_runs', 'fixed_asset_depreciation_entries', 'journal_line_dimensions', 'dimension_audit_events', 'fixed_asset_depreciation_schedules', 'fixed_asset_capitalizations', 'fixed_asset_acquisition_costs', 'departments', 'employees', 'fixed_asset_acquisitions', 'fixed_asset_audit_events', 'fixed_asset_status_history', 'fixed_asset_approvals', 'fixed_asset_documents', 'fixed_assets', 'fixed_asset_locations', 'fixed_asset_categories', 'cost_centres', 'chart_of_accounts', 'email_templates', 'ch_messages', 'languages', 'plans', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'permissions', 'roles', 'users', 'settings', 'branch_user', 'branches'] as $table) {
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

    // ------------------------------------------------------------------
    // Phase 2 helpers
    // ------------------------------------------------------------------

    /**
     * Full Phase 1 flow for the spec-example asset: 3,000,000, 5-year
     * life → 60 monthly rows of 50,000.00. Above the MZN 100,000
     * threshold, so TWO approval levels: user 3 decides level 1,
     * user 7 decides level 2 (the decide endpoint decides the first
     * pending approval).
     */
    protected function createBigAsset(): FixedAsset
    {
        $acquisition = $this->createDraftViaForm(1, [
            'components' => [
                ['component_type' => 'purchase_price', 'gross_amount' => '3000000', 'vat_amount' => '0', 'vat_recoverable' => '0'],
            ],
        ]);
        $asset = $acquisition->asset;

        $this->actingAs(User::findOrFail(1))->post(route('fixed-asset-acquisitions.submit-review', $acquisition->id))->assertRedirect();
        $this->actingAs(User::findOrFail(1))->post(route('fixed-asset-acquisitions.submit-approval', $acquisition->id))->assertRedirect();
        $this->actingAs(User::findOrFail(3))->post(route('fixed-asset-acquisitions.decide', $acquisition->id), ['decision' => 'approved', 'reason' => 'OK'])->assertRedirect();
        $this->actingAs(User::findOrFail(7))->post(route('fixed-asset-acquisitions.decide', $acquisition->id), ['decision' => 'approved', 'reason' => 'OK'])->assertRedirect();
        $this->actingAs(User::findOrFail(1))->post(route('fixed-asset-acquisitions.capitalize', $acquisition->id))->assertRedirect();

        $this->assertSame('available', $asset->fresh()->status);
        $this->assertSame(60, \App\Models\FixedAssetDepreciationSchedule::where('asset_id', $asset->id)->count());

        return $asset->fresh();
    }

    /** Posted dimension rows for one asset in one month, keyed by centre code. */
    protected function dimensionRowsForAsset(FixedAsset $asset, string $periodYm): array
    {
        $period = Carbon::parse($periodYm.'-01');
        $rows = DB::table('journal_line_dimensions as d')
            ->join('journal_items as ji', 'ji.id', '=', 'd.line_id')
            ->join('journal_entries as je', 'je.id', '=', 'ji.journal')
            ->join('cost_centres as cc', 'cc.id', '=', 'd.cost_centre_id')
            ->where('d.created_by', 1)
            ->where('d.asset_id', $asset->id)
            ->where('d.status', JournalLineDimension::STATUS_ACTIVE)
            ->where('d.line_type', JournalLineDimension::LINE_JOURNAL_ITEM)
            ->whereDate('je.date', '>=', $period->copy()->startOfMonth()->toDateString())
            ->whereDate('je.date', '<=', $period->copy()->endOfMonth()->toDateString())
            ->select('cc.code', 'd.allocated_amount', 'd.percentage')
            ->orderBy('cc.code')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            // SQLite returns decimals without trailing zeros
            // ('35000' not '35000.00') — normalise (P4 lesson).
            $out[$row->code] = [
                'amount' => number_format((float) $row->allocated_amount, 2, '.', ''),
                'percentage' => number_format((float) $row->percentage, 6, '.', ''),
            ];
        }

        return $out;
    }

    protected function requestMaintenanceFor(FixedAsset $asset, int $requesterId = 1, array $overrides = []): FixedAssetMaintenanceRequest
    {
        return $this->maintenance->requestMaintenance($asset->fresh(), array_merge([
            'type' => FixedAssetMaintenanceRequest::TYPE_CORRECTIVE,
            'priority' => FixedAssetMaintenanceRequest::PRIORITY_HIGH,
            'description' => 'Screen flickers and goes dark',
            'expected_cost' => '1500.0000',
        ], $overrides), 1, $requesterId);
    }

    protected function approveMaintenance(FixedAssetMaintenanceRequest $request, int $approverId = 2): FixedAssetMaintenanceRequest
    {
        $approval = FixedAssetApproval::where('transaction_type', FixedAssetApproval::TYPE_MAINTENANCE)
            ->where('transaction_id', $request->id)
            ->where('decision', FixedAssetApproval::DECISION_PENDING)
            ->firstOrFail();
        $this->maintenance->decideMaintenanceApproval($approval, $approverId, FixedAssetApproval::DECISION_APPROVED);

        return $request->fresh();
    }

    // ------------------------------------------------------------------
    // Phase 2 — assignment service
    // ------------------------------------------------------------------

    public function test_assign_split_generation_and_supersede_preserves_history(): void
    {
        $asset = $this->createCapitalizedAsset();

        $rows = $this->assignments->assign($asset->fresh(), [
            ['cost_centre_id' => 1, 'percentage' => '70'],
            ['cost_centre_id' => 2, 'percentage' => '30'],
        ], Carbon::parse('2026-11-01'), 1, 1);

        $this->assertCount(2, $rows);
        $this->assertSame('2026-11-01', $rows[0]->valid_from->format('Y-m-d'));
        $this->assertNull($rows[0]->valid_to);

        // The split in force today is the new generation.
        $current = $this->assignments->currentSplit($asset->fresh(), 1, Carbon::parse('2026-11-07'));
        $this->assertCount(2, $current);
        $this->assertSame('70.000000', $current[0]['percentage']);
        $this->assertSame('30.000000', $current[1]['percentage']);

        // Supersede from 2026-12-01: the old rows keep their valid_to,
        // the new generation is open, and the history has 4 rows.
        $this->assignments->assign($asset->fresh(), [
            ['cost_centre_id' => 2, 'percentage' => '100'],
        ], Carbon::parse('2026-12-01'), 1, 1);

        $history = $this->assignments->history($asset->fresh(), 1);
        $this->assertCount(3, $history);
        // valid_from is a Carbon date — compare formatted, not to a string.
        $old = $history->filter(fn ($row) => $row->valid_from->format('Y-m-d') === '2026-11-01')->values();
        $this->assertSame('2026-11-30', $old[0]->valid_to->format('Y-m-d'));
        $this->assertSame('2026-11-30', $old[1]->valid_to->format('Y-m-d'));
        $new = $history->filter(fn ($row) => $row->valid_from->format('Y-m-d') === '2026-12-01')->values();
        $this->assertNull($new[0]->valid_to);

        // Days before the supersede still see the old split.
        $nov = $this->assignments->activeSplitsOn($asset->id, 1, Carbon::parse('2026-11-15'));
        $this->assertCount(2, $nov);
        $dec = $this->assignments->activeSplitsOn($asset->id, 1, Carbon::parse('2026-12-15'));
        $this->assertCount(1, $dec);
        $this->assertSame(2, $dec[0]['cost_centre_id']);
    }

    public function test_assign_refuses_overlap_over_100_percent(): void
    {
        $asset = $this->createCapitalizedAsset();

        $this->expectException(CostCentreException::class);
        $this->assignments->assign($asset->fresh(), [
            ['cost_centre_id' => 1, 'percentage' => '70'],
            ['cost_centre_id' => 2, 'percentage' => '40'],
        ], Carbon::parse('2026-11-01'), 1, 1);
    }

    public function test_assign_refuses_99_percent_and_duplicate_centre(): void
    {
        $asset = $this->createCapitalizedAsset();

        try {
            $this->assignments->assign($asset->fresh(), [
                ['cost_centre_id' => 1, 'percentage' => '99'],
            ], Carbon::parse('2026-11-01'), 1, 1);
            $this->fail('A 99% split must be refused.');
        } catch (CostCentreException $e) {
            $this->assertStringContainsString('100%', $e->getMessage());
        }

        try {
            $this->assignments->assign($asset->fresh(), [
                ['cost_centre_id' => 1, 'percentage' => '50'],
                ['cost_centre_id' => 1, 'percentage' => '50'],
            ], Carbon::parse('2026-11-01'), 1, 1);
            $this->fail('A centre twice in one split must be refused.');
        } catch (CostCentreException $e) {
            $this->assertStringContainsString('only appear once', $e->getMessage());
        }
    }

    public function test_assign_refuses_same_date_start_over_open_generation(): void
    {
        $asset = $this->createCapitalizedAsset();
        $this->assignments->assign($asset->fresh(), [
            ['cost_centre_id' => 1, 'percentage' => '100'],
        ], Carbon::parse('2026-11-01'), 1, 1);

        try {
            $this->assignments->assign($asset->fresh(), [
                ['cost_centre_id' => 2, 'percentage' => '100'],
            ], Carbon::parse('2026-11-01'), 1, 1);
            $this->fail('A second assignment on the same start date must be refused.');
        } catch (CostCentreException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        // An earlier start date is refused too (history is immutable).
        try {
            $this->assignments->assign($asset->fresh(), [
                ['cost_centre_id' => 2, 'percentage' => '100'],
            ], Carbon::parse('2026-10-15'), 1, 1);
            $this->fail('An earlier start date must be refused.');
        } catch (CostCentreException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }
    }

    public function test_end_assignment_reverts_to_asset_centre(): void
    {
        $asset = $this->createCapitalizedAsset();
        $this->assignments->assign($asset->fresh(), [
            ['cost_centre_id' => 2, 'percentage' => '100'],
        ], Carbon::parse('2026-11-01'), 1, 1);

        $closed = $this->assignments->endAssignment($asset->fresh(), Carbon::parse('2026-11-30'), 1, 1);
        $this->assertSame(1, $closed);
        $this->assertSame([], $this->assignments->activeSplitsOn($asset->id, 1, Carbon::parse('2026-12-15')));

        // Depreciation after the end falls back to the asset's own
        // centre (LOG, cost_centre_id 1 from the form).
        $split = $this->depreciation->costCentreSplitForPeriod(
            $asset->fresh(), Carbon::parse('2026-12-01'), Carbon::parse('2026-12-31'), 1
        );
        $this->assertCount(1, $split);
        $this->assertSame(1, $split[0]['cost_centre_id']);
        $this->assertSame('100.000000', $split[0]['percentage']);
    }

    public function test_assignment_writes_audit_events(): void
    {
        $asset = $this->createCapitalizedAsset();
        $this->assignments->assign($asset->fresh(), [
            ['cost_centre_id' => 1, 'percentage' => '70'],
            ['cost_centre_id' => 2, 'percentage' => '30'],
        ], Carbon::parse('2026-11-01'), 1, 1);

        $assigned = DB::table('dimension_audit_events')
            ->where('entity_type', 'asset_cost_centre_assignment')
            ->where('action', 'assigned')
            ->count();
        $this->assertSame(2, $assigned);

        $this->assignments->assign($asset->fresh(), [
            ['cost_centre_id' => 2, 'percentage' => '100'],
        ], Carbon::parse('2026-12-01'), 1, 1);

        $superseded = DB::table('dimension_audit_events')
            ->where('entity_type', 'asset_cost_centre_assignment')
            ->where('action', 'superseded')
            ->count();
        $this->assertSame(2, $superseded);
    }

    public function test_disposed_asset_refuses_assignment(): void
    {
        $asset = $this->createCapitalizedAsset();
        DB::table('fixed_assets')->where('id', $asset->id)->update(['status' => 'disposed']);

        $this->expectException(CostCentreException::class);
        $this->assignments->assign($asset->fresh(), [
            ['cost_centre_id' => 1, 'percentage' => '100'],
        ], Carbon::parse('2026-11-01'), 1, 1);
    }

    public function test_assign_via_http_and_end_via_http(): void
    {
        $asset = $this->createCapitalizedAsset();

        $this->actingAs(User::findOrFail(1))->post(route('asset-cost-centre-assignments.store'), [
            'asset_id' => $asset->id,
            'valid_from' => '2026-11-01',
            'splits' => [
                ['cost_centre_id' => 1, 'percentage' => '70'],
                ['cost_centre_id' => 2, 'percentage' => '30'],
            ],
        ])->assertRedirect(route('asset-cost-centre-assignments.show', $asset->id));
        $this->assertSame(2, AssetCostCentreAssignment::where('asset_id', $asset->id)->count());

        // 70 + 40 = 110% is refused with an error flash.
        $this->actingAs(User::findOrFail(1))->post(route('asset-cost-centre-assignments.store'), [
            'asset_id' => $asset->id,
            'valid_from' => '2026-12-01',
            'splits' => [
                ['cost_centre_id' => 1, 'percentage' => '70'],
                ['cost_centre_id' => 2, 'percentage' => '40'],
            ],
        ])->assertRedirect()->assertSessionHas('error');
        $this->assertSame(2, AssetCostCentreAssignment::where('asset_id', $asset->id)->count());

        $this->actingAs(User::findOrFail(1))->post(route('asset-cost-centre-assignments.end', $asset->id), [
            'valid_to' => '2026-11-30',
        ])->assertRedirect(route('asset-cost-centre-assignments.show', $asset->id));
        $this->assertSame(0, AssetCostCentreAssignment::where('asset_id', $asset->id)->whereNull('valid_to')->count());
    }

    // ------------------------------------------------------------------
    // Phase 2 — depreciation engine integration
    // ------------------------------------------------------------------

    /** Spec example (plan §14): 3,000,000 / 60 = 50,000 at 70/30 = 35,000 / 15,000. */
    public function test_spec_example_split_35k_15k_for_one_month(): void
    {
        $asset = $this->createBigAsset();
        $this->assertSame(0, bccomp((string) $asset->fresh()->capitalized_cost, '3000000.0000', 4));

        $this->assignments->assign($asset->fresh(), [
            ['cost_centre_id' => 1, 'percentage' => '70'],
            ['cost_centre_id' => 2, 'percentage' => '30'],
        ], Carbon::parse('2026-11-01'), 1, 1);

        $this->postPeriod('2026-11');

        $rows = $this->dimensionRowsForAsset($asset->fresh(), '2026-11');
        $this->assertCount(2, $rows);
        $this->assertSame('35000.00', $rows['LOG']['amount']);
        $this->assertSame('70.000000', $rows['LOG']['percentage']);
        $this->assertSame('15000.00', $rows['ADM']['amount']);
        $this->assertSame('30.000000', $rows['ADM']['percentage']);

        // The journal itself is unchanged: one balanced journal,
        // Dr 631 / Cr 328, 50,000 total.
        $entry = FixedAssetDepreciationEntry::where('asset_id', $asset->id)->where('status', 'posted')->firstOrFail();
        $this->assertSame(0, bccomp((string) $entry->posted_amount, '50000.0000', 4));
        // Period-end centre is the largest share: LOG.
        $this->assertSame(1, (int) $entry->cost_centre_id);
    }

    /** Mid-period move (D4): 100% LOG Nov 1 → 100% ADM Nov 16 = 15/30 each. */
    public function test_mid_period_move_splits_by_days(): void
    {
        $asset = $this->createCapitalizedAsset();

        $this->assignments->assign($asset->fresh(), [
            ['cost_centre_id' => 2, 'percentage' => '100'],
        ], Carbon::parse('2026-11-16'), 1, 1);

        $this->postPeriod('2026-11');

        $rows = $this->dimensionRowsForAsset($asset->fresh(), '2026-11');
        $this->assertCount(2, $rows);
        // 783.33 for the month, 15 of 30 days each side.
        $this->assertSame('50.000000', $rows['LOG']['percentage']);
        $this->assertSame('50.000000', $rows['ADM']['percentage']);
        $this->assertSame('391.67', $rows['LOG']['amount']);
        $this->assertSame('391.66', $rows['ADM']['amount']);
    }

    /** Mid-life move: November stays on LOG, December posts to ADM. */
    public function test_mid_life_move_keeps_old_depreciation_on_old_centre(): void
    {
        $asset = $this->createCapitalizedAsset();
        $this->postPeriod('2026-11');

        $nov = $this->dimensionRowsForAsset($asset->fresh(), '2026-11');
        $this->assertCount(1, $nov);
        $this->assertSame('783.33', $nov['LOG']['amount']);

        $this->assignments->assign($asset->fresh(), [
            ['cost_centre_id' => 2, 'percentage' => '100'],
        ], Carbon::parse('2026-12-01'), 1, 1);
        $this->postPeriod('2026-12');

        $dec = $this->dimensionRowsForAsset($asset->fresh(), '2026-12');
        $this->assertCount(1, $dec);
        $this->assertSame('783.33', $dec['ADM']['amount']);

        // November is untouched by the December move.
        $novAgain = $this->dimensionRowsForAsset($asset->fresh(), '2026-11');
        $this->assertSame('783.33', $novAgain['LOG']['amount']);
    }

    /** No split rows: the asset's own centre, exactly as before (regression guard). */
    public function test_no_split_rows_falls_back_to_asset_centre(): void
    {
        $asset = $this->createCapitalizedAsset();
        $this->postPeriod('2026-11');

        $rows = $this->dimensionRowsForAsset($asset->fresh(), '2026-11');
        $this->assertCount(1, $rows);
        $this->assertSame('783.33', $rows['LOG']['amount']);
        $this->assertSame('100.000000', $rows['LOG']['percentage']);
    }

    /** A split assignment plus a mid-period transfer: split wins where it covers, transfer where it does not. */
    public function test_split_assignment_and_transfer_combine(): void
    {
        $asset = $this->createCapitalizedAsset();

        // Split covers Nov 1–15 only (70/30); the transfer moves the
        // asset master to ADM on Nov 16, so Nov 16–30 falls back to
        // the single-centre timeline (ADM 100%).
        $this->assignments->assign($asset->fresh(), [
            ['cost_centre_id' => 1, 'percentage' => '70'],
            ['cost_centre_id' => 2, 'percentage' => '30'],
        ], Carbon::parse('2026-11-01'), 1, 1);
        $this->assignments->endAssignment($asset->fresh(), Carbon::parse('2026-11-15'), 1, 1);
        $this->receiveTransferOn($asset->fresh(), '2026-11-16', 2);

        $this->postPeriod('2026-11');

        $rows = $this->dimensionRowsForAsset($asset->fresh(), '2026-11');
        $this->assertCount(2, $rows);
        // Nov 1–15: 70/30 of 391.67 (15 days) = 274.17 / 117.50;
        // Nov 16–30: ADM 391.66. Totals: LOG 274.17, ADM 509.16.
        $this->assertSame('274.17', $rows['LOG']['amount']);
        $this->assertSame('509.16', $rows['ADM']['amount']);
    }

    // ------------------------------------------------------------------
    // Phase 2 — reports
    // ------------------------------------------------------------------

    public function test_depreciation_report_equals_posted_dimensions(): void
    {
        $asset = $this->createBigAsset();
        $this->assignments->assign($asset->fresh(), [
            ['cost_centre_id' => 1, 'percentage' => '70'],
            ['cost_centre_id' => 2, 'percentage' => '30'],
        ], Carbon::parse('2026-11-01'), 1, 1);
        $this->postPeriod('2026-11');

        $report = $this->reports->depreciationByAssetAndCentre(1, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
        $this->assertCount(2, $report['rows']);
        $this->assertSame('50000.00', $report['total']);
        $this->assertSame('50000.00', $report['dimension_total']);
        $this->assertSame('50000.00', $report['posted_total']);
        $this->assertTrue($report['matches']);

        $byCode = [];
        foreach ($report['rows'] as $row) {
            $byCode[$row['cost_centre_code']] = $row['total'];
        }
        $this->assertSame('35000.00', $byCode['LOG']);
        $this->assertSame('15000.00', $byCode['ADM']);

        // The report total is the sum of the dimension rows the
        // engine wrote — queried directly, not through the service.
        $direct = $this->dimensionRowsForAsset($asset->fresh(), '2026-11');
        $directTotal = bcadd($direct['LOG']['amount'], $direct['ADM']['amount'], 2);
        $this->assertSame($directTotal, $report['total']);

        // Centre filter: only ADM rows, same reconciliation.
        $filtered = $this->reports->depreciationByAssetAndCentre(1, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'), 2);
        $this->assertCount(1, $filtered['rows']);
        $this->assertSame('15000.00', $filtered['total']);
        $this->assertTrue($filtered['matches']);
    }

    public function test_maintenance_report_groups_by_centre_in_force(): void
    {
        $asset = $this->createCapitalizedAsset();
        $this->assignments->assign($asset->fresh(), [
            ['cost_centre_id' => 1, 'percentage' => '70'],
            ['cost_centre_id' => 2, 'percentage' => '30'],
        ], Carbon::parse('2026-11-01'), 1, 1);

        $request = $this->requestMaintenanceFor($asset->fresh());
        $this->approveMaintenance($request);
        $this->maintenance->startMaintenance($request->fresh(), 1, 1);
        $this->maintenance->recordCost($request->fresh(), [
            'cost_type' => 'parts', 'amount' => '1000.0000', 'description' => 'Screen panel', 'vendor' => 'Acme Repairs',
        ], 1, 1);

        $report = $this->reports->maintenanceByAssetAndCentre(1, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
        $this->assertCount(2, $report['rows']);
        $this->assertSame('1000.00', $report['total']);
        $this->assertSame('1000.00', $report['journal_total']);
        $this->assertTrue($report['matches']);

        $byCode = [];
        foreach ($report['rows'] as $row) {
            $byCode[$row['cost_centre_code']] = $row['total'];
        }
        $this->assertSame('700.00', $byCode['LOG']);
        $this->assertSame('300.00', $byCode['ADM']);
    }

    public function test_maintenance_report_unassigned_when_asset_has_no_centre(): void
    {
        $asset = $this->createCapitalizedAsset();
        DB::table('fixed_assets')->where('id', $asset->id)->update(['cost_centre_id' => null]);

        $request = $this->requestMaintenanceFor($asset->fresh());
        $this->approveMaintenance($request);
        $this->maintenance->startMaintenance($request->fresh(), 1, 1);
        $this->maintenance->recordCost($request->fresh(), [
            'cost_type' => 'parts', 'amount' => '1000.0000', 'description' => 'Screen panel',
        ], 1, 1);

        $report = $this->reports->maintenanceByAssetAndCentre(1, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
        $this->assertCount(1, $report['rows']);
        $this->assertNull($report['rows'][0]['cost_centre_id']);
        $this->assertSame('1000.00', $report['total']);
        $this->assertSame('1000.00', $report['journal_total']);
        $this->assertTrue($report['matches']);
    }

    // ------------------------------------------------------------------
    // Phase 2 — tenant isolation, screens, routes
    // ------------------------------------------------------------------

    public function test_tenant_isolation_second_company_sees_nothing(): void
    {
        $asset = $this->createBigAsset();
        $this->assignments->assign($asset->fresh(), [
            ['cost_centre_id' => 1, 'percentage' => '70'],
            ['cost_centre_id' => 2, 'percentage' => '30'],
        ], Carbon::parse('2026-11-01'), 1, 1);
        $this->postPeriod('2026-11');

        // Company 4 (creatorId 4) sees nothing in either report.
        $dep = $this->reports->depreciationByAssetAndCentre(4, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
        $this->assertSame([], $dep['rows']);
        $this->assertSame('0.00', $dep['total']);
        $this->assertSame('0.00', $dep['posted_total']);
        $this->assertTrue($dep['matches']);

        $mnt = $this->reports->maintenanceByAssetAndCentre(4, Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30'));
        $this->assertSame([], $mnt['rows']);
        $this->assertSame('0.00', $mnt['total']);

        // And the assignment history of company 1's asset refuses
        // company 4 outright.
        try {
            $this->assignments->history($asset->fresh(), 4);
            $this->fail('Company 4 must not read company 1 assignment history.');
        } catch (FixedAssetException $e) {
            $this->assertStringContainsString('not found', $e->getMessage());
        }

        // The assignments index as user 4 shows no rows.
        $this->actingAs(User::findOrFail(4))->get(route('asset-cost-centre-assignments.index'))
            ->assertOk()->assertSee(__('No assignments yet.'));
    }

    public function test_screens_render_and_deny(): void
    {
        $asset = $this->createCapitalizedAsset();
        $this->assignments->assign($asset->fresh(), [
            ['cost_centre_id' => 1, 'percentage' => '70'],
            ['cost_centre_id' => 2, 'percentage' => '30'],
        ], Carbon::parse('2026-11-01'), 1, 1);

        $this->actingAs(User::findOrFail(1))->get(route('asset-cost-centre-assignments.index'))
            ->assertOk()->assertSee(__('Asset Assignments'));
        $this->actingAs(User::findOrFail(1))->get(route('asset-cost-centre-assignments.create'))
            ->assertOk()->assertSee(__('New Asset Assignment'));
        $this->actingAs(User::findOrFail(1))->get(route('asset-cost-centre-assignments.show', $asset->id))
            ->assertOk()->assertSee($asset->code);
        $this->actingAs(User::findOrFail(1))->get(route('fixed-assets.show', $asset->id))
            ->assertOk()->assertSee(__('Cost Centre Allocation'))->assertSee('70.00%');
        $this->actingAs(User::findOrFail(1))->get(route('cost-centre-reports.depreciation'))
            ->assertOk()->assertSee(__('Depreciation by Asset'));
        $this->actingAs(User::findOrFail(1))->get(route('cost-centre-reports.maintenance'))
            ->assertOk()->assertSee(__('Maintenance by Asset'));

        // User 6 has no permission: every screen redirects back.
        $this->actingAs(User::findOrFail(6))->get(route('asset-cost-centre-assignments.index'))->assertRedirect();
        $this->actingAs(User::findOrFail(6))->get(route('asset-cost-centre-assignments.create'))->assertRedirect();
        $this->actingAs(User::findOrFail(6))->get(route('asset-cost-centre-assignments.show', $asset->id))->assertRedirect();
        $this->actingAs(User::findOrFail(6))->get(route('cost-centre-reports.depreciation'))->assertRedirect();
        $this->actingAs(User::findOrFail(6))->get(route('cost-centre-reports.maintenance'))->assertRedirect();

        // Other company cannot open company 1's assignment history.
        $this->actingAs(User::findOrFail(4))->get(route('asset-cost-centre-assignments.show', $asset->id))->assertRedirect();
    }

    public function test_routes_resolve(): void
    {
        $this->assertTrue(Route::has('asset-cost-centre-assignments.index'));
        $this->assertTrue(Route::has('asset-cost-centre-assignments.create'));
        $this->assertTrue(Route::has('asset-cost-centre-assignments.store'));
        $this->assertTrue(Route::has('asset-cost-centre-assignments.show'));
        $this->assertTrue(Route::has('asset-cost-centre-assignments.end'));
        $this->assertTrue(Route::has('cost-centre-reports.depreciation'));
        $this->assertTrue(Route::has('cost-centre-reports.maintenance'));
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
        // Cost Centre Phase 2: effective-dated split assignments
        // (mirrors the 2026_11_07_270000 migration).
        Schema::create('asset_cost_centre_assignments', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('asset_id');
            $table->integer('company_id');
            $table->unsignedBigInteger('cost_centre_id');
            $table->decimal('percentage', 9, 6);
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->integer('created_by');
            $table->timestamps();
            $table->index(['asset_id'], 'acca_asset_idx');
            $table->index(['created_by'], 'acca_creator_idx');
            $table->index(['cost_centre_id'], 'acca_centre_idx');
            $table->index(['asset_id', 'valid_from'], 'acca_asset_from_idx');
        });
        // Phase 4 maintenance tables (for the maintenance-by-asset
        // report test): request + cost rows only.
        Schema::create('fixed_asset_maintenance_requests', function ($table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            $table->string('type', 20)->default('corrective');
            $table->string('priority', 20)->default('medium');
            $table->text('description');
            $table->decimal('expected_cost', 20, 4)->nullable();
            $table->string('status', 30)->default('requested');
            $table->string('previous_status', 30)->nullable();
            $table->integer('requested_by');
            $table->integer('approved_by')->nullable();
            $table->integer('started_by')->nullable();
            $table->integer('completed_by')->nullable();
            $table->integer('verified_by')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('completion_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('fixed_asset_maintenance_costs', function ($table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('maintenance_request_id');
            $table->unsignedBigInteger('asset_id');
            $table->string('cost_type', 20)->default('parts');
            $table->text('description')->nullable();
            $table->decimal('amount', 20, 4);
            $table->string('vendor', 255)->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->integer('recorded_by');
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();
            $table->index(['maintenance_request_id'], 'famc_request_idx');
            $table->index(['asset_id'], 'famc_asset_idx');
        });
    }
}
