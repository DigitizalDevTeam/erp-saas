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
    }
}
