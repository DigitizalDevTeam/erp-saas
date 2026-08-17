<?php

namespace Tests\Unit\Accounting;

use App\Services\Accounting\AccountingReportFilterService;
use App\Services\BalanceSheetReportService;
use PHPUnit\Framework\TestCase;

class AccountingReportFilterServiceTest extends TestCase
{
    public function test_blank_months_default_to_full_year_0_to_15(): void
    {
        $resolved = AccountingReportFilterService::resolve([
            'fiscal_year' => 2025,
        ]);

        $this->assertSame('2025-01-01', $resolved['start']);
        $this->assertSame('2025-12-31', $resolved['end']);
        $this->assertSame(0, $resolved['accountingPeriodFrom']);
        $this->assertSame(15, $resolved['accountingPeriodTo']);
    }

    public function test_balance_sheet_uses_shared_filter_with_previous_year(): void
    {
        $resolved = BalanceSheetReportService::resolveFilter([
            'fiscal_year' => 2025,
        ]);

        $this->assertSame('2024-01-01', $resolved['previousStart']);
        $this->assertSame('2024-12-31', $resolved['previousEnd']);
        $this->assertSame(0, $resolved['accountingPeriodFrom']);
        $this->assertSame(15, $resolved['accountingPeriodTo']);
    }
}
