<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\FixedAssetDepreciationEntry;
use App\Models\Invoice;
use App\Models\InvoiceProduct;
use App\Models\JournalEntry;
use App\Models\JournalItem;
use App\Models\ManagementAccountMapping;
use App\Models\ManagementCostCentre;
use App\Models\ManagementDocument;
use App\Models\ManagementRule;
use App\Models\Pos;
use App\Models\PosArea;
use App\Models\PosTable;
use App\Models\Tax;
use App\Models\User;
use App\Services\ManagementMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\SeedsFileDocuments;
use Tests\TestCase;

/**
 * Management Reports II: account mapping UI + journal flow,
 * depreciation from Fixed Assets, Sales by channel, per-line
 * history marks, department drill-down, sales export.
 */
class ManagementReportsTest extends TestCase
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
            $table->string('pos_type')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_areas', function ($table) {
            $table->bigIncrements('id');
            $table->integer('branch_id')->nullable();
            $table->string('name')->nullable();
            $table->integer('sort_order')->default(0);
            $table->integer('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_tables', function ($table) {
            $table->bigIncrements('id');
            $table->integer('area_id')->nullable();
            $table->integer('branch_id')->nullable();
            $table->string('name')->nullable();
            $table->integer('capacity')->nullable();
            $table->string('status')->nullable();
            $table->integer('created_by')->nullable();
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

        Schema::create('journal_entries', function ($table) {
            $table->bigIncrements('id');
            $table->date('date');
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->integer('journal_id')->default(0);
            $table->integer('created_by')->default(0);
            $table->integer('status')->default(0);
            $table->string('source', 20)->nullable();
            $table->string('accounting_period')->nullable();
            $table->timestamps();
        });

        Schema::create('journal_items', function ($table) {
            $table->bigIncrements('id');
            $table->integer('journal')->default(0);
            $table->integer('account')->default(0);
            $table->text('description')->nullable();
            $table->float('debit', 15, 2)->default(0.0);
            $table->float('credit', 15, 2)->default(0.0);
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

        Schema::create('fixed_asset_depreciation_entries', function ($table) {
            $table->bigIncrements('id');
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
            ['id' => 4, 'name' => 'Other Company', 'email' => 'other@test.local', 'type' => 'company', 'created_by' => 0, 'default_company_id' => 4, 'email_verified_at' => now()],
        ]);

        User::findOrFail(1)->givePermissionTo(['manage report']);
        User::findOrFail(4)->givePermissionTo(['manage report']);
    }

    protected function tearDown(): void
    {
        $this->dropManagementSchema();
        foreach (['branches', 'pos_areas', 'pos_tables', 'chart_of_accounts', 'journal_entries', 'journal_items', 'management_account_mappings', 'fixed_asset_depreciation_entries', 'branch_user', 'settings', 'users', 'roles', 'permissions', 'model_has_permissions', 'model_has_roles', 'role_has_permissions', 'plans', 'languages', 'ch_messages', 'email_templates'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    private function asCompany()
    {
        return $this->actingAs(User::findOrFail(1));
    }

    private function money(float $value): string
    {
        return ManagementMoney::format(User::findOrFail(1), $value);
    }

    /** One POS invoice: $ex ex-VAT at 17%, on the given POS (or direct). */
    private function posInvoice(float $ex, string $date, ?int $posId = null): void
    {
        $tax = Tax::firstOrCreate(['name' => 'IVA 17%', 'rate' => 17, 'created_by' => 1]);
        $invoice = Invoice::create([
            'invoice_id' => random_int(100, 999999),
            'issue_date' => $date, 'due_date' => $date,
            'status' => Invoice::STATUS_SENT, 'created_by' => 1,
            'pos_id' => $posId,
        ]);
        InvoiceProduct::create(['invoice_id' => $invoice->id, 'quantity' => 1, 'price' => $ex, 'discount' => 0, 'tax' => (string) $tax->id]);
    }

    public function test_account_mapping_ui_and_journal_flow(): void
    {
        $account = ChartOfAccount::create(['created_by' => 1, 'code' => '6241', 'name' => 'Eletricidade', 'is_enabled' => 1]);

        $this->asCompany()->post(route('management-rules.account-mappings.store'), [
            'chart_account_id' => $account->id, 'group' => 'Custos Fixos', 'subgroup' => 'Utilities',
        ])->assertRedirect(route('management-rules.index'));
        $mapping = ManagementAccountMapping::where('created_by', 1)->where('chart_account_id', $account->id)->firstOrFail();
        $this->asCompany()->get(route('management-rules.index'))->assertOk()->assertSee('6241 - Eletricidade');

        $entry = JournalEntry::create([
            'date' => '2026-06-10', 'reference' => 'TEST', 'description' => 'Manual journal',
            'journal_id' => 1, 'created_by' => 1, 'status' => JournalEntry::STATUS_APPROVED, 'source' => 'manual',
        ]);
        JournalItem::create(['journal' => $entry->id, 'account' => $account->id, 'debit' => 250.00, 'credit' => 0]);

        $this->asCompany()->get(route('management-reports.pl', ['year' => 2026]))
            ->assertOk()
            ->assertSee($this->money(250));

        // Moving the mapping moves the cost to the new group.
        $this->asCompany()->put(route('management-rules.account-mappings.update', $mapping), [
            'group' => 'Outros Custos / Pontuais', 'subgroup' => 'Utilities',
        ])->assertRedirect(route('management-rules.index'));
        $this->asCompany()->get(route('management-reports.pl', ['year' => 2026]))
            ->assertOk()
            ->assertSee($this->money(250));

        // Deleting the mapping stops the journal counting.
        $this->asCompany()->delete(route('management-rules.account-mappings.destroy', $mapping))
            ->assertRedirect(route('management-rules.index'));
        $this->asCompany()->get(route('management-reports.pl', ['year' => 2026]))
            ->assertDontSee($this->money(250));
    }

    public function test_depreciation_from_fixed_assets_flows_into_pl(): void
    {
        FixedAssetDepreciationEntry::create([
            'created_by' => 1, 'run_id' => 1, 'asset_id' => 1, 'schedule_id' => 1,
            'period' => '2026-06-01', 'planned_amount' => 150, 'posted_amount' => 150,
            'status' => FixedAssetDepreciationEntry::STATUS_POSTED,
        ]);
        // Calculated but not posted: not a cost yet.
        FixedAssetDepreciationEntry::create([
            'created_by' => 1, 'run_id' => 1, 'asset_id' => 2, 'schedule_id' => 2,
            'period' => '2026-06-01', 'planned_amount' => 999, 'posted_amount' => 999,
            'status' => FixedAssetDepreciationEntry::STATUS_CALCULATED,
        ]);
        // Another company's posted entry: never counts here.
        FixedAssetDepreciationEntry::create([
            'created_by' => 4, 'run_id' => 1, 'asset_id' => 1, 'schedule_id' => 1,
            'period' => '2026-06-01', 'planned_amount' => 777, 'posted_amount' => 777,
            'status' => FixedAssetDepreciationEntry::STATUS_POSTED,
        ]);

        $this->asCompany()->get(route('management-reports.pl', ['year' => 2026]))
            ->assertOk()
            ->assertSee('DEPRECIAÇÕES')
            ->assertSee($this->money(150))
            ->assertDontSee($this->money(999))
            ->assertDontSee($this->money(777));

        // History still wins for its line-month.
        \App\Models\ManagementReportHistory::create([
            'created_by' => 1, 'year' => 2026, 'month' => 6, 'line' => 'depreciation', 'amount' => 200,
        ]);
        $this->asCompany()->get(route('management-reports.pl', ['year' => 2026]))
            ->assertSee($this->money(200));
    }

    public function test_sales_page_splits_channels(): void
    {
        $restaurant = Branch::create(['name' => 'Restaurant', 'pos_type' => Branch::POS_TYPE_RESTAURANT, 'created_by' => 1]);
        $retail = Branch::create(['name' => 'Retail', 'pos_type' => Branch::POS_TYPE_RETAIL, 'created_by' => 1]);
        $barArea = PosArea::create(['branch_id' => $restaurant->id, 'name' => 'Bar', 'created_by' => 1]);
        $salaArea = PosArea::create(['branch_id' => $restaurant->id, 'name' => 'Sala', 'created_by' => 1]);
        $barTable = PosTable::create(['area_id' => $barArea->id, 'branch_id' => $restaurant->id, 'name' => 'B1', 'created_by' => 1]);
        $salaTable = PosTable::create(['area_id' => $salaArea->id, 'branch_id' => $restaurant->id, 'name' => 'S1', 'created_by' => 1]);

        $barPos = Pos::create(['branch_id' => $restaurant->id, 'table_id' => $barTable->id, 'created_by' => 1, 'pos_date' => '2026-01-10']);
        $restPos = Pos::create(['branch_id' => $restaurant->id, 'table_id' => $salaTable->id, 'created_by' => 1, 'pos_date' => '2026-01-10']);
        $retailPos = Pos::create(['branch_id' => $retail->id, 'created_by' => 1, 'pos_date' => '2026-01-10']);

        $this->posInvoice(2000, '2026-01-10', $barPos->id);
        $this->posInvoice(5000, '2026-01-10', $restPos->id);
        $this->posInvoice(1000, '2026-01-10', $retailPos->id);
        $this->posInvoice(3000, '2026-01-10', null);

        $this->asCompany()->get(route('management-reports.sales', ['year' => 2026]))
            ->assertOk()
            ->assertSee('Software Faturação — Sem IVA')
            ->assertSee($this->money(11000))
            ->assertSee('Restaurante — Sem IVA')
            ->assertSee($this->money(5000))
            ->assertSee('Bar — Sem IVA')
            ->assertSee($this->money(2000))
            ->assertSee('Outras — Sem IVA')
            ->assertSee($this->money(4000))
            ->assertSee('Software Faturação — Total')
            ->assertSee($this->money(12870));
    }

    public function test_sales_channel_history_marks_cells(): void
    {
        $this->posInvoice(2000, '2026-01-10', null);
        \App\Models\ManagementReportHistory::create([
            'created_by' => 1, 'year' => 2026, 'month' => 1, 'line' => 'channel:Restaurante:ex', 'amount' => 9000,
        ]);

        // The history row wins for Restaurante Jan ex (9000*), the
        // direct invoice stays in Outras.
        $this->asCompany()->get(route('management-reports.sales', ['year' => 2026]))
            ->assertOk()
            ->assertSee($this->money(9000) . '*', false)
            ->assertSee($this->money(2000));
    }

    public function test_per_line_history_mark_only_on_history_line_month(): void
    {
        \App\Models\ManagementReportHistory::create([
            'created_by' => 1, 'year' => 2026, 'month' => 1, 'line' => 'cogs', 'amount' => 213,
        ]);
        \App\Models\ManagementDocument::create([
            'created_by' => 1, 'doc_date' => '2026-02-10', 'supplier_name' => 'Feb Supplier',
            'doc_number' => 'FEB-001', 'doc_type' => 'invoice', 'base' => 100.00, 'iva' => 0,
            'retention' => 0, 'total' => 100.00, 'status' => 'Conciliado',
            'management_rule_id' => ManagementRule::where('created_by', 1)->where('code', '312_01')->firstOrFail()->id,
        ]);

        $this->asCompany()->get(route('management-reports.pl', ['year' => 2026]))
            ->assertOk()
            // Jan F&B is history-sourced: * on its cell.
            ->assertSee($this->money(213) . '</a>*', false)
            // Feb F&B is system-sourced: no * on its cell.
            ->assertSee($this->money(100) . '</a></td>', false);
    }

    public function test_department_row_drills_down_by_cost_centre(): void
    {
        $this->seedFileDocuments(1);
        $cc101 = ManagementCostCentre::where('created_by', 1)->where('code', '101')->firstOrFail();

        $this->asCompany()->get(route('management-reports.pl', ['year' => 2026]))
            ->assertOk()
            ->assertSee('Restaurante (CC 101)')
            ->assertSee('management_cost_centre_id=' . $cc101->id, false);
    }

    public function test_sales_export_returns_valid_xlsx(): void
    {
        $this->posInvoice(2000, '2026-01-10', null);

        $response = $this->asCompany()->get(route('management-reports.sales.export', ['year' => 2026]));
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $path = tempnam(sys_get_temp_dir(), 'mgmt-sales-');
        file_put_contents($path, $response->streamedContent());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
        unlink($path);

        $values = $sheet->toArray();
        $this->assertContains('Outras — Sem IVA', array_column($values, 0));
        $outrasRow = collect($values)->firstWhere(0, 'Outras — Sem IVA');
        $this->assertEqualsWithDelta(2000, (float) $outrasRow[1], 0.01);
    }

    public function test_costs_page_shows_empty_hint_when_year_has_no_costs(): void
    {
        // Rules and centres are seeded in setUp (57 categories exist),
        // but no documents are seeded for 2026, so every cost is 0 and
        // the empty-state hint must show. (The old condition checked
        // empty($table['rows']), which is never true once categories exist.)
        $this->asCompany()->get(route('management-reports.costs', ['year' => 2026]))
            ->assertOk()
            ->assertSee('No documents exist yet for this year, so every cost is 0.');

        // And once a document exists for the year, the hint goes away.
        $this->seedFileDocuments(1);

        $this->asCompany()->get(route('management-reports.costs', ['year' => 2026]))
            ->assertOk()
            ->assertDontSee('No documents exist yet for this year, so every cost is 0.');
    }

    public function test_costs_hint_stays_hidden_when_documents_net_to_zero(): void
    {
        // An invoice and an equal credit note net to zero for the
        // year, but documents DO exist — the hint must not claim
        // there are none.
        $rule = ManagementRule::where('created_by', 1)->where('code', '6241')->firstOrFail();
        $cc = ManagementCostCentre::where('created_by', 1)->where('code', '101')->firstOrFail();

        ManagementDocument::create([
            'created_by' => 1, 'doc_date' => '2026-06-10', 'supplier_name' => 'Net Zero Supplier',
            'doc_number' => 'NZ-001', 'doc_type' => 'invoice', 'base' => 100, 'iva' => 16,
            'retention' => 0, 'total' => 116, 'status' => 'Conciliado',
            'management_rule_id' => $rule->id, 'management_cost_centre_id' => $cc->id,
        ]);
        ManagementDocument::create([
            'created_by' => 1, 'doc_date' => '2026-06-20', 'supplier_name' => 'Net Zero Supplier',
            'doc_number' => 'NZ-002', 'doc_type' => 'credit_note', 'base' => -100, 'iva' => -16,
            'retention' => 0, 'total' => -116, 'status' => 'Conciliado',
            'management_rule_id' => $rule->id, 'management_cost_centre_id' => $cc->id,
        ]);

        $this->asCompany()->get(route('management-reports.costs', ['year' => 2026]))
            ->assertOk()
            ->assertDontSee('No documents exist yet for this year, so every cost is 0.');
    }
}
