<?php

namespace Modules\HotelManagement\Services;

use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelCashierShift;
use Modules\HotelManagement\Entities\HotelExpense;

/**
 * Hotel petty cash = open cashier shift float. Cash expenses deduct from expected cash.
 */
class HotelPettyCashService
{
    public function isCashMethod(?string $method): bool
    {
        $normalized = strtolower(trim((string) $method));

        return $normalized === '' || $normalized === 'cash';
    }

    public function openShiftForUser(int $creatorId, int $userId): ?HotelCashierShift
    {
        if (! Schema::hasTable('hotel_cashier_shifts')) {
            return null;
        }

        return HotelCashierShift::where('created_by', $creatorId)
            ->where('user_id', $userId)
            ->where('status', HotelCashierShift::STATUS_OPEN)
            ->first();
    }

    public function syncExpenseToShift(HotelExpense $expense, int $creatorId, int $userId): void
    {
        if (! Schema::hasColumn('hotel_expenses', 'hotel_cashier_shift_id')) {
            return;
        }

        $previousShiftId = $expense->hotel_cashier_shift_id ? (int) $expense->hotel_cashier_shift_id : null;

        if (! $this->isCashMethod($expense->payment_method)) {
            if ($previousShiftId) {
                $expense->update(['hotel_cashier_shift_id' => null]);
                $this->refreshShiftExpensesTotal($previousShiftId, $creatorId);
            }

            return;
        }

        $openShift = $this->openShiftForUser($creatorId, $userId);
        $newShiftId = $openShift?->id;

        if ((int) $expense->hotel_cashier_shift_id !== (int) $newShiftId) {
            $expense->update(['hotel_cashier_shift_id' => $newShiftId]);
        }

        if ($previousShiftId && $previousShiftId !== (int) $newShiftId) {
            $this->refreshShiftExpensesTotal($previousShiftId, $creatorId);
        }

        if ($newShiftId) {
            $this->refreshShiftExpensesTotal((int) $newShiftId, $creatorId);
        }
    }

    public function detachExpense(HotelExpense $expense, int $creatorId): void
    {
        if (! Schema::hasColumn('hotel_expenses', 'hotel_cashier_shift_id')) {
            return;
        }

        $shiftId = $expense->hotel_cashier_shift_id ? (int) $expense->hotel_cashier_shift_id : null;
        if ($shiftId) {
            $expense->update(['hotel_cashier_shift_id' => null]);
            $this->refreshShiftExpensesTotal($shiftId, $creatorId);
        }
    }

    public function refreshShiftExpensesTotal(int $shiftId, int $creatorId): float
    {
        if (! Schema::hasColumn('hotel_cashier_shifts', 'expenses_total')) {
            return 0.0;
        }

        $total = (float) HotelExpense::where('created_by', $creatorId)
            ->where('hotel_cashier_shift_id', $shiftId)
            ->sum('amount');

        HotelCashierShift::where('id', $shiftId)
            ->where('created_by', $creatorId)
            ->update(['expenses_total' => round($total, 2)]);

        return round($total, 2);
    }

    public function expensesTotalForShift(HotelCashierShift $shift): float
    {
        if (Schema::hasColumn('hotel_cashier_shifts', 'expenses_total')) {
            return round((float) $shift->expenses_total, 2);
        }

        if (! Schema::hasColumn('hotel_expenses', 'hotel_cashier_shift_id')) {
            return 0.0;
        }

        return round((float) HotelExpense::where('hotel_cashier_shift_id', $shift->id)->sum('amount'), 2);
    }

    public function cashExpensesForDate(int $creatorId, string $date): float
    {
        if (! Schema::hasTable('hotel_expenses')) {
            return 0.0;
        }

        $query = HotelExpense::where('created_by', $creatorId)
            ->whereDate('expense_date', $date);

        $total = 0.0;
        foreach ($query->get(['amount', 'payment_method']) as $expense) {
            if ($this->isCashMethod($expense->payment_method)) {
                $total += (float) $expense->amount;
            }
        }

        return round($total, 2);
    }
}
