<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\HotelManagement\Entities\HotelBookingSite;
use Modules\HotelManagement\Services\HotelAuthorization;
use Modules\HotelManagement\Services\HotelStripePaymentService;

class HotelBookingSiteController extends Controller
{
    private function canManage(): bool
    {
        return HotelAuthorization::manageBookingSite(Auth::user());
    }

    public function edit()
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        if (! Schema::hasTable('hotel_booking_sites')) {
            return redirect()->route('hotel.dashboard')->with('error', __('Run database migrations to enable the booking website.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $site = HotelBookingSite::firstOrNew(['created_by' => $creatorId]);

        if (! $site->exists) {
            $company = User::find($creatorId);
            $site->display_name = $company?->name ?? __('My Hotel');
            $site->slug = $this->suggestSlug($site->display_name, $creatorId);
            $site->accent_color = '#0d9488';
            $site->payment_mode = HotelBookingSite::PAY_AT_HOTEL;
        }

        $publicUrl = $site->slug ? url('/' . $site->slug) : null;
        $stripeService = app(HotelStripePaymentService::class);
        $stripeConfigured = $site->exists && $stripeService->isConfigured($site);
        $companyPayment = Utility::getCompanyPaymentSetting($creatorId);
        $companyStripeEnabled = ($companyPayment['is_stripe_enabled'] ?? 'off') === 'on';
        $webhookUrl = route('hotel.stripe.webhook');

        return view('hotelmanagement::booking_site.edit', compact(
            'site',
            'publicUrl',
            'stripeConfigured',
            'companyStripeEnabled',
            'companyPayment',
            'webhookUrl',
        ));
    }

    public function update(Request $request)
    {
        if (! $this->canManage()) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        $creatorId = (int) Auth::user()->creatorId();
        $site = HotelBookingSite::firstOrNew(['created_by' => $creatorId]);

        $slugRule = Rule::unique('hotel_booking_sites', 'slug')->ignore($site->id);
        $request->merge([
            'slug' => Str::slug(strtolower(trim((string) $request->input('slug', ''))), '-'),
        ]);

        $validator = \Validator::make($request->all(), [
            'slug' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slugRule],
            'display_name' => 'required|string|max:191',
            'tagline' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
            'contact_email' => 'nullable|email|max:191',
            'contact_phone' => 'nullable|string|max:64',
            'address' => 'nullable|string|max:500',
            'accent_color' => 'nullable|string|max:16',
            'check_in_time' => 'nullable|date_format:H:i',
            'check_out_time' => 'nullable|date_format:H:i',
            'cancellation_policy' => 'nullable|string|max:3000',
            'meta_title' => 'nullable|string|max:191',
            'meta_description' => 'nullable|string|max:500',
            'default_language' => 'nullable|in:en,pt,es,fr,zh',
            'payment_mode' => 'nullable|in:pay_at_hotel,prepay',
            'prepayment_percent' => 'nullable|integer|min:0|max:100',
            'stripe_key' => 'nullable|string|max:255',
            'stripe_secret' => 'nullable|string|max:255',
            'stripe_webhook_secret' => 'nullable|string|max:255',
            'is_published' => 'nullable|boolean',
            'logo' => 'nullable|image|max:2048',
            'cover' => 'nullable|image|max:4096',
            'gallery.*' => 'nullable|image|max:4096',
            'amenities_text' => 'nullable|string|max:2000',
        ]);

        if (HotelBookingSite::isReservedSlug($request->slug)) {
            return redirect()->back()->withInput()->with('error', __('This URL slug is reserved. Choose another.'));
        }

        if ($validator->fails()) {
            return redirect()->back()->withInput()->with('error', $validator->getMessageBag()->first());
        }

        $amenities = collect(preg_split('/\r\n|\r|\n/', (string) $request->amenities_text))
            ->map(fn ($line) => trim($line))
            ->filter()
            ->values()
            ->all();

        $data = [
            'slug' => $request->slug,
            'display_name' => $request->display_name,
            'tagline' => $request->tagline,
            'description' => $request->description,
            'contact_email' => $request->contact_email,
            'contact_phone' => $request->contact_phone,
            'address' => $request->address,
            'accent_color' => $request->accent_color ?: '#0d9488',
            'check_in_time' => $request->check_in_time ?: '14:00',
            'check_out_time' => $request->check_out_time ?: '11:00',
            'cancellation_policy' => $request->cancellation_policy,
            'meta_title' => $request->meta_title,
            'meta_description' => $request->meta_description,
            'default_language' => $request->default_language ?: 'en',
            'payment_mode' => $request->payment_mode ?: HotelBookingSite::PAY_AT_HOTEL,
            'prepayment_percent' => ($request->payment_mode ?? '') === HotelBookingSite::PAY_PREPAY
                ? max(1, min(100, (int) ($request->prepayment_percent ?: 100)))
                : 0,
            'is_published' => $request->boolean('is_published'),
            'amenities' => $amenities,
            'created_by' => $creatorId,
        ];

        $paymentConfig = $site->payment_config ?? [];
        if ($request->filled('stripe_key')) {
            $paymentConfig['stripe_key'] = $request->stripe_key;
        }
        if ($request->filled('stripe_secret')) {
            $paymentConfig['stripe_secret'] = $request->stripe_secret;
        }
        if ($request->filled('stripe_webhook_secret')) {
            $paymentConfig['stripe_webhook_secret'] = $request->stripe_webhook_secret;
        }
        $data['payment_config'] = $paymentConfig;

        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('hotel-booking/' . $creatorId, 'public');
        }
        if ($request->hasFile('cover')) {
            $data['cover_image_path'] = $request->file('cover')->store('hotel-booking/' . $creatorId, 'public');
        }

        if ($request->hasFile('gallery')) {
            $gallery = $site->gallery_images ?? [];
            foreach ($request->file('gallery') as $file) {
                $gallery[] = $file->store('hotel-booking/' . $creatorId, 'public');
            }
            $data['gallery_images'] = array_slice($gallery, -12);
        }

        $site->fill($data)->save();

        return redirect()
            ->route('hotel.booking-site.edit')
            ->with('success', __('Booking website settings saved.'));
    }

    protected function suggestSlug(string $name, int $creatorId): string
    {
        $base = Str::slug($name) ?: 'hotel';
        $slug = $base;
        $i = 1;
        while (
            HotelBookingSite::isReservedSlug($slug)
            || HotelBookingSite::where('slug', $slug)->where('created_by', '!=', $creatorId)->exists()
        ) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}
