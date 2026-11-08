<?php

namespace Tests\Feature\FixedAssets;

use App\Exceptions\FixedAssetException;
use App\Models\FixedAsset;
use App\Models\FixedAssetAcquisition;
use App\Models\FixedAssetApproval;
use App\Models\FixedAssetAccountingEntry;
use App\Models\FixedAssetAssignment;
use App\Models\FixedAssetDepreciationEntry;
use App\Models\FixedAssetDepreciationRun;
use App\Models\FixedAssetDepreciationSchedule;
use App\Models\FixedAssetDisposal;
use App\Models\FixedAssetLocation;
use App\Models\FixedAssetMaintenanceCost;
use App\Models\FixedAssetMaintenanceRequest;
use App\Models\FixedAssetPhysicalVerification;
use App\Models\FixedAssetVerificationItem;
use App\Models\JournalEntry;
use App\Models\JournalItem;
use App\Models\JournalLineDimension;
use App\Models\User;
use App\Services\Accounting\ChartAccountResolver;
use App\Services\Accounting\JournalEntryPoster;
use App\Services\FixedAssetAcquisitionService;
use App\Services\FixedAssetAssignmentService;
use App\Services\FixedAssetDepreciationService;
use App\Services\FixedAssetDisposalService;
use App\Services\FixedAssetTransferService;
use App\Services\FixedAssetMaintenanceService;
use App\Services\FixedAssetVerificationService;
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
 * Fixed Assets Phase 5 — Disposal, service + HTTP level
 * (plan §5, §10; spec §16, §17).
 *
 * Covers: disposal request → approval → execution, execution
 * refused without approval; the disposal journal (cost +
 * accumulated depreciation removed, gain/loss = actual
 * proceeds − book value at disposal date) with exact numbers;
 * depreciation catch-up to the disposal date (posted periods
 * first, catch-up only for the unposted ones); sale on credit
 * (Dr 411) vs cash/bank (Dr 12); scrapping and donation with
 * zero proceeds; write-off of a lost asset (proceeds 0, loss
 * recognised, status written_off, asset retained in the
 * register, its active assignment closed); no self-approval;
 * reject/cancel restoring the asset status; post-disposal
 * refusals (assignment, transfer, maintenance, depreciation);
 * the asset show page timeline showing the disposal.
 *
 * Users: 1 = Company (all fixed-asset permissions), 2 =
 * Operator (manage fixed assets only), 3 = Approver (manage
 * + approve acquisitions + approve transfers + approve
 * disposals + post fixed asset depreciation), 4 = Other
 * Company (all fixed-asset permissions), 5 = Custodian user
 * (no fixed-asset permission, linked to employee Ana),
 * 6 = No Permission. "Today" is frozen at 2026-11-07.
 * Employees: 1 = Ana (user 5), 2 = Bruno (user 3).
 * Locations: 1 = WH1, 2 = WH2. Cost centres: 1 = LOG,
 * 2 = ADM. Chart: 1 = 321 asset, 2 = 328 accumulated
 * depreciation, 3 = 421 AP, 4 = 471 clearing, 5 = 631
 * depreciation expense, 6 = 6226 maintenance expense,
 * 7 = 12 bank, 8 = 411 customers, 9 = 763 disposal gain,
 * 10 = 6831 disposal loss.
 */
class FixedAssetPhase5WorkflowTest extends TestCase
{
    protected FixedAssetService $service;
    protected FixedAssetNumberingService $numbering;
    protected FixedAssetAcquisitionService $acquisitions;
    protected FixedAssetDepreciationService $depreciation;
    protected FixedAssetAssignmentService $assignments;
    protected FixedAssetMaintenanceService $maintenance;
    protected FixedAssetVerificationService $verification;
    protected FixedAssetDisposalService $disposals;
    protected FixedAssetTransferService $transfers;

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
        User::findOrFail(3)->givePermissionTo(['manage transaction', 'manage fixed assets', 'approve fixed asset acquisitions', 'approve fixed asset transfers', 'approve fixed asset disposals', 'post fixed asset depreciation']);
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
            ['id' => 6, 'name' => 'Maintenance expense', 'code' => 6226, 'type' => 6, 'sub_type' => 1, 'is_enabled' => 1, 'created_by' => 1],
            ['id' => 7, 'name' => 'Bank accounts', 'code' => 12, 'type' => 1, 'sub_type' => 1, 'is_enabled' => 1, 'created_by' => 1],
            ['id' => 8, 'name' => 'Customers', 'code' => 411, 'type' => 1, 'sub_type' => 1, 'is_enabled' => 1, 'created_by' => 1],
            ['id' => 9, 'name' => 'Gains on capital investments', 'code' => 763, 'type' => 7, 'sub_type' => 1, 'is_enabled' => 1, 'created_by' => 1],
            ['id' => 10, 'name' => 'Disposal losses', 'code' => 6831, 'type' => 6, 'sub_type' => 1, 'is_enabled' => 1, 'created_by' => 1],
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

        $this->assignments = new FixedAssetAssignmentService($this->service);
        $this->maintenance = new FixedAssetMaintenanceService(
            $this->service,
            $this->acquisitions,
            new JournalEntryPoster(),
            new ChartAccountResolver(),
        );
        $this->verification = new FixedAssetVerificationService($this->service);
        $this->transfers = new FixedAssetTransferService($this->service);
        $this->disposals = new FixedAssetDisposalService(
            $this->service,
            $this->acquisitions,
            $this->depreciation,
            $this->assignments,
            new JournalEntryPoster(),
            new ChartAccountResolver(),
        );

        $this->service->createCategory([
            'code' => 'IT', 'name' => 'IT Equipment', 'classification' => 'tangible',
            'default_useful_life' => 5, 'default_life_unit' => 'year',
            'depreciation_method' => 'straight_line', 'residual_value_type' => 'none',
            'asset_account_id' => 1, 'accum_depreciation_account_id' => 2,
            'depreciation_expense_account_id' => 5,
            'disposal_gain_account_id' => 9, 'disposal_loss_account_id' => 10,
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

        foreach (['purchase_delivery_note_items', 'purchase_delivery_notes', 'bill_products', 'bills', 'purchase_products', 'purchases', 'product_services', 'venders', 'transaction_lines', 'bank_accounts', 'journal_items', 'journal_entries', 'fixed_asset_accounting_entries', 'fixed_asset_assignments', 'fixed_asset_transfers', 'fixed_asset_maintenance_requests', 'fixed_asset_maintenance_costs', 'fixed_asset_physical_verifications', 'fixed_asset_verification_items', 'fixed_asset_disposals', 'fixed_asset_depreciation_runs', 'fixed_asset_depreciation_entries', 'journal_line_dimensions', 'dimension_audit_events', 'fixed_asset_depreciation_schedules', 'fixed_asset_capitalizations', 'fixed_asset_acquisition_costs', 'departments', 'employees', 'fixed_asset_acquisitions', 'fixed_asset_audit_events', 'fixed_asset_status_history', 'fixed_asset_approvals', 'fixed_asset_documents', 'fixed_assets', 'fixed_asset_locations', 'fixed_asset_categories', 'cost_centres', 'chart_of_accounts', 'email_templates', 'ch_messages', 'languages', 'plans', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'permissions', 'roles', 'users', 'settings', 'branch_user', 'branches'] as $table) {
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
    // Disposal — sale with gain (exact journal numbers)
    // ------------------------------------------------------------------

    public function test_disposal_sale_with_gain_posts_balanced_journal(): void
    {
        $asset = $this->createCapitalizedAsset();
        $this->postPeriod('2026-11');
        $this->assertSame(0, bccomp((string) $asset->fresh()->current_book_value, '46216.67', 2));

        $disposal = $this->requestDisposalFor($asset);
        $this->assertSame('requested', $disposal->status);
        $this->assertSame('disposal_pending', $asset->fresh()->status);

        $this->approveDisposal($disposal);
        $this->assertSame('approved', $disposal->fresh()->status);

        $executed = $this->disposals->executeDisposal($disposal->fresh(), [
            'actual_proceeds' => '50000.0000', 'proceeds_received' => true, 'disposal_date' => '2026-11-20',
        ], 1, 1);

        // Book value 46,216.67; proceeds 50,000 → gain 3,783.33.
        $this->assertSame('executed', $executed->status);
        $this->assertSame(0, bccomp((string) $executed->actual_proceeds, '50000.00', 2));
        $this->assertSame(0, bccomp((string) $executed->gain_loss_amount, '3783.33', 2));
        $this->assertSame(0, bccomp((string) $executed->book_value_at_disposal, '46216.67', 2));
        $this->assertSame(0, bccomp((string) $executed->accumulated_depreciation_at_disposal, '783.33', 2));
        $this->assertNotNull($executed->journal_id);

        // Dr Bank 12 (chart 7) 50,000 + Dr Accum dep 328 (chart 2)
        // 783.33 / Cr Asset 321 (chart 1) 47,000 + Cr Gain 763
        // (chart 9) 3,783.33. Balanced: 50,783.33 = 50,783.33.
        $this->assertJournalLines((int) $executed->journal_id, [
            7 => ['50000.00', '0.00'],
            2 => ['783.33', '0.00'],
            1 => ['0.00', '47000.00'],
            9 => ['0.00', '3783.33'],
        ]);

        $fresh = $asset->fresh();
        $this->assertSame('disposed', $fresh->status);
        $this->assertSame(0, bccomp((string) $fresh->current_book_value, '0.0000', 4));
        $this->assertSame(1, FixedAssetAccountingEntry::where('asset_id', $asset->id)
            ->where('transaction_type', FixedAssetAccountingEntry::TYPE_DISPOSAL)->count());

        // November was already posted by the run — the catch-up
        // had nothing to do and created no second run.
        $this->assertSame(1, FixedAssetDepreciationRun::count());
    }

    public function test_execution_refused_without_approval(): void
    {
        $asset = $this->createCapitalizedAsset();
        $disposal = $this->requestDisposalFor($asset);

        $this->expectException(FixedAssetException::class);
        $this->disposals->executeDisposal($disposal, [
            'actual_proceeds' => '50000.0000', 'disposal_date' => '2026-11-20',
        ], 1, 1);
    }

    // ------------------------------------------------------------------
    // Disposal — depreciation catch-up
    // ------------------------------------------------------------------

    public function test_disposal_catches_up_unposted_depreciation(): void
    {
        $asset = $this->createCapitalizedAsset();
        // No period posted yet: November is still unposted.

        $disposal = $this->requestDisposalFor($asset, 1, ['expected_proceeds' => '40000.0000']);
        $this->approveDisposal($disposal);

        $executed = $this->disposals->executeDisposal($disposal->fresh(), [
            'actual_proceeds' => '40000.0000', 'proceeds_received' => true, 'disposal_date' => '2026-11-30',
        ], 1, 1);

        // The catch-up posted November first: one run, one journal
        // Dr 631 (chart 5) 783.33 / Cr 328 (chart 2) 783.33.
        $catchUp = FixedAssetDepreciationRun::where('notes', 'like', '%catch-up on disposal of '.$asset->code.'%')->firstOrFail();
        $this->assertSame('posted', $catchUp->status);
        $this->assertSame(0, bccomp((string) $catchUp->total_amount, '783.33', 2));
        $this->assertJournalLines((int) $catchUp->journal_id, [
            5 => ['783.33', '0.00'],
            2 => ['0.00', '783.33'],
        ]);
        $this->assertSame('posted', FixedAssetDepreciationSchedule::where('asset_id', $asset->id)->whereDate('period', '2026-11-01')->value('status'));
        $this->assertSame(1, FixedAssetDepreciationEntry::where('asset_id', $asset->id)->where('status', 'posted')->count());

        // Then the disposal: book 46,216.67; proceeds 40,000 →
        // loss 6,216.67. Dr Bank 40,000 + Dr Accum 783.33 +
        // Dr Loss 6831 6,216.67 / Cr Asset 47,000.
        $this->assertSame(0, bccomp((string) $executed->gain_loss_amount, '-6216.67', 2));
        $this->assertJournalLines((int) $executed->journal_id, [
            7 => ['40000.00', '0.00'],
            2 => ['783.33', '0.00'],
            10 => ['6216.67', '0.00'],
            1 => ['0.00', '47000.00'],
        ]);
        $this->assertSame('disposed', $asset->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Disposal — sale on credit, scrapping, donation
    // ------------------------------------------------------------------

    public function test_sale_on_credit_debits_receivables(): void
    {
        $asset = $this->createCapitalizedAsset();
        $this->postPeriod('2026-11');

        $disposal = $this->requestDisposalFor($asset);
        $this->approveDisposal($disposal);
        $executed = $this->disposals->executeDisposal($disposal->fresh(), [
            'actual_proceeds' => '50000.0000', 'proceeds_received' => false, 'disposal_date' => '2026-11-20',
        ], 1, 1);

        // Same gain, but the proceeds sit in Customers 411
        // (chart 8) instead of the bank.
        $this->assertJournalLines((int) $executed->journal_id, [
            8 => ['50000.00', '0.00'],
            2 => ['783.33', '0.00'],
            1 => ['0.00', '47000.00'],
            9 => ['0.00', '3783.33'],
        ]);
    }

    public function test_scrapping_with_zero_proceeds_recognises_full_loss(): void
    {
        $asset = $this->createCapitalizedAsset();
        $asset->update(['status' => 'damaged']);

        $disposal = $this->requestDisposalFor($asset, 1, [
            'method' => FixedAssetDisposal::METHOD_SCRAPPING, 'expected_proceeds' => '0.0000',
        ]);
        $this->approveDisposal($disposal);
        $executed = $this->disposals->executeDisposal($disposal->fresh(), [
            'actual_proceeds' => '0.0000', 'disposal_date' => '2026-11-07',
        ], 1, 1);

        // Catch-up posted November (783.33), so the loss is the
        // whole remaining book value 46,216.67.
        $this->assertSame(0, bccomp((string) $executed->gain_loss_amount, '-46216.67', 2));
        $this->assertJournalLines((int) $executed->journal_id, [
            2 => ['783.33', '0.00'],
            10 => ['46216.67', '0.00'],
            1 => ['0.00', '47000.00'],
        ]);
        $this->assertSame('disposed', $asset->fresh()->status);
    }

    public function test_donation_with_zero_proceeds(): void
    {
        $asset = $this->createCapitalizedAsset();

        $disposal = $this->requestDisposalFor($asset, 1, [
            'method' => FixedAssetDisposal::METHOD_DONATION, 'expected_proceeds' => '0.0000',
        ]);
        $this->approveDisposal($disposal);
        $executed = $this->disposals->executeDisposal($disposal->fresh(), [
            'actual_proceeds' => '0.0000', 'disposal_date' => '2026-11-07',
        ], 1, 1);

        $this->assertSame('executed', $executed->status);
        $this->assertSame('disposed', $asset->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Disposal — write-off of a lost asset
    // ------------------------------------------------------------------

    public function test_write_off_of_lost_asset(): void
    {
        $asset = $this->createCapitalizedAsset();
        $assignment = $this->assignAssetTo($asset);
        $this->assertSame('assigned', $asset->fresh()->status);

        // Phase 4 verification flags it lost — NOT written off.
        $this->markAssetLost($asset);
        $this->assertSame('lost', $asset->fresh()->status);

        $disposal = $this->requestDisposalFor($asset, 1, [
            'method' => FixedAssetDisposal::METHOD_WRITE_OFF,
            'reason' => 'Lost during the November stocktake',
            'expected_proceeds' => '0.0000',
        ]);
        $this->approveDisposal($disposal);
        $executed = $this->disposals->executeDisposal($disposal->fresh(), [
            'actual_proceeds' => '0.0000', 'disposal_date' => '2026-11-07',
        ], 1, 1);

        // Catch-up posted November (783.33); the loss is the whole
        // remaining book value. Proceeds 0.
        $this->assertSame(0, bccomp((string) $executed->gain_loss_amount, '-46216.67', 2));
        $this->assertJournalLines((int) $executed->journal_id, [
            2 => ['783.33', '0.00'],
            10 => ['46216.67', '0.00'],
            1 => ['0.00', '47000.00'],
        ]);

        // Written off, NOT deleted: the register keeps the asset
        // with its full history.
        $fresh = $asset->fresh();
        $this->assertSame('written_off', $fresh->status);
        $this->assertNotNull(FixedAsset::find($asset->id));
        $this->assertNull($fresh->deleted_at);
        $this->assertSame(0, bccomp((string) $fresh->current_book_value, '0.0000', 4));
        $this->assertTrue($fresh->statusHistory()->where('to_status', 'written_off')->exists());

        // The custodian's assignment is closed by the execution —
        // a written-off asset is nobody's responsibility any more.
        $this->assertSame('returned', $assignment->fresh()->status);
    }

    public function test_write_off_refused_for_available_asset(): void
    {
        $asset = $this->createCapitalizedAsset();

        $this->expectException(FixedAssetException::class);
        $this->requestDisposalFor($asset, 1, ['method' => FixedAssetDisposal::METHOD_WRITE_OFF, 'expected_proceeds' => '0.0000']);
    }

    public function test_sale_refused_for_lost_asset(): void
    {
        $asset = $this->createCapitalizedAsset();
        $this->markAssetLost($asset);

        $this->expectException(FixedAssetException::class);
        $this->requestDisposalFor($asset);
    }

    // ------------------------------------------------------------------
    // Disposal — approval rules, reject, cancel, duplicates
    // ------------------------------------------------------------------

    public function test_disposal_self_approval_refused(): void
    {
        $asset = $this->createCapitalizedAsset();
        $disposal = $this->requestDisposalFor($asset);
        $approval = $this->disposalApprovalFor($disposal);

        $this->expectException(FixedAssetException::class);
        $this->disposals->decideDisposalApproval($approval, 1, FixedAssetApproval::DECISION_APPROVED);
    }

    public function test_reject_restores_asset_status(): void
    {
        $asset = $this->createCapitalizedAsset();
        $disposal = $this->requestDisposalFor($asset);
        $approval = $this->disposalApprovalFor($disposal);

        $this->disposals->decideDisposalApproval($approval, 3, FixedAssetApproval::DECISION_REJECTED, 'Price too low');

        $this->assertSame('rejected', $disposal->fresh()->status);
        $this->assertSame('available', $asset->fresh()->status);
    }

    public function test_cancel_requested_disposal_restores_status(): void
    {
        $asset = $this->createCapitalizedAsset();
        $disposal = $this->requestDisposalFor($asset);

        $this->disposals->cancelDisposal($disposal->fresh(), 1, 1);

        $this->assertSame('cancelled', $disposal->fresh()->status);
        $this->assertSame('available', $asset->fresh()->status);
    }

    public function test_second_disposal_while_pending_refused(): void
    {
        $asset = $this->createCapitalizedAsset();
        $this->requestDisposalFor($asset);

        $this->expectException(FixedAssetException::class);
        $this->requestDisposalFor($asset);
    }

    public function test_disposal_of_assigned_asset_refused(): void
    {
        $asset = $this->createCapitalizedAsset();
        $this->assignAssetTo($asset);

        $this->expectException(FixedAssetException::class);
        $this->requestDisposalFor($asset);
    }

    public function test_expected_proceeds_must_be_zero_for_scrapping(): void
    {
        $asset = $this->createCapitalizedAsset();

        $this->expectException(FixedAssetException::class);
        $this->requestDisposalFor($asset, 1, [
            'method' => FixedAssetDisposal::METHOD_SCRAPPING, 'expected_proceeds' => '100.0000',
        ]);
    }

    // ------------------------------------------------------------------
    // Disposal — post-disposal guards (service layer)
    // ------------------------------------------------------------------

    public function test_post_disposal_guards_refuse_everything(): void
    {
        $asset = $this->createCapitalizedAsset();
        $disposal = $this->requestDisposalFor($asset);
        $this->approveDisposal($disposal);
        $this->disposals->executeDisposal($disposal->fresh(), [
            'actual_proceeds' => '50000.0000', 'proceeds_received' => true, 'disposal_date' => '2026-11-20',
        ], 1, 1);
        $this->assertSame('disposed', $asset->fresh()->status);

        // Assignment refused.
        try {
            $this->assignments->requestAssignment($asset->fresh(), ['custodian_id' => 1], 1, 1);
            $this->fail('Assignment of a disposed asset was not refused.');
        } catch (FixedAssetException) {
            $this->assertTrue(true);
        }

        // Transfer refused.
        try {
            $this->transfers->requestTransfer($asset->fresh(), [
                'to_location_id' => 2, 'to_custodian_id' => 2, 'to_cost_centre_id' => 2, 'reason' => 'Move',
            ], 1, 1);
            $this->fail('Transfer of a disposed asset was not refused.');
        } catch (FixedAssetException) {
            $this->assertTrue(true);
        }

        // Maintenance refused.
        try {
            $this->requestMaintenanceFor($asset->fresh());
            $this->fail('Maintenance of a disposed asset was not refused.');
        } catch (FixedAssetException) {
            $this->assertTrue(true);
        }

        // Depreciation: the disposed asset is never in a run's
        // lines, and the catch-up throws instead of posting.
        $lines = $this->depreciation->computeLines(1, Carbon::parse('2026-12-01'));
        $this->assertSame([], array_values(array_filter($lines, fn ($line) => (int) $line['asset']->id === (int) $asset->id)));
        try {
            $this->depreciation->postCatchUpForAsset($asset->fresh(), Carbon::parse('2026-12-31'), 1, 1, 3);
            $this->fail('Catch-up on a disposed asset was not refused.');
        } catch (FixedAssetException) {
            $this->assertTrue(true);
        }

        // A second disposal is refused too.
        try {
            $this->requestDisposalFor($asset->fresh());
            $this->fail('Second disposal of a disposed asset was not refused.');
        } catch (FixedAssetException) {
            $this->assertTrue(true);
        }
    }

    // ------------------------------------------------------------------
    // Disposal — HTTP: routes, pages, full flow, timeline
    // ------------------------------------------------------------------

    public function test_disposal_routes_resolve(): void
    {
        $this->assertTrue(Route::has('fixed-asset-disposals.index'));
        $this->assertTrue(Route::has('fixed-asset-disposals.create'));
        $this->assertTrue(Route::has('fixed-asset-disposals.store'));
        $this->assertTrue(Route::has('fixed-asset-disposals.show'));
        $this->assertTrue(Route::has('fixed-asset-disposals.decide'));
        $this->assertTrue(Route::has('fixed-asset-disposals.execute-form'));
        $this->assertTrue(Route::has('fixed-asset-disposals.execute'));
        $this->assertTrue(Route::has('fixed-asset-disposals.cancel'));
    }

    public function test_disposal_full_flow_via_http(): void
    {
        $asset = $this->createCapitalizedAsset();

        $this->actingAs(User::findOrFail(1))->post(route('fixed-asset-disposals.store'), [
            'asset_id' => $asset->id, 'method' => 'sale', 'reason' => 'Replaced by a newer model',
            'expected_proceeds' => '50000', 'buyer_name' => 'Maputo Traders',
        ])->assertRedirect();
        $disposal = FixedAssetDisposal::orderByDesc('id')->firstOrFail();
        $this->assertSame('requested', $disposal->status);

        $this->actingAs(User::findOrFail(3))->post(route('fixed-asset-disposals.decide', $disposal->id), [
            'decision' => 'approved', 'reason' => 'OK',
        ])->assertRedirect();
        $this->assertSame('approved', $disposal->fresh()->status);

        $this->actingAs(User::findOrFail(1))->get(route('fixed-asset-disposals.execute-form', $disposal->id))->assertOk();

        $this->actingAs(User::findOrFail(1))->post(route('fixed-asset-disposals.execute', $disposal->id), [
            'actual_proceeds' => '50000', 'proceeds_received' => '1', 'disposal_date' => '2026-11-20',
        ])->assertRedirect();

        $executed = $disposal->fresh();
        $this->assertSame('executed', $executed->status);
        $this->assertSame(0, bccomp((string) $executed->gain_loss_amount, '3783.33', 2));
        $this->assertSame('disposed', $asset->fresh()->status);
    }

    public function test_disposal_pages_render(): void
    {
        $asset = $this->createCapitalizedAsset();
        $disposal = $this->requestDisposalFor($asset);

        $this->actingAs(User::findOrFail(2))->get(route('fixed-asset-disposals.index'))->assertOk();
        $this->actingAs(User::findOrFail(2))->get(route('fixed-asset-disposals.create'))->assertOk();
        $this->actingAs(User::findOrFail(2))->get(route('fixed-asset-disposals.show', $disposal->id))->assertOk();
    }

    public function test_disposal_show_denied_for_other_company(): void
    {
        $asset = $this->createCapitalizedAsset();
        $disposal = $this->requestDisposalFor($asset);
        $response = $this->actingAs(User::findOrFail(4))->get(route('fixed-asset-disposals.show', $disposal->id));
        $response->assertRedirect();
        $response->assertSessionHas('error');
    }

    public function test_asset_show_timeline_includes_disposal(): void
    {
        $asset = $this->createCapitalizedAsset();
        $disposal = $this->requestDisposalFor($asset);
        $this->approveDisposal($disposal);
        $this->disposals->executeDisposal($disposal->fresh(), [
            'actual_proceeds' => '50000.0000', 'proceeds_received' => true, 'disposal_date' => '2026-11-20',
        ], 1, 1);

        $this->actingAs(User::findOrFail(2))->get(route('fixed-assets.show', $asset->id))
            ->assertOk()
            ->assertSee('Disposal requested')
            ->assertSee('Disposal approved')
            ->assertSee('Disposal executed')
            ->assertSee('Gain 3,783.33');
    }

    // ------------------------------------------------------------------
    // Phase 5 helpers
    // ------------------------------------------------------------------

    protected function requestDisposalFor(FixedAsset $asset, int $requesterId = 1, array $overrides = []): FixedAssetDisposal
    {
        return $this->disposals->requestDisposal($asset->fresh(), array_merge([
            'method' => FixedAssetDisposal::METHOD_SALE,
            'reason' => 'Replaced by a newer model',
            'expected_proceeds' => '50000.0000',
        ], $overrides), 1, $requesterId);
    }

    protected function disposalApprovalFor(FixedAssetDisposal $disposal): FixedAssetApproval
    {
        return FixedAssetApproval::where('transaction_type', FixedAssetApproval::TYPE_DISPOSAL)
            ->where('transaction_id', $disposal->id)
            ->where('decision', FixedAssetApproval::DECISION_PENDING)
            ->firstOrFail();
    }

    protected function approveDisposal(FixedAssetDisposal $disposal, int $approverId = 3): FixedAssetDisposal
    {
        $approval = $this->disposalApprovalFor($disposal);
        $this->disposals->decideDisposalApproval($approval, $approverId, FixedAssetApproval::DECISION_APPROVED);

        return $disposal->fresh();
    }

    /** Drive an asset through the Phase 4 verification flow to "lost". */
    protected function markAssetLost(FixedAsset $asset): FixedAsset
    {
        $campaign = $this->createCampaignFor();
        $this->verification->startCampaign($campaign->fresh(), 1, 1);
        $item = $campaign->fresh()->items()->first();
        $this->verification->checkItem($item, 'missing', 1, 2, 'Not in the store room');
        $this->verification->closeCampaign($campaign->fresh(), 1, 1);

        $this->assertSame('lost', $asset->fresh()->status);

        return $asset->fresh();
    }

    /** Assert a journal's lines: [accountId => [debit, credit]] at 2dp, in posting order, and balanced. */
    protected function assertJournalLines(int $journalId, array $expected): void
    {
        $items = JournalItem::where('journal', $journalId)->orderBy('id')->get();
        $actual = [];
        foreach ($items as $item) {
            $actual[(int) $item->account] = [(string) $item->debit, (string) $item->credit];
        }
        $this->assertSame(array_keys($expected), array_keys($actual), 'Journal accounts differ for journal '.$journalId);
        foreach ($expected as $account => [$debit, $credit]) {
            $this->assertSame(0, bccomp($actual[$account][0], $debit, 2), "Journal {$journalId} account {$account} debit");
            $this->assertSame(0, bccomp($actual[$account][1], $credit, 2), "Journal {$journalId} account {$account} credit");
        }
        $this->assertSame(0, bccomp((string) JournalItem::where('journal', $journalId)->sum('debit'), (string) JournalItem::where('journal', $journalId)->sum('credit'), 2), 'Journal '.$journalId.' is not balanced');
    }

    // ------------------------------------------------------------------
    // Phase 4 helpers
    // ------------------------------------------------------------------

    protected function requestMaintenanceFor(FixedAsset $asset, int $requesterId = 1, array $overrides = []): FixedAssetMaintenanceRequest
    {
        return $this->maintenance->requestMaintenance($asset->fresh(), array_merge([
            'type' => FixedAssetMaintenanceRequest::TYPE_CORRECTIVE,
            'priority' => FixedAssetMaintenanceRequest::PRIORITY_HIGH,
            'description' => 'Screen flickers and goes dark',
            'expected_cost' => '1500.0000',
        ], $overrides), 1, $requesterId);
    }

    protected function maintenanceApprovalFor(FixedAssetMaintenanceRequest $request): FixedAssetApproval
    {
        return FixedAssetApproval::where('transaction_type', FixedAssetApproval::TYPE_MAINTENANCE)
            ->where('transaction_id', $request->id)
            ->where('decision', FixedAssetApproval::DECISION_PENDING)
            ->firstOrFail();
    }

    protected function approveMaintenance(FixedAssetMaintenanceRequest $request, int $approverId = 2): FixedAssetMaintenanceRequest
    {
        $approval = $this->maintenanceApprovalFor($request);
        $this->maintenance->decideMaintenanceApproval($approval, $approverId, FixedAssetApproval::DECISION_APPROVED);

        return $request->fresh();
    }

    protected function assignAssetTo(FixedAsset $asset): FixedAssetAssignment
    {
        // Custodian is the fixture employee Ana (id 1, user_id 5).
        $assignment = $this->assignments->requestAssignment($asset->fresh(), ['custodian_id' => 1], 1, 1);
        $approval = FixedAssetApproval::where('transaction_type', FixedAssetApproval::TYPE_ASSIGNMENT)
            ->where('transaction_id', $assignment->id)
            ->where('decision', FixedAssetApproval::DECISION_PENDING)
            ->firstOrFail();
        $this->assignments->decideAssignmentApproval($approval, 3, FixedAssetApproval::DECISION_APPROVED);
        $this->assignments->handOverAssignment($assignment->fresh(), 1, 1);
        $this->assignments->acceptAssignment($assignment->fresh(), 1, 5);

        return $assignment->fresh();
    }

    protected function createCampaignFor(?int $locationId = null): FixedAssetPhysicalVerification
    {
        return $this->verification->createCampaign([
            'name' => 'November stocktake',
            'location_id' => $locationId,
            'deadline' => '2026-11-30',
            'notes' => 'Annual count',
        ], 1, 1);
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

        Schema::create('fixed_asset_disposals', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            $table->string('method', 20);
            $table->string('status', 20)->default('requested');
            $table->text('reason')->nullable();
            $table->decimal('expected_proceeds', 20, 4)->default(0);
            $table->decimal('actual_proceeds', 20, 4)->nullable();
            $table->boolean('proceeds_received')->default(true);
            $table->string('buyer_name', 255)->nullable();
            $table->date('disposal_date')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->decimal('gain_loss_amount', 20, 4)->nullable();
            $table->decimal('book_value_at_disposal', 20, 4)->nullable();
            $table->decimal('accumulated_depreciation_at_disposal', 20, 4)->nullable();
            $table->string('previous_status', 30)->nullable();
            $table->integer('requested_by');
            $table->integer('approved_by')->nullable();
            $table->integer('executed_by')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['asset_id'], 'fad_asset_idx');
            $table->index(['created_by'], 'fad_creator_idx');
            $table->index(['created_by', 'status'], 'fad_status_idx');
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
        Schema::create('fixed_asset_maintenance_requests', function ($table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('asset_id');
            $table->string('type', 20)->default('corrective');
            $table->string('priority', 20)->default('medium');
            $table->text('description');
            $table->decimal('expected_cost', 20, 4)->nullable();
            $table->string('status', 20)->default('requested');
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
            $table->index(['asset_id'], 'famr_asset_idx');
            $table->index(['created_by', 'status'], 'famr_creator_status_idx');
            $table->index(['requested_by'], 'famr_requested_idx');
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
            $table->integer('recorded_by');
            $table->timestamp('recorded_at')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->timestamps();
            $table->index(['maintenance_request_id'], 'famc_request_idx');
            $table->index(['asset_id'], 'famc_asset_idx');
        });
        Schema::create('fixed_asset_physical_verifications', function ($table) {
            $table->id();
            $table->integer('created_by');
            $table->string('name');
            $table->unsignedBigInteger('location_id')->nullable();
            $table->date('deadline')->nullable();
            $table->string('status', 20)->default('planned');
            $table->integer('created_by_user')->nullable();
            $table->integer('started_by')->nullable();
            $table->integer('closed_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['created_by', 'status'], 'fapv_creator_status_idx');
            $table->index(['location_id'], 'fapv_location_idx');
        });
        Schema::create('fixed_asset_verification_items', function ($table) {
            $table->id();
            $table->integer('created_by');
            $table->unsignedBigInteger('verification_id');
            $table->unsignedBigInteger('asset_id');
            $table->unsignedBigInteger('expected_location_id')->nullable();
            $table->unsignedBigInteger('expected_custodian_id')->nullable();
            $table->string('previous_status', 30)->nullable();
            $table->string('result', 20)->default('pending');
            $table->integer('checked_by')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['verification_id'], 'favi_verification_idx');
            $table->index(['asset_id'], 'favi_asset_idx');
            $table->unique(['verification_id', 'asset_id'], 'favi_verif_asset_uq');
        });
    }
}
