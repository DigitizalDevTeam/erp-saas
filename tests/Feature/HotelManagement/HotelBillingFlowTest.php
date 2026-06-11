<?php

namespace Tests\Feature\HotelManagement;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelAddon;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelGuest;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Entities\HotelRoomType;
use Modules\HotelManagement\Entities\HotelStay;
use Modules\HotelManagement\Http\Controllers\HotelFrontdeskController;
use Modules\HotelManagement\Services\HotelAddonService;
use Modules\HotelManagement\Services\HotelFolioPaymentService;
use Modules\HotelManagement\Services\HotelPosChargeService;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class HotelBillingFlowTest extends TestCase
{
    use DatabaseTransactions;

    public function test_addon_occupancy_validation_counts_extra_beds(): void
    {
        if (! Schema::hasTable('hotel_addons')) {
            $this->markTestSkipped('hotel_addons table not migrated.');
        }

        $company = User::factory()->create(['type' => 'company']);
        $creatorId = (int) $company->id;

        $type = HotelRoomType::create([
            'name' => 'Test Standard',
            'unit_category' => 'room',
            'base_price' => 50,
            'nightly_rate' => 50,
            'max_occupancy' => 2,
            'is_active' => true,
            'created_by' => $creatorId,
        ]);

        $addon = HotelAddon::create([
            'name' => 'Extra bed',
            'type' => HotelAddon::TYPE_EXTRA_BED,
            'price' => 20,
            'billing_mode' => HotelAddon::BILLING_PER_NIGHT,
            'is_active' => true,
            'created_by' => $creatorId,
        ]);

        $service = app(HotelAddonService::class);

        $ok = $service->validateOccupancyForBooking(
            (int) $type->id,
            2,
            1,
            [['addon_id' => $addon->id, 'qty' => 1]],
            $creatorId
        );
        $this->assertNull($ok);

        $fail = $service->validateOccupancyForBooking(
            (int) $type->id,
            3,
            1,
            [['addon_id' => $addon->id, 'qty' => 0]],
            $creatorId
        );
        $this->assertNotNull($fail);
    }

    public function test_checkout_sets_folio_paid_and_room_cleaning(): void
    {
        if (! Schema::hasTable('hotel_folios')) {
            $this->markTestSkipped('Hotel tables not migrated.');
        }

        $company = User::factory()->create(['type' => 'company']);
        Permission::firstOrCreate(['name' => 'manage hotel', 'guard_name' => 'web']);
        $company->givePermissionTo('manage hotel');
        $this->actingAs($company);
        $creatorId = (int) $company->creatorId();

        $type = HotelRoomType::create([
            'name' => 'Billing Test',
            'unit_category' => 'room',
            'base_price' => 100,
            'nightly_rate' => 100,
            'max_occupancy' => 2,
            'is_active' => true,
            'created_by' => $creatorId,
        ]);

        $room = HotelRoom::create([
            'hotel_room_type_id' => $type->id,
            'room_no' => 'T-' . uniqid(),
            'status' => HotelRoom::STATUS_AVAILABLE,
            'created_by' => $creatorId,
        ]);

        $guest = HotelGuest::create([
            'first_name' => 'Test',
            'last_name' => 'Guest',
            'created_by' => $creatorId,
        ]);

        $checkIn = Carbon::today()->setTime(14, 0);
        $checkOut = Carbon::today()->addDay()->setTime(11, 0);

        $reservation = HotelReservation::create([
            'reservation_no' => 'RSV-TEST-' . strtoupper(substr(uniqid(), -6)),
            'hotel_guest_id' => $guest->id,
            'hotel_room_type_id' => $type->id,
            'hotel_room_id' => $room->id,
            'check_in_at' => $checkIn,
            'check_out_at' => $checkOut,
            'check_in_date' => $checkIn->toDateString(),
            'check_out_date' => $checkOut->toDateString(),
            'adults' => 1,
            'source' => 'manual',
            'status' => HotelReservation::STATUS_CONFIRMED,
            'rate_per_night' => 100,
            'discount_amount' => 0,
            'created_by' => $creatorId,
        ]);

        app(HotelFrontdeskController::class)->checkIn($reservation);

        $reservation->refresh();
        $stay = $reservation->stay;
        $folio = $stay->folio;
        $this->assertNotNull($folio);
        $this->assertSame(HotelRoom::STATUS_OCCUPIED, $room->fresh()->status);

        app(HotelPosChargeService::class)->addMiscChargeToFolio($folio, [
            'description' => 'Minibar test',
            'quantity' => 1,
            'unit_price' => 15,
        ], $creatorId);

        $folio->refresh();
        $grand = (float) $folio->grand_total;
        $this->assertGreaterThan(100, $grand);

        app(HotelFolioPaymentService::class)->recordPayment($folio, $grand, 'cash', $creatorId, 'TEST');

        if (Schema::hasColumn('hotel_stays', 'minibar_verified_at')) {
            $stay->update(['minibar_verified_at' => now()]);
        }

        $request = Request::create('/', 'POST', ['payment_amount' => 0, 'payment_method' => 'cash']);
        app(HotelFrontdeskController::class)->checkOut($request, $stay->fresh());

        $folio->refresh();
        $reservation->refresh();
        $room->refresh();

        $this->assertSame(HotelReservation::STATUS_CHECKED_OUT, $reservation->status);
        $this->assertSame('paid', $folio->paymentStatusLabel());
        $this->assertSame(HotelRoom::STATUS_CLEANING, $room->status);
    }
}
