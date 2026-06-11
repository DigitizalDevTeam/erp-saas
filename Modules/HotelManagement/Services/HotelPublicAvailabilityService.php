<?php

namespace Modules\HotelManagement\Services;

use Carbon\Carbon;
use Modules\HotelManagement\Entities\HotelAddon;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Entities\HotelRoomType;

class HotelPublicAvailabilityService
{
    public function __construct(
        private readonly HotelReservationPricingService $pricingService,
        private readonly HotelRoomStatusService $roomStatusService,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchRoomTypes(
        int $creatorId,
        Carbon $checkIn,
        Carbon $checkOut,
        int $adults = 1,
        int $children = 0,
    ): array {
        $types = HotelRoomType::where('created_by', $creatorId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $results = [];
        foreach ($types as $type) {
            $available = $this->availableRoomCount($creatorId, (int) $type->id, $checkIn, $checkOut);
            if ($available < 1) {
                continue;
            }

            $quote = $this->pricingService->quote(
                $creatorId,
                (int) $type->id,
                null,
                $checkIn,
                $checkOut,
                'nightly'
            );

            $maxGuests = (int) $type->max_occupancy;
            if ($adults + $children > $maxGuests) {
                continue;
            }

            $results[] = [
                'id' => $type->id,
                'name' => $type->name,
                'description' => $type->description,
                'image_url' => $type->displayImageUrl(),
                'gallery_urls' => $type->galleryImageUrls(),
                'max_occupancy' => $maxGuests,
                'available_rooms' => $available,
                'rate_per_night' => (float) $quote['rate_amount'],
                'units' => (int) $quote['units'],
                'unit_label' => $quote['unit_label'],
                'subtotal' => (float) $quote['subtotal'],
                'rate_plan_name' => $quote['rate_plan_name'] ?? null,
            ];
        }

        return $results;
    }

    public function availableRoomCount(
        int $creatorId,
        int $roomTypeId,
        Carbon $checkIn,
        Carbon $checkOut,
        ?int $ignoreReservationId = null
    ): int {
        $rooms = HotelRoom::where('created_by', $creatorId)
            ->where('hotel_room_type_id', $roomTypeId)
            ->get();

        $count = 0;
        foreach ($rooms as $room) {
            if ($this->roomStatusService->assignmentBlockReason($room)) {
                continue;
            }
            if ($this->roomHasConflict($creatorId, (int) $room->id, $checkIn, $checkOut, $ignoreReservationId)) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    public function pickRoomForType(
        int $creatorId,
        int $roomTypeId,
        Carbon $checkIn,
        Carbon $checkOut
    ): ?HotelRoom {
        return HotelRoom::where('created_by', $creatorId)
            ->where('hotel_room_type_id', $roomTypeId)
            ->orderBy('room_no')
            ->get()
            ->first(function (HotelRoom $room) use ($creatorId, $checkIn, $checkOut) {
                if ($this->roomStatusService->assignmentBlockReason($room)) {
                    return false;
                }

                return ! $this->roomHasConflict($creatorId, (int) $room->id, $checkIn, $checkOut);
            });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeAddons(int $creatorId): array
    {
        return HotelAddon::where('created_by', $creatorId)
            ->where('is_active', true)
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->map(fn (HotelAddon $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type,
                'description' => $a->description,
                'price' => (float) $a->price,
                'billing_mode' => $a->billing_mode,
            ])
            ->all();
    }

    private function roomHasConflict(
        int $creatorId,
        int $roomId,
        Carbon $checkInAt,
        Carbon $checkOutAt,
        ?int $ignoreReservationId = null
    ): bool {
        return HotelReservation::where('created_by', $creatorId)
            ->where('hotel_room_id', $roomId)
            ->whereIn('status', [
                HotelReservation::STATUS_PENDING,
                HotelReservation::STATUS_CONFIRMED,
                HotelReservation::STATUS_CHECKED_IN,
            ])
            ->when($ignoreReservationId, fn ($q) => $q->where('id', '!=', $ignoreReservationId))
            ->whereRaw(
                "COALESCE(check_in_at, CONCAT(check_in_date, ' 00:00:00')) < ? AND COALESCE(check_out_at, CONCAT(check_out_date, ' 23:59:59')) > ?",
                [$checkOutAt->format('Y-m-d H:i:s'), $checkInAt->format('Y-m-d H:i:s')]
            )
            ->exists();
    }
}
