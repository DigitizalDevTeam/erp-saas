<?php

namespace Tests\Unit\Accounting;

use App\Support\AccountingPeriodDate;
use PHPUnit\Framework\TestCase;

class AccountingPeriodDateTest extends TestCase
{
    public function test_special_periods_always_have_31_days(): void
    {
        $this->assertSame(31, AccountingPeriodDate::daysInPeriod(2026, 0));
        $this->assertSame(31, AccountingPeriodDate::daysInPeriod(2026, 13));
        $this->assertSame(31, AccountingPeriodDate::daysInPeriod(2026, 14));
        $this->assertSame(31, AccountingPeriodDate::daysInPeriod(2026, 15));
    }

    public function test_compose_date_for_operational_month(): void
    {
        $this->assertSame('2026-02-28', AccountingPeriodDate::composeDate(2026, 2, 31));
        $this->assertSame('2026-03-15', AccountingPeriodDate::composeDate(2026, 3, 15));
    }

    public function test_compose_date_for_opening_and_closing_periods(): void
    {
        $this->assertSame('2026-01-31', AccountingPeriodDate::composeDate(2026, 0, 31));
        $this->assertSame('2026-12-31', AccountingPeriodDate::composeDate(2026, 14, 31));
    }

    public function test_from_request_sets_accounting_period_from_month(): void
    {
        $resolved = AccountingPeriodDate::fromRequest([
            'journal_year' => 2024,
            'journal_month' => 14,
            'journal_day' => 31,
        ]);

        $this->assertSame(14, $resolved['accounting_period']);
        $this->assertSame('2024-12-31', $resolved['date']);
    }

    public function test_parse_prefers_accounting_period_over_calendar_month(): void
    {
        $parsed = AccountingPeriodDate::parse('2024-12-31', 14);

        $this->assertSame(2024, $parsed['year']);
        $this->assertSame(14, $parsed['month']);
        $this->assertSame(31, $parsed['day']);
    }
}
