<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Modules\HotelManagement\Entities\HotelRoomType;
use Modules\HotelManagement\Services\HotelAuthorization;

class HotelRoomTypeController extends Controller
{
    private const MAX_EXTRA_SLIDES = 2;

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

        $roomTypes = HotelRoomType::where('created_by', Auth::user()->creatorId())
            ->orderBy('name')
            ->get();

        return view('hotelmanagement::room_types.index', compact('roomTypes'));
    }

    public function create()
    {
        if (! $this->canManage()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        return view('hotelmanagement::room_types.create');
    }

    public function store(Request $request)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'unit_category' => 'required|in:room,apartment',
            'nightly_rate' => 'nullable|numeric|min:0',
            'monthly_rate' => 'nullable|numeric|min:0',
            'default_billing_mode' => 'required|in:nightly,monthly',
            'max_occupancy' => 'required|integer|min:1',
            'cover' => 'nullable|image|max:4096',
            'slide_2' => 'nullable|image|max:4096',
            'slide_3' => 'nullable|image|max:4096',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        $creatorId = (int) Auth::user()->creatorId();
        $nightly = (float) ($request->nightly_rate ?: 0);

        $data = [
            'name' => $request->name,
            'description' => $request->description,
            'unit_category' => $request->unit_category,
            'base_price' => $nightly,
            'nightly_rate' => $nightly,
            'monthly_rate' => $request->monthly_rate ?: 0,
            'default_billing_mode' => $request->default_billing_mode,
            'max_occupancy' => $request->max_occupancy,
            'is_active' => $request->has('is_active'),
            'created_by' => $creatorId,
        ];

        if ($request->hasFile('cover')) {
            $data['cover_image_path'] = $request->file('cover')->store('hotel-room-types/' . $creatorId, 'public');
        }

        $gallery = $this->storeSlideFiles($request, $creatorId);
        if ($gallery !== []) {
            $data['gallery_images'] = $gallery;
        }

        HotelRoomType::create($data);

        return redirect()->route('hotel.room-types.index')->with('success', __('Room type created successfully.'));
    }

    public function edit(HotelRoomType $room_type)
    {
        if ((! $this->canManage()) || (int) $room_type->created_by !== (int) Auth::user()->creatorId()) {
            return response()->json(['error' => __('Permission denied.')], 401);
        }

        return view('hotelmanagement::room_types.edit', ['roomType' => $room_type]);
    }

    public function update(Request $request, HotelRoomType $room_type)
    {
        if ((! $this->canManage()) || (int) $room_type->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $validator = \Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'unit_category' => 'required|in:room,apartment',
            'nightly_rate' => 'nullable|numeric|min:0',
            'monthly_rate' => 'nullable|numeric|min:0',
            'default_billing_mode' => 'required|in:nightly,monthly',
            'max_occupancy' => 'required|integer|min:1',
            'cover' => 'nullable|image|max:4096',
            'slide_2' => 'nullable|image|max:4096',
            'slide_3' => 'nullable|image|max:4096',
        ]);
        if ($validator->fails()) {
            return redirect()->back()->with('error', $validator->getMessageBag()->first());
        }

        $creatorId = (int) Auth::user()->creatorId();
        $nightly = (float) ($request->nightly_rate ?: 0);

        $data = [
            'name' => $request->name,
            'description' => $request->description,
            'unit_category' => $request->unit_category,
            'base_price' => $nightly,
            'nightly_rate' => $nightly,
            'monthly_rate' => $request->monthly_rate ?: 0,
            'default_billing_mode' => $request->default_billing_mode,
            'max_occupancy' => $request->max_occupancy,
            'is_active' => $request->has('is_active'),
        ];

        if ($request->boolean('remove_cover') && $room_type->cover_image_path) {
            Storage::disk('public')->delete($room_type->cover_image_path);
            $data['cover_image_path'] = null;
        }

        if ($request->hasFile('cover')) {
            if ($room_type->cover_image_path) {
                Storage::disk('public')->delete($room_type->cover_image_path);
            }
            $data['cover_image_path'] = $request->file('cover')->store('hotel-room-types/' . $creatorId, 'public');
        }

        $gallery = array_values($room_type->gallery_images ?? []);

        if ($request->boolean('remove_slide_2') && isset($gallery[0])) {
            Storage::disk('public')->delete($gallery[0]);
            unset($gallery[0]);
            $gallery = array_values($gallery);
        }

        if ($request->boolean('remove_slide_3') && isset($gallery[1])) {
            Storage::disk('public')->delete($gallery[1]);
            unset($gallery[1]);
            $gallery = array_values($gallery);
        }

        if ($request->hasFile('slide_2')) {
            if (isset($gallery[0])) {
                Storage::disk('public')->delete($gallery[0]);
            }
            $gallery[0] = $request->file('slide_2')->store('hotel-room-types/' . $creatorId, 'public');
        }

        if ($request->hasFile('slide_3')) {
            if (isset($gallery[1])) {
                Storage::disk('public')->delete($gallery[1]);
            }
            $gallery[1] = $request->file('slide_3')->store('hotel-room-types/' . $creatorId, 'public');
        }

        $data['gallery_images'] = array_slice(array_values($gallery), 0, self::MAX_EXTRA_SLIDES);

        $room_type->update($data);

        return redirect()->route('hotel.room-types.index')->with('success', __('Room type updated successfully.'));
    }

    public function destroy(HotelRoomType $room_type)
    {
        if ((! $this->canManage()) || (int) $room_type->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if ($room_type->rooms()->exists()) {
            return redirect()->back()->with('error', __('Room type is in use by rooms and cannot be deleted.'));
        }

        if ($room_type->cover_image_path) {
            Storage::disk('public')->delete($room_type->cover_image_path);
        }
        foreach ($room_type->gallery_images ?? [] as $path) {
            Storage::disk('public')->delete($path);
        }

        $room_type->delete();

        return redirect()->route('hotel.room-types.index')->with('success', __('Room type deleted successfully.'));
    }

    /**
     * @return array<int, string>
     */
    private function storeSlideFiles(Request $request, int $creatorId): array
    {
        $gallery = [];

        if ($request->hasFile('slide_2')) {
            $gallery[] = $request->file('slide_2')->store('hotel-room-types/' . $creatorId, 'public');
        }

        if ($request->hasFile('slide_3')) {
            $gallery[] = $request->file('slide_3')->store('hotel-room-types/' . $creatorId, 'public');
        }

        return array_slice($gallery, 0, self::MAX_EXTRA_SLIDES);
    }
}
