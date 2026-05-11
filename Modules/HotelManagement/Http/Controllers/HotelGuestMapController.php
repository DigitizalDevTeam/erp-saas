<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelGuest;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelStay;
use Modules\HotelManagement\Http\Controllers\Concerns\BuildsRoomMap;

class HotelGuestMapController extends Controller
{
    use BuildsRoomMap;

    public function index()
    {
        if (
            !Auth::user()->can('manage hotel')
            && !Auth::user()->can('manage hotel reservation')
            && !Auth::user()->can('manage hotel frontdesk')
            && !Auth::user()->can('show hotel reservation')
            && !Auth::user()->can('show hotel frontdesk')
        ) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $today = Carbon::today();

        $rooms = $this->roomsWithCurrentReservation($creatorId, $today);

        $inHouseQuery = HotelStay::where('created_by', $creatorId)
            ->where('status', 'in_house')
            ->with(['reservation.guest', 'reservation.room']);
        $inHouseCount = (clone $inHouseQuery)->count();
        $inHouseList = (clone $inHouseQuery)->orderByDesc('check_in_at')->limit(10)->get();

        $arrivalsToday = HotelReservation::where('created_by', $creatorId)
            ->whereDate('check_in_date', $today->toDateString())
            ->count();
        $departuresToday = HotelReservation::where('created_by', $creatorId)
            ->whereDate('check_out_date', $today->toDateString())
            ->count();

        $vipInHouse = 0;
        $totalAdults = 0;
        $totalChildren = 0;
        $nationalitiesCount = 0;
        $vipTotal = 0;

        if (Schema::hasTable('hotel_guests')) {
            $vipTotal = HotelGuest::where('created_by', $creatorId)->where('is_vip', 1)->count();

            $vipInHouse = HotelStay::where('hotel_stays.created_by', $creatorId)
                ->where('hotel_stays.status', 'in_house')
                ->join('hotel_guests', 'hotel_guests.id', '=', 'hotel_stays.hotel_guest_id')
                ->where('hotel_guests.is_vip', 1)
                ->count();

            $nationalitiesCount = HotelGuest::where('created_by', $creatorId)
                ->whereNotNull('nationality')
                ->where('nationality', '!=', '')
                ->distinct('nationality')
                ->count('nationality');
        }

        $inHouseStaysWithRes = HotelStay::where('created_by', $creatorId)
            ->where('status', 'in_house')
            ->with('reservation')
            ->get();
        foreach ($inHouseStaysWithRes as $s) {
            $totalAdults += (int) ($s->reservation->adults ?? 0);
            $totalChildren += (int) ($s->reservation->children ?? 0);
        }

        $kpis = [
            'in_house' => $inHouseCount,
            'arrivals_today' => $arrivalsToday,
            'departures_today' => $departuresToday,
            'vip_in_house' => $vipInHouse,
            'vip_total' => $vipTotal,
            'nationalities' => $nationalitiesCount,
        ];

        $trendArrivals = $this->trendVsYesterday($creatorId, 'check_in_date', $today);
        $trendDepartures = $this->trendVsYesterday($creatorId, 'check_out_date', $today);

        $stats = [
            'total' => $inHouseCount,
            'adults' => $totalAdults,
            'children' => $totalChildren,
            'vips' => $vipInHouse,
            'nationalities' => $nationalitiesCount,
        ];

        return view('hotelmanagement::guests.map', compact('rooms', 'kpis', 'inHouseList', 'stats', 'trendArrivals', 'trendDepartures'));
    }

    private function trendVsYesterday(int $creatorId, string $dateColumn, Carbon $today): float
    {
        $yesterday = (int) HotelReservation::where('created_by', $creatorId)
            ->whereDate($dateColumn, $today->copy()->subDay()->toDateString())
            ->count();
        $current = (int) HotelReservation::where('created_by', $creatorId)
            ->whereDate($dateColumn, $today->toDateString())
            ->count();
        if ($yesterday === 0) {
            return $current > 0 ? 100.0 : 0.0;
        }
        return round((($current - $yesterday) / $yesterday) * 100, 1);
    }
}
