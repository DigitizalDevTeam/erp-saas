<?php

namespace App\Http\Controllers;

use App\Models\HotelGuest;
use App\Models\HotelReservation;
use App\Models\HotelRoom;
use App\Models\HotelRoomType;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HotelReservationController extends Controller
{
    protected function reservationDateTimeFromInput(?string $value, ?string $fallbackDate, string $fallbackTime): ?Carbon
    {
        if (!empty($value)) {
            return Carbon::parse($value);
        }
        if (!empty($fallbackDate)) {
            return Carbon::parse($fallbackDate . ' ' . $fallbackTime);
        }
        return null;
    }

    protected function roomHasConflict(int $roomId, Carbon $checkInAt, Carbon $checkOutAt, ?int $ignoreReservationId = null): bool
    {
        return HotelReservation::where('created_by', Auth::user()->creatorId())
            ->where('hotel_room_id', $roomId)
            ->whereIn('status', [HotelReservation::STATUS_PENDING, HotelReservation::STATUS_CONFIRMED, HotelReservation::STATUS_CHECKED_IN])
            ->when($ignoreReservationId, fn ($q) => $q->where('id', '!=', $ignoreReservationId))
            ->whereRaw(
                "COALESCE(check_in_at, CONCAT(check_in_date, ' 00:00:00')) < ? AND COALESCE(check_out_at, CONCAT(check_out_date, ' 23:59:59')) > ?",
                [$checkOutAt->format('Y-m-d H:i:s'), $checkInAt->format('Y-m-d H:i:s')]
            )
            ->exists();
    }

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
        if (!Auth::user()->can('manage hotel reservation') && !Auth::user()->can('manage hotel')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $reservations = HotelReservation::where('created_by', Auth::user()->creatorId())
            ->with(['guest', 'room', 'roomType'])
            ->orderByDesc('id')
            ->get();
        return view('hotel.reservations.index', compact('reservations'));
    }

    public function create()
    {
        if (!Auth::user()->can('manage hotel reservation') && !Auth::user()->can('manage hotel')) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
        $roomTypes = $this->roomTypeOptions();
        $rooms = HotelRoom::where('created_by', Auth::user()->creatorId())
            ->orderBy('room_no')
            ->get(['id', 'room_no', 'hotel_room_type_id']);
        $roomsByType = $rooms->groupBy('hotel_room_type_id')
            ->map(fn ($group) => $group->map(fn ($room) => ['id' => $room->id, 'room_no' => $room->room_no])->values())
            ->toArray();
        $rooms = $rooms->pluck('room_no', 'id');
        return view('hotel.reservations.create', compact('roomTypes', 'rooms', 'roomsByType'));
    }

    public function store(Request $request)
    {
        if (!Auth::user()->can('manage hotel reservation') && !Auth::user()->can('manage hotel')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $validator = \Validator::make($request->all(), [
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'check_in_at' => 'required|date',
            'check_out_at' => 'required|date|after:check_in_at',
            'hotel_room_type_id' => 'required|exists:hotel_room_types,id',
            'hotel_room_id' => 'nullable|exists:hotel_rooms,id',
            'source' => 'required|in:manual,walkin,online,ota',
            'status' => 'required|in:pending,confirmed,checked_in,checked_out,cancelled',
            'rate_per_night' => 'required|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        $checkInAt = Carbon::parse($request->check_in_at);
        $checkOutAt = Carbon::parse($request->check_out_at);
        if (!empty($request->hotel_room_id) && $this->roomHasConflict((int) $request->hotel_room_id, $checkInAt, $checkOutAt)) {
            return redirect()->back()->withInput()->with('error', __('Selected room is not available for the chosen check-in/check-out time.'));
        }

        $guest = HotelGuest::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'birth_date' => $request->birth_date,
            'id_type' => $request->id_type,
            'id_number' => $request->id_number,
            'company_name' => $request->company_name,
            'is_vip' => $request->has('is_vip'),
            'created_by' => Auth::user()->creatorId(),
        ]);

        HotelReservation::create([
            'reservation_no' => 'RSV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
            'hotel_guest_id' => $guest->id,
            'hotel_room_type_id' => $request->hotel_room_type_id,
            'hotel_room_id' => $request->hotel_room_id,
            'branch_id' => Auth::user()->activeBranchContextId(),
            'check_in_at' => $checkInAt->format('Y-m-d H:i:s'),
            'check_out_at' => $checkOutAt->format('Y-m-d H:i:s'),
            'check_in_date' => $checkInAt->toDateString(),
            'check_out_date' => $checkOutAt->toDateString(),
            'adults' => (int) ($request->adults ?: 1),
            'children' => (int) ($request->children ?: 0),
            'source' => $request->source,
            'status' => $request->status,
            'rate_per_night' => $request->rate_per_night,
            'discount_amount' => $request->discount_amount ?: 0,
            'notes' => $request->notes,
            'created_by' => Auth::user()->creatorId(),
        ]);

        return redirect()->route('hotel.reservations.index')->with('success', __('Reservation created successfully.'));
    }

    public function edit(HotelReservation $reservation)
    {
        if ((!Auth::user()->can('manage hotel reservation') && !Auth::user()->can('manage hotel')) || (int) $reservation->created_by !== (int) Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
        $roomTypes = $this->roomTypeOptions();
        $roomsCollection = HotelRoom::where('created_by', Auth::user()->creatorId())
            ->orderBy('room_no')
            ->get(['id', 'room_no', 'hotel_room_type_id']);
        $roomsByType = $roomsCollection->groupBy('hotel_room_type_id')
            ->map(fn ($group) => $group->map(fn ($room) => ['id' => $room->id, 'room_no' => $room->room_no])->values())
            ->toArray();
        $rooms = $roomsCollection->pluck('room_no', 'id');
        $reservation->load('guest');
        return view('hotel.reservations.edit', compact('reservation', 'roomTypes', 'rooms', 'roomsByType'));
    }

    public function update(Request $request, HotelReservation $reservation)
    {
        if ((!Auth::user()->can('manage hotel reservation') && !Auth::user()->can('manage hotel')) || (int) $reservation->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $validator = \Validator::make($request->all(), [
            'check_in_at' => 'required|date',
            'check_out_at' => 'required|date|after:check_in_at',
            'hotel_room_type_id' => 'required|exists:hotel_room_types,id',
            'hotel_room_id' => 'nullable|exists:hotel_rooms,id',
            'status' => 'required|in:pending,confirmed,checked_in,checked_out,cancelled',
            'rate_per_night' => 'required|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        $checkInAt = Carbon::parse($request->check_in_at);
        $checkOutAt = Carbon::parse($request->check_out_at);
        if (!empty($request->hotel_room_id) && $this->roomHasConflict((int) $request->hotel_room_id, $checkInAt, $checkOutAt, (int) $reservation->id)) {
            return redirect()->back()->withInput()->with('error', __('Selected room is not available for the chosen check-in/check-out time.'));
        }

        $reservation->guest->update([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'birth_date' => $request->birth_date,
            'id_type' => $request->id_type,
            'id_number' => $request->id_number,
            'company_name' => $request->company_name,
            'is_vip' => $request->has('is_vip'),
        ]);

        $reservation->update([
            'hotel_room_type_id' => $request->hotel_room_type_id,
            'hotel_room_id' => $request->hotel_room_id,
            'check_in_at' => $checkInAt->format('Y-m-d H:i:s'),
            'check_out_at' => $checkOutAt->format('Y-m-d H:i:s'),
            'check_in_date' => $checkInAt->toDateString(),
            'check_out_date' => $checkOutAt->toDateString(),
            'adults' => (int) ($request->adults ?: 1),
            'children' => (int) ($request->children ?: 0),
            'source' => $request->source,
            'status' => $request->status,
            'rate_per_night' => $request->rate_per_night,
            'discount_amount' => $request->discount_amount ?: 0,
            'notes' => $request->notes,
        ]);

        return redirect()->route('hotel.reservations.index')->with('success', __('Reservation updated successfully.'));
    }

    public function destroy(HotelReservation $reservation)
    {
        if ((!Auth::user()->can('manage hotel reservation') && !Auth::user()->can('manage hotel')) || (int) $reservation->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $reservation->delete();
        return redirect()->route('hotel.reservations.index')->with('success', __('Reservation deleted successfully.'));
    }
}
