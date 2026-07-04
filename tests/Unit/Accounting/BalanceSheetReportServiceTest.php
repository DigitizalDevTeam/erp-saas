<?php

namespace Tests\Unit\Accounting;

use App\Services\BalanceSheetReportService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class BalanceSheetReportServiceTest extends TestCase
{
    public function test_assets_use_debit_minus_credit_expression(): void
    {
        $service = new BalanceSheetReportService();
        $method = (new ReflectionClass($service))->getMethod('balanceExpression');
        $method->setAccessible(true);

        $this->assertSame(
            'transaction_lines.debit - transaction_lines.credit',
            $method->invoke($service, 'Assets')
        );
    }

    public function test_liabilities_and_equity_use_credit_minus_debit_expression(): void
    {
        $service = new BalanceSheetReportService();
        $method = (new ReflectionClass($service))->getMethod('balanceExpression');
        $method->setAccessible(true);

        $this->assertSame(
            'transaction_lines.credit - transaction_lines.debit',
            $method->invoke($service, 'Liabilities')
        );
        $this->assertSame(
            'transaction_lines.credit - transaction_lines.debit',
            $method->invoke($service, 'Equity')
        );
    }
}
