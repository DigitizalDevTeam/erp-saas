<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Services\HotelAuthorization;
use Modules\HotelManagement\Services\HotelRoomStatusService;
use Modules\HotelManagement\Services\HotelStayOperationsService;

class HotelCalendarController extends Controller
{
    public function __construct(
        private readonly HotelStayOperationsService $stayOperations,
        private readonly HotelRoomStatusService $roomStatusService,
    ) {
    }

    public function index()
    {
        if (! HotelAuthorization::viewCalendar(Auth::user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $availableRooms = HotelRoom::where('created_by', $creatorId)
            ->orderBy('room_no')
            ->pluck('room_no', 'id');
        $statusOptions = HotelRoom::statusOptions();
        $canManage = HotelAuthorization::manageFrontdesk(Auth::user())
            || HotelAuthorization::manageRooms(Auth::user());

        return view('hotelmanagement::calendar.index', compact(
            'availableRooms',
            'statusOptions',
            'canManage'
        ));
    }

    public function events(Request $request)
    {
        if (! HotelAuthorization::viewCalendar(Auth::user())) {
            return response()->json([], 403);
        }
        $creatorId = Auth::user()->creatorId();
        $start = Carbon::parse($request->get('start', now()->startOfMonth()))->startOfDay();
        $end = Carbon::parse($request->get('end', now()->endOfMonth()))->endOfDay();

        $reservations = HotelReservation::where('created_by', $creatorId)
            ->whereDate('check_in_date', '<=', $end->toDateString())
            ->whereDate('check_out_date', '>=', $start->toDateString())
            ->where('status', '!=', HotelReservation::STATUS_CANCELLED)
            ->with(['guest', 'room'])
            ->get();

        $colors = [
            'pending' => '#94A3B8',
            'confirmed' => '#3B82F6',
            'checked_in' => '#10B981',
            'checked_out' => '#22C55E',
            'cancelled' => '#EF4444',
        ];

        $events = [];
        foreach ($reservations as $r) {
            $guestName = trim(($r->guest->first_name ?? '') . ' ' . ($r->guest->last_name ?? ''));
            $room = $r->room?->room_no ? ' #' . $r->room->room_no : '';
            $startAt = $r->check_in_at ? Carbon::parse($r->check_in_at)->toIso8601String() : Carbon::parse($r->check_in_date)->startOfDay()->toIso8601String();
            $endAt = $r->check_out_at ? Carbon::parse($r->check_out_at)->toIso8601String() : Carbon::parse($r->check_out_date)->endOfDay()->toIso8601String();
            $events[] = [
                'id' => (string) $r->id,
                'title' => $guestName . $room,
                'start' => $startAt,
                'end' => $endAt,
                'backgroundColor' => $colors[$r->status] ?? '#6366F1',
                'borderColor' => $colors[$r->status] ?? '#6366F1',
                'editable' => ! in_array($r->status, [HotelReservation::STATUS_CHECKED_IN, HotelReservation::STATUS_CHECKED_OUT], true),
                'extendedProps' => [
                    'reservation_id' => $r->id,
                    'status' => $r->status,
                    'hotel_room_id' => $r->hotel_room_id,
                    'guest_name' => $guestName,
                    'room_no' => $r->room?->room_no,
                    'check_out_date' => $r->check_out_date
                        ? Carbon::parse($r->check_out_date)->toDateString()
                        : null,
                ],
            ];
        }

        return response()->json($events);
    }

    /**
     * Room grid payload: every hotel room (so 138–142 always appear) + bookings in range.
     */
    public function roomBoard(Request $request)
    {
        if (! HotelAuthorization::viewCalendar(Auth::user())) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $creatorId = (int) Auth::user()->creatorId();
        $start = Carbon::parse($request->get('start', now()->startOfWeek()))->startOfDay();
        $end = Carbon::parse($request->get('end', now()->startOfWeek()->addDays(6)))->endOfDay();
        if ($end->lt($start)) {
            $end = $start->copy()->endOfDay();
        }

        $rooms = HotelRoom::where('created_by', $creatorId)
            ->with('floor')
            ->orderBy('room_no')
            ->get()
            ->map(fn (HotelRoom $room) => [
                'id' => $room->id,
                'room_no' => $room->room_no,
                'status' => $room->status,
                'floor' => $room->floor->name ?? null,
            ])
            ->values();

        $reservations = HotelReservation::where('created_by', $creatorId)
            ->whereDate('check_in_date', '<=', $end->toDateString())
            ->whereDate('check_out_date', '>=', $start->toDateString())
            ->where('status', '!=', HotelReservation::STATUS_CANCELLED)
            ->with(['guest', 'room'])
            ->get()
            ->map(function (HotelReservation $r) {
                $guestName = trim(($r->guest->first_name ?? '') . ' ' . ($r->guest->last_name ?? ''));

                return [
                    'id' => $r->id,
                    'hotel_room_id' => $r->hotel_room_id,
                    'guest_name' => $guestName !== '' ? $guestName : __('Guest'),
                    'status' => $r->status,
                    'check_in_date' => Carbon::parse($r->check_in_date)->toDateString(),
                    'check_out_date' => Carbon::parse($r->check_out_date)->toDateString(),
                    'room_no' => $r->room?->room_no,
                ];
            })
            ->values();

        $days = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $days[] = $d->toDateString();
        }

        return response()->json([
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'days' => $days,
            'rooms' => $rooms,
            'reservations' => $reservations,
            'status_options' => HotelRoom::statusOptions(),
        ]);
    }

    public function extendStay(Request $request, HotelReservation $reservation)
    {
        if (! $this->canManageStay($reservation)) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $request->validate(['check_out_at' => 'required|date']);

        try {
            $this->stayOperations->extendStay(
                $reservation,
                Carbon::parse($request->check_out_at),
                (int) Auth::user()->creatorId()
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('Stay extended successfully.')]);
    }

    public function changeRoom(Request $request, HotelReservation $reservation)
    {
        if (! $this->canManageStay($reservation)) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $request->validate(['hotel_room_id' => 'required|exists:hotel_rooms,id']);

        try {
            $this->stayOperations->changeRoom(
                $reservation,
                (int) $request->hotel_room_id,
                (int) Auth::user()->creatorId()
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => __('Room changed successfully.')]);
    }

    public function changeRoomStatus(Request $request, HotelRoom $room)
    {
        if (! HotelAuthorization::manageRooms(Auth::user())
            && ! HotelAuthorization::manageFrontdesk(Auth::user())) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        if ((int) $room->created_by !== (int) Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $request->validate([
            'status' => 'required|in:available,occupied,dirty,cleaning,maintenance,blocked',
        ]);

        try {
            $this->roomStatusService->transition(
                $room,
                $request->status,
                (int) Auth::user()->creatorId()
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('Room status updated.'),
            'status' => $room->fresh()->status,
        ]);
    }

    private function canManageStay(HotelReservation $reservation): bool
    {
        if ((int) $reservation->created_by !== (int) Auth::user()->creatorId()) {
            return false;
        }

        return HotelAuthorization::manageFrontdesk(Auth::user())
            || HotelAuthorization::manageRooms(Auth::user());
    }
}
