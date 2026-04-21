<?php

namespace App\Http\Controllers;

use App\Models\HotelRoomType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HotelRoomTypeController extends Controller
{
    public function index()
    {
        if (!Auth::user()->can('manage hotel room') && !Auth::user()->can('manage hotel')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $roomTypes = HotelRoomType::where('created_by', Auth::user()->creatorId())
            ->orderBy('name')
            ->get();

        return view('hotel.room_types.index', compact('roomTypes'));
    }

    public function create()
    {
        if (!Auth::user()->can('manage hotel room') && !Auth::user()->can('manage hotel')) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        return view('hotel.room_types.create');
    }

    public function store(Request $request)
    {
        if (!Auth::user()->can('manage hotel room') && !Auth::user()->can('manage hotel')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'base_price' => 'nullable|numeric|min:0',
            'max_occupancy' => 'required|integer|min:1',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        HotelRoomType::create([
            'name' => $request->name,
            'description' => $request->description,
            'base_price' => $request->base_price ?: 0,
            'max_occupancy' => $request->max_occupancy,
            'is_active' => $request->has('is_active'),
            'created_by' => Auth::user()->creatorId(),
        ]);

        return redirect()->route('hotel.room-types.index')->with('success', __('Room type created successfully.'));
    }

    public function edit(HotelRoomType $room_type)
    {
        if ((!Auth::user()->can('manage hotel room') && !Auth::user()->can('manage hotel')) || (int) $room_type->created_by !== (int) Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        return view('hotel.room_types.edit', ['roomType' => $room_type]);
    }

    public function update(Request $request, HotelRoomType $room_type)
    {
        if ((!Auth::user()->can('manage hotel room') && !Auth::user()->can('manage hotel')) || (int) $room_type->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'base_price' => 'nullable|numeric|min:0',
            'max_occupancy' => 'required|integer|min:1',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        $room_type->update([
            'name' => $request->name,
            'description' => $request->description,
            'base_price' => $request->base_price ?: 0,
            'max_occupancy' => $request->max_occupancy,
            'is_active' => $request->has('is_active'),
        ]);

        return redirect()->route('hotel.room-types.index')->with('success', __('Room type updated successfully.'));
    }

    public function destroy(HotelRoomType $room_type)
    {
        if ((!Auth::user()->can('manage hotel room') && !Auth::user()->can('manage hotel')) || (int) $room_type->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ($room_type->rooms()->exists()) {
            return redirect()->back()->with('error', __('Room type is in use by rooms and cannot be deleted.'));
        }

        $room_type->delete();
        return redirect()->route('hotel.room-types.index')->with('success', __('Room type deleted successfully.'));
    }
}
