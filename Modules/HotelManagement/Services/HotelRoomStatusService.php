<?php

namespace Modules\HotelManagement\Services;

use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Entities\HotelRoomMinibarRestock;
use Modules\HotelManagement\Entities\HotelStay;

class HotelRoomStatusService
{
    /**
     * @return array<string, string>
     */
    public function statusLabels(): array
    {
        return HotelRoom::statusOptions();
    }

    public function hasInHouseStay(HotelRoom $room, int $creatorId): bool
    {
        return HotelStay::where('created_by', $creatorId)
            ->where('hotel_room_id', $room->id)
            ->where('status', 'in_house')
            ->exists();
    }

    /**
     * Reason the room cannot be assigned to a new reservation / check-in.
     */
    public function assignmentBlockReason(HotelRoom $room, ?int $ignoreReservationRoomId = null): ?string
    {
        if ((int) $room->id === (int) $ignoreReservationRoomId) {
            return null;
        }

        if ($room->status === HotelRoom::STATUS_BLOCKED) {
            return __('Room :room is blocked and cannot be assigned.', ['room' => $room->room_no]);
        }

        if ($room->status === HotelRoom::STATUS_MAINTENANCE) {
            return __('Room :room is out of order (maintenance).', ['room' => $room->room_no]);
        }

        if ($room->status === HotelRoom::STATUS_DIRTY) {
            return __('Room :room is dirty and waiting for housekeeping.', ['room' => $room->room_no]);
        }

        if ($room->status === HotelRoom::STATUS_CLEANING) {
            return __('Room :room is being cleaned and cannot be assigned yet.', ['room' => $room->room_no]);
        }

        if ($room->status === HotelRoom::STATUS_OCCUPIED) {
            return __('Room :room is already occupied.', ['room' => $room->room_no]);
        }

        return null;
    }

    /**
     * Validate a manual or bulk status change initiated by staff.
     */
    public function validateTransition(HotelRoom $room, string $newStatus, int $creatorId): ?string
    {
        $allowed = array_keys(HotelRoom::statusOptions());

        if (! in_array($newStatus, $allowed, true)) {
            return __('Invalid room status.');
        }

        if ($room->status === $newStatus) {
            return null;
        }

        $inHouse = $this->hasInHouseStay($room, $creatorId);

        if ($inHouse && in_array($newStatus, [
            HotelRoom::STATUS_AVAILABLE,
            HotelRoom::STATUS_MAINTENANCE,
            HotelRoom::STATUS_BLOCKED,
            HotelRoom::STATUS_CLEANING,
            HotelRoom::STATUS_DIRTY,
        ], true)) {
            return __('Room :room has an in-house guest. Check out before changing status.', ['room' => $room->room_no]);
        }

        if ($newStatus === HotelRoom::STATUS_OCCUPIED && ! $inHouse) {
            return __('Room :room has no in-house guest. Use check-in to mark occupied.', ['room' => $room->room_no]);
        }

        return null;
    }

    public function transition(HotelRoom $room, string $newStatus, int $creatorId, ?string $notes = null): void
    {
        $error = $this->validateTransition($room, $newStatus, $creatorId);
        if ($error) {
            throw new \InvalidArgumentException($error);
        }

        $payload = ['status' => $newStatus];
        if ($notes !== null && $notes !== '') {
            $payload['notes'] = $notes;
        }

        $room->update($payload);
    }

    public function markOccupied(HotelRoom $room, int $creatorId): void
    {
        $block = $this->assignmentBlockReason($room);
        if ($block && $room->status !== HotelRoom::STATUS_OCCUPIED) {
            throw new \InvalidArgumentException($block);
        }

        $room->update(['status' => HotelRoom::STATUS_OCCUPIED]);
    }

    public function markDirty(HotelRoom $room, bool $allowFromOccupied = false): void
    {
        if ($room->status === HotelRoom::STATUS_OCCUPIED && ! $allowFromOccupied) {
            throw new \InvalidArgumentException(__('Occupied rooms cannot be marked dirty.'));
        }

        if (in_array($room->status, [HotelRoom::STATUS_BLOCKED, HotelRoom::STATUS_MAINTENANCE], true)) {
            throw new \InvalidArgumentException(__('Blocked or maintenance rooms cannot be marked dirty.'));
        }

        $room->update(['status' => HotelRoom::STATUS_DIRTY]);
    }

    /**
     * Release an occupied room to dirty (checkout / room change).
     */
    public function releaseOccupiedToDirty(HotelRoom $room): void
    {
        $this->markDirty($room, true);
    }

    public function markCleaning(HotelRoom $room): void
    {
        $room->update(['status' => HotelRoom::STATUS_CLEANING]);
    }

    public function markAvailable(HotelRoom $room, int $creatorId): void
    {
        $this->transition($room, HotelRoom::STATUS_AVAILABLE, $creatorId);
    }

    public function markMaintenance(HotelRoom $room, int $creatorId, ?string $notes = null): void
    {
        $this->transition($room, HotelRoom::STATUS_MAINTENANCE, $creatorId, $notes);
    }

    public function blockRoom(HotelRoom $room, int $creatorId, ?string $reason = null): void
    {
        $this->transition($room, HotelRoom::STATUS_BLOCKED, $creatorId, $reason);
    }

    public function unblockRoom(HotelRoom $room, int $creatorId): void
    {
        if ($room->status !== HotelRoom::STATUS_BLOCKED) {
            throw new \InvalidArgumentException(__('Room :room is not blocked.', ['room' => $room->room_no]));
        }

        $this->transition($room, HotelRoom::STATUS_AVAILABLE, $creatorId);
    }

    /**
     * Housekeeping: cleaning room is ready for the next guest.
     */
    public function markRoomReady(HotelRoom $room, int $creatorId): void
    {
        if (! in_array($room->status, [HotelRoom::STATUS_CLEANING, HotelRoom::STATUS_DIRTY], true)) {
            throw new \InvalidArgumentException(__('Only dirty or in-progress cleaning rooms can be marked ready.'));
        }

        if ($this->hasPendingRestock((int) $room->id, $creatorId)) {
            throw new \InvalidArgumentException(__('Complete minibar restock before marking room ready.'));
        }

        $this->markAvailable($room, $creatorId);
    }

    public function hasPendingRestock(int $roomId, int $creatorId): bool
    {
        if (! Schema::hasTable('hotel_room_minibar_restocks')) {
            return false;
        }

        return HotelRoomMinibarRestock::where('created_by', $creatorId)
            ->where('hotel_room_id', $roomId)
            ->where('status', HotelRoomMinibarRestock::STATUS_PENDING)
            ->exists();
    }

    /**
     * @param  array<int, int|string>  $roomIds
     * @return array{updated: int, errors: array<int, string>}
     */
    public function bulkUpdate(array $roomIds, string $newStatus, int $creatorId): array
    {
        $updated = 0;
        $errors = [];

        $rooms = HotelRoom::where('created_by', $creatorId)
            ->whereIn('id', $roomIds)
            ->get();

        foreach ($rooms as $room) {
            try {
                if ($newStatus === HotelRoom::STATUS_AVAILABLE && $room->status === HotelRoom::STATUS_CLEANING) {
                    $this->markRoomReady($room, $creatorId);
                } else {
                    $this->transition($room, $newStatus, $creatorId);
                }
                $updated++;
            } catch (\InvalidArgumentException $e) {
                $errors[(int) $room->id] = $room->room_no . ': ' . $e->getMessage();
            }
        }

        return ['updated' => $updated, 'errors' => $errors];
    }

    /**
     * @return array<int, string>
     */
    public function assignableRoomIds(int $creatorId, ?int $roomTypeId = null): array
    {
        $query = HotelRoom::where('created_by', $creatorId)
            ->where('status', HotelRoom::STATUS_AVAILABLE);

        if ($roomTypeId) {
            $query->where('hotel_room_type_id', $roomTypeId);
        }

        return $query->orderBy('room_no')->pluck('room_no', 'id')->all();
    }
}
