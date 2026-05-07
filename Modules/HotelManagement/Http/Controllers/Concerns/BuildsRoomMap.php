<?php

namespace Modules\HotelManagement\Http\Controllers\Concerns;

use Carbon\Carbon;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;

trait BuildsRoomMap
{
    /**
     * Returns an ordered collection of rooms (with roomType eager-loaded) where each room
     * has a `currentReservation` relation set to the active reservation for "today" (if any).
     * The reservation includes its guest and stay->folio to enable click-through deep links.
     */
    protected function roomsWithCurrentReservation(int $creatorId, ?Carbon $today = null)
    {
        $today = $today ?: Carbon::today();

        $rooms = HotelRoom::where('created_by', $creatorId)
            ->with('roomType')
            ->orderBy('room_no')
            ->get();

        if ($rooms->isEmpty()) {
            return $rooms;
        }

        $roomIds = $rooms->pluck('id')->all();

        $reservations = HotelReservation::with(['guest', 'stay.folio'])
            ->whereIn('hotel_room_id', $roomIds)
            ->whereDate('check_in_date', '<=', $today->toDateString())
            ->whereDate('check_out_date', '>', $today->toDateString())
            ->whereIn('status', [
                HotelReservation::STATUS_CHECKED_IN,
                HotelReservation::STATUS_CONFIRMED,
                HotelReservation::STATUS_PENDING,
            ])
            ->get()
            ->sortBy(function ($r) {
                $rank = ['checked_in' => 0, 'confirmed' => 1, 'pending' => 2];
                return $rank[$r->status] ?? 9;
            })
            ->keyBy('hotel_room_id');

        $rooms->each(function ($room) use ($reservations) {
            $room->setRelation('currentReservation', $reservations->get($room->id));
        });

        return $rooms;
    }
}
