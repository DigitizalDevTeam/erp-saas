<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Modules\HotelManagement\Entities\HotelFloor;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Entities\HotelRoomType;
use Modules\HotelManagement\Http\Controllers\Concerns\BuildsRoomMap;
use Modules\HotelManagement\Services\HotelAuthorization;
use Modules\HotelManagement\Services\HotelRoomStatusService;

class HotelRoomController extends Controller
{
    use BuildsRoomMap;

    public function __construct(private readonly HotelRoomStatusService $roomStatusService)
    {
    }

    private function canView(): bool
    {
        return HotelAuthorization::viewRooms(Auth::user());
    }

    private function canManage(): bool
    {
        return HotelAuthorization::manageRooms(Auth::user());
    }

    protected function roomTypeOptions()
    {
        $creatorId = Auth::user()->creatorId();
        if (!HotelRoomType::where('created_by', $creatorId)->exists()) {
            HotelRoomType::create([
                'name' => 'Standard',
                'unit_category' => 'room',
                'base_price' => 0,
                'nightly_rate' => 0,
                'monthly_rate' => 0,
                'default_billing_mode' => 'nightly',
                'max_occupancy' => 2,
                'is_active' => true,
                'created_by' => $creatorId,
            ]);
        }
        return HotelRoomType::where('created_by', $creatorId)->where('is_active', true)->pluck('name', 'id');
    }

    protected function floorOptions()
    {
        return HotelFloor::where('created_by', Auth::user()->creatorId())
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('level_number')
            ->pluck('name', 'id');
    }

    public function index()
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $rooms = HotelRoom::where('created_by', Auth::user()->creatorId())
            ->with(['roomType', 'floor'])
            ->orderBy('room_no')
            ->get();
        return view('hotelmanagement::rooms.index', compact('rooms'));
    }

    public function map()
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $rooms = $this->roomsWithCurrentReservation((int) Auth::user()->creatorId());
        $canManage = $this->canManage();
        $statusLabels = $this->roomStatusService->statusLabels();

        return view('hotelmanagement::rooms.map', compact('rooms', 'canManage', 'statusLabels'));
    }

    public function bulkStatus(Request $request)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'room_ids' => 'required|array|min:1',
            'room_ids.*' => 'integer|exists:hotel_rooms,id',
            'status' => 'required|in:available,occupied,dirty,cleaning,maintenance',
        ]);

        $creatorId = (int) Auth::user()->creatorId();
        $result = $this->roomStatusService->bulkUpdate(
            array_map('intval', $request->room_ids),
            $request->status,
            $creatorId
        );

        if ($result['updated'] === 0) {
            $firstError = reset($result['errors']) ?: __('No rooms were updated.');

            return redirect()->back()->with('error', $firstError);
        }

        $message = __(':count room(s) updated.', ['count' => $result['updated']]);
        if ($result['errors'] !== []) {
            $message .= ' ' . __('Some rooms could not be updated: :details', [
                'details' => implode('; ', array_slice($result['errors'], 0, 3)),
            ]);
        }

        return redirect()->route('hotel.rooms.map')->with('success', $message);
    }

    public function markReady(HotelRoom $room)
    {
        if ((! $this->canManage()) || (int) $room->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        try {
            $this->roomStatusService->markRoomReady($room, (int) Auth::user()->creatorId());
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->back()->with('success', __('Room :room is ready for the next guest.', ['room' => $room->room_no]));
    }

    public function create()
    {
        if (! $this->canManage()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
        $roomTypes = $this->roomTypeOptions();
        $floors = $this->floorOptions();
        return view('hotelmanagement::rooms.create', compact('roomTypes', 'floors'));
    }

    public function store(Request $request)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $request->merge(['room_no' => trim((string) $request->input('room_no', ''))]);
        $creatorId = (int) Auth::user()->creatorId();
        $validator = \Validator::make($request->all(), [
            'room_no' => [
                'required',
                'string',
                'max:50',
                Rule::unique('hotel_rooms', 'room_no')->where(fn ($q) => $q->where('created_by', $creatorId)),
            ],
            'hotel_room_type_id' => 'required|exists:hotel_room_types,id',
            'hotel_floor_id' => 'nullable|exists:hotel_floors,id',
            'status' => 'required|in:available,occupied,dirty,cleaning,maintenance',
            'override_price' => 'nullable|numeric|min:0',
            'monthly_override_price' => 'nullable|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }
        HotelRoom::create([
            'hotel_room_type_id' => $request->hotel_room_type_id,
            'hotel_floor_id' => $request->hotel_floor_id ?: null,
            'branch_id' => Auth::user()->activeBranchContextId(),
            'room_no' => $request->room_no,
            'override_price' => $request->override_price ?: null,
            'monthly_override_price' => $request->monthly_override_price ?: null,
            'status' => $request->status,
            'notes' => $request->notes,
            'created_by' => Auth::user()->creatorId(),
        ]);
        return redirect()->route('hotel.rooms.index')->with('success', __('Room created successfully.'));
    }

    public function edit(HotelRoom $room)
    {
        if ((! $this->canManage()) || (int) $room->created_by !== (int) Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
        $roomTypes = $this->roomTypeOptions();
        $floors = $this->floorOptions();
        return view('hotelmanagement::rooms.edit', compact('room', 'roomTypes', 'floors'));
    }

    public function update(Request $request, HotelRoom $room)
    {
        if ((! $this->canManage()) || (int) $room->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $request->merge(['room_no' => trim((string) $request->input('room_no', ''))]);
        $creatorId = (int) Auth::user()->creatorId();
        $validator = \Validator::make($request->all(), [
            'room_no' => [
                'required',
                'string',
                'max:50',
                Rule::unique('hotel_rooms', 'room_no')
                    ->where(fn ($q) => $q->where('created_by', $creatorId))
                    ->ignore($room),
            ],
            'hotel_room_type_id' => 'required|exists:hotel_room_types,id',
            'hotel_floor_id' => 'nullable|exists:hotel_floors,id',
            'status' => 'required|in:available,occupied,dirty,cleaning,maintenance',
            'override_price' => 'nullable|numeric|min:0',
            'monthly_override_price' => 'nullable|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }
        if ($request->status !== $room->status) {
            try {
                $this->roomStatusService->transition($room, $request->status, $creatorId, $request->notes);
            } catch (\InvalidArgumentException $e) {
                return redirect()->back()->withInput()->with('error', $e->getMessage());
            }
        }

        $room->update([
            'hotel_room_type_id' => $request->hotel_room_type_id,
            'hotel_floor_id' => $request->hotel_floor_id ?: null,
            'room_no' => $request->room_no,
            'override_price' => $request->override_price ?: null,
            'monthly_override_price' => $request->monthly_override_price ?: null,
            'notes' => $request->notes,
        ]);

        return redirect()->route('hotel.rooms.index')->with('success', __('Room updated successfully.'));
    }

    public function destroy(HotelRoom $room)
    {
        if ((! $this->canManage()) || (int) $room->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $room->delete();
        return redirect()->route('hotel.rooms.index')->with('success', __('Room deleted successfully.'));
    }
}
