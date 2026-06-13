<?php

namespace Tests\Unit\HotelManagement;

use Modules\HotelManagement\Entities\HotelBookingSite;
use Modules\HotelManagement\Services\HotelStripePaymentService;
use Tests\TestCase;

class HotelStripePaymentServiceTest extends TestCase
{
    public function test_stripe_ready_requires_prepay_mode_and_keys(): void
    {
        $service = $this->app->make(HotelStripePaymentService::class);

        $site = new HotelBookingSite([
            'payment_mode' => HotelBookingSite::PAY_AT_HOTEL,
            'payment_config' => [
                'stripe_key' => 'pk_test_abc',
                'stripe_secret' => 'sk_test_xyz',
            ],
        ]);

        $status = $service->stripeStatus($site);

        $this->assertTrue($status['has_keys']);
        $this->assertFalse($status['is_prepay_mode']);
        $this->assertFalse($status['is_ready']);

        $site->payment_mode = HotelBookingSite::PAY_PREPAY;
        $status = $service->stripeStatus($site);

        $this->assertTrue($status['is_ready']);
    }
}
