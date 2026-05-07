<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\HotelManagement\Entities\HotelFolio;
use Modules\HotelManagement\Entities\HotelStay;
use Modules\HotelManagement\Services\HotelPosChargeService;

class HotelPosIntegrationController extends Controller
{
    public function inHouseGuests(Request $request)
    {
        if (!Auth::user()->canManagePos() && !Auth::user()->can('manage hotel frontdesk') && !Auth::user()->can('manage hotel')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $q = trim((string) $request->get('q', ''));
        $stays = HotelStay::where('created_by', Auth::user()->creatorId())
            ->where('status', 'in_house')
            ->with(['reservation.guest', 'reservation.room', 'folio'])
            ->when($q !== '', function ($query) use ($q) {
                $query->whereHas('reservation.guest', function ($guestQuery) use ($q) {
                    $guestQuery->where('first_name', 'like', '%' . $q . '%')
                        ->orWhere('last_name', 'like', '%' . $q . '%');
                })->orWhereHas('reservation.room', function ($roomQuery) use ($q) {
                    $roomQuery->where('room_no', 'like', '%' . $q . '%');
                });
            })
            ->limit(30)
            ->get();

        return response()->json($stays->map(function ($stay) {
            return [
                'stay_id' => $stay->id,
                'folio_id' => $stay->folio?->id,
                'folio_no' => $stay->folio?->folio_no,
                'room_no' => $stay->reservation?->room?->room_no,
                'guest_name' => trim(($stay->reservation?->guest?->first_name ?? '') . ' ' . ($stay->reservation?->guest?->last_name ?? '')),
            ];
        }));
    }

    public function charge(Request $request, HotelPosChargeService $service)
    {
        if (!Auth::user()->canManagePos() && !Auth::user()->can('manage hotel frontdesk') && !Auth::user()->can('manage hotel')) {
            return response()->json(['error' => __('Permission denied.')], 403);
        }

        $validator = \Validator::make($request->all(), [
            'hotel_folio_id' => 'required|exists:hotel_folios,id',
            'description' => 'required|string|max:191',
            'quantity' => 'nullable|integer|min:1',
            'unit_price' => 'required|numeric|min:0',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'source_pos_id' => 'nullable|integer',
        ]);
        if ($validator->fails()) {
            return response()->json(['error' => $validator->getMessageBag()->first()], 422);
        }

        $folio = HotelFolio::where('id', $request->hotel_folio_id)
            ->where('created_by', Auth::user()->creatorId())
            ->firstOrFail();

        $line = $service->chargeToFolio($folio, $request->all(), (int) Auth::user()->creatorId());

        return response()->json([
            'success' => true,
            'line_id' => $line->id,
            'folio_grand_total' => $folio->fresh()->grand_total,
        ]);
    }
}
