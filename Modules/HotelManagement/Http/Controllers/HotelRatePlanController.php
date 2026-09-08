<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\HotelManagement\Entities\HotelRatePlan;
use Modules\HotelManagement\Entities\HotelRoomType;
use Modules\HotelManagement\Services\HotelAuthorization;

class HotelRatePlanController extends Controller
{
    private function canView(): bool
    {
        return HotelAuthorization::viewRooms(Auth::user());
    }

    private function canManage(): bool
    {
        return HotelAuthorization::manageRooms(Auth::user());
    }

    private function roomTypeOptions()
    {
        return HotelRoomType::where('created_by', Auth::user()->creatorId())
            ->orderBy('name')
            ->pluck('name', 'id');
    }

    public function index()
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $ratePlans = HotelRatePlan::where('created_by', Auth::user()->creatorId())
            ->with('roomType')
            ->orderByRaw("CASE WHEN plan_type = 'promotional' THEN 0 ELSE 1 END")
            ->orderBy('priority')
            ->orderBy('name')
            ->get();

        return view('hotelmanagement::rate_plans.index', compact('ratePlans'));
    }

    public function create()
    {
        if (! $this->canManage()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        $roomTypes = $this->roomTypeOptions();

        return view('hotelmanagement::rate_plans.create', compact('roomTypes'));
    }

    public function store(Request $request)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $structure = $this->normalizeStructure($request->input('pricing_structure'));

        if ($structure === 'normal') {
            return $this->storeNormalPrice($request);
        }

        // Promotional create UI is commented out — new plans are always period.
        $planType = HotelRatePlan::TYPE_PERIOD;
        $defaultPriority = 100;

        $validator = \Validator::make($request->all(), [
            'hotel_room_type_id' => 'required|exists:hotel_room_types,id',
            'name' => 'required|string|max:100',
            'rate' => 'required|numeric|min:0',
            'weekend_rate' => 'nullable|numeric|min:0',
            'monthly_rate' => 'nullable|numeric|min:0',
            'billing_mode' => 'required|in:nightly,monthly,both',
            'priority' => 'nullable|integer|min:0|max:999',
            'valid_from' => 'required|date',
            'valid_to' => 'required|date|after:valid_from',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', $validator->getMessageBag()->first());
        }

        $creatorId = (int) Auth::user()->creatorId();
        $roomType = HotelRoomType::where('created_by', $creatorId)
            ->find($request->hotel_room_type_id);
        if (! $roomType) {
            return redirect()->back()->withInput()->with('error', __('Invalid room type.'));
        }

        $isActive = $request->has('is_active');
        if ($isActive) {
            $conflict = $this->findOverlappingActivePeriod(
                $creatorId,
                (int) $request->hotel_room_type_id,
                (string) $request->valid_from,
                (string) $request->valid_to
            );
            if ($conflict) {
                return redirect()->back()->withInput()->with('error', $this->overlapErrorMessage($conflict));
            }
        }

        HotelRatePlan::create([
            'hotel_room_type_id' => $request->hotel_room_type_id,
            'name' => $request->name,
            'plan_type' => $planType,
            'rate' => $request->rate ?: 0,
            'weekend_rate' => $request->filled('weekend_rate') ? $request->weekend_rate : null,
            'monthly_rate' => $request->monthly_rate ?: 0,
            'billing_mode' => $request->billing_mode,
            'priority' => $request->filled('priority') ? (int) $request->priority : $defaultPriority,
            'valid_from' => $request->valid_from,
            'valid_to' => $request->valid_to,
            'is_active' => $isActive,
            'created_by' => $creatorId,
        ]);

        return redirect()->route('hotel.rate-plans.index')->with('success', __('Price period created successfully.'));
    }

    public function edit(HotelRatePlan $rate_plan)
    {
        if ((! $this->canManage()) || (int) $rate_plan->created_by !== (int) Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        $roomTypes = $this->roomTypeOptions();

        return view('hotelmanagement::rate_plans.edit', ['ratePlan' => $rate_plan, 'roomTypes' => $roomTypes]);
    }

    public function update(Request $request, HotelRatePlan $rate_plan)
    {
        if ((! $this->canManage()) || (int) $rate_plan->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        // Edit keeps the existing plan type (period / promotional); Normal is create-only.
        $planType = in_array($rate_plan->plan_type, [HotelRatePlan::TYPE_PERIOD, HotelRatePlan::TYPE_PROMOTIONAL], true)
            ? $rate_plan->plan_type
            : HotelRatePlan::TYPE_PERIOD;

        $validator = \Validator::make($request->all(), [
            'hotel_room_type_id' => 'required|exists:hotel_room_types,id',
            'name' => 'required|string|max:100',
            'rate' => 'required|numeric|min:0',
            'weekend_rate' => 'nullable|numeric|min:0',
            'monthly_rate' => 'nullable|numeric|min:0',
            'billing_mode' => 'required|in:nightly,monthly,both',
            'priority' => 'nullable|integer|min:0|max:999',
            'valid_from' => 'required|date',
            'valid_to' => 'required|date|after:valid_from',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', $validator->getMessageBag()->first());
        }

        $creatorId = (int) Auth::user()->creatorId();
        $roomType = HotelRoomType::where('created_by', $creatorId)
            ->find($request->hotel_room_type_id);
        if (! $roomType) {
            return redirect()->back()->withInput()->with('error', __('Invalid room type.'));
        }

        $isActive = $request->has('is_active');
        if ($isActive && $planType === HotelRatePlan::TYPE_PERIOD) {
            $conflict = $this->findOverlappingActivePeriod(
                $creatorId,
                (int) $request->hotel_room_type_id,
                (string) $request->valid_from,
                (string) $request->valid_to,
                (int) $rate_plan->id
            );
            if ($conflict) {
                return redirect()->back()->withInput()->with('error', $this->overlapErrorMessage($conflict));
            }
        }

        $defaultPriority = $planType === HotelRatePlan::TYPE_PROMOTIONAL ? 10 : 100;

        $rate_plan->update([
            'hotel_room_type_id' => $request->hotel_room_type_id,
            'name' => $request->name,
            'plan_type' => $planType,
            'rate' => $request->rate ?: 0,
            'weekend_rate' => $planType === HotelRatePlan::TYPE_PERIOD && $request->filled('weekend_rate')
                ? $request->weekend_rate
                : null,
            'monthly_rate' => $request->monthly_rate ?: 0,
            'billing_mode' => $request->billing_mode,
            'priority' => $request->filled('priority') ? (int) $request->priority : $defaultPriority,
            'valid_from' => $request->valid_from,
            'valid_to' => $request->valid_to,
            'is_active' => $isActive,
        ]);

        $msg = $planType === HotelRatePlan::TYPE_PROMOTIONAL
            ? __('Promotional price updated successfully.')
            : __('Price period updated successfully.');

        return redirect()->route('hotel.rate-plans.index')->with('success', $msg);
    }

    public function destroy(HotelRatePlan $rate_plan)
    {
        if ((! $this->canManage()) || (int) $rate_plan->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $rate_plan->delete();

        return redirect()->route('hotel.rate-plans.index')->with('success', __('Rate plan deleted successfully.'));
    }

    private function normalizeStructure(?string $structure): string
    {
        $structure = strtolower(trim((string) $structure));

        // Promotional create UI commented out — coerce to period.
        if ($structure === 'promotional') {
            return 'period';
        }

        return in_array($structure, ['normal', 'period'], true) ? $structure : 'period';
    }

    private function storeNormalPrice(Request $request)
    {
        $validator = \Validator::make($request->all(), [
            'hotel_room_type_id' => 'required|exists:hotel_room_types,id',
            'rate' => 'required|numeric|min:0',
            'monthly_rate' => 'nullable|numeric|min:0',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', $validator->getMessageBag()->first());
        }

        $roomType = HotelRoomType::where('created_by', Auth::user()->creatorId())
            ->find($request->hotel_room_type_id);
        if (! $roomType) {
            return redirect()->back()->withInput()->with('error', __('Invalid room type.'));
        }

        $nightly = round((float) $request->rate, 2);
        $roomType->update([
            'base_price' => $nightly,
            'nightly_rate' => $nightly,
            'monthly_rate' => $request->filled('monthly_rate') ? round((float) $request->monthly_rate, 2) : (float) ($roomType->monthly_rate ?? 0),
        ]);

        return redirect()->route('hotel.rate-plans.index')->with(
            'success',
            __('Normal price updated for :type.', ['type' => $roomType->name])
        );
    }

    /**
     * Find an active period plan that overlaps [start, end) for the same room type.
     * Adjacent ranges (A ends exactly when B starts) are allowed.
     */
    private function findOverlappingActivePeriod(
        int $creatorId,
        int $roomTypeId,
        string $start,
        string $end,
        ?int $ignoreId = null
    ): ?HotelRatePlan {
        $startAt = Carbon::parse($start);
        $endAt = Carbon::parse($end);

        return HotelRatePlan::where('created_by', $creatorId)
            ->where('hotel_room_type_id', $roomTypeId)
            ->where('is_active', true)
            ->where('plan_type', HotelRatePlan::TYPE_PERIOD)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('valid_from', '<', $endAt)
            ->where('valid_to', '>', $startAt)
            ->orderBy('valid_from')
            ->first();
    }

    private function overlapErrorMessage(HotelRatePlan $conflict): string
    {
        $from = $conflict->valid_from ? Carbon::parse($conflict->valid_from)->format('Y-m-d H:i') : '—';
        $to = $conflict->valid_to ? Carbon::parse($conflict->valid_to)->format('Y-m-d H:i') : '—';

        return __('This period overlaps an active period ":name" (:from – :to). Active periods for the same room type cannot overlap. Outside active periods, the Normal price applies.', [
            'name' => $conflict->name,
            'from' => $from,
            'to' => $to,
        ]);
    }
}
