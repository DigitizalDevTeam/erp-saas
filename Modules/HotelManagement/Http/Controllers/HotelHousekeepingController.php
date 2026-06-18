<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\HotelManagement\Entities\HotelHousekeepingTask;
use Modules\HotelManagement\Services\HotelAuthorization;
use Modules\HotelManagement\Entities\HotelRoom;
use Modules\HotelManagement\Entities\HotelRoomConsumptionLog;
use Modules\HotelManagement\Entities\HotelStay;
use Modules\HotelManagement\Entities\HotelRoomMinibarRestock;
use Modules\HotelManagement\Services\HotelMinibarRestockService;
use Modules\HotelManagement\Services\HotelRoomConsumptionService;
use Modules\HotelManagement\Services\HotelRoomStatusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HotelHousekeepingController extends Controller
{
    public function __construct(
        private readonly HotelRoomConsumptionService $consumptionService,
        private readonly HotelMinibarRestockService $restockService,
        private readonly HotelRoomStatusService $roomStatusService,
    ) {
    }

    private function canView(): bool
    {
        return HotelAuthorization::viewHousekeeping(Auth::user());
    }

    private function canManage(): bool
    {
        return HotelAuthorization::manageHousekeeping(Auth::user());
    }

    public function index()
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $tasks = HotelHousekeepingTask::where('created_by', Auth::user()->creatorId())
            ->with(['room', 'assignee'])
            ->orderByRaw("FIELD(status, 'pending', 'in_progress', 'done')")
            ->orderByDesc('id')
            ->get();
        $canManage = $this->canManage();
        return view('hotelmanagement::housekeeping.index', compact('tasks', 'canManage'));
    }

    public function create()
    {
        if (! $this->canManage()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
        $rooms = HotelRoom::where('created_by', Auth::user()->creatorId())->orderBy('room_no')->pluck('room_no', 'id');
        $staff = User::where('created_by', Auth::user()->creatorId())->orderBy('name')->pluck('name', 'id');
        return view('hotelmanagement::housekeeping.create', compact('rooms', 'staff'));
    }

    public function store(Request $request)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $validator = \Validator::make($request->all(), [
            'hotel_room_id' => 'required|exists:hotel_rooms,id',
            'assigned_to' => 'nullable|exists:users,id',
            'status' => 'required|in:pending,in_progress,done',
            'notes' => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        HotelHousekeepingTask::create([
            'hotel_room_id' => $request->hotel_room_id,
            'assigned_to' => $request->assigned_to ?: null,
            'status' => $request->status,
            'notes' => $request->notes,
            'created_by' => Auth::user()->creatorId(),
        ]);

        $room = HotelRoom::where('id', $request->hotel_room_id)
            ->where('created_by', Auth::user()->creatorId())
            ->first();
        if ($room) {
            if ($request->status === 'done') {
                try {
                    $this->roomStatusService->markRoomReady($room, (int) Auth::user()->creatorId());
                } catch (\InvalidArgumentException) {
                    // Room may still need minibar restock; stays in cleaning.
                }
            } else {
                $this->roomStatusService->markCleaning($room);
            }
        }

        return redirect()->route('hotel.housekeeping.index')->with('success', __('Housekeeping task created.'));
    }

    public function edit(HotelHousekeepingTask $housekeeping)
    {
        if ((! $this->canManage()) || (int) $housekeeping->created_by !== (int) Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
        $rooms = HotelRoom::where('created_by', Auth::user()->creatorId())->orderBy('room_no')->pluck('room_no', 'id');
        $staff = User::where('created_by', Auth::user()->creatorId())->orderBy('name')->pluck('name', 'id');
        return view('hotelmanagement::housekeeping.edit', compact('housekeeping', 'rooms', 'staff'));
    }

    public function update(Request $request, HotelHousekeepingTask $housekeeping)
    {
        if ((! $this->canManage()) || (int) $housekeeping->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $validator = \Validator::make($request->all(), [
            'hotel_room_id' => 'required|exists:hotel_rooms,id',
            'assigned_to' => 'nullable|exists:users,id',
            'status' => 'required|in:pending,in_progress,done',
            'notes' => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }
        $housekeeping->update([
            'hotel_room_id' => $request->hotel_room_id,
            'assigned_to' => $request->assigned_to ?: null,
            'status' => $request->status,
            'notes' => $request->notes,
        ]);
        $room = HotelRoom::where('id', $request->hotel_room_id)
            ->where('created_by', Auth::user()->creatorId())
            ->first();
        if ($room) {
            if ($request->status === 'done') {
                try {
                    $this->roomStatusService->markRoomReady($room, (int) Auth::user()->creatorId());
                } catch (\InvalidArgumentException) {
                    // Room may still need minibar restock; stays in cleaning.
                }
            } else {
                $this->roomStatusService->markCleaning($room);
            }
        }

        return redirect()->route('hotel.housekeeping.index')->with('success', __('Housekeeping task updated.'));
    }

    /**
     * Daily minibar / consumption update for occupied rooms.
     */
    public function minibar()
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $today = now()->toDateString();

        $occupiedStays = HotelStay::query()
            ->where('created_by', $creatorId)
            ->where('status', 'in_house')
            ->with(['reservation.guest', 'reservation.room', 'folio'])
            ->get()
            ->sortBy(fn ($stay) => $stay->reservation->room->room_no ?? '');

        $todayLogs = collect();
        if (Schema::hasTable('hotel_room_consumption_logs')) {
            $todayLogs = HotelRoomConsumptionLog::query()
                ->where('created_by', $creatorId)
                ->whereDate('service_date', $today)
                ->with('room')
                ->orderByDesc('id')
                ->limit(50)
                ->get();
        }

        $products = $this->consumptionService->consumableProducts($creatorId);

        $pendingRestocks = $this->restockService->pendingRestocks($creatorId);

        return view('hotelmanagement::housekeeping.minibar', [
            'occupiedStays' => $occupiedStays,
            'todayLogs' => $todayLogs,
            'products' => $products,
            'pendingRestocks' => $pendingRestocks,
            'canManage' => $this->canManage(),
            'today' => $today,
        ]);
    }

    public function completeRestock(Request $request, HotelRoomMinibarRestock $restock)
    {
        if (! $this->canManage() || (int) $restock->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ($restock->status !== HotelRoomMinibarRestock::STATUS_PENDING) {
            return redirect()->back()->with('error', __('Restock already completed.'));
        }

        $request->validate(['notes' => 'nullable|string|max:500']);

        $this->restockService->completeRestock($restock, $request->notes);

        return redirect()->route('hotel.housekeeping.minibar')->with('success', __('Minibar restock marked complete.'));
    }

    public function storeMinibar(Request $request)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'hotel_room_id' => 'required|exists:hotel_rooms,id',
            'charge_type' => 'required|in:minibar,laundry,misc',
            'description' => 'nullable|string|max:191',
            'quantity' => 'nullable|integer|min:1',
            'unit_price' => 'nullable|numeric|min:0',
            'service_date' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
            'product_id' => 'required_if:charge_type,minibar|nullable|integer',
            'warehouse_id' => 'required_if:charge_type,minibar|nullable|integer',
        ]);

        $room = HotelRoom::where('id', $request->hotel_room_id)
            ->where('created_by', Auth::user()->creatorId())
            ->firstOrFail();

        try {
            DB::transaction(function () use ($request, $room) {
                $this->consumptionService->postConsumption(
                    $room,
                    (string) $request->charge_type,
                    [
                        'description' => $request->description,
                        'quantity' => $request->quantity,
                        'unit_price' => $request->unit_price,
                        'product_id' => $request->product_id,
                        'warehouse_id' => $request->warehouse_id,
                        'service_date' => $request->service_date ?? now()->toDateString(),
                        'notes' => $request->notes,
                    ],
                    (int) Auth::user()->creatorId()
                );
            });
        } catch (\RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('hotel.housekeeping.minibar')->with('success', __('Consumption posted to guest room bill.'));
    }
}
