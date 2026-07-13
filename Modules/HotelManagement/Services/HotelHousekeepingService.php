<?php

namespace Modules\HotelManagement\Services;

use Illuminate\Support\Facades\Auth;
use Modules\HotelManagement\Entities\HotelHousekeepingTask;
use Modules\HotelManagement\Entities\HotelRoom;

class HotelHousekeepingService
{
    public const SOURCE_NIGHT_AUDIT = 'night_audit';
    public const SOURCE_CHECKOUT = 'checkout';
    public const SOURCE_MANUAL = 'manual';

    public function __construct(
        private readonly HotelRoomStatusService $roomStatusService,
    ) {
    }

    public function hasOpenTask(int $roomId, int $creatorId): bool
    {
        return HotelHousekeepingTask::query()
            ->where('created_by', $creatorId)
            ->where('hotel_room_id', $roomId)
            ->whereIn('status', ['pending', 'in_progress'])
            ->exists();
    }

    public function queueRoom(
        HotelRoom $room,
        int $creatorId,
        string $source = self::SOURCE_MANUAL,
        ?string $notes = null,
        bool $allowFromOccupied = false
    ): ?HotelHousekeepingTask {
        $releaseOccupied = $allowFromOccupied
            || in_array($source, [self::SOURCE_CHECKOUT, self::SOURCE_MANUAL], true);

        if ($this->hasOpenTask((int) $room->id, $creatorId)) {
            $this->roomStatusService->markDirty($room, $releaseOccupied && $room->status === HotelRoom::STATUS_OCCUPIED);

            return null;
        }

        $this->roomStatusService->markDirty($room, $releaseOccupied && $room->status === HotelRoom::STATUS_OCCUPIED);

        return HotelHousekeepingTask::create([
            'hotel_room_id' => $room->id,
            'assigned_to' => null,
            'status' => 'pending',
            'source' => $source,
            'notes' => $notes,
            'created_by' => $creatorId,
        ]);
    }

    /**
     * After night audit: every non-occupied, non-maintenance room is marked dirty
     * and gets a housekeeping task if one is not already open.
     *
     * @return array{rooms_marked_dirty: int, tasks_created: int, tasks_skipped: int}
     */
    public function queueDirtyRoomsAfterNightAudit(int $creatorId, ?int $branchId, string $auditDate): array
    {
        $query = HotelRoom::query()->where('created_by', $creatorId);
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $rooms = $query
            ->whereNotIn('status', [HotelRoom::STATUS_OCCUPIED, HotelRoom::STATUS_MAINTENANCE])
            ->orderBy('room_no')
            ->get();

        $tasksCreated = 0;
        $tasksSkipped = 0;

        foreach ($rooms as $room) {
            $note = __('Queued by night audit (:date)', ['date' => $auditDate]);
            $task = $this->queueRoom($room, $creatorId, self::SOURCE_NIGHT_AUDIT, $note);
            if ($task) {
                $tasksCreated++;
            } else {
                $tasksSkipped++;
            }
        }

        return [
            'rooms_marked_dirty' => $rooms->count(),
            'tasks_created' => $tasksCreated,
            'tasks_skipped' => $tasksSkipped,
        ];
    }

    public function queueRoomAfterCheckout(HotelRoom $room, int $creatorId): ?HotelHousekeepingTask
    {
        return $this->queueRoom(
            $room,
            $creatorId,
            self::SOURCE_CHECKOUT,
            __('Queued after guest checkout.')
        );
    }

    public function startCleaning(HotelHousekeepingTask $task, int $creatorId, ?int $userId = null): void
    {
        if (! in_array($task->status, ['pending'], true)) {
            throw new \InvalidArgumentException(__('Only pending tasks can be started.'));
        }

        $room = $this->roomForTask($task, $creatorId);
        $task->update([
            'status' => 'in_progress',
            'assigned_to' => $userId ?: $task->assigned_to ?: (Auth::id() ?: null),
        ]);
        $this->roomStatusService->markCleaning($room);
    }

    public function completeCleaning(HotelHousekeepingTask $task, int $creatorId, ?int $userId = null): void
    {
        if ($task->status !== 'in_progress') {
            throw new \InvalidArgumentException(__('Start cleaning before marking the room ready.'));
        }

        $room = $this->roomForTask($task, $creatorId);
        $this->roomStatusService->markRoomReady($room, $creatorId);

        $task->update([
            'status' => 'done',
            'completed_at' => now(),
            'completed_by' => $userId ?: (Auth::id() ?: null),
        ]);
    }

    public function sendToMaintenance(HotelHousekeepingTask $task, int $creatorId, ?string $notes = null, ?int $userId = null): void
    {
        if ($task->status === 'done') {
            throw new \InvalidArgumentException(__('This task is already completed.'));
        }

        $room = $this->roomForTask($task, $creatorId);
        $this->roomStatusService->markMaintenance($room, $creatorId, $notes);

        $task->update([
            'status' => 'done',
            'notes' => trim(($task->notes ? $task->notes . ' | ' : '') . ($notes ?: __('Sent to maintenance.'))),
            'completed_at' => now(),
            'completed_by' => $userId ?: (Auth::id() ?: null),
        ]);
    }

    private function roomForTask(HotelHousekeepingTask $task, int $creatorId): HotelRoom
    {
        $room = HotelRoom::query()
            ->where('id', $task->hotel_room_id)
            ->where('created_by', $creatorId)
            ->first();

        if (! $room) {
            throw new \InvalidArgumentException(__('Room not found for this task.'));
        }

        return $room;
    }
}
