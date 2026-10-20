<?php

namespace Tests\Unit\HotelManagement;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoomType;
use Modules\HotelManagement\Services\HotelReservationPricingService;
use Tests\TestCase;

class HotelReservationPricingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The pricing service loads rate plans from the DB and reservation
        // totals load the reservationAddons relation — both tables must
        // exist in the in-memory test database.
        Schema::create('hotel_rate_plans', function ($table) {
            $table->id();
            $table->unsignedBigInteger('hotel_room_type_id')->nullable();
            $table->string('name')->nullable();
            $table->decimal('rate', 15, 2)->default(0);
            $table->decimal('weekend_rate', 15, 2)->nullable();
            $table->decimal('monthly_rate', 15, 2)->default(0);
            $table->string('billing_mode', 16)->default('nightly');
            $table->string('plan_type', 32)->default('period');
            $table->integer('priority')->default(100);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::create('hotel_reservation_addons', function ($table) {
            $table->id();
            $table->unsignedBigInteger('hotel_reservation_id')->nullable();
            $table->unsignedBigInteger('hotel_addon_id')->nullable();
            $table->unsignedInteger('qty')->default(1);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->string('billing_mode', 16)->default('per_stay');
            $table->unsignedBigInteger('hotel_folio_line_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('hotel_reservation_addons');
        Schema::dropIfExists('hotel_rate_plans');

        parent::tearDown();
    }

    private function seedPeriodPlan(?float $weekendRate): void
    {
        DB::table('hotel_rate_plans')->insert([
            'hotel_room_type_id' => 1,
            'name' => 'Test period plan',
            'rate' => 100,
            'weekend_rate' => $weekendRate,
            'monthly_rate' => 0,
            'billing_mode' => 'nightly',
            'plan_type' => 'period',
            'priority' => 100,
            'valid_from' => null,
            'valid_to' => null,
            'is_active' => 1,
            'created_by' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function roomType(): HotelRoomType
    {
        $type = new HotelRoomType();
        $type->forceFill([
            'id' => 1,
            'base_price' => 0,
            'nightly_rate' => 100,
            'monthly_rate' => 0,
            'default_billing_mode' => 'nightly',
        ]);

        return $type;
    }

    private function priceNightlyStay(HotelReservationPricingService $service, Carbon $in, Carbon $out, int $units): array
    {
        $ref = new \ReflectionClass(HotelReservationPricingService::class);
        $method = $ref->getMethod('priceNightlyStay');
        $method->setAccessible(true);

        return $method->invoke($service, 1, $this->roomType(), null, $in, $out, $units);
    }
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
        $this->seedPeriodPlan(80.0);
        $service = new HotelReservationPricingService();

        // Thu 14:00 → Mon 11:00 = 4 nights: Thu, Fri, Sat, Sun
        $result = $this->priceNightlyStay(
            $service,
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
        $this->seedPeriodPlan(null);
        $service = new HotelReservationPricingService();

        $result = $this->priceNightlyStay(
            $service,
            Carbon::parse('2026-05-07 14:00:00'),
            Carbon::parse('2026-05-11 11:00:00'),
            4
        );

        $this->assertFalse($result['uses_weekend_rate']);
        $this->assertSame(400.0, $result['subtotal']);
    }
}
