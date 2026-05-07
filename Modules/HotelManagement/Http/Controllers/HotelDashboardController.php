<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Entities\HotelStay;
use Modules\HotelManagement\Http\Controllers\Concerns\BuildsRoomMap;

class HotelDashboardController extends Controller
{
    use BuildsRoomMap;

    public function index()
    {
        if (!Auth::user()->can('show hotel dashboard') && !Auth::user()->can('manage hotel')) {
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
            'statusLabels' => ['Pending', 'Confirmed', 'Checked In', 'Checked Out', 'Cancelled'],
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
        ];

        return view('hotelmanagement::dashboard', compact('data'));
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
