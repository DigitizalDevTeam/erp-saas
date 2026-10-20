<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\HotelManagement\Entities\HotelAddon;
use Modules\HotelManagement\Entities\HotelPriceRule;
use Modules\HotelManagement\Entities\HotelRoomType;
use Modules\HotelManagement\Services\HotelAuthorization;
use Modules\HotelManagement\Services\HotelOccupancyPricingService;

class HotelPriceRuleController extends Controller
{
    private function canManage(): bool
    {
        return HotelAuthorization::managePriceConfigurator(Auth::user());
    }

    private function backToConfigurator()
    {
        return redirect()->route('hotel.price-configurator.index', ['tab' => 'occupancy']);
    }

    public function create(Request $request)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        $rule = new HotelPriceRule([
            'rule_type' => (string) $request->query('type', HotelPriceRule::TYPE_CHILD),
            'qty_from' => 1,
            'price_basis' => HotelPriceRule::BASIS_PER_NIGHT,
            'priority' => 0,
            'is_active' => true,
        ]);
        $roomTypes = HotelRoomType::where('created_by', $creatorId)->orderBy('name')->get();
        $addons = HotelAddon::where('created_by', $creatorId)->orderBy('name')->get();

        return view('hotelmanagement::price_configurator.rules.form', compact('rule', 'roomTypes', 'addons'));
    }

    public function store(Request $request, HotelOccupancyPricingService $engine)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $data = $this->validateRule($request);
        if ($data instanceof \Illuminate\Http\RedirectResponse) {
            return $data;
        }

        $rule = HotelPriceRule::create($data + ['created_by' => Auth::user()->creatorId()]);

        return $this->redirectWithOverlapWarning($engine, $rule);
    }

    public function edit(HotelPriceRule $rule)
    {
        if (! $this->canManage() || (int) $rule->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        $roomTypes = HotelRoomType::where('created_by', $creatorId)->orderBy('name')->get();
        $addons = HotelAddon::where('created_by', $creatorId)->orderBy('name')->get();

        return view('hotelmanagement::price_configurator.rules.form', compact('rule', 'roomTypes', 'addons'));
    }

    public function update(Request $request, HotelPriceRule $rule, HotelOccupancyPricingService $engine)
    {
        if (! $this->canManage() || (int) $rule->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $data = $this->validateRule($request);
        if ($data instanceof \Illuminate\Http\RedirectResponse) {
            return $data;
        }

        $rule->update($data);

        return $this->redirectWithOverlapWarning($engine, $rule);
    }

    public function destroy(HotelPriceRule $rule)
    {
        if (! $this->canManage() || (int) $rule->created_by !== (int) Auth::user()->creatorId()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $rule->delete();

        return $this->backToConfigurator()->with('success', __('Price rule deleted.'));
    }

    /**
     * Validate + normalize the rule payload. Returns the data array, or a
     * redirect response when validation fails.
     */
    private function validateRule(Request $request): array|\Illuminate\Http\RedirectResponse
    {
        $creatorId = Auth::user()->creatorId();
        $validator = \Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'rule_type' => ['required', 'in:'.implode(',', [HotelPriceRule::TYPE_CHILD, HotelPriceRule::TYPE_EXTRA_PERSON, HotelPriceRule::TYPE_ADDON])],
            'hotel_room_type_id' => ['nullable', 'integer'],
            'hotel_addon_id' => ['nullable', 'integer'],
            'adults_min' => ['nullable', 'integer', 'min:0', 'max:50'],
            'adults_max' => ['nullable', 'integer', 'min:0', 'max:50'],
            'qty_from' => ['required', 'integer', 'min:1', 'max:999'],
            'qty_to' => ['nullable', 'integer', 'min:1', 'max:999'],
            'price' => ['required', 'numeric', 'min:0'],
            'price_basis' => ['required', 'in:'.implode(',', [HotelPriceRule::BASIS_PER_NIGHT, HotelPriceRule::BASIS_PER_STAY])],
            'valid_from' => ['nullable', 'date'],
            'valid_to' => ['nullable', 'date'],
            'priority' => ['nullable', 'integer', 'min:-999', 'max:999'],
            'is_active' => ['nullable'],
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $data = $validator->validated();
        $type = $data['rule_type'];

        if (! empty($data['hotel_room_type_id'])
            && ! HotelRoomType::where('created_by', $creatorId)->whereKey($data['hotel_room_type_id'])->exists()) {
            return redirect()->back()->with('error', __('Room type not found.'))->withInput();
        }
        if ($type === HotelPriceRule::TYPE_ADDON) {
            if (empty($data['hotel_addon_id'])
                || ! HotelAddon::where('created_by', $creatorId)->whereKey($data['hotel_addon_id'])->exists()) {
                return redirect()->back()->with('error', __('Choose an extra item (addon) for this rule.'))->withInput();
            }
        } else {
            $data['hotel_addon_id'] = null;
        }
        if ($type !== HotelPriceRule::TYPE_CHILD) {
            $data['adults_min'] = null;
            $data['adults_max'] = null;
        } elseif ($data['adults_min'] !== null && $data['adults_max'] !== null && (int) $data['adults_min'] > (int) $data['adults_max']) {
            return redirect()->back()->with('error', __('Adults "from" cannot be greater than adults "to".'))->withInput();
        }
        if ($data['qty_to'] !== null && (int) $data['qty_to'] < (int) $data['qty_from']) {
            return redirect()->back()->with('error', __('Quantity "to" cannot be less than quantity "from".'))->withInput();
        }
        if (! empty($data['valid_from']) && ! empty($data['valid_to']) && $data['valid_from'] > $data['valid_to']) {
            return redirect()->back()->with('error', __('Valid-from date cannot be after the valid-to date.'))->withInput();
        }

        $data['priority'] = (int) ($data['priority'] ?? 0);
        $data['is_active'] = ! empty($request->input('is_active'));

        return $data;
    }

    /**
     * Saving an overlapping rule is allowed (matching stays deterministic),
     * but the manager is told which rule wins at the overlapping quantities.
     */
    private function redirectWithOverlapWarning(HotelOccupancyPricingService $engine, HotelPriceRule $rule)
    {
        $redirect = $this->backToConfigurator()->with('success', __('Price rule saved.'));

        $overlaps = $engine->findOverlappingRules((int) $rule->created_by, $rule->only([
            'rule_type', 'hotel_room_type_id', 'hotel_addon_id',
            'adults_min', 'adults_max', 'qty_from', 'qty_to', 'valid_from', 'valid_to',
        ]), (int) $rule->id);

        if ($overlaps->isNotEmpty()) {
            $winner = $engine->matchRule(
                (int) $rule->created_by,
                $rule->rule_type,
                max((int) $rule->qty_from, (int) $overlaps->first()->qty_from),
                $rule->hotel_room_type_id !== null ? (int) $rule->hotel_room_type_id : null,
                $rule->hotel_addon_id !== null ? (int) $rule->hotel_addon_id : null,
                $rule->adults_min !== null ? (int) $rule->adults_min : null
            );
            $names = $overlaps->pluck('name')->implode(', ');
            $redirect->with('warning', __('This rule overlaps with: :names. Where they compete, ":winner" wins (room-type scope first, then lowest priority).', [
                'names' => $names,
                'winner' => $winner?->name ?? $rule->name,
            ]));
        }

        return $redirect;
    }
}
