<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\HotelManagement\Entities\HotelRatePlan;
use Modules\HotelManagement\Services\HotelAuthorization;
use Modules\HotelManagement\Entities\HotelRoomType;

class HotelRatePlanController extends Controller
{
    private function canView(): bool
    {
        return HotelAuthorization::viewRooms(Auth::user());
    }

    private function canManage(): bool
    {
        return HotelAuthorization::manageRooms(Auth::user());
    }

    private function roomTypeOptions()
    {
        return HotelRoomType::where('created_by', Auth::user()->creatorId())
            ->orderBy('name')
            ->pluck('name', 'id');
    }

    public function index()
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $ratePlans = HotelRatePlan::where('created_by', Auth::user()->creatorId())
            ->with('roomType')
            ->orderBy('priority')
            ->orderBy('name')
            ->get();

        return view('hotelmanagement::rate_plans.index', compact('ratePlans'));
    }

    public function create()
    {
        if (! $this->canManage()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        $roomTypes = $this->roomTypeOptions();

        return view('hotelmanagement::rate_plans.create', compact('roomTypes'));
    }

    public function store(Request $request)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'hotel_room_type_id' => 'required|exists:hotel_room_types,id',
            'name' => 'required|string|max:100',
            'rate' => 'nullable|numeric|min:0',
            'weekend_rate' => 'nullable|numeric|min:0',
            'monthly_rate' => 'nullable|numeric|min:0',
            'billing_mode' => 'required|in:nightly,monthly,both',
            'priority' => 'nullable|integer|min:0|max:999',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', $validator->getMessageBag()->first());
        }

        HotelRatePlan::create([
            'hotel_room_type_id' => $request->hotel_room_type_id,
            'name' => $request->name,
            'rate' => $request->rate ?: 0,
            'weekend_rate' => $request->filled('weekend_rate') ? $request->weekend_rate : null,
            'monthly_rate' => $request->monthly_rate ?: 0,
            'billing_mode' => $request->billing_mode,
            'priority' => $request->filled('priority') ? (int) $request->priority : 100,
            'valid_from' => $request->valid_from,
            'valid_to' => $request->valid_to,
            'is_active' => $request->has('is_active'),
            'created_by' => Auth::user()->creatorId(),
        ]);

        return redirect()->route('hotel.rate-plans.index')->with('success', __('Price period created successfully.'));
    }

    public function edit(HotelRatePlan $rate_plan)
    {
        if ((! $this->canManage()) || (int) $rate_plan->created_by !== (int) Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        $roomTypes = $this->roomTypeOptions();

        return view('hotelmanagement::rate_plans.edit', ['ratePlan' => $rate_plan, 'roomTypes' => $roomTypes]);
    }

    public function update(Request $request, HotelRatePlan $rate_plan)
    {
        if ((! $this->canManage()) || (int) $rate_plan->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'hotel_room_type_id' => 'required|exists:hotel_room_types,id',
            'name' => 'required|string|max:100',
            'rate' => 'nullable|numeric|min:0',
            'weekend_rate' => 'nullable|numeric|min:0',
            'monthly_rate' => 'nullable|numeric|min:0',
            'billing_mode' => 'required|in:nightly,monthly,both',
            'priority' => 'nullable|integer|min:0|max:999',
            'valid_from' => 'nullable|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', $validator->getMessageBag()->first());
        }

        $rate_plan->update([
            'hotel_room_type_id' => $request->hotel_room_type_id,
            'name' => $request->name,
            'rate' => $request->rate ?: 0,
            'weekend_rate' => $request->filled('weekend_rate') ? $request->weekend_rate : null,
            'monthly_rate' => $request->monthly_rate ?: 0,
            'billing_mode' => $request->billing_mode,
            'priority' => $request->filled('priority') ? (int) $request->priority : 100,
            'valid_from' => $request->valid_from,
            'valid_to' => $request->valid_to,
            'is_active' => $request->has('is_active'),
        ]);

        return redirect()->route('hotel.rate-plans.index')->with('success', __('Price period updated successfully.'));
    }

    public function destroy(HotelRatePlan $rate_plan)
    {
        if ((! $this->canManage()) || (int) $rate_plan->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $rate_plan->delete();

        return redirect()->route('hotel.rate-plans.index')->with('success', __('Rate plan deleted successfully.'));
    }
}
