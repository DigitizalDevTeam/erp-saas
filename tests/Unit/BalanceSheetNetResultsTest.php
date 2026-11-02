<?php

namespace Tests\Unit;

use App\Services\BalanceSheetReportService;
use Tests\TestCase;

/**
 * Balance Sheet: the period result must appear under Net results (88)
 * — the Net Profit/Loss line — not as a separate interim
 * "Current Period Result" line (client case: 73,448.66).
 *
 * Presentation-only fix in buildRows(): net_income is folded into the
 * Net results row and the separate row is gone. Totals are unchanged
 * because computeSnapshot already included net_income in the Equity
 * total. A closed year (result already in 88) is unchanged, because
 * its net_income is 0 after the double-count guard.
 */
class BalanceSheetNetResultsTest extends TestCase
{
    private function rowsFor(array $accounts): array
    {
        $service = new BalanceSheetReportService;

        $compute = new \ReflectionMethod($service, 'computeSnapshot');
        $compute->setAccessible(true);
        $current = $compute->invoke($service, $accounts);
        $previous = $compute->invoke($service, []);

        $buildRows = new \ReflectionMethod($service, 'buildRows');
        $buildRows->setAccessible(true);

        return [
            'rows' => $buildRows->invoke($service, $current, $previous),
            'snapshot' => $current,
        ];
    }

    private function netResultsRow(array $rows): array
    {
        foreach ($rows as $row) {
            if ($row['code_label'] === '88') {
                return $row;
            }
        }

        $this->fail('Net results (88) row missing from Balance Sheet rows.');
    }

    private function equityRowsSum(array $rows): float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            if ($row['section'] === 'Equity') {
                $sum += $row['current_year'];
            }
        }

        return round($sum, 2);
    }

    public function test_open_year_result_moves_to_net_results_and_totals_unchanged(): void
    {
        // Example income minus expenses = 73,448.66, nothing closed yet.
        $result = $this->rowsFor([
            ['code' => '71', 'type' => 'Income', 'debit' => 0, 'credit' => 100000.00],
            ['code' => '61', 'type' => 'Expenses', 'debit' => 26551.34, 'credit' => 0],
        ]);

        $this->assertSame(73448.66, $result['snapshot']['net_income']);
        $this->assertSame(73448.66, $result['snapshot']['totals']['Equity']);

        $netResults = $this->netResultsRow($result['rows']);
        $this->assertSame(73448.66, $netResults['current_year']);

        foreach ($result['rows'] as $row) {
            $this->assertNotSame('Current Period Result', $row['label']);
        }

        // Equity rows must still sum to the Equity total.
        $this->assertSame(73448.66, $this->equityRowsSum($result['rows']));
    }

    public function test_closed_year_result_is_not_doubled(): void
    {
        // Same operational result, already closed into account 88.
        $result = $this->rowsFor([
            ['code' => '71', 'type' => 'Income', 'debit' => 0, 'credit' => 100000.00],
            ['code' => '61', 'type' => 'Expenses', 'debit' => 26551.34, 'credit' => 0],
            ['code' => '88', 'type' => 'Results', 'debit' => 0, 'credit' => 73448.66],
        ]);

        $this->assertSame(0.0, $result['snapshot']['net_income']);
        $this->assertSame(73448.66, $result['snapshot']['totals']['Equity']);

        $netResults = $this->netResultsRow($result['rows']);
        $this->assertSame(73448.66, $netResults['current_year']);
        $this->assertSame(73448.66, $this->equityRowsSum($result['rows']));
    }

    public function test_balance_sheet_blades_no_longer_reference_current_period_result_line(): void
    {
        foreach ([
            'resources/views/report/balance_sheet.blade.php',
            'resources/views/report/balance_sheet_horizontal.blade.php',
        ] as $blade) {
            $content = file_get_contents(base_path($blade));
            $this->assertStringNotContainsString('Current Period Result is the net income', $content);
            $this->assertStringContainsString('Net results (88) includes the current period', $content);
        }
    }
}
