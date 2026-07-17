<?php

namespace Modules\HotelManagement\Services;

use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelCashierShift;
use Modules\HotelManagement\Entities\HotelExpense;
use Modules\HotelManagement\Entities\HotelFolioPayment;

/**
 * Hotel petty cash = open cashier shift float. Cash expenses deduct from expected cash.
 */
class HotelPettyCashService
{
    public function isCashMethod(?string $method): bool
    {
        $normalized = HotelFolioPaymentService::normalizeMethod(strtolower(trim((string) $method)));

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

    public function openShiftForCompany(int $creatorId, ?int $branchId = null): ?HotelCashierShift
    {
        if (! Schema::hasTable('hotel_cashier_shifts')) {
            return null;
        }

        $query = HotelCashierShift::where('created_by', $creatorId)
            ->where('status', HotelCashierShift::STATUS_OPEN);

        if ($branchId && Schema::hasColumn('hotel_cashier_shifts', 'branch_id')) {
            $query->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            });
        }

        return $query->orderByDesc('opened_at')->orderByDesc('id')->first();
    }

    public function resolveShiftForExpense(int $creatorId, int $userId, ?int $branchId = null): ?HotelCashierShift
    {
        return $this->openShiftForUser($creatorId, $userId)
            ?? $this->openShiftForCompany($creatorId, $branchId);
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
                $this->refreshShiftLiveTotals($previousShiftId, $creatorId);
            }

            return;
        }

        $openShift = $this->resolveShiftForExpense(
            $creatorId,
            $userId,
            $expense->branch_id ? (int) $expense->branch_id : null
        );
        $newShiftId = $openShift?->id;

        if ((int) $expense->hotel_cashier_shift_id !== (int) $newShiftId) {
            $expense->update(['hotel_cashier_shift_id' => $newShiftId]);
        }

        if ($previousShiftId && $previousShiftId !== (int) $newShiftId) {
            $this->refreshShiftLiveTotals($previousShiftId, $creatorId);
        }

        if ($newShiftId) {
            $this->refreshShiftLiveTotals((int) $newShiftId, $creatorId);
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
            $this->refreshShiftLiveTotals($shiftId, $creatorId);
        }
    }

    /**
     * Attach same-day cash expenses that were recorded before a shift was opened.
     */
    public function linkOrphanCashExpensesToShift(HotelCashierShift $shift): int
    {
        if (! Schema::hasColumn('hotel_expenses', 'hotel_cashier_shift_id') || ! $shift->opened_at) {
            return 0;
        }

        $creatorId = (int) $shift->created_by;
        $shiftDate = $shift->opened_at->toDateString();
        $linked = 0;

        $query = HotelExpense::where('created_by', $creatorId)
            ->whereNull('hotel_cashier_shift_id')
            ->whereDate('expense_date', $shiftDate);

        if ($shift->branch_id && Schema::hasColumn('hotel_expenses', 'branch_id')) {
            $query->where(function ($q) use ($shift) {
                $q->where('branch_id', $shift->branch_id)->orWhereNull('branch_id');
            });
        }

        foreach ($query->get() as $expense) {
            if (! $this->isCashMethod($expense->payment_method)) {
                continue;
            }

            $expense->update(['hotel_cashier_shift_id' => $shift->id]);
            $linked++;
        }

        if ($linked > 0) {
            $this->refreshShiftLiveTotals((int) $shift->id, $creatorId);
        }

        return $linked;
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

    /**
     * @return array{cash: float, card: float, other: float, total: float}
     */
    public function collectPaymentTotals(HotelCashierShift $shift): array
    {
        $cash = 0.0;
        $card = 0.0;
        $other = 0.0;

        if (! Schema::hasTable('hotel_folio_payments')) {
            return ['cash' => 0.0, 'card' => 0.0, 'other' => 0.0, 'total' => 0.0];
        }

        $creatorId = (int) $shift->created_by;
        $userId = (int) $shift->user_id;
        $openedAt = $shift->opened_at;
        $endedAt = $shift->closed_at ?? now();

        $query = HotelFolioPayment::query()
            ->where('created_by', $creatorId)
            ->where('created_at', '>=', $openedAt)
            ->where('created_at', '<=', $endedAt);

        if (Schema::hasColumn('hotel_folio_payments', 'recorded_by')) {
            $query->where('recorded_by', $userId);
        }

        foreach ($query->get(['amount', 'method']) as $payment) {
            $amount = (float) $payment->amount;
            $method = HotelFolioPaymentService::normalizeMethod(strtolower(trim((string) $payment->method)));
            if ($method === 'cash') {
                $cash += $amount;
            } elseif ($method === 'card') {
                $card += $amount;
            } else {
                $other += $amount;
            }
        }

        return [
            'cash' => round($cash, 2),
            'card' => round($card, 2),
            'other' => round($other, 2),
            'total' => round($cash + $card + $other, 2),
        ];
    }

    /**
     * @return array{
     *     expenses_total: float,
     *     cash_collected: float,
     *     card_collected: float,
     *     other_collected: float,
     *     expected_cash: float,
     *     remaining_petty_cash: float
     * }
     */
    public function buildLiveShiftSummary(HotelCashierShift $shift, bool $persistOpenShift = true): array
    {
        $totals = $this->collectPaymentTotals($shift);
        $expensesTotal = $this->refreshShiftExpensesTotal((int) $shift->id, (int) $shift->created_by);
        $openingFloat = round((float) $shift->opening_float, 2);
        $expectedCash = round($openingFloat + $totals['cash'] - $expensesTotal, 2);
        $remainingPettyCash = round(max(0, $openingFloat - $expensesTotal), 2);

        if ($persistOpenShift && $shift->status === HotelCashierShift::STATUS_OPEN) {
            HotelCashierShift::where('id', $shift->id)
                ->where('created_by', $shift->created_by)
                ->update([
                    'cash_collected' => $totals['cash'],
                    'card_collected' => $totals['card'],
                    'other_collected' => $totals['other'],
                    'expected_cash' => $expectedCash,
                    'expenses_total' => $expensesTotal,
                ]);
            $shift->refresh();
        }

        return [
            'expenses_total' => $expensesTotal,
            'cash_collected' => $totals['cash'],
            'card_collected' => $totals['card'],
            'other_collected' => $totals['other'],
            'expected_cash' => $expectedCash,
            'remaining_petty_cash' => $remainingPettyCash,
        ];
    }

    public function refreshShiftLiveTotals(int $shiftId, int $creatorId): array
    {
        $shift = HotelCashierShift::where('id', $shiftId)
            ->where('created_by', $creatorId)
            ->first();

        if (! $shift) {
            return [
                'expenses_total' => 0.0,
                'cash_collected' => 0.0,
                'card_collected' => 0.0,
                'other_collected' => 0.0,
                'expected_cash' => 0.0,
                'remaining_petty_cash' => 0.0,
            ];
        }

        return $this->buildLiveShiftSummary($shift);
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

    public function openingFloatForDate(int $creatorId, string $date): float
    {
        if (! Schema::hasTable('hotel_cashier_shifts')) {
            return 0.0;
        }

        return round((float) HotelCashierShift::where('created_by', $creatorId)
            ->where(function ($q) use ($date) {
                $q->whereDate('opened_at', $date)
                    ->orWhereDate('closed_at', $date);
            })
            ->sum('opening_float'), 2);
    }
}
