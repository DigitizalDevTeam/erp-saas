<?php

namespace Modules\HotelManagement\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\HotelManagement\Entities\HotelRatePlan;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Entities\HotelRoomType;

class HotelReservationPricingService
{
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
        $ratePlan = $this->resolveRatePlan($creatorId, $roomTypeId, $checkInAt, $checkOutAt, $billingMode);
        $rate = $this->resolveRateAmount($type, $room, $ratePlan, $billingMode);
        [$units, $unitLabel] = $this->calculateUnits($checkInAt, $checkOutAt, $billingMode);

        return [
            'rate_per_night' => $rate,
            'rate_amount' => $rate,
            'billing_mode' => $billingMode,
            'units' => $units,
            'nights' => $units,
            'unit_label' => $unitLabel,
            'subtotal' => round($rate * $units, 2),
            'rate_plan_id' => $ratePlan?->id,
            'rate_plan_name' => $ratePlan?->name,
            'source' => $this->resolveSource($room, $ratePlan, $billingMode),
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
        $subTotal = round((float) $reservation->rate_per_night * $units, 2);
        $discount = round((float) $reservation->discount_amount, 2);

        return [
            'units' => $units,
            'unit_label' => $unitLabel,
            'sub_total' => $subTotal,
            'grand_total' => max(0, round($subTotal - $discount, 2)),
        ];
    }

    public function maxDiscountPercentForCurrentUser(): float
    {
        $user = Auth::user();
        if (! $user) {
            return 0.0;
        }

        if ($user->can('manage hotel')) {
            return 25.0;
        }

        if ($user->can('manage hotel frontdesk') || $user->can('manage hotel reservation')) {
            return 10.0;
        }

        return 0.0;
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

    private function resolveRatePlan(
        int $creatorId,
        ?int $roomTypeId,
        ?Carbon $checkInAt,
        ?Carbon $checkOutAt,
        string $billingMode
    ): ?HotelRatePlan {
        if (! $roomTypeId) {
            return null;
        }

        return HotelRatePlan::where('created_by', $creatorId)
            ->where('hotel_room_type_id', $roomTypeId)
            ->where('is_active', true)
            ->whereIn('billing_mode', [$billingMode, 'both'])
            ->when($checkInAt, function ($query) use ($checkInAt) {
                $query->where(function ($sub) use ($checkInAt) {
                    $sub->whereNull('valid_from')->orWhereDate('valid_from', '<=', $checkInAt->toDateString());
                });
            })
            ->when($checkOutAt, function ($query) use ($checkOutAt) {
                $query->where(function ($sub) use ($checkOutAt) {
                    $sub->whereNull('valid_to')->orWhereDate('valid_to', '>=', $checkOutAt->toDateString());
                });
            })
            ->orderBy('priority')
            ->orderByDesc('valid_from')
            ->first();
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
        if ($type && (float) ($type->nightly_rate ?? $type->base_price) > 0) {
            return round((float) ($type->nightly_rate ?? $type->base_price), 2);
        }

        return 0.0;
    }

    private function resolveSource(?HotelRoom $room, ?HotelRatePlan $ratePlan, string $billingMode): string
    {
        if ($billingMode === 'monthly' && $room && (float) $room->monthly_override_price > 0) {
            return 'room_monthly_override';
        }
        if ($billingMode === 'monthly' && $ratePlan && (float) $ratePlan->monthly_rate > 0) {
            return 'rate_plan_monthly';
        }
        if ($room && (float) $room->override_price > 0) {
            return 'room_override';
        }
        if ($ratePlan && (float) $ratePlan->rate > 0) {
            return 'rate_plan';
        }

        return 'room_type_default';
    }
}
