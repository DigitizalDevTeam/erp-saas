<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Modules\HotelManagement\Entities\HotelOtaSetting;

class HotelOtaSettingsController extends Controller
{
    public function index()
    {
        if (!Auth::user()->can('manage hotel') && !Auth::user()->can('manage hotel reservation')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if (!Schema::hasTable('hotel_ota_settings')) {
            return view('hotelmanagement::ota.stub');
        }
        $creatorId = Auth::user()->creatorId();
        $channels = ['booking_com', 'agoda', 'generic_ical'];
        $settings = [];
        foreach ($channels as $ch) {
            $settings[$ch] = HotelOtaSetting::firstOrCreate(
                ['created_by' => $creatorId, 'channel' => $ch],
                ['enabled' => false]
            );
        }

        return view('hotelmanagement::ota.index', compact('settings', 'channels'));
    }

    public function update(Request $request)
    {
        if (!Auth::user()->can('manage hotel') && !Auth::user()->can('manage hotel reservation')) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if (!Schema::hasTable('hotel_ota_settings')) {
            return redirect()->back()->with('error', __('Run migrations to enable OTA settings.'));
        }
        $creatorId = Auth::user()->creatorId();
        foreach ($request->get('channels', []) as $channel => $payload) {
            if (!is_array($payload)) {
                continue;
            }
            HotelOtaSetting::updateOrCreate(
                ['created_by' => $creatorId, 'channel' => $channel],
                [
                    'enabled' => !empty($payload['enabled']),
                    'api_key' => $payload['api_key'] ?? null,
                    'api_secret' => $payload['api_secret'] ?? null,
                    'property_id' => $payload['property_id'] ?? null,
                    'webhook_url' => $payload['webhook_url'] ?? null,
                ]
            );
        }

        return redirect()->route('hotel.ota-settings.index')->with('success', __('OTA settings saved. Sync runs via `php artisan hotelmanagement:ota-sync`.'));
    }
}
