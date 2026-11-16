<?php

namespace Tests\Feature;

use App\Models\ManagementCostCentre;
use App\Models\ManagementDocument;
use App\Models\ManagementRule;
use App\Models\ManagementSupplierCategory;
use App\Models\User;
use App\Services\ManagementMoney;
use Database\Seeders\ManagementReportHistorySeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\SeedsFileDocuments;
use Tests\TestCase;

/**
 * Management Documents (Blade): CRUD, validation, supplier-default
 * auto-assign, opt-in rules/centres seeding, import, duplicates,
 * history UI, and the rewired P&L / Costs / %P&L pages against the
 * file's verified Jan–May 2026 numbers (revenue 22,000; F&B 2,939.05;
 * EBITDA 7,736.57).
 *
 * Page totals are asserted through ManagementMoney::format() (the
 * management views show 2 decimals, e.g. 213.40); the exact 2dp
 * totals are asserted in ManagementDocumentCostsTest.
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

        Schema::create('chart_of_accounts', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by')->nullable();
            $table->string('code')->nullable();
            $table->string('name')->nullable();
            $table->string('type')->nullable();
            $table->integer('parent')->nullable();
            $table->integer('is_enabled')->default(1);
            $table->timestamps();
        });

        Schema::create('management_account_mappings', function ($table) {
            $table->bigIncrements('id');
            $table->integer('created_by');
            $table->integer('chart_account_id');
            $table->string('group', 100);
            $table->string('subgroup', 100)->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'chart_account_id'], 'mgmt_acct_map_creator_acct_uq');
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
        foreach (['branches', 'branch_user', 'settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates', 'chart_of_accounts', 'management_account_mappings'] as $table) {
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

    public function test_doc_number_is_required_for_all_types(): void
    {
        // There is no 'manual' type any more (a cost with no document
        // is a journal, not a document row): the doc number is
        // required for every type, and 'manual' is not a valid type.
        $payload = $this->docPayload();
        unset($payload['doc_number']);
        $this->asCompany()->post(route('management-documents.store'), $payload)
            ->assertSessionHasErrors('doc_number');

        $creditNote = $this->docPayload([
            'doc_type' => 'credit_note', 'base' => -100.00, 'iva' => 0, 'total' => -100.00,
        ]);
        unset($creditNote['doc_number']);
        $this->asCompany()->post(route('management-documents.store'), $creditNote)
            ->assertSessionHasErrors('doc_number');

        $this->asCompany()->post(route('management-documents.store'), $this->docPayload(['doc_type' => 'manual']))
            ->assertSessionHasErrors('doc_type');
    }

    public function test_credit_note_with_negative_base_saves(): void
    {
        // The form has no min="0" on Base/VAT/Retention any more: a
        // credit note with base −100, IVA −16, total −116 saves.
        $this->asCompany()->post(route('management-documents.store'), $this->docPayload([
            'doc_type' => 'credit_note', 'doc_number' => 'CN-116',
            'base' => -100.00, 'iva' => -16.00, 'retention' => 0, 'total' => -116.00,
        ]))->assertRedirect(route('management-documents.index'));
        $doc = ManagementDocument::where('doc_number', 'CN-116')->firstOrFail();
        $this->assertEqualsWithDelta(-100.0, $doc->netValue(), 0.01);
    }

    public function test_duplicate_document_is_rejected(): void
    {
        $this->asCompany()->post(route('management-documents.store'), $this->docPayload())
            ->assertRedirect(route('management-documents.index'));

        // Same supplier (different case + spaces) + doc number + date
        // + total: rejected.
        $this->asCompany()->post(route('management-documents.store'), $this->docPayload([
            'supplier_name' => '  test supplier ',
        ]))->assertSessionHasErrors('doc_number');
        $this->assertSame(1, ManagementDocument::where('doc_number', 'FT-001')->count());

        // Same supplier + doc number but a different total: allowed.
        $this->asCompany()->post(route('management-documents.store'), $this->docPayload([
            'doc_number' => 'FT-001', 'total' => 232.00, 'base' => 200.00, 'iva' => 32.00,
        ]))->assertRedirect(route('management-documents.index'));
    }

    public function test_rules_are_opt_in_and_deletion_is_blocked_in_use(): void
    {
        // Seeding is opt-in now: deleting the last category does NOT
        // bring it back on the next visit.
        ManagementRule::where('created_by', 1)->delete();
        $this->asCompany()->get(route('management-rules.index'))
            ->assertOk()
            ->assertDontSee('6263 - Seguros');
        $this->assertSame(0, ManagementRule::where('created_by', 1)->count());

        // The load-starter button brings the 57 back (missing only).
        $this->asCompany()->post(route('management-rules.load-starter-categories'))
            ->assertRedirect(route('management-rules.index'));
        $this->assertSame(57, ManagementRule::where('created_by', 1)->count());
        $this->asCompany()->get(route('management-rules.index'))
            ->assertOk()
            ->assertSee('6263 - Seguros');

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

    public function test_cost_centres_are_opt_in(): void
    {
        ManagementCostCentre::where('created_by', 1)->delete();
        $this->asCompany()->get(route('management-rules.index'))
            ->assertOk()
            ->assertDontSee('101 | Restaurante');
        $this->assertSame(0, ManagementCostCentre::where('created_by', 1)->count());

        $this->asCompany()->post(route('management-rules.load-starter-centres'))
            ->assertRedirect(route('management-rules.index'));
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
            ->assertSee(ManagementMoney::format($user, 22000))
            ->assertSee(ManagementMoney::format($user, 2939.05))
            ->assertSee(ManagementMoney::format($user, 7736.57))
            ->assertSee(ManagementMoney::format($user, 19048.96))
            ->assertSee(ManagementMoney::format($user, 4039.72))
            ->assertSee(ManagementMoney::format($user, 7196.25))
            ->assertSee(ManagementMoney::format($user, 76.42))
            // Drill-down: cost cells link to the Documents list.
            ->assertSee('management-documents?year=2026')
            ->assertSee('management_cost_centre_id=');
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
            ->assertSee(ManagementMoney::format($user, 2939))
            ->assertSee(ManagementMoney::format($user, 7736))
            ->assertDontSee('7,736.57')
            // Per-line history marks: the Jan F&B cell (213.00, from
            // history) carries *, a system zero cell (June F&B, no
            // history) renders without one.
            ->assertSee(ManagementMoney::format($user, 213) . '</a>*', false)
            ->assertSee('>' . ManagementMoney::format($user, 0) . '</td>', false);
    }

    public function test_costs_report_matches_the_file(): void
    {
        $this->seedFileDocuments(1);
        $user = User::findOrFail(1);

        $this->asCompany()->get(route('management-reports.costs', ['year' => 2026]))
            ->assertOk()
            ->assertSee('TOTAL GERAL')
            ->assertSee(ManagementMoney::format($user, 14263.43))
            ->assertSee('632 - Remunerações Pessoal')
            // Drill-down: category rows link to the Documents list.
            ->assertSee('management-documents?year=2026');

        $cc102 = ManagementCostCentre::where('created_by', 1)->where('code', '102')->firstOrFail();
        $this->asCompany()->get(route('management-reports.costs', ['year' => 2026, 'cost_centre' => $cc102->id]))
            ->assertOk()
            ->assertSee(ManagementMoney::format($user, 303.76));
    }

    public function test_documents_import_csv_reports_imported_and_skipped(): void
    {
        $csv = $this->buildImportCsv([
            // Valid.
            ['2026-06-10', '123456789', 'Import Supplier', 'IMP-001', 'invoice', '100', '16', '', '116', 'Conciliado', 'BCP Ordem', 'Tran. Bancária', '6241', '101', ''],
            // Bad total: 100 + 16 != 999.
            ['2026-06-10', '123456789', 'Import Supplier', 'IMP-002', 'invoice', '100', '16', '', '999', 'Conciliado', 'BCP Ordem', 'Tran. Bancária', '6241', '101', ''],
            // Duplicate of the first row.
            ['2026-06-10', '123456789', 'Import Supplier', 'IMP-001', 'invoice', '100', '16', '', '116', 'Conciliado', 'BCP Ordem', 'Tran. Bancária', '6241', '101', ''],
        ]);
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        $response = $this->asCompany()->post(route('management-documents.import'), ['file' => $file]);
        $response->assertRedirect(route('management-documents.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('management_documents', ['doc_number' => 'IMP-001', 'total' => 116.00]);
        $this->assertDatabaseMissing('management_documents', ['doc_number' => 'IMP-002']);
        $this->assertSame(1, ManagementDocument::where('doc_number', 'IMP-001')->count());

        $skipped = $response->getSession()->get('importSkipped');
        $this->assertCount(2, $skipped);
        $this->assertStringContainsString('already entered', $skipped[1]['reason']);
    }

    public function test_documents_import_accepts_cost_centre_code_header(): void
    {
        // The natural header 'cost_centre_code' (and 'category_code')
        // must map like the short 'cost_centre' / 'category' headers —
        // without it the imported row silently gets "No cost centre".
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['date', 'supplier_nif', 'supplier_name', 'doc_number', 'type', 'base', 'iva', 'retention', 'total', 'status', 'bank_account', 'payment_method', 'category_code', 'cost_centre_code', 'notes']);
        fputcsv($handle, ['2026-06-10', '123456789', 'Import Supplier', 'IMP-CC-001', 'invoice', '100', '16', '', '116', 'Conciliado', 'BCP Ordem', 'Tran. Bancária', '6241', '101', '']);
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        $response = $this->asCompany()->post(route('management-documents.import'), ['file' => $file]);
        $response->assertRedirect(route('management-documents.index'))
            ->assertSessionHas('success');

        $cc101 = ManagementCostCentre::where('created_by', 1)->where('code', '101')->firstOrFail();
        $this->assertDatabaseHas('management_documents', [
            'doc_number' => 'IMP-CC-001',
            'management_cost_centre_id' => $cc101->id,
        ]);
    }

    public function test_create_form_lists_used_suppliers_for_autocomplete(): void
    {
        // The supplier field is a datalist of suppliers already used
        // in this company's documents (free text still works for a
        // new supplier). A native datalist popup cannot be observed
        // in an automated browser, so the rendered options are the
        // proof here.
        $this->asCompany()->post(route('management-documents.store'), $this->docPayload());

        $this->asCompany()->get(route('management-documents.create'))
            ->assertOk()
            ->assertSee('supplier-list', false)
            ->assertSee('Test Supplier');
    }

    public function test_file_documents_import_cleanly(): void
    {
        $rows = [];
        foreach (self::fileDocuments() as $doc) {
            $rows[] = [
                $doc['doc_date'], $doc['supplier_nif'], $doc['supplier_name'], $doc['doc_number'], 'invoice',
                $doc['base'] ?? '', $doc['iva'] ?? '', $doc['retention'] ?? '', $doc['total'], $doc['status'],
                $doc['bank_account'], $doc['payment_method'],
                explode(' - ', $doc['category'])[0], explode(' | ', $doc['cc'])[0], '',
            ];
        }
        $file = UploadedFile::fake()->createWithContent('file-docs.csv', $this->buildImportCsv($rows));

        $response = $this->asCompany()->post(route('management-documents.import'), ['file' => $file]);
        $response->assertRedirect(route('management-documents.index'));

        $this->assertSame(27, ManagementDocument::where('created_by', 1)->count());
        $this->assertSame([], $response->getSession()->get('importSkipped'));
        $data = \App\Services\ManagementDocumentCosts::forYear(1, 2026);
        $this->assertEqualsWithDelta(14263.43, array_sum($data['total_geral']), 0.01);
    }

    public function test_documents_import_xlsx(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['date', 'supplier_nif', 'supplier_name', 'doc_number', 'type', 'base', 'iva', 'retention', 'total', 'status', 'bank_account', 'payment_method', 'category', 'cost_centre', 'notes'],
            ['2026-06-10', '123456789', 'Xlsx Supplier', 'XLS-001', 'invoice', 100, 16, null, 116, 'Conciliado', 'BCP Ordem', 'Tran. Bancária', '6241', '101', null],
            // Unknown category code: skipped.
            ['2026-06-10', '123456789', 'Xlsx Supplier', 'XLS-002', 'invoice', 100, 16, null, 116, 'Conciliado', 'BCP Ordem', 'Tran. Bancária', '9999', '101', null],
        ], null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'mgmt-import-') . '.xlsx';
        \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);
        $file = new UploadedFile($path, 'import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $response = $this->asCompany()->post(route('management-documents.import'), ['file' => $file]);
        $response->assertRedirect(route('management-documents.index'));
        $this->assertDatabaseHas('management_documents', ['doc_number' => 'XLS-001', 'total' => 116.00]);
        $this->assertDatabaseMissing('management_documents', ['doc_number' => 'XLS-002']);
        $skipped = $response->getSession()->get('importSkipped');
        $this->assertCount(1, $skipped);
        $this->assertStringContainsString('9999', $skipped[0]['reason']);
        unlink($path);
    }

    public function test_history_crud_changes_the_pl(): void
    {
        $user = User::findOrFail(1);

        $this->asCompany()->post(route('management-history.store'), [
            'year' => 2026, 'month' => 6, 'line' => 'cogs', 'amount' => 500,
        ])->assertRedirect(route('management-history.index'));
        $row = \App\Models\ManagementReportHistory::where('created_by', 1)->where('line', 'cogs')->where('month', 6)->firstOrFail();
        $this->asCompany()->get(route('management-reports.pl', ['year' => 2026]))
            ->assertOk()
            ->assertSee(ManagementMoney::format($user, 500));

        $this->asCompany()->put(route('management-history.update', $row), ['amount' => 600])
            ->assertRedirect(route('management-history.index'));
        $this->asCompany()->get(route('management-reports.pl', ['year' => 2026]))
            ->assertSee(ManagementMoney::format($user, 600));

        $this->asCompany()->delete(route('management-history.destroy', $row))
            ->assertRedirect(route('management-history.index'));
        $this->assertDatabaseMissing('management_report_history', ['id' => $row->id]);

        // Cross-company isolation: another company's row never shows here.
        $this->actingAs(User::findOrFail(4))->post(route('management-history.store'), [
            'year' => 2026, 'month' => 6, 'line' => 'cogs', 'amount' => 999,
        ])->assertRedirect(route('management-history.index'));
        $this->asCompany()->get(route('management-reports.pl', ['year' => 2026]))
            ->assertDontSee(ManagementMoney::format($user, 999));
    }

    public function test_documents_index_shows_group_column_and_filtered_sums(): void
    {
        $this->seedFileDocuments(1);
        $user = User::findOrFail(1);

        $this->asCompany()->get(route('management-documents.index', ['year' => 2026]))
            ->assertOk()
            ->assertSee('P&L Group')
            ->assertSee('CMVM Food')
            ->assertSee('Filtered totals:')
            // Net = base when present else total: the 27 docs sum to
            // the file's TOTAL GERAL.
            ->assertSee(ManagementMoney::format($user, 14263.43));
    }

    public function test_exports_return_valid_xlsx(): void
    {
        $this->seedFileDocuments(1);
        $this->seedFileRevenue(1);

        $pl = $this->asCompany()->get(route('management-reports.pl.export', ['year' => 2026]));
        $pl->assertOk();
        $pl->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $sheet = $this->loadXlsx($pl->streamedContent());
        $this->assertSame('FATURAÇÃO BRUTA', $sheet->getCell('A2')->getValue());
        $this->assertEqualsWithDelta(12000, (float) $sheet->getCell('B2')->getValue(), 0.01);

        $costs = $this->asCompany()->get(route('management-reports.costs.export', ['year' => 2026]));
        $costs->assertOk();
        $sheet = $this->loadXlsx($costs->streamedContent());
        $this->assertContains('TOTAL GERAL', array_column($sheet->toArray(), 0));

        $docs = $this->asCompany()->get(route('management-documents.export', ['year' => 2026]));
        $docs->assertOk();
        $sheet = $this->loadXlsx($docs->streamedContent());
        $this->assertSame(27, $sheet->getHighestRow() - 1);
    }

    public function test_management_money_shows_two_decimals(): void
    {
        $user = User::findOrFail(1);
        // 213.4 must render with two decimals (213.40), not 213.
        $this->assertMatchesRegularExpression('/\d+[.,]\d{2}/', ManagementMoney::format($user, 213.4));
    }

    private function buildImportCsv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['date', 'supplier_nif', 'supplier_name', 'doc_number', 'type', 'base', 'iva', 'retention', 'total', 'status', 'bank_account', 'payment_method', 'category', 'cost_centre', 'notes']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function loadXlsx(string $content): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $path = tempnam(sys_get_temp_dir(), 'mgmt-export-');
        file_put_contents($path, $content);
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        unlink($path);

        return $spreadsheet->getActiveSheet();
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
