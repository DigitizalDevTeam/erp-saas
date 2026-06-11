<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Mail\HotelReservationCreatedMail;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Support\LoggedMail;
use Modules\HotelManagement\Entities\HotelGroup;
use Modules\HotelManagement\Entities\HotelGroupMember;
use Modules\HotelManagement\Entities\HotelGuest;
use Modules\HotelManagement\Entities\HotelReservation;
use Modules\HotelManagement\Entities\HotelRoomType;
use Modules\HotelManagement\Services\HotelAuthorization;
use Modules\HotelManagement\Services\HotelReservationPricingService;

class HotelGroupController extends Controller
{
    public function __construct(private readonly HotelReservationPricingService $pricingService)
    {
    }

    private function canView(): bool
    {
        return HotelAuthorization::viewGroups(Auth::user());
    }

    private function canManage(): bool
    {
        return HotelAuthorization::manageGroups(Auth::user());
    }

    public function index()
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $groups = HotelGroup::where('created_by', Auth::user()->creatorId())
            ->withCount('reservations')
            ->orderByDesc('id')
            ->get();
        $canManage = $this->canManage();

        return view('hotelmanagement::groups.index', compact('groups', 'canManage'));
    }

    public function create()
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $roomTypes = HotelRoomType::where('created_by', Auth::user()->creatorId())->where('is_active', true)->pluck('name', 'id');
        $roomTypesMeta = HotelRoomType::where('created_by', Auth::user()->creatorId())
            ->where('is_active', true)
            ->get(['id', 'nightly_rate', 'monthly_rate', 'default_billing_mode', 'base_price'])
            ->mapWithKeys(fn ($type) => [$type->id => [
                'nightly_rate' => (float) ($type->nightly_rate ?? $type->base_price ?? 0),
                'monthly_rate' => (float) ($type->monthly_rate ?? 0),
                'default_billing_mode' => $type->default_billing_mode ?? 'nightly',
            ]])
            ->toArray();

        return view('hotelmanagement::groups.create', compact('roomTypes', 'roomTypesMeta'));
    }

    public function store(Request $request)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $request->validate([
            'name' => 'required|string|max:191',
            'company_name' => 'nullable|string|max:191',
            'contact_name' => 'nullable|string|max:191',
            'members' => 'required|array|min:1',
            'members.*.first_name' => 'required|string|max:100',
            'members.*.last_name' => 'nullable|string|max:100',
            'members.*.hotel_room_type_id' => 'required|exists:hotel_room_types,id',
            'members.*.check_in_at' => 'required|date',
            'members.*.check_out_at' => 'required|date',
            'members.*.billing_mode' => 'required|in:nightly,monthly',
            'members.*.role' => 'nullable|in:adult,child',
            'members.*.phone' => 'nullable|string|max:50',
            'members.*.email' => 'nullable|email|max:191',
            'members.*.hotel_room_id' => 'nullable|exists:hotel_rooms,id',
            'members.*.notes' => 'nullable|string|max:500',
        ]);

        $creatorId = Auth::user()->creatorId();
        $branchId = Auth::user()->activeBranchContextId();

        DB::transaction(function () use ($request, $creatorId, $branchId) {
            $group = HotelGroup::create([
                'name' => $request->name,
                'company_name' => $request->company_name,
                'contact_name' => $request->contact_name,
                'notes' => $request->notes,
                'branch_id' => $branchId,
                'created_by' => $creatorId,
            ]);

            $sort = 0;
            foreach ($request->members as $m) {
                $checkInAt = Carbon::parse($m['check_in_at']);
                $checkOutAt = Carbon::parse($m['check_out_at']);
                if ($checkOutAt->lte($checkInAt)) {
                    continue;
                }
                $quote = $this->pricingService->quote(
                    $creatorId,
                    (int) $m['hotel_room_type_id'],
                    !empty($m['hotel_room_id']) ? (int) $m['hotel_room_id'] : null,
                    $checkInAt,
                    $checkOutAt,
                    $m['billing_mode'] ?? 'nightly'
                );
                $guest = HotelGuest::create([
                    'first_name' => $m['first_name'],
                    'last_name' => $m['last_name'] ?? null,
                    'phone' => $m['phone'] ?? null,
                    'email' => $m['email'] ?? null,
                    'created_by' => $creatorId,
                ]);
                HotelGroupMember::create([
                    'hotel_group_id' => $group->id,
                    'hotel_guest_id' => $guest->id,
                    'role' => $m['role'] ?? 'adult',
                    'sort_order' => $sort++,
                ]);
                $reservation = HotelReservation::create([
                    'reservation_no' => 'RSV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5)),
                    'hotel_guest_id' => $guest->id,
                    'hotel_group_id' => $group->id,
                    'hotel_room_type_id' => $m['hotel_room_type_id'],
                    'hotel_room_id' => !empty($m['hotel_room_id']) ? $m['hotel_room_id'] : null,
                    'hotel_rate_plan_id' => $quote['rate_plan_id'] ?? null,
                    'branch_id' => $branchId,
                    'check_in_at' => $checkInAt->format('Y-m-d H:i:s'),
                    'check_out_at' => $checkOutAt->format('Y-m-d H:i:s'),
                    'check_in_date' => $checkInAt->toDateString(),
                    'check_out_date' => $checkOutAt->toDateString(),
                    'adults' => ($m['role'] ?? 'adult') === 'child' ? 0 : 1,
                    'children' => ($m['role'] ?? 'adult') === 'child' ? 1 : 0,
                    'source' => 'manual',
                    'status' => HotelReservation::STATUS_CONFIRMED,
                    'billing_mode' => $quote['billing_mode'],
                    'rate_per_night' => $quote['rate_amount'],
                    'discount_amount' => 0,
                    'notes' => $m['notes'] ?? null,
                    'created_by' => $creatorId,
                ]);

                if (!empty($guest->email)) {
                    try {
                        LoggedMail::send($guest->email, new HotelReservationCreatedMail($reservation->fresh(['guest', 'roomType', 'room']), [
                            'billing_mode' => $reservation->billing_mode,
                            'rate_display' => Auth::user()->priceFormat($reservation->rate_per_night) . ' / ' . __($reservation->billing_mode === 'monthly' ? 'month' : 'night'),
                        ]), [
                            'source' => 'hotel_group_reservation',
                            'subject' => __('Hotel reservation confirmation'),
                            'created_by' => $creatorId,
                        ]);
                        $reservation->forceFill(['guest_email_sent_at' => now()])->save();
                    } catch (\Throwable $e) {
                        report($e);
                    }
                }
            }
        });

        return redirect()->route('hotel.groups.index')->with('success', __('Group reservations created.'));
    }

    public function show(HotelGroup $group)
    {
        if (! $this->canView() || (int) $group->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        $group->load(['reservations.guest', 'reservations.room', 'members.guest', 'reservations.stay.folio.payments']);
        $canManage = $this->canManage();

        return view('hotelmanagement::groups.show', compact('group', 'canManage'));
    }

    public function edit(HotelGroup $group)
    {
        if (! $this->canManage() || (int) $group->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $group->load(['reservations.guest', 'reservations.room', 'reservations.roomType']);

        return view('hotelmanagement::groups.edit', compact('group'));
    }

    public function update(Request $request, HotelGroup $group)
    {
        if (! $this->canManage() || (int) $group->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'name' => 'required|string|max:191',
            'company_name' => 'nullable|string|max:191',
            'contact_name' => 'nullable|string|max:191',
            'notes' => 'nullable|string|max:2000',
        ]);

        $group->update([
            'name' => $request->name,
            'company_name' => $request->company_name,
            'contact_name' => $request->contact_name,
            'notes' => $request->notes,
        ]);

        return redirect()->route('hotel.groups.show', $group->id)->with('success', __('Group updated successfully.'));
    }

    public function destroy(HotelGroup $group)
    {
        if (! $this->canManage() || (int) $group->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $active = $group->reservations()
            ->whereIn('status', [HotelReservation::STATUS_CHECKED_IN])
            ->exists();

        if ($active) {
            return redirect()->back()->with('error', __('Cannot cancel group while members are checked in.'));
        }

        DB::transaction(function () use ($group) {
            $group->reservations()
                ->whereNotIn('status', [HotelReservation::STATUS_CHECKED_OUT, HotelReservation::STATUS_CANCELLED])
                ->update(['status' => HotelReservation::STATUS_CANCELLED]);
        });

        return redirect()->route('hotel.groups.index')->with('success', __('Group reservations cancelled.'));
    }
}
