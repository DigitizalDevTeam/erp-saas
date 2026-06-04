<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\HotelManagement\Entities\HotelFloor;
use Modules\HotelManagement\Services\HotelAuthorization;

class HotelFloorController extends Controller
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

        $floors = HotelFloor::where('created_by', Auth::user()->creatorId())
            ->orderBy('sort_order')
            ->orderBy('level_number')
            ->orderBy('name')
            ->get();

        return view('hotelmanagement::floors.index', compact('floors'));
    }

    public function create()
    {
        if (! $this->canManage()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        return view('hotelmanagement::floors.create');
    }

    public function store(Request $request)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'level_number' => 'nullable|integer|min:-10|max:200',
            'sort_order' => 'nullable|integer|min:0|max:1000',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', $validator->getMessageBag()->first());
        }

        HotelFloor::create([
            'name' => $request->name,
            'level_number' => $request->filled('level_number') ? (int) $request->level_number : null,
            'sort_order' => $request->filled('sort_order') ? (int) $request->sort_order : 0,
            'is_active' => $request->has('is_active'),
            'created_by' => Auth::user()->creatorId(),
        ]);

        return redirect()->route('hotel.floors.index')->with('success', __('Floor created successfully.'));
    }

    public function edit(HotelFloor $floor)
    {
        if ((! $this->canManage()) || (int) $floor->created_by !== (int) Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        return view('hotelmanagement::floors.edit', compact('floor'));
    }

    public function update(Request $request, HotelFloor $floor)
    {
        if ((! $this->canManage()) || (int) $floor->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'level_number' => 'nullable|integer|min:-10|max:200',
            'sort_order' => 'nullable|integer|min:0|max:1000',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', $validator->getMessageBag()->first());
        }

        $floor->update([
            'name' => $request->name,
            'level_number' => $request->filled('level_number') ? (int) $request->level_number : null,
            'sort_order' => $request->filled('sort_order') ? (int) $request->sort_order : 0,
            'is_active' => $request->has('is_active'),
        ]);

        return redirect()->route('hotel.floors.index')->with('success', __('Floor updated successfully.'));
    }

    public function destroy(HotelFloor $floor)
    {
        if ((! $this->canManage()) || (int) $floor->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ($floor->rooms()->exists()) {
            return redirect()->back()->with('error', __('Floor is assigned to rooms and cannot be deleted.'));
        }

        $floor->delete();

        return redirect()->route('hotel.floors.index')->with('success', __('Floor deleted successfully.'));
    }
}
