<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelMinibarItem;
use Modules\HotelManagement\Services\HotelAuthorization;

class HotelMinibarItemController extends Controller
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

        if (! Schema::hasTable('hotel_minibar_items')) {
            return redirect()->back()->with('error', __('Run migrations to enable minibar catalog.'));
        }

        $items = HotelMinibarItem::where('created_by', Auth::user()->creatorId())
            ->orderBy('name')
            ->get();

        return view('hotelmanagement::minibar_items.index', [
            'items' => $items,
            'canManage' => $this->canManage(),
        ]);
    }

    public function create()
    {
        if (! $this->canManage()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        return view('hotelmanagement::minibar_items.create');
    }

    public function store(Request $request)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'name' => 'required|string|max:191',
            'unit_price' => 'required|numeric|min:0',
            'default_quantity' => 'nullable|integer|min:1|max:99',
        ]);

        HotelMinibarItem::create([
            'name' => $request->name,
            'unit_price' => $request->unit_price,
            'default_quantity' => max(1, (int) ($request->default_quantity ?: 1)),
            'is_active' => $request->has('is_active'),
            'created_by' => Auth::user()->creatorId(),
        ]);

        return redirect()->route('hotel.minibar-items.index')->with('success', __('Minibar item created successfully.'));
    }

    public function edit(HotelMinibarItem $minibar_item)
    {
        if ((! $this->canManage()) || (int) $minibar_item->created_by !== (int) Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        return view('hotelmanagement::minibar_items.edit', ['item' => $minibar_item]);
    }

    public function update(Request $request, HotelMinibarItem $minibar_item)
    {
        if ((! $this->canManage()) || (int) $minibar_item->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $request->validate([
            'name' => 'required|string|max:191',
            'unit_price' => 'required|numeric|min:0',
            'default_quantity' => 'nullable|integer|min:1|max:99',
        ]);

        $minibar_item->update([
            'name' => $request->name,
            'unit_price' => $request->unit_price,
            'default_quantity' => max(1, (int) ($request->default_quantity ?: 1)),
            'is_active' => $request->has('is_active'),
        ]);

        return redirect()->route('hotel.minibar-items.index')->with('success', __('Minibar item updated successfully.'));
    }

    public function destroy(HotelMinibarItem $minibar_item)
    {
        if ((! $this->canManage()) || (int) $minibar_item->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $minibar_item->delete();

        return redirect()->route('hotel.minibar-items.index')->with('success', __('Minibar item deleted successfully.'));
    }

    public function seedDefaults()
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $defaults = [
            ['name' => __('Water (500ml)'), 'unit_price' => 2.50],
            ['name' => __('Soft drink'), 'unit_price' => 3.50],
            ['name' => __('Beer'), 'unit_price' => 5.00],
            ['name' => __('Wine (mini)'), 'unit_price' => 12.00],
            ['name' => __('Snacks'), 'unit_price' => 4.00],
        ];

        foreach ($defaults as $row) {
            HotelMinibarItem::firstOrCreate(
                ['created_by' => $creatorId, 'name' => $row['name']],
                [
                    'unit_price' => $row['unit_price'],
                    'default_quantity' => 1,
                    'is_active' => true,
                ]
            );
        }

        return redirect()->route('hotel.minibar-items.index')->with('success', __('Default minibar items loaded.'));
    }
}
