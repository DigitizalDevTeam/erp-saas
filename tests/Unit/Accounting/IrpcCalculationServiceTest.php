<?php

namespace Tests\Unit\Accounting;

use App\Services\IrpcCalculationService;
use PHPUnit\Framework\TestCase;

class IrpcCalculationServiceTest extends TestCase
{
    public function test_it_builds_irpc_payload_from_selected_fiscal_year(): void
    {
        $service = new IrpcCalculationService();

        $payload = $service->buildPayloadFromComparativeReport(
            [
                'year1' => 2026,
                'year2' => 2025,
                'A' => ['y1' => 300.0, 'y2' => 200.0],
                'C' => ['y1' => 50.0, 'y2' => 10.0],
                'E' => ['y1' => 25.0, 'y2' => 5.0],
                'H' => ['y1' => 1000.0, 'y2' => 700.0],
                'income_tax' => ['y1' => 0.0, 'y2' => 0.0],
            ],
            2026,
            0.32,
            [
                'company_name' => 'ACME SA',
                'vat_number' => '123456789',
                'company_address' => 'Maputo',
                'company_city' => 'Maputo',
                'company_telephone' => '258000000',
                'company_email' => 'tax@example.com',
            ]
        );

        $this->assertSame(2026, $payload['fiscal_year']);
        $this->assertSame('ACME SA', $payload['company_name']);
        $this->assertEquals(1000.0, $payload['total_revenue']);
        $this->assertEquals(375.0, $payload['total_expenses_before_tax']);
        $this->assertEquals(625.0, $payload['accounting_profit_before_tax']);
        $this->assertEquals(625.0, $payload['taxable_income']);
        $this->assertEquals(200.0, $payload['irpc_payable']);
        $this->assertEquals(200.0, $payload['net_payable']);
    }

    public function test_it_zeroes_taxable_income_when_accounting_profit_is_negative(): void
    {
        $service = new IrpcCalculationService();

        $payload = $service->buildPayloadFromComparativeReport(
            [
                'year1' => 2026,
                'year2' => 2025,
                'A' => ['y1' => 200.0, 'y2' => 250.0],
                'C' => ['y1' => 20.0, 'y2' => 30.0],
                'E' => ['y1' => 10.0, 'y2' => 40.0],
                'H' => ['y1' => 500.0, 'y2' => 100.0],
                'income_tax' => ['y1' => 0.0, 'y2' => 12.0],
            ],
            2025,
            0.32
        );

        $this->assertSame(2025, $payload['fiscal_year']);
        $this->assertEquals(100.0, $payload['total_revenue']);
        $this->assertEquals(320.0, $payload['total_expenses_before_tax']);
        $this->assertEquals(-220.0, $payload['accounting_profit_before_tax']);
        $this->assertEquals(0.0, $payload['taxable_income']);
        $this->assertEquals(0.0, $payload['irpc_payable']);
        $this->assertEquals(0.0, $payload['net_payable']);
        $this->assertEquals(12.0, $payload['income_tax_expense']);
    }
}
