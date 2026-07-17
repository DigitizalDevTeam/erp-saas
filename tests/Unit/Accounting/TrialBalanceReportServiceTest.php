<?php

namespace Tests\Unit\Accounting;

use App\Services\TrialBalanceReportService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class TrialBalanceReportServiceTest extends TestCase
{
    public function test_it_normalizes_negative_debit_into_credit(): void
    {
        $service = new TrialBalanceReportService();
        $method = (new ReflectionClass($service))->getMethod('normalizeAmounts');
        $method->setAccessible(true);

        $result = $method->invoke($service, -50.0, 100.0);

        $this->assertSame(0.0, $result['totalDebit']);
        $this->assertSame(150.0, $result['totalCredit']);
    }

    public function test_it_treats_zero_amounts_as_no_activity(): void
    {
        $service = new TrialBalanceReportService();
        $method = (new ReflectionClass($service))->getMethod('hasActivity');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($service, 0.0, 0.0));
        $this->assertTrue($method->invoke($service, 10.0, 0.0));
        $this->assertTrue($method->invoke($service, 0.0, 10.0));
        $this->assertFalse($method->invoke($service, 100.0, 100.0));
        $this->assertTrue($method->invoke($service, 100.0, 50.0));
    }

    public function test_default_date_range_covers_full_fiscal_year(): void
    {
        $range = TrialBalanceReportService::defaultDateRange(2026);

        $this->assertSame('2026-01-01', $range['start']);
        $this->assertSame('2026-12-31', $range['end']);
    }

    public function test_resolve_filter_uses_month_dates_for_operational_period(): void
    {
        $resolved = TrialBalanceReportService::resolveFilter([
            'fiscal_year' => 2026,
            'accounting_period' => 3,
        ]);

        $this->assertSame('2026-03-01', $resolved['start']);
        $this->assertSame('2026-03-31', $resolved['end']);
        $this->assertSame(3, $resolved['accountingPeriod']);
    }

    public function test_resolve_filter_uses_full_year_for_closing_period(): void
    {
        $resolved = TrialBalanceReportService::resolveFilter([
            'fiscal_year' => 2026,
            'accounting_period' => 15,
        ]);

        $this->assertSame('2026-01-01', $resolved['start']);
        $this->assertSame('2026-12-31', $resolved['end']);
        $this->assertSame(15, $resolved['accountingPeriod']);
    }
}
