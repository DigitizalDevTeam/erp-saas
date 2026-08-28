<?php

namespace Tests\Unit\Accounting;

use App\Http\Controllers\MonthlyVatReportController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ModeloAVatReportSettlementExclusionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('chart_of_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name')->nullable();
            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('journal_entries', function (Blueprint $table): void {
            $table->id();
            $table->date('date')->nullable();
            $table->string('reference')->nullable();
            $table->string('description')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->unsignedBigInteger('created_by')->default(0);
            $table->timestamps();
        });

        Schema::create('journal_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('journal')->default(0);
            $table->unsignedBigInteger('account')->default(0);
            $table->text('description')->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('transaction_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->string('reference');
            $table->unsignedBigInteger('reference_id');
            $table->unsignedBigInteger('reference_sub_id')->default(0);
            $table->date('date');
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('transaction_lines');
        Schema::dropIfExists('journal_items');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('chart_of_accounts');

        parent::tearDown();
    }

    public function test_sum_by_prefix_nets_debit_mode(): void
    {
        $rows = [
            (object) ['code' => '44323', 'debit' => 7466.78, 'credit' => 4059.12],
        ];

        $this->assertSame(
            3407.66,
            MonthlyVatReportController::sumByPrefix($rows, ['44323'], 'debit')
        );
    }

    public function test_settlement_credit_does_not_reduce_field_07(): void
    {
        $creatorId = 9001;
        $id44323 = DB::table('chart_of_accounts')->insertGetId([
            'code' => '44323',
            'name' => 'Other goods VAT',
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $id4435 = DB::table('chart_of_accounts')->insertGetId([
            'code' => '4435',
            'name' => 'VAT settlement',
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $id71 = DB::table('chart_of_accounts')->insertGetId([
            'code' => '711',
            'name' => 'Sales',
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Purchase VAT debit (must count toward field 07).
        DB::table('transaction_lines')->insert([
            'account_id' => $id44323,
            'reference' => 'Bill',
            'reference_id' => 1,
            'reference_sub_id' => 1,
            'date' => '2025-01-15',
            'debit' => 7466.78,
            'credit' => 0,
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Settlement journal: credit 44323 / debit 4435 (must NOT reduce field 07).
        $settlementId = DB::table('journal_entries')->insertGetId([
            'date' => '2025-01-31',
            'reference' => 'FY-2025-VAT-DED',
            'description' => 'VAT — deductible to 4435',
            'journal_id' => 1,
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('transaction_lines')->insert([
            [
                'account_id' => $id4435,
                'reference' => 'Journal',
                'reference_id' => $settlementId,
                'reference_sub_id' => 1,
                'date' => '2025-01-31',
                'debit' => 4059.12,
                'credit' => 0,
                'created_by' => $creatorId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'account_id' => $id44323,
                'reference' => 'Journal',
                'reference_id' => $settlementId,
                'reference_sub_id' => 2,
                'date' => '2025-01-31',
                'debit' => 0,
                'credit' => 4059.12,
                'created_by' => $creatorId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Sales (unaffected by settlement exclusion).
        DB::table('transaction_lines')->insert([
            'account_id' => $id71,
            'reference' => 'Invoice',
            'reference_id' => 10,
            'reference_sub_id' => 1,
            'date' => '2025-01-10',
            'debit' => 0,
            'credit' => 10000,
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $controller = app(MonthlyVatReportController::class);
        $report = $controller->buildReportData($creatorId, '2025-01-01', '2025-01-31', [], true);

        $this->assertSame(7466.78, $report['f07']);
        $this->assertSame(10000.0, $report['f01']);
        $this->assertContains($settlementId, $controller->settlementJournalIds($creatorId));
    }

    public function test_manual_settlement_without_4435_still_excluded_when_paired_with_4437(): void
    {
        $creatorId = 9004;
        $id44323 = DB::table('chart_of_accounts')->insertGetId([
            'code' => '44323',
            'name' => 'Other goods VAT',
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $id4437 = DB::table('chart_of_accounts')->insertGetId([
            'code' => '4437',
            'name' => 'VAT payable',
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('transaction_lines')->insert([
            'account_id' => $id44323,
            'reference' => 'Bill',
            'reference_id' => 1,
            'reference_sub_id' => 1,
            'date' => '2025-01-08',
            'debit' => 5020.34,
            'credit' => 0,
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $settlementId = DB::table('journal_entries')->insertGetId([
            'date' => '2025-01-31',
            'reference' => 'MANUAL-VAT-JAN',
            'description' => 'Manual VAT clearing',
            'journal_id' => 4,
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('transaction_lines')->insert([
            [
                'account_id' => $id44323,
                'reference' => 'Journal',
                'reference_id' => $settlementId,
                'reference_sub_id' => 1,
                'date' => '2025-01-31',
                'debit' => 0,
                'credit' => 2760.47,
                'created_by' => $creatorId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'account_id' => $id4437,
                'reference' => 'Journal',
                'reference_id' => $settlementId,
                'reference_sub_id' => 2,
                'date' => '2025-01-31',
                'debit' => 2760.47,
                'credit' => 0,
                'created_by' => $creatorId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $controller = app(MonthlyVatReportController::class);
        $report = $controller->buildReportData($creatorId, '2025-01-01', '2025-01-31', [], true);

        $this->assertSame(5020.34, $report['f07']);
        $this->assertContains($settlementId, $controller->settlementJournalIds($creatorId));
    }

    public function test_output_vat_settlement_debit_does_not_reduce_field_02(): void
    {
        $creatorId = 9002;
        $id44331 = DB::table('chart_of_accounts')->insertGetId([
            'code' => '44331',
            'name' => 'VAT collected',
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $id4435 = DB::table('chart_of_accounts')->insertGetId([
            'code' => '4435',
            'name' => 'VAT settlement',
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('transaction_lines')->insert([
            'account_id' => $id44331,
            'reference' => 'Invoice',
            'reference_id' => 20,
            'reference_sub_id' => 1,
            'date' => '2025-02-10',
            'debit' => 0,
            'credit' => 1600,
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $settlementId = DB::table('journal_entries')->insertGetId([
            'date' => '2025-02-28',
            'reference' => 'FY-2025-VAT-COL',
            'description' => 'VAT — collected to settlement',
            'journal_id' => 2,
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('transaction_lines')->insert([
            [
                'account_id' => $id44331,
                'reference' => 'Journal',
                'reference_id' => $settlementId,
                'reference_sub_id' => 1,
                'date' => '2025-02-28',
                'debit' => 800,
                'credit' => 0,
                'created_by' => $creatorId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'account_id' => $id4435,
                'reference' => 'Journal',
                'reference_id' => $settlementId,
                'reference_sub_id' => 2,
                'date' => '2025-02-28',
                'debit' => 0,
                'credit' => 800,
                'created_by' => $creatorId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $controller = app(MonthlyVatReportController::class);
        $report = $controller->buildReportData($creatorId, '2025-02-01', '2025-02-28', [], true);

        $this->assertSame(1600.0, $report['f02']);
    }

    public function test_february_output_vat_matches_balancete_credit_after_large_settlement(): void
    {
        $creatorId = 9005;
        $id44331 = DB::table('chart_of_accounts')->insertGetId([
            'code' => '44331',
            'name' => 'VAT collected',
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $id4435 = DB::table('chart_of_accounts')->insertGetId([
            'code' => '4435',
            'name' => 'VAT settlement',
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('transaction_lines')->insert([
            'account_id' => $id44331,
            'reference' => 'Invoice',
            'reference_id' => 30,
            'reference_sub_id' => 1,
            'date' => '2025-02-15',
            'debit' => 0,
            'credit' => 8858.40,
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $settlementId = DB::table('journal_entries')->insertGetId([
            'date' => '2025-02-28',
            'reference' => 'FY-2025-VAT-COL',
            'description' => 'VAT — collected to settlement',
            'journal_id' => 5,
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('transaction_lines')->insert([
            [
                'account_id' => $id44331,
                'reference' => 'Journal',
                'reference_id' => $settlementId,
                'reference_sub_id' => 1,
                'date' => '2025-02-28',
                'debit' => 8575.90,
                'credit' => 0,
                'created_by' => $creatorId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'account_id' => $id4435,
                'reference' => 'Journal',
                'reference_id' => $settlementId,
                'reference_sub_id' => 2,
                'date' => '2025-02-28',
                'debit' => 0,
                'credit' => 8575.90,
                'created_by' => $creatorId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $controller = app(MonthlyVatReportController::class);
        $report = $controller->buildReportData($creatorId, '2025-02-01', '2025-02-28', [], true);

        $this->assertSame(8858.40, $report['f02']);
    }

    public function test_purchase_credit_note_still_reduces_field_07(): void
    {
        $creatorId = 9003;
        $id44323 = DB::table('chart_of_accounts')->insertGetId([
            'code' => '44323',
            'name' => 'Other goods VAT',
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('transaction_lines')->insert([
            [
                'account_id' => $id44323,
                'reference' => 'Bill',
                'reference_id' => 1,
                'reference_sub_id' => 1,
                'date' => '2025-03-05',
                'debit' => 5000,
                'credit' => 0,
                'created_by' => $creatorId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'account_id' => $id44323,
                'reference' => 'Bill',
                'reference_id' => 2,
                'reference_sub_id' => 1,
                'date' => '2025-03-12',
                'debit' => 0,
                'credit' => 200,
                'created_by' => $creatorId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $controller = app(MonthlyVatReportController::class);
        $report = $controller->buildReportData($creatorId, '2025-03-01', '2025-03-31', [], true);

        $this->assertSame(4800.0, $report['f07']);
    }

    public function test_settlement_with_non_standard_reference_and_description_is_excluded(): void
    {
        $creatorId = 9006;
        $id44323 = DB::table('chart_of_accounts')->insertGetId([
            'code' => '44323',
            'name' => 'Other goods VAT',
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $id4435 = DB::table('chart_of_accounts')->insertGetId([
            'code' => '4435',
            'name' => 'VAT settlement',
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('transaction_lines')->insert([
            'account_id' => $id44323,
            'reference' => 'Bill',
            'reference_id' => 3,
            'reference_sub_id' => 1,
            'date' => '2025-03-04',
            'debit' => 6107.71,
            'credit' => 0,
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $settlementId = DB::table('journal_entries')->insertGetId([
            'date' => '2025-03-31',
            'reference' => 'AJ-2025-03',
            'description' => 'VAT — deductible to 4435',
            'journal_id' => 6,
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('transaction_lines')->insert([
            [
                'account_id' => $id4435,
                'reference' => 'Journal',
                'reference_id' => $settlementId,
                'reference_sub_id' => 1,
                'date' => '2025-03-31',
                'debit' => 3541.58,
                'credit' => 0,
                'created_by' => $creatorId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'account_id' => $id44323,
                'reference' => 'Journal',
                'reference_id' => $settlementId,
                'reference_sub_id' => 2,
                'date' => '2025-03-31',
                'debit' => 0,
                'credit' => 3541.58,
                'created_by' => $creatorId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $controller = app(MonthlyVatReportController::class);
        $report = $controller->buildReportData($creatorId, '2025-03-01', '2025-03-31', [], true);

        $this->assertSame(6107.71, $report['f07']);
    }

    public function test_consultant_january_february_march_scenarios_match_balancete_debit_credit_columns(): void
    {
        $cases = [
            ['month' => 1, 'deductible' => 7466.78, 'settlementCredit' => 4059.12, 'expectedF07' => 7466.78],
            ['month' => 2, 'deductible' => 5020.34, 'settlementCredit' => 2760.47, 'expectedF07' => 5020.34],
            ['month' => 3, 'deductible' => 6107.71, 'settlementCredit' => 3541.58, 'expectedF07' => 6107.71],
        ];

        foreach ($cases as $index => $case) {
            $creatorId = 9100 + $index;
            $month = $case['month'];
            $start = sprintf('2025-%02d-01', $month);
            $end = date('Y-m-t', strtotime($start));

            $id44323 = DB::table('chart_of_accounts')->insertGetId([
                'code' => '44323',
                'name' => 'Other goods VAT',
                'created_by' => $creatorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $id4435 = DB::table('chart_of_accounts')->insertGetId([
                'code' => '4435',
                'name' => 'VAT settlement',
                'created_by' => $creatorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('transaction_lines')->insert([
                'account_id' => $id44323,
                'reference' => 'Bill',
                'reference_id' => 100 + $index,
                'reference_sub_id' => 1,
                'date' => $start,
                'debit' => $case['deductible'],
                'credit' => 0,
                'created_by' => $creatorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $settlementId = DB::table('journal_entries')->insertGetId([
                'date' => $end,
                'reference' => 'FY-2025-VAT-DED-'.$month,
                'description' => 'VAT — deductible to 4435',
                'journal_id' => 100 + $index,
                'created_by' => $creatorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('transaction_lines')->insert([
                [
                    'account_id' => $id4435,
                    'reference' => 'Journal',
                    'reference_id' => $settlementId,
                    'reference_sub_id' => 1,
                    'date' => $end,
                    'debit' => $case['settlementCredit'],
                    'credit' => 0,
                    'created_by' => $creatorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'account_id' => $id44323,
                    'reference' => 'Journal',
                    'reference_id' => $settlementId,
                    'reference_sub_id' => 2,
                    'date' => $end,
                    'debit' => 0,
                    'credit' => $case['settlementCredit'],
                    'created_by' => $creatorId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $controller = app(MonthlyVatReportController::class);
            $report = $controller->buildReportData($creatorId, $start, $end, [], true);

            $this->assertSame(
                $case['expectedF07'],
                $report['f07'],
                "Field 07 for month {$month} should match Balancete 44323 Debit column."
            );
        }

        $creatorId = 9200;
        $id44331 = DB::table('chart_of_accounts')->insertGetId([
            'code' => '44331',
            'name' => 'VAT collected',
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $id4435 = DB::table('chart_of_accounts')->insertGetId([
            'code' => '4435',
            'name' => 'VAT settlement',
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('transaction_lines')->insert([
            'account_id' => $id44331,
            'reference' => 'Invoice',
            'reference_id' => 200,
            'reference_sub_id' => 1,
            'date' => '2025-02-12',
            'debit' => 0,
            'credit' => 8858.40,
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $settlementId = DB::table('journal_entries')->insertGetId([
            'date' => '2025-02-28',
            'reference' => 'FY-2025-VAT-COL',
            'description' => 'VAT — collected to settlement',
            'journal_id' => 200,
            'created_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('transaction_lines')->insert([
            [
                'account_id' => $id44331,
                'reference' => 'Journal',
                'reference_id' => $settlementId,
                'reference_sub_id' => 1,
                'date' => '2025-02-28',
                'debit' => 8575.90,
                'credit' => 0,
                'created_by' => $creatorId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'account_id' => $id4435,
                'reference' => 'Journal',
                'reference_id' => $settlementId,
                'reference_sub_id' => 2,
                'date' => '2025-02-28',
                'debit' => 0,
                'credit' => 8575.90,
                'created_by' => $creatorId,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $controller = app(MonthlyVatReportController::class);
        $report = $controller->buildReportData($creatorId, '2025-02-01', '2025-02-28', [], true);

        $this->assertSame(8858.40, $report['f02']);
    }
}
