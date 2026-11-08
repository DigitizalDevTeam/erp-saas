<?php

namespace Tests\Feature\FixedAssets;

use App\Exceptions\FixedAssetException;
use App\Models\FixedAsset;
use App\Models\FixedAssetAcquisition;
use App\Models\FixedAssetApproval;
use App\Models\FixedAssetAssignment;
use App\Models\FixedAssetTransfer;
use App\Models\User;
use App\Services\Accounting\ChartAccountResolver;
use App\Services\Accounting\JournalEntryPoster;
use App\Services\FixedAssetAcquisitionService;
use App\Services\FixedAssetAssignmentService;
use App\Services\FixedAssetNumberingService;
use App\Services\FixedAssetService;
use App\Services\FixedAssetSettings;
use App\Services\FixedAssetTransferService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Fixed Assets Phase 2 — Assignment + Transfer, HTTP level
 * (plan §5, §10; spec §11, §12).
 *
 * Covers: assignment requested → approved → handed over →
 * accepted (custodian acceptance completes it) / refused
 * (back to available, recorded); return + reassignment with
 * history kept; transfer requested → approved → handed over
 * → received (asset moves only on receipt, schedule
 * untouched); rejection/cancellation restores the previous
 * state; disposed/lost/damaged/written-off assets refuse
 * assignment and transfer in the service layer; permission
 * and cross-company denial; the asset show timeline.
 *
 * Users: 1 = Company (all fixed-asset permissions), 2 =
 * Operator (manage fixed assets only), 3 = Approver (manage
 * + approve acquisitions + approve transfers), 4 = Other
 * Company (all fixed-asset permissions), 5 = Custodian user
 * (no fixed-asset permission, linked to employee Ana),
 * 6 = No Permission. "Today" is frozen at 2026-11-07.
 * Employees: 1 = Ana (user 5), 2 = Bruno (user 3).
 * Locations: 1 = WH1, 2 = WH2. Cost centres: 1 = LOG,
 * 2 = ADM.
 */
class FixedAssetPhase2WorkflowTest extends TestCase
{
    protected FixedAssetService $service;
    protected FixedAssetNumberingService $numbering;
    protected FixedAssetAcquisitionService $acquisitions;
    protected FixedAssetAssignmentService $assignments;
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
        User::findOrFail(3)->givePermissionTo(['manage transaction', 'manage fixed assets', 'approve fixed asset acquisitions', 'approve fixed asset transfers']);
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
        $this->assignments = new FixedAssetAssignmentService($this->service);
        $this->transfers = new FixedAssetTransferService($this->service);

        $this->service->createCategory([
            'code' => 'IT', 'name' => 'IT Equipment', 'classification' => 'tangible',
            'default_useful_life' => 5, 'default_life_unit' => 'year',
            'depreciation_method' => 'straight_line', 'residual_value_type' => 'none',
            'asset_account_id' => 1, 'accum_depreciation_account_id' => 2,
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

        foreach (['purchase_delivery_note_items', 'purchase_delivery_notes', 'bill_products', 'bills', 'purchase_products', 'purchases', 'product_services', 'venders', 'transaction_lines', 'bank_accounts', 'journal_items', 'journal_entries', 'fixed_asset_accounting_entries', 'fixed_asset_assignments', 'fixed_asset_transfers', 'fixed_asset_depreciation_schedules', 'fixed_asset_capitalizations', 'fixed_asset_acquisition_costs', 'departments', 'employees', 'fixed_asset_acquisitions', 'fixed_asset_audit_events', 'fixed_asset_status_history', 'fixed_asset_approvals', 'fixed_asset_documents', 'fixed_assets', 'fixed_asset_locations', 'fixed_asset_categories', 'cost_centres', 'chart_of_accounts', 'email_templates', 'ch_messages', 'languages', 'plans', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'permissions', 'roles', 'users', 'settings', 'branch_user', 'branches'] as $table) {
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
            'useful_life' => 5,
            'life_unit' => 'year',
            'components' => [
                ['component_type' => 'purchase_price', 'gross_amount' => '50000', 'vat_amount' => '8000', 'vat_recoverable' => '1'],
                ['component_type' => 'transport', 'gross_amount' => '5000', 'vat_amount' => '0'],
            ],
        ], $overrides);
    }

    protected function createDraftViaForm(int $userId = 1): FixedAssetAcquisition
    {
        $response = $this->actingAs(User::findOrFail($userId))->post(route('fixed-assets.store'), $this->formPayload());
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

    protected function assignmentPayload(int $assetId, array $overrides = []): array
    {
        return array_merge([
            'asset_id' => $assetId,
            'custodian_id' => 1,
            'location_id' => 1,
            'cost_centre_id' => 1,
            'condition' => 'Good',
            'accessories' => 'Charger, bag',
        ], $overrides);
    }

    protected function transferPayload(int $assetId, array $overrides = []): array
    {
        return array_merge([
            'asset_id' => $assetId,
            'to_location_id' => 2,
            'to_custodian_id' => 2,
            'to_cost_centre_id' => 2,
            'reason' => 'Moved to Warehouse 2',
        ], $overrides);
    }

    /** Drive an assignment to "accepted" over HTTP; returns it. */
    protected function assignAccepted(FixedAsset $asset, int $custodianId = 1, int $acceptingUserId = 5): FixedAssetAssignment
    {
        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-assignments.store'), $this->assignmentPayload($asset->id, ['custodian_id' => $custodianId]))
            ->assertRedirect();
        $assignment = FixedAssetAssignment::orderByDesc('id')->firstOrFail();

        $this->actingAs(User::findOrFail(3))
            ->post(route('fixed-asset-assignments.decide', $assignment->id), ['decision' => 'approved', 'reason' => 'OK'])
            ->assertRedirect();
        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-assignments.handover', $assignment->id))
            ->assertRedirect();
        $this->actingAs(User::findOrFail($acceptingUserId))
            ->post(route('fixed-asset-assignments.accept', $assignment->id))
            ->assertRedirect();

        $this->assertSame('accepted', $assignment->fresh()->status);

        return $assignment->fresh();
    }

    // ------------------------------------------------------------------
    // Assignment workflow over HTTP
    // ------------------------------------------------------------------

    public function test_assignment_happy_path_over_http_completes_only_on_custodian_acceptance(): void
    {
        $asset = $this->createCapitalizedAsset();

        // Requested by the company user; the asset is still
        // available — it is not "assigned" yet.
        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-assignments.store'), $this->assignmentPayload($asset->id))
            ->assertRedirect();
        $assignment = FixedAssetAssignment::orderByDesc('id')->firstOrFail();
        $this->assertSame('requested', $assignment->status);
        $this->assertSame('available', $asset->fresh()->status);
        $this->assertNull($asset->fresh()->custodian_id);

        // Approved by a different user (user 3); still not assigned.
        $this->actingAs(User::findOrFail(3))
            ->post(route('fixed-asset-assignments.decide', $assignment->id), ['decision' => 'approved', 'reason' => 'OK'])
            ->assertRedirect();
        $this->assertSame('approved', $assignment->fresh()->status);
        $this->assertSame('available', $asset->fresh()->status);

        // Handed over; still not assigned.
        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-assignments.handover', $assignment->id))
            ->assertRedirect();
        $this->assertSame('handed_over', $assignment->fresh()->status);
        $this->assertSame('available', $asset->fresh()->status);

        // Accepted by the custodian's linked user (Ana = user 5) —
        // NOW the asset is assigned.
        $this->actingAs(User::findOrFail(5))
            ->post(route('fixed-asset-assignments.accept', $assignment->id))
            ->assertRedirect();

        $assignment = $assignment->fresh();
        $this->assertSame('accepted', $assignment->status);
        $this->assertNotNull($assignment->accepted_at);
        $this->assertSame('2026-11-07', $assignment->effective_from->format('Y-m-d'));

        $asset = $asset->fresh();
        $this->assertSame('assigned', $asset->status);
        $this->assertSame(1, (int) $asset->custodian_id);
        $this->assertSame(1, (int) $asset->location_id);
        $this->assertSame(1, (int) $asset->cost_centre_id);

        // Status history records the change.
        $this->assertSame(1, \App\Models\FixedAssetStatusHistory::where('asset_id', $asset->id)->where('to_status', 'assigned')->count());
        // Exactly one active assignment.
        $this->assertSame(1, FixedAssetAssignment::where('asset_id', $asset->id)->whereIn('status', FixedAssetAssignment::ACTIVE_STATUSES)->count());
    }

    public function test_assignment_refusal_returns_asset_to_available_and_is_recorded(): void
    {
        $asset = $this->createCapitalizedAsset();

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-assignments.store'), $this->assignmentPayload($asset->id))
            ->assertRedirect();
        $assignment = FixedAssetAssignment::orderByDesc('id')->firstOrFail();
        $this->actingAs(User::findOrFail(3))
            ->post(route('fixed-asset-assignments.decide', $assignment->id), ['decision' => 'approved'])
            ->assertRedirect();
        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-assignments.handover', $assignment->id))
            ->assertRedirect();

        $this->actingAs(User::findOrFail(5))
            ->post(route('fixed-asset-assignments.refuse', $assignment->id), ['reason' => 'Wrong laptop model'])
            ->assertRedirect();

        $assignment = $assignment->fresh();
        $this->assertSame('refused', $assignment->status);
        $this->assertSame('Wrong laptop model', $assignment->refusal_reason);
        $this->assertNotNull($assignment->refused_at);

        $asset = $asset->fresh();
        $this->assertSame('available', $asset->status);
        $this->assertNull($asset->custodian_id);
        // No status change was ever recorded for the refusal —
        // the asset never left "available".
        $this->assertSame(0, \App\Models\FixedAssetStatusHistory::where('asset_id', $asset->id)->where('to_status', 'assigned')->count());
    }

    public function test_assignment_self_approval_is_refused(): void
    {
        $asset = $this->createCapitalizedAsset();

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-assignments.store'), $this->assignmentPayload($asset->id))
            ->assertRedirect();
        $assignment = FixedAssetAssignment::orderByDesc('id')->firstOrFail();

        // The requester (user 1) cannot approve their own request.
        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-assignments.decide', $assignment->id), ['decision' => 'approved'])
            ->assertRedirect();
        $this->assertSame('requested', $assignment->fresh()->status);
        $approval = FixedAssetApproval::where('transaction_type', FixedAssetApproval::TYPE_ASSIGNMENT)->where('transaction_id', $assignment->id)->firstOrFail();
        $this->assertSame('pending', $approval->decision);
    }

    public function test_second_assignment_while_one_is_active_is_refused(): void
    {
        $asset = $this->createCapitalizedAsset();
        $this->assignAccepted($asset);

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-assignments.store'), $this->assignmentPayload($asset->id, ['custodian_id' => 2]))
            ->assertRedirect();

        $this->assertSame(1, FixedAssetAssignment::where('asset_id', $asset->id)->count());
        $this->assertSame('assigned', $asset->fresh()->status);
    }

    public function test_assignment_return_and_reassignment_keeps_history(): void
    {
        $asset = $this->createCapitalizedAsset();
        $first = $this->assignAccepted($asset);

        // Return: asset available again, custodian cleared,
        // location kept, history row closed with a return date.
        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-assignments.return', $first->id), ['reason' => 'Ana left the project'])
            ->assertRedirect();

        $first = $first->fresh();
        $this->assertSame('returned', $first->status);
        $this->assertSame('2026-11-07', $first->effective_to->format('Y-m-d'));
        $this->assertSame('Ana left the project', $first->return_reason);

        $asset = $asset->fresh();
        $this->assertSame('available', $asset->status);
        $this->assertNull($asset->custodian_id);
        $this->assertSame(1, (int) $asset->location_id);

        // Reassign to Bruno — a NEW row, the old one untouched.
        $second = $this->assignAccepted($asset, 2, 3);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, FixedAssetAssignment::where('asset_id', $asset->id)->count());
        $this->assertSame('returned', $first->fresh()->status);
        $this->assertSame('assigned', $asset->fresh()->status);
        $this->assertSame(2, (int) $asset->fresh()->custodian_id);
    }

    public function test_assignment_on_a_draft_asset_is_refused(): void
    {
        $acquisition = $this->createDraftViaForm();
        $asset = $acquisition->asset;

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-assignments.store'), $this->assignmentPayload($asset->id))
            ->assertRedirect();

        $this->assertSame(0, FixedAssetAssignment::where('asset_id', $asset->id)->count());
        $this->assertSame('draft', $asset->fresh()->status);
    }

    public function test_assignment_create_form_lists_available_assets_only(): void
    {
        $capitalized = $this->createCapitalizedAsset();
        $draft = $this->createDraftViaForm()->asset;

        $this->actingAs(User::findOrFail(1))
            ->get(route('fixed-asset-assignments.create'))
            ->assertOk()
            ->assertSee($capitalized->code)
            // The draft code still shows in the flash toast from
            // its creation; assert its *option label* is absent.
            ->assertDontSee($draft->code.' — '.$draft->name);
    }

    public function test_accept_denied_for_a_user_who_is_not_the_custodian(): void
    {
        $asset = $this->createCapitalizedAsset();

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-assignments.store'), $this->assignmentPayload($asset->id))
            ->assertRedirect();
        $assignment = FixedAssetAssignment::orderByDesc('id')->firstOrFail();
        $this->actingAs(User::findOrFail(3))
            ->post(route('fixed-asset-assignments.decide', $assignment->id), ['decision' => 'approved'])
            ->assertRedirect();
        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-assignments.handover', $assignment->id))
            ->assertRedirect();

        // User 6 is neither an officer nor the custodian.
        $this->actingAs(User::findOrFail(6))
            ->post(route('fixed-asset-assignments.accept', $assignment->id))
            ->assertRedirect();
        $this->assertSame('handed_over', $assignment->fresh()->status);
        $this->assertSame('available', $asset->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Transfer workflow over HTTP
    // ------------------------------------------------------------------

    public function test_transfer_happy_path_over_http_moves_the_asset_only_on_receipt(): void
    {
        $asset = $this->createCapitalizedAsset();
        $this->assignAccepted($asset);

        $scheduleBefore = \App\Models\FixedAssetDepreciationSchedule::where('asset_id', $asset->id)->orderBy('period')->get();
        $this->assertSame(60, $scheduleBefore->count());
        $firstRowBefore = $scheduleBefore->first();

        // Requested by the company user; the asset goes in_transfer
        // but its location / custodian do NOT move yet.
        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-transfers.store'), $this->transferPayload($asset->id))
            ->assertRedirect();
        $transfer = FixedAssetTransfer::orderByDesc('id')->firstOrFail();
        $this->assertSame('requested', $transfer->status);
        $this->assertSame('in_transfer', $asset->fresh()->status);
        $this->assertSame(1, (int) $asset->fresh()->location_id);
        $this->assertSame(1, (int) $asset->fresh()->custodian_id);

        // Approved by a different user; handover; still not moved.
        $this->actingAs(User::findOrFail(3))
            ->post(route('fixed-asset-transfers.decide', $transfer->id), ['decision' => 'approved', 'reason' => 'OK'])
            ->assertRedirect();
        $this->assertSame('approved', $transfer->fresh()->status);
        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-transfers.handover', $transfer->id))
            ->assertRedirect();
        $this->assertSame('handed_over', $transfer->fresh()->status);
        $this->assertSame(1, (int) $asset->fresh()->location_id);

        // Received at the destination — NOW the asset moves.
        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-transfers.receive', $transfer->id))
            ->assertRedirect();

        $transfer = $transfer->fresh();
        $this->assertSame('received', $transfer->status);
        $this->assertSame('2026-11-07', $transfer->effective_date->format('Y-m-d'));

        $asset = $asset->fresh();
        $this->assertSame('assigned', $asset->status);
        $this->assertSame(2, (int) $asset->location_id);
        $this->assertSame(2, (int) $asset->custodian_id);
        $this->assertSame(2, (int) $asset->cost_centre_id);

        // The previous assignment is closed by the transfer, with
        // its history kept.
        $oldAssignment = FixedAssetAssignment::where('asset_id', $asset->id)->orderBy('id')->firstOrFail();
        $this->assertSame('returned', $oldAssignment->status);
        $this->assertSame('Transferred', $oldAssignment->return_reason);

        // The depreciation schedule is untouched by the transfer.
        $scheduleAfter = \App\Models\FixedAssetDepreciationSchedule::where('asset_id', $asset->id)->orderBy('period')->get();
        $this->assertSame(60, $scheduleAfter->count());
        $this->assertSame(0, bccomp((string) $firstRowBefore->period_depreciation, (string) $scheduleAfter->first()->period_depreciation, 4));
        $this->assertSame(0, bccomp((string) $firstRowBefore->closing_book_value, (string) $scheduleAfter->first()->closing_book_value, 4));
        $this->assertSame($firstRowBefore->period->format('Y-m-d'), $scheduleAfter->first()->period->format('Y-m-d'));

        // Status history records in_transfer → assigned.
        $this->assertSame(1, \App\Models\FixedAssetStatusHistory::where('asset_id', $asset->id)->where('from_status', 'in_transfer')->where('to_status', 'assigned')->count());
    }

    public function test_transfer_self_approval_is_refused(): void
    {
        $asset = $this->createCapitalizedAsset();

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-transfers.store'), $this->transferPayload($asset->id))
            ->assertRedirect();
        $transfer = FixedAssetTransfer::orderByDesc('id')->firstOrFail();

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-transfers.decide', $transfer->id), ['decision' => 'approved'])
            ->assertRedirect();
        $this->assertSame('requested', $transfer->fresh()->status);
        $approval = FixedAssetApproval::where('transaction_type', FixedAssetApproval::TYPE_TRANSFER)->where('transaction_id', $transfer->id)->firstOrFail();
        $this->assertSame('pending', $approval->decision);
    }

    public function test_transfer_decide_denied_without_the_transfer_permission(): void
    {
        $asset = $this->createCapitalizedAsset();

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-transfers.store'), $this->transferPayload($asset->id))
            ->assertRedirect();
        $transfer = FixedAssetTransfer::orderByDesc('id')->firstOrFail();

        // Operator (user 2) has manage fixed assets but not the
        // transfer-approval permission.
        $this->actingAs(User::findOrFail(2))
            ->post(route('fixed-asset-transfers.decide', $transfer->id), ['decision' => 'approved'])
            ->assertRedirect();
        $this->assertSame('requested', $transfer->fresh()->status);
    }

    public function test_transfer_rejection_restores_the_previous_state(): void
    {
        $asset = $this->createCapitalizedAsset();
        $this->assignAccepted($asset);

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-transfers.store'), $this->transferPayload($asset->id))
            ->assertRedirect();
        $transfer = FixedAssetTransfer::orderByDesc('id')->firstOrFail();
        $this->actingAs(User::findOrFail(3))
            ->post(route('fixed-asset-transfers.decide', $transfer->id), ['decision' => 'rejected', 'reason' => 'No room in WH2'])
            ->assertRedirect();

        $this->assertSame('rejected', $transfer->fresh()->status);
        $asset = $asset->fresh();
        $this->assertSame('assigned', $asset->status);
        $this->assertSame(1, (int) $asset->location_id);
        $this->assertSame(1, (int) $asset->custodian_id);
        $this->assertSame(1, (int) $asset->cost_centre_id);
    }

    public function test_transfer_cancel_restores_the_previous_state(): void
    {
        $asset = $this->createCapitalizedAsset();

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-transfers.store'), $this->transferPayload($asset->id))
            ->assertRedirect();
        $transfer = FixedAssetTransfer::orderByDesc('id')->firstOrFail();
        $this->assertSame('in_transfer', $asset->fresh()->status);

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-transfers.cancel', $transfer->id))
            ->assertRedirect();

        $this->assertSame('cancelled', $transfer->fresh()->status);
        $this->assertSame('available', $asset->fresh()->status);
        $this->assertNull($asset->fresh()->location_id);
    }

    public function test_transfer_with_no_change_is_refused(): void
    {
        $asset = $this->createCapitalizedAsset();

        // Same location, same custodian (none), same cost centre
        // (none — the asset has none yet) = no move at all.
        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-transfers.store'), $this->transferPayload($asset->id, [
                'to_location_id' => null,
                'to_custodian_id' => null,
                'to_cost_centre_id' => null,
            ]))
            ->assertRedirect();

        $this->assertSame(0, FixedAssetTransfer::where('asset_id', $asset->id)->count());
        $this->assertSame('available', $asset->fresh()->status);
    }

    public function test_second_transfer_while_one_is_active_is_refused(): void
    {
        $asset = $this->createCapitalizedAsset();

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-transfers.store'), $this->transferPayload($asset->id))
            ->assertRedirect();
        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-transfers.store'), $this->transferPayload($asset->id, ['to_location_id' => 1]))
            ->assertRedirect();

        $this->assertSame(1, FixedAssetTransfer::where('asset_id', $asset->id)->count());
    }

    // ------------------------------------------------------------------
    // Service-layer guards
    // ------------------------------------------------------------------

    public function test_closed_assets_refuse_assignment_and_transfer_in_the_service_layer(): void
    {
        $asset = $this->createCapitalizedAsset();

        foreach (['disposed', 'written_off', 'lost', 'stolen', 'damaged'] as $status) {
            $asset->update(['status' => $status]);

            try {
                $this->assignments->requestAssignment($asset->fresh(), ['custodian_id' => 1], 1, 1);
                $this->fail("Assignment should be refused for a {$status} asset.");
            } catch (FixedAssetException $e) {
                $this->assertStringContainsString('can never be assigned again', $e->getMessage());
            }

            try {
                $this->transfers->requestTransfer($asset->fresh(), ['to_location_id' => 2], 1, 1);
                $this->fail("Transfer should be refused for a {$status} asset.");
            } catch (FixedAssetException $e) {
                $this->assertStringContainsString('can never be transferred again', $e->getMessage());
            }
        }

        $this->assertSame(0, FixedAssetAssignment::where('asset_id', $asset->id)->count());
        $this->assertSame(0, FixedAssetTransfer::where('asset_id', $asset->id)->count());
    }

    public function test_draft_asset_refuses_transfer_in_the_service_layer(): void
    {
        $asset = $this->createDraftViaForm()->asset;

        $this->expectException(FixedAssetException::class);
        $this->transfers->requestTransfer($asset, ['to_location_id' => 2], 1, 1);
    }

    // ------------------------------------------------------------------
    // Permissions, cross-company, screens, routes
    // ------------------------------------------------------------------

    public function test_assignment_and_transfer_show_denied_for_another_company(): void
    {
        $asset = $this->createCapitalizedAsset();
        $assignment = $this->assignAccepted($asset);

        $this->actingAs(User::findOrFail(4))
            ->get(route('fixed-asset-assignments.show', $assignment->id))
            ->assertRedirect();
        $this->actingAs(User::findOrFail(6))
            ->get(route('fixed-asset-assignments.show', $assignment->id))
            ->assertRedirect();
    }

    public function test_assignment_and_transfer_indexes_load(): void
    {
        $asset = $this->createCapitalizedAsset();
        $this->assignAccepted($asset);

        $this->actingAs(User::findOrFail(1))
            ->get(route('fixed-asset-assignments.index'))
            ->assertOk()
            ->assertSee($asset->code);

        $this->actingAs(User::findOrFail(1))
            ->get(route('fixed-asset-transfers.index'))
            ->assertOk()
            ->assertSee(__('No transfers found.'));
    }

    public function test_asset_show_page_timeline_shows_assignment_and_transfer(): void
    {
        $asset = $this->createCapitalizedAsset();
        $this->assignAccepted($asset);

        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-transfers.store'), $this->transferPayload($asset->id))
            ->assertRedirect();
        $transfer = FixedAssetTransfer::orderByDesc('id')->firstOrFail();
        $this->actingAs(User::findOrFail(3))
            ->post(route('fixed-asset-transfers.decide', $transfer->id), ['decision' => 'approved'])
            ->assertRedirect();
        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-transfers.handover', $transfer->id))
            ->assertRedirect();
        $this->actingAs(User::findOrFail(1))
            ->post(route('fixed-asset-transfers.receive', $transfer->id))
            ->assertRedirect();

        $this->actingAs(User::findOrFail(1))
            ->get(route('fixed-assets.show', $asset->id))
            ->assertOk()
            ->assertSee('History Timeline')
            ->assertSee('Assignment accepted')
            ->assertSee('Transfer received')
            ->assertSee('Transfer requested');
    }

    public function test_phase2_routes_resolve(): void
    {
        foreach ([
            'fixed-asset-assignments.index', 'fixed-asset-assignments.create', 'fixed-asset-assignments.store',
            'fixed-asset-assignments.show', 'fixed-asset-assignments.decide', 'fixed-asset-assignments.handover',
            'fixed-asset-assignments.accept', 'fixed-asset-assignments.refuse', 'fixed-asset-assignments.return',
            'fixed-asset-assignments.cancel',
            'fixed-asset-transfers.index', 'fixed-asset-transfers.create', 'fixed-asset-transfers.store',
            'fixed-asset-transfers.show', 'fixed-asset-transfers.decide', 'fixed-asset-transfers.handover',
            'fixed-asset-transfers.receive', 'fixed-asset-transfers.reject', 'fixed-asset-transfers.cancel',
        ] as $name) {
            $this->assertTrue(Route::has($name), "Route {$name} is missing.");
        }
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
            $table->string('code', 50);
            $table->string('name');
            $table->string('status', 20)->default('active');
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
