<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelAddon;
use Modules\HotelManagement\Services\HotelAuthorization;

class HotelAddonController extends Controller
{
    private function canView(): bool
    {
        return HotelAuthorization::viewRooms(Auth::user());
    }

    private function canManage(): bool
    {
        return HotelAuthorization::manageRooms(Auth::user());
    }

    public function index()
    {
        if (! $this->canView()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! Schema::hasTable('hotel_addons')) {
            return redirect()->back()->with('error', __('Run migrations to enable addons catalog.'));
        }

        $addons = HotelAddon::where('created_by', Auth::user()->creatorId())
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return view('hotelmanagement::addons.index', compact('addons'));
    }

    public function create()
    {
        if (! $this->canManage()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        return view('hotelmanagement::addons.create');
    }

    public function store(Request $request)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'name' => 'required|string|max:191',
            'type' => 'required|in:extra_bed,breakfast,parking,other',
            'price' => 'required|numeric|min:0',
            'billing_mode' => 'required|in:per_night,per_stay',
            'description' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', $validator->getMessageBag()->first());
        }

        $billingMode = $request->billing_mode;
        if ($request->type === HotelAddon::TYPE_EXTRA_BED) {
            $billingMode = HotelAddon::BILLING_PER_NIGHT;
        }

        HotelAddon::create([
            'name' => $request->name,
            'type' => $request->type,
            'description' => $request->description,
            'price' => $request->price,
            'billing_mode' => $billingMode,
            'taxable' => $request->has('taxable'),
            'is_active' => $request->has('is_active'),
            'created_by' => Auth::user()->creatorId(),
        ]);

        return redirect()->route('hotel.addons.index')->with('success', __('Addon created successfully.'));
    }

    public function edit(HotelAddon $addon)
    {
        if ((! $this->canManage()) || (int) $addon->created_by !== (int) Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        return view('hotelmanagement::addons.edit', compact('addon'));
    }

    public function update(Request $request, HotelAddon $addon)
    {
        if ((! $this->canManage()) || (int) $addon->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'name' => 'required|string|max:191',
            'type' => 'required|in:extra_bed,breakfast,parking,other',
            'price' => 'required|numeric|min:0',
            'billing_mode' => 'required|in:per_night,per_stay',
            'description' => 'nullable|string|max:500',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', $validator->getMessageBag()->first());
        }

        $billingMode = $request->billing_mode;
        if ($request->type === HotelAddon::TYPE_EXTRA_BED) {
            $billingMode = HotelAddon::BILLING_PER_NIGHT;
        }

        $addon->update([
            'name' => $request->name,
            'type' => $request->type,
            'description' => $request->description,
            'price' => $request->price,
            'billing_mode' => $billingMode,
            'taxable' => $request->has('taxable'),
            'is_active' => $request->has('is_active'),
        ]);

        return redirect()->route('hotel.addons.index')->with('success', __('Addon updated successfully.'));
    }

    public function destroy(HotelAddon $addon)
    {
        if ((! $this->canManage()) || (int) $addon->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ($addon->reservationAddons()->whereNotNull('hotel_folio_line_id')->exists()) {
            return redirect()->back()->with('error', __('Addon is linked to posted folio charges and cannot be deleted.'));
        }

        $addon->reservationAddons()->delete();
        $addon->delete();

        return redirect()->route('hotel.addons.index')->with('success', __('Addon deleted successfully.'));
    }
}
