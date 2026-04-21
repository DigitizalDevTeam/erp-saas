<?php

namespace App\Http\Controllers;

use App\Models\HotelReservation;
use App\Models\HotelRoom;
use App\Models\HotelStay;
use App\Models\HotelFolio;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class HotelDashboardController extends Controller
{
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
        $occupancyRate = $roomsTotal > 0 ? round(($roomsOccupied / $roomsTotal) * 100, 1) : 0;

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

        $data = [
            'roomsTotal' => $roomsTotal,
            'roomsAvailable' => $roomsAvailable,
            'roomsOccupied' => $roomsOccupied,
            'occupancyRate' => $occupancyRate,
            'arrivalsToday' => HotelReservation::where('created_by', $creatorId)->whereDate('check_in_date', $today->toDateString())->count(),
            'departuresToday' => HotelReservation::where('created_by', $creatorId)->whereDate('check_out_date', $today->toDateString())->count(),
            'inHouse' => HotelStay::where('created_by', $creatorId)->where('status', 'in_house')->count(),
            'todayRevenue' => (float) HotelFolio::where('created_by', $creatorId)->whereDate('created_at', $today->toDateString())->sum('grand_total'),
            'labels' => $days->map(fn ($d) => $d->format('d M'))->values(),
            'arrivalsSeries' => $days->map(fn ($d) => (int) ($arrivalsByDay[$d->toDateString()] ?? 0))->values(),
            'departuresSeries' => $days->map(fn ($d) => (int) ($departuresByDay[$d->toDateString()] ?? 0))->values(),
            'revenueSeries' => $days->map(fn ($d) => (float) ($revenueByDay[$d->toDateString()] ?? 0))->values(),
            'statusLabels' => ['Pending', 'Confirmed', 'Checked In', 'Checked Out', 'Cancelled'],
            'statusSeries' => [
                (int) ($statusCounts['pending'] ?? 0),
                (int) ($statusCounts['confirmed'] ?? 0),
                (int) ($statusCounts['checked_in'] ?? 0),
                (int) ($statusCounts['checked_out'] ?? 0),
                (int) ($statusCounts['cancelled'] ?? 0),
            ],
        ];

        return view('hotel.dashboard', compact('data'));
    }
}
