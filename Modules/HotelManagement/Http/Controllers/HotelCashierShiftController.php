<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelCashierShift;
use Modules\HotelManagement\Services\HotelAuthorization;
use Modules\HotelManagement\Services\HotelPettyCashService;

class HotelCashierShiftController extends Controller
{
    public function __construct(
        private readonly HotelPettyCashService $pettyCashService,
    ) {
    }

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

        $openShift = $this->pettyCashService->resolveShiftForExpense(
            $creatorId,
            $userId,
            Auth::user()->activeBranchContextId()
        );

        if ($openShift) {
            $openShift->loadMissing('user');
        }

        $openShiftSummary = null;
        if ($openShift) {
            $openShiftSummary = $this->pettyCashService->buildLiveShiftSummary($openShift);
        }

        $canManage = $this->canManage();
        $operationalShiftOptions = HotelCashierShift::operationalShiftOptions();

        return view('hotelmanagement::cashier_shifts.index', compact(
            'shifts',
            'openShift',
            'openShiftSummary',
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

        $shift = HotelCashierShift::create([
            'branch_id' => Auth::user()->activeBranchContextId(),
            'user_id' => $userId,
            'operational_shift' => $request->operational_shift,
            'opened_at' => now(),
            'opening_float' => (float) $request->opening_float,
            'status' => HotelCashierShift::STATUS_OPEN,
            'created_by' => $creatorId,
        ]);

        $linked = $this->pettyCashService->linkOrphanCashExpensesToShift($shift);
        $this->pettyCashService->buildLiveShiftSummary($shift->fresh());

        $message = __('Cashier shift opened.');
        if ($linked > 0) {
            $message = __('Cashier shift opened. :count cash expense(s) linked to petty cash.', ['count' => $linked]);
        }

        return redirect()->route('hotel.cashier-shifts.index')->with('success', $message);
    }

    public function closeForm(HotelCashierShift $shift)
    {
        if (! $this->canView() || ! $this->ownsShift($shift)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ($shift->status !== HotelCashierShift::STATUS_OPEN) {
            return redirect()->route('hotel.cashier-shifts.show', $shift)->with('error', __('Shift is already closed.'));
        }

        $summary = $this->pettyCashService->buildLiveShiftSummary($shift);
        $totals = $this->pettyCashService->collectPaymentTotals($shift);
        $expensesTotal = $summary['expenses_total'];
        $expectedCash = $summary['expected_cash'];
        $remainingPettyCash = $summary['remaining_petty_cash'];
        $canManage = $this->canManage();

        return view('hotelmanagement::cashier_shifts.close', compact(
            'shift',
            'totals',
            'expectedCash',
            'expensesTotal',
            'remainingPettyCash',
            'canManage'
        ));
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

        $summary = $this->pettyCashService->buildLiveShiftSummary($shift, persistOpenShift: false);
        $totals = $this->pettyCashService->collectPaymentTotals($shift);
        $expensesTotal = $summary['expenses_total'];
        $closingCash = (float) $request->closing_cash;
        $expectedCash = $summary['expected_cash'];
        $difference = round($closingCash - $expectedCash, 2);

        $shift->update([
            'closed_at' => now(),
            'closing_cash' => $closingCash,
            'expected_cash' => $expectedCash,
            'expenses_total' => $expensesTotal,
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

        $summary = null;
        if ($shift->status === HotelCashierShift::STATUS_OPEN) {
            $summary = $this->pettyCashService->buildLiveShiftSummary($shift);
            $shift->refresh();
        } else {
            $summary = [
                'expenses_total' => $this->pettyCashService->expensesTotalForShift($shift),
                'cash_collected' => round((float) $shift->cash_collected, 2),
                'card_collected' => round((float) $shift->card_collected, 2),
                'other_collected' => round((float) $shift->other_collected, 2),
                'expected_cash' => round((float) $shift->expected_cash, 2),
                'remaining_petty_cash' => round(max(0, (float) $shift->opening_float - (float) $shift->expenses_total), 2),
            ];
        }

        return view('hotelmanagement::cashier_shifts.show', compact('shift', 'summary'));
    }

    private function ownsShift(HotelCashierShift $shift): bool
    {
        return (int) $shift->created_by === (int) Auth::user()->creatorId();
    }
}
