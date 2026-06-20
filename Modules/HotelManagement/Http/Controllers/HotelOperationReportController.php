<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Http\Controllers\Concerns\RendersHotelReports;
use Modules\HotelManagement\Services\HotelAuthorization;

class HotelOperationReportController extends Controller
{
    use RendersHotelReports;

    protected function authorizeReport(): void
    {
        HotelAuthorization::abortUnless(HotelAuthorization::viewReports(Auth::user()));
    }

    public function reservations(Request $request)
    {
        $this->authorizeReport();
        [$from, $to] = $this->reportDateRange($request);
        $creatorId = Auth::user()->creatorId();

        $reservations = HotelReservation::where('created_by', $creatorId)
            ->whereDate('check_in_date', '<=', $to->toDateString())
            ->whereDate('check_out_date', '>=', $from->toDateString())
            ->with(['guest', 'roomType', 'room'])
            ->orderByDesc('check_in_date')
            ->get();

        $totalAmount = 0.0;
        $rows = $reservations->map(function (HotelReservation $r) use (&$totalAmount) {
            $nights = max(1, Carbon::parse($r->check_in_date)->diffInDays(Carbon::parse($r->check_out_date)));
            $amount = max(0, ($nights * (float) $r->rate_per_night) - (float) $r->discount_amount);
            $totalAmount += $amount;

            return [
                'res_no' => $r->reservation_no,
                'guest' => $r->guest?->fullName() ?: '—',
                'room_type' => $r->roomType?->name ?: '—',
                'room' => $r->room?->room_no ?: '—',
                'check_in' => $r->check_in_date,
                'check_out' => $r->check_out_date,
                'nights' => $nights,
                'pax' => (int) $r->adults + (int) $r->children,
                'source' => __(ucfirst((string) $r->source)),
                'status' => __(ucfirst(str_replace('_', ' ', (string) $r->status))),
                'amount' => $amount,
            ];
        })->all();

        $byStatus = $reservations->groupBy('status')->map->count();

        return $this->renderReport($request, [
            'key' => 'reservations',
            'title' => __('Reservation Report'),
            'icon' => 'ti ti-calendar-event',
            'subtitle' => __('Reservations active within the selected period.'),
            'from' => $from,
            'to' => $to,
            'orientation' => 'landscape',
            'summary' => [
                ['label' => __('Reservations'), 'value' => $reservations->count(), 'tone' => 'primary'],
                ['label' => __('Confirmed'), 'value' => (int) ($byStatus['confirmed'] ?? 0), 'tone' => 'success'],
                ['label' => __('Cancelled'), 'value' => (int) ($byStatus['cancelled'] ?? 0), 'tone' => 'danger'],
                ['label' => __('Est. room amount'), 'value' => Auth::user()->priceFormat($totalAmount), 'tone' => 'info'],
            ],
            'columns' => [
                ['label' => __('Reservation'), 'key' => 'res_no', 'type' => 'text'],
                ['label' => __('Guest'), 'key' => 'guest', 'type' => 'text'],
                ['label' => __('Room type'), 'key' => 'room_type', 'type' => 'text'],
                ['label' => __('Room'), 'key' => 'room', 'type' => 'text'],
                ['label' => __('Check-in'), 'key' => 'check_in', 'type' => 'text'],
                ['label' => __('Check-out'), 'key' => 'check_out', 'type' => 'text'],
                ['label' => __('Nights'), 'key' => 'nights', 'type' => 'number'],
                ['label' => __('Guests'), 'key' => 'pax', 'type' => 'number'],
                ['label' => __('Source'), 'key' => 'source', 'type' => 'text'],
                ['label' => __('Status'), 'key' => 'status', 'type' => 'text'],
                ['label' => __('Est. amount'), 'key' => 'amount', 'type' => 'money'],
            ],
            'rows' => $rows,
            'totalsRow' => ['res_no' => __('Total'), 'amount' => $totalAmount],
        ]);
    }

    public function checkIn(Request $request)
    {
        $this->authorizeReport();
        [$from, $to] = $this->reportDateRange($request);
        $creatorId = Auth::user()->creatorId();

        $stays = $this->stayQuery($creatorId)
            ->whereNotNull('s.check_in_at')
            ->whereBetween('s.check_in_at', [$from, $to])
            ->orderByDesc('s.check_in_at')
            ->get();

        $rows = $stays->map(fn ($s) => [
            'check_in' => Carbon::parse($s->check_in_at)->format('Y-m-d H:i'),
            'guest' => trim((string) $s->guest) ?: '—',
            'room' => $s->room ?: '—',
            'res_no' => $s->reservation_no ?: '—',
            'expected_out' => $s->expected_check_out_at ? Carbon::parse($s->expected_check_out_at)->format('Y-m-d') : '—',
            'status' => __(ucfirst(str_replace('_', ' ', (string) $s->stay_status))),
            'folio' => $s->folio_no ?: '—',
        ])->all();

        return $this->renderReport($request, [
            'key' => 'check-in',
            'title' => __('Check-in Report'),
            'icon' => 'ti ti-login',
            'subtitle' => __('Guests checked in during the period.'),
            'from' => $from,
            'to' => $to,
            'summary' => [
                ['label' => __('Check-ins'), 'value' => $stays->count(), 'tone' => 'success'],
            ],
            'columns' => [
                ['label' => __('Check-in time'), 'key' => 'check_in', 'type' => 'text'],
                ['label' => __('Guest'), 'key' => 'guest', 'type' => 'text'],
                ['label' => __('Room'), 'key' => 'room', 'type' => 'text'],
                ['label' => __('Reservation'), 'key' => 'res_no', 'type' => 'text'],
                ['label' => __('Expected check-out'), 'key' => 'expected_out', 'type' => 'text'],
                ['label' => __('Status'), 'key' => 'status', 'type' => 'text'],
                ['label' => __('Folio'), 'key' => 'folio', 'type' => 'text'],
            ],
            'rows' => $rows,
        ]);
    }

    public function checkOut(Request $request)
    {
        $this->authorizeReport();
        [$from, $to] = $this->reportDateRange($request);
        $creatorId = Auth::user()->creatorId();

        $stays = $this->stayQuery($creatorId)
            ->whereNotNull('s.checked_out_at')
            ->whereBetween('s.checked_out_at', [$from, $to])
            ->orderByDesc('s.checked_out_at')
            ->get();

        $totalRevenue = 0.0;
        $totalBalance = 0.0;
        $rows = $stays->map(function ($s) use (&$totalRevenue, &$totalBalance) {
            $grand = (float) $s->grand_total;
            $paid = (float) $s->paid_total;
            $balance = max(0, $grand - $paid);
            $totalRevenue += $grand;
            $totalBalance += $balance;

            return [
                'check_out' => Carbon::parse($s->checked_out_at)->format('Y-m-d H:i'),
                'guest' => trim((string) $s->guest) ?: '—',
                'room' => $s->room ?: '—',
                'folio' => $s->folio_no ?: '—',
                'grand' => $grand,
                'paid' => $paid,
                'balance' => $balance,
            ];
        })->all();

        return $this->renderReport($request, [
            'key' => 'check-out',
            'title' => __('Check-out Report'),
            'icon' => 'ti ti-logout',
            'subtitle' => __('Guests checked out during the period.'),
            'from' => $from,
            'to' => $to,
            'summary' => [
                ['label' => __('Check-outs'), 'value' => $stays->count(), 'tone' => 'primary'],
                ['label' => __('Revenue'), 'value' => Auth::user()->priceFormat($totalRevenue), 'tone' => 'success'],
                ['label' => __('Balance due'), 'value' => Auth::user()->priceFormat($totalBalance), 'tone' => 'danger'],
            ],
            'columns' => [
                ['label' => __('Check-out time'), 'key' => 'check_out', 'type' => 'text'],
                ['label' => __('Guest'), 'key' => 'guest', 'type' => 'text'],
                ['label' => __('Room'), 'key' => 'room', 'type' => 'text'],
                ['label' => __('Folio'), 'key' => 'folio', 'type' => 'text'],
                ['label' => __('Grand total'), 'key' => 'grand', 'type' => 'money'],
                ['label' => __('Paid'), 'key' => 'paid', 'type' => 'money'],
                ['label' => __('Balance'), 'key' => 'balance', 'type' => 'money'],
            ],
            'rows' => $rows,
            'totalsRow' => ['check_out' => __('Total'), 'grand' => $totalRevenue, 'balance' => $totalBalance],
        ]);
    }

    public function roomStatus(Request $request)
    {
        $this->authorizeReport();
        $creatorId = Auth::user()->creatorId();

        $rooms = DB::table('hotel_rooms as rm')
            ->leftJoin('hotel_room_types as t', 't.id', '=', 'rm.hotel_room_type_id')
            ->leftJoin('hotel_floors as fl', 'fl.id', '=', 'rm.hotel_floor_id')
            ->where('rm.created_by', $creatorId)
            ->selectRaw('rm.id, rm.room_no, rm.status, t.name as room_type, fl.name as floor')
            ->orderBy('rm.room_no')
            ->get();

        // Current guest per occupied room.
        $guestByRoom = DB::table('hotel_stays as s')
            ->join('hotel_reservations as r', 'r.id', '=', 's.hotel_reservation_id')
            ->leftJoin('hotel_guests as g', 'g.id', '=', 'r.hotel_guest_id')
            ->where('s.created_by', $creatorId)
            ->where('s.status', 'in_house')
            ->selectRaw("s.hotel_room_id, TRIM(CONCAT(COALESCE(g.first_name,''),' ',COALESCE(g.last_name,''))) as guest")
            ->pluck('guest', 'hotel_room_id');

        $rows = $rooms->map(fn ($r) => [
            'room' => $r->room_no,
            'room_type' => $r->room_type ?: '—',
            'floor' => $r->floor ?: '—',
            'status' => __(ucfirst(str_replace('_', ' ', (string) $r->status))),
            'guest' => trim((string) ($guestByRoom[$r->id] ?? '')) ?: '—',
        ])->all();

        $byStatus = $rooms->groupBy('status')->map->count();

        return $this->renderReport($request, [
            'key' => 'room-status',
            'title' => __('Room Status Report'),
            'icon' => 'ti ti-door',
            'subtitle' => __('Live snapshot of every room and its current status.'),
            'showDateFilter' => false,
            'summary' => [
                ['label' => __('Total rooms'), 'value' => $rooms->count(), 'tone' => 'primary'],
                ['label' => __('Available'), 'value' => (int) ($byStatus['available'] ?? 0), 'tone' => 'success'],
                ['label' => __('Occupied'), 'value' => (int) ($byStatus['occupied'] ?? 0), 'tone' => 'danger'],
                ['label' => __('Dirty'), 'value' => (int) ($byStatus['dirty'] ?? 0), 'tone' => 'warning'],
                ['label' => __('Cleaning / maintenance'), 'value' => (int) ($byStatus['cleaning'] ?? 0) + (int) ($byStatus['maintenance'] ?? 0), 'tone' => 'warning'],
            ],
            'columns' => [
                ['label' => __('Room'), 'key' => 'room', 'type' => 'text'],
                ['label' => __('Room type'), 'key' => 'room_type', 'type' => 'text'],
                ['label' => __('Floor'), 'key' => 'floor', 'type' => 'text'],
                ['label' => __('Status'), 'key' => 'status', 'type' => 'text'],
                ['label' => __('Current guest'), 'key' => 'guest', 'type' => 'text'],
            ],
            'rows' => $rows,
        ]);
    }

    public function housekeeping(Request $request)
    {
        $this->authorizeReport();
        [$from, $to] = $this->reportDateRange($request);
        $creatorId = Auth::user()->creatorId();

        $tasks = DB::table('hotel_housekeeping_tasks as h')
            ->leftJoin('hotel_rooms as rm', 'rm.id', '=', 'h.hotel_room_id')
            ->leftJoin('users as u', 'u.id', '=', 'h.assigned_to')
            ->where('h.created_by', $creatorId)
            ->whereBetween('h.created_at', [$from, $to])
            ->selectRaw('h.status, h.notes, h.created_at, h.updated_at, rm.room_no as room, u.name as assignee')
            ->orderByDesc('h.created_at')
            ->get();

        $rows = $tasks->map(fn ($t) => [
            'room' => $t->room ?: '—',
            'status' => __(ucfirst(str_replace('_', ' ', (string) $t->status))),
            'assignee' => $t->assignee ?: __('Unassigned'),
            'notes' => $t->notes ?: '—',
            'created' => Carbon::parse($t->created_at)->format('Y-m-d H:i'),
            'updated' => Carbon::parse($t->updated_at)->format('Y-m-d H:i'),
        ])->all();

        $byStatus = $tasks->groupBy('status')->map->count();

        return $this->renderReport($request, [
            'key' => 'housekeeping',
            'title' => __('Housekeeping Report'),
            'icon' => 'ti ti-spray',
            'subtitle' => __('Housekeeping tasks created within the period.'),
            'from' => $from,
            'to' => $to,
            'summary' => [
                ['label' => __('Tasks'), 'value' => $tasks->count(), 'tone' => 'primary'],
                ['label' => __('Pending'), 'value' => (int) ($byStatus['pending'] ?? 0), 'tone' => 'warning'],
                ['label' => __('In progress'), 'value' => (int) ($byStatus['in_progress'] ?? 0), 'tone' => 'info'],
                ['label' => __('Done'), 'value' => (int) ($byStatus['done'] ?? 0), 'tone' => 'success'],
            ],
            'columns' => [
                ['label' => __('Room'), 'key' => 'room', 'type' => 'text'],
                ['label' => __('Status'), 'key' => 'status', 'type' => 'text'],
                ['label' => __('Assigned to'), 'key' => 'assignee', 'type' => 'text'],
                ['label' => __('Notes'), 'key' => 'notes', 'type' => 'text'],
                ['label' => __('Created'), 'key' => 'created', 'type' => 'text'],
                ['label' => __('Updated'), 'key' => 'updated', 'type' => 'text'],
            ],
            'rows' => $rows,
        ]);
    }

    /**
     * Base stay query joined to reservation, guest, room and folio.
     */
    private function stayQuery(int $creatorId)
    {
        return DB::table('hotel_stays as s')
            ->leftJoin('hotel_reservations as r', 'r.id', '=', 's.hotel_reservation_id')
            ->leftJoin('hotel_guests as g', 'g.id', '=', 'r.hotel_guest_id')
            ->leftJoin('hotel_rooms as rm', 'rm.id', '=', 's.hotel_room_id')
            ->leftJoin('hotel_folios as f', 'f.hotel_stay_id', '=', 's.id')
            ->where('s.created_by', $creatorId)
            ->selectRaw("s.check_in_at, s.checked_out_at, s.expected_check_out_at, s.status as stay_status,
                r.reservation_no,
                TRIM(CONCAT(COALESCE(g.first_name,''),' ',COALESCE(g.last_name,''))) as guest,
                rm.room_no as room,
                f.folio_no, f.grand_total, f.paid_total");
    }
}
