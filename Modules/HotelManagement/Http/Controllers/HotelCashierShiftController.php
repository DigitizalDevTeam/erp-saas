<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelCashierShift;
use Modules\HotelManagement\Entities\HotelFolioPayment;
use Modules\HotelManagement\Services\HotelAuthorization;

class HotelCashierShiftController extends Controller
{
    private function canView(): bool
    {
        return HotelAuthorization::viewFrontdesk(Auth::user());
    }

    private function canManage(): bool
    {
        return HotelAuthorization::manageFrontdesk(Auth::user());
    }

    public function index()
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! Schema::hasTable('hotel_cashier_shifts')) {
            return redirect()->back()->with('error', __('Run migrations to enable cashier shifts.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $userId = (int) Auth::id();

        $shifts = HotelCashierShift::where('created_by', $creatorId)
            ->with('user')
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $openShift = HotelCashierShift::where('created_by', $creatorId)
            ->where('user_id', $userId)
            ->where('status', HotelCashierShift::STATUS_OPEN)
            ->first();

        $canManage = $this->canManage();
        $operationalShiftOptions = HotelCashierShift::operationalShiftOptions();

        return view('hotelmanagement::cashier_shifts.index', compact(
            'shifts',
            'openShift',
            'canManage',
            'operationalShiftOptions'
        ));
    }

    public function open(Request $request)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! Schema::hasTable('hotel_cashier_shifts')) {
            return redirect()->back()->with('error', __('Run migrations to enable cashier shifts.'));
        }

        $validator = \Validator::make($request->all(), [
            'opening_float' => 'required|numeric|min:0',
            'operational_shift' => 'nullable|in:morning,afternoon,night',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', $validator->getMessageBag()->first());
        }

        $creatorId = (int) Auth::user()->creatorId();
        $userId = (int) Auth::id();

        $existing = HotelCashierShift::where('created_by', $creatorId)
            ->where('user_id', $userId)
            ->where('status', HotelCashierShift::STATUS_OPEN)
            ->exists();

        if ($existing) {
            return redirect()->back()->with('error', __('You already have an open cashier shift.'));
        }

        HotelCashierShift::create([
            'branch_id' => Auth::user()->activeBranchContextId(),
            'user_id' => $userId,
            'operational_shift' => $request->operational_shift,
            'opened_at' => now(),
            'opening_float' => (float) $request->opening_float,
            'status' => HotelCashierShift::STATUS_OPEN,
            'created_by' => $creatorId,
        ]);

        return redirect()->route('hotel.cashier-shifts.index')->with('success', __('Cashier shift opened.'));
    }

    public function closeForm(HotelCashierShift $shift)
    {
        if (! $this->canView() || ! $this->ownsShift($shift)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ($shift->status !== HotelCashierShift::STATUS_OPEN) {
            return redirect()->route('hotel.cashier-shifts.show', $shift)->with('error', __('Shift is already closed.'));
        }

        $totals = $this->collectPaymentTotals($shift);
        $expectedCash = round((float) $shift->opening_float + $totals['cash'], 2);
        $canManage = $this->canManage();

        return view('hotelmanagement::cashier_shifts.close', compact('shift', 'totals', 'expectedCash', 'canManage'));
    }

    public function close(Request $request, HotelCashierShift $shift)
    {
        if (! $this->canManage() || ! $this->ownsShift($shift)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ($shift->status !== HotelCashierShift::STATUS_OPEN) {
            return redirect()->route('hotel.cashier-shifts.show', $shift)->with('error', __('Shift is already closed.'));
        }

        $validator = \Validator::make($request->all(), [
            'closing_cash' => 'required|numeric|min:0',
            'cash_handover' => 'nullable|numeric|min:0',
            'handed_to' => 'nullable|string|max:191',
            'notes' => 'nullable|string|max:2000',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', $validator->getMessageBag()->first());
        }

        $totals = $this->collectPaymentTotals($shift);
        $closingCash = (float) $request->closing_cash;
        $expectedCash = round((float) $shift->opening_float + $totals['cash'], 2);
        $difference = round($closingCash - $expectedCash, 2);

        $shift->update([
            'closed_at' => now(),
            'closing_cash' => $closingCash,
            'expected_cash' => $expectedCash,
            'cash_collected' => $totals['cash'],
            'card_collected' => $totals['card'],
            'other_collected' => $totals['other'],
            'difference' => $difference,
            'cash_handover' => $request->filled('cash_handover') ? (float) $request->cash_handover : null,
            'handed_to' => $request->handed_to,
            'notes' => $request->notes,
            'status' => HotelCashierShift::STATUS_CLOSED,
            'closed_by' => Auth::id(),
        ]);

        return redirect()->route('hotel.cashier-shifts.show', $shift)->with('success', __('Cashier shift closed.'));
    }

    public function show(HotelCashierShift $shift)
    {
        if (! $this->canView() || ! $this->ownsShift($shift)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $shift->load(['user', 'closedByUser']);

        return view('hotelmanagement::cashier_shifts.show', compact('shift'));
    }

    private function ownsShift(HotelCashierShift $shift): bool
    {
        return (int) $shift->created_by === (int) Auth::user()->creatorId();
    }

    /**
     * @return array{cash: float, card: float, other: float, total: float}
     */
    private function collectPaymentTotals(HotelCashierShift $shift): array
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
            $method = strtolower(trim((string) $payment->method));
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
}
