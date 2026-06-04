<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelHousekeepingTask;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Entities\HotelStay;
use Modules\HotelManagement\Http\Controllers\Concerns\BuildsRoomMap;
use Modules\HotelManagement\Services\HotelAuthorization;

class HotelDashboardController extends Controller
{
    use BuildsRoomMap;

    public function index()
    {
        if (! HotelAuthorization::viewDashboard(Auth::user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        $today = Carbon::today();
        $start = Carbon::today()->subDays(6);
        $days = collect(range(0, 6))->map(fn ($i) => $start->copy()->addDays($i));

        $roomsTotal = HotelRoom::where('created_by', $creatorId)->count();
        $roomsAvailable = HotelRoom::where('created_by', $creatorId)->where('status', HotelRoom::STATUS_AVAILABLE)->count();
        $roomsOccupied = HotelRoom::where('created_by', $creatorId)->where('status', HotelRoom::STATUS_OCCUPIED)->count();
        $roomsCleaning = HotelRoom::where('created_by', $creatorId)->where('status', HotelRoom::STATUS_CLEANING)->count();
        $roomsMaintenance = HotelRoom::where('created_by', $creatorId)->where('status', HotelRoom::STATUS_MAINTENANCE)->count();
        $occupancyRate = $roomsTotal > 0 ? round(($roomsOccupied / $roomsTotal) * 100, 1) : 0;

        $overbookingAlerts = HotelReservation::where('created_by', $creatorId)
            ->whereIn('status', [HotelReservation::STATUS_PENDING, HotelReservation::STATUS_CONFIRMED])
            ->whereNull('hotel_room_id')
            ->whereDate('check_in_date', '<=', $today->toDateString())
            ->count();

        $arrivalsByDay = HotelReservation::where('created_by', $creatorId)
            ->whereDate('check_in_date', '>=', $start->toDateString())
            ->selectRaw('DATE(check_in_date) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $departuresByDay = HotelReservation::where('created_by', $creatorId)
            ->whereDate('check_out_date', '>=', $start->toDateString())
            ->selectRaw('DATE(check_out_date) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $revenueByDay = HotelFolio::where('created_by', $creatorId)
            ->whereDate('created_at', '>=', $start->toDateString())
            ->selectRaw('DATE(created_at) as day, SUM(grand_total) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $statusCounts = HotelReservation::where('created_by', $creatorId)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $occupiedSeries = $days->map(function ($d) use ($creatorId) {
            return (int) HotelReservation::where('created_by', $creatorId)
                ->whereNotNull('hotel_room_id')
                ->whereIn('status', [HotelReservation::STATUS_CHECKED_IN, HotelReservation::STATUS_CONFIRMED])
                ->whereDate('check_in_date', '<=', $d->toDateString())
                ->whereDate('check_out_date', '>', $d->toDateString())
                ->distinct('hotel_room_id')
                ->count('hotel_room_id');
        })->values();

        $availableSeries = $occupiedSeries->map(function ($occ) use ($roomsTotal) {
            return max(0, $roomsTotal - (int) $occ);
        })->values();

        $arrivalsSeries = $days->map(fn ($d) => (int) ($arrivalsByDay[$d->toDateString()] ?? 0))->values();
        $departuresSeries = $days->map(fn ($d) => (int) ($departuresByDay[$d->toDateString()] ?? 0))->values();
        $revenueSeries = $days->map(fn ($d) => (float) ($revenueByDay[$d->toDateString()] ?? 0))->values();

        $mapRooms = $this->roomsWithCurrentReservation($creatorId, $today);

        $data = [
            'roomsTotal' => $roomsTotal,
            'roomsAvailable' => $roomsAvailable,
            'roomsOccupied' => $roomsOccupied,
            'roomsCleaning' => $roomsCleaning,
            'roomsMaintenance' => $roomsMaintenance,
            'occupancyRate' => $occupancyRate,
            'overbookingAlerts' => $overbookingAlerts,
            'arrivalsToday' => HotelReservation::where('created_by', $creatorId)->whereDate('check_in_date', $today->toDateString())->count(),
            'departuresToday' => HotelReservation::where('created_by', $creatorId)->whereDate('check_out_date', $today->toDateString())->count(),
            'inHouse' => HotelStay::where('created_by', $creatorId)->where('status', 'in_house')->count(),
            'todayRevenue' => (float) HotelFolio::where('created_by', $creatorId)->whereDate('created_at', $today->toDateString())->sum('grand_total'),
            'labels' => $days->map(fn ($d) => $d->format('d M'))->values(),
            'arrivalsSeries' => $arrivalsSeries,
            'departuresSeries' => $departuresSeries,
            'revenueSeries' => $revenueSeries,
            'occupiedSeries' => $occupiedSeries,
            'availableSeries' => $availableSeries,
            'trends' => [
                'available' => $this->trendVsYesterday($availableSeries->all()),
                'occupied' => $this->trendVsYesterday($occupiedSeries->all()),
                'arrivals' => $this->trendVsYesterday($arrivalsSeries->all()),
                'departures' => $this->trendVsYesterday($departuresSeries->all()),
                'revenue' => $this->trendVsYesterday($revenueSeries->all()),
            ],
            'mapRooms' => $mapRooms,
            'statusLabels' => [__('Pending'), __('Confirmed'), __('Checked In'), __('Checked Out'), __('Cancelled')],
            'statusSeries' => [
                (int) ($statusCounts['pending'] ?? 0),
                (int) ($statusCounts['confirmed'] ?? 0),
                (int) ($statusCounts['checked_in'] ?? 0),
                (int) ($statusCounts['checked_out'] ?? 0),
                (int) ($statusCounts['cancelled'] ?? 0),
            ],
            'smart' => $this->smartInsights($creatorId, $roomsTotal, $occupancyRate, $days, $arrivalsByDay, $departuresByDay),
            'openFolioBalances' => HotelFolio::where('created_by', $creatorId)
                ->where('status', 'open')
                ->whereColumn('paid_total', '<', 'grand_total')
                ->count(),
            'inHouseGuests' => $this->inHouseGuestList($creatorId),
            'alerts' => $this->alertsFeed($creatorId, $today),
            'recentActivity' => $this->recentActivity($creatorId),
            'financial' => $this->financialSummary($creatorId, $today),
            'checkoutUrl' => $this->checkoutQuickLink($creatorId, $today),
        ];

        return view('hotelmanagement::dashboard', compact('data'));
    }

    /**
     * Dashboard "Check-out" shortcut: open checkout for a departing in-house guest, or frontdesk list.
     */
    private function checkoutQuickLink(int $creatorId, Carbon $today): string
    {
        $stay = HotelStay::where('created_by', $creatorId)
            ->where('status', 'in_house')
            ->whereDate('expected_check_out_at', $today->toDateString())
            ->orderBy('expected_check_out_at')
            ->first();

        if (! $stay) {
            $stay = HotelStay::where('created_by', $creatorId)
                ->where('status', 'in_house')
                ->orderByDesc('check_in_at')
                ->first();
        }

        if ($stay?->hotel_reservation_id) {
            return route('hotel.frontdesk.checkout', $stay->hotel_reservation_id);
        }

        return route('hotel.frontdesk.in-house');
    }

    /**
     * Top 8 in-house stays with guest, room and folio info — used by the dashboard mini list.
     */
    private function inHouseGuestList(int $creatorId)
    {
        return HotelStay::where('created_by', $creatorId)
            ->where('status', 'in_house')
            ->with(['reservation.guest', 'reservation.room', 'folio'])
            ->orderByDesc('check_in_at')
            ->limit(8)
            ->get();
    }

    /**
     * Aggregates the alerts feed from existing data:
     *  - Late check-ins (reservations with check-in date <= today, status pending/confirmed, not checked in)
     *  - Rooms ready for cleaning (status = cleaning)
     *  - VIP arrivals today
     *  - Pending payments (open folios with balance)
     *  - Scheduled maintenance (rooms with status = maintenance)
     */
    private function alertsFeed(int $creatorId, Carbon $today): array
    {
        $alerts = [];

        $lateCheckIns = HotelReservation::where('created_by', $creatorId)
            ->whereIn('status', [HotelReservation::STATUS_PENDING, HotelReservation::STATUS_CONFIRMED])
            ->whereDate('check_in_date', '<', $today->toDateString())
            ->with(['guest', 'room'])
            ->orderBy('check_in_date')
            ->limit(5)
            ->get();

        foreach ($lateCheckIns as $r) {
            $name = trim(($r->guest->first_name ?? '') . ' ' . ($r->guest->last_name ?? ''));
            $alerts[] = [
                'kind' => 'late_check_in',
                'level' => 'danger',
                'icon' => 'ti ti-alert-triangle',
                'title' => __('Late check-in'),
                'description' => ($name ?: __('Guest')) . ' — ' . __('Room') . ' ' . ($r->room->room_no ?? '—'),
                'when' => optional($r->check_in_date)->format('d/m/Y') ?: $r->check_in_date,
                'href' => route('hotel.reservations.edit', $r->id),
            ];
        }

        $cleaningRooms = HotelRoom::where('created_by', $creatorId)
            ->where('status', HotelRoom::STATUS_CLEANING)
            ->orderBy('room_no')
            ->limit(5)
            ->get();

        foreach ($cleaningRooms as $room) {
            $alerts[] = [
                'kind' => 'cleaning',
                'level' => 'warning',
                'icon' => 'ti ti-spray',
                'title' => __('Room ready for cleaning'),
                'description' => __('Room') . ' ' . $room->room_no,
                'when' => null,
                'href' => route('hotel.housekeeping.index'),
            ];
        }

        if (Schema::hasTable('hotel_guests')) {
            $vipArrivals = HotelReservation::where('created_by', $creatorId)
                ->whereDate('check_in_date', $today->toDateString())
                ->whereIn('status', [HotelReservation::STATUS_PENDING, HotelReservation::STATUS_CONFIRMED])
                ->whereHas('guest', fn ($q) => $q->where('is_vip', 1))
                ->with(['guest', 'room'])
                ->limit(3)
                ->get();

            foreach ($vipArrivals as $r) {
                $name = trim(($r->guest->first_name ?? '') . ' ' . ($r->guest->last_name ?? ''));
                $alerts[] = [
                    'kind' => 'vip_arrival',
                    'level' => 'info',
                    'icon' => 'ti ti-star-filled',
                    'title' => __('VIP guest arriving today'),
                    'description' => $name . ' — ' . __('Room') . ' ' . ($r->room->room_no ?? __('not assigned')),
                    'when' => __('Today'),
                    'href' => route('hotel.reservations.edit', $r->id),
                ];
            }
        }

        $pendingPayments = HotelFolio::where('created_by', $creatorId)
            ->where('status', 'open')
            ->whereColumn('paid_total', '<', 'grand_total')
            ->with('stay.reservation.guest', 'stay.reservation.room')
            ->orderByDesc('id')
            ->limit(4)
            ->get();

        foreach ($pendingPayments as $f) {
            $guest = $f->stay->reservation->guest ?? null;
            $name = $guest ? trim(($guest->first_name ?? '') . ' ' . ($guest->last_name ?? '')) : __('Guest');
            $room = $f->stay->reservation->room->room_no ?? '—';
            $alerts[] = [
                'kind' => 'pending_payment',
                'level' => 'info',
                'icon' => 'ti ti-credit-card',
                'title' => __('Pending payment'),
                'description' => $name . ' — ' . __('Room') . ' ' . $room . ' · ' . Auth::user()->priceFormat(max(0, $f->grand_total - $f->paid_total)),
                'when' => null,
                'href' => route('hotel.folios.show', $f->id),
            ];
        }

        $maintenanceRooms = HotelRoom::where('created_by', $creatorId)
            ->where('status', HotelRoom::STATUS_MAINTENANCE)
            ->orderBy('room_no')
            ->limit(3)
            ->get();

        foreach ($maintenanceRooms as $room) {
            $alerts[] = [
                'kind' => 'maintenance',
                'level' => 'secondary',
                'icon' => 'ti ti-tool',
                'title' => __('Scheduled maintenance'),
                'description' => __('Room') . ' ' . $room->room_no . ($room->notes ? ' · ' . $room->notes : ''),
                'when' => null,
                'href' => route('hotel.rooms.edit', $room->id),
            ];
        }

        return $alerts;
    }

    /**
     * Recent activity feed assembled from check-ins, check-outs, payments and new reservations.
     */
    private function recentActivity(int $creatorId): array
    {
        $events = [];

        $recentCheckIns = HotelStay::where('created_by', $creatorId)
            ->where('status', 'in_house')
            ->with(['reservation.guest', 'reservation.room'])
            ->orderByDesc('check_in_at')
            ->limit(5)
            ->get();

        foreach ($recentCheckIns as $s) {
            $guest = $s->reservation->guest ?? null;
            $name = $guest ? trim(($guest->first_name ?? '') . ' ' . ($guest->last_name ?? '')) : __('Guest');
            $events[] = [
                'kind' => 'check_in',
                'icon' => 'ti ti-login',
                'level' => 'success',
                'title' => __('Check-in performed'),
                'description' => $name . ' — ' . __('Room') . ' ' . ($s->reservation->room->room_no ?? '—'),
                'when' => $s->check_in_at,
                'sort' => $s->check_in_at,
            ];
        }

        $recentCheckOuts = HotelStay::where('created_by', $creatorId)
            ->where('status', 'checked_out')
            ->whereNotNull('checked_out_at')
            ->with(['reservation.guest', 'reservation.room'])
            ->orderByDesc('checked_out_at')
            ->limit(5)
            ->get();

        foreach ($recentCheckOuts as $s) {
            $guest = $s->reservation->guest ?? null;
            $name = $guest ? trim(($guest->first_name ?? '') . ' ' . ($guest->last_name ?? '')) : __('Guest');
            $events[] = [
                'kind' => 'check_out',
                'icon' => 'ti ti-logout',
                'level' => 'primary',
                'title' => __('Check-out performed'),
                'description' => $name . ' — ' . __('Room') . ' ' . ($s->reservation->room->room_no ?? '—'),
                'when' => $s->checked_out_at,
                'sort' => $s->checked_out_at,
            ];
        }

        if (Schema::hasTable('hotel_folio_payments')) {
            $recentPayments = \DB::table('hotel_folio_payments as p')
                ->leftJoin('hotel_folios as f', 'f.id', '=', 'p.hotel_folio_id')
                ->leftJoin('hotel_stays as s', 's.id', '=', 'f.hotel_stay_id')
                ->leftJoin('hotel_reservations as r', 'r.id', '=', 's.hotel_reservation_id')
                ->leftJoin('hotel_guests as g', 'g.id', '=', 'r.hotel_guest_id')
                ->where('p.created_by', $creatorId)
                ->orderByDesc('p.created_at')
                ->limit(5)
                ->select('p.amount', 'p.method', 'p.created_at', 'g.first_name', 'g.last_name', 'r.hotel_room_id', 'f.id as folio_id')
                ->get();

            foreach ($recentPayments as $p) {
                $name = trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? '')) ?: __('Guest');
                $events[] = [
                    'kind' => 'payment',
                    'icon' => 'ti ti-cash',
                    'level' => 'info',
                    'title' => __('Payment received'),
                    'description' => $name . ' · ' . Auth::user()->priceFormat($p->amount),
                    'when' => $p->created_at,
                    'sort' => $p->created_at,
                ];
            }
        }

        $recentReservations = HotelReservation::where('created_by', $creatorId)
            ->with(['guest', 'group', 'room'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        foreach ($recentReservations as $r) {
            $guest = $r->guest;
            $label = $r->group?->name ? $r->group->name : ($guest ? trim(($guest->first_name ?? '') . ' ' . ($guest->last_name ?? '')) : __('Guest'));
            $events[] = [
                'kind' => 'reservation',
                'icon' => 'ti ti-calendar-plus',
                'level' => 'warning',
                'title' => __('New reservation'),
                'description' => $label . ' — ' . __('Room') . ' ' . ($r->room->room_no ?? __('not assigned')),
                'when' => $r->created_at,
                'sort' => $r->created_at,
            ];
        }

        if (Schema::hasTable('hotel_housekeeping_tasks')) {
            $recentHk = HotelHousekeepingTask::where('created_by', $creatorId)
                ->where('status', '!=', 'done')
                ->with(['room'])
                ->orderByDesc('updated_at')
                ->limit(3)
                ->get();

            foreach ($recentHk as $hk) {
                $events[] = [
                    'kind' => 'housekeeping',
                    'icon' => 'ti ti-spray',
                    'level' => 'secondary',
                    'title' => __('Room needs cleaning'),
                    'description' => __('Room') . ' ' . ($hk->room->room_no ?? '—'),
                    'when' => $hk->updated_at,
                    'sort' => $hk->updated_at,
                ];
            }
        }

        usort($events, function ($a, $b) {
            $tsA = $a['sort'] ? Carbon::parse($a['sort'])->getTimestamp() : 0;
            $tsB = $b['sort'] ? Carbon::parse($b['sort'])->getTimestamp() : 0;
            return $tsB <=> $tsA;
        });

        return array_slice($events, 0, 8);
    }

    /**
     * Returns financial summary used by the right-side card on the dashboard.
     */
    private function financialSummary(int $creatorId, Carbon $today): array
    {
        $todayRev = (float) HotelFolio::where('created_by', $creatorId)
            ->whereDate('created_at', $today->toDateString())
            ->sum('grand_total');

        $yesterdayRev = (float) HotelFolio::where('created_by', $creatorId)
            ->whereDate('created_at', $today->copy()->subDay()->toDateString())
            ->sum('grand_total');

        $pendingFolios = HotelFolio::where('created_by', $creatorId)
            ->where('status', 'open')
            ->whereColumn('paid_total', '<', 'grand_total')
            ->get(['grand_total', 'paid_total']);

        $pendingCount = $pendingFolios->count();
        $pendingAmount = (float) $pendingFolios->sum(fn ($f) => max(0, $f->grand_total - $f->paid_total));

        $totalReceivables = (float) HotelFolio::where('created_by', $creatorId)
            ->whereColumn('paid_total', '<', 'grand_total')
            ->sum(\DB::raw('grand_total - paid_total'));

        $trend = 0.0;
        if ($yesterdayRev > 0.0) {
            $trend = round((($todayRev - $yesterdayRev) / $yesterdayRev) * 100, 1);
        } elseif ($todayRev > 0) {
            $trend = 100.0;
        }

        return [
            'today_revenue' => $todayRev,
            'yesterday_revenue' => $yesterdayRev,
            'today_trend_pct' => $trend,
            'pending_count' => $pendingCount,
            'pending_amount' => $pendingAmount,
            'total_receivables' => $totalReceivables,
        ];
    }

    /**
     * Returns the percent delta of the last value in $series compared to the previous one.
     * Returns 0.0 if the previous value is 0 (avoid div by zero) or the series is too short.
     */
    private function trendVsYesterday(array $series): float
    {
        $n = count($series);
        if ($n < 2) {
            return 0.0;
        }
        $last = (float) $series[$n - 1];
        $prev = (float) $series[$n - 2];
        if ($prev == 0.0) {
            return $last > 0 ? 100.0 : 0.0;
        }
        return round((($last - $prev) / $prev) * 100, 1);
    }

    /**
     * Simple forecast from last 7 days average night-demand vs capacity; pricing hint when occupancy high.
     */
    private function smartInsights(int $creatorId, int $roomsTotal, float $occupancyRate, $days, $arrivalsByDay, $departuresByDay): array
    {
        if ($roomsTotal < 1) {
            return [
                'forecast_occupancy_pct' => 0,
                'low_occupancy' => true,
                'pricing_hint' => __('Add rooms and room types to enable insights.'),
            ];
        }
        $nightDemand = [];
        foreach ($days as $d) {
            $ds = $d->toDateString();
            $in = (int) ($arrivalsByDay[$ds] ?? 0);
            $out = (int) ($departuresByDay[$ds] ?? 0);
            $nightDemand[] = max(0, $in - $out + $roomsTotal * ($occupancyRate / 100));
        }
        $avgDemand = count($nightDemand) ? array_sum($nightDemand) / count($nightDemand) : 0;
        $forecastPct = round(min(100, ($avgDemand / $roomsTotal) * 100), 1);
        $low = $forecastPct < 30;

        $pricing = $occupancyRate >= 70
            ? __('High occupancy — consider increasing BAR / rate by ~10% on peak dates.')
            : ($occupancyRate <= 35
                ? __('Lower occupancy — promotions or OTA visibility may help.')
                : __('Rates look balanced for current demand.'));

        return [
            'forecast_occupancy_pct' => $forecastPct,
            'low_occupancy' => $low,
            'pricing_hint' => $pricing,
        ];
    }
}
