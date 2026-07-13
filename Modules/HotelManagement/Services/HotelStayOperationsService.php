<?php

namespace Modules\HotelManagement\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;

class HotelStayOperationsService
{
    public function __construct(
        private readonly HotelReservationPricingService $pricingService,
        private readonly HotelPosChargeService $chargeService,
        private readonly HotelRoomStatusService $roomStatusService,
        private readonly HotelHousekeepingService $housekeepingService,
    ) {
    }

    public function extendStay(HotelReservation $reservation, Carbon $newCheckOutAt, int $creatorId): void
    {
        if ($reservation->status !== HotelReservation::STATUS_CHECKED_IN) {
            throw new \InvalidArgumentException(__('Guest must be checked in to extend stay.'));
        }

        $stay = $reservation->stay;
        $folio = $stay?->folio;
        if (! $stay || ! $folio) {
            throw new \InvalidArgumentException(__('No active stay or folio found.'));
        }

        $oldCheckOutAt = Carbon::parse($reservation->check_out_at ?: ($reservation->check_out_date . ' 12:00:00'));
        if (! $newCheckOutAt->greaterThan($oldCheckOutAt)) {
            throw new \InvalidArgumentException(__('New check-out must be after the current check-out date.'));
        }

        if ($reservation->hotel_room_id && $this->roomHasConflict(
            $creatorId,
            (int) $reservation->hotel_room_id,
            $oldCheckOutAt,
            $newCheckOutAt,
            (int) $reservation->id
        )) {
            throw new \InvalidArgumentException(__('Room is not available for the extended dates.'));
        }

        [$additionalUnits, $unitLabel] = $this->pricingService->calculateUnits(
            $oldCheckOutAt,
            $newCheckOutAt,
            (string) ($reservation->billing_mode ?? 'nightly')
        );

        $rate = (float) $reservation->rate_per_night;
        $lineTotal = round($rate * $additionalUnits, 2);
        $unitLabelLower = strtolower($unitLabel);

        DB::transaction(function () use ($reservation, $stay, $folio, $newCheckOutAt, $creatorId, $additionalUnits, $unitLabelLower, $rate, $lineTotal) {
            $this->chargeService->addRoomChargeToFolio($folio, [
                'description' => __('Stay extension') . ' (' . $additionalUnits . ' ' . $unitLabelLower . ')',
                'quantity' => $additionalUnits,
                'unit_price' => $rate,
                'discount_amount' => 0,
            ], $creatorId);

            $reservation->update([
                'check_out_at' => $newCheckOutAt->format('Y-m-d H:i:s'),
                'check_out_date' => $newCheckOutAt->toDateString(),
            ]);

            $stay->update([
                'expected_check_out_at' => $newCheckOutAt->format('Y-m-d H:i:s'),
            ]);
        });
    }

    public function changeRoom(HotelReservation $reservation, int $newRoomId, int $creatorId): void
    {
        if ($reservation->status !== HotelReservation::STATUS_CHECKED_IN) {
            throw new \InvalidArgumentException(__('Guest must be checked in to change room.'));
        }

        $stay = $reservation->stay;
        $folio = $stay?->folio;
        if (! $stay || ! $folio) {
            throw new \InvalidArgumentException(__('No active stay or folio found.'));
        }

        if ((int) $reservation->hotel_room_id === $newRoomId) {
            throw new \InvalidArgumentException(__('Guest is already assigned to this room.'));
        }

        $newRoom = HotelRoom::where('created_by', $creatorId)->with('roomType')->find($newRoomId);
        if (! $newRoom) {
            throw new \InvalidArgumentException(__('Room not found.'));
        }

        $checkOutAt = Carbon::parse($reservation->check_out_at ?: ($reservation->check_out_date . ' 12:00:00'));
        $now = now();

        if ($this->roomHasConflict($creatorId, $newRoomId, $now, $checkOutAt, (int) $reservation->id)) {
            throw new \InvalidArgumentException(__('Selected room is not available for the remaining stay.'));
        }

        $block = $this->roomStatusService->assignmentBlockReason($newRoom);
        if ($block) {
            throw new \InvalidArgumentException($block);
        }

        $quote = $this->pricingService->quote(
            $creatorId,
            (int) $newRoom->hotel_room_type_id,
            $newRoomId,
            $now,
            $checkOutAt,
            (string) ($reservation->billing_mode ?? 'nightly')
        );

        [$remainingUnits, $unitLabel] = $this->pricingService->calculateUnits(
            $now,
            $checkOutAt,
            (string) ($reservation->billing_mode ?? 'nightly')
        );

        $oldRate = (float) $reservation->rate_per_night;
        $newRate = (float) $quote['rate_amount'];
        $rateDiff = round(($newRate - $oldRate) * $remainingUnits, 2);
        $oldRoomId = (int) $reservation->hotel_room_id;
        $unitLabelLower = strtolower($unitLabel);

        DB::transaction(function () use (
            $reservation,
            $stay,
            $folio,
            $newRoom,
            $quote,
            $creatorId,
            $rateDiff,
            $remainingUnits,
            $unitLabelLower,
            $newRate,
            $oldRoomId,
            $newRoomId
        ) {
            if (abs($rateDiff) >= 0.01) {
                $this->chargeService->addRoomChargeToFolio($folio, [
                    'description' => __('Room change adjustment') . ' (' . $newRoom->room_no . ', ' . $remainingUnits . ' ' . $unitLabelLower . ')',
                    'quantity' => 1,
                    'unit_price' => $rateDiff,
                    'discount_amount' => 0,
                ], $creatorId);
            }

            $reservation->update([
                'hotel_room_id' => $newRoomId,
                'hotel_room_type_id' => $newRoom->hotel_room_type_id,
                'hotel_rate_plan_id' => $quote['rate_plan_id'] ?? $reservation->hotel_rate_plan_id,
                'rate_per_night' => $newRate,
            ]);

            $stay->update(['hotel_room_id' => $newRoomId]);

            if ($oldRoomId) {
                $oldRoom = HotelRoom::find($oldRoomId);
                if ($oldRoom) {
                    $this->housekeepingService->queueRoom(
                        $oldRoom,
                        $creatorId,
                        HotelHousekeepingService::SOURCE_MANUAL,
                        __('Queued after guest room change.'),
                        true
                    );
                }
            }
            $newRoom->refresh();
            $this->roomStatusService->markOccupied($newRoom, $creatorId);
        });
    }

    public function roomHasConflict(
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

    /**
     * @return \Illuminate\Support\Collection<int, HotelRoom>
     */
    public function availableRoomsForChange(HotelReservation $reservation, int $creatorId)
    {
        $checkOutAt = Carbon::parse($reservation->check_out_at ?: ($reservation->check_out_date . ' 12:00:00'));

        return HotelRoom::where('created_by', $creatorId)
            ->with(['roomType', 'floor'])
            ->orderBy('room_no')
            ->get()
            ->filter(function (HotelRoom $room) use ($reservation, $creatorId, $checkOutAt) {
                if ((int) $room->id === (int) $reservation->hotel_room_id) {
                    return false;
                }
                if ($this->roomStatusService->assignmentBlockReason($room)) {
                    return false;
                }

                return ! $this->roomHasConflict($creatorId, (int) $room->id, now(), $checkOutAt, (int) $reservation->id);
            })
            ->values();
    }
}
