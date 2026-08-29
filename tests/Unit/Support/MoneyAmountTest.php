<?php

namespace Tests\Unit\Support;

use App\Support\MoneyAmount;
use PHPUnit\Framework\TestCase;

class MoneyAmountTest extends TestCase
{
    public function test_parse_comma_decimal_portuguese_format(): void
    {
        $this->assertSame(22.87, MoneyAmount::parse('22,87'));
    }

    public function test_parse_dot_decimal(): void
    {
        $this->assertSame(22.87, MoneyAmount::parse('22.87'));
    }

    public function test_parse_european_thousands_with_comma_decimal(): void
    {
        $settings = [
            'decimal_separator' => 'comma',
            'thousand_separator' => 'dot',
        ];

        $this->assertSame(1234.56, MoneyAmount::parse('1.234,56', $settings));
    }

    public function test_parse_us_thousands_with_dot_decimal(): void
    {
        $settings = [
            'decimal_separator' => 'dot',
            'thousand_separator' => 'comma',
        ];

        $this->assertSame(1234.56, MoneyAmount::parse('1,234.56', $settings));
    }

    public function test_parse_rounds_to_two_decimals(): void
    {
        $this->assertSame(22.88, MoneyAmount::parse('22.875'));
    }

    public function test_parse_empty_returns_zero(): void
    {
        $this->assertSame(0.0, MoneyAmount::parse(''));
        $this->assertSame(0.0, MoneyAmount::parse(null));
    }

    public function test_equals_within_tolerance(): void
    {
        $this->assertTrue(MoneyAmount::equals(22.87, 22.87));
        $this->assertTrue(MoneyAmount::equals(22.871, 22.87));
        $this->assertFalse(MoneyAmount::equals(22.87, 22.90));
    }
}
