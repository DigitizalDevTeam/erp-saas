<?php

namespace Tests\Feature\HotelManagement;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelBookingSite;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Entities\HotelRoomType;
use Tests\TestCase;

class HotelPublicBookingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guest_can_book_on_published_site(): void
    {
        if (! Schema::hasTable('hotel_booking_sites')) {
            $this->markTestSkipped('hotel_booking_sites table not migrated.');
        }

        $company = User::factory()->create(['type' => 'company']);
        $creatorId = (int) $company->id;

        $type = HotelRoomType::create([
            'name' => 'Public Standard',
            'unit_category' => 'room',
            'base_price' => 100,
            'nightly_rate' => 100,
            'max_occupancy' => 2,
            'is_active' => true,
            'created_by' => $creatorId,
        ]);

        HotelRoom::create([
            'room_no' => 'P101',
            'hotel_room_type_id' => $type->id,
            'status' => HotelRoom::STATUS_AVAILABLE,
            'created_by' => $creatorId,
        ]);

        $site = HotelBookingSite::create([
            'created_by' => $creatorId,
            'slug' => 'test-hotel-book',
            'is_published' => true,
            'display_name' => 'Test Hotel',
            'payment_mode' => HotelBookingSite::PAY_AT_HOTEL,
        ]);

        $checkIn = Carbon::today()->addDays(3)->toDateString();
        $checkOut = Carbon::today()->addDays(5)->toDateString();

        $response = $this->post('/' . $site->slug . '/book', [
            'hotel_room_type_id' => $type->id,
            'check_in_at' => $checkIn . ' 14:00:00',
            'check_out_at' => $checkOut . ' 11:00:00',
            'first_name' => 'Jane',
            'last_name' => 'Guest',
            'email' => 'jane@example.com',
            'phone' => '+258840000001',
            'adults' => 2,
            'children' => 0,
            'terms' => '1',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('/confirmation/', $response->headers->get('Location') ?? '');

        $reservation = HotelReservation::where('created_by', $creatorId)
            ->where('source', 'online')
            ->where('hotel_booking_site_id', $site->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($reservation);
        $this->assertSame(HotelReservation::STATUS_CONFIRMED, $reservation->status);
        $this->assertSame('Jane', $reservation->guest?->first_name);
    }

    public function test_unpublished_site_returns_not_found(): void
    {
        if (! Schema::hasTable('hotel_booking_sites')) {
            $this->markTestSkipped('hotel_booking_sites table not migrated.');
        }

        $company = User::factory()->create(['type' => 'company']);

        $site = HotelBookingSite::create([
            'created_by' => (int) $company->id,
            'slug' => 'hidden-hotel',
            'is_published' => false,
            'display_name' => 'Hidden',
            'payment_mode' => HotelBookingSite::PAY_AT_HOTEL,
        ]);

        $this->get('/' . $site->slug)->assertNotFound();
    }
}
