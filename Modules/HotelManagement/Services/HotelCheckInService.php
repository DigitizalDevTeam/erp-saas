<?php

namespace Modules\HotelManagement\Services;

use Illuminate\Support\Facades\DB;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelFolioLine;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Entities\HotelStay;

/**
 * Creates (or repairs) stay + folio for a checked-in reservation.
 */
class HotelCheckInService
{
    public function __construct(
        private readonly HotelReservationPricingService $pricingService,
        private readonly HotelAddonService $addonService,
        private readonly HotelRoomStatusService $roomStatusService,
    ) {
    }

    /**
     * Ensure a checked-in reservation has an in-house stay and folio.
     * When $healMode is true, dirty/cleaning room status does not block repair.
     */
    public function ensureStayAndFolio(HotelReservation $reservation, int $creatorId, bool $setCheckedInStatus = true, bool $healMode = false): HotelStay
    {
        $reservation->loadMissing(['reservationAddons', 'stay.folio', 'room']);

        if ($reservation->stay && $reservation->stay->status === 'in_house') {
            if ($reservation->hotel_room_id) {
                $room = HotelRoom::where('created_by', $creatorId)->find($reservation->hotel_room_id);
                if ($room && $room->status !== HotelRoom::STATUS_OCCUPIED) {
                    $this->syncRoomOccupied($room, $creatorId, $healMode);
                }
            }

            return $reservation->stay;
        }

        if (! $reservation->hotel_room_id) {
            throw new \InvalidArgumentException(__('Assign room before check-in.'));
        }

        $room = HotelRoom::where('created_by', $creatorId)->find($reservation->hotel_room_id);
        if (! $room) {
            throw new \InvalidArgumentException(__('Room not found.'));
        }

        if (! $reservation->stay && ! $healMode) {
            $block = $this->roomStatusService->assignmentBlockReason($room);
            if ($block && $room->status !== HotelRoom::STATUS_OCCUPIED) {
                throw new \InvalidArgumentException($block);
            }
        }

        return DB::transaction(function () use ($reservation, $room, $creatorId, $setCheckedInStatus, $healMode) {
            $stay = $reservation->stay;
            if (! $stay) {
                $stay = HotelStay::create([
                    'hotel_reservation_id' => $reservation->id,
                    'hotel_guest_id' => $reservation->hotel_guest_id,
                    'hotel_room_id' => $reservation->hotel_room_id,
                    'check_in_at' => now(),
                    'expected_check_out_at' => $reservation->check_out_at ?: ($reservation->check_out_date . ' 12:00:00'),
                    'status' => 'in_house',
                    'created_by' => $creatorId,
                ]);
            } elseif ($stay->status !== 'in_house') {
                $stay->update([
                    'status' => 'in_house',
                    'hotel_room_id' => $reservation->hotel_room_id,
                    'checked_out_at' => null,
                ]);
            }

            $folio = $stay->folio;
            if (! $folio) {
                $totals = $this->pricingService->reservationTotals($reservation);
                $unitLabel = strtolower($totals['unit_label']);
                $folio = HotelFolio::create([
                    'hotel_stay_id' => $stay->id,
                    'folio_no' => 'FOL-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
                    'sub_total' => $totals['sub_total'],
                    'grand_total' => $totals['grand_total'],
                    'created_by' => $creatorId,
                ]);

                HotelFolioLine::create([
                    'hotel_folio_id' => $folio->id,
                    'line_type' => 'room_charge',
                    'description' => ucfirst($unitLabel) . ' charge (' . $totals['units'] . ' ' . $unitLabel . ')',
                    'quantity' => $totals['units'],
                    'unit_price' => $reservation->rate_per_night,
                    'discount_amount' => $reservation->discount_amount,
                    'line_total' => $totals['grand_total'],
                    'created_by' => $creatorId,
                ]);
            }

            if ($setCheckedInStatus && $reservation->status !== HotelReservation::STATUS_CHECKED_IN) {
                $reservation->update(['status' => HotelReservation::STATUS_CHECKED_IN]);
            }

            $room->refresh();
            $this->syncRoomOccupied($room, $creatorId, $healMode);

            $reservation->refresh();
            $reservation->load('reservationAddons');
            $this->addonService->postReservationAddonsToFolio($reservation, $folio, $creatorId);

            return $stay->fresh(['folio']);
        });
    }

    /**
     * Heal checked-in reservations missing or non-in-house stays, and occupied rooms without guests.
     *
     * @return array{stays_created: int, rooms_released: int}
     */
    public function healOccupancyMismatches(int $creatorId): array
    {
        $staysCreated = 0;
        $roomsReleased = 0;

        $checkedInNeedingStay = HotelReservation::where('created_by', $creatorId)
            ->where('status', HotelReservation::STATUS_CHECKED_IN)
            ->whereNotNull('hotel_room_id')
            ->where(function ($q) {
                $q->whereDoesntHave('stay')
                    ->orWhereHas('stay', function ($stayQuery) {
                        $stayQuery->where('status', '!=', 'in_house');
                    });
            })
            ->get();

        foreach ($checkedInNeedingStay as $reservation) {
            try {
                $this->ensureStayAndFolio($reservation, $creatorId, false, true);
                $staysCreated++;
            } catch (\Throwable) {
                // Skip rooms that cannot be healed automatically.
            }
        }

        $inHouseRoomIds = HotelStay::where('created_by', $creatorId)
            ->where('status', 'in_house')
            ->pluck('hotel_room_id')
            ->filter()
            ->unique()
            ->all();

        $orphanOccupied = HotelRoom::where('created_by', $creatorId)
            ->where('status', HotelRoom::STATUS_OCCUPIED)
            ->whereNotIn('id', $inHouseRoomIds ?: [0])
            ->get();

        foreach ($orphanOccupied as $room) {
            $activeReservation = HotelReservation::where('created_by', $creatorId)
                ->where('hotel_room_id', $room->id)
                ->where('status', HotelReservation::STATUS_CHECKED_IN)
                ->first();

            if ($activeReservation) {
                try {
                    $this->ensureStayAndFolio($activeReservation, $creatorId, false, true);
                    $staysCreated++;
                    continue;
                } catch (\Throwable) {
                    // Fall through to release.
                }
            }

            $room->update(['status' => HotelRoom::STATUS_AVAILABLE]);
            $roomsReleased++;
        }

        return [
            'stays_created' => $staysCreated,
            'rooms_released' => $roomsReleased,
        ];
    }

    private function syncRoomOccupied(HotelRoom $room, int $creatorId, bool $healMode): void
    {
        if ($room->status === HotelRoom::STATUS_OCCUPIED) {
            return;
        }

        if ($healMode) {
            // Already checked-in guest takes priority over dirty/cleaning housekeeping status.
            $room->update(['status' => HotelRoom::STATUS_OCCUPIED]);

            return;
        }

        $this->roomStatusService->markOccupied($room, $creatorId);
    }
}
