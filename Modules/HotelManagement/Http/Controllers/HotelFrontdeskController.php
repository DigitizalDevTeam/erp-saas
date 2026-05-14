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
use Modules\HotelManagement\Http\Controllers\Concerns\BuildsRoomMap;
use Modules\HotelManagement\Services\HotelReservationPricingService;

class HotelFrontdeskController extends Controller
{
    use BuildsRoomMap;

    public function __construct(private readonly HotelReservationPricingService $pricingService)
    {
    }

    private function canView(): bool
    {
        return Auth::user()->can('show hotel frontdesk') || Auth::user()->can('manage hotel frontdesk') || Auth::user()->can('manage hotel');
    }

    private function canManage(): bool
    {
        return Auth::user()->can('manage hotel frontdesk') || Auth::user()->can('manage hotel');
    }

    public function inHouse()
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $stays = HotelStay::where('created_by', Auth::user()->creatorId())
            ->with(['reservation.guest', 'reservation.room', 'folio.payments'])
            ->orderByDesc('id')
            ->get();
        $mapRooms = $this->roomsWithCurrentReservation((int) Auth::user()->creatorId());
        $canManage = $this->canManage();
        $canForceCheckout = Auth::user()->can('force hotel checkout') || Auth::user()->can('manage hotel');

        return view('hotelmanagement::frontdesk.in_house', compact('stays', 'mapRooms', 'canManage', 'canForceCheckout'));
    }

    public function checkIn(HotelReservation $reservation)
    {
        if ((! $this->canManage()) || (int) $reservation->created_by !== (int) Auth::user()->creatorId()) {
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

            $totals = $this->pricingService->reservationTotals($reservation);
            $unitLabel = strtolower($totals['unit_label']);
            $folio = HotelFolio::create([
                'hotel_stay_id' => $stay->id,
                'folio_no' => 'FOL-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
                'sub_total' => $totals['sub_total'],
                'grand_total' => $totals['grand_total'],
                'created_by' => Auth::user()->creatorId(),
            ]);

            HotelFolioLine::create([
                'hotel_folio_id' => $folio->id,
                'line_type' => 'room_charge',
                'description' => ucfirst($unitLabel) . ' charge (' . $totals['units'] . ' ' . $unitLabel . ')',
                'quantity' => $totals['units'],
                'unit_price' => $reservation->rate_per_night,
                'discount_amount' => $reservation->discount_amount,
                'line_total' => $totals['grand_total'],
                'created_by' => Auth::user()->creatorId(),
            ]);

            $reservation->update(['status' => HotelReservation::STATUS_CHECKED_IN]);
            HotelRoom::where('id', $reservation->hotel_room_id)->update(['status' => HotelRoom::STATUS_OCCUPIED]);
        });

        return redirect()->route('hotel.frontdesk.in-house')->with('success', __('Guest checked in successfully.'));
    }

    public function checkOut(HotelStay $stay)
    {
        if ((! $this->canManage()) || (int) $stay->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if ($stay->status === 'checked_out') {
            return redirect()->back()->with('error', __('Stay already checked out.'));
        }

        $folio = $stay->folio;
        if ($folio) {
            $folio->refresh();
            $paid = $folio->paymentStatusLabel();
            if ($paid !== 'paid') {
                $canForce = Auth::user()->can('force hotel checkout') || Auth::user()->can('manage hotel');
                if (! $canForce) {
                    return redirect()->back()->with(
                        'error',
                        __('Settle room bill before checkout')
                    )->with('warning_folio_id', $folio->id);
                }
            }
        }

        $forcedCheckout = $folio && $folio->paymentStatusLabel() !== 'paid';

        DB::transaction(function () use ($stay, $folio, $forcedCheckout) {
            $stay->update([
                'checked_out_at' => now(),
                'status' => 'checked_out',
            ]);

            $reservation = $stay->reservation;
            if ($reservation) {
                $reservation->update(['status' => HotelReservation::STATUS_CHECKED_OUT]);
            }

            HotelRoom::where('id', $stay->hotel_room_id)->update(['status' => HotelRoom::STATUS_CLEANING]);

            if ($folio) {
                $folio->refresh();
                if ($folio->paymentStatusLabel() === 'paid') {
                    $folio->update(['status' => 'settled']);
                }
                // Forced checkout with balance: leave folio open until fully paid.
            }
        });

        $msg = __('Guest checked out successfully.');
        if ($forcedCheckout) {
            $msg .= ' ' . __('Guest checked out balance note');
        }

        return redirect()->route('hotel.frontdesk.in-house')->with('success', $msg);
    }
}
