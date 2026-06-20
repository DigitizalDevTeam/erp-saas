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
use Modules\HotelManagement\Services\HotelHousekeepingService;
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
        private readonly HotelHousekeepingService $housekeepingService,
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

    public function index(Request $request)
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $showDone = $request->boolean('show_done');

        $query = HotelHousekeepingTask::where('created_by', $creatorId)
            ->with(['room.floor', 'room.roomType', 'assignee', 'completedByUser']);

        if (! $showDone) {
            $query->whereIn('status', ['pending', 'in_progress']);
        }

        $tasks = $query
            ->orderByRaw("FIELD(status, 'pending', 'in_progress', 'done')")
            ->orderByDesc('id')
            ->get();

        $stats = [
            'pending' => HotelHousekeepingTask::where('created_by', $creatorId)->where('status', 'pending')->count(),
            'in_progress' => HotelHousekeepingTask::where('created_by', $creatorId)->where('status', 'in_progress')->count(),
            'dirty_rooms' => HotelRoom::where('created_by', $creatorId)->where('status', HotelRoom::STATUS_DIRTY)->count(),
        ];

        return view('hotelmanagement::housekeeping.index', [
            'tasks' => $tasks,
            'canManage' => $this->canManage(),
            'showDone' => $showDone,
            'stats' => $stats,
        ]);
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

        $creatorId = (int) Auth::user()->creatorId();
        $room = HotelRoom::where('id', $request->hotel_room_id)
            ->where('created_by', $creatorId)
            ->firstOrFail();

        try {
            if ($request->status === 'done') {
                $task = $this->housekeepingService->queueRoom($room, $creatorId, HotelHousekeepingService::SOURCE_MANUAL, $request->notes);
                if (! $task) {
                    $task = HotelHousekeepingTask::query()
                        ->where('created_by', $creatorId)
                        ->where('hotel_room_id', $room->id)
                        ->whereIn('status', ['pending', 'in_progress'])
                        ->first();
                }
                if ($task) {
                    if ($task->status === 'pending') {
                        $this->housekeepingService->startCleaning($task, $creatorId, $request->assigned_to ?: null);
                        $task->refresh();
                    }
                    $this->housekeepingService->completeCleaning($task, $creatorId, $request->assigned_to ?: null);
                }
            } elseif ($request->status === 'in_progress') {
                $task = $this->housekeepingService->queueRoom($room, $creatorId, HotelHousekeepingService::SOURCE_MANUAL, $request->notes);
                if (! $task) {
                    $task = HotelHousekeepingTask::query()
                        ->where('created_by', $creatorId)
                        ->where('hotel_room_id', $room->id)
                        ->where('status', 'pending')
                        ->first();
                }
                if ($task) {
                    if ($request->assigned_to) {
                        $task->update(['assigned_to' => $request->assigned_to]);
                    }
                    $this->housekeepingService->startCleaning($task, $creatorId, $request->assigned_to ?: null);
                }
            } else {
                $task = $this->housekeepingService->queueRoom($room, $creatorId, HotelHousekeepingService::SOURCE_MANUAL, $request->notes);
                if ($task && $request->assigned_to) {
                    $task->update(['assigned_to' => $request->assigned_to]);
                }
            }
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('hotel.housekeeping.index')->with('success', __('Housekeeping task created.'));
    }

    public function edit(HotelHousekeepingTask $housekeeping)
    {
        if ((! $this->canManage()) || (int) $housekeeping->created_by !== (int) Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }
        if ($housekeeping->status === 'done') {
            return redirect()->route('hotel.housekeeping.index')->with('error', __('Completed tasks cannot be edited.'));
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
        if ($housekeeping->status === 'done') {
            return redirect()->route('hotel.housekeeping.index')->with('error', __('Completed tasks cannot be edited.'));
        }

        $validator = \Validator::make($request->all(), [
            'assigned_to' => 'nullable|exists:users,id',
            'notes' => 'nullable|string|max:1000',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        $housekeeping->update([
            'assigned_to' => $request->assigned_to ?: null,
            'notes' => $request->notes,
        ]);

        return redirect()->route('hotel.housekeeping.index')->with('success', __('Housekeeping task updated.'));
    }

    public function executeAction(Request $request, HotelHousekeepingTask $housekeeping)
    {
        if (! $this->canManage() || (int) $housekeeping->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'action' => 'required|in:start,complete,maintenance',
            'notes' => 'nullable|string|max:1000',
        ]);

        $creatorId = (int) Auth::user()->creatorId();
        $userId = (int) Auth::id();

        try {
            match ($request->action) {
                'start' => $this->housekeepingService->startCleaning($housekeeping, $creatorId, $userId),
                'complete' => $this->housekeepingService->completeCleaning($housekeeping, $creatorId, $userId),
                'maintenance' => $this->housekeepingService->sendToMaintenance(
                    $housekeeping,
                    $creatorId,
                    $request->notes,
                    $userId
                ),
            };
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $messages = [
            'start' => __('Cleaning started.'),
            'complete' => __('Room marked clean and ready.'),
            'maintenance' => __('Room sent to maintenance.'),
        ];

        return redirect()->route('hotel.housekeeping.index')->with('success', $messages[$request->action] ?? __('Updated.'));
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
