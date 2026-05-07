<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelGuest;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Entities\HotelRoomType;

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
        $creatorId = Auth::user()->creatorId();
        $today = Carbon::today()->toDateString();

        $reservations = HotelReservation::where('created_by', $creatorId)
            ->with(['guest', 'room', 'roomType', 'stay.folio.payments'])
            ->orderByDesc('id')
            ->get();

        $kpis = [
            'today' => HotelReservation::where('created_by', $creatorId)
                ->whereDate('check_in_date', $today)
                ->count(),
            'confirmed' => HotelReservation::where('created_by', $creatorId)
                ->whereIn('status', [HotelReservation::STATUS_CONFIRMED, HotelReservation::STATUS_CHECKED_IN])
                ->count(),
            'pending' => HotelReservation::where('created_by', $creatorId)
                ->where('status', HotelReservation::STATUS_PENDING)
                ->count(),
            'no_shows' => HotelReservation::where('created_by', $creatorId)
                ->where('status', HotelReservation::STATUS_PENDING)
                ->whereDate('check_in_date', '<', $today)
                ->count(),
            'revenue' => (float) \DB::table('hotel_folios')
                ->join('hotel_stays', 'hotel_stays.id', '=', 'hotel_folios.hotel_stay_id')
                ->join('hotel_reservations', 'hotel_reservations.id', '=', 'hotel_stays.hotel_reservation_id')
                ->where('hotel_reservations.created_by', $creatorId)
                ->whereIn('hotel_reservations.status', [
                    HotelReservation::STATUS_CONFIRMED,
                    HotelReservation::STATUS_CHECKED_IN,
                    HotelReservation::STATUS_CHECKED_OUT,
                ])
                ->sum('hotel_folios.grand_total'),
        ];

        $sourceCounts = HotelReservation::where('created_by', $creatorId)
            ->selectRaw('COALESCE(NULLIF(source, ""), "manual") as source, COUNT(*) as total')
            ->groupBy('source')
            ->pluck('total', 'source');

        $sourceLabels = $sourceCounts->keys()->map(fn ($s) => __(\Illuminate\Support\Str::title(str_replace('_', ' ', $s))))->values();
        $sourceSeries = $sourceCounts->values();
        $totalSource = (int) $sourceCounts->sum();

        $daySummary = [
            'check_ins' => HotelReservation::where('created_by', $creatorId)
                ->whereDate('check_in_date', $today)
                ->count(),
            'check_outs' => HotelReservation::where('created_by', $creatorId)
                ->whereDate('check_out_date', $today)
                ->count(),
            'reservations' => HotelReservation::where('created_by', $creatorId)
                ->whereDate('created_at', $today)
                ->count(),
            'revenue' => (float) HotelFolio::where('created_by', $creatorId)
                ->whereDate('created_at', $today)
                ->sum('grand_total'),
            'occupancy_rate' => (function () use ($creatorId) {
                $rt = HotelRoom::where('created_by', $creatorId)->count();
                $occ = HotelRoom::where('created_by', $creatorId)->where('status', HotelRoom::STATUS_OCCUPIED)->count();
                return $rt > 0 ? round(($occ / $rt) * 100, 1) : 0;
            })(),
        ];

        $recent = $reservations->take(8);

        return view('hotelmanagement::reservations.index', compact('reservations', 'kpis', 'sourceLabels', 'sourceSeries', 'totalSource', 'daySummary', 'recent'));
    }

    public function create()
    {
        if (!Auth::user()->can('manage hotel reservation') && !Auth::user()->can('manage hotel')) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
        $roomTypes = $this->roomTypeOptions();
        $rooms = HotelRoom::where('created_by', Auth::user()->creatorId())
            ->orderBy('room_no')
            ->get(['id', 'room_no', 'hotel_room_type_id', 'status', 'override_price']);
        $roomsByType = $rooms->groupBy('hotel_room_type_id')
            ->map(fn ($group) => $group->map(fn ($room) => [
                'id' => $room->id,
                'room_no' => $room->room_no,
                'status' => $room->status,
                'override_price' => $room->override_price,
            ])->values())
            ->toArray();
        $rooms = $rooms->pluck('room_no', 'id');
        $roomTypeBasePrices = HotelRoomType::where('created_by', Auth::user()->creatorId())
            ->pluck('base_price', 'id');
        return view('hotelmanagement::reservations.create', compact('roomTypes', 'rooms', 'roomsByType', 'roomTypeBasePrices'));
    }

    /**
     * @return HotelReservation|RedirectResponse
     */
    protected function persistReservation(Request $request, bool $forceConfirmed = false)
    {
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
            'id_type' => 'nullable|in:id,passport,letter',
            'nationality' => 'nullable|string|max:100',
            'preferred_payment_method' => 'nullable|string|max:50',
            'credit_limit' => 'nullable|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', $validator->getMessageBag()->first());
        }

        $checkInAt = Carbon::parse($request->check_in_at);
        $checkOutAt = Carbon::parse($request->check_out_at);
        if (!empty($request->hotel_room_id) && $this->roomHasConflict((int) $request->hotel_room_id, $checkInAt, $checkOutAt)) {
            return redirect()->back()->withInput()->with('error', __('Selected room is not available for the chosen check-in/check-out time.'));
        }

        $guestId = $request->filled('hotel_guest_id') ? (int) $request->hotel_guest_id : null;
        $guest = null;
        if ($guestId) {
            $guest = HotelGuest::where('created_by', Auth::user()->creatorId())->find($guestId);
        }
        $guestData = [
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'birth_date' => $request->birth_date,
            'id_type' => $request->id_type,
            'id_number' => $request->id_number,
            'nationality' => $request->nationality,
            'preferred_room_type_id' => $request->preferred_room_type_id ?: $request->hotel_room_type_id,
            'preferred_floor' => $request->preferred_floor,
            'preferred_payment_method' => $request->preferred_payment_method,
            'credit_limit' => $request->credit_limit ?: 0,
            'notes' => $request->guest_notes,
            'company_name' => $request->company_name,
            'is_vip' => $request->has('is_vip'),
        ];
        if ($guest) {
            $guest->update($guestData);
        } else {
            $guest = HotelGuest::create(array_merge($guestData, ['created_by' => Auth::user()->creatorId()]));
        }

        $status = $forceConfirmed ? HotelReservation::STATUS_CONFIRMED : $request->status;
        $groupId = $request->filled('hotel_group_id') ? (int) $request->hotel_group_id : null;

        return HotelReservation::create([
            'reservation_no' => 'RSV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
            'hotel_guest_id' => $guest->id,
            'hotel_room_type_id' => $request->hotel_room_type_id,
            'hotel_room_id' => $request->hotel_room_id,
            'hotel_group_id' => $groupId,
            'branch_id' => Auth::user()->activeBranchContextId(),
            'check_in_at' => $checkInAt->format('Y-m-d H:i:s'),
            'check_out_at' => $checkOutAt->format('Y-m-d H:i:s'),
            'check_in_date' => $checkInAt->toDateString(),
            'check_out_date' => $checkOutAt->toDateString(),
            'adults' => (int) ($request->adults ?: 1),
            'children' => (int) ($request->children ?: 0),
            'source' => $request->source,
            'status' => $status,
            'rate_per_night' => $request->rate_per_night,
            'discount_amount' => $request->discount_amount ?: 0,
            'notes' => $request->notes,
            'created_by' => Auth::user()->creatorId(),
        ]);
    }

    public function store(Request $request)
    {
        if (!Auth::user()->can('manage hotel reservation') && !Auth::user()->can('manage hotel')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $result = $this->persistReservation($request, false);
        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return redirect()->route('hotel.reservations.index')->with('success', __('Reservation created successfully.'));
    }

    public function storeAndCheckIn(Request $request, HotelFrontdeskController $frontdesk)
    {
        if (!Auth::user()->can('manage hotel reservation') && !Auth::user()->can('manage hotel')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $request->merge(['status' => HotelReservation::STATUS_CONFIRMED]);
        $result = $this->persistReservation($request, true);
        if ($result instanceof RedirectResponse) {
            return $result;
        }
        /** @var HotelReservation $reservation */
        $reservation = $result;
        if (!$reservation->hotel_room_id) {
            return redirect()->route('hotel.reservations.edit', $reservation)
                ->with('error', __('Assign a room before check-in, then use Check-in from the list.'));
        }

        return $frontdesk->checkIn($reservation);
    }

    public function edit(HotelReservation $reservation)
    {
        if ((!Auth::user()->can('manage hotel reservation') && !Auth::user()->can('manage hotel')) || (int) $reservation->created_by !== (int) Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
        $roomTypes = $this->roomTypeOptions();
        $roomsCollection = HotelRoom::where('created_by', Auth::user()->creatorId())
            ->orderBy('room_no')
            ->get(['id', 'room_no', 'hotel_room_type_id', 'status', 'override_price']);
        $roomsByType = $roomsCollection->groupBy('hotel_room_type_id')
            ->map(fn ($group) => $group->map(fn ($room) => [
                'id' => $room->id,
                'room_no' => $room->room_no,
                'status' => $room->status,
                'override_price' => $room->override_price,
            ])->values())
            ->toArray();
        $rooms = $roomsCollection->pluck('room_no', 'id');
        $reservation->load('guest');
        $roomTypeBasePrices = HotelRoomType::where('created_by', Auth::user()->creatorId())
            ->pluck('base_price', 'id');
        return view('hotelmanagement::reservations.edit', compact('reservation', 'roomTypes', 'rooms', 'roomsByType', 'roomTypeBasePrices'));
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
            'id_type' => 'nullable|in:id,passport,letter',
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
            'nationality' => $request->nationality,
            'preferred_room_type_id' => $request->preferred_room_type_id ?: $request->hotel_room_type_id,
            'preferred_floor' => $request->preferred_floor,
            'preferred_payment_method' => $request->preferred_payment_method,
            'credit_limit' => $request->credit_limit ?: 0,
            'notes' => $request->guest_notes,
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

    public function guestSearch(Request $request)
    {
        if (!Auth::user()->can('manage hotel reservation') && !Auth::user()->can('manage hotel')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        $q = trim((string) $request->get('q', ''));
        if ($q === '' || strlen($q) < 2) {
            return response()->json([]);
        }
        $guests = HotelGuest::where('created_by', Auth::user()->creatorId())
            ->where(function ($w) use ($q) {
                $w->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('id_number', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            })
            ->orderBy('first_name')
            ->limit(15)
            ->get([
                'id', 'first_name', 'last_name', 'email', 'phone', 'birth_date', 'id_type', 'id_number',
                'nationality', 'company_name', 'is_vip', 'preferred_room_type_id', 'preferred_floor',
                'preferred_payment_method', 'credit_limit', 'notes',
            ]);
        return response()->json($guests);
    }

    public function rateQuote(Request $request)
    {
        if (!Auth::user()->can('manage hotel reservation') && !Auth::user()->can('manage hotel')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        $creatorId = Auth::user()->creatorId();
        $roomTypeId = (int) $request->get('hotel_room_type_id');
        $roomId = (int) $request->get('hotel_room_id');
        $checkInAt = $request->filled('check_in_at') ? Carbon::parse($request->get('check_in_at')) : null;
        $checkOutAt = $request->filled('check_out_at') ? Carbon::parse($request->get('check_out_at')) : null;

        $type = $roomTypeId ? HotelRoomType::where('created_by', $creatorId)->find($roomTypeId) : null;
        $room = $roomId ? HotelRoom::where('created_by', $creatorId)->find($roomId) : null;

        $rate = $room && (float) $room->override_price > 0
            ? (float) $room->override_price
            : (float) ($type->base_price ?? 0);

        $nights = 1;
        if ($checkInAt && $checkOutAt && $checkOutAt->greaterThan($checkInAt)) {
            $hours = $checkInAt->diffInHours($checkOutAt);
            $nights = max(1, (int) ceil($hours / 24));
        }

        return response()->json([
            'rate_per_night' => $rate,
            'nights' => $nights,
            'subtotal' => $rate * $nights,
            'source' => $room && (float) $room->override_price > 0 ? 'room_override' : 'room_type_base',
        ]);
    }

    /**
     * Suggest first available room for type and date range (no conflict, status available).
     */
    public function suggestRoom(Request $request)
    {
        if (!Auth::user()->can('manage hotel reservation') && !Auth::user()->can('manage hotel')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        $creatorId = Auth::user()->creatorId();
        $roomTypeId = (int) $request->get('hotel_room_type_id');
        if (!$roomTypeId) {
            return response()->json(['room_id' => null]);
        }
        if (!$request->filled('check_in_at') || !$request->filled('check_out_at')) {
            return response()->json(['room_id' => null]);
        }
        $checkInAt = Carbon::parse($request->get('check_in_at'));
        $checkOutAt = Carbon::parse($request->get('check_out_at'));

        $rooms = HotelRoom::where('created_by', $creatorId)
            ->where('hotel_room_type_id', $roomTypeId)
            ->where('status', HotelRoom::STATUS_AVAILABLE)
            ->orderBy('room_no')
            ->get(['id']);

        foreach ($rooms as $room) {
            if (!$this->roomHasConflict((int) $room->id, $checkInAt, $checkOutAt)) {
                return response()->json(['room_id' => $room->id]);
            }
        }

        return response()->json(['room_id' => null]);
    }

    /**
     * AJAX: update reservation dates/room after calendar drag-drop.
     */
    public function calendarUpdate(Request $request, HotelReservation $reservation)
    {
        if ((!Auth::user()->can('manage hotel reservation') && !Auth::user()->can('manage hotel')) || (int) $reservation->created_by !== (int) Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }
        if (in_array($reservation->status, [HotelReservation::STATUS_CHECKED_IN, HotelReservation::STATUS_CHECKED_OUT], true)) {
            return response()->json(['error' => __('Cannot move checked-in/out reservation from calendar.')], 422);
        }
        $request->validate([
            'check_in_at' => 'required|date',
            'check_out_at' => 'required|date|after:check_in_at',
            'hotel_room_id' => 'nullable|exists:hotel_rooms,id',
        ]);
        $checkInAt = Carbon::parse($request->check_in_at);
        $checkOutAt = Carbon::parse($request->check_out_at);
        $roomId = $request->filled('hotel_room_id') ? (int) $request->hotel_room_id : $reservation->hotel_room_id;
        if ($roomId && $this->roomHasConflict($roomId, $checkInAt, $checkOutAt, (int) $reservation->id)) {
            return response()->json(['error' => __('Room conflict for new dates.')], 422);
        }
        $reservation->update([
            'check_in_at' => $checkInAt->format('Y-m-d H:i:s'),
            'check_out_at' => $checkOutAt->format('Y-m-d H:i:s'),
            'check_in_date' => $checkInAt->toDateString(),
            'check_out_date' => $checkOutAt->toDateString(),
            'hotel_room_id' => $roomId ?: $reservation->hotel_room_id,
        ]);

        return response()->json(['success' => true]);
    }
}
