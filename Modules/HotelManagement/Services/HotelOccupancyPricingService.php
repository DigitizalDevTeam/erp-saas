<?php

namespace Modules\HotelManagement\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelAddon;
use Modules\HotelManagement\Entities\HotelPriceRule;
use Modules\HotelManagement\Entities\HotelPriceSetting;
use Modules\HotelManagement\Entities\HotelRoomType;

/**
 * Price Configurator rules engine.
 *
 * Single source of truth for occupancy pricing (children + additional
 * persons) and rule-resolved addon prices. The reservation form's live
 * quote and the server-side save path both call price() so the numbers a
 * guest is quoted are the numbers that get saved. Room rates are NOT
 * priced here — HotelReservationPricingService stays the room authority.
 */
class HotelOccupancyPricingService
{
    public function settings(int $creatorId): ?HotelPriceSetting
    {
        if (! Schema::hasTable('hotel_price_settings')) {
            return null;
        }

        return HotelPriceSetting::query()->where('created_by', $creatorId)->first();
    }

    /**
     * Adults included in the room price before extra-person rules apply.
     * Room-type override wins over the global setting; default 2.
     */
    public function resolveIncludedAdults(int $creatorId, ?HotelRoomType $roomType): int
    {
        if ($roomType !== null && $roomType->included_adults !== null) {
            return max(0, (int) $roomType->included_adults);
        }

        $setting = $this->settings($creatorId);
        if ($setting !== null) {
            return max(0, (int) $setting->included_adults);
        }

        return HotelPriceSetting::DEFAULT_INCLUDED_ADULTS;
    }

    public function suggestedExtraAdults(int $adults, int $includedAdults): int
    {
        return max(0, $adults - $includedAdults);
    }

    /**
     * Find the single rule that prices a quantity, or null when no rule
     * matches (the charge is then free — today's behaviour — and the
     * caller surfaces a "no rule" note).
     *
     * Precedence: a rule scoped to this room type beats a global rule;
     * within a scope the lowest priority wins; ties break by lowest id,
     * so matching is fully deterministic.
     */
    public function matchRule(
        int $creatorId,
        string $ruleType,
        int $qty,
        ?int $roomTypeId = null,
        ?int $addonId = null,
        ?int $adults = null,
        ?Carbon $stayStart = null,
        ?Carbon $stayEnd = null
    ): ?HotelPriceRule {
        if ($qty < 1 || ! Schema::hasTable('hotel_price_rules')) {
            return null;
        }

        $candidates = HotelPriceRule::query()
            ->where('created_by', $creatorId)
            ->where('rule_type', $ruleType)
            ->where('is_active', true)
            ->when($ruleType === HotelPriceRule::TYPE_ADDON, fn ($q) => $q->where('hotel_addon_id', $addonId))
            // A rule scoped to a different room type never applies.
            ->where(function ($q) use ($roomTypeId) {
                $q->whereNull('hotel_room_type_id');
                if ($roomTypeId !== null) {
                    $q->orWhere('hotel_room_type_id', $roomTypeId);
                }
            })
            ->where('qty_from', '<=', $qty)
            ->where(function ($q) use ($qty) {
                $q->whereNull('qty_to')->orWhere('qty_to', '>=', $qty);
            })
            ->when($ruleType === HotelPriceRule::TYPE_CHILD && $adults !== null, function ($q) use ($adults) {
                $q->where(function ($inner) use ($adults) {
                    $inner->whereNull('adults_min')->orWhere('adults_min', '<=', $adults);
                })->where(function ($inner) use ($adults) {
                    $inner->whereNull('adults_max')->orWhere('adults_max', '>=', $adults);
                });
            })
            ->when($stayStart !== null, function ($q) use ($stayStart, $stayEnd) {
                $end = ($stayEnd ?? $stayStart)->toDateString();
                $start = $stayStart->toDateString();
                $q->where(function ($inner) use ($end) {
                    $inner->whereNull('valid_from')->orWhereDate('valid_from', '<=', $end);
                })->where(function ($inner) use ($start) {
                    $inner->whereNull('valid_to')->orWhereDate('valid_to', '>=', $start);
                });
            })
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        // Explicit comparator: Collection::sortBy() with a list of closures
        // does not sort by multiple ascending keys in this Laravel version.
        return $candidates
            ->sort(function (HotelPriceRule $a, HotelPriceRule $b) use ($roomTypeId) {
                $scopeA = ($roomTypeId !== null && (int) $a->hotel_room_type_id === $roomTypeId) ? 0 : 1;
                $scopeB = ($roomTypeId !== null && (int) $b->hotel_room_type_id === $roomTypeId) ? 0 : 1;

                return [$scopeA, (int) $a->priority, (int) $a->id]
                    <=> [$scopeB, (int) $b->priority, (int) $b->id];
            })
            ->first();
    }

    /**
     * Price occupancy charges + addon rows for a stay.
     *
     * $addonRows: [['addon_id' => int, 'qty' => int], ...] (request-shaped).
     * Per-night prices multiply by the stay's billing units (nights, or
     * months on monthly stays) — the same convention addons already use.
     */
    public function price(
        int $creatorId,
        ?HotelRoomType $roomType,
        int $billingUnits,
        int $adults,
        int $children,
        int $extraAdults,
        array $addonRows = [],
        ?Carbon $checkInAt = null,
        ?Carbon $checkOutAt = null
    ): array {
        $units = max(1, $billingUnits);
        $roomTypeId = $roomType?->id !== null ? (int) $roomType->id : null;
        $includedAdults = $this->resolveIncludedAdults($creatorId, $roomType);

        $occupancyLines = [];
        foreach ([
            [HotelPriceRule::TYPE_CHILD, max(0, $children), __('Children')],
            [HotelPriceRule::TYPE_EXTRA_PERSON, max(0, $extraAdults), __('Additional persons')],
        ] as [$ruleType, $qty, $fallbackLabel]) {
            if ($qty < 1) {
                continue;
            }
            $rule = $this->matchRule($creatorId, $ruleType, $qty, $roomTypeId, null, $adults, $checkInAt, $checkOutAt);
            $lineUnits = ($rule !== null && $rule->price_basis === HotelPriceRule::BASIS_PER_STAY) ? 1 : $units;
            $unitPrice = $rule !== null ? (float) $rule->price : 0.0;
            $occupancyLines[] = [
                'kind' => $ruleType,
                'label' => $rule !== null ? $rule->name : $fallbackLabel,
                'rule_id' => $rule?->id !== null ? (int) $rule->id : null,
                'rule_name' => $rule?->name,
                'no_rule' => $rule === null,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'units' => $lineUnits,
                'amount' => round($unitPrice * $qty * $lineUnits, 2),
            ];
        }

        $addonLines = $this->priceAddonRows($creatorId, $addonRows, $units, $roomTypeId, $checkInAt, $checkOutAt);

        $occupancyTotal = round(array_sum(array_column($occupancyLines, 'amount')), 2);
        $addonsTotal = round(array_sum(array_column($addonLines, 'amount')), 2);

        return [
            'included_adults' => $includedAdults,
            'occupancy_lines' => $occupancyLines,
            'occupancy_total' => $occupancyTotal,
            'addon_lines' => $addonLines,
            'addons_total' => $addonsTotal,
            'charges_total' => round($occupancyTotal + $addonsTotal, 2),
        ];
    }

    /**
     * Resolve each addon row's unit price: a matching addon rule replaces
     * the catalog price before the addon's own billing mode is applied
     * (per-night rows then multiply by billing units, exactly like the
     * saved-snapshot totals in HotelReservationPricingService).
     */
    public function priceAddonRows(
        int $creatorId,
        array $addonRows,
        int $billingUnits,
        ?int $roomTypeId = null,
        ?Carbon $checkInAt = null,
        ?Carbon $checkOutAt = null
    ): array {
        if ($addonRows === [] || ! Schema::hasTable('hotel_addons')) {
            return [];
        }

        $units = max(1, $billingUnits);
        $addons = HotelAddon::query()
            ->where('created_by', $creatorId)
            ->whereIn('id', collect($addonRows)->pluck('addon_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $lines = [];
        foreach ($addonRows as $row) {
            $addon = $addons->get($row['addon_id'] ?? null);
            if (! $addon) {
                continue;
            }
            $qty = max(1, (int) ($row['qty'] ?? 1));
            $rule = $this->matchRule($creatorId, HotelPriceRule::TYPE_ADDON, $qty, $roomTypeId, (int) $addon->id, null, $checkInAt, $checkOutAt);
            $unitPrice = $rule !== null ? (float) $rule->price : (float) $addon->price;
            $multiplier = $addon->billing_mode === HotelAddon::BILLING_PER_NIGHT ? $units : 1;
            $lines[] = [
                'addon_id' => (int) $addon->id,
                'name' => $addon->name,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'billing_mode' => $addon->billing_mode,
                'rule_id' => $rule?->id !== null ? (int) $rule->id : null,
                'rule_name' => $rule?->name,
                'multiplier' => $multiplier,
                'amount' => round($qty * $unitPrice * $multiplier, 2),
            ];
        }

        return $lines;
    }

    /**
     * Rules that would compete with a candidate rule (same type + scope,
     * intersecting quantity range / adults band / validity window).
     * Used by the Price Configurator to warn before saving an overlap;
     * matching itself stays deterministic via matchRule().
     */
    public function findOverlappingRules(int $creatorId, array $attrs, ?int $excludeId = null): Collection
    {
        if (! Schema::hasTable('hotel_price_rules')) {
            return collect();
        }

        $qtyFrom = (int) ($attrs['qty_from'] ?? 1);
        $qtyTo = isset($attrs['qty_to']) && $attrs['qty_to'] !== null ? (int) $attrs['qty_to'] : null;

        return HotelPriceRule::query()
            ->where('created_by', $creatorId)
            ->where('rule_type', $attrs['rule_type'] ?? '')
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', $excludeId))
            ->where(function ($q) use ($attrs) {
                // Same scope only: both global, or the same room type.
                if (! empty($attrs['hotel_room_type_id'])) {
                    $q->where('hotel_room_type_id', (int) $attrs['hotel_room_type_id']);
                } else {
                    $q->whereNull('hotel_room_type_id');
                }
            })
            ->when(($attrs['rule_type'] ?? null) === HotelPriceRule::TYPE_ADDON, fn ($q) => $q->where('hotel_addon_id', $attrs['hotel_addon_id'] ?? null))
            // Quantity ranges intersect (null qty_to = open-ended).
            ->where('qty_from', '<=', $qtyTo ?? PHP_INT_MAX)
            ->where(function ($q) use ($qtyFrom) {
                $q->whereNull('qty_to')->orWhere('qty_to', '>=', $qtyFrom);
            })
            ->when(($attrs['rule_type'] ?? null) === HotelPriceRule::TYPE_CHILD, function ($q) use ($attrs) {
                $min = isset($attrs['adults_min']) && $attrs['adults_min'] !== null ? (int) $attrs['adults_min'] : null;
                $max = isset($attrs['adults_max']) && $attrs['adults_max'] !== null ? (int) $attrs['adults_max'] : null;
                $q->where(function ($inner) use ($max) {
                    $inner->whereNull('adults_min')->orWhere('adults_min', '<=', $max ?? PHP_INT_MAX);
                })->where(function ($inner) use ($min) {
                    $inner->whereNull('adults_max')->orWhere('adults_max', '>=', $min ?? 0);
                });
            })
            // Validity windows intersect (null bound = open).
            ->where(function ($q) use ($attrs) {
                $q->whereNull('valid_from');
                if (! empty($attrs['valid_to'])) {
                    $q->orWhereDate('valid_from', '<=', $attrs['valid_to']);
                }
            })
            ->where(function ($q) use ($attrs) {
                $q->whereNull('valid_to');
                if (! empty($attrs['valid_from'])) {
                    $q->orWhereDate('valid_to', '>=', $attrs['valid_from']);
                }
            })
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }
}
