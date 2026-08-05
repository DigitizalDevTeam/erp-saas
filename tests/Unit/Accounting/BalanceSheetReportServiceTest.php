<?php

namespace Tests\Unit\Accounting;

use App\Services\BalanceSheetReportService;
use PHPUnit\Framework\TestCase;

class BalanceSheetReportServiceTest extends TestCase
{
    private function snapshot(array $accounts): array
    {
        return (new BalanceSheetReportService())->computeSnapshot($accounts);
    }

    public function test_balanced_ledger_gives_assets_equal_to_equity_plus_liabilities(): void
    {
        $snapshot = $this->snapshot([
            ['code' => '11', 'type' => 'Assets', 'debit' => 5000, 'credit' => 0],
            ['code' => '41', 'type' => 'Assets', 'debit' => 2000, 'credit' => 0],
            ['code' => '42', 'type' => 'Liabilities', 'debit' => 0, 'credit' => 3000],
            ['code' => '51', 'type' => 'Equity', 'debit' => 0, 'credit' => 1000],
            ['code' => '71', 'type' => 'Income', 'debit' => 0, 'credit' => 4000],
            ['code' => '62', 'type' => 'Expenses', 'debit' => 1000, 'credit' => 0],
        ]);

        $this->assertSame(7000.0, $snapshot['totals']['Assets']);
        $this->assertSame(3000.0, $snapshot['totals']['Liabilities']);
        $this->assertSame(4000.0, $snapshot['totals']['Equity']);
        $this->assertSame(0.0, $snapshot['difference']);
    }

    public function test_result_accounts_count_as_equity(): void
    {
        $snapshot = $this->snapshot([
            ['code' => '11', 'type' => 'Assets', 'debit' => 1500, 'credit' => 0],
            ['code' => '88', 'type' => 'Results', 'debit' => 0, 'credit' => 1500],
        ]);

        $this->assertSame(1500.0, $snapshot['totals']['Equity']);
        $this->assertSame(0.0, $snapshot['difference']);
    }

    public function test_overlapping_prefixes_are_not_double_counted(): void
    {
        $snapshot = $this->snapshot([
            ['code' => '4437', 'type' => 'Liabilities', 'debit' => 0, 'credit' => 700],
            ['code' => '44331', 'type' => 'Liabilities', 'debit' => 0, 'credit' => 300],
        ]);

        $this->assertSame(1000.0, $snapshot['totals']['Liabilities']);

        $vatPayable = $this->lineFor($snapshot, 'VAT payable');
        $stateTaxes = $this->lineFor($snapshot, 'State / Tax authorities');
        $this->assertSame(700.0, $vatPayable);
        $this->assertSame(300.0, $stateTaxes);
    }

    public function test_unmapped_and_untyped_accounts_still_reach_the_totals(): void
    {
        $snapshot = $this->snapshot([
            ['code' => '492', 'type' => 'Liabilities', 'debit' => 0, 'credit' => 4400],
            ['code' => '', 'type' => '', 'debit' => 4400, 'credit' => 0],
        ]);

        $this->assertSame(4400.0, $snapshot['other']['Liabilities']);
        $this->assertSame(4400.0, $snapshot['unclassified']);
        $this->assertSame(4400.0, $snapshot['totals']['Assets']);
        $this->assertSame(0.0, $snapshot['difference']);
    }

    public function test_difference_reports_the_unbalanced_ledger_amount(): void
    {
        $snapshot = $this->snapshot([
            ['code' => '11', 'type' => 'Assets', 'debit' => 0, 'credit' => 100000],
        ]);

        $this->assertSame(-100000.0, $snapshot['difference']);
    }

    public function test_closed_year_does_not_double_count_the_result(): void
    {
        $snapshot = $this->snapshot([
            ['code' => '11', 'type' => 'Assets', 'debit' => 3000, 'credit' => 0],
            ['code' => '71', 'type' => 'Income', 'debit' => 3000, 'credit' => 3000],
            ['code' => '88', 'type' => 'Results', 'debit' => 0, 'credit' => 3000],
        ]);

        $this->assertSame(0.0, $snapshot['net_income']);
        $this->assertSame(3000.0, $snapshot['totals']['Equity']);
        $this->assertSame(0.0, $snapshot['difference']);
    }

    private function lineFor(array $snapshot, string $label): float
    {
        $reflection = new \ReflectionClass(BalanceSheetReportService::class);
        $mappings = $reflection->getConstant('LINE_MAPPINGS');

        foreach ($mappings as $index => $mapping) {
            if ($mapping['label'] === $label) {
                return round($snapshot['lines'][$index], 2);
            }
        }

        $this->fail("Line '{$label}' not found.");
    }
}
