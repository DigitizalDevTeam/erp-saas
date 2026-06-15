<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Browsershot\Browsershot;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelGuest;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Exports\SimpleSheetExport;
use Modules\HotelManagement\Services\HotelAuthorization;

class HotelReportController extends Controller
{
    protected function authorizeReport(): void
    {
        HotelAuthorization::abortUnless(HotelAuthorization::viewReports(Auth::user()));
    }

    protected function dateRange(Request $request): array
    {
        $to = $request->filled('to') ? Carbon::parse($request->get('to'))->endOfDay() : Carbon::today()->endOfDay();
        $from = $request->filled('from') ? Carbon::parse($request->get('from'))->startOfDay() : $to->copy()->subDays(29)->startOfDay();

        return [$from, $to];
    }

    public function index()
    {
        $this->authorizeReport();

        return view('hotelmanagement::reports.index');
    }

    public function operational(Request $request)
    {
        $this->authorizeReport();
        [$from, $to] = $this->dateRange($request);
        $creatorId = Auth::user()->creatorId();
        $roomsTotal = max(1, HotelRoom::where('created_by', $creatorId)->count());

        $period = CarbonPeriod::create($from->toDateString(), $to->toDateString());
        $daily = [];
        foreach ($period as $day) {
            $d = $day->toDateString();
            $inHouse = HotelReservation::where('created_by', $creatorId)
                ->whereIn('status', [HotelReservation::STATUS_PENDING, HotelReservation::STATUS_CONFIRMED, HotelReservation::STATUS_CHECKED_IN])
                ->whereDate('check_in_date', '<=', $d)
                ->whereDate('check_out_date', '>', $d)
                ->count();
            $checkIns = HotelReservation::where('created_by', $creatorId)->whereDate('check_in_date', $d)->count();
            $checkOuts = HotelReservation::where('created_by', $creatorId)->whereDate('check_out_date', $d)->count();
            $daily[] = [
                'date' => $d,
                'occupancy_pct' => round(min(100, ($inHouse / $roomsTotal) * 100), 1),
                'check_ins' => $checkIns,
                'check_outs' => $checkOuts,
            ];
        }

        $roomStatus = HotelRoom::where('created_by', $creatorId)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $avgStay = $this->averageStayNights($creatorId, $from, $to);

        return view('hotelmanagement::reports.operational', compact('daily', 'roomStatus', 'avgStay', 'from', 'to', 'roomsTotal'));
    }

    public function operationalPdf(Request $request)
    {
        $this->authorizeReport();
        [$from, $to] = $this->dateRange($request);
        $creatorId = Auth::user()->creatorId();
        $roomsTotal = max(1, HotelRoom::where('created_by', $creatorId)->count());

        $period = CarbonPeriod::create($from->toDateString(), $to->toDateString());
        $daily = [];
        foreach ($period as $day) {
            $d = $day->toDateString();
            $inHouse = HotelReservation::where('created_by', $creatorId)
                ->whereIn('status', [HotelReservation::STATUS_PENDING, HotelReservation::STATUS_CONFIRMED, HotelReservation::STATUS_CHECKED_IN])
                ->whereDate('check_in_date', '<=', $d)
                ->whereDate('check_out_date', '>', $d)
                ->count();
            $checkIns = HotelReservation::where('created_by', $creatorId)->whereDate('check_in_date', $d)->count();
            $checkOuts = HotelReservation::where('created_by', $creatorId)->whereDate('check_out_date', $d)->count();
            $daily[] = [
                'date' => $d,
                'occupancy_pct' => round(min(100, ($inHouse / $roomsTotal) * 100), 1),
                'check_ins' => $checkIns,
                'check_outs' => $checkOuts,
            ];
        }

        $avgStay = $this->averageStayNights($creatorId, $from, $to);
        $fileName = 'hotel-operational-' . $from->format('Ymd') . '-' . $to->format('Ymd') . '.pdf';
        $html = view('hotelmanagement::reports.operational_pdf', compact('daily', 'from', 'to', 'roomsTotal', 'avgStay'))->render();

        return $this->downloadPdf($html, $fileName, 'landscape');
    }

    public function operationalExport(Request $request)
    {
        $this->authorizeReport();
        [$from, $to] = $this->dateRange($request);
        $creatorId = Auth::user()->creatorId();
        $roomsTotal = max(1, HotelRoom::where('created_by', $creatorId)->count());
        $period = CarbonPeriod::create($from->toDateString(), $to->toDateString());
        $rows = [];
        foreach ($period as $day) {
            $d = $day->toDateString();
            $inHouse = HotelReservation::where('created_by', $creatorId)
                ->whereIn('status', [HotelReservation::STATUS_PENDING, HotelReservation::STATUS_CONFIRMED, HotelReservation::STATUS_CHECKED_IN])
                ->whereDate('check_in_date', '<=', $d)
                ->whereDate('check_out_date', '>', $d)
                ->count();
            $rows[] = [
                $d,
                round(min(100, ($inHouse / $roomsTotal) * 100), 1),
                HotelReservation::where('created_by', $creatorId)->whereDate('check_in_date', $d)->count(),
                HotelReservation::where('created_by', $creatorId)->whereDate('check_out_date', $d)->count(),
            ];
        }

        return Excel::download(
            new SimpleSheetExport(['Date', 'Occupancy %', 'Check-ins', 'Check-outs'], $rows),
            'hotel-operational-' . $from->format('Ymd') . '-' . $to->format('Ymd') . '.xlsx'
        );
    }

    public function financial(Request $request)
    {
        $this->authorizeReport();
        [$from, $to] = $this->dateRange($request);
        $creatorId = Auth::user()->creatorId();

        $folioByDay = HotelFolio::where('created_by', $creatorId)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as d, SUM(grand_total) as revenue, SUM(paid_total) as paid')
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        $discountTotal = DB::table('hotel_folio_lines')
            ->join('hotel_folios', 'hotel_folio_lines.hotel_folio_id', '=', 'hotel_folios.id')
            ->where('hotel_folios.created_by', $creatorId)
            ->whereBetween('hotel_folio_lines.created_at', [$from, $to])
            ->sum('hotel_folio_lines.discount_amount');

        $methodTotals = collect();
        if (Schema::hasTable('hotel_folio_payments')) {
            $methodTotals = DB::table('hotel_folio_payments')
                ->join('hotel_folios', 'hotel_folio_payments.hotel_folio_id', '=', 'hotel_folios.id')
                ->where('hotel_folios.created_by', $creatorId)
                ->whereBetween('hotel_folio_payments.created_at', [$from, $to])
                ->selectRaw('method, SUM(amount) as total')
                ->groupBy('method')
                ->pluck('total', 'method');
        }

        $totalRevenue = (float) $folioByDay->sum('revenue');
        $totalPaid = (float) $folioByDay->sum('paid');
        $totalPending = max(0, $totalRevenue - $totalPaid);

        return view('hotelmanagement::reports.financial', compact(
            'folioByDay',
            'discountTotal',
            'methodTotals',
            'totalRevenue',
            'totalPaid',
            'totalPending',
            'from',
            'to'
        ));
    }

    public function financialPdf(Request $request)
    {
        $this->authorizeReport();
        [$from, $to] = $this->dateRange($request);
        $creatorId = Auth::user()->creatorId();

        $folioByDay = HotelFolio::where('created_by', $creatorId)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as d, SUM(grand_total) as revenue, SUM(paid_total) as paid')
            ->groupBy('d')
            ->orderBy('d')
            ->get();

        $discountTotal = DB::table('hotel_folio_lines')
            ->join('hotel_folios', 'hotel_folio_lines.hotel_folio_id', '=', 'hotel_folios.id')
            ->where('hotel_folios.created_by', $creatorId)
            ->whereBetween('hotel_folio_lines.created_at', [$from, $to])
            ->sum('hotel_folio_lines.discount_amount');

        $methodTotals = collect();
        if (Schema::hasTable('hotel_folio_payments')) {
            $methodTotals = DB::table('hotel_folio_payments')
                ->join('hotel_folios', 'hotel_folio_payments.hotel_folio_id', '=', 'hotel_folios.id')
                ->where('hotel_folios.created_by', $creatorId)
                ->whereBetween('hotel_folio_payments.created_at', [$from, $to])
                ->selectRaw('method, SUM(amount) as total')
                ->groupBy('method')
                ->pluck('total', 'method');
        }

        $totalRevenue = (float) $folioByDay->sum('revenue');
        $totalPaid = (float) $folioByDay->sum('paid');
        $fileName = 'hotel-financial-' . $from->format('Ymd') . '-' . $to->format('Ymd') . '.pdf';
        $html = view('hotelmanagement::reports.financial_pdf', compact(
            'folioByDay',
            'discountTotal',
            'methodTotals',
            'totalRevenue',
            'totalPaid',
            'from',
            'to'
        ))->render();

        return $this->downloadPdf($html, $fileName);
    }

    public function financialExport(Request $request)
    {
        $this->authorizeReport();
        [$from, $to] = $this->dateRange($request);
        $creatorId = Auth::user()->creatorId();
        $rows = HotelFolio::where('created_by', $creatorId)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as d, SUM(grand_total) as revenue, SUM(paid_total) as paid')
            ->groupBy('d')
            ->orderBy('d')
            ->get()
            ->map(fn ($r) => [$r->d, $r->revenue, $r->paid])
            ->toArray();

        return Excel::download(
            new SimpleSheetExport(['Date', 'Revenue', 'Paid'], $rows),
            'hotel-financial-' . $from->format('Ymd') . '-' . $to->format('Ymd') . '.xlsx'
        );
    }

    public function immigration(Request $request)
    {
        $this->authorizeReport();
        [$from, $to] = $this->dateRange($request);
        $creatorId = Auth::user()->creatorId();
        $rows = $this->immigrationRows($creatorId, $from, $to);

        return view('hotelmanagement::reports.immigration', compact('rows', 'from', 'to'));
    }

    public function immigrationPdf(Request $request)
    {
        $this->authorizeReport();
        [$from, $to] = $this->dateRange($request);
        $creatorId = Auth::user()->creatorId();
        $rows = $this->immigrationRows($creatorId, $from, $to);
        $fileName = 'hotel-immigration-' . $from->format('Ymd') . '-' . $to->format('Ymd') . '.pdf';
        $html = view('hotelmanagement::reports.immigration_pdf', compact('rows', 'from', 'to'))->render();

        return $this->downloadPdf($html, $fileName, 'landscape');
    }

    public function immigrationExport(Request $request)
    {
        $this->authorizeReport();
        [$from, $to] = $this->dateRange($request);
        $creatorId = Auth::user()->creatorId();
        $rows = $this->immigrationRows($creatorId, $from, $to)->map(fn (array $row) => [
            $row['reservation_no'],
            $row['full_name'],
            $row['nationality'],
            $row['id_type'],
            $row['id_number'],
            $row['birth_date'],
            $row['check_in_date'],
            $row['check_out_date'],
            $row['room'],
            $row['role'],
            $row['status'],
        ])->toArray();

        return Excel::download(
            new SimpleSheetExport([
                __('Reservation'),
                __('Full name'),
                __('Nationality'),
                __('ID type'),
                __('ID / Passport'),
                __('Birth date'),
                __('Check-in'),
                __('Check-out'),
                __('Room'),
                __('Role'),
                __('Status'),
            ], $rows),
            'hotel-immigration-' . $from->format('Ymd') . '-' . $to->format('Ymd') . '.xlsx'
        );
    }

    public function occupancy(Request $request)
    {
        $this->authorizeReport();
        [$from, $to] = $this->dateRange($request);
        $creatorId = Auth::user()->creatorId();
        $roomsTotal = max(1, HotelRoom::where('created_by', $creatorId)->count());

        $period = CarbonPeriod::create($from->toDateString(), $to->toDateString());
        $heatmap = [];
        foreach ($period as $day) {
            $d = $day->toDateString();
            $inHouse = HotelReservation::where('created_by', $creatorId)
                ->whereIn('status', [HotelReservation::STATUS_PENDING, HotelReservation::STATUS_CONFIRMED, HotelReservation::STATUS_CHECKED_IN])
                ->whereDate('check_in_date', '<=', $d)
                ->whereDate('check_out_date', '>', $d)
                ->count();
            $heatmap[] = ['date' => $d, 'rate' => round(min(100, ($inHouse / $roomsTotal) * 100), 1)];
        }

        $busiest = HotelReservation::where('created_by', $creatorId)
            ->whereBetween('check_in_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('DATE(check_in_date) as d, COUNT(*) as c')
            ->groupBy('d')
            ->orderByDesc('c')
            ->limit(10)
            ->get();

        $topRooms = HotelReservation::where('created_by', $creatorId)
            ->whereNotNull('hotel_room_id')
            ->whereBetween('check_in_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('hotel_room_id, COUNT(*) as c')
            ->groupBy('hotel_room_id')
            ->orderByDesc('c')
            ->limit(10)
            ->get();

        $nationality = HotelGuest::where('created_by', $creatorId)
            ->whereNotNull('nationality')
            ->where('nationality', '!=', '')
            ->selectRaw('nationality, COUNT(*) as c')
            ->groupBy('nationality')
            ->orderByDesc('c')
            ->limit(15)
            ->get();

        return view('hotelmanagement::reports.occupancy', compact('heatmap', 'busiest', 'topRooms', 'nationality', 'from', 'to', 'roomsTotal'));
    }

    public function customers(Request $request)
    {
        $this->authorizeReport();
        [$from, $to] = $this->dateRange($request);
        $creatorId = Auth::user()->creatorId();

        $vipCount = HotelGuest::where('created_by', $creatorId)->where('is_vip', true)->count();

        $returning = DB::table('hotel_reservations')
            ->select('hotel_guest_id', DB::raw('COUNT(*) as cnt'))
            ->where('created_by', $creatorId)
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('hotel_guest_id')
            ->having('cnt', '>', 1)
            ->count();

        $newGuests = DB::table('hotel_guests')
            ->where('created_by', $creatorId)
            ->whereBetween('created_at', [$from, $to])
            ->count();

        $frequent = HotelGuest::where('created_by', $creatorId)
            ->withCount(['reservations' => fn ($q) => $q->whereBetween('created_at', [$from, $to])])
            ->orderByDesc('reservations_count')
            ->limit(50)
            ->get()
            ->filter(fn ($g) => $g->reservations_count >= 2)
            ->take(20)
            ->values();

        $byOrigin = HotelGuest::where('created_by', $creatorId)
            ->whereNotNull('nationality')
            ->where('nationality', '!=', '')
            ->selectRaw('nationality, COUNT(*) as c')
            ->groupBy('nationality')
            ->orderByDesc('c')
            ->limit(20)
            ->get();

        $guestsWithAlerts = HotelGuest::where('created_by', $creatorId)
            ->where(function ($query) {
                $query->whereNotNull('allergies')->where('allergies', '!=', '')
                    ->orWhere(function ($sub) {
                        $sub->whereNotNull('preferences')->where('preferences', '!=', '');
                    })
                    ->orWhere(function ($sub) {
                        $sub->whereNotNull('drink_preferences')->where('drink_preferences', '!=', '');
                    });
            })
            ->count();

        $parkingRequests = HotelReservation::where('created_by', $creatorId)
            ->whereBetween('check_in_date', [$from->toDateString(), $to->toDateString()])
            ->where('parking_requested', true)
            ->count();

        $etaReservations = HotelReservation::where('created_by', $creatorId)
            ->whereBetween('check_in_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('estimated_arrival_time')
            ->count();

        return view('hotelmanagement::reports.customers', compact('vipCount', 'returning', 'newGuests', 'frequent', 'byOrigin', 'guestsWithAlerts', 'parkingRequests', 'etaReservations', 'from', 'to'));
    }

    public function alerts(Request $request)
    {
        $this->authorizeReport();
        $creatorId = Auth::user()->creatorId();
        $today = Carbon::today()->toDateString();

        $lateCheckIns = HotelReservation::where('created_by', $creatorId)
            ->whereIn('status', [HotelReservation::STATUS_PENDING, HotelReservation::STATUS_CONFIRMED])
            ->whereDate('check_in_date', '<', $today)
            ->with(['guest', 'room'])
            ->orderBy('check_in_date')
            ->get();

        $unassignedToday = HotelReservation::where('created_by', $creatorId)
            ->whereIn('status', [HotelReservation::STATUS_PENDING, HotelReservation::STATUS_CONFIRMED])
            ->whereNull('hotel_room_id')
            ->whereDate('check_in_date', '<=', $today)
            ->count();

        $cancellations = HotelReservation::where('created_by', $creatorId)
            ->where('status', HotelReservation::STATUS_CANCELLED)
            ->whereMonth('updated_at', Carbon::now()->month)
            ->count();

        $paymentIssues = HotelFolio::where('created_by', $creatorId)
            ->where('status', 'open')
            ->whereColumn('paid_total', '<', 'grand_total')
            ->with(['stay.reservation.guest'])
            ->limit(50)
            ->get();

        $guestAlerts = HotelReservation::where('created_by', $creatorId)
            ->whereIn('status', [HotelReservation::STATUS_PENDING, HotelReservation::STATUS_CONFIRMED, HotelReservation::STATUS_CHECKED_IN])
            ->whereDate('check_in_date', '>=', $today)
            ->with(['guest', 'room'])
            ->get()
            ->filter(function ($reservation) {
                $guest = $reservation->guest;
                return $guest && (
                    filled($guest->allergies ?? null)
                    || filled($guest->preferences ?? null)
                    || filled($guest->drink_preferences ?? null)
                    || !empty($reservation->parking_requested)
                    || !empty($reservation->estimated_arrival_time)
                );
            })
            ->take(50)
            ->values();

        return view('hotelmanagement::reports.alerts', compact('lateCheckIns', 'unassignedToday', 'cancellations', 'paymentIssues', 'guestAlerts'));
    }

    private function averageStayNights(int $creatorId, Carbon $from, Carbon $to): float
    {
        $rows = HotelReservation::where('created_by', $creatorId)
            ->whereBetween('check_in_date', [$from->toDateString(), $to->toDateString()])
            ->where('status', '!=', HotelReservation::STATUS_CANCELLED)
            ->get(['check_in_date', 'check_out_date']);
        if ($rows->isEmpty()) {
            return 0;
        }
        $total = 0;
        foreach ($rows as $r) {
            $ci = Carbon::parse($r->check_in_date);
            $co = Carbon::parse($r->check_out_date);
            $total += max(1, $ci->diffInDays($co));
        }

        return round($total / $rows->count(), 1);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function immigrationRows(int $creatorId, Carbon $from, Carbon $to): \Illuminate\Support\Collection
    {
        $reservations = HotelReservation::where('created_by', $creatorId)
            ->where('status', '!=', HotelReservation::STATUS_CANCELLED)
            ->whereDate('check_in_date', '<=', $to->toDateString())
            ->whereDate('check_out_date', '>=', $from->toDateString())
            ->with(['guest', 'room', 'group.members.guest'])
            ->orderBy('check_in_date')
            ->orderBy('id')
            ->get();

        $rows = collect();

        foreach ($reservations as $reservation) {
            $roomLabel = $reservation->room?->room_no ?? '—';
            $base = [
                'reservation_no' => $reservation->reservation_no,
                'check_in_date' => $reservation->check_in_date,
                'check_out_date' => $reservation->check_out_date,
                'room' => $roomLabel,
                'status' => $reservation->status,
            ];

            if ($reservation->guest) {
                $rows->push(array_merge($base, $this->guestImmigrationFields($reservation->guest, __('Main guest'))));
            }

            if ($reservation->group) {
                foreach ($reservation->group->members as $member) {
                    if (! $member->guest || (int) $member->hotel_guest_id === (int) $reservation->hotel_guest_id) {
                        continue;
                    }
                    $rows->push(array_merge($base, $this->guestImmigrationFields($member->guest, $member->role)));
                }
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    protected function guestImmigrationFields(HotelGuest $guest, string $role): array
    {
        return [
            'full_name' => $guest->fullName(),
            'nationality' => $guest->nationality ?: '—',
            'id_type' => $guest->id_type ?: '—',
            'id_number' => $guest->id_number ?: '—',
            'birth_date' => $guest->birth_date ?: '—',
            'role' => $role,
        ];
    }

    protected function downloadPdf(string $html, string $fileName, string $orientation = 'portrait')
    {
        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', $orientation);

            return $pdf->download($fileName);
        }

        $shot = Browsershot::html($html)->showBackground()->format('A4')->margins(12, 12, 16, 12);
        if ($orientation === 'landscape') {
            $shot->landscape();
        }
        $pdfBinary = $shot->pdf();

        return response()->streamDownload(static function () use ($pdfBinary): void {
            echo $pdfBinary;
        }, $fileName, ['Content-Type' => 'application/pdf']);
    }
}
