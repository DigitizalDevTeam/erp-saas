<?php

namespace Tests\Unit\HotelManagement;

use Carbon\Carbon;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoomType;
use Modules\HotelManagement\Services\HotelReservationPricingService;
use Tests\TestCase;

class HotelReservationPricingServiceTest extends TestCase
{
    public function test_quote_falls_back_to_base_price_when_nightly_rate_is_zero(): void
    {
        $type = new HotelRoomType([
            'base_price' => 120,
            'nightly_rate' => 0,
            'default_billing_mode' => 'nightly',
        ]);

        $service = new class extends HotelReservationPricingService {
            public function quoteType(HotelRoomType $type): float
            {
                $ref = new \ReflectionClass(HotelReservationPricingService::class);
                $method = $ref->getMethod('resolveTypeDefaultRate');
                $method->setAccessible(true);

                return $method->invoke($this, $type, 'nightly');
            }
        };

        $this->assertSame(120.0, $service->quoteType($type));
    }

    public function test_calculate_units_supports_nightly_and_monthly_modes(): void
    {
        $service = new HotelReservationPricingService();

        [$nightlyUnits, $nightlyLabel] = $service->calculateUnits(
            Carbon::parse('2026-05-01 14:00:00'),
            Carbon::parse('2026-05-03 11:00:00'),
            'nightly'
        );
        [$monthlyUnits, $monthlyLabel] = $service->calculateUnits(
            Carbon::parse('2026-05-01 14:00:00'),
            Carbon::parse('2026-07-05 11:00:00'),
            'monthly'
        );

        $this->assertSame(2, $nightlyUnits);
        $this->assertSame('Nights', $nightlyLabel);
        $this->assertSame(3, $monthlyUnits);
        $this->assertSame('Months', $monthlyLabel);
    }

    public function test_reservation_totals_use_billing_mode_units(): void
    {
        $service = new HotelReservationPricingService();
        $reservation = new HotelReservation([
            'billing_mode' => 'monthly',
            'check_in_at' => '2026-01-01 12:00:00',
            'check_out_at' => '2026-03-15 10:00:00',
            'rate_per_night' => 1500,
            'discount_amount' => 250,
        ]);

        $totals = $service->reservationTotals($reservation);

        $this->assertSame(3, $totals['units']);
        $this->assertSame('Months', $totals['unit_label']);
        $this->assertSame(4500.0, $totals['sub_total']);
        $this->assertSame(4250.0, $totals['grand_total']);
    }

    public function test_discount_validation_uses_role_cap_percentage(): void
    {
        $service = new class extends HotelReservationPricingService {
            public function maxDiscountPercentForCurrentUser(): float
            {
                return 10.0;
            }
        };

        $this->assertTrue($service->validateDiscountAmount(100, 1000));
        $this->assertFalse($service->validateDiscountAmount(101, 1000));
    }

    public function test_weekend_rate_applies_fri_sat_sun_nights_only(): void
    {
        $service = new HotelReservationPricingService();
        $ref = new \ReflectionClass(HotelReservationPricingService::class);
        $method = $ref->getMethod('priceNightlyStay');
        $method->setAccessible(true);

        $ratePlan = new \Modules\HotelManagement\Entities\HotelRatePlan([
            'rate' => 100,
            'weekend_rate' => 80,
        ]);

        // Thu 14:00 → Mon 11:00 = 4 nights: Thu, Fri, Sat, Sun
        $result = $method->invoke(
            $service,
            null,
            null,
            $ratePlan,
            Carbon::parse('2026-05-07 14:00:00'), // Thursday
            Carbon::parse('2026-05-11 11:00:00'), // Monday
            4
        );

        // Thu 100 + Fri 80 + Sat 80 + Sun 80 = 340
        $this->assertTrue($result['uses_weekend_rate']);
        $this->assertSame(340.0, $result['subtotal']);
        $this->assertSame(100.0, $result['base_rate']);
        $this->assertSame(80.0, $result['weekend_rate']);
    }

    public function test_flat_rate_when_weekend_rate_not_set(): void
    {
        $service = new HotelReservationPricingService();
        $ref = new \ReflectionClass(HotelReservationPricingService::class);
        $method = $ref->getMethod('priceNightlyStay');
        $method->setAccessible(true);

        $ratePlan = new \Modules\HotelManagement\Entities\HotelRatePlan([
            'rate' => 100,
            'weekend_rate' => null,
        ]);

        $result = $method->invoke(
            $service,
            null,
            null,
            $ratePlan,
            Carbon::parse('2026-05-07 14:00:00'),
            Carbon::parse('2026-05-11 11:00:00'),
            4
        );

        $this->assertFalse($result['uses_weekend_rate']);
        $this->assertSame(400.0, $result['subtotal']);
    }
}
