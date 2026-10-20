<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelAddon;
use Modules\HotelManagement\Entities\HotelPriceRule;
use Modules\HotelManagement\Entities\HotelPriceSetting;
use Modules\HotelManagement\Entities\HotelRoomType;
use Modules\HotelManagement\Services\HotelAuthorization;
use Modules\HotelManagement\Services\HotelOccupancyPricingService;
use Modules\HotelManagement\Services\HotelReservationPricingService;

class HotelPriceConfiguratorController extends Controller
{
    public function index(Request $request, HotelOccupancyPricingService $engine)
    {
        if (! HotelAuthorization::managePriceConfigurator(Auth::user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if (! Schema::hasTable('hotel_price_rules')) {
            return redirect()->back()->with('error', __('Run migrations to enable the Price Configurator.'));
        }

        $creatorId = Auth::user()->creatorId();
        $tab = (string) $request->query('tab', 'occupancy');
        if (! in_array($tab, ['occupancy', 'room-rates', 'rate-plans', 'addons', 'taxes', 'calculator'], true)) {
            $tab = 'occupancy';
        }

        $roomTypes = HotelRoomType::where('created_by', $creatorId)->orderBy('name')->get();
        $addons = HotelAddon::where('created_by', $creatorId)->orderBy('name')->get();
        $rules = HotelPriceRule::where('created_by', $creatorId)
            ->with(['roomType', 'addon'])
            ->orderBy('rule_type')
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->groupBy('rule_type');
        $setting = HotelPriceSetting::forCreator($creatorId);
        $calculation = session('calculation');

        return view('hotelmanagement::price_configurator.index', compact(
            'tab', 'roomTypes', 'addons', 'rules', 'setting', 'calculation', 'engine'
        ));
    }

    public function updateSettings(Request $request)
    {
        if (! HotelAuthorization::managePriceConfigurator(Auth::user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        $validator = \Validator::make($request->all(), [
            'included_adults' => ['required', 'integer', 'min:0', 'max:50'],
        ]);
        if ($validator->fails()) {
            return redirect()->back()->withErrors($validator)->withInput();
        }

        $setting = HotelPriceSetting::forCreator($creatorId);
        $setting->included_adults = (int) $request->input('included_adults');
        $setting->save();

        // Per-room-type overrides: blank = fall back to the global setting.
        $overrides = (array) $request->input('room_type_included_adults', []);
        $roomTypes = HotelRoomType::where('created_by', $creatorId)->get()->keyBy('id');
        foreach ($overrides as $roomTypeId => $value) {
            $roomType = $roomTypes->get((int) $roomTypeId);
            if (! $roomType) {
                continue;
            }
            if ($value === null || $value === '') {
                $roomType->included_adults = null;
            } else {
                $value = (int) $value;
                if ($roomType->max_occupancy !== null && $value > (int) $roomType->max_occupancy) {
                    return redirect()->back()
                        ->with('error', __('Included adults for :type cannot exceed its max occupancy (:max).', [
                            'type' => $roomType->name, 'max' => $roomType->max_occupancy,
                        ]))
                        ->withInput();
                }
                $roomType->included_adults = max(0, $value);
            }
            $roomType->save();
        }

        return redirect()->route('hotel.price-configurator.index', ['tab' => 'occupancy'])
            ->with('success', __('Price settings saved.'));
    }

    public function calculate(Request $request, HotelOccupancyPricingService $engine, HotelReservationPricingService $pricing)
    {
        if (! HotelAuthorization::managePriceConfigurator(Auth::user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = Auth::user()->creatorId();
        $validator = \Validator::make($request->all(), [
            'hotel_room_type_id' => ['required', 'integer'],
            'check_in_date' => ['required', 'date'],
            'check_out_date' => ['required', 'date', 'after:check_in_date'],
            'adults' => ['required', 'integer', 'min:0', 'max:50'],
            'children' => ['nullable', 'integer', 'min:0', 'max:50'],
            'extra_adults' => ['nullable', 'integer', 'min:0', 'max:50'],
            'addons' => ['nullable', 'array'],
            'addons.*' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);
        if ($validator->fails()) {
            return redirect()->route('hotel.price-configurator.index', ['tab' => 'calculator'])
                ->withErrors($validator)->withInput();
        }

        $roomType = HotelRoomType::where('created_by', $creatorId)->find((int) $request->input('hotel_room_type_id'));
        if (! $roomType) {
            return redirect()->route('hotel.price-configurator.index', ['tab' => 'calculator'])
                ->with('error', __('Room type not found.'))->withInput();
        }

        $checkIn = Carbon::parse($request->input('check_in_date'));
        $checkOut = Carbon::parse($request->input('check_out_date'));
        $quote = $pricing->quote($creatorId, $roomType->id, null, $checkIn, $checkOut);
        $units = max(1, (int) ($quote['units'] ?? 1));

        $included = $engine->resolveIncludedAdults($creatorId, $roomType);
        $adults = (int) $request->input('adults');
        $extraAdults = $request->filled('extra_adults')
            ? (int) $request->input('extra_adults')
            : $engine->suggestedExtraAdults($adults, $included);

        $addonRows = [];
        foreach ((array) $request->input('addons', []) as $addonId => $qty) {
            if ((int) $qty > 0) {
                $addonRows[] = ['addon_id' => (int) $addonId, 'qty' => (int) $qty];
            }
        }

        $charges = $engine->price($creatorId, $roomType, $units, $adults, (int) $request->input('children', 0), $extraAdults, $addonRows, $checkIn, $checkOut);

        $calculation = [
            'room_type_name' => $roomType->name,
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkOut->toDateString(),
            'units' => $units,
            'unit_label' => $quote['unit_label'] ?? __('nights'),
            'room_rate' => (float) ($quote['rate_per_night'] ?? 0),
            'room_subtotal' => (float) ($quote['subtotal'] ?? 0),
            'included_adults' => $included,
            'adults' => $adults,
            'extra_adults' => $extraAdults,
            'charges' => $charges,
            'grand_total' => round((float) ($quote['subtotal'] ?? 0) + (float) $charges['charges_total'], 2),
        ];

        return redirect()->route('hotel.price-configurator.index', ['tab' => 'calculator'])
            ->with('calculation', $calculation)
            ->withInput();
    }
}
