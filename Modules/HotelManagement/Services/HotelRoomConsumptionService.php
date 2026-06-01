<?php

namespace Modules\HotelManagement\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelFolioLine;
use Modules\HotelManagement\Entities\HotelMinibarItem;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Entities\HotelRoomConsumptionLog;
use Modules\HotelManagement\Entities\HotelStay;

class HotelRoomConsumptionService
{
    public const CHARGE_MINIBAR = 'minibar';

    public const CHARGE_LAUNDRY = 'laundry';

    public const CHARGE_MISC = 'misc';

    public function __construct(private readonly HotelPosChargeService $chargeService)
    {
    }

    /**
     * Active in-house stay with open folio for the room, if any.
     */
    public function activeStayForRoom(HotelRoom $room): ?HotelStay
    {
        return HotelStay::query()
            ->where('hotel_room_id', $room->id)
            ->where('created_by', $room->created_by)
            ->where('status', 'in_house')
            ->with(['folio', 'reservation.guest'])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Post consumption to the guest folio and log for housekeeping audit.
     *
     * @param  array{description?:string,quantity?:int,unit_price?:float,tax_amount?:float,discount_amount?:float,notes?:string,service_date?:string}  $payload
     */
    public function postConsumption(
        HotelRoom $room,
        string $chargeType,
        array $payload,
        int $creatorId
    ): HotelFolioLine {
        $stay = $this->activeStayForRoom($room);
        if (! $stay || ! $stay->folio) {
            throw new \RuntimeException(__('No in-house guest folio for this room.'));
        }

        $folio = $stay->folio;
        $description = (string) ($payload['description'] ?? '');
        if ($description === '') {
            $description = match ($chargeType) {
                self::CHARGE_MINIBAR => __('Minibar'),
                self::CHARGE_LAUNDRY => __('Laundry'),
                default => __('Extra charge'),
            };
        }

        $linePayload = [
            'description' => $description,
            'quantity' => $payload['quantity'] ?? 1,
            'unit_price' => $payload['unit_price'] ?? 0,
            'tax_amount' => $payload['tax_amount'] ?? 0,
            'discount_amount' => $payload['discount_amount'] ?? 0,
            'revenue_channel' => $this->revenueChannelFor($chargeType),
        ];

        $line = match ($chargeType) {
            self::CHARGE_MINIBAR => $this->chargeService->addMinibarChargeToFolio($folio, $linePayload, $creatorId),
            self::CHARGE_LAUNDRY => $this->chargeService->addLaundryChargeToFolio($folio, $linePayload, $creatorId),
            default => $this->chargeService->addMiscChargeToFolio($folio, $linePayload, $creatorId),
        };

        if (Schema::hasTable('hotel_room_consumption_logs')) {
            HotelRoomConsumptionLog::create([
                'hotel_room_id' => $room->id,
                'hotel_stay_id' => $stay->id,
                'hotel_folio_id' => $folio->id,
                'hotel_folio_line_id' => $line->id,
                'charge_type' => $chargeType,
                'service_date' => $payload['service_date'] ?? Carbon::today()->toDateString(),
                'description' => $description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'line_total' => $line->line_total,
                'notes' => $payload['notes'] ?? null,
                'created_by' => $creatorId,
            ]);
        }

        return $line;
    }

    public function revenueChannelFor(string $chargeType): string
    {
        return match ($chargeType) {
            self::CHARGE_LAUNDRY => 'laundry',
            self::CHARGE_MINIBAR => 'misc',
            default => 'misc',
        };
    }

    /**
     * @return \Illuminate\Support\Collection<int, HotelMinibarItem>
     */
    public function minibarCatalog(int $creatorId)
    {
        if (! Schema::hasTable('hotel_minibar_items')) {
            return collect();
        }

        $items = HotelMinibarItem::query()
            ->where('created_by', $creatorId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        if ($items->isEmpty()) {
            $defaults = [
                ['name' => __('Water (500ml)'), 'unit_price' => 2.50],
                ['name' => __('Soft drink'), 'unit_price' => 3.50],
                ['name' => __('Beer'), 'unit_price' => 5.00],
                ['name' => __('Wine (mini)'), 'unit_price' => 12.00],
                ['name' => __('Snacks'), 'unit_price' => 4.00],
            ];
            foreach ($defaults as $row) {
                HotelMinibarItem::create([
                    'name' => $row['name'],
                    'unit_price' => $row['unit_price'],
                    'is_active' => true,
                    'created_by' => $creatorId,
                ]);
            }
            $items = HotelMinibarItem::query()
                ->where('created_by', $creatorId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
        }

        return $items;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function folioLinesForCheckout(HotelFolio $folio): array
    {
        $folio->loadMissing('lines');

        return $folio->lines->map(function (HotelFolioLine $line) {
            $meta = $this->lineDisplayMeta($line);

            return [
                'icon' => $meta['icon'],
                'color' => $meta['color'],
                'bg' => $meta['bg'],
                'desc' => $meta['label'],
                'detail' => $line->description,
                'qty' => $line->quantity,
                'amount' => (float) $line->line_total,
                'line_type' => $line->line_type,
            ];
        })->values()->all();
    }

    /**
     * @return array{icon:string,color:string,bg:string,label:string}
     */
    public function lineDisplayMeta(HotelFolioLine $line): array
    {
        $type = (string) $line->line_type;
        $channel = strtolower((string) ($line->revenue_channel ?? ''));

        if ($type === 'minibar_charge' || str_contains(strtolower($line->description), 'minibar')) {
            return ['icon' => 'ti-fridge', 'color' => '#f59e0b', 'bg' => '#fffbeb', 'label' => __('Minibar')];
        }
        if ($type === 'laundry_charge' || $channel === 'laundry') {
            return ['icon' => 'ti-wash', 'color' => '#8b5cf6', 'bg' => '#f5f3ff', 'label' => __('Laundry')];
        }
        if ($type === 'room_charge' || $channel === 'room') {
            return ['icon' => 'ti-bed', 'color' => '#3b82f6', 'bg' => '#eff6ff', 'label' => __('Lodging')];
        }
        if ($type === 'pos_charge' || $channel === 'restaurant') {
            return ['icon' => 'ti-tools-kitchen-2', 'color' => '#10b981', 'bg' => '#ecfdf5', 'label' => __('Restaurant')];
        }

        return ['icon' => 'ti-receipt', 'color' => '#06b6d4', 'bg' => '#ecfeff', 'label' => __('Extra')];
    }
}
