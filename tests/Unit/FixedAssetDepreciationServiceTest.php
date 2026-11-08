<?php

namespace Tests\Unit;

use App\Exceptions\FixedAssetException;
use App\Models\FixedAsset;
use App\Models\FixedAssetAcquisition;
use App\Models\FixedAssetApproval;
use App\Models\FixedAssetDepreciationEntry;
use App\Models\FixedAssetDepreciationRun;
use App\Models\FixedAssetDepreciationSchedule;
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
 * Fixed Assets Phase 3 — depreciation service, unit level
 * (plan §5, §10; spec §13, §14).
 *
 * Covers the service directly (not through HTTP): preview
 * computation (schedule amounts, final-period 2dp
 * adjustment), cost-centre split math (single centre and
 * mid-period transfer), duplicate-period guard, closed-year
 * block, fully-depreciated stop, journal accounts + balance,
 * residual-exact close. The capitalized-asset fixture is
 * built through the same HTTP acquisition flow the feature
 * test uses, so the schedule shell is the real Phase 1 shell.
 *
 * Users: 1 = Company, 3 = Approver (post fixed asset
 * depreciation). "Today" is frozen at 2026-11-07. The
 * capitalized asset costs 47,000 over 60 months (783.3333 /
 * month, first period 2026-11-01).
 */
class FixedAssetDepreciationServiceTest extends TestCase
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

    /** Drive a run for one period to POSTED through the service; returns it. */
    protected function postPeriod(string $period, int $submitterId = 1, int $approverId = 3): FixedAssetDepreciationRun
    {
        $run = $this->depreciation->createRun(1, $submitterId, $period);
        $this->depreciation->reviewRun($run, 1, $submitterId);
        $this->depreciation->submitForApproval($run, 1, $submitterId);
        $this->depreciation->approveRun($run, 1, $approverId, 'OK');
        $this->depreciation->postRun($run, 1, $submitterId);

        $this->assertSame('posted', $run->fresh()->status);

        return $run->fresh();
    }

    /** Drive a transfer to "received" through the service, then force its received date. */
    protected function receiveTransferOn(FixedAsset $asset, string $receivedDate, int $toCostCentreId = 2): void
    {
        $transfers = new \App\Services\FixedAssetTransferService($this->service);
        $transfer = $transfers->requestTransfer($asset, [
            'to_location_id' => 2,
            'to_custodian_id' => 2,
            'to_cost_centre_id' => $toCostCentreId,
            'reason' => 'Moved to Warehouse 2',
        ], 1, 1);
        $approval = FixedAssetApproval::where('created_by', 1)
            ->where('transaction_type', FixedAssetApproval::TYPE_TRANSFER)
            ->where('transaction_id', $transfer->id)
            ->where('decision', 'pending')
            ->firstOrFail();
        $transfers->decideTransferApproval($approval, 3, 'approved', 'OK');
        // decideTransferApproval updates the transfer through its
        // own instance — this one is stale until refreshed.
        $transfer = $transfer->fresh();
        $transfers->handOverTransfer($transfer, 1, 1);
        $transfers->receiveTransfer($transfer, 1, 1);

        $this->assertSame('received', $transfer->fresh()->status);

        DB::table('fixed_asset_transfers')->where('id', $transfer->id)->update(['received_at' => $receivedDate.' 09:00:00']);
    }

    // ------------------------------------------------------------------
    // Preview computation
    // ------------------------------------------------------------------

    public function test_compute_lines_returns_schedule_amounts_without_writing(): void
    {
        $asset = $this->createCapitalizedAsset();

        $lines = $this->depreciation->computeLines(1, Carbon::parse('2026-11-01'));

        $this->assertCount(1, $lines);
        $this->assertSame((int) $asset->id, (int) $lines[0]['asset']->id);
        $this->assertSame('783.3333', (string) $lines[0]['planned']);
        $this->assertSame('783.33', (string) $lines[0]['amount']);
        $this->assertFalse($lines[0]['is_last']);
        $this->assertSame(5, (int) $lines[0]['expense_account_id']);
        $this->assertSame(2, (int) $lines[0]['accum_account_id']);
        $this->assertSame(0, FixedAssetDepreciationRun::count());
        $this->assertSame(0, DB::table('journal_entries')->where('reference', 'like', 'FA-DEP-%')->count());
    }

    public function test_final_period_before_earlier_months_posts_only_its_own_amount(): void
    {
        // Regression: the final row's remainder is (base - posted
        // so far). Posted out of order (Jan before Nov/Dec) that
        // remainder is the WHOLE base — posting it would
        // over-depreciate. Until the earlier rows post, the final
        // row posts only its own rounded monthly amount.
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

        $run = $this->postPeriod('2027-01');
        $this->assertSame('16666.67', (string) $run->total_amount);
        $this->assertSame('33333.33', number_format((float) $asset->fresh()->current_book_value, 2, '.', ''));
        // Unassigned after capitalizing, and NOT fully depreciated:
        // two months are still outstanding.
        $this->assertSame('available', $asset->fresh()->status);
    }

    public function test_compute_lines_skips_periods_with_nothing_due(): void
    {
        $this->createCapitalizedAsset();

        $this->assertSame([], $this->depreciation->computeLines(1, Carbon::parse('2026-10-01')));
        $this->assertSame([], $this->depreciation->computeLines(1, Carbon::parse('2032-01-01')));
    }

    public function test_final_period_posts_the_exact_remainder(): void
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

        $lines = $this->depreciation->computeLines(1, Carbon::parse('2027-01-01'));
        $this->assertCount(1, $lines);
        $this->assertTrue($lines[0]['is_last']);
        $this->assertSame('16666.6668', (string) $lines[0]['planned']);
        $this->assertSame('16666.67', (string) $lines[0]['amount']);

        $this->postPeriod('2026-11');
        $this->postPeriod('2026-12');

        // After two postings the final line is the exact remainder.
        $lines = $this->depreciation->computeLines(1, Carbon::parse('2027-01-01'));
        $this->assertSame('16666.66', (string) $lines[0]['amount']);

        $run = $this->postPeriod('2027-01');
        $this->assertSame('16666.66', (string) $run->total_amount);
        $this->assertSame('50000.00', number_format((float) FixedAssetDepreciationEntry::where('asset_id', $asset->id)->sum('posted_amount'), 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $asset->fresh()->current_book_value, 2, '.', ''));
        $this->assertSame('fully_depreciated', $asset->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Cost-centre split
    // ------------------------------------------------------------------

    public function test_single_centre_split_is_100_percent(): void
    {
        $asset = $this->createCapitalizedAsset();

        $split = $this->depreciation->costCentreSplitForPeriod(
            $asset->fresh(),
            Carbon::parse('2026-11-01'),
            Carbon::parse('2026-11-30'),
            1
        );

        $this->assertCount(1, $split);
        $this->assertSame(1, (int) $split[0]['cost_centre_id']);
        $this->assertSame('100.000000', (string) $split[0]['percentage']);
        $this->assertSame('LOG', $split[0]['cost_centre_code']);
    }

    public function test_mid_period_transfer_splits_by_days(): void
    {
        $asset = $this->createCapitalizedAsset();
        $this->receiveTransferOn($asset, '2026-11-16', 2);

        $split = $this->depreciation->costCentreSplitForPeriod(
            $asset->fresh(),
            Carbon::parse('2026-11-01'),
            Carbon::parse('2026-11-30'),
            1
        );

        $this->assertCount(2, $split);
        $this->assertSame(1, (int) $split[0]['cost_centre_id']);
        $this->assertSame('50.000000', (string) $split[0]['percentage']);
        $this->assertSame(2, (int) $split[1]['cost_centre_id']);
        $this->assertSame('50.000000', (string) $split[1]['percentage']);
    }

    public function test_asset_without_a_cost_centre_has_no_split(): void
    {
        $asset = $this->createCapitalizedAsset();
        $asset->update(['cost_centre_id' => null]);

        $split = $this->depreciation->costCentreSplitForPeriod(
            $asset->fresh(),
            Carbon::parse('2026-11-01'),
            Carbon::parse('2026-11-30'),
            1
        );

        $this->assertSame([], $split);
    }

    // ------------------------------------------------------------------
    // Guards
    // ------------------------------------------------------------------

    public function test_create_run_twice_for_a_period_is_refused(): void
    {
        $this->createCapitalizedAsset();
        $this->depreciation->createRun(1, 1, '2026-11');

        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('already exists');
        $this->depreciation->createRun(1, 1, '2026-11');
    }

    public function test_create_run_with_nothing_due_is_refused(): void
    {
        $this->createCapitalizedAsset();

        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('No assets have depreciation due');
        $this->depreciation->createRun(1, 1, '2026-10');
    }

    public function test_approve_run_by_the_requester_is_refused(): void
    {
        $this->createCapitalizedAsset();
        $run = $this->depreciation->createRun(1, 1, '2026-11');
        $this->depreciation->reviewRun($run, 1, 1);
        $this->depreciation->submitForApproval($run, 1, 1);

        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('cannot approve a transaction you requested yourself');
        $this->depreciation->approveRun($run, 1, 1, 'OK');
    }

    public function test_posting_a_closed_year_is_blocked(): void
    {
        $this->createCapitalizedAsset();
        $run = $this->depreciation->createRun(1, 1, '2026-12');
        $this->depreciation->reviewRun($run, 1, 1);
        $this->depreciation->submitForApproval($run, 1, 1);
        $this->depreciation->approveRun($run, 1, 3, 'OK');

        DB::table('journal_entries')->insert([
            'journal_id' => 99, 'date' => '2026-12-31', 'reference' => 'FY-2026-CLOSE-1',
            'description' => 'Year-end closing', 'status' => 1, 'created_by' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('already closed');
        $this->depreciation->postRun($run, 1, 1);
    }

    public function test_posting_twice_is_refused_by_the_unique_key(): void
    {
        $this->createCapitalizedAsset();
        $this->postPeriod('2026-11');

        // The run exists and is posted; a second run for the period
        // is refused before it ever reaches the unique key.
        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('already exists');
        $this->depreciation->createRun(1, 1, '2026-11');
    }

    public function test_disposed_asset_is_excluded_from_later_periods(): void
    {
        $asset = $this->createCapitalizedAsset();
        $asset->update(['status' => \App\Models\FixedAsset::STATUS_DISPOSED]);

        $this->assertSame([], $this->depreciation->computeLines(1, Carbon::parse('2026-11-01')));
    }

    public function test_invalid_period_is_refused(): void
    {
        $this->expectException(FixedAssetException::class);
        $this->depreciation->periodDate('2026-13');
    }

    // ------------------------------------------------------------------
    // Posting + report
    // ------------------------------------------------------------------

    public function test_post_run_writes_one_balanced_journal_and_updates_the_asset(): void
    {
        $asset = $this->createCapitalizedAsset();
        $run = $this->postPeriod('2026-11');

        $journal = DB::table('journal_entries')->where('id', $run->journal_id)->first();
        $this->assertNotNull($journal);
        $this->assertSame('FA-DEP-2026-11', $journal->reference);
        $debit = DB::table('journal_items')->where('journal', $journal->id)->sum('debit');
        $credit = DB::table('journal_items')->where('journal', $journal->id)->sum('credit');
        $this->assertSame('783.33', (string) $debit);
        $this->assertSame('783.33', (string) $credit);

        $row = FixedAssetDepreciationSchedule::where('asset_id', $asset->id)
            ->whereDate('period', '2026-11-01')->firstOrFail();
        $this->assertSame('posted', $row->status);
        $this->assertSame('46216.67', (string) $asset->fresh()->current_book_value);
    }

    public function test_monthly_totals_report(): void
    {
        $this->createCapitalizedAsset();

        $before = $this->depreciation->monthlyTotals(1, '2026-11');
        $this->assertFalse($before['period_posted']);
        $this->assertSame('783.33', (string) $before['period_total']);
        $this->assertSame('0.00', (string) $before['ytd_posted']);

        $this->postPeriod('2026-11');

        $after = $this->depreciation->monthlyTotals(1, '2026-11');
        $this->assertTrue($after['period_posted']);
        $this->assertSame('783.33', (string) $after['period_total']);
        $this->assertSame('783.33', (string) $after['ytd_posted']);
        // 59 planned rows remain: 59 × 783.3333 = 46,216.6667.
        $this->assertSame('46216.6667', (string) $after['remaining_planned']);
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
