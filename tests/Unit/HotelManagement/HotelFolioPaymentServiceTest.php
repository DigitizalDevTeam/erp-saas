<?php

namespace Tests\Unit\HotelManagement;

use Modules\HotelManagement\Services\HotelFolioPaymentService;
use Tests\TestCase;

class HotelFolioPaymentServiceTest extends TestCase
{
    public function test_normalize_method_maps_aliases(): void
    {
        $this->assertSame('bank_transfer', HotelFolioPaymentService::normalizeMethod('transfer'));
        $this->assertSame('bank_transfer', HotelFolioPaymentService::normalizeMethod('account'));
        $this->assertSame('cash', HotelFolioPaymentService::normalizeMethod('other'));
        $this->assertSame('mpesa', HotelFolioPaymentService::normalizeMethod('mpesa'));
    }

    public function test_allowed_methods_include_front_desk_options(): void
    {
        $allowed = HotelFolioPaymentService::allowedMethods();

        $this->assertContains('cash', $allowed);
        $this->assertContains('mpesa', $allowed);
        $this->assertContains('card', $allowed);
        $this->assertContains('bank_transfer', $allowed);
    }
}
