<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Entities\HotelRoomType;
use Modules\HotelManagement\Http\Controllers\Concerns\BuildsRoomMap;

class HotelRoomController extends Controller
{
    use BuildsRoomMap;

    protected function roomTypeOptions()
    {
        $creatorId = Auth::user()->creatorId();
        if (!HotelRoomType::where('created_by', $creatorId)->exists()) {
            HotelRoomType::create([
                'name' => 'Standard',
                'base_price' => 0,
                'max_occupancy' => 2,
                'is_active' => true,
                'created_by' => $creatorId,
            ]);
        }
        return HotelRoomType::where('created_by', $creatorId)->where('is_active', true)->pluck('name', 'id');
    }

    public function index()
    {
        if (!Auth::user()->can('manage hotel room') && !Auth::user()->can('manage hotel')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $rooms = HotelRoom::where('created_by', Auth::user()->creatorId())
            ->with('roomType')
            ->orderBy('room_no')
            ->get();
        return view('hotelmanagement::rooms.index', compact('rooms'));
    }

    public function map()
    {
        if (!Auth::user()->can('manage hotel room') && !Auth::user()->can('manage hotel')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $rooms = $this->roomsWithCurrentReservation((int) Auth::user()->creatorId());
        return view('hotelmanagement::rooms.map', compact('rooms'));
    }

    public function create()
    {
        if (!Auth::user()->can('manage hotel room') && !Auth::user()->can('manage hotel')) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
        $roomTypes = $this->roomTypeOptions();
        return view('hotelmanagement::rooms.create', compact('roomTypes'));
    }

    public function store(Request $request)
    {
        if (!Auth::user()->can('manage hotel room') && !Auth::user()->can('manage hotel')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $validator = \Validator::make($request->all(), [
            'room_no' => 'required|string|max:50',
            'hotel_room_type_id' => 'required|exists:hotel_room_types,id',
            'status' => 'required|in:available,occupied,cleaning,maintenance',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }
        HotelRoom::create([
            'hotel_room_type_id' => $request->hotel_room_type_id,
            'branch_id' => Auth::user()->activeBranchContextId(),
            'room_no' => $request->room_no,
            'override_price' => $request->override_price ?: null,
            'status' => $request->status,
            'notes' => $request->notes,
            'created_by' => Auth::user()->creatorId(),
        ]);
        return redirect()->route('hotel.rooms.index')->with('success', __('Room created successfully.'));
    }

    public function edit(HotelRoom $room)
    {
        if ((!Auth::user()->can('manage hotel room') && !Auth::user()->can('manage hotel')) || (int) $room->created_by !== (int) Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
        $roomTypes = $this->roomTypeOptions();
        return view('hotelmanagement::rooms.edit', compact('room', 'roomTypes'));
    }

    public function update(Request $request, HotelRoom $room)
    {
        if ((!Auth::user()->can('manage hotel room') && !Auth::user()->can('manage hotel')) || (int) $room->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $validator = \Validator::make($request->all(), [
            'room_no' => 'required|string|max:50',
            'hotel_room_type_id' => 'required|exists:hotel_room_types,id',
            'status' => 'required|in:available,occupied,cleaning,maintenance',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }
        $room->update([
            'hotel_room_type_id' => $request->hotel_room_type_id,
            'room_no' => $request->room_no,
            'override_price' => $request->override_price ?: null,
            'status' => $request->status,
            'notes' => $request->notes,
        ]);
        return redirect()->route('hotel.rooms.index')->with('success', __('Room updated successfully.'));
    }

    public function destroy(HotelRoom $room)
    {
        if ((!Auth::user()->can('manage hotel room') && !Auth::user()->can('manage hotel')) || (int) $room->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $room->delete();
        return redirect()->route('hotel.rooms.index')->with('success', __('Room deleted successfully.'));
    }
}
