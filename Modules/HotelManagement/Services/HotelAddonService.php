<?php

namespace Modules\HotelManagement\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelAddon;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelReservationAddon;
use Modules\HotelManagement\Entities\HotelReservationPriceLine;
use Modules\HotelManagement\Entities\HotelRoomType;

class HotelAddonService
{
    public function __construct(
        private readonly HotelPosChargeService $chargeService,
        private readonly HotelReservationPricingService $pricingService,
        private readonly HotelOccupancyPricingService $occupancyService,
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
     * @param  array<int, array{unit_price:float, rule_id:int|null}>  $resolvedPrices  Price Configurator
     *                                                                                 rule-resolved prices per addon id;
     *                                                                                 empty = catalog prices (legacy behaviour).
     */
    public function syncReservationAddons(HotelReservation $reservation, array $addonRows, int $creatorId, array $resolvedPrices = []): void
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

            $resolved = $resolvedPrices[$addon->id] ?? null;
            HotelReservationAddon::create([
                'hotel_reservation_id' => $reservation->id,
                'hotel_addon_id' => $addon->id,
                'rule_id' => $resolved['rule_id'] ?? null,
                'qty' => $row['qty'],
                'unit_price' => $resolved !== null ? (float) $resolved['unit_price'] : $addon->price,
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
     * Snapshot the Price Configurator occupancy lines (children /
     * additional persons) onto a reservation. Mirrors syncReservationAddons:
     * unposted lines are replaced; a kind that is already posted to the
     * folio is left untouched.
     *
     * @param  array<int, array<string, mixed>>  $occupancyLines  Engine lines from HotelOccupancyPricingService::price()
     */
    public function syncReservationPriceLines(HotelReservation $reservation, array $occupancyLines, int $creatorId): void
    {
        if (! Schema::hasTable('hotel_reservation_price_lines')) {
            return;
        }

        $reservation->loadMissing('priceLines');
        $postedKinds = $reservation->priceLines
            ->filter(fn (HotelReservationPriceLine $line) => $line->hotel_folio_line_id !== null)
            ->pluck('kind')
            ->all();

        $reservation->priceLines()->whereNull('hotel_folio_line_id')->delete();

        foreach ($occupancyLines as $line) {
            if (in_array($line['kind'], $postedKinds, true)) {
                continue;
            }
            HotelReservationPriceLine::create([
                'created_by' => $creatorId,
                'hotel_reservation_id' => $reservation->id,
                'kind' => $line['kind'],
                'label' => $line['label'],
                'rule_id' => $line['rule_id'] ?? null,
                'qty' => (int) $line['qty'],
                'unit_price' => (float) $line['unit_price'],
                'units' => (int) $line['units'],
                'amount' => (float) $line['amount'],
            ]);
        }
    }

    /**
     * Post unposted occupancy price lines to the folio on the room revenue
     * channel, mirroring postReservationAddonsToFolio. Zero-amount lines
     * (no rule matched — free) never post.
     */
    public function postReservationPriceLinesToFolio(HotelReservation $reservation, HotelFolio $folio, int $creatorId): void
    {
        if (! Schema::hasTable('hotel_reservation_price_lines')) {
            return;
        }

        $reservation->loadMissing('priceLines');

        foreach ($reservation->priceLines as $priceLine) {
            if ($priceLine->hotel_folio_line_id !== null || (float) $priceLine->amount <= 0) {
                continue;
            }

            $billQty = max(1, (int) $priceLine->qty) * max(1, (int) $priceLine->units);
            $description = $priceLine->label;
            if ((int) $priceLine->units > 1) {
                $description .= ' (' . (int) $priceLine->qty . ' × ' . (int) $priceLine->units . ' ' . __('Nights') . ')';
            } elseif ((int) $priceLine->qty > 1) {
                $description .= ' × ' . (int) $priceLine->qty;
            }

            $line = $this->chargeService->addOccupancyChargeToFolio(
                $folio,
                [
                    'description' => $description,
                    'quantity' => $billQty,
                    'unit_price' => (float) $priceLine->unit_price,
                    'revenue_channel' => 'room',
                ],
                $creatorId
            );

            $priceLine->update(['hotel_folio_line_id' => $line->id]);
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

        // Rule-resolved prices via the Price Configurator engine, so
        // estimates match what the reservation save path will snapshot.
        $roomType = $reservation->hotel_room_type_id
            ? HotelRoomType::where('created_by', $creatorId)->find((int) $reservation->hotel_room_type_id)
            : null;
        $lines = $this->occupancyService->priceAddonRows(
            $creatorId,
            $addonRows,
            $this->billingUnits($reservation),
            $roomType?->id !== null ? (int) $roomType->id : null,
            $reservation->check_in_at ? Carbon::parse($reservation->check_in_at) : null,
            $reservation->check_out_at ? Carbon::parse($reservation->check_out_at) : null
        );

        return round(array_sum(array_column($lines, 'amount')), 2);
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
