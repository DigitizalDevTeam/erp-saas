<?php

namespace Tests\Unit;

use App\Services\KitchenOccupancyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KitchenOccupancyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('hotel_reservations', function ($table) {
            $table->increments('id');
            $table->string('reservation_no')->nullable();
            $table->integer('hotel_guest_id')->nullable();
            $table->integer('hotel_room_type_id')->nullable();
            $table->integer('hotel_room_id')->nullable();
            $table->integer('branch_id')->nullable();
            $table->date('check_in_date')->nullable();
            $table->date('check_out_date')->nullable();
            $table->integer('adults')->default(1);
            $table->integer('extra_adults')->default(0);
            $table->integer('children')->default(0);
            $table->integer('infants')->default(0);
            $table->string('meal_plan', 20)->nullable();
            $table->string('status', 20)->default('pending');
            $table->integer('created_by')->default(0);
            $table->timestamps();
        });

        // In-house on 2026-11-10 (check-in 9th, check-out 12th): 2 adults
        // + 1 child on half board, 1 infant (infants eat no cover).
        DB::table('hotel_reservations')->insert([
            'id' => 1, 'reservation_no' => 'RES-1', 'branch_id' => null,
            'check_in_date' => '2026-11-09', 'check_out_date' => '2026-11-12',
            'adults' => 2, 'extra_adults' => 0, 'children' => 1, 'infants' => 1,
            'meal_plan' => 'half_board', 'status' => 'confirmed', 'created_by' => 1,
        ]);
        // In-house on 2026-11-10 (checked in): 2 adults, full board.
        DB::table('hotel_reservations')->insert([
            'id' => 2, 'reservation_no' => 'RES-2', 'branch_id' => null,
            'check_in_date' => '2026-11-10', 'check_out_date' => '2026-11-11',
            'adults' => 2, 'extra_adults' => 0, 'children' => 0, 'infants' => 0,
            'meal_plan' => 'full_board', 'status' => 'checked_in', 'created_by' => 1,
        ]);
        // Arrives on 2026-11-10 (check-in == date): not in-house yet
        // before check-in, so it counts (check-in <= date).
        DB::table('hotel_reservations')->insert([
            'id' => 3, 'reservation_no' => 'RES-3', 'branch_id' => null,
            'check_in_date' => '2026-11-10', 'check_out_date' => '2026-11-13',
            'adults' => 1, 'extra_adults' => 1, 'children' => 0, 'infants' => 0,
            'meal_plan' => 'breakfast', 'status' => 'confirmed', 'created_by' => 1,
        ]);
        // Leaves on 2026-11-10 (check-out == date): NOT in-house that day.
        DB::table('hotel_reservations')->insert([
            'id' => 4, 'reservation_no' => 'RES-4', 'branch_id' => null,
            'check_in_date' => '2026-11-08', 'check_out_date' => '2026-11-10',
            'adults' => 4, 'extra_adults' => 0, 'children' => 0, 'infants' => 0,
            'meal_plan' => 'all_inclusive', 'status' => 'checked_in', 'created_by' => 1,
        ]);
        // Pending: not in-house.
        DB::table('hotel_reservations')->insert([
            'id' => 5, 'reservation_no' => 'RES-5', 'branch_id' => null,
            'check_in_date' => '2026-11-09', 'check_out_date' => '2026-11-12',
            'adults' => 3, 'extra_adults' => 0, 'children' => 0, 'infants' => 0,
            'meal_plan' => 'full_board', 'status' => 'pending', 'created_by' => 1,
        ]);
        // Cancelled: not in-house.
        DB::table('hotel_reservations')->insert([
            'id' => 6, 'reservation_no' => 'RES-6', 'branch_id' => null,
            'check_in_date' => '2026-11-09', 'check_out_date' => '2026-11-12',
            'adults' => 2, 'extra_adults' => 0, 'children' => 0, 'infants' => 0,
            'meal_plan' => 'full_board', 'status' => 'cancelled', 'created_by' => 1,
        ]);
        // Another company: never counted.
        DB::table('hotel_reservations')->insert([
            'id' => 7, 'reservation_no' => 'RES-7', 'branch_id' => null,
            'check_in_date' => '2026-11-09', 'check_out_date' => '2026-11-12',
            'adults' => 9, 'extra_adults' => 0, 'children' => 0, 'infants' => 0,
            'meal_plan' => 'full_board', 'status' => 'confirmed', 'created_by' => 4,
        ]);
        // Room only: in-house, but feeds no meal cover.
        DB::table('hotel_reservations')->insert([
            'id' => 8, 'reservation_no' => 'RES-8', 'branch_id' => 1,
            'check_in_date' => '2026-11-09', 'check_out_date' => '2026-11-12',
            'adults' => 2, 'extra_adults' => 0, 'children' => 0, 'infants' => 0,
            'meal_plan' => 'room_only', 'status' => 'confirmed', 'created_by' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('hotel_reservations');

        parent::tearDown();
    }

    private function service(): KitchenOccupancyService
    {
        return new KitchenOccupancyService();
    }

    public function test_snapshot_counts_guests_in_house_on_the_date(): void
    {
        $snapshot = $this->service()->snapshot('2026-11-10', 1);

        // RES-1 (3 guests) + RES-2 (2) + RES-3 (2) + RES-8 (2) = 9.
        // RES-4 left that morning, RES-5 pending, RES-6 cancelled,
        // RES-7 belongs to another company.
        $this->assertSame(4, $snapshot['total_reservations']);
        $this->assertSame(9, $snapshot['total_guests']);
        // Ordered by check-in date, then id: RES-1 and RES-8 checked in
        // on the 9th, RES-2 and RES-3 on the 10th.
        $this->assertSame(['RES-1', 'RES-8', 'RES-2', 'RES-3'], array_column($snapshot['reservations'], 'reservation_no'));
    }

    public function test_snapshot_excludes_checkout_day(): void
    {
        $snapshot = $this->service()->snapshot('2026-11-12', 1);

        // Only RES-3 is still in-house (check-out 13th).
        $this->assertSame(1, $snapshot['total_reservations']);
        $this->assertSame(['RES-3'], array_column($snapshot['reservations'], 'reservation_no'));
    }

    public function test_snapshot_meal_covers_follow_the_meal_plan(): void
    {
        $snapshot = $this->service()->snapshot('2026-11-10', 1);

        // Breakfast: RES-1 half board (3) + RES-2 full board (2) +
        // RES-3 breakfast (2) = 7. Room-only RES-8 feeds nothing.
        $this->assertSame(7, $snapshot['breakfast_covers']);
        // Lunch: only full board (RES-2, 2 guests).
        $this->assertSame(2, $snapshot['lunch_covers']);
        // Dinner: half board (RES-1, 3) + full board (RES-2, 2) = 5.
        $this->assertSame(5, $snapshot['dinner_covers']);
    }

    public function test_infants_are_recorded_but_do_not_eat_a_cover(): void
    {
        $snapshot = $this->service()->snapshot('2026-11-10', 1);

        $res1 = collect($snapshot['reservations'])->firstWhere('reservation_no', 'RES-1');
        $this->assertSame(3, $res1['guests']);
        $this->assertSame(1, $res1['infants']);
        // 3 guests from RES-1, not 4: the infant is not a cover.
        $this->assertSame(9, $snapshot['total_guests']);
    }

    public function test_snapshot_scopes_by_branch_when_given(): void
    {
        $snapshot = $this->service()->snapshot('2026-11-10', 1, 1);

        // Only RES-8 has branch_id 1.
        $this->assertSame(1, $snapshot['total_reservations']);
        $this->assertSame(2, $snapshot['total_guests']);
        $this->assertSame(0, $snapshot['breakfast_covers']);
    }

    public function test_covers_for_meal_returns_one_service_count(): void
    {
        $this->assertSame(7, $this->service()->coversForMeal('2026-11-10', 'breakfast', 1));
        $this->assertSame(2, $this->service()->coversForMeal('2026-11-10', 'lunch', 1));
        $this->assertSame(5, $this->service()->coversForMeal('2026-11-10', 'dinner', 1));
    }

    public function test_snapshot_is_empty_when_nobody_is_in_house(): void
    {
        $snapshot = $this->service()->snapshot('2026-12-25', 1);

        $this->assertSame(0, $snapshot['total_reservations']);
        $this->assertSame(0, $snapshot['total_guests']);
        $this->assertSame([], $snapshot['reservations']);
    }
}
