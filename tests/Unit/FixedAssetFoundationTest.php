<?php

namespace Tests\Unit;

use App\Exceptions\FixedAssetException;
use App\Models\FixedAsset;
use App\Models\FixedAssetApproval;
use App\Models\FixedAssetAuditEvent;
use App\Models\FixedAssetCategory;
use App\Models\FixedAssetStatusHistory;
use App\Services\FixedAssetNumberingService;
use App\Services\FixedAssetService;
use App\Services\FixedAssetSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fixed Assets Phase 0 — foundation (plan §5, §10, §12b).
 *
 * Covers: numbering per company/category/year (never reused),
 * category + location master data, settings defaults + save,
 * approval decisions with the no-self-approval rule, status
 * history + audit trail (append-only), and cost-centre reuse
 * (the Cost Centre module's table, not a new one).
 *
 * Users: 1 = Company ("Hoya Hoya" — prefix HOYA), 2 = Operator,
 * 4 = Other Company ("Uthara"). "Today" is frozen at 2026-11-07.
 */
class FixedAssetFoundationTest extends TestCase
{
    protected FixedAssetService $service;
    protected FixedAssetNumberingService $numbering;

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
        ]);

        $this->service = new FixedAssetService();
        $this->numbering = new FixedAssetNumberingService();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        foreach (['fixed_asset_audit_events', 'fixed_asset_status_history', 'fixed_asset_approvals', 'fixed_asset_documents', 'fixed_assets', 'fixed_asset_locations', 'fixed_asset_categories', 'cost_centres', 'branches', 'settings', 'users'] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Numbering
    // ------------------------------------------------------------------

    public function test_numbering_allocates_per_company_category_year_and_never_reuses(): void
    {
        // First allocation for a fresh head is 0001.
        $this->assertSame('HOYA-IT-2026-0001', $this->numbering->nextCode(1, 'IT', '2026'));

        // Once the number is taken (the row exists), the next
        // allocation moves on — a number is never reused.
        $this->numbering->allocateAndCreate(1, 'IT', [
            'category_id' => 1, 'name' => 'Laptop', 'status' => FixedAsset::STATUS_DRAFT,
        ]);
        $this->assertSame('HOYA-IT-2026-0002', $this->numbering->nextCode(1, 'IT', '2026'));

        // A different category has its own sequence.
        $this->assertSame('HOYA-FUR-2026-0001', $this->numbering->nextCode(1, 'FUR', '2026'));
        // A different year restarts the sequence.
        $this->assertSame('HOYA-IT-2027-0001', $this->numbering->nextCode(1, 'IT', '2027'));
        // A different company has its own sequence (prefix from name).
        $this->assertSame('UTHA-IT-2026-0001', $this->numbering->nextCode(4, 'IT', '2026'));
    }

    public function test_numbering_never_reuses_a_code_after_an_asset_exists(): void
    {
        $asset = $this->numbering->allocateAndCreate(1, 'IT', [
            'category_id' => 1,
            'name' => 'Laptop',
            'status' => FixedAsset::STATUS_DRAFT,
        ]);

        $this->assertSame('HOYA-IT-2026-0001', $asset->code);

        // The next allocation skips the used number.
        $this->assertSame('HOYA-IT-2026-0002', $this->numbering->nextCode(1, 'IT', '2026'));

        // Even after the asset is soft-deleted, its code stays burned.
        $asset->delete();
        $this->assertSame('HOYA-IT-2026-0002', $this->numbering->nextCode(1, 'IT', '2026'));
    }

    public function test_numbering_uses_the_configured_prefix(): void
    {
        FixedAssetSettings::save(1, [FixedAssetSettings::KEY_REFERENCE_PREFIX => 'acme']);

        $this->assertSame('ACME-IT-2026-0001', $this->numbering->nextCode(1, 'it', '2026'));
    }

    public function test_allocate_and_create_is_atomic(): void
    {
        $asset = $this->numbering->allocateAndCreate(1, 'VEH', [
            'category_id' => 2,
            'name' => 'Pickup',
            'purchase_cost' => '3000000.0000',
            'currency' => 'MZN',
            'exchange_rate' => '1.0000000000',
            'amount_base' => '3000000.0000',
            'status' => FixedAsset::STATUS_DRAFT,
        ]);

        $this->assertSame('HOYA-VEH-2026-0001', $asset->code);
        $this->assertSame(1, (int) $asset->created_by);
        $this->assertSame(1, (int) $asset->company_id);
    }

    // ------------------------------------------------------------------
    // Categories
    // ------------------------------------------------------------------

    public function test_create_category_and_duplicate_code_is_refused_per_company(): void
    {
        $category = $this->service->createCategory([
            'code' => 'it',
            'name' => 'IT Equipment',
            'classification' => 'tangible',
            'default_useful_life' => 5,
            'default_life_unit' => 'year',
            'depreciation_method' => 'straight_line',
            'residual_value_type' => 'none',
        ], 1, 1);

        $this->assertSame('IT', $category->code);
        $this->assertSame(5, (int) $category->default_useful_life);
        $this->assertSame(1, (int) $category->created_by);

        // Same code, same company → refused.
        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('already exists');
        $this->service->createCategory(['code' => 'IT', 'name' => 'Other'], 1, 1);
    }

    public function test_same_category_code_is_allowed_for_another_company(): void
    {
        $this->service->createCategory(['code' => 'IT', 'name' => 'IT Equipment'], 1, 1);

        $other = $this->service->createCategory(['code' => 'IT', 'name' => 'IT Equipment'], 4, 4);

        $this->assertSame('IT', $other->code);
        $this->assertSame(4, (int) $other->created_by);
    }

    public function test_deactivated_category_code_stays_burned(): void
    {
        $category = $this->service->createCategory(['code' => 'FUR', 'name' => 'Furniture'], 1, 1);
        $this->service->deactivateCategory($category, 1, 1);

        $this->assertSoftDeleted('fixed_asset_categories', ['id' => $category->id]);

        $this->expectException(FixedAssetException::class);
        $this->service->createCategory(['code' => 'FUR', 'name' => 'Furniture again'], 1, 1);
    }

    public function test_v2_depreciation_method_is_refused(): void
    {
        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('Only straight-line');
        $this->service->createCategory([
            'code' => 'IT', 'name' => 'IT Equipment',
            'depreciation_method' => 'reducing_balance',
        ], 1, 1);
    }

    public function test_update_category_rejects_a_code_taken_by_a_sibling(): void
    {
        $first = $this->service->createCategory(['code' => 'IT', 'name' => 'IT Equipment'], 1, 1);
        $second = $this->service->createCategory(['code' => 'VEH', 'name' => 'Vehicles'], 1, 1);

        $this->expectException(FixedAssetException::class);
        $this->service->updateCategory($second, ['code' => 'IT', 'name' => 'Vehicles'], 1, 1);
    }

    public function test_category_update_keeps_its_own_code(): void
    {
        $category = $this->service->createCategory(['code' => 'IT', 'name' => 'IT Equipment'], 1, 1);

        $updated = $this->service->updateCategory($category, ['code' => 'IT', 'name' => 'IT & Office Equipment'], 1, 1);

        $this->assertSame('IT & Office Equipment', $updated->name);
    }

    // ------------------------------------------------------------------
    // Locations
    // ------------------------------------------------------------------

    public function test_create_location_and_duplicate_code_is_refused(): void
    {
        $location = $this->service->createLocation(['code' => 'WH1', 'name' => 'Warehouse 1', 'room' => 'A1'], 1, 1);

        $this->assertSame('WH1', $location->code);
        $this->assertSame('A1', $location->room);

        $this->expectException(FixedAssetException::class);
        $this->service->createLocation(['code' => 'WH1', 'name' => 'Warehouse again'], 1, 1);
    }

    public function test_deactivate_location_blocked_while_assets_use_it(): void
    {
        $location = $this->service->createLocation(['code' => 'WH1', 'name' => 'Warehouse 1'], 1, 1);

        FixedAsset::create([
            'created_by' => 1, 'company_id' => 1, 'category_id' => 1,
            'location_id' => $location->id, 'code' => 'HOYA-IT-2026-0001',
            'name' => 'Laptop', 'status' => FixedAsset::STATUS_DRAFT,
        ]);

        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('still used by assets');
        $this->service->deactivateLocation($location, 1, 1);
    }

    public function test_deactivate_unused_location_works(): void
    {
        $location = $this->service->createLocation(['code' => 'WH1', 'name' => 'Warehouse 1'], 1, 1);

        $this->service->deactivateLocation($location, 1, 1);

        $this->assertSoftDeleted('fixed_asset_locations', ['id' => $location->id]);
    }

    // ------------------------------------------------------------------
    // Settings (D2/D5/D7/D8)
    // ------------------------------------------------------------------

    public function test_settings_defaults(): void
    {
        $settings = FixedAssetSettings::forCompany(1);

        $this->assertSame('MZN', $settings[FixedAssetSettings::KEY_BASE_CURRENCY]);
        $this->assertSame('', $settings[FixedAssetSettings::KEY_REFERENCE_PREFIX]);
        $this->assertSame('', $settings[FixedAssetSettings::KEY_CLEARING_ACCOUNT_CODE]);
        $this->assertSame('standard', $settings[FixedAssetSettings::KEY_LABEL_SIZE]);
        $this->assertSame('100000', $settings[FixedAssetSettings::KEY_APPROVAL_THRESHOLD]);
        $this->assertSame(['width' => 80, 'height' => 50], FixedAssetSettings::labelDimensions(1));
    }

    public function test_settings_save_normalises_and_falls_back_on_invalid_values(): void
    {
        $saved = FixedAssetSettings::save(1, [
            FixedAssetSettings::KEY_BASE_CURRENCY => 'mzn',
            FixedAssetSettings::KEY_REFERENCE_PREFIX => '  hoya  ',
            FixedAssetSettings::KEY_CLEARING_ACCOUNT_CODE => '471',
            FixedAssetSettings::KEY_LABEL_SIZE => 'huge',
            FixedAssetSettings::KEY_APPROVAL_THRESHOLD => '-5',
        ]);

        $this->assertSame('MZN', $saved[FixedAssetSettings::KEY_BASE_CURRENCY]);
        $this->assertSame('hoya', $saved[FixedAssetSettings::KEY_REFERENCE_PREFIX]);
        $this->assertSame('471', $saved[FixedAssetSettings::KEY_CLEARING_ACCOUNT_CODE]);
        $this->assertSame('standard', $saved[FixedAssetSettings::KEY_LABEL_SIZE]);
        $this->assertSame('100000', $saved[FixedAssetSettings::KEY_APPROVAL_THRESHOLD]);
    }

    public function test_approval_levels_add_a_second_level_at_the_threshold(): void
    {
        $below = FixedAssetSettings::approvalLevels(1, '99999.9999');
        $this->assertCount(1, $below);

        $at = FixedAssetSettings::approvalLevels(1, '100000.0000');
        $this->assertCount(2, $at);
        $this->assertSame('approve fixed asset acquisitions', $at[1]['permission']);
        $this->assertSame('100000', $at[1]['min_amount_base']);
    }

    // ------------------------------------------------------------------
    // Approvals — no self-approval (spec §3, §24)
    // ------------------------------------------------------------------

    public function test_approval_request_and_decide_flow(): void
    {
        $asset = $this->makeAsset(1, 'HOYA-IT-2026-0001');

        $approval = $this->service->requestApproval($asset, FixedAssetApproval::TYPE_ACQUISITION, null, 2, '50000.0000', 1);

        $this->assertTrue($approval->isPending());
        $this->assertSame(2, (int) $approval->requested_by);

        $decided = $this->service->decideApproval($approval, 3, FixedAssetApproval::DECISION_APPROVED, 'Looks good.');

        $this->assertSame(FixedAssetApproval::DECISION_APPROVED, $decided->decision);
        $this->assertSame(3, (int) $decided->approver_id);
        $this->assertNotNull($decided->decided_at);
    }

    public function test_self_approval_is_refused(): void
    {
        $asset = $this->makeAsset(1, 'HOYA-IT-2026-0001');
        $approval = $this->service->requestApproval($asset, FixedAssetApproval::TYPE_ACQUISITION, null, 2, '50000.0000', 1);

        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('cannot approve a transaction you requested yourself');
        $this->service->decideApproval($approval, 2, FixedAssetApproval::DECISION_APPROVED);
    }

    public function test_decide_twice_is_refused(): void
    {
        $asset = $this->makeAsset(1, 'HOYA-IT-2026-0001');
        $approval = $this->service->requestApproval($asset, FixedAssetApproval::TYPE_ACQUISITION, null, 2, '50000.0000', 1);
        $this->service->decideApproval($approval, 3, FixedAssetApproval::DECISION_APPROVED);

        $this->expectException(FixedAssetException::class);
        $this->expectExceptionMessage('Only a pending approval');
        $this->service->decideApproval($approval->fresh(), 3, FixedAssetApproval::DECISION_REJECTED);
    }

    // ------------------------------------------------------------------
    // Status history + audit trail (append-only, spec §24)
    // ------------------------------------------------------------------

    public function test_status_change_writes_history_and_audit_and_updates_the_asset(): void
    {
        $asset = $this->makeAsset(1, 'HOYA-IT-2026-0001');

        $history = $this->service->recordStatusChange($asset, 'draft', FixedAsset::STATUS_AVAILABLE, 2, 'Registered.');

        $this->assertSame('draft', $history->from_status);
        $this->assertSame(FixedAsset::STATUS_AVAILABLE, $history->to_status);
        $this->assertSame(FixedAsset::STATUS_AVAILABLE, $asset->fresh()->status);
        $this->assertSame(1, FixedAssetStatusHistory::count());
        $this->assertSame(1, FixedAssetAuditEvent::where('action', 'status:available')->count());
    }

    public function test_invalid_status_is_refused(): void
    {
        $asset = $this->makeAsset(1, 'HOYA-IT-2026-0001');

        $this->expectException(FixedAssetException::class);
        $this->service->recordStatusChange($asset, 'draft', 'not_a_status', 2);
    }

    // ------------------------------------------------------------------
    // Cost-centre reuse (plan §12b D3, §12c)
    // ------------------------------------------------------------------

    public function test_asset_carries_a_cost_centre_from_the_cost_centre_module(): void
    {
        DB::table('cost_centres')->insert([
            'id' => 1, 'company_id' => 1, 'created_by' => 1,
            'code' => 'LOG', 'name' => 'Logistics', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $asset = $this->makeAsset(1, 'HOYA-IT-2026-0001', ['cost_centre_id' => 1]);

        $this->assertSame('LOG', $asset->fresh()->costCentre->code);
        $this->assertSame('Logistics', $asset->fresh()->costCentre->name);
    }

    public function test_cross_company_access_is_refused(): void
    {
        $category = $this->service->createCategory(['code' => 'IT', 'name' => 'IT Equipment'], 1, 1);

        $this->expectException(FixedAssetException::class);
        $this->service->updateCategory($category, ['code' => 'IT', 'name' => 'Changed'], 4, 4);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function makeAsset(int $creatorId, string $code, array $overrides = []): FixedAsset
    {
        return FixedAsset::create(array_merge([
            'created_by' => $creatorId,
            'company_id' => $creatorId,
            'category_id' => 1,
            'code' => $code,
            'name' => 'Test Asset',
            'status' => FixedAsset::STATUS_DRAFT,
        ], $overrides));
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
        Schema::create('branches', function ($table) {
            $table->bigIncrements('id');
            $table->string('name')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });
        // Minimal Cost Centre master (the real module's table) —
        // Phase 0 reuses it, never rebuilds it.
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
    }
}
