<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Services\HotelAuthorization;
use Modules\HotelManagement\Services\HotelRoomConsumptionService;

class HotelRoomConsumptionController extends Controller
{
    public function __construct(private readonly HotelRoomConsumptionService $consumptionService)
    {
    }

    private function canManage(): bool
    {
        return HotelAuthorization::manageRoomConsumption(Auth::user());
    }

    public function create(HotelRoom $room)
    {
        if (! $this->canManage() || (int) $room->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $stay = $this->consumptionService->activeStayForRoom($room);
        if (! $stay || ! $stay->folio) {
            return redirect()->back()->with('error', __('No in-house guest folio for this room.'));
        }

        $minibarItems = $this->consumptionService->minibarCatalog((int) Auth::user()->creatorId());

        return view('hotelmanagement::rooms.consumption', [
            'room' => $room,
            'stay' => $stay,
            'folio' => $stay->folio,
            'minibarItems' => $minibarItems,
            'chargeTypes' => $this->chargeTypeOptions(),
        ]);
    }

    public function store(Request $request, HotelRoom $room)
    {
        if (! $this->canManage() || (int) $room->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'charge_type' => 'required|in:minibar,laundry,misc',
            'description' => 'nullable|string|max:191',
            'quantity' => 'nullable|integer|min:1',
            'unit_price' => 'required|numeric|min:0',
            'service_date' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
            'minibar_item_id' => 'nullable|integer',
        ]);

        try {
            DB::transaction(function () use ($request, $room) {
                $description = $request->description;
                if ($request->filled('minibar_item_id') && $request->charge_type === 'minibar') {
                    $item = $this->consumptionService->minibarCatalog((int) Auth::user()->creatorId())
                        ->firstWhere('id', (int) $request->minibar_item_id);
                    if ($item) {
                        $description = $item->name;
                        if (! $request->filled('unit_price')) {
                            $request->merge(['unit_price' => $item->unit_price]);
                        }
                    }
                }

                $this->consumptionService->postConsumption(
                    $room,
                    (string) $request->charge_type,
                    [
                        'description' => $description,
                        'quantity' => $request->quantity,
                        'unit_price' => $request->unit_price,
                        'service_date' => $request->service_date,
                        'notes' => $request->notes,
                    ],
                    (int) Auth::user()->creatorId()
                );
            });
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $redirect = $request->input('redirect_to');
        if ($redirect === 'folio' && $stay = $this->consumptionService->activeStayForRoom($room)) {
            return redirect()->route('hotel.folios.show', $stay->folio->id)
                ->with('success', __('Charge posted to room bill.'));
        }

        return redirect()->route('hotel.rooms.map')->with('success', __('Charge posted to room bill.'));
    }

    /**
     * @return array<string, string>
     */
    private function chargeTypeOptions(): array
    {
        return [
            HotelRoomConsumptionService::CHARGE_MINIBAR => __('Minibar'),
            HotelRoomConsumptionService::CHARGE_LAUNDRY => __('Laundry'),
            HotelRoomConsumptionService::CHARGE_MISC => __('Other extra'),
        ];
    }
}
