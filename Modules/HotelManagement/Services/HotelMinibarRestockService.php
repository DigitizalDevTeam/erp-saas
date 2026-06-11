<?php

namespace Modules\HotelManagement\Services;

use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelMinibarItem;
use Modules\HotelManagement\Entities\HotelRoomMinibarRestock;
use Modules\HotelManagement\Entities\HotelRoomMinibarRestockLine;
use Modules\HotelManagement\Entities\HotelStay;

class HotelMinibarRestockService
{
    public function __construct(
        private readonly HotelRoomConsumptionService $consumptionService,
        private readonly HotelRoomStatusService $roomStatusService,
    ) {
    }

    public function queueRestockAfterCheckout(HotelStay $stay, int $creatorId): ?HotelRoomMinibarRestock
    {
        if (! Schema::hasTable('hotel_room_minibar_restocks') || ! $stay->hotel_room_id) {
            return null;
        }

        $catalog = HotelMinibarItem::where('created_by', $creatorId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        if ($catalog->isEmpty()) {
            return null;
        }

        $existing = HotelRoomMinibarRestock::where('created_by', $creatorId)
            ->where('hotel_room_id', $stay->hotel_room_id)
            ->where('status', HotelRoomMinibarRestock::STATUS_PENDING)
            ->first();

        if ($existing) {
            return $existing;
        }

        $restock = HotelRoomMinibarRestock::create([
            'hotel_room_id' => $stay->hotel_room_id,
            'hotel_stay_id' => $stay->id,
            'status' => HotelRoomMinibarRestock::STATUS_PENDING,
            'created_by' => $creatorId,
        ]);

        foreach ($catalog as $item) {
            HotelRoomMinibarRestockLine::create([
                'hotel_room_minibar_restock_id' => $restock->id,
                'hotel_minibar_item_id' => $item->id,
                'expected_qty' => max(1, (int) ($item->default_quantity ?? 1)),
                'created_by' => $creatorId,
            ]);
        }

        return $restock->load(['lines.minibarItem', 'room']);
    }

    /**
     * @return \Illuminate\Support\Collection<int, HotelRoomMinibarRestock>
     */
    public function pendingRestocks(int $creatorId)
    {
        if (! Schema::hasTable('hotel_room_minibar_restocks')) {
            return collect();
        }

        return HotelRoomMinibarRestock::where('created_by', $creatorId)
            ->where('status', HotelRoomMinibarRestock::STATUS_PENDING)
            ->with(['room', 'lines.minibarItem'])
            ->orderByDesc('id')
            ->get();
    }

    public function completeRestock(HotelRoomMinibarRestock $restock, ?string $notes = null): void
    {
        $restock->update([
            'status' => HotelRoomMinibarRestock::STATUS_COMPLETED,
            'completed_at' => now(),
            'notes' => $notes,
        ]);

        $restock->loadMissing('room');
        $room = $restock->room;
        if ($room && $room->status === \Modules\HotelManagement\Entities\HotelRoom::STATUS_CLEANING) {
            if (! $this->roomStatusService->hasPendingRestock((int) $room->id, (int) $restock->created_by)) {
                try {
                    $this->roomStatusService->markRoomReady($room, (int) $restock->created_by);
                } catch (\InvalidArgumentException) {
                    // Room stays in cleaning until housekeeping confirms.
                }
            }
        }
    }

    public function requiresMinibarScanAtCheckout(int $creatorId): bool
    {
        if (! Schema::hasColumn('hotel_accounting_settings', 'require_minibar_scan_at_checkout')) {
            return false;
        }

        $settings = \Modules\HotelManagement\Entities\HotelAccountingSetting::where('created_by', $creatorId)->first();

        return (bool) ($settings?->require_minibar_scan_at_checkout);
    }
}
