<?php

namespace Modules\HotelManagement\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelAddon;
use Modules\HotelManagement\Entities\HotelPriceSetting;
use Modules\HotelManagement\Entities\HotelRatePlan;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelReservationAddon;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Entities\HotelRoomType;

class HotelReservationPricingService
{
    /** Carbon dayOfWeekIso: 5=Fri, 6=Sat, 7=Sun */
    private const WEEKEND_ISO_DAYS = [5, 6, 7];

    /**
     * @return array<string, mixed>
     */
    public function quote(
        int $creatorId,
        ?int $roomTypeId,
        ?int $roomId,
        ?Carbon $checkInAt,
        ?Carbon $checkOutAt,
        ?string $billingMode = null
    ): array {
        $type = $roomTypeId ? HotelRoomType::where('created_by', $creatorId)->find($roomTypeId) : null;
        $room = $roomId ? HotelRoom::where('created_by', $creatorId)->find($roomId) : null;

        $billingMode = $this->normalizeBillingMode($billingMode ?: ($type->default_billing_mode ?? 'nightly'));
        [$units, $unitLabel] = $this->calculateUnits($checkInAt, $checkOutAt, $billingMode);

        if ($billingMode === 'monthly') {
            $ratePlan = $this->resolveBestPlanForStayWindow($creatorId, $roomTypeId, $checkInAt, $checkOutAt, $billingMode);
            $rate = $this->resolveRateAmount($type, $room, $ratePlan, $billingMode);
            $subtotal = round($rate * $units, 2);

            return [
                'rate_per_night' => $rate,
                'rate_amount' => $rate,
                'billing_mode' => $billingMode,
                'units' => $units,
                'nights' => $units,
                'unit_label' => $unitLabel,
                'subtotal' => $subtotal,
                'rate_plan_id' => $ratePlan?->id,
                'rate_plan_name' => $ratePlan?->name,
                'source' => $this->resolveSource($room, $ratePlan, $billingMode),
                'uses_weekend_rate' => false,
                'uses_promotional' => $ratePlan?->isPromotional() ?? false,
            ];
        }

        $priced = $this->priceNightlyStay($creatorId, $type, $room, $checkInAt, $checkOutAt, $units);
        $avgRate = $units > 0 ? round($priced['subtotal'] / $units, 2) : $priced['base_rate'];
        $dominantPlan = $priced['dominant_plan'];

        return [
            'rate_per_night' => $avgRate,
            'rate_amount' => $avgRate,
            'billing_mode' => $billingMode,
            'units' => $units,
            'nights' => $units,
            'unit_label' => $unitLabel,
            'subtotal' => $priced['subtotal'],
            'rate_plan_id' => $dominantPlan?->id,
            'rate_plan_name' => $dominantPlan?->name,
            'source' => $this->resolveSource($room, $dominantPlan, $billingMode),
            'uses_weekend_rate' => $priced['uses_weekend_rate'],
            'uses_promotional' => $priced['uses_promotional'],
            'weekday_rate' => $priced['base_rate'],
            'weekend_rate' => $priced['weekend_rate'],
            'night_rates' => $priced['night_rates'],
        ];
    }

    /**
     * @return array{0:int,1:string}
     */
    public function calculateUnits(?Carbon $checkInAt, ?Carbon $checkOutAt, string $billingMode): array
    {
        $billingMode = $this->normalizeBillingMode($billingMode);

        if (! $checkInAt || ! $checkOutAt || ! $checkOutAt->greaterThan($checkInAt)) {
            return [1, $billingMode === 'monthly' ? __('Months') : __('Nights')];
        }

        if ($billingMode === 'monthly') {
            $days = max(1, $checkInAt->diffInDays($checkOutAt));

            return [max(1, (int) ceil($days / 30)), __('Months')];
        }

        $hours = max(1, $checkInAt->diffInHours($checkOutAt));

        return [max(1, (int) ceil($hours / 24)), __('Nights')];
    }

    /**
     * @return array<string, mixed>
     */
    public function reservationTotals(HotelReservation $reservation): array
    {
        $checkInAt = Carbon::parse($reservation->check_in_at ?: ($reservation->check_in_date . ' 12:00:00'));
        $checkOutAt = Carbon::parse($reservation->check_out_at ?: ($reservation->check_out_date . ' 12:00:00'));
        [$units, $unitLabel] = $this->calculateUnits($checkInAt, $checkOutAt, (string) ($reservation->billing_mode ?? 'nightly'));
        $roomSubTotal = round((float) $reservation->rate_per_night * $units, 2);
        $addonSubTotal = $this->reservationAddonSubtotal($reservation, $units);
        $subTotal = round($roomSubTotal + $addonSubTotal, 2);
        $discount = round((float) $reservation->discount_amount, 2);

        return [
            'units' => $units,
            'unit_label' => $unitLabel,
            'room_sub_total' => $roomSubTotal,
            'addon_sub_total' => $addonSubTotal,
            'sub_total' => $subTotal,
            'grand_total' => max(0, round($subTotal - $discount, 2)),
        ];
    }

    private function reservationAddonSubtotal(HotelReservation $reservation, int $units): float
    {
        $reservation->loadMissing('reservationAddons');
        $total = 0.0;

        foreach ($reservation->reservationAddons as $addonLine) {
            if (! $addonLine instanceof HotelReservationAddon) {
                continue;
            }
            $qty = max(1, (int) $addonLine->qty);
            $multiplier = $addonLine->billing_mode === HotelAddon::BILLING_PER_NIGHT ? max(1, $units) : 1;
            $total += $qty * (float) $addonLine->unit_price * $multiplier;
        }

        return round($total, 2);
    }

    public function maxDiscountPercentForCurrentUser(): float
    {
        $user = Auth::user();
        if (! $user) {
            return 0.0;
        }

        if ($user->can('manage hotel')) {
            return $this->configuredDiscountCap($user, true) ?? HotelPriceSetting::DEFAULT_DISCOUNT_CAP_MANAGER;
        }

        if ($user->can('manage hotel frontdesk') || $user->can('manage hotel reservation')) {
            return $this->configuredDiscountCap($user, false) ?? HotelPriceSetting::DEFAULT_DISCOUNT_CAP_FRONTDESK;
        }

        return 0.0;
    }

    /**
     * Discount caps are editable in the Price Configurator (Taxes & charges
     * tab); without a settings row the historical defaults apply.
     */
    private function configuredDiscountCap($user, bool $manager): ?float
    {
        if (! Schema::hasTable('hotel_price_settings') || ! method_exists($user, 'creatorId')) {
            return null;
        }

        $setting = HotelPriceSetting::query()->where('created_by', $user->creatorId())->first();
        if (! $setting) {
            return null;
        }

        return (float) ($manager ? $setting->discount_cap_manager_percent : $setting->discount_cap_frontdesk_percent);
    }

    public function validateDiscountAmount(float $discountAmount, float $subTotal): bool
    {
        if ($discountAmount <= 0) {
            return true;
        }

        $maxPercent = $this->maxDiscountPercentForCurrentUser();
        if ($maxPercent <= 0) {
            return false;
        }

        return $discountAmount <= round($subTotal * ($maxPercent / 100), 2);
    }

    private function normalizeBillingMode(?string $billingMode): string
    {
        return $billingMode === 'monthly' ? 'monthly' : 'nightly';
    }

    /**
     * Load active plans for a room type (period + promotional).
     *
     * @return Collection<int, HotelRatePlan>
     */
    private function loadCandidatePlans(int $creatorId, ?int $roomTypeId, string $billingMode): Collection
    {
        if (! $roomTypeId) {
            return collect();
        }

        return HotelRatePlan::where('created_by', $creatorId)
            ->where('hotel_room_type_id', $roomTypeId)
            ->where('is_active', true)
            ->whereIn('billing_mode', [$billingMode, 'both'])
            ->orderByRaw("CASE WHEN plan_type = 'promotional' THEN 0 ELSE 1 END")
            ->orderBy('priority')
            ->orderByDesc('valid_from')
            ->get();
    }

    /**
     * Best plan overlapping the full stay window (used for monthly billing).
     */
    private function resolveBestPlanForStayWindow(
        int $creatorId,
        ?int $roomTypeId,
        ?Carbon $checkInAt,
        ?Carbon $checkOutAt,
        string $billingMode
    ): ?HotelRatePlan {
        $plans = $this->loadCandidatePlans($creatorId, $roomTypeId, $billingMode);
        if ($plans->isEmpty()) {
            return null;
        }

        if (! $checkInAt || ! $checkOutAt) {
            return $plans->first();
        }

        $stayStart = $checkInAt->copy();
        $stayEnd = $checkOutAt->copy();

        return $plans->first(function (HotelRatePlan $plan) use ($stayStart, $stayEnd) {
            return $this->planOverlapsWindow($plan, $stayStart, $stayEnd);
        });
    }

    /**
     * Pick best plan for a single night interval [nightStart, nightEnd).
     * Priority: promotional > period; within type: lower priority, then newer valid_from.
     */
    private function resolveRatePlanForNight(Collection $plans, Carbon $nightStart, Carbon $nightEnd): ?HotelRatePlan
    {
        $overlapping = $plans->filter(function (HotelRatePlan $plan) use ($nightStart, $nightEnd) {
            return $this->planOverlapsWindow($plan, $nightStart, $nightEnd);
        });

        if ($overlapping->isEmpty()) {
            return null;
        }

        $promos = $overlapping->filter(fn (HotelRatePlan $p) => $p->isPromotional());
        $pool = $promos->isNotEmpty() ? $promos : $overlapping;

        return $pool
            ->sortByDesc(fn (HotelRatePlan $p) => optional($p->valid_from)->getTimestamp() ?? 0)
            ->sortBy(fn (HotelRatePlan $p) => (int) $p->priority)
            ->first();
    }

    private function planOverlapsWindow(HotelRatePlan $plan, Carbon $windowStart, Carbon $windowEnd): bool
    {
        $from = $plan->valid_from ? Carbon::parse($plan->valid_from) : null;
        $to = $plan->valid_to ? Carbon::parse($plan->valid_to) : null;

        // Open-ended plans: null from = always started; null to = never ends.
        if ($from && $from->greaterThanOrEqualTo($windowEnd)) {
            return false;
        }
        if ($to && $to->lessThanOrEqualTo($windowStart)) {
            return false;
        }

        return true;
    }

    /**
     * @return array{
     *   subtotal:float,
     *   base_rate:float,
     *   weekend_rate:float|null,
     *   uses_weekend_rate:bool,
     *   uses_promotional:bool,
     *   dominant_plan:?HotelRatePlan,
     *   night_rates:array<int, array<string, mixed>>
     * }
     */
    private function priceNightlyStay(
        int $creatorId,
        ?HotelRoomType $type,
        ?HotelRoom $room,
        ?Carbon $checkInAt,
        ?Carbon $checkOutAt,
        int $units
    ): array {
        $normalRate = $this->resolveTypeDefaultRate($type, 'nightly');
        $hasRoomOverride = $room && (float) $room->override_price > 0;
        $roomOverride = $hasRoomOverride ? round((float) $room->override_price, 2) : null;

        $plans = $this->loadCandidatePlans($creatorId, $type?->id, 'nightly');

        $subtotal = 0.0;
        $usesWeekend = false;
        $usesPromotional = false;
        $dominantPlan = null;
        $seenWeekendRate = null;
        $nightRates = [];
        $units = max(1, $units);

        if (! $checkInAt) {
            $rate = $roomOverride ?? $normalRate;

            return [
                'subtotal' => round($rate * $units, 2),
                'base_rate' => $normalRate,
                'weekend_rate' => null,
                'uses_weekend_rate' => false,
                'uses_promotional' => false,
                'dominant_plan' => null,
                'night_rates' => [],
            ];
        }

        for ($i = 0; $i < $units; $i++) {
            $nightStart = $checkInAt->copy()->addDays($i);
            $nightEnd = $checkInAt->copy()->addDays($i + 1);

            if ($roomOverride !== null) {
                $nightRate = $roomOverride;
                $plan = null;
                $source = 'room_override';
            } else {
                $plan = $this->resolveRatePlanForNight($plans, $nightStart, $nightEnd);
                if ($plan) {
                    $nightRate = round((float) $plan->rate, 2);
                    $source = $plan->isPromotional() ? 'promotional' : 'period';
                    if ($plan->isPromotional()) {
                        $usesPromotional = true;
                    }
                    // Weekend rate only on period plans (not promo), when no room override.
                    if (
                        ! $plan->isPromotional()
                        && $plan->weekend_rate !== null
                        && (float) $plan->weekend_rate > 0
                        && $this->isWeekendNight($nightStart)
                    ) {
                        $nightRate = round((float) $plan->weekend_rate, 2);
                        $usesWeekend = true;
                        $seenWeekendRate = $nightRate;
                        $source = 'period_weekend';
                    }
                    if ($dominantPlan === null) {
                        $dominantPlan = $plan;
                    } elseif ($plan->isPromotional() && ! $dominantPlan->isPromotional()) {
                        $dominantPlan = $plan;
                    }
                } else {
                    $nightRate = $normalRate;
                    $source = 'room_type_default';
                }
            }

            $subtotal += $nightRate;
            $nightRates[] = [
                'night_index' => $i,
                'date' => $nightStart->toDateString(),
                'rate' => $nightRate,
                'source' => $source,
                'rate_plan_id' => $plan?->id,
                'rate_plan_name' => $plan?->name,
            ];
        }

        return [
            'subtotal' => round($subtotal, 2),
            'base_rate' => $normalRate,
            'weekend_rate' => $seenWeekendRate,
            'uses_weekend_rate' => $usesWeekend,
            'uses_promotional' => $usesPromotional,
            'dominant_plan' => $dominantPlan,
            'night_rates' => $nightRates,
        ];
    }

    private function isWeekendNight(Carbon $nightDate): bool
    {
        return in_array((int) $nightDate->dayOfWeekIso, self::WEEKEND_ISO_DAYS, true);
    }

    private function resolveRateAmount(
        ?HotelRoomType $type,
        ?HotelRoom $room,
        ?HotelRatePlan $ratePlan,
        string $billingMode
    ): float {
        if ($billingMode === 'monthly') {
            if ($room && (float) $room->monthly_override_price > 0) {
                return round((float) $room->monthly_override_price, 2);
            }
            if ($ratePlan && (float) $ratePlan->monthly_rate > 0) {
                return round((float) $ratePlan->monthly_rate, 2);
            }
            if ($type && (float) $type->monthly_rate > 0) {
                return round((float) $type->monthly_rate, 2);
            }
        }

        if ($room && (float) $room->override_price > 0) {
            return round((float) $room->override_price, 2);
        }
        if ($ratePlan && (float) $ratePlan->rate > 0) {
            return round((float) $ratePlan->rate, 2);
        }
        $typeRate = $this->resolveTypeDefaultRate($type, $billingMode);
        if ($typeRate > 0) {
            return $typeRate;
        }

        return 0.0;
    }

    private function resolveTypeDefaultRate(?HotelRoomType $type, string $billingMode): float
    {
        if (! $type) {
            return 0.0;
        }

        if ($billingMode === 'monthly') {
            $monthly = (float) ($type->monthly_rate ?? 0);
            if ($monthly > 0) {
                return round($monthly, 2);
            }
        }

        $nightly = (float) ($type->nightly_rate ?? 0);
        $base = (float) ($type->base_price ?? 0);

        return round($nightly > 0 ? $nightly : $base, 2);
    }

    private function resolveSource(?HotelRoom $room, ?HotelRatePlan $ratePlan, string $billingMode): string
    {
        if ($billingMode === 'monthly' && $room && (float) $room->monthly_override_price > 0) {
            return 'room_monthly_override';
        }
        if ($billingMode === 'monthly' && $ratePlan && (float) $ratePlan->monthly_rate > 0) {
            return $ratePlan->isPromotional() ? 'promotional_monthly' : 'rate_plan_monthly';
        }
        if ($room && (float) $room->override_price > 0) {
            return 'room_override';
        }
        if ($ratePlan && (float) $ratePlan->rate > 0) {
            return $ratePlan->isPromotional() ? 'promotional' : 'rate_plan';
        }

        return 'room_type_default';
    }
}
