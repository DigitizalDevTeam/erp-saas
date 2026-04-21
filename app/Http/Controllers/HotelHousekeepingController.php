<?php

namespace App\Http\Controllers;

use App\Models\HotelHousekeepingTask;
use App\Models\HotelRoom;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HotelHousekeepingController extends Controller
{
    public function index()
    {
        if (!Auth::user()->can('manage hotel frontdesk') && !Auth::user()->can('manage hotel')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $tasks = HotelHousekeepingTask::where('created_by', Auth::user()->creatorId())
            ->with(['room', 'assignee'])
            ->orderByRaw("FIELD(status, 'pending', 'in_progress', 'done')")
            ->orderByDesc('id')
            ->get();
        return view('hotel.housekeeping.index', compact('tasks'));
    }

    public function create()
    {
        if (!Auth::user()->can('manage hotel frontdesk') && !Auth::user()->can('manage hotel')) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
        $rooms = HotelRoom::where('created_by', Auth::user()->creatorId())->orderBy('room_no')->pluck('room_no', 'id');
        $staff = User::where('created_by', Auth::user()->creatorId())->orderBy('name')->pluck('name', 'id');
        return view('hotel.housekeeping.create', compact('rooms', 'staff'));
    }

    public function store(Request $request)
    {
        if (!Auth::user()->can('manage hotel frontdesk') && !Auth::user()->can('manage hotel')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $validator = \Validator::make($request->all(), [
            'hotel_room_id' => 'required|exists:hotel_rooms,id',
            'assigned_to' => 'nullable|exists:users,id',
            'status' => 'required|in:pending,in_progress,done',
            'notes' => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        HotelHousekeepingTask::create([
            'hotel_room_id' => $request->hotel_room_id,
            'assigned_to' => $request->assigned_to ?: null,
            'status' => $request->status,
            'notes' => $request->notes,
            'created_by' => Auth::user()->creatorId(),
        ]);

        HotelRoom::where('id', $request->hotel_room_id)
            ->where('created_by', Auth::user()->creatorId())
            ->update(['status' => $request->status === 'done' ? HotelRoom::STATUS_AVAILABLE : HotelRoom::STATUS_CLEANING]);

        return redirect()->route('hotel.housekeeping.index')->with('success', __('Housekeeping task created.'));
    }

    public function edit(HotelHousekeepingTask $housekeeping)
    {
        if ((!Auth::user()->can('manage hotel frontdesk') && !Auth::user()->can('manage hotel')) || (int) $housekeeping->created_by !== (int) Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
        $rooms = HotelRoom::where('created_by', Auth::user()->creatorId())->orderBy('room_no')->pluck('room_no', 'id');
        $staff = User::where('created_by', Auth::user()->creatorId())->orderBy('name')->pluck('name', 'id');
        return view('hotel.housekeeping.edit', compact('housekeeping', 'rooms', 'staff'));
    }

    public function update(Request $request, HotelHousekeepingTask $housekeeping)
    {
        if ((!Auth::user()->can('manage hotel frontdesk') && !Auth::user()->can('manage hotel')) || (int) $housekeeping->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $validator = \Validator::make($request->all(), [
            'hotel_room_id' => 'required|exists:hotel_rooms,id',
            'assigned_to' => 'nullable|exists:users,id',
            'status' => 'required|in:pending,in_progress,done',
            'notes' => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }
        $housekeeping->update([
            'hotel_room_id' => $request->hotel_room_id,
            'assigned_to' => $request->assigned_to ?: null,
            'status' => $request->status,
            'notes' => $request->notes,
        ]);
        HotelRoom::where('id', $request->hotel_room_id)
            ->where('created_by', Auth::user()->creatorId())
            ->update(['status' => $request->status === 'done' ? HotelRoom::STATUS_AVAILABLE : HotelRoom::STATUS_CLEANING]);
        return redirect()->route('hotel.housekeeping.index')->with('success', __('Housekeeping task updated.'));
    }
}
