<?php

namespace Tests\Unit\HotelManagement;

use Carbon\Carbon;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Services\HotelReservationPricingService;
use Tests\TestCase;

class HotelReservationPricingServiceTest extends TestCase
{
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
}
