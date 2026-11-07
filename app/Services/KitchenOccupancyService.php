<?php

namespace App\Services;

use Modules\HotelManagement\Entities\HotelReservation;

/**
 * Kitchen Production — hotel occupancy feed (Phase 3, plan §2–§3).
 *
 * Read-only use of the Hotel module: nothing here writes to a hotel
 * table. Guests in-house on a date are the reservations with
 * check-in <= date < check-out whose status is confirmed or checked_in
 * (both are in-house; pending and cancelled are not). A reservation
 * without a meal plan (or room-only) feeds no meal covers.
 *
 * Meal covers follow the standard hotel convention:
 * - breakfast: breakfast, half board, full board, all inclusive
 * - lunch: full board, all inclusive (half board is breakfast + dinner)
 * - dinner: half board, full board, all inclusive
 *
 * Covers count adults + extra adults + children. Infants are recorded
 * but do not eat a cover.
 */
class KitchenOccupancyService
{
    public const MEAL_PLANS_WITH_BREAKFAST = [
        HotelReservation::MEAL_BREAKFAST,
        HotelReservation::MEAL_HALF_BOARD,
        HotelReservation::MEAL_FULL_BOARD,
        HotelReservation::MEAL_ALL_INCLUSIVE,
    ];

    public const MEAL_PLANS_WITH_LUNCH = [
        HotelReservation::MEAL_FULL_BOARD,
        HotelReservation::MEAL_ALL_INCLUSIVE,
    ];

    public const MEAL_PLANS_WITH_DINNER = [
        HotelReservation::MEAL_HALF_BOARD,
        HotelReservation::MEAL_FULL_BOARD,
        HotelReservation::MEAL_ALL_INCLUSIVE,
    ];

    /**
     * Occupancy snapshot for one date: the counts AND the reservations
     * behind them, so the chef can see exactly why a number is what it
     * is (plan §11.4).
     *
     * @return array{date: string, branch_id: int|null, total_reservations: int, total_guests: int, breakfast_covers: int, lunch_covers: int, dinner_covers: int, reservations: array<int, array{id: int, reservation_no: string|null, guests: int, infants: int, meal_plan: string|null, status: string, check_in_date: string, check_out_date: string, branch_id: int|null}>}
     */
    public function snapshot(string $date, int $creatorId, ?int $branchId = null): array
    {
        $reservations = HotelReservation::where('created_by', $creatorId)
            ->where('check_in_date', '<=', $date)
            ->where('check_out_date', '>', $date)
            ->whereIn('status', [HotelReservation::STATUS_CONFIRMED, HotelReservation::STATUS_CHECKED_IN])
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('check_in_date')
            ->orderBy('id')
            ->get(['id', 'reservation_no', 'adults', 'extra_adults', 'children', 'infants', 'meal_plan', 'status', 'check_in_date', 'check_out_date', 'branch_id']);

        $rows = [];
        $totalGuests = 0;
        $breakfast = 0;
        $lunch = 0;
        $dinner = 0;

        foreach ($reservations as $reservation) {
            $guests = (int) ($reservation->adults ?? 0)
                + (int) ($reservation->extra_adults ?? 0)
                + (int) ($reservation->children ?? 0);
            $infants = (int) ($reservation->infants ?? 0);
            $mealPlan = $reservation->meal_plan;

            $totalGuests += $guests;
            if (in_array($mealPlan, self::MEAL_PLANS_WITH_BREAKFAST, true)) {
                $breakfast += $guests;
            }
            if (in_array($mealPlan, self::MEAL_PLANS_WITH_LUNCH, true)) {
                $lunch += $guests;
            }
            if (in_array($mealPlan, self::MEAL_PLANS_WITH_DINNER, true)) {
                $dinner += $guests;
            }

            $rows[] = [
                'id' => (int) $reservation->id,
                'reservation_no' => $reservation->reservation_no,
                'guests' => $guests,
                'infants' => $infants,
                'meal_plan' => $mealPlan,
                'status' => $reservation->status,
                // check_in/out are cast to Carbon 'date' by the entity.
                'check_in_date' => $reservation->check_in_date instanceof \DateTimeInterface
                    ? $reservation->check_in_date->format('Y-m-d')
                    : (string) $reservation->check_in_date,
                'check_out_date' => $reservation->check_out_date instanceof \DateTimeInterface
                    ? $reservation->check_out_date->format('Y-m-d')
                    : (string) $reservation->check_out_date,
                'branch_id' => $reservation->branch_id !== null ? (int) $reservation->branch_id : null,
            ];
        }

        return [
            'date' => $date,
            'branch_id' => $branchId,
            'total_reservations' => count($rows),
            'total_guests' => $totalGuests,
            'breakfast_covers' => $breakfast,
            'lunch_covers' => $lunch,
            'dinner_covers' => $dinner,
            'reservations' => $rows,
        ];
    }

    /** Covers for one meal service on a date (no snapshot stored). */
    public function coversForMeal(string $date, string $mealService, int $creatorId, ?int $branchId = null): int
    {
        $snapshot = $this->snapshot($date, $creatorId, $branchId);

        return match ($mealService) {
            'breakfast' => $snapshot['breakfast_covers'],
            'lunch' => $snapshot['lunch_covers'],
            'dinner' => $snapshot['dinner_covers'],
            default => $snapshot['total_guests'],
        };
    }
}
