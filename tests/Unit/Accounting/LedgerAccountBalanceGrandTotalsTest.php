<?php

namespace Tests\Unit\Accounting;

use App\Services\Accounting\LedgerAccountBalanceService;
use PHPUnit\Framework\TestCase;

class LedgerAccountBalanceGrandTotalsTest extends TestCase
{
    public function test_aggregate_grand_totals_sums_remapped_balances(): void
    {
        $balances = [
            11 => ['totalDebit' => 100.04, 'totalCredit' => 50.0],
            12 => ['totalDebit' => 200.0, 'totalCredit' => 250.04],
        ];

        $totals = LedgerAccountBalanceService::aggregateGrandTotalsFromBalances($balances);

        $this->assertSame(300.04, $totals['totalDebit']);
        $this->assertSame(300.04, $totals['totalCredit']);
    }

    public function test_remap_preserves_grand_total_sums(): void
    {
        $before = [
            171 => ['totalDebit' => 100.02, 'totalCredit' => 0.0],
            698 => ['totalDebit' => 50.0, 'totalCredit' => 50.04],
        ];

        $after = LedgerAccountBalanceService::rekeyBalancesToCompanyAccounts(
            $before,
            [
                171 => ['code' => '4437', 'created_by' => 2],
                698 => ['code' => '4437', 'created_by' => 6],
            ],
            ['4437' => 698],
            6
        );

        $beforeTotals = LedgerAccountBalanceService::aggregateGrandTotalsFromBalances($before);
        $afterTotals = LedgerAccountBalanceService::aggregateGrandTotalsFromBalances($after);

        $this->assertEqualsWithDelta(
            $beforeTotals['totalDebit'],
            $afterTotals['totalDebit'],
            0.001
        );
        $this->assertEqualsWithDelta(
            $beforeTotals['totalCredit'],
            $afterTotals['totalCredit'],
            0.001
        );
    }

    public function test_aggregate_force_balances_cent_level_rounding_drift(): void
    {
        $balances = [
            1 => ['totalDebit' => 100.0, 'totalCredit' => 0.0],
            2 => ['totalDebit' => 0.0, 'totalCredit' => 100.04],
        ];

        $totals = LedgerAccountBalanceService::aggregateGrandTotalsFromBalances($balances);

        $this->assertSame($totals['totalDebit'], $totals['totalCredit']);
        $this->assertSame(100.0, $totals['totalDebit']);
        $this->assertSame(100.0, $totals['totalCredit']);
    }

    public function test_aggregate_force_balances_three_cent_trial_balance_gap(): void
    {
        $balances = [
            1 => ['totalDebit' => 15299028.52, 'totalCredit' => 0.0],
            2 => ['totalDebit' => 0.0, 'totalCredit' => 15299028.55],
        ];

        $totals = LedgerAccountBalanceService::aggregateGrandTotalsFromBalances($balances);

        $this->assertSame($totals['totalDebit'], $totals['totalCredit']);
        $this->assertSame(15299028.52, $totals['totalDebit']);
        $this->assertSame(15299028.52, $totals['totalCredit']);
    }

    public function test_aggregate_does_not_force_balance_large_imbalances(): void
    {
        $balances = [
            1 => ['totalDebit' => 100.0, 'totalCredit' => 0.0],
            2 => ['totalDebit' => 0.0, 'totalCredit' => 150.0],
        ];

        $totals = LedgerAccountBalanceService::aggregateGrandTotalsFromBalances($balances);

        $this->assertSame(100.0, $totals['totalDebit']);
        $this->assertSame(150.0, $totals['totalCredit']);
    }

    public function test_force_balance_within_tolerance_helper(): void
    {
        $balanced = LedgerAccountBalanceService::forceBalanceWithinTolerance([
            'totalDebit' => 15.02,
            'totalCredit' => 15.05,
        ]);

        $this->assertSame(15.02, $balanced['totalDebit']);
        $this->assertSame(15.02, $balanced['totalCredit']);
    }
}
