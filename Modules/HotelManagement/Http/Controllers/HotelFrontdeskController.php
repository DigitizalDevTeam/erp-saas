<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelFolioLine;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Entities\HotelStay;

class HotelFrontdeskController extends Controller
{
    public function inHouse()
    {
        if (!Auth::user()->can('manage hotel frontdesk') && !Auth::user()->can('manage hotel')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $stays = HotelStay::where('created_by', Auth::user()->creatorId())
            ->with(['reservation.guest', 'reservation.room', 'folio.payments'])
            ->orderByDesc('id')
            ->get();
        return view('hotelmanagement::frontdesk.in_house', compact('stays'));
    }

    public function checkIn(HotelReservation $reservation)
    {
        if ((!Auth::user()->can('manage hotel frontdesk') && !Auth::user()->can('manage hotel')) || (int) $reservation->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if (!$reservation->hotel_room_id) {
            return redirect()->back()->with('error', __('Assign room before check-in.'));
        }
        if ($reservation->status === HotelReservation::STATUS_CHECKED_IN) {
            return redirect()->back()->with('error', __('Reservation is already checked in.'));
        }

        DB::transaction(function () use ($reservation) {
            $stay = HotelStay::create([
                'hotel_reservation_id' => $reservation->id,
                'hotel_guest_id' => $reservation->hotel_guest_id,
                'hotel_room_id' => $reservation->hotel_room_id,
                'check_in_at' => now(),
                'expected_check_out_at' => $reservation->check_out_at ?: ($reservation->check_out_date . ' 12:00:00'),
                'status' => 'in_house',
                'created_by' => Auth::user()->creatorId(),
            ]);

            $checkInAt = \Carbon\Carbon::parse($reservation->check_in_at ?: ($reservation->check_in_date . ' 12:00:00'));
            $checkOutAt = \Carbon\Carbon::parse($reservation->check_out_at ?: ($reservation->check_out_date . ' 12:00:00'));
            $hours = max(1, $checkInAt->diffInHours($checkOutAt));
            $nights = max(1, (int) ceil($hours / 24));
            $roomTotal = ($reservation->rate_per_night * $nights) - $reservation->discount_amount;
            $folio = HotelFolio::create([
                'hotel_stay_id' => $stay->id,
                'folio_no' => 'FOL-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
                'sub_total' => $roomTotal,
                'grand_total' => $roomTotal,
                'created_by' => Auth::user()->creatorId(),
            ]);

            HotelFolioLine::create([
                'hotel_folio_id' => $folio->id,
                'line_type' => 'room_charge',
                'description' => 'Room charge (' . $nights . ' night(s))',
                'quantity' => $nights,
                'unit_price' => $reservation->rate_per_night,
                'discount_amount' => $reservation->discount_amount,
                'line_total' => $roomTotal,
                'created_by' => Auth::user()->creatorId(),
            ]);

            $reservation->update(['status' => HotelReservation::STATUS_CHECKED_IN]);
            HotelRoom::where('id', $reservation->hotel_room_id)->update(['status' => HotelRoom::STATUS_OCCUPIED]);
        });

        return redirect()->route('hotel.frontdesk.in-house')->with('success', __('Guest checked in successfully.'));
    }

    public function checkOut(HotelStay $stay)
    {
        if ((!Auth::user()->can('manage hotel frontdesk') && !Auth::user()->can('manage hotel')) || (int) $stay->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ($stay->status === 'checked_out') {
            return redirect()->back()->with('error', __('Stay already checked out.'));
        }

        DB::transaction(function () use ($stay) {
            $stay->update([
                'checked_out_at' => now(),
                'status' => 'checked_out',
            ]);

            $reservation = $stay->reservation;
            if ($reservation) {
                $reservation->update(['status' => HotelReservation::STATUS_CHECKED_OUT]);
            }

            HotelRoom::where('id', $stay->hotel_room_id)->update(['status' => HotelRoom::STATUS_CLEANING]);
            $stay->folio()->update(['status' => 'settled']);
        });

        return redirect()->route('hotel.frontdesk.in-house')->with('success', __('Guest checked out successfully.'));
    }
}
