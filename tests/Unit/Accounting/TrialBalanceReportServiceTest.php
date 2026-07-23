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
        $this->assertSame(3, $resolved['accountingPeriodFrom']);
        $this->assertSame(3, $resolved['accountingPeriodTo']);
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

    public function test_resolve_filter_uses_full_year_for_opening_period(): void
    {
        $resolved = TrialBalanceReportService::resolveFilter([
            'fiscal_year' => 2027,
            'accounting_period' => 0,
        ]);

        $this->assertSame('2027-01-01', $resolved['start']);
        $this->assertSame('2027-12-31', $resolved['end']);
        $this->assertSame(0, $resolved['accountingPeriod']);
    }

    public function test_resolve_filter_supports_period_range_0_to_1(): void
    {
        $resolved = TrialBalanceReportService::resolveFilter([
            'fiscal_year' => 2026,
            'accounting_period_from' => 0,
            'accounting_period_to' => 1,
        ]);

        $this->assertSame('2026-01-01', $resolved['start']);
        $this->assertSame('2026-01-31', $resolved['end']);
        $this->assertSame(0, $resolved['accountingPeriodFrom']);
        $this->assertSame(1, $resolved['accountingPeriodTo']);
        $this->assertNull($resolved['accountingPeriod']);
    }

    public function test_resolve_filter_supports_period_range_0_to_12(): void
    {
        $resolved = TrialBalanceReportService::resolveFilter([
            'fiscal_year' => 2026,
            'accounting_period_from' => 0,
            'accounting_period_to' => 12,
        ]);

        $this->assertSame('2026-01-01', $resolved['start']);
        $this->assertSame('2026-12-31', $resolved['end']);
        $this->assertSame(0, $resolved['accountingPeriodFrom']);
        $this->assertSame(12, $resolved['accountingPeriodTo']);
    }

    public function test_resolve_filter_supports_period_range_13_to_14(): void
    {
        $resolved = TrialBalanceReportService::resolveFilter([
            'fiscal_year' => 2026,
            'accounting_period_from' => 13,
            'accounting_period_to' => 14,
        ]);

        $this->assertSame('2026-01-01', $resolved['start']);
        $this->assertSame('2026-12-31', $resolved['end']);
        $this->assertSame(13, $resolved['accountingPeriodFrom']);
        $this->assertSame(14, $resolved['accountingPeriodTo']);
        $this->assertNull($resolved['accountingPeriod']);
    }

    public function test_normalize_period_range_swaps_inverted_bounds(): void
    {
        [$from, $to] = TrialBalanceReportService::normalizePeriodRange([
            'accounting_period_from' => 14,
            'accounting_period_to' => 13,
        ]);

        $this->assertSame(13, $from);
        $this->assertSame(14, $to);
    }
}
