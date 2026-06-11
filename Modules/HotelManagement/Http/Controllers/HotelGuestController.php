<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\HotelManagement\Entities\HotelFloor;
use Modules\HotelManagement\Entities\HotelGuest;
use Modules\HotelManagement\Entities\HotelRoomType;
use Modules\HotelManagement\Services\HotelAuthorization;
use Modules\HotelManagement\Services\HotelGuestCrmSyncService;
use Modules\HotelManagement\Support\HotelNationalityOptions;

class HotelGuestController extends Controller
{
    public function __construct(private readonly HotelGuestCrmSyncService $crmSyncService)
    {
    }

    private function canView(): bool
    {
        return HotelAuthorization::viewReservations(Auth::user());
    }

    private function canManage(): bool
    {
        return HotelAuthorization::manageReservations(Auth::user());
    }

    public function index(Request $request)
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        $query = HotelGuest::where('created_by', $creatorId)
            ->withCount('reservations');

        if ($request->filled('q')) {
            $q = trim((string) $request->q);
            $query->where(function ($w) use ($q) {
                $w->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%")
                    ->orWhere('id_number', 'like', "%{$q}%");
            });
        }

        if ($request->boolean('vip')) {
            $query->where('is_vip', true);
        }

        $guests = $query->orderByDesc('id')->paginate(25)->withQueryString();
        $canManage = $this->canManage();

        return view('hotelmanagement::guests.index', compact('guests', 'canManage'));
    }

    public function show(HotelGuest $guest)
    {
        if (! $this->canView() || (int) $guest->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $guest->load([
            'customer',
            'preferredRoomType',
            'preferredFloor',
            'reservations' => fn ($q) => $q->with(['room', 'roomType', 'stay.folio'])->orderByDesc('id'),
        ]);

        $canManage = $this->canManage();
        $crmCustomer = $guest->customer_id
            ? Customer::where('created_by', Auth::user()->creatorId())->find($guest->customer_id)
            : null;

        return view('hotelmanagement::guests.show', compact('guest', 'canManage', 'crmCustomer'));
    }

    public function edit(HotelGuest $guest)
    {
        if (! $this->canManage() || (int) $guest->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = Auth::user()->creatorId();

        return view('hotelmanagement::guests.edit', [
            'guest' => $guest,
            'roomTypes' => HotelRoomType::where('created_by', $creatorId)->where('is_active', true)->pluck('name', 'id'),
            'floors' => HotelFloor::where('created_by', $creatorId)->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id'),
            'nationalityOptions' => HotelNationalityOptions::all(),
        ]);
    }

    public function update(Request $request, HotelGuest $guest)
    {
        if (! $this->canManage() || (int) $guest->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:191',
            'phone' => 'nullable|string|max:50',
            'birth_date' => 'nullable|date',
            'id_type' => 'nullable|in:id,passport,letter',
            'id_number' => 'nullable|string|max:100',
            'nationality' => 'nullable|string|max:100',
            'preferred_room_type_id' => 'nullable|exists:hotel_room_types,id',
            'preferred_hotel_floor_id' => 'nullable|exists:hotel_floors,id',
            'preferred_payment_method' => 'nullable|string|max:50',
            'credit_limit' => 'nullable|numeric|min:0',
            'company_name' => 'nullable|string|max:191',
        ]);

        $preferredFloor = null;
        if ($request->filled('preferred_hotel_floor_id')) {
            $preferredFloor = HotelFloor::where('created_by', Auth::user()->creatorId())
                ->find((int) $request->preferred_hotel_floor_id);
        }

        $guest->update([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'phone' => $request->phone,
            'birth_date' => $request->birth_date,
            'id_type' => $request->id_type,
            'id_number' => $request->id_number,
            'nationality' => $request->nationality,
            'preferred_room_type_id' => $request->preferred_room_type_id,
            'preferred_floor' => $preferredFloor?->name,
            'preferred_hotel_floor_id' => $preferredFloor?->id,
            'preferred_payment_method' => $request->preferred_payment_method,
            'credit_limit' => $request->credit_limit ?: 0,
            'notes' => $request->notes,
            'allergies' => $request->allergies,
            'preferences' => $request->preferences,
            'drink_preferences' => $request->drink_preferences,
            'company_name' => $request->company_name,
            'is_vip' => $request->has('is_vip'),
        ]);

        if ($request->boolean('sync_crm')) {
            $this->crmSyncService->syncGuest($guest->fresh(), (int) Auth::user()->creatorId());
        }

        return redirect()->route('hotel.guests.show', $guest->id)->with('success', __('Guest updated successfully.'));
    }

    public function syncCrm(HotelGuest $guest)
    {
        if (! $this->canManage() || (int) $guest->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $customer = $this->crmSyncService->syncGuest($guest, (int) Auth::user()->creatorId());
        if (! $customer) {
            return redirect()->back()->with('error', __('Guest needs email or phone to link to CRM.'));
        }

        return redirect()->back()->with('success', __('Guest linked to CRM customer.'));
    }
}
