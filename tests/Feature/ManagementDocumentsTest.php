<?php

namespace Tests\Feature;

use App\Models\ManagementCostCentre;
use App\Models\ManagementDocument;
use App\Models\ManagementRule;
use App\Models\ManagementSupplierCategory;
use App\Models\User;
use Database\Seeders\ManagementReportHistorySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\SeedsFileDocuments;
use Tests\TestCase;

/**
 * Management Documents (Blade): CRUD, validation, supplier-default
 * auto-assign, rules/centres seeding, and the rewired P&L / Costs /
 * %P&L pages against the file's verified Jan–May 2026 numbers
 * (revenue 22,000; F&B 2,939.05; EBITDA 7,736.57).
 *
 * Page totals are asserted through the app's own priceFormat() (the
 * test settings are empty, so it formats with 0 decimals, e.g.
 * 22,000 -> "MZN22,000"); the exact 2dp totals are asserted in
 * ManagementDocumentCostsTest.
 */
class ManagementDocumentsTest extends TestCase
{
    use SeedsFileDocuments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createManagementSchema();
        $this->createRevenueSchema();
        ManagementRule::ensureSeeded(1);
        ManagementCostCentre::ensureSeeded(1);

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
            $table->primary(['permission_id', 'role_id']);
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

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::create(['name' => 'manage report', 'guard_name' => 'web']);

        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Company', 'email' => 'company@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 2, 'name' => 'No Permission', 'email' => 'noperm@test.local', 'type' => 'employee', 'created_by' => 1, 'default_company_id' => 1, 'email_verified_at' => now()],
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['manage report']);
        User::findOrFail(4)->givePermissionTo(['manage report']);
    }

    protected function tearDown(): void
    {
        $this->dropManagementSchema();
        foreach (['branches', 'branch_user', 'settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    private function ruleId(string $code, int $creatorId = 1): int
    {
        return ManagementRule::where('created_by', $creatorId)->where('code', $code)->firstOrFail()->id;
    }

    private function docPayload(array $overrides = []): array
    {
        return array_merge([
            'doc_date' => '2026-06-10',
            'supplier_name' => 'Test Supplier',
            'doc_number' => 'FT-001',
            'doc_type' => 'invoice',
            'base' => 100.00,
            'iva' => 16.00,
            'retention' => 0,
            'total' => 116.00,
            'status' => 'Conciliado',
            'management_rule_id' => $this->ruleId('312_01'),
        ], $overrides);
    }

    private function asCompany()
    {
        return $this->actingAs(User::findOrFail(1));
    }

    public function test_documents_crud_via_http(): void
    {
        $this->asCompany()
            ->post(route('management-documents.store'), $this->docPayload())
            ->assertRedirect(route('management-documents.index'));
        $this->assertDatabaseHas('management_documents', ['supplier_name' => 'Test Supplier', 'total' => 116.00]);

        $doc = ManagementDocument::where('supplier_name', 'Test Supplier')->firstOrFail();
        $this->assertEqualsWithDelta(100.0, $doc->netValue(), 0.01);
        $this->assertSame('CMVM Food', $doc->plGroup());
        $this->assertSame(6, $doc->month());

        $this->asCompany()->get(route('management-documents.index', ['year' => 2026, 'month' => 6]))
            ->assertOk()
            ->assertSee('Test Supplier');

        $this->asCompany()
            ->put(route('management-documents.update', $doc), $this->docPayload(['total' => 232.00, 'base' => 200.00, 'iva' => 32.00]))
            ->assertRedirect(route('management-documents.index'));
        $this->assertDatabaseHas('management_documents', ['id' => $doc->id, 'total' => 232.00]);

        $this->asCompany()
            ->delete(route('management-documents.destroy', $doc))
            ->assertRedirect(route('management-documents.index'));
        $this->assertDatabaseMissing('management_documents', ['id' => $doc->id]);
    }

    public function test_supplier_default_auto_assigns_category(): void
    {
        $this->asCompany()->post(route('management-rules.supplier-defaults.store'), [
            'supplier_name' => 'Auto Supplier',
            'management_rule_id' => $this->ruleId('632'),
        ])->assertRedirect(route('management-rules.index'));

        $payload = $this->docPayload(['supplier_name' => 'Auto Supplier']);
        unset($payload['management_rule_id']);

        $this->asCompany()->post(route('management-documents.store'), $payload)
            ->assertRedirect(route('management-documents.index'));
        $this->assertDatabaseHas('management_documents', [
            'supplier_name' => 'Auto Supplier',
            'management_rule_id' => $this->ruleId('632'),
        ]);
    }

    public function test_category_required_without_supplier_default(): void
    {
        $payload = $this->docPayload();
        unset($payload['management_rule_id']);

        $this->asCompany()->post(route('management-documents.store'), $payload)
            ->assertSessionHasErrors('management_rule_id');
    }

    public function test_total_must_equal_base_plus_iva_minus_retention(): void
    {
        $this->asCompany()->post(route('management-documents.store'), $this->docPayload(['total' => 200.00]))
            ->assertSessionHasErrors('total');
    }

    public function test_credit_note_must_be_negative(): void
    {
        $this->asCompany()->post(route('management-documents.store'), $this->docPayload([
            'doc_type' => 'credit_note', 'base' => 100.00, 'iva' => 0, 'total' => 100.00,
        ]))->assertSessionHasErrors('total');

        $this->asCompany()->post(route('management-documents.store'), $this->docPayload([
            'doc_type' => 'credit_note', 'doc_number' => 'CN-001', 'base' => -100.00, 'iva' => 0, 'total' => -100.00,
        ]))->assertRedirect(route('management-documents.index'));
        $this->assertDatabaseHas('management_documents', ['doc_number' => 'CN-001', 'total' => -100.00]);
    }

    public function test_manual_entry_needs_no_doc_number(): void
    {
        $payload = $this->docPayload(['doc_type' => 'manual']);
        unset($payload['doc_number']);

        $this->asCompany()->post(route('management-documents.store'), $payload)
            ->assertRedirect(route('management-documents.index'));
        $doc = ManagementDocument::where('doc_type', 'manual')->firstOrFail();
        $this->assertTrue($doc->isManual());
    }

    public function test_rules_are_seeded_and_deletion_is_blocked_in_use(): void
    {
        $this->asCompany()->get(route('management-rules.index'))
            ->assertOk()
            ->assertSee('6263 - Seguros');
        $this->assertSame(57, ManagementRule::where('created_by', 1)->count());

        $this->seedFileDocuments(1);
        $this->asCompany()
            ->delete(route('management-rules.destroy', $this->ruleId('632')))
            ->assertRedirect(route('management-rules.index'))
            ->assertSessionHas('error');
        $this->assertNotNull(ManagementRule::where('created_by', 1)->where('code', '632')->first());

        $this->asCompany()->post(route('management-rules.store'), [
            'code' => '9999', 'name' => 'Test Category', 'group' => 'Custos Fixos', 'subgroup' => 'Test', 'sort_order' => 99,
        ])->assertRedirect(route('management-rules.index'));
        $created = ManagementRule::where('created_by', 1)->where('code', '9999')->firstOrFail();
        $this->asCompany()->delete(route('management-rules.destroy', $created))
            ->assertRedirect(route('management-rules.index'));
        $this->assertDatabaseMissing('management_rules', ['id' => $created->id]);
    }

    public function test_cost_centres_are_seeded(): void
    {
        $this->asCompany()->get(route('management-rules.index'))
            ->assertOk()
            ->assertSee('Restaurante')
            ->assertSee('Group');
        $this->assertSame(['101 | Restaurante', '102 | Group'], ManagementCostCentre::where('created_by', 1)->orderBy('code')->get()->map->label()->all());
    }

    public function test_pages_require_manage_report_permission(): void
    {
        $this->actingAs(User::findOrFail(2))->get(route('management-documents.index'))->assertForbidden();
        $this->actingAs(User::findOrFail(2))->get(route('management-reports.pl'))->assertForbidden();
    }

    public function test_other_company_sees_no_documents(): void
    {
        $this->asCompany()->post(route('management-documents.store'), $this->docPayload());
        $this->actingAs(User::findOrFail(4))->get(route('management-documents.index', ['year' => 2026]))
            ->assertOk()
            ->assertDontSee('Test Supplier');
    }

    public function test_rewired_pl_totals_match_the_file(): void
    {
        $this->seedFileDocuments(1);
        $this->seedFileRevenue(1);
        $user = User::findOrFail(1);

        $this->asCompany()->get(route('management-reports.pl', ['year' => 2026]))
            ->assertOk()
            ->assertSee('FATURAÇÃO BRUTA')
            ->assertSee($user->priceFormat(22000))
            ->assertSee($user->priceFormat(2939.05))
            ->assertSee($user->priceFormat(7736.57))
            ->assertSee($user->priceFormat(19048.96))
            ->assertSee($user->priceFormat(4039.72))
            ->assertSee($user->priceFormat(7196.25))
            ->assertSee($user->priceFormat(76.42));
    }

    public function test_history_overlay_wins_for_imported_months(): void
    {
        $this->seedFileDocuments(1);
        $this->seedFileRevenue(1);
        foreach (ManagementReportHistorySeeder::rows() as $row) {
            \App\Models\ManagementReportHistory::updateOrCreate(
                ['created_by' => 1, 'year' => $row['year'], 'month' => $row['month'], 'line' => $row['line']],
                ['amount' => $row['amount']]
            );
        }
        $user = User::findOrFail(1);

        // The imported months use the file's ROUNDED figures: F&B 2,939
        // (not 2,939.05) and EBITDA 7,736 (not 7,736.57).
        $this->asCompany()->get(route('management-reports.pl', ['year' => 2026]))
            ->assertOk()
            ->assertSee($user->priceFormat(2939))
            ->assertSee($user->priceFormat(7736))
            ->assertDontSee($user->priceFormat(7736.57));
    }

    public function test_costs_report_matches_the_file(): void
    {
        $this->seedFileDocuments(1);
        $user = User::findOrFail(1);

        $this->asCompany()->get(route('management-reports.costs', ['year' => 2026]))
            ->assertOk()
            ->assertSee('TOTAL GERAL')
            ->assertSee($user->priceFormat(14263.43))
            ->assertSee('632 - Remunerações Pessoal');

        $cc102 = ManagementCostCentre::where('created_by', 1)->where('code', '102')->firstOrFail();
        $this->asCompany()->get(route('management-reports.costs', ['year' => 2026, 'cost_centre' => $cc102->id]))
            ->assertOk()
            ->assertSee($user->priceFormat(303.76));
    }

    public function test_delivery_line_is_computed_not_hardcoded(): void
    {
        $this->seedFileDocuments(1);
        $this->seedFileRevenue(1);

        // No delivery documents yet: DELIVERY COSTS is 0.0% everywhere.
        $this->asCompany()->get(route('management-reports.pl-percent', ['year' => 2026]))
            ->assertOk()
            ->assertSee('DELIVERY COSTS')
            ->assertSee('0.0%');

        // One delivery document: 120 on Jan sales of 12,000 = 1.0%.
        ManagementDocument::create([
            'created_by' => 1,
            'doc_date' => '2026-01-15',
            'supplier_name' => 'Delivery Partner',
            'doc_number' => 'DP-001',
            'doc_type' => 'invoice',
            'base' => 120.00,
            'iva' => 0,
            'retention' => 0,
            'total' => 120.00,
            'status' => 'Conciliado',
            'management_rule_id' => $this->ruleId('6225_01'),
            'management_cost_centre_id' => ManagementCostCentre::where('created_by', 1)->where('code', '101')->firstOrFail()->id,
        ]);

        $this->asCompany()->get(route('management-reports.pl-percent', ['year' => 2026]))
            ->assertOk()
            ->assertSee('1.0%');
    }
}
