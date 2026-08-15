<?php

namespace Tests\Unit\Accounting;

use App\Services\Accounting\LedgerAccountBalanceService;
use PHPUnit\Framework\TestCase;

class LedgerAccountBalanceRemapTest extends TestCase
{
    public function test_foreign_account_balances_remap_onto_company_code(): void
    {
        $remapped = LedgerAccountBalanceService::rekeyBalancesToCompanyAccounts(
            [
                171 => ['totalDebit' => 178001.25, 'totalCredit' => 0.0],
                698 => ['totalDebit' => 10.0, 'totalCredit' => 0.0],
            ],
            [
                171 => ['code' => '4437', 'created_by' => 2],
                698 => ['code' => '4437', 'created_by' => 6],
            ],
            ['4437' => 698],
            6
        );

        $this->assertSame([698], array_keys($remapped));
        $this->assertEqualsWithDelta(178011.25, $remapped[698]['totalDebit'], 0.001);
        $this->assertEqualsWithDelta(0.0, $remapped[698]['totalCredit'], 0.001);
    }

    public function test_missing_chart_account_stays_on_original_id(): void
    {
        $remapped = LedgerAccountBalanceService::rekeyBalancesToCompanyAccounts(
            [
                1 => ['totalDebit' => 380.48, 'totalCredit' => 0.0],
            ],
            [
                1 => null,
            ],
            [],
            6
        );

        $this->assertSame([1], array_keys($remapped));
        $this->assertEqualsWithDelta(380.48, $remapped[1]['totalDebit'], 0.001);
    }

    public function test_foreign_code_without_company_match_keeps_foreign_id(): void
    {
        $remapped = LedgerAccountBalanceService::rekeyBalancesToCompanyAccounts(
            [
                999 => ['totalDebit' => 50.0, 'totalCredit' => 0.0],
            ],
            [
                999 => ['code' => '9999', 'created_by' => 2],
            ],
            [],
            6
        );

        $this->assertSame([999], array_keys($remapped));
        $this->assertEqualsWithDelta(50.0, $remapped[999]['totalDebit'], 0.001);
    }
}
