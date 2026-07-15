<?php

namespace Modules\HotelManagement\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelAddon;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelReservationAddon;
use Modules\HotelManagement\Entities\HotelRoomType;

class HotelAddonService
{
    public function __construct(
        private readonly HotelPosChargeService $chargeService,
        private readonly HotelReservationPricingService $pricingService,
    ) {
    }

    /**
     * @return array<int, array{addon_id:int,qty:int}>
     */
    public function normalizeAddonInput(?array $rows): array
    {
        $normalized = [];
        foreach ($rows ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $addonId = (int) ($row['addon_id'] ?? 0);
            $qty = max(1, (int) ($row['qty'] ?? 1));
            if ($addonId < 1) {
                continue;
            }
            $normalized[] = ['addon_id' => $addonId, 'qty' => $qty];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{addon_id:int,qty:int}>  $addonRows
     */
    public function validateOccupancyForBooking(
        int $roomTypeId,
        int $adults,
        int $children,
        array $addonRows,
        int $creatorId
    ): ?string {
        $roomType = HotelRoomType::where('created_by', $creatorId)->find($roomTypeId);
        if (! $roomType) {
            return null;
        }

        $extraBeds = $this->extraBedCount($addonRows, $creatorId);
        $maxGuests = (int) $roomType->max_occupancy + $extraBeds;
        $guests = max(1, $adults) + max(0, $children);

        if ($guests > $maxGuests) {
            return __('Guest count exceeds room capacity. Max :max (including :beds extra bed(s)).', [
                'max' => $maxGuests,
                'beds' => $extraBeds,
            ]);
        }

        return null;
    }

    /**
     * @param  array<int, array{addon_id:int,qty:int}>  $addonRows
     */
    public function validateOccupancy(HotelReservation $reservation, array $addonRows, int $creatorId): ?string
    {
        return $this->validateOccupancyForBooking(
            (int) $reservation->hotel_room_type_id,
            (int) ($reservation->adults ?? 1),
            (int) ($reservation->children ?? 0),
            $addonRows,
            $creatorId
        );
    }

    /**
     * @param  array<int, array{addon_id:int,qty:int}>  $addonRows
     */
    public function extraBedCount(array $addonRows, int $creatorId): int
    {
        if ($addonRows === [] || ! Schema::hasTable('hotel_addons')) {
            return 0;
        }

        $ids = collect($addonRows)->pluck('addon_id')->unique()->all();
        $addons = HotelAddon::where('created_by', $creatorId)->whereIn('id', $ids)->get()->keyBy('id');
        $count = 0;
        foreach ($addonRows as $row) {
            $addon = $addons->get($row['addon_id']);
            if ($addon && $addon->type === HotelAddon::TYPE_EXTRA_BED) {
                $count += (int) $row['qty'];
            }
        }

        return $count;
    }

    /**
     * @param  array<int, array{addon_id:int,qty:int}>  $addonRows
     */
    public function syncReservationAddons(HotelReservation $reservation, array $addonRows, int $creatorId): void
    {
        if (! Schema::hasTable('hotel_reservation_addons')) {
            return;
        }

        $reservation->loadMissing('reservationAddons');
        $postedIds = $reservation->reservationAddons
            ->filter(fn (HotelReservationAddon $row) => $row->isPosted())
            ->pluck('hotel_addon_id')
            ->all();

        $reservation->reservationAddons()
            ->whereNull('hotel_folio_line_id')
            ->delete();

        $addons = HotelAddon::where('created_by', $creatorId)
            ->where('is_active', true)
            ->whereIn('id', collect($addonRows)->pluck('addon_id')->unique()->all())
            ->get()
            ->keyBy('id');

        foreach ($addonRows as $row) {
            $addon = $addons->get($row['addon_id']);
            if (! $addon) {
                continue;
            }
            if (in_array($addon->id, $postedIds, true)) {
                continue;
            }

            HotelReservationAddon::create([
                'hotel_reservation_id' => $reservation->id,
                'hotel_addon_id' => $addon->id,
                'qty' => $row['qty'],
                'unit_price' => $addon->price,
                'billing_mode' => $addon->billing_mode,
                'created_by' => $creatorId,
            ]);
        }
    }

    public function postReservationAddonsToFolio(HotelReservation $reservation, HotelFolio $folio, int $creatorId): void
    {
        if (! Schema::hasTable('hotel_reservation_addons')) {
            return;
        }

        $reservation->loadMissing(['reservationAddons.addon']);
        $units = $this->billingUnits($reservation);

        foreach ($reservation->reservationAddons as $reservationAddon) {
            if ($reservationAddon->isPosted()) {
                continue;
            }

            $addon = $reservationAddon->addon;
            if (! $addon) {
                continue;
            }

            $billQty = $this->billableQuantity($reservationAddon, $units);
            $line = $this->chargeService->addAddonChargeToFolio(
                $folio,
                [
                    'description' => $this->lineDescription($addon, $reservationAddon, $billQty, $units),
                    'quantity' => $billQty,
                    'unit_price' => (float) $reservationAddon->unit_price,
                    'revenue_channel' => $this->revenueChannelFor($addon),
                    'hotel_addon_id' => $addon->id,
                ],
                $creatorId
            );

            $reservationAddon->update(['hotel_folio_line_id' => $line->id]);
        }
    }

    /**
     * @param  array<int, array{addon_id:int,qty:int}>  $addonRows
     */
    public function estimateAddonsTotal(HotelReservation $reservation, array $addonRows, int $creatorId): float
    {
        if ($addonRows === [] || ! Schema::hasTable('hotel_addons')) {
            return 0.0;
        }

        $addons = HotelAddon::where('created_by', $creatorId)
            ->whereIn('id', collect($addonRows)->pluck('addon_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $units = $this->billingUnits($reservation);
        $total = 0.0;

        foreach ($addonRows as $row) {
            $addon = $addons->get($row['addon_id']);
            if (! $addon) {
                continue;
            }
            $qty = max(1, (int) $row['qty']);
            $multiplier = $addon->billing_mode === HotelAddon::BILLING_PER_NIGHT ? max(1, $units) : 1;
            $total += $qty * (float) $addon->price * $multiplier;
        }

        return round($total, 2);
    }

    private function billingUnits(HotelReservation $reservation): int
    {
        $totals = $this->pricingService->reservationTotals($reservation);

        return max(1, (int) ($totals['units'] ?? 1));
    }

    private function billableQuantity(HotelReservationAddon $reservationAddon, int $units): int
    {
        $qty = max(1, (int) $reservationAddon->qty);
        if ($reservationAddon->billing_mode === HotelAddon::BILLING_PER_NIGHT) {
            return $qty * max(1, $units);
        }

        return $qty;
    }

    private function lineDescription(HotelAddon $addon, HotelReservationAddon $reservationAddon, int $billQty, int $units): string
    {
        $base = $addon->name;
        if ($reservationAddon->billing_mode === HotelAddon::BILLING_PER_NIGHT) {
            return $base . ' (' . $reservationAddon->qty . ' × ' . $units . ' ' . __('Nights') . ')';
        }

        if ($reservationAddon->billing_mode === HotelAddon::BILLING_PER_UNIT) {
            return $base . ' (' . $reservationAddon->qty . ' ' . __('units') . ')';
        }

        return $base . ($reservationAddon->qty > 1 ? ' × ' . $reservationAddon->qty : '');
    }

    private function revenueChannelFor(HotelAddon $addon): string
    {
        return match ($addon->type) {
            HotelAddon::TYPE_BREAKFAST => 'restaurant',
            HotelAddon::TYPE_EXTRA_BED => 'room',
            HotelAddon::TYPE_LAUNDRY => 'laundry',
            default => 'misc',
        };
    }
}
