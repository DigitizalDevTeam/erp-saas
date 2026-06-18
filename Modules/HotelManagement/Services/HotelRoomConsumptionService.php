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

    public function __construct(
        private readonly HotelPosChargeService $chargeService,
        private readonly HotelStockDeductionService $stockService,
    ) {
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
     * @param  array{description?:string,quantity?:int,unit_price?:float,tax_amount?:float,discount_amount?:float,notes?:string,service_date?:string,product_id?:int,warehouse_id?:int}  $payload
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
        $qty = max(1, (int) ($payload['quantity'] ?? 1));
        $productId = isset($payload['product_id']) ? (int) $payload['product_id'] : 0;
        $warehouseId = isset($payload['warehouse_id']) ? (int) $payload['warehouse_id'] : 0;
        $unitPrice = (float) ($payload['unit_price'] ?? 0);
        $taxAmount = (float) ($payload['tax_amount'] ?? 0);

        // Product-backed consumable: resolve price/tax from the centralized catalog.
        $product = null;
        if ($chargeType === self::CHARGE_MINIBAR && $productId > 0) {
            $product = $this->stockService->findProduct($productId, $creatorId);
            if (! $product) {
                throw new \RuntimeException(__('Selected product was not found.'));
            }
            $unitPrice = (float) $product->sale_price;
            $taxAmount = round($unitPrice * $qty * $this->stockService->taxRateForProduct($product) / 100, 2);
        }

        $description = (string) ($payload['description'] ?? '');
        if ($description === '') {
            $description = $product?->name ?: match ($chargeType) {
                self::CHARGE_MINIBAR => __('Minibar'),
                self::CHARGE_LAUNDRY => __('Laundry'),
                default => __('Extra charge'),
            };
        }

        $linePayload = [
            'description' => $description,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'tax_amount' => $taxAmount,
            'discount_amount' => $payload['discount_amount'] ?? 0,
            'revenue_channel' => $this->revenueChannelFor($chargeType),
        ];

        $line = match ($chargeType) {
            self::CHARGE_MINIBAR => $this->chargeService->addMinibarChargeToFolio($folio, $linePayload, $creatorId),
            self::CHARGE_LAUNDRY => $this->chargeService->addLaundryChargeToFolio($folio, $linePayload, $creatorId),
            default => $this->chargeService->addMiscChargeToFolio($folio, $linePayload, $creatorId),
        };

        // Deduct centralized stock from the chosen warehouse, like a POS sale.
        if ($product && $warehouseId > 0) {
            $reference = __('Room :room minibar — folio :folio', [
                'room' => $room->room_no,
                'folio' => $folio->folio_no,
            ]);
            $this->stockService->deduct((int) $product->id, (float) $qty, $warehouseId, $creatorId, $reference, (int) $folio->id);
        }

        if (Schema::hasTable('hotel_room_consumption_logs')) {
            HotelRoomConsumptionLog::create([
                'hotel_room_id' => $room->id,
                'hotel_stay_id' => $stay->id,
                'hotel_folio_id' => $folio->id,
                'hotel_folio_line_id' => $line->id,
                'charge_type' => $chargeType,
                'product_id' => $product?->id,
                'warehouse_id' => $warehouseId ?: null,
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
     * Centralized stockable products usable as room consumables.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\ProductService>
     */
    public function consumableProducts(int $creatorId)
    {
        return $this->stockService->consumableProducts($creatorId);
    }

    /**
     * @return array<int, array{id:int, name:string, qty:float}>
     */
    public function warehousesForProduct(int $productId, int $creatorId): array
    {
        return $this->stockService->warehousesForProduct($productId, $creatorId);
    }

    /**
     * @return \Illuminate\Support\Collection<int, HotelMinibarItem>
     */
    public function minibarCatalog(int $creatorId)
    {
        if (! Schema::hasTable('hotel_minibar_items')) {
            return collect();
        }

        return HotelMinibarItem::query()
            ->where('created_by', $creatorId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, HotelRoomConsumptionLog>
     */
    public function consumptionLogsForStay(int $stayId, int $creatorId)
    {
        if (! Schema::hasTable('hotel_room_consumption_logs')) {
            return collect();
        }

        return HotelRoomConsumptionLog::query()
            ->where('created_by', $creatorId)
            ->where('hotel_stay_id', $stayId)
            ->orderByDesc('service_date')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function folioLinesForCheckout(HotelFolio $folio): array
    {
        $folio->loadMissing('lines');

        $logsByLineId = collect();
        if (Schema::hasTable('hotel_room_consumption_logs')) {
            $logsByLineId = HotelRoomConsumptionLog::query()
                ->where('hotel_folio_id', $folio->id)
                ->get()
                ->keyBy('hotel_folio_line_id');
        }

        return $folio->lines->map(function (HotelFolioLine $line) use ($logsByLineId) {
            $meta = $this->lineDisplayMeta($line);
            $log = $logsByLineId->get($line->id);
            $serviceDate = $log?->service_date?->format('Y-m-d')
                ?? $line->created_at?->format('Y-m-d')
                ?? '';

            return [
                'icon' => $meta['icon'],
                'color' => $meta['color'],
                'bg' => $meta['bg'],
                'desc' => $meta['label'],
                'detail' => $line->description,
                'qty' => $line->quantity,
                'amount' => (float) $line->line_total,
                'line_type' => $line->line_type,
                'service_date' => $serviceDate,
                'charge_type' => $log?->charge_type,
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
        if ($type === 'addon_charge') {
            return ['icon' => 'ti-bed', 'color' => '#6366f1', 'bg' => '#eef2ff', 'label' => __('Addons & extras')];
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
