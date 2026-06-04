<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Services\HotelAuthorization;

class HotelCalendarController extends Controller
{
    public function index()
    {
        if (! HotelAuthorization::viewCalendar(Auth::user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return view('hotelmanagement::calendar.index');
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
                'editable' => !in_array($r->status, [HotelReservation::STATUS_CHECKED_IN, HotelReservation::STATUS_CHECKED_OUT], true),
                'extendedProps' => [
                    'reservation_id' => $r->id,
                    'status' => $r->status,
                ],
            ];
        }

        return response()->json($events);
    }
}
