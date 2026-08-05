<?php

namespace Tests\Unit\Accounting;

use App\Http\Controllers\ReportController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ProfitLossBalanceTest extends TestCase
{
    private function balance(string $type, float $debit, float $credit): float
    {
        $reflection = new ReflectionClass(ReportController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('profitLossBalance');
        $method->setAccessible(true);

        return $method->invoke($controller, $type, $debit, $credit);
    }

    public function test_income_is_positive_when_credited(): void
    {
        $this->assertSame(91086.0, $this->balance('Income', 0, 91086));
    }

    public function test_expenses_are_positive_when_debited(): void
    {
        $this->assertSame(784030.22, $this->balance('Expenses', 784030.22, 0));
        $this->assertSame(1200.0, $this->balance('Costs of Goods Sold', 1200, 0));
    }

    public function test_profit_is_income_minus_expenses(): void
    {
        $income = $this->balance('Income', 0, 91086);
        $expenses = $this->balance('Expenses', 784030.22, 0);

        $this->assertSame(-692944.22, round($income - $expenses, 2));
    }

    public function test_contra_entries_keep_their_sign(): void
    {
        $this->assertSame(-500.0, $this->balance('Income', 500, 0));
        $this->assertSame(-500.0, $this->balance('Expenses', 0, 500));
    }
}
