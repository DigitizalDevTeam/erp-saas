<?php

namespace Tests\Unit\Accounting;

use App\Models\ChartOfAccount;
use App\Services\Accounting\FiscalYearClosingService;
use App\Services\BalanceSheetReportService;
use App\Services\DreDemonstracaoResultadosService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The Profit & Loss statement is the reference report. The comparative
 * income statement (DRE) must agree with it: year-end closing journals
 * (FY-*-CLOSE*) only move class 6/7 balances into result accounts, so —
 * exactly like P&L — the DRE must exclude them, otherwise every revenue
 * and cost line cancels to zero once the year is closed.
 *
 * Also covers: class 7 line descriptions in the DRE, account display
 * names when only a translated name exists, and the Balance Sheet
 * tying out on the same (clean) closed ledger.
 */
class ComparativeReportsConsistencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pdo = DB::connection()->getPdo();
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $pdo->sqliteCreateFunction('YEAR', fn ($d) => $d ? (int) substr((string) $d, 0, 4) : null, 1);
        }

        Schema::create('chart_of_account_types', function (Blueprint $t) { $t->id(); $t->string('name')->nullable(); $t->integer('created_by')->nullable(); });
        Schema::create('chart_of_accounts', function (Blueprint $t) { $t->id(); $t->string('code')->nullable(); $t->string('name')->nullable(); $t->string('name_pt_br')->nullable(); $t->string('name_fr')->nullable(); $t->string('name_es')->nullable(); $t->string('name_zh')->nullable(); $t->integer('type')->nullable(); $t->integer('sub_type')->nullable(); $t->integer('parent')->nullable(); $t->integer('parent_account_id')->nullable(); $t->integer('created_by')->nullable(); $t->timestamps(); });
        Schema::create('chart_of_account_parents', function (Blueprint $t) { $t->id(); $t->string('name')->nullable(); $t->integer('type')->nullable(); $t->integer('account')->nullable(); $t->integer('created_by')->nullable(); $t->timestamps(); });
        Schema::create('journal_entries', function (Blueprint $t) { $t->id(); $t->integer('journal_id')->nullable(); $t->date('date'); $t->string('reference')->nullable(); $t->text('description')->nullable(); $t->unsignedTinyInteger('status')->default(0); $t->integer('created_by'); $t->integer('accounting_period')->nullable(); $t->timestamps(); });
        Schema::create('journal_items', function (Blueprint $t) { $t->id(); $t->integer('journal'); $t->integer('account'); $t->decimal('debit', 15, 2)->default(0); $t->decimal('credit', 15, 2)->default(0); $t->text('description')->nullable(); $t->timestamps(); });
        Schema::create('transaction_lines', function (Blueprint $t) { $t->id(); $t->integer('account_id'); $t->decimal('debit', 15, 2)->default(0); $t->decimal('credit', 15, 2)->default(0); $t->date('date'); $t->string('reference')->nullable(); $t->integer('reference_id')->nullable(); $t->integer('reference_sub_id')->nullable(); $t->integer('created_by'); $t->timestamps(); });
        Schema::create('bank_accounts', function (Blueprint $t) { $t->id(); $t->integer('chart_account_id')->nullable(); $t->decimal('opening_balance', 15, 2)->default(0); $t->timestamps(); });

        DB::table('chart_of_account_types')->insert([
            ['id' => 1, 'name' => 'Assets', 'created_by' => 1],
            ['id' => 2, 'name' => 'Liabilities', 'created_by' => 1],
            ['id' => 3, 'name' => 'Equity', 'created_by' => 1],
            ['id' => 4, 'name' => 'Income', 'created_by' => 1],
            ['id' => 5, 'name' => 'Expenses', 'created_by' => 1],
        ]);
        DB::table('chart_of_accounts')->insert([
            ['id' => 101, 'code' => '11', 'name' => 'Cash', 'type' => 1, 'created_by' => 1],
            ['id' => 103, 'code' => '42', 'name' => 'Suppliers', 'type' => 2, 'created_by' => 1],
            ['id' => 104, 'code' => '51', 'name' => 'Capital', 'type' => 3, 'created_by' => 1],
            ['id' => 105, 'code' => '622', 'name' => 'Personnel', 'type' => 5, 'created_by' => 1],
            ['id' => 106, 'code' => '632', 'name' => 'Supplies', 'type' => 5, 'created_by' => 1],
            ['id' => 107, 'code' => '711', 'name' => 'Sales', 'type' => 4, 'created_by' => 1],
            ['id' => 108, 'code' => '722001', 'name' => 'Services', 'type' => 4, 'created_by' => 1],
            ['id' => 109, 'code' => '81', 'name' => 'Operating Results', 'type' => 3, 'created_by' => 1],
            ['id' => 110, 'code' => '82', 'name' => 'Financial Results', 'type' => 3, 'created_by' => 1],
            ['id' => 111, 'code' => '83', 'name' => 'Current Results', 'type' => 3, 'created_by' => 1],
            ['id' => 112, 'code' => '85', 'name' => 'Current Tax', 'type' => 3, 'created_by' => 1],
            ['id' => 113, 'code' => '88', 'name' => 'Net Profits', 'type' => 3, 'created_by' => 1],
            ['id' => 114, 'code' => '44101', 'name' => 'Income Tax Payable', 'type' => 2, 'created_by' => 1],
        ]);

        $lines = [
            ['account_id' => 101, 'debit' => 2000, 'credit' => 0, 'date' => '2025-01-02', 'reference' => 'Journal', 'reference_id' => null],
            ['account_id' => 104, 'debit' => 0, 'credit' => 2000, 'date' => '2025-01-02', 'reference' => 'Journal', 'reference_id' => null],
            ['account_id' => 101, 'debit' => 1500, 'credit' => 0, 'date' => '2025-06-01', 'reference' => 'Invoice', 'reference_id' => 1],
            ['account_id' => 107, 'debit' => 0, 'credit' => 1000, 'date' => '2025-06-01', 'reference' => 'Invoice', 'reference_id' => 1],
            ['account_id' => 108, 'debit' => 0, 'credit' => 500, 'date' => '2025-06-01', 'reference' => 'Invoice', 'reference_id' => 1],
            ['account_id' => 105, 'debit' => 400, 'credit' => 0, 'date' => '2025-06-02', 'reference' => 'Expense', 'reference_id' => 1],
            ['account_id' => 101, 'debit' => 0, 'credit' => 400, 'date' => '2025-06-02', 'reference' => 'Expense', 'reference_id' => 1],
            ['account_id' => 106, 'debit' => 100, 'credit' => 0, 'date' => '2025-06-03', 'reference' => 'Bill', 'reference_id' => 1],
            ['account_id' => 103, 'debit' => 0, 'credit' => 100, 'date' => '2025-06-03', 'reference' => 'Bill', 'reference_id' => 1],
        ];
        foreach ($lines as $row) {
            $row['created_by'] = 1;
            DB::table('transaction_lines')->insert($row);
        }

        // Suppliers carries a Portuguese name that contains "fornec…" —
        // it must never be classified as a class 632 cost by name fallback.
        DB::table('chart_of_accounts')->where('id', 103)->update(['name_pt_br' => 'Fornecedores']);
    }

    private function closeYear(): void
    {
        $result = app(FiscalYearClosingService::class)->run(1, 2025, '2025-12-31', 15);
        $this->assertTrue($result['success'], implode(' ', $result['messages']));
    }

    public function test_dre_matches_pl_basis_after_year_end_closing(): void
    {
        // Before closing, DRE agrees with the P&L basis (income 1500, costs 500).
        $before = DreDemonstracaoResultadosService::build(1, 2025, 2024);
        $this->assertSame(1500.0, $before['B']['y1']);
        $this->assertSame(500.0, $before['A']['y1']);

        $this->closeYear();

        // Closing journals must not cancel the year out (P&L excludes them too).
        $after = DreDemonstracaoResultadosService::build(1, 2025, 2024);
        $this->assertSame(1500.0, $after['B']['y1'], 'Revenue total (B) must survive year-end closing');
        $this->assertSame(500.0, $after['A']['y1'], 'Operating cost total (A) must survive year-end closing');
        $this->assertSame(1000.0, $after['net_result']['y1']);
    }

    public function test_balance_sheet_accounts_do_not_leak_into_dre_via_name_fallback(): void
    {
        // Suppliers (42, "Fornecedores") must not be classified as a 632 cost
        // just because its Portuguese name contains "fornec…".
        $report = DreDemonstracaoResultadosService::build(1, 2025, 2024);

        $this->assertSame(100.0, $report['amounts']['c632']['y1']);
        $this->assertSame(500.0, $report['A']['y1']);
    }

    public function test_dre_class_7_line_labels_describe_their_accounts(): void
    {
        $report = DreDemonstracaoResultadosService::build(1, 2025, 2024);
        $filled = DreDemonstracaoResultadosService::buildFilledRows($report, 1, 'pt');

        $labels = collect($filled['revenue'])->pluck('label')->all();

        $this->assertContains('Prestação de serviços', $labels);   // class 72
        $this->assertContains('Variação da produção', $labels);    // class 73
        $this->assertContains('Subsídios à exploração', $labels);  // class 74
        $this->assertContains('Proveitos suplementares', $labels); // class 75
    }

    public function test_balance_sheet_ties_on_the_same_closed_ledger(): void
    {
        $this->closeYear();

        $bs = app(BalanceSheetReportService::class)->build(1, '2025-01-01', '2025-12-31', null, 2025, 0, 15);

        $this->assertSame(3100.0, $bs['totals']['assets']['current']);
        $this->assertSame(420.0, $bs['totals']['liabilities']['current']);
        // Capital 2000 + net profit after 32% tax on 1000 = 680.
        $this->assertSame(2680.0, $bs['totals']['equity']['current']);
        $this->assertSame(0.0, $bs['totals']['difference']['current']);
    }

    public function test_account_display_name_falls_back_to_translated_name(): void
    {
        $account = new ChartOfAccount();
        $account->name = '';
        $account->name_pt_br = 'Prestação de Serviços';

        $this->assertSame('Prestação de Serviços', $account->labelForLocale('en'));
        $this->assertSame('Prestação de Serviços', $account->labelForLocale('pt'));
    }

    public function test_pl_report_uses_localized_account_names(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/ReportController.php'));

        $this->assertStringContainsString('function reportAccountName(', $source);
        $this->assertStringContainsString("'account_name' => \$this->reportAccountName(", $source);
    }
}
