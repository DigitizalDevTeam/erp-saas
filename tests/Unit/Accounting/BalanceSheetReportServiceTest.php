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

    public function test_credit_orphan_with_supplier_code_routes_to_liabilities(): void
    {
        $snapshot = $this->snapshot([
            ['code' => '4210001', 'type' => '', 'debit' => 0, 'credit' => 380.48],
        ]);

        $this->assertEqualsWithDelta(380.48, $snapshot['other']['Liabilities'], 0.001);
        $this->assertSame(380.48, $snapshot['totals']['Liabilities']);
        $this->assertSame(0.0, $snapshot['unclassified']);
        $this->assertSame(0.0, $snapshot['unclassified_liabilities']);
    }

    public function test_credit_orphan_without_code_uses_unclassified_liabilities_bucket(): void
    {
        $snapshot = $this->snapshot([
            ['code' => '', 'type' => '', 'debit' => 0, 'credit' => 380.48],
        ]);

        $this->assertEqualsWithDelta(380.48, $snapshot['unclassified_liabilities'], 0.001);
        $this->assertSame(380.48, $snapshot['totals']['Liabilities']);
        $this->assertSame(0.0, $snapshot['unclassified']);
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

    public function test_does_not_add_period_result_when_identical_to_results_accounts(): void
    {
        // Closing posted to 88 but income/expense were not cleared — same amount.
        $snapshot = $this->snapshot([
            ['code' => '11', 'type' => 'Assets', 'debit' => 3000, 'credit' => 0],
            ['code' => '71', 'type' => 'Income', 'debit' => 0, 'credit' => 4000],
            ['code' => '62', 'type' => 'Expenses', 'debit' => 1000, 'credit' => 0],
            ['code' => '88', 'type' => 'Results', 'debit' => 0, 'credit' => 3000],
        ]);

        $this->assertSame(0.0, $snapshot['net_income']);
        $this->assertSame(3000.0, $snapshot['totals']['Equity']);
        $this->assertSame(0.0, $snapshot['difference']);
    }

    public function test_prior_results_plus_current_period_still_adds_net_income(): void
    {
        // Mid-year: prior year in 88, current P&L still open — different amounts.
        $snapshot = $this->snapshot([
            ['code' => '11', 'type' => 'Assets', 'debit' => 5000, 'credit' => 0],
            ['code' => '88', 'type' => 'Results', 'debit' => 0, 'credit' => 2000],
            ['code' => '71', 'type' => 'Income', 'debit' => 0, 'credit' => 4000],
            ['code' => '62', 'type' => 'Expenses', 'debit' => 1000, 'credit' => 0],
        ]);

        $this->assertSame(3000.0, $snapshot['net_income']);
        $this->assertSame(5000.0, $snapshot['totals']['Equity']);
        $this->assertSame(0.0, $snapshot['difference']);
    }

    public function test_resolve_filter_defaults_to_full_year_0_to_15(): void
    {
        $resolved = BalanceSheetReportService::resolveFilter([
            'fiscal_year' => 2025,
        ]);

        $this->assertSame('2025-01-01', $resolved['start']);
        $this->assertSame('2025-12-31', $resolved['end']);
        $this->assertSame(0, $resolved['accountingPeriodFrom']);
        $this->assertSame(15, $resolved['accountingPeriodTo']);
        $this->assertSame(2025, $resolved['fiscalYear']);
        $this->assertSame('2024-01-01', $resolved['previousStart']);
        $this->assertSame('2024-12-31', $resolved['previousEnd']);
        $this->assertSame(2024, $resolved['previousFiscalYear']);
    }

    public function test_resolve_filter_date_only_range_uses_start_year_full_0_to_15(): void
    {
        $resolved = BalanceSheetReportService::resolveFilter([
            'start_date' => '2025-01-01',
            'end_date' => '2026-12-31',
        ]);

        $this->assertSame(2025, $resolved['fiscalYear']);
        $this->assertSame(0, $resolved['accountingPeriodFrom']);
        $this->assertSame(15, $resolved['accountingPeriodTo']);
        $this->assertSame('2025-01-01', $resolved['start']);
        $this->assertSame('2025-12-31', $resolved['end']);
        $this->assertSame(2024, $resolved['previousFiscalYear']);
        $this->assertSame('2024-01-01', $resolved['previousStart']);
        $this->assertSame('2024-12-31', $resolved['previousEnd']);
    }

    public function test_resolve_filter_forwards_explicit_months(): void
    {
        $resolved = BalanceSheetReportService::resolveFilter([
            'fiscal_year' => 2026,
            'accounting_period_from' => 1,
            'accounting_period_to' => 3,
        ]);

        $this->assertSame('2026-01-01', $resolved['start']);
        $this->assertSame('2026-03-31', $resolved['end']);
        $this->assertSame(1, $resolved['accountingPeriodFrom']);
        $this->assertSame(3, $resolved['accountingPeriodTo']);
        $this->assertSame('2025-01-01', $resolved['previousStart']);
        $this->assertSame('2025-03-31', $resolved['previousEnd']);
    }

    public function test_resolve_filter_blank_uses_current_calendar_year(): void
    {
        $resolved = BalanceSheetReportService::resolveFilter([]);
        $year = (int) date('Y');

        $this->assertSame($year, $resolved['fiscalYear']);
        $this->assertSame(0, $resolved['accountingPeriodFrom']);
        $this->assertSame(15, $resolved['accountingPeriodTo']);
        $this->assertSame(sprintf('%04d-01-01', $year), $resolved['start']);
        $this->assertSame(sprintf('%04d-12-31', $year), $resolved['end']);
    }

    public function test_cash_credit_balance_matches_trial_balance_magnitude(): void
    {
        $snapshot = $this->snapshot([
            ['code' => '11', 'type' => 'Assets', 'debit' => 0, 'credit' => 898474.10],
            ['code' => '21', 'type' => 'Assets', 'debit' => 95384.57, 'credit' => 0],
        ]);

        $this->assertEqualsWithDelta(-898474.10, $this->lineFor($snapshot, 'Cash'), 0.001);
        $this->assertEqualsWithDelta(95384.57, $this->lineFor($snapshot, 'Purchases / Inventory'), 0.001);
    }

    public function test_vat_payable_is_hidden_when_4437_has_no_balance(): void
    {
        $current = $this->snapshot([
            ['code' => '11', 'type' => 'Assets', 'debit' => 0, 'credit' => 898474.10],
            ['code' => '21', 'type' => 'Assets', 'debit' => 95384.57, 'credit' => 0],
            ['code' => '443', 'type' => 'Liabilities', 'debit' => 4359.31, 'credit' => 14573.76],
        ]);
        $previous = $this->snapshot([]);
        $rows = $this->rowsFrom($current, $previous);

        $this->assertNull($this->rowFor($rows, 'VAT payable'));
        $this->assertNotNull($this->rowFor($rows, 'State / Tax authorities'));
        $this->assertEqualsWithDelta(-898474.10, $this->rowFor($rows, 'Cash')['current_year'], 0.001);
        $this->assertEqualsWithDelta(95384.57, $this->rowFor($rows, 'Purchases / Inventory')['current_year'], 0.001);
    }

    public function test_vat_payable_is_shown_when_4437_has_a_balance(): void
    {
        $current = $this->snapshot([
            ['code' => '4437', 'type' => 'Liabilities', 'debit' => 0, 'credit' => 700],
        ]);
        $previous = $this->snapshot([]);
        $rows = $this->rowsFrom($current, $previous);

        $vat = $this->rowFor($rows, 'VAT payable');
        $this->assertNotNull($vat);
        $this->assertSame(700.0, $vat['current_year']);
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

    /**
     * @param  array{lines: array<int, float>, other: array<string, float>, unclassified: float, net_income: float}  $current
     * @param  array{lines: array<int, float>, other: array<string, float>, unclassified: float, net_income: float}  $previous
     * @return list<array<string, mixed>>
     */
    private function rowsFrom(array $current, array $previous): array
    {
        $method = (new \ReflectionClass(BalanceSheetReportService::class))->getMethod('buildRows');
        $method->setAccessible(true);

        return $method->invoke(new BalanceSheetReportService(), $current, $previous);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function rowFor(array $rows, string $label): ?array
    {
        foreach ($rows as $row) {
            if (($row['label'] ?? '') === $label) {
                return $row;
            }
        }

        return null;
    }
}
