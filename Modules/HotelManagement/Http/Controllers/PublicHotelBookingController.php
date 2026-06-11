<?php

namespace Modules\HotelManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Modules\HotelManagement\Entities\HotelBookingSite;
use Modules\HotelManagement\Entities\HotelRoomType;
use Modules\HotelManagement\Services\HotelPublicAvailabilityService;
use Modules\HotelManagement\Services\HotelPublicBookingService;
use Modules\HotelManagement\Services\HotelReservationPricingService;
use Modules\HotelManagement\Services\HotelStripePaymentService;

class PublicHotelBookingController extends Controller
{
    public function __construct(
        private readonly HotelPublicAvailabilityService $availabilityService,
        private readonly HotelPublicBookingService $bookingService,
        private readonly HotelReservationPricingService $pricingService,
        private readonly HotelStripePaymentService $stripeService,
    ) {
    }

    protected function site(Request $request): HotelBookingSite
    {
        return $request->attributes->get('hotel_booking_site');
    }

    protected function tenantId(Request $request): int
    {
        return (int) $request->attributes->get('hotel_tenant_id');
    }

    public function home(Request $request)
    {
        $site = $this->site($request);
        $creatorId = $this->tenantId($request);

        $featuredRoomTypes = HotelRoomType::where('created_by', $creatorId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (HotelRoomType $type) => [
                'id' => $type->id,
                'name' => $type->name,
                'description' => $type->description,
                'image_url' => $type->displayImageUrl(),
                'from_price' => $type->effectiveNightlyRate(),
                'max_occupancy' => (int) $type->max_occupancy,
            ])
            ->filter(fn (array $row) => $row['from_price'] > 0)
            ->values();

        return view('hotelmanagement::public.home', compact('site', 'featuredRoomTypes'));
    }

    public function search(Request $request)
    {
        $site = $this->site($request);
        $checkIn = $request->get('check_in', Carbon::today()->addDay()->toDateString());
        $checkOut = $request->get('check_out', Carbon::today()->addDays(2)->toDateString());
        $adults = max(1, (int) $request->get('adults', 2));
        $children = max(0, (int) $request->get('children', 0));

        $results = [];
        $error = null;

        try {
            $checkInAt = Carbon::parse($checkIn . ' ' . ($site->check_in_time ?? '14:00'));
            $checkOutAt = Carbon::parse($checkOut . ' ' . ($site->check_out_time ?? '11:00'));
            if (! $checkOutAt->greaterThan($checkInAt)) {
                $error = __('Check-out must be after check-in.');
            } else {
                $results = $this->availabilityService->searchRoomTypes(
                    $this->tenantId($request),
                    $checkInAt,
                    $checkOutAt,
                    $adults,
                    $children
                );
            }
        } catch (\Throwable $e) {
            $error = __('Invalid dates selected.');
        }

        $addons = $this->availabilityService->activeAddons($this->tenantId($request));

        return view('hotelmanagement::public.search', compact(
            'site',
            'checkIn',
            'checkOut',
            'adults',
            'children',
            'results',
            'error',
            'addons'
        ));
    }

    public function availabilityJson(Request $request)
    {
        $site = $this->site($request);
        $request->validate([
            'check_in' => 'required|date',
            'check_out' => 'required|date|after:check_in',
            'adults' => 'nullable|integer|min:1',
            'children' => 'nullable|integer|min:0',
        ]);

        $checkInAt = Carbon::parse($request->check_in . ' ' . ($site->check_in_time ?? '14:00'));
        $checkOutAt = Carbon::parse($request->check_out . ' ' . ($site->check_out_time ?? '11:00'));

        return response()->json([
            'rooms' => $this->availabilityService->searchRoomTypes(
                $this->tenantId($request),
                $checkInAt,
                $checkOutAt,
                (int) $request->get('adults', 2),
                (int) $request->get('children', 0)
            ),
        ]);
    }

    public function bookForm(Request $request)
    {
        $site = $this->site($request);
        $request->validate([
            'room_type_id' => 'required|integer',
            'check_in' => 'required|date',
            'check_out' => 'required|date|after:check_in',
        ]);

        $checkInAt = Carbon::parse($request->check_in . ' ' . ($site->check_in_time ?? '14:00'));
        $checkOutAt = Carbon::parse($request->check_out . ' ' . ($site->check_out_time ?? '11:00'));
        $creatorId = $this->tenantId($request);
        $roomTypeId = (int) $request->room_type_id;

        if ($this->availabilityService->availableRoomCount($creatorId, $roomTypeId, $checkInAt, $checkOutAt) < 1) {
            return redirect()
                ->route('hotel.public.search', ['hotelSlug' => $site->slug])
                ->with('error', __('This room type is no longer available.'));
        }

        $roomType = HotelRoomType::where('created_by', $creatorId)->findOrFail($roomTypeId);
        $quote = $this->pricingService->quote($creatorId, $roomTypeId, null, $checkInAt, $checkOutAt, 'nightly');
        $addons = $this->availabilityService->activeAddons($creatorId);
        $stripeEnabled = $this->stripeService->isConfigured($site);
        $paymentAmounts = null;
        if ($stripeEnabled) {
            $paymentAmounts = $this->stripeService->calculateAmounts($site, [
                'hotel_room_type_id' => $roomTypeId,
                'check_in_at' => $checkInAt->format('Y-m-d H:i:s'),
                'check_out_at' => $checkOutAt->format('Y-m-d H:i:s'),
                'adults' => max(1, (int) $request->get('adults', 2)),
                'children' => max(0, (int) $request->get('children', 0)),
            ]);
        }

        return view('hotelmanagement::public.book', [
            'site' => $site,
            'checkIn' => $request->check_in,
            'checkOut' => $request->check_out,
            'adults' => max(1, (int) $request->get('adults', 2)),
            'children' => max(0, (int) $request->get('children', 0)),
            'roomTypeId' => $roomTypeId,
            'roomType' => $roomType,
            'quote' => $quote,
            'addons' => $addons,
            'stripeEnabled' => $stripeEnabled,
            'paymentAmounts' => $paymentAmounts,
        ]);
    }

    public function storeBooking(Request $request)
    {
        $site = $this->site($request);
        $key = 'hotel-book:' . $request->ip() . ':' . $site->slug;

        if (RateLimiter::tooManyAttempts($key, 10)) {
            return back()->withInput()->with('error', __('Too many booking attempts. Please try again later.'));
        }
        RateLimiter::hit($key, 3600);

        $request->validate([
            'hotel_room_type_id' => 'required|integer',
            'check_in_at' => 'required|date',
            'check_out_at' => 'required|date|after:check_in_at',
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'email' => 'required|email|max:191',
            'phone' => 'required|string|max:50',
            'adults' => 'required|integer|min:1',
            'children' => 'nullable|integer|min:0',
            'nationality' => 'nullable|string|max:100',
            'special_requests' => 'nullable|string|max:1000',
            'addons' => 'nullable|array',
            'terms' => 'accepted',
        ]);

        if ($site->payment_mode === HotelBookingSite::PAY_PREPAY && ! $this->stripeService->isConfigured($site)) {
            return back()->withInput()->with('error', __('Online payment is not configured for this hotel. Please contact us or choose pay at hotel.'));
        }

        try {
            if ($this->stripeService->isConfigured($site)) {
                $checkout = $this->stripeService->createCheckoutSession($site, $request->all());

                return redirect()->away($checkout['url']);
            }

            $result = $this->bookingService->createOnlineBooking($site, $request->all());
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', __('Payment could not be started. Please try again or contact the hotel.'));
        }

        return redirect()->route('hotel.public.confirmation', [
            'hotelSlug' => $site->slug,
            'token' => $result['token'],
        ]);
    }

    public function stripeSuccess(Request $request)
    {
        $site = $this->site($request);
        $sessionId = (string) $request->query('session_id', '');
        if ($sessionId === '') {
            return redirect()->route('hotel.public.home', ['hotelSlug' => $site->slug])
                ->with('error', __('Invalid payment session.'));
        }

        try {
            $result = $this->stripeService->completeCheckoutSession($sessionId);
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('hotel.public.search', ['hotelSlug' => $site->slug])
                ->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return redirect()->route('hotel.public.search', ['hotelSlug' => $site->slug])
                ->with('error', __('Payment verification failed. Contact the hotel if you were charged.'));
        }

        return redirect()->route('hotel.public.confirmation', [
            'hotelSlug' => $site->slug,
            'token' => $result['token'],
        ]);
    }

    public function stripeCancel(Request $request)
    {
        $site = $this->site($request);

        return redirect()->route('hotel.public.search', ['hotelSlug' => $site->slug])
            ->with('error', __('Payment was cancelled. Your booking was not confirmed.'));
    }

    public function confirmation(Request $request, string $hotelSlug, string $token)
    {
        $reservation = $this->bookingService->findByConfirmationToken($hotelSlug, $token);
        if (! $reservation) {
            abort(404);
        }

        $site = $request->attributes->get('hotel_booking_site')
            ?? HotelBookingSite::where('slug', $hotelSlug)->firstOrFail();

        $totals = $this->pricingService->reservationTotals($reservation);

        return view('hotelmanagement::public.confirmation', compact('site', 'reservation', 'totals', 'token'));
    }
}
