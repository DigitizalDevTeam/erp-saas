<?php

namespace Tests\Unit\HotelManagement;

use Modules\HotelManagement\Entities\HotelFolio;
use PHPUnit\Framework\TestCase;

class HotelFolioTest extends TestCase
{
    public function test_payment_status_label(): void
    {
        $pending = new HotelFolio(['grand_total' => 100, 'paid_total' => 0]);
        $this->assertSame('pending', $pending->paymentStatusLabel());

        $partial = new HotelFolio(['grand_total' => 100, 'paid_total' => 40]);
        $this->assertSame('partial', $partial->paymentStatusLabel());

        $paid = new HotelFolio(['grand_total' => 100, 'paid_total' => 100]);
        $this->assertSame('paid', $paid->paymentStatusLabel());

        $zero = new HotelFolio(['grand_total' => 0, 'paid_total' => 0]);
        $this->assertSame('paid', $zero->paymentStatusLabel());
    }
}
