<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\HotelManagement\Entities\HotelRoomType;
use Modules\HotelManagement\Services\HotelAuthorization;

class HotelRoomTypeController extends Controller
{
    private function canView(): bool
    {
        return HotelAuthorization::viewRooms(Auth::user());
    }

    private function canManage(): bool
    {
        return HotelAuthorization::manageRooms(Auth::user());
    }

    public function index()
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $roomTypes = HotelRoomType::where('created_by', Auth::user()->creatorId())
            ->orderBy('name')
            ->get();

        return view('hotelmanagement::room_types.index', compact('roomTypes'));
    }

    public function create()
    {
        if (! $this->canManage()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        return view('hotelmanagement::room_types.create');
    }

    public function store(Request $request)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'unit_category' => 'required|in:room,apartment',
            'nightly_rate' => 'nullable|numeric|min:0',
            'monthly_rate' => 'nullable|numeric|min:0',
            'default_billing_mode' => 'required|in:nightly,monthly',
            'max_occupancy' => 'required|integer|min:1',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        HotelRoomType::create([
            'name' => $request->name,
            'description' => $request->description,
            'unit_category' => $request->unit_category,
            'base_price' => $request->nightly_rate ?: 0,
            'nightly_rate' => $request->nightly_rate ?: 0,
            'monthly_rate' => $request->monthly_rate ?: 0,
            'default_billing_mode' => $request->default_billing_mode,
            'max_occupancy' => $request->max_occupancy,
            'is_active' => $request->has('is_active'),
            'created_by' => Auth::user()->creatorId(),
        ]);

        return redirect()->route('hotel.room-types.index')->with('success', __('Room type created successfully.'));
    }

    public function edit(HotelRoomType $room_type)
    {
        if ((! $this->canManage()) || (int) $room_type->created_by !== (int) Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        return view('hotelmanagement::room_types.edit', ['roomType' => $room_type]);
    }

    public function update(Request $request, HotelRoomType $room_type)
    {
        if ((! $this->canManage()) || (int) $room_type->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'unit_category' => 'required|in:room,apartment',
            'nightly_rate' => 'nullable|numeric|min:0',
            'monthly_rate' => 'nullable|numeric|min:0',
            'default_billing_mode' => 'required|in:nightly,monthly',
            'max_occupancy' => 'required|integer|min:1',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        $room_type->update([
            'name' => $request->name,
            'description' => $request->description,
            'unit_category' => $request->unit_category,
            'base_price' => $request->nightly_rate ?: 0,
            'nightly_rate' => $request->nightly_rate ?: 0,
            'monthly_rate' => $request->monthly_rate ?: 0,
            'default_billing_mode' => $request->default_billing_mode,
            'max_occupancy' => $request->max_occupancy,
            'is_active' => $request->has('is_active'),
        ]);

        return redirect()->route('hotel.room-types.index')->with('success', __('Room type updated successfully.'));
    }

    public function destroy(HotelRoomType $room_type)
    {
        if ((! $this->canManage()) || (int) $room_type->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ($room_type->rooms()->exists()) {
            return redirect()->back()->with('error', __('Room type is in use by rooms and cannot be deleted.'));
        }

        $room_type->delete();
        return redirect()->route('hotel.room-types.index')->with('success', __('Room type deleted successfully.'));
    }
}
