<?php

namespace Tests\Unit\Accounting;

use App\Models\Customer;
use App\Services\Accounting\CustomerCreditLimitService;
use Tests\TestCase;

class CustomerCreditLimitServiceTest extends TestCase
{
    public function test_not_exceeded_when_no_limit_and_no_overdue(): void
    {
        $customer = new Customer([
            'credit_limit' => 0,
            'created_by' => 1,
        ]);
        $customer->id = 1;

        $svc = new class extends CustomerCreditLimitService {
            protected function openReceivable(int $customerId, int $companyId, ?int $excludeInvoiceId = null): float
            {
                return 0;
            }

            protected function overdueReceivable(int $customerId, int $companyId, ?int $excludeInvoiceId = null): float
            {
                return 0;
            }
        };

        $result = $svc->evaluate($customer, 500);
        $this->assertFalse($result['exceeded']);
    }

    public function test_exceeded_by_amount(): void
    {
        $customer = new Customer([
            'credit_limit' => 1000,
            'created_by' => 1,
        ]);
        $customer->id = 1;

        $svc = new class extends CustomerCreditLimitService {
            protected function openReceivable(int $customerId, int $companyId, ?int $excludeInvoiceId = null): float
            {
                return 800;
            }

            protected function overdueReceivable(int $customerId, int $companyId, ?int $excludeInvoiceId = null): float
            {
                return 0;
            }
        };

        $result = $svc->evaluate($customer, 300);
        $this->assertTrue($result['exceeded']);
        $this->assertContains('amount', $result['reasons']);
        $this->assertEquals(1100.0, $result['projected']);
    }

    public function test_exceeded_by_overdue_days(): void
    {
        $customer = new Customer([
            'credit_limit' => 50000,
            'created_by' => 1,
        ]);
        $customer->id = 1;

        $svc = new class extends CustomerCreditLimitService {
            protected function openReceivable(int $customerId, int $companyId, ?int $excludeInvoiceId = null): float
            {
                return 100;
            }

            protected function overdueReceivable(int $customerId, int $companyId, ?int $excludeInvoiceId = null): float
            {
                return 100;
            }
        };

        $result = $svc->evaluate($customer, 50);
        $this->assertTrue($result['exceeded']);
        $this->assertContains('days', $result['reasons']);
    }
}
